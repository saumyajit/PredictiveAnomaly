<?php
/**
 * CAnomalyEngine
 *
 * Pure-PHP anomaly detection and forecasting engine.
 * Zero external dependencies — runs entirely on Zabbix trend data.
 *
 * Methods:
 *   zScoreAnomalyScore()       — Rolling Z-score anomaly detection
 *   linearRegressionForecast() — OLS linear regression + confidence intervals
 *   exhaustionEstimate()       — Time-to-threshold extrapolation
 */

namespace Modules\PredictiveAnomaly\Services;

class CAnomalyEngine {

	// Z-score threshold for flagging a point as anomalous
	const Z_THRESHOLD = 2.5;

	// Forecast steps ahead (number of data intervals)
	const FORECAST_STEPS = 12;

	// Confidence interval coverage (1.96 = 95%)
	const CI_Z = 1.96;

	// ─────────────────────────────────────────────────────────────────
	// Z-SCORE ANOMALY DETECTION
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Compute Z-score for each value in the series.
	 * Uses a rolling window for non-stationary series.
	 *
	 * Returns:
	 *   score         float  0.0–1.0  composite anomaly score
	 *   max_z         float  maximum |Z| seen in the series
	 *   z_scores      array  per-point Z-score
	 *   anomaly_indices array indices where |Z| > threshold
	 *   mean          float  series mean
	 *   std           float  series std deviation
	 */
	public function zScoreAnomalyScore(array $values): array {
		$n = count($values);

		if ($n < 3) {
			return $this->emptyZResult();
		}

		// Use rolling window for long series, global otherwise
		$window = $n >= 30 ? (int) ceil($n * 0.3) : $n;

		$z_scores       = [];
		$anomaly_indices = [];
		$max_z          = 0;

		for ($i = 0; $i < $n; $i++) {
			$start  = max(0, $i - $window + 1);
			$window_vals = array_slice($values, $start, $i - $start + 1);

			$mean = array_sum($window_vals) / count($window_vals);
			$std  = $this->std($window_vals);

			if ($std < 1e-9) {
				$z_scores[] = 0;
				continue;
			}

			$z = abs($values[$i] - $mean) / $std;
			$z_scores[] = round($z, 3);

			if ($z > self::Z_THRESHOLD) {
				$anomaly_indices[] = $i;
			}
			$max_z = max($max_z, $z);
		}

		// Global stats
		$global_mean = array_sum($values) / $n;
		$global_std  = $this->std($values);

		// Score: sigmoid-like mapping of max_z into [0,1]
		$score = min(1.0, $max_z / (self::Z_THRESHOLD * 2));

		// Boost score if many anomaly points
		$anomaly_ratio = count($anomaly_indices) / $n;
		$score = min(1.0, $score * (1 + $anomaly_ratio));

		return [
			'score'           => round($score, 4),
			'max_z'           => round($max_z, 3),
			'z_scores'        => $z_scores,
			'anomaly_indices' => $anomaly_indices,
			'mean'            => round($global_mean, 4),
			'std'             => round($global_std, 4),
		];
	}

