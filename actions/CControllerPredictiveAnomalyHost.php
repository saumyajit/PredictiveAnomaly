<?php
/**
 * Action: predictive.anomaly.host  (JSON)
 *
 * Returns per-host anomaly detail for a single host group.
 * Called when the user drills down into a group row.
 * Returns top-N anomalous hosts with per-metric scores and
 * a 24-point sparkline series for inline charts.
 */

namespace Modules\PredictiveAnomaly\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CWebUser;
use API;
use Modules\PredictiveAnomaly\Services\CAnomalyEngine;
use Modules\PredictiveAnomaly\Services\CMLBridge;

class CControllerPredictiveAnomalyHost extends CController {

	const HOST_PAGE_SIZE = 50;

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
			$this->setResponse(new CControllerResponseFatal());
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::isLoggedIn();
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

		$time_seconds = $this->timeRangeToSeconds($time_range);
		$time_from    = time() - $time_seconds;
		$time_till    = time();
		$use_trends   = in_array($time_range, ['7d', '30d']);

		// Fetch group info
		$groups = API::HostGroup()->get([
			'output'   => ['groupid', 'name'],
			'groupids' => [$groupid],
		]);
		$group = $groups[0] ?? ['name' => 'Unknown'];

		// Fetch all hosts in group
		$all_hosts = API::Host()->get([
			'output'          => ['hostid', 'name', 'status', 'description'],
			'groupids'        => [$groupid],
			'monitored_hosts' => true,
			'preservekeys'    => true,
			'sortfield'       => 'name',
		]);

		$total_hosts = count($all_hosts);

		// For performance: only process paginated slice
		$paged_hosts = array_slice(
			array_values($all_hosts),
			($page - 1) * self::HOST_PAGE_SIZE,
			self::HOST_PAGE_SIZE,
			true
		);

		$engine = new CAnomalyEngine();
		$ml     = new CMLBridge();

		// Build metric key map
		$metric_key_map = $this->buildMetricKeyMap($metrics);

		// Gather items for paged hosts
		$paged_hostids = array_column($paged_hosts, 'hostid');

