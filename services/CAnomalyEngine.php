<?php
/**
 * CAnomalyEngine
 * Pure PHP. Reads all thresholds from config/thresholds.php.
 * Zero external dependencies.
 */

namespace Modules\PredictiveAnomaly\services;

class CAnomalyEngine {

	private array $cfg;

	public function __construct() {
		$cfg_file    = __DIR__ . '/../config/thresholds.php';
		$this->cfg   = file_exists($cfg_file) ? require $cfg_file : [];
	}

	private function cfgGet(string $key, $default) {
		return $this->cfg[$key] ?? $default;
	}

	// ── Z-SCORE ───────────────────────────────────────────────────────────

	public function zScoreAnomalyScore(array $values): array {
		$n = count($values);
		if ($n < 3) return $this->emptyZ();

		$z_threshold     = (float) $this->cfgGet('z_score_threshold', 2.5);
		$window_fraction = (float) $this->cfgGet('z_window_fraction', 0.3);
		$window          = $n >= 30 ? (int) ceil($n * $window_fraction) : $n;

		$z_scores = []; $anomaly_indices = []; $max_z = 0;

		for ($i = 0; $i < $n; $i++) {
			$start       = max(0, $i - $window + 1);
			$window_vals = array_slice($values, $start, $i - $start + 1);
			$mean        = array_sum($window_vals) / count($window_vals);
			$std         = $this->std($window_vals);
			if ($std < 1e-9) { $z_scores[] = 0; continue; }
			$z          = abs($values[$i] - $mean) / $std;
			$z_scores[] = round($z, 3);
			if ($z > $z_threshold) $anomaly_indices[] = $i;
			$max_z = max($max_z, $z);
		}

		$gmean = array_sum($values) / $n;
		$gstd  = $this->std($values);
		$score = min(1.0, $max_z / ($z_threshold * 2));
		$score = min(1.0, $score * (1 + count($anomaly_indices) / $n));

		return [
			'score'           => round($score, 4),
			'max_z'           => round($max_z, 3),
			'z_scores'        => $z_scores,
			'anomaly_indices' => $anomaly_indices,
			'mean'            => round($gmean, 4),
			'std'             => round($gstd, 4),
		];
	}

	// ── LINEAR REGRESSION ─────────────────────────────────────────────────

	public function linearRegressionForecast(
		array  $clocks,
		array  $values,
		string $time_range  = '24h',
		string $metric_slug = 'disk'
	): array {
		$n = count($values);
		if ($n < 3 || count($clocks) !== $n) return $this->emptyLR();

		$steps  = (int)   $this->cfgGet('forecast_steps', 12);
		$ci_z   = (float) $this->cfgGet('confidence_interval_z', 1.96);

		$exhaustion_thresholds = $this->cfgGet('exhaustion_thresholds', []);
		$threshold = (float) ($exhaustion_thresholds[$metric_slug] ?? 85.0);

		$t0     = $clocks[0];
		$t_norm = array_map(fn($c) => $c - $t0, $clocks);
		$sum_t  = array_sum($t_norm);
		$sum_y  = array_sum($values);
		$sum_tt = array_sum(array_map(fn($t) => $t * $t, $t_norm));
		$sum_ty = 0;
		for ($i = 0; $i < $n; $i++) $sum_ty += $t_norm[$i] * $values[$i];

		$denom = $n * $sum_tt - $sum_t * $sum_t;
		if (abs($denom) < 1e-12) return $this->emptyLR();

		$b = ($n * $sum_ty - $sum_t * $sum_y) / $denom;
		$a = ($sum_y - $b * $sum_t) / $n;

		$ss_res = 0; $ss_tot = 0; $y_mean = $sum_y / $n; $residuals = [];
		for ($i = 0; $i < $n; $i++) {
			$r           = $values[$i] - ($a + $b * $t_norm[$i]);
			$residuals[] = round($r, 4);
			$ss_res     += $r * $r;
			$ss_tot     += ($values[$i] - $y_mean) ** 2;
		}
		$r_squared = $ss_tot > 1e-12 ? 1 - $ss_res / $ss_tot : 0;
		$rse       = $n > 2 ? sqrt($ss_res / ($n - 2)) : 0;

		$step   = $this->forecastStepSize($time_range, $clocks);
		$last_t = end($t_norm);
		$t_mean = $sum_t / $n;
		$stt    = $sum_tt - $n * $t_mean * $t_mean;
		$forecast = []; $ci_upper = []; $ci_lower = [];

		for ($i = 1; $i <= $steps; $i++) {
			$t_pred   = $last_t + $i * $step;
			$y_pred   = $a + $b * $t_pred;
			$leverage = $stt > 0 ? sqrt(1.0 / $n + ($t_pred - $t_mean) ** 2 / $stt) : 0;
			$margin   = $ci_z * $rse * (1 + $leverage);
			$forecast[]  = round($y_pred, 3);
			$ci_upper[]  = round($y_pred + $margin, 3);
			$ci_lower[]  = round($y_pred - $margin, 3);
		}

		$max_res     = $residuals ? max(array_map('abs', $residuals)) : 0;
		$lr_score    = $r_squared < 0.1 ? min(1.0, $max_res / (abs($y_mean) + 1)) : 0;
		$breach_eta  = ($threshold > 0) ? $this->estimateBreachEta($a, $b, $t_norm, $threshold) : null;
		$slope_score = 0;
		if ($b > 0 && $breach_eta !== null) {
			$slope_score = (1 - min(1.0, $breach_eta / (30 * 86400))) * 0.5;
		}

		return [
			'score'            => round(min(1.0, $lr_score + $slope_score), 4),
			'slope'            => $b,
			'intercept'        => $a,
			'r_squared'        => round($r_squared, 4),
			'forecast'         => $forecast,
			'forecast_series'  => $forecast,
			'ci_upper'         => $ci_upper,
			'ci_lower'         => $ci_lower,
			'breach_eta'       => $breach_eta,
			'breach_threshold' => $threshold,
			'residuals'        => $residuals,
		];
	}

	private function std(array $values): float {
		$n = count($values); if ($n < 2) return 0;
		$mean = array_sum($values) / $n;
		return sqrt(array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / ($n - 1));
	}

	private function estimateBreachEta(float $a, float $b, array $t_norm, float $threshold): ?int {
		if ($b <= 0) return null;
		$last_pred = $a + $b * end($t_norm);
		if ($last_pred >= $threshold) return 0;
		$last_t_val = end($t_norm);
		$eta = (int)(($threshold - $a) / $b - $last_t_val);
		return ($eta > 0 && $eta < 365 * 86400) ? $eta : null;
	}

	private function forecastStepSize(string $time_range, array $clocks): int {
		$n = count($clocks);
		if ($n >= 2) { $i = (int)(($clocks[$n-1] - $clocks[0]) / ($n - 1)); if ($i > 0) return $i; }
		return match($time_range) { '1h'=>300,'6h'=>900,'24h'=>3600,'7d'=>21600,'30d'=>86400,default=>3600 };
	}

	private function emptyZ(): array {
		return ['score'=>0,'max_z'=>0,'z_scores'=>[],'anomaly_indices'=>[],'mean'=>0,'std'=>0];
	}

	private function emptyLR(): array {
		return [
			'score'=>0,'slope'=>0,'intercept'=>0,'r_squared'=>0,
			'forecast'=>[],'forecast_series'=>[],'ci_upper'=>[],'ci_lower'=>[],
			'breach_eta'=>null,'breach_threshold'=>85,'residuals'=>[],
		];
	}
}