	// ─────────────────────────────────────────────────────────────────
	// LINEAR REGRESSION FORECAST
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Ordinary Least Squares regression on (clock, value) pairs.
	 *
	 * Returns:
	 *   score          float   anomaly score based on residuals
	 *   slope          float   trend slope (units/second)
	 *   intercept      float
	 *   r_squared      float   goodness of fit
	 *   forecast       array   next N predicted values (flat)
	 *   forecast_series array  same as forecast (verbose alias)
	 *   ci_upper       array   95% confidence interval upper bound
	 *   ci_lower       array   lower bound
	 *   breach_eta     int|null seconds until predicted value hits threshold
	 *   residuals      array   per-point residuals
	 */
	public function linearRegressionForecast(array $clocks, array $values, string $time_range = '24h'): array {
		$n = count($values);

		if ($n < 3 || count($clocks) !== $n) {
			return $this->emptyLRResult();
		}

		// Normalise clocks to reduce float precision issues
		$t0      = $clocks[0];
		$t_norm  = array_map(fn($c) => $c - $t0, $clocks);

		// OLS: y = a + b*t
		$sum_t  = array_sum($t_norm);
		$sum_y  = array_sum($values);
		$sum_tt = array_sum(array_map(fn($t) => $t * $t, $t_norm));
		$sum_ty = 0;
		for ($i = 0; $i < $n; $i++) {
			$sum_ty += $t_norm[$i] * $values[$i];
		}

		$denom = $n * $sum_tt - $sum_t * $sum_t;

		if (abs($denom) < 1e-12) {
			return $this->emptyLRResult();
		}

		$b = ($n * $sum_ty - $sum_t * $sum_y) / $denom; // slope
		$a = ($sum_y - $b * $sum_t) / $n;               // intercept

		// Residuals and R²
		$residuals = [];
		$ss_res    = 0;
		$y_mean    = $sum_y / $n;
		$ss_tot    = 0;
		for ($i = 0; $i < $n; $i++) {
			$predicted   = $a + $b * $t_norm[$i];
			$r           = $values[$i] - $predicted;
			$residuals[] = round($r, 4);
			$ss_res     += $r * $r;
			$ss_tot     += ($values[$i] - $y_mean) ** 2;
		}
		$r_squared = $ss_tot > 1e-12 ? 1 - $ss_res / $ss_tot : 0;

		// Residual standard error (for confidence intervals)
		$rse = $n > 2 ? sqrt($ss_res / ($n - 2)) : 0;

		// Forecast horizon: adapt to time range
		$steps      = self::FORECAST_STEPS;
		$step_size  = $this->forecastStepSize($time_range, $clocks);

		$last_t     = end($t_norm);
		$forecast   = [];
		$ci_upper   = [];
		$ci_lower   = [];

		// Leverage factor denominator for CI
		$t_mean     = $sum_t / $n;
		$stt        = $sum_tt - $n * $t_mean * $t_mean;

		for ($i = 1; $i <= $steps; $i++) {
			$t_pred   = $last_t + $i * $step_size;
			$y_pred   = $a + $b * $t_pred;

			// CI: y_pred ± Z * RSE * sqrt(1/n + (t_pred - t_mean)² / Stt)
			$leverage = $stt > 0
				? sqrt(1.0 / $n + ($t_pred - $t_mean) ** 2 / $stt)
				: 0;
			$margin    = self::CI_Z * $rse * (1 + $leverage);

			$forecast[]  = round($y_pred, 3);
			$ci_upper[]  = round($y_pred + $margin, 3);
			$ci_lower[]  = round($y_pred - $margin, 3);
		}

		// Anomaly score from regression: high residuals = anomalous
		$max_residual = $residuals ? max(array_map('abs', $residuals)) : 0;
		$lr_score     = $r_squared < 0.1
			? min(1.0, $max_residual / (abs($y_mean) + 1))
			: 0;

		// Breach ETA: when does forecast cross 85% (disk) / 90% (mem) / 95% (cpu)?
		// Use a generic 85% threshold; per-metric thresholds can be configured.
		$breach_eta = $this->estimateBreachEta($a, $b, $t0, $t_norm, 85.0);

		// Slope-based exhaustion score
		$slope_score = 0;
		if ($b > 0 && $breach_eta !== null) {
			// More urgent = higher score
			$urgency     = 1 - min(1.0, $breach_eta / (30 * 86400));
			$slope_score = $urgency * 0.5;
		}

		$final_score = min(1.0, $lr_score + $slope_score);

		return [
			'score'          => round($final_score, 4),
			'slope'          => $b,
			'intercept'      => $a,
			'r_squared'      => round($r_squared, 4),
			'forecast'       => $forecast,
			'forecast_series'=> $forecast,
			'ci_upper'       => $ci_upper,
			'ci_lower'       => $ci_lower,
			'breach_eta'     => $breach_eta,
			'residuals'      => $residuals,
		];
	}

	// ─────────────────────────────────────────────────────────────────
	// EXHAUSTION ESTIMATE
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Estimate when a linearly-trending metric will hit a given threshold.
	 * Returns seconds from now, or null if threshold is not approached.
	 */
	public function exhaustionEstimate(
		array $clocks,
		array $values,
		float $threshold = 85.0
	): ?int {
		$lr = $this->linearRegressionForecast($clocks, $values, '30d');
		return $lr['breach_eta'];
	}

	// ─────────────────────────────────────────────────────────────────
	// HELPERS
	// ─────────────────────────────────────────────────────────────────

	private function std(array $values): float {
		$n = count($values);
		if ($n < 2) return 0;
		$mean = array_sum($values) / $n;
		$sq   = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values));
		return sqrt($sq / ($n - 1));
	}

	private function estimateBreachEta(
		float $a,
		float $b,
		int $t0,
		array $t_norm,
		float $threshold
	): ?int {
		// Only estimate breach if slope is positive (growing trend)
		if ($b <= 0) return null;

		$last_t    = end($t_norm);
		$last_pred = $a + $b * $last_t;

		if ($last_pred >= $threshold) {
			// Already breached
			return 0;
		}

		// t_breach = (threshold - a) / b  → add back t0
		$t_breach_norm = ($threshold - $a) / $b;
		$eta_seconds   = (int) ($t_breach_norm - $last_t);

		// Sanity: don't return breach estimates more than 1 year out
		if ($eta_seconds <= 0 || $eta_seconds > 365 * 86400) {
			return null;
		}

		return $eta_seconds;
	}

	private function forecastStepSize(string $time_range, array $clocks): int {
		// Infer step from data
		$n = count($clocks);
		if ($n >= 2) {
			$inferred = (int) (($clocks[$n-1] - $clocks[0]) / ($n - 1));
			if ($inferred > 0) return $inferred;
		}

		return match($time_range) {
			'1h'  => 300,    // 5 min
			'6h'  => 900,    // 15 min
			'24h' => 3600,   // 1 hour
			'7d'  => 21600,  // 6 hours
			'30d' => 86400,  // 1 day
			default => 3600,
		};
	}

	private function emptyZResult(): array {
		return [
			'score' => 0, 'max_z' => 0, 'z_scores' => [],
			'anomaly_indices' => [], 'mean' => 0, 'std' => 0,
		];
	}

	private function emptyLRResult(): array {
		return [
			'score' => 0, 'slope' => 0, 'intercept' => 0, 'r_squared' => 0,
			'forecast' => [], 'forecast_series' => [],
			'ci_upper' => [], 'ci_lower' => [],
			'breach_eta' => null, 'residuals' => [],
		];
	}
}
