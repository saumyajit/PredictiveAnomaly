<?php
/**
 * Action: predictive.anomaly.host  (JSON — no view/layout in manifest)
 *
 * Per-host anomaly detail for a single group drilldown.
 */

namespace Modules\PredictiveAnomaly\actions;

use CController;
use API;
use Modules\PredictiveAnomaly\services\CAnomalyEngine;
use Modules\PredictiveAnomaly\services\CMLBridge;
use Modules\PredictiveAnomaly\services\MetricConfig;

class CControllerPredictiveAnomalyHost extends CController {

	const PAGE_SIZE = 50;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	private function sendJson(array $payload): void {
		// Output JSON directly, bypassing Zabbix layout rendering.
		// JSON actions have no "view" or "layout" in manifest.json.
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode($payload);
		exit;
	}

	protected function checkInput(): bool {
		$fields = [
			'groupid'         => 'required|id',
			'metrics'         => 'array',
			'time_range'      => 'in 1h,6h,24h,7d,30d',
			'score_threshold' => 'string',
			'model'           => 'in all,zscore,linear,arima,prophet',
			'page'            => 'ge 1',
			'sort_field'      => 'in name,score,cpu,memory,disk',
			'sort_order'      => 'in ASC,DESC',
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
		$groupid         = $this->getInput('groupid');
		$metrics         = $this->getInput('metrics', ['cpu', 'memory', 'disk']);
		$time_range      = $this->getInput('time_range', '24h');
		$score_threshold = (float) $this->getInput('score_threshold', '0.0');
		$model           = $this->getInput('model', 'all');
		$page            = (int) $this->getInput('page', 1);
		$sort_field      = $this->getInput('sort_field', 'score');
		$sort_order      = $this->getInput('sort_order', 'DESC');

		$time_map   = ['1h'=>3600,'6h'=>21600,'24h'=>86400,'7d'=>604800,'30d'=>2592000];
		$time_from  = time() - ($time_map[$time_range] ?? 86400);
		$time_till  = time();
		$use_trends = in_array($time_range, ['7d', '30d']);

		// Group info
		$groups = API::HostGroup()->get([
			'output'   => ['groupid', 'name'],
			'groupids' => [$groupid],
		]);
		$group = $groups[0] ?? ['name' => 'Unknown'];

		// All hosts in group
		$all_hosts = API::Host()->get([
			'output'          => ['hostid', 'name', 'status'],
			'groupids'        => [$groupid],
			'monitored_hosts' => true,
			'preservekeys'    => true,
			'sortfield'       => 'name',
		]);

		$total_hosts = count($all_hosts);
		$paged_hosts = array_slice(
			array_values($all_hosts),
			($page - 1) * self::PAGE_SIZE,
			self::PAGE_SIZE
		);

		$engine = new CAnomalyEngine();
		$ml     = new CMLBridge();

		// Use MetricConfig so definitions come from config/metrics.php
		$metric_key_map = MetricConfig::keyMap($metrics);
		$paged_hostids  = array_column($paged_hosts, 'hostid');

		// Fetch all items for paged hosts
		$all_items = API::Item()->get([
			'output'       => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'units'],
			'hostids'      => $paged_hostids,
			'filter'       => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]],
			'monitored'    => true,
			'preservekeys' => true,
		]);

		// Fetch trend/history data
		$itemids    = array_keys($all_items);
		$trend_data = [];
		if ($itemids) {
			if ($use_trends) {
				$raw = API::Trend()->get([
					'output'    => ['itemid', 'clock', 'value_avg'],
					'itemids'   => $itemids,
					'time_from' => $time_from,
					'time_till' => $time_till,
					'limit'     => 100000,
				]);
				foreach ($raw as $row) {
					$trend_data[$row['itemid']][] = [
						'clock' => (int)$row['clock'],
						'value' => (float)$row['value_avg'],
					];
				}
			} else {
				$raw = API::History()->get([
					'output'    => ['itemid', 'clock', 'value'],
					'itemids'   => $itemids,
					'time_from' => $time_from,
					'time_till' => $time_till,
					'history'   => ITEM_VALUE_TYPE_FLOAT,
					'sortfield' => 'clock',
					'sortorder' => 'ASC',
					'limit'     => 200000,
				]);
				foreach ($raw as $row) {
					$trend_data[$row['itemid']][] = [
						'clock' => (int)$row['clock'],
						'value' => (float)$row['value'],
					];
				}
			}
		}

		// Score each host
		$host_results = [];
		foreach ($paged_hosts as $host) {
			$hid        = $host['hostid'];
			$host_items = array_filter($all_items, fn($item) => $item['hostid'] == $hid);
			$metric_results = [];
			$host_score     = 0;

			foreach ($metric_key_map as $slug => $key_patterns) {
				$matched = array_filter($host_items, function($item) use ($key_patterns) {
					foreach ($key_patterns as $pattern) {
						if (str_contains($item['key_'], explode('[', $pattern)[0])) {
							return true;
						}
					}
					return false;
				});

				if (!$matched) continue;
				// Filter matched items to correct unit (% not bytes)
				$matched = MetricConfig::filterItemsByUnit(array_values($matched), $slug);
				if (!$matched) continue;
				$item = reset($matched);
				$iid  = $item['itemid'];
				$vals = $trend_data[$iid] ?? [];
				if (count($vals) < 5) continue;

				$values_raw = array_column($vals, 'value');
				$clocks     = array_column($vals, 'clock');
				$values = array_map(
					fn($v) => min(100.0, max(0.0, MetricConfig::normalizeValue($v, $item['key_'], $slug))),
					$values_raw
				);

				$z  = $engine->zScoreAnomalyScore($values);
				$lr = $engine->linearRegressionForecast($clocks, $values, $time_range, $slug ?? 'disk');

				$ml_result = null;
				if (in_array($model, ['all', 'arima', 'prophet']) && $ml->isAvailable()) {
					$ml_result = $ml->forecast((int)$iid, $values, $clocks, $model);
				}

				$score = $this->blendScore($z, $lr, $ml_result, $model);
				$metric_results[$slug] = [
					'score'          => $score,
					'current'        => round(end($values), 1),
					'forecast_1h'    => round($lr['forecast'][0] ?? 0, 1),
					'breach_eta'     => $lr['breach_eta'],
					'z_score'        => round($z['max_z'], 2),
					'anomaly_points' => $z['anomaly_indices'],
					'sparkline'      => array_map(
						fn($p) => ['x' => $p['clock'], 'y' => round($p['value'], 2)],
						array_slice($vals, -24)
					),
					'forecast_series' => $lr['forecast_series'],
					'ci_upper'        => $lr['ci_upper'],
					'ci_lower'        => $lr['ci_lower'],
					'itemid'          => $iid,
					'item_name'       => $item['name'],
					'units'           => $item['units'],
				];
				$host_score = max($host_score, $score);
			}

			if (!$metric_results) continue;

			$breach_etas = array_filter(
				array_column($metric_results, 'breach_eta'),
				fn($v) => $v !== null
			);

			$host_results[] = [
				'hostid'      => $hid,
				'name'        => $host['name'],
				'score'       => round($host_score, 3),
				'cpu'         => $metric_results['cpu']['current'] ?? 0,
				'memory'      => $metric_results['memory']['current'] ?? 0,
				'disk'        => $metric_results['disk']['current'] ?? 0,
				'metrics'     => $metric_results,
				'breach_etas' => array_values($breach_etas),
			];
		}

		// Filter + sort
		$host_results = array_values(array_filter(
			$host_results, fn($h) => $h['score'] >= $score_threshold
		));

		usort($host_results, function($a, $b) use ($sort_field, $sort_order) {
			$av = $a[$sort_field] ?? 0;
			$bv = $b[$sort_field] ?? 0;
			$cmp = is_string($av) ? strcmp($av, $bv) : ($av <=> $bv);
			return $sort_order === 'DESC' ? -$cmp : $cmp;
		});

		$this->sendJson([
			'groupid'     => $groupid,
			'group_name'  => $group['name'],
			'total_hosts' => $total_hosts,
			'hosts'       => $host_results,
			'page'        => $page,
			'page_size'   => self::PAGE_SIZE,
			'total_pages' => (int) ceil($total_hosts / self::PAGE_SIZE),
		]);
	}

	private function blendScore(?array $z, ?array $lr, ?array $ml, string $model): float {
		if ($model === 'zscore') return $z['score'] ?? 0;
		if ($model === 'linear') return $lr['score'] ?? 0;
		$score  = ($z['score'] ?? 0) * 0.45 + ($lr['score'] ?? 0) * 0.35;
		$weight = 0.80;
		if ($ml) { $score += ($ml['score'] ?? 0) * 0.20; $weight = 1.0; }
		return round(min(1.0, $score / $weight), 3);
	}

	// buildMetricKeyMap removed — using MetricConfig::keyMap()
}