		$all_items = API::Item()->get([
			'output'       => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'units', 'lastvalue', 'lastclock'],
			'hostids'      => $paged_hostids,
			'filter'       => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]],
			'monitored'    => true,
			'preservekeys' => true,
		]);

		// Fetch trend/history data
		$itemids = array_keys($all_items);
		$trend_data = [];

		if ($itemids) {
			if ($use_trends) {
				$raw = API::Trend()->get([
					'output'    => ['itemid', 'clock', 'value_avg', 'value_min', 'value_max'],
					'itemids'   => $itemids,
					'time_from' => $time_from,
					'time_till' => $time_till,
					'limit'     => 50000,
				]);
				foreach ($raw as $row) {
					$trend_data[$row['itemid']][] = [
						'clock' => (int)$row['clock'],
						'value' => (float)$row['value_avg'],
						'min'   => (float)$row['value_min'],
						'max'   => (float)$row['value_max'],
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
					'limit'     => 100000,
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
			$hid = $host['hostid'];

			// Items belonging to this host
			$host_items = array_filter($all_items, fn($item) => $item['hostid'] == $hid);

			$metric_results = [];
			$host_score     = 0;

			foreach ($metric_key_map as $slug => $key_patterns) {
				// Find matching items for this metric
				$matched = array_filter($host_items, function($item) use ($key_patterns) {
					foreach ($key_patterns as $pattern) {
						if (str_contains($item['key_'], explode('[', $pattern)[0])) {
							return true;
						}
					}
					return false;
				});

				if (!$matched) continue;

				// Use the first matched item
				$item  = reset($matched);
				$iid   = $item['itemid'];
				$vals  = $trend_data[$iid] ?? [];

				if (count($vals) < 5) continue;

				$values = array_column($vals, 'value');
				$clocks = array_column($vals, 'clock');

				$z_result  = $engine->zScoreAnomalyScore($values);
				$lr_result = $engine->linearRegressionForecast($clocks, $values, $time_range);

				$ml_result = null;
				if (in_array($model, ['all', 'arima', 'prophet']) && $ml->isAvailable()) {
					$ml_result = $ml->forecast($iid, $values, $clocks, $model);
				}

				$score = $this->blendScore($z_result, $lr_result, $ml_result, $model);

				$metric_results[$slug] = [
					'score'         => $score,
					'current'       => round(end($values), 1),
					'max'           => round(max($values), 1),
					'forecast_1h'   => round($lr_result['forecast'][0] ?? 0, 1),
					'forecast_6h'   => round($lr_result['forecast'][5] ?? 0, 1),
					'breach_eta'    => $lr_result['breach_eta'],
					'slope'         => round($lr_result['slope'], 4),
					'z_score'       => round($z_result['max_z'], 2),
					'anomaly_points'=> $z_result['anomaly_indices'],
					// Sparkline: last 24 data points
					'sparkline'     => array_map(fn($p) => [
						'x' => $p['clock'],
						'y' => round($p['value'], 2),
					], array_slice($vals, -24)),
					// Forecast band for drilldown chart
					'forecast_series' => $lr_result['forecast_series'],
					'ci_upper'        => $lr_result['ci_upper'],
					'ci_lower'        => $lr_result['ci_lower'],
					'itemid'          => $iid,
					'item_name'       => $item['name'],
					'units'           => $item['units'],
				];

				$host_score = max($host_score, $score);
			}

			if (empty($metric_results)) continue;

			// Flatten for sort
			$host_results[] = [
				'hostid'      => $hid,
				'name'        => $host['name'],
				'score'       => round($host_score, 3),
				'cpu'         => $metric_results['cpu']['current'] ?? 0,
				'memory'      => $metric_results['memory']['current'] ?? 0,
				'disk'        => $metric_results['disk']['current'] ?? 0,
				'metrics'     => $metric_results,
				'breach_etas' => array_filter(
					array_column($metric_results, 'breach_eta'),
					fn($v) => $v !== null
				),
			];
		}

		// Filter by threshold
		$host_results = array_filter($host_results, fn($h) => $h['score'] >= $score_threshold);

		// Sort
		usort($host_results, function ($a, $b) use ($sort_field, $sort_order) {
			$av = $a[$sort_field] ?? 0;
			$bv = $b[$sort_field] ?? 0;
			$cmp = is_string($av) ? strcmp($av, $bv) : ($av <=> $bv);
			return $sort_order === 'DESC' ? -$cmp : $cmp;
		});

		$this->setResponse(new CControllerResponseData([
			'groupid'     => $groupid,
			'group_name'  => $group['name'],
			'total_hosts' => $total_hosts,
			'hosts'       => array_values($host_results),
			'page'        => $page,
			'page_size'   => self::HOST_PAGE_SIZE,
			'total_pages' => (int) ceil($total_hosts / self::HOST_PAGE_SIZE),
		]));
	}

	private function blendScore(?array $z, ?array $lr, ?array $ml, string $model): float {
		if ($model === 'zscore') return $z['score'] ?? 0;
		if ($model === 'linear') return $lr['score'] ?? 0;

		$score  = ($z['score'] ?? 0) * 0.45 + ($lr['score'] ?? 0) * 0.35;
		$weight = 0.80;

		if ($ml) {
			$score  += ($ml['score'] ?? 0) * 0.20;
			$weight  = 1.0;
		}
		return round(min(1.0, $score / $weight), 3);
	}

	private function buildMetricKeyMap(array $metrics): array {
		$map = [
			'cpu'     => ['system.cpu.util', 'system.cpu.load'],
			'memory'  => ['vm.memory.utilization', 'vm.memory.size'],
			'disk'    => ['vfs.fs.size', 'vfs.fs.inode'],
			'network' => ['net.if.in', 'net.if.out'],
			'iops'    => ['vfs.dev.read.ops', 'vfs.dev.write.ops'],
			'load'    => ['system.cpu.load'],
		];
		$result = [];
		foreach ($metrics as $m) {
			if (isset($map[$m])) $result[$m] = $map[$m];
		}
		return $result;
	}

	private function timeRangeToSeconds(string $range): int {
		return match($range) {
			'1h'  => 3600,
			'6h'  => 21600,
			'24h' => 86400,
			'7d'  => 604800,
			'30d' => 2592000,
			default => 86400,
		};
	}
}
