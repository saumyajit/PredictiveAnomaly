<?php
/**
 * Action: predictive.anomaly.fleet  (JSON — no view/layout in manifest)
 *
 * Powers the Forecasts tab with real Zabbix data.
 * Always uses hourly trend buckets for forecasting so step size is
 * predictable regardless of the time_range display setting.
 *
 * Forecast horizon: user-selected days (7/30/90).
 * At 3600s/step, 30 days = 720 steps — well within reasonable bounds.
 */

namespace Modules\PredictiveAnomaly\actions;

use CController;
use API;
use Modules\PredictiveAnomaly\services\CAnomalyEngine;
use Modules\PredictiveAnomaly\services\MetricConfig;

class CControllerPredictiveAnomalyFleet extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'groupid'       => 'required|id',
			'metric'        => 'required|string',
			'time_range'    => 'in 1h,6h,24h,7d,30d',
			'forecast_days' => 'ge 1',
		];
		$ret = $this->validateInput($fields);
		if (!$ret) $this->sendJson(['error' => 'Invalid input']);
		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$groupid       = $this->getInput('groupid');
		$metric        = $this->getInput('metric', 'cpu');
		$time_range    = $this->getInput('time_range', '24h');
		$forecast_days = min(90, max(1, (int)$this->getInput('forecast_days', 30)));

		// ── Historical window for actual data ─────────────────────────────
		$time_map  = ['1h'=>3600,'6h'=>21600,'24h'=>86400,'7d'=>604800,'30d'=>2592000];
		$hist_secs = $time_map[$time_range] ?? 86400;
		$time_from = time() - $hist_secs;
		$time_till = time();

		// ── Group info ────────────────────────────────────────────────────
		$groups = API::HostGroup()->get(['output'=>['groupid','name'],'groupids'=>[$groupid]]);
		$group_name = $groups ? reset($groups)['name'] : 'Unknown';

		// ── Hosts ─────────────────────────────────────────────────────────
		$hosts = API::Host()->get([
			'output'          => ['hostid','name'],
			'groupids'        => [$groupid],
			'monitored_hosts' => true,
			'preservekeys'    => true,
			'limit'           => 200,
		]);

		if (!$hosts) {
			$this->sendJson(['error'=>'No monitored hosts','group'=>$group_name,'series'=>[],'forecast'=>[]]);
			return;
		}

		// ── Items ─────────────────────────────────────────────────────────
		$all_keys        = MetricConfig::keyMap([$metric]);
		$key_patterns    = $all_keys[$metric] ?? [];
		$metric_def      = MetricConfig::enabled()[$metric] ?? ['label'=>$metric,'unit'=>'%','icon'=>'📊'];
		$hostids         = array_keys($hosts);
		$search_patterns = array_map(fn($p) => explode('[', $p)[0], $key_patterns);

		$items = API::Item()->get([
			'output'       => ['itemid','hostid','name','key_','units'],
			'hostids'      => $hostids,
			'search'       => ['key_' => $search_patterns],
			'searchByAny'  => true,
			'filter'       => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]],
			'monitored'    => true,
			'preservekeys' => true,
			'limit'        => count($hostids) * 3,
		]);

		$items = MetricConfig::filterItemsByUnit($items, $metric);

		if (!$items) {
			$this->sendJson([
				'error'  => "No items for metric '$metric' with correct units in '$group_name'",
				'group'  => $group_name, 'metric' => $metric_def['label'],
				'series' => [], 'forecast' => [],
			]);
			return;
		}

		$itemids = array_keys($items);

		// ── Historical data: ALWAYS use trends (hourly = reliable step size) ──
		// For shorter ranges, fall back to history but bucket into hourly averages.
		$use_trends = ($hist_secs >= 86400);  // use trends for 24h+
		$raw = [];

		if ($use_trends) {
			$rows = API::Trend()->get([
				'output'    => ['itemid','clock','value_avg'],
				'itemids'   => $itemids,
				'time_from' => $time_from,
				'time_till' => $time_till,
				'limit'     => 50000,
			]);
			foreach ($rows as $row) {
				$iid = $row['itemid'];
				$val = MetricConfig::normalizeValue((float)$row['value_avg'], $items[$iid]['key_']??'', $metric);
				$raw[$iid][] = ['clock' => (int)$row['clock'], 'value' => min(100, max(0, $val))];
			}
		} else {
			// Short range: fetch history, then bucket to hourly averages
			$rows = API::History()->get([
				'output'    => ['itemid','clock','value'],
				'itemids'   => $itemids,
				'time_from' => $time_from,
				'time_till' => $time_till,
				'history'   => ITEM_VALUE_TYPE_FLOAT,
				'sortfield' => 'clock',
				'sortorder' => 'ASC',
				'limit'     => 100000,
			]);
			// Bucket into hourly averages so step size is consistent
			$hourly = [];
			foreach ($rows as $row) {
				$iid    = $row['itemid'];
				$bucket = (int)(floor($row['clock'] / 3600) * 3600);
				$val    = MetricConfig::normalizeValue((float)$row['value'], $items[$iid]['key_']??'', $metric);
				$val    = min(100, max(0, $val));
				$hourly[$iid][$bucket]['sum']   = ($hourly[$iid][$bucket]['sum']   ?? 0) + $val;
				$hourly[$iid][$bucket]['count'] = ($hourly[$iid][$bucket]['count'] ?? 0) + 1;
			}
			foreach ($hourly as $iid => $buckets) {
				ksort($buckets);
				foreach ($buckets as $clock => $agg) {
					$raw[$iid][] = ['clock' => $clock, 'value' => round($agg['sum']/$agg['count'], 2)];
				}
			}
		}

		// ── Aggregate: hourly fleet average across all items ──────────────
		$bucket_sums = []; $bucket_counts = [];
		foreach ($raw as $iid => $points) {
			foreach ($points as $pt) {
				$b = $pt['clock'];
				$bucket_sums[$b]   = ($bucket_sums[$b]   ?? 0) + $pt['value'];
				$bucket_counts[$b] = ($bucket_counts[$b] ?? 0) + 1;
			}
		}
		ksort($bucket_sums);

		$series = [];
		foreach ($bucket_sums as $clock => $sum) {
			$series[] = ['clock' => $clock, 'value' => round($sum / $bucket_counts[$clock], 2)];
		}

		if (count($series) < 5) {
			$this->sendJson([
				'error'  => 'Insufficient data (need ≥5 hourly points). Try a longer time range.',
				'group'  => $group_name, 'metric' => $metric_def['label'],
				'series' => [], 'forecast' => [],
			]);
			return;
		}

		// ── Forecast ─────────────────────────────────────────────────────
		$engine = new CAnomalyEngine();
		$values = array_column($series, 'value');
		$clocks = array_column($series, 'clock');

		// Step size is always 3600s (hourly buckets) → N steps = N hours
		// forecast_days * 24 = forecast_steps at 1h resolution
		$forecast_steps = $forecast_days * 24;
		// Cap at 720 (30d) to keep response size reasonable for long horizons
		$forecast_steps = min($forecast_steps, 720);

		$z  = $engine->zScoreAnomalyScore($values);
		$lr = $engine->linearRegressionForecastN($clocks, $values, $time_range, $metric, $forecast_steps);

		// ── Build output series ───────────────────────────────────────────
		$step = 3600; // always hourly
		$last = end($clocks);
		$anomaly_set = array_flip($z['anomaly_indices']);

		$series_out = [];
		foreach ($series as $i => $pt) {
			$series_out[] = ['x' => $pt['clock'] * 1000, 'y' => $pt['value'], 'anomaly' => isset($anomaly_set[$i])];
		}

		$forecast_out = [];
		foreach ($lr['forecast_series'] as $i => $fval) {
			$forecast_out[] = [
				'x'     => ($last + ($i + 1) * $step) * 1000,
				'y'     => round(max(0, min(100, $fval)), 2),
				'upper' => round(max(0, min(100, $lr['ci_upper'][$i] ?? $fval)), 2),
				'lower' => round(max(0, min(100, $lr['ci_lower'][$i] ?? $fval)), 2),
			];
		}

		// ── Per-host series (last point for sparklines + legend) ──────────
		$colors = ['#2563eb','#ef4444','#10b981','#f59e0b','#7c3aed','#06b6d4','#f97316','#ec4899'];
		$host_series = [];
		$ci = 0;
		foreach ($raw as $iid => $points) {
			if (!isset($items[$iid])) continue;
			$hid  = $items[$iid]['hostid'];
			if (isset($host_series[$hid])) continue; // one item per host
			$name = $hosts[$hid]['name'] ?? "Host $hid";
			usort($points, fn($a,$b) => $a['clock'] - $b['clock']);
			$host_series[] = [
				'name'   => $name,
				'color'  => $colors[$ci % count($colors)],
				'points' => array_map(fn($p) => ['x'=>$p['clock']*1000,'y'=>$p['value']], $points),
			];
			$ci++;
			if ($ci >= 8) break;
		}

		$this->sendJson([
			'group'       => $group_name,
			'groupid'     => $groupid,
			'metric'      => $metric_def['label'],
			'metric_slug' => $metric,
			'unit'        => $metric_def['unit'] ?? '%',
			'icon'        => $metric_def['icon'] ?? '📊',
			'item_count'  => count($items),
			'host_count'  => count($hosts),
			'step_hours'  => 1,
			'series'      => $series_out,
			'forecast'    => $forecast_out,
			'host_series' => $host_series,
			'stats'       => [
				'mean'              => round($z['mean'], 2),
				'max_z'             => round($z['max_z'], 2),
				'score'             => round($z['score'], 3),
				'slope'             => round($lr['slope'], 6),
				'breach_eta'        => $lr['breach_eta'],
				'breach_threshold'  => $lr['breach_threshold'] ?? 85,
				'r_squared'         => round($lr['r_squared'], 3),
			],
		]);
	}

	private function sendJson(array $payload): void {
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode($payload);
		exit;
	}
}
