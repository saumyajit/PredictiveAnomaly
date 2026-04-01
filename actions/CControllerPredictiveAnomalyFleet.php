<?php
/**
 * Action: predictive.anomaly.fleet  (JSON — no view/layout in manifest)
 *
 * Powers the Forecasts tab. Returns real Zabbix history/trend data
 * aggregated across all hosts in a group for a given metric.
 * Also runs linear regression to produce forecast + CI band.
 *
 * Called by JS when user selects a group + metric in the Forecasts tab.
 */

namespace Modules\PredictiveAnomaly\actions;

use CController;
use CControllerResponseFatal;
use API;
use Modules\PredictiveAnomaly\services\CAnomalyEngine;
use Modules\PredictiveAnomaly\services\MetricConfig;

class CControllerPredictiveAnomalyFleet extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'groupid'    => 'required|id',
			'metric'     => 'required|string',
			'time_range' => 'in 1h,6h,24h,7d,30d',
			'model'      => 'in all,zscore,linear',
			'forecast_days' => 'ge 1',
		];
		$ret = $this->validateInput($fields);
		if (!$ret) {
			// invalid input — sendJson error
			$this->sendJson(['error' => 'Invalid input']);
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$groupid    = $this->getInput('groupid');
		$metric     = $this->getInput('metric', 'cpu');
		$time_range = $this->getInput('time_range', '24h');

		$forecast_days = min(90, (int)$this->getInput('forecast_days', 30));
		$time_map   = ['1h'=>3600,'6h'=>21600,'24h'=>86400,'7d'=>604800,'30d'=>2592000];
		$time_from  = time() - ($time_map[$time_range] ?? 86400);
		$time_till  = time();
		$use_trends = in_array($time_range, ['7d', '30d']);

		// ── Group info ────────────────────────────────────────────────────
		$groups = API::HostGroup()->get([
			'output'   => ['groupid', 'name'],
			'groupids' => [$groupid],
		]);
		$group_name = $groups ? reset($groups)['name'] : 'Unknown';

		// ── Hosts in group ────────────────────────────────────────────────
		$hosts = API::Host()->get([
			'output'          => ['hostid', 'name'],
			'groupids'        => [$groupid],
			'monitored_hosts' => true,
			'preservekeys'    => true,
			'limit'           => 200,
		]);

		if (!$hosts) {
			$this->sendJson(['error' => 'No monitored hosts in group', 'group' => $group_name, 'series' => [], 'forecast' => []]);
			return;
		}

		// ── Metric key patterns ───────────────────────────────────────────
		$all_keys = MetricConfig::keyMap([$metric]);
		$key_patterns = $all_keys[$metric] ?? [];
		if (!$key_patterns) {
			$this->sendJson(['error' => "Metric '$metric' not found in config/metrics.php"]);
			return;
		}

		$metric_def = MetricConfig::enabled()[$metric] ?? ['label'=>$metric,'unit'=>'%','icon'=>'📊'];
		$hostids    = array_keys($hosts);

		// Search all key patterns for this metric
		$search_patterns = array_map(fn($p) => explode('[', $p)[0], $key_patterns);
		$items = API::Item()->get([
			'output'       => ['itemid', 'hostid', 'name', 'key_', 'units'],
			'hostids'      => $hostids,
			'search'       => ['key_' => $search_patterns],
			'searchByAny'  => true,
			'filter'       => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]],
			'monitored'    => true,
			'preservekeys' => true,
			'limit'        => count($hostids) * 3,
		]);

		// Filter to correct unit (% not bytes for memory/disk)
		$items = MetricConfig::filterItemsByUnit($items, $metric);

		if (!$items) {
			$this->sendJson([
				'error'  => "No items matching metric '$metric' with correct unit in group '$group_name'",
				'group'  => $group_name,
				'metric' => $metric_def['label'],
				'series' => [], 'forecast' => [],
			]);
			return;
		}

		// ── Fetch data ────────────────────────────────────────────────────
		$itemids = array_keys($items);
		$raw     = [];

		if ($use_trends) {
			$rows = API::Trend()->get([
				'output'    => ['itemid', 'clock', 'value_avg'],
				'itemids'   => $itemids,
				'time_from' => $time_from,
				'time_till' => $time_till,
				'limit'     => 50000,
			]);
			foreach ($rows as $row) {
				$raw[$row['itemid']][] = ['clock' => (int)$row['clock'], 'value' => (float)$row['value_avg']];
			}
		} else {
			$rows = API::History()->get([
				'output'    => ['itemid', 'clock', 'value'],
				'itemids'   => $itemids,
				'time_from' => $time_from,
				'time_till' => $time_till,
				'history'   => ITEM_VALUE_TYPE_FLOAT,
				'sortfield' => 'clock',
				'sortorder' => 'ASC',
				'limit'     => 100000,
			]);
			foreach ($rows as $row) {
				$raw[$row['itemid']][] = ['clock' => (int)$row['clock'], 'value' => (float)$row['value']];
			}
		}

		// ── Aggregate: average across all items at each clock step ───────
		// Group by clock bucket, average value across all items
		$bucket_sums   = [];
		$bucket_counts = [];

		foreach ($raw as $iid => $points) {
			foreach ($points as $pt) {
				$bucket = $pt['clock'];
				// Normalize: pavailable → invert; clamp to [0,100] for % metrics
				$item_key = $items[$iid]['key_'] ?? '';
				$val = MetricConfig::normalizeValue($pt['value'], $item_key, $metric);
				$val = min(100.0, max(0.0, $val));
				$bucket_sums[$bucket]   = ($bucket_sums[$bucket]   ?? 0) + $val;
				$bucket_counts[$bucket] = ($bucket_counts[$bucket] ?? 0) + 1;
			}
		}

		ksort($bucket_sums);
		$series = [];
		foreach ($bucket_sums as $clock => $sum) {
			$series[] = [
				'clock' => $clock,
				'value' => round($sum / $bucket_counts[$clock], 2),
			];
		}

		if (count($series) < 5) {
			$this->sendJson([
				'error'  => 'Insufficient data points for forecasting (need ≥5)',
				'group'  => $group_name,
				'metric' => $metric_def['label'],
				'series' => [], 'forecast' => [],
			]);
			return;
		}

		// ── Per-host series for multi-line view ───────────────────────────
		$host_series = [];
		foreach ($raw as $iid => $points) {
			if (!isset($items[$iid])) continue;
			$hid  = $items[$iid]['hostid'];
			$name = $hosts[$hid]['name'] ?? "Host $hid";
			if (!isset($host_series[$hid])) {
				$host_series[$hid] = ['name' => $name, 'points' => []];
			}
			foreach ($points as $pt) {
				$host_series[$hid]['points'][] = $pt;
			}
		}

		// ── Forecast on aggregated series ────────────────────────────────
		$engine  = new CAnomalyEngine();

		// Compute how many forecast steps cover $forecast_days
		$values  = array_column($series, 'value');
		$clocks  = array_column($series, 'clock');
		$n_pts   = count($clocks);
		$inferred_step = $n_pts >= 2
			? max(60, (int)(($clocks[$n_pts-1] - $clocks[0]) / ($n_pts - 1)))
			: 3600;
		$forecast_steps_needed = max(12, (int)ceil(($forecast_days * 86400) / $inferred_step));
		// Cap at 500 to avoid massive arrays
		$forecast_steps_needed = min(500, $forecast_steps_needed);
		$clocks  = array_column($series, 'clock');
		$z       = $engine->zScoreAnomalyScore($values);
		$lr      = $engine->linearRegressionForecast($clocks, $values, $time_range, $metric);

		// Build step size from data
		$n    = count($clocks);
		$step = $n >= 2 ? max((int)(($clocks[$n-1] - $clocks[0]) / ($n - 1)), 60) : 3600;
		$last = end($clocks);

		$series_out = [];
		$anomaly_set = array_flip($z['anomaly_indices']);
		foreach ($series as $i => $pt) {
			$series_out[] = [
				'x'       => $pt['clock'] * 1000,
				'y'       => $pt['value'],
				'anomaly' => isset($anomaly_set[$i]),
			];
		}

		$forecast_out = [];
		foreach ($lr['forecast_series'] as $i => $fval) {
			$forecast_out[] = [
				'x'     => ($last + ($i + 1) * $step) * 1000,
				'y'     => round($fval, 2),
				'upper' => round($lr['ci_upper'][$i] ?? $fval, 2),
				'lower' => round($lr['ci_lower'][$i] ?? $fval, 2),
			];
		}

		// Host series for multi-line chart
		$colors = ['#2563eb','#ef4444','#10b981','#f59e0b','#7c3aed','#06b6d4','#f97316','#ec4899'];
		$host_series_out = [];
		$ci = 0;
		foreach ($host_series as $hid => $hs) {
			if (count($hs['points']) < 3) continue;
			usort($hs['points'], fn($a,$b) => $a['clock'] - $b['clock']);
			$host_series_out[] = [
				'name'   => $hs['name'],
				'color'  => $colors[$ci % count($colors)],
				'points' => array_map(fn($p) => ['x' => $p['clock']*1000, 'y' => $p['value']], $hs['points']),
			];
			$ci++;
			if ($ci >= 8) break; // max 8 hosts on one chart
		}

		$this->sendJson([
			'group'        => $group_name,
			'groupid'      => $groupid,
			'metric'       => $metric_def['label'],
			'metric_slug'  => $metric,
			'unit'         => $metric_def['unit'] ?? '%',
			'icon'         => $metric_def['icon'] ?? '📊',
			'item_count'   => count($items),
			'host_count'   => count($hosts),
			'series'       => $series_out,        // aggregated average
			'forecast'     => $forecast_out,
			'host_series'  => $host_series_out,   // per-host lines
			'stats' => [
				'mean'       => round($z['mean'], 2),
				'max_z'      => round($z['max_z'], 2),
				'score'      => round($z['score'], 3),
				'slope'      => round($lr['slope'], 5),
				'breach_eta' => $lr['breach_eta'],
				'breach_threshold' => $lr['breach_threshold'],
				'r_squared'  => round($lr['r_squared'], 3),
			],
		]);
	}

	private function sendJson(array $payload): void {
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode($payload);
		exit;
	}
}
