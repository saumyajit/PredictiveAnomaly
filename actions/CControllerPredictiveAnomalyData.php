<?php
/**
 * Action: predictive.anomaly.data  (JSON — no view/layout in manifest)
 *
 * Aggregates anomaly scores per host group.
 * Called asynchronously from JS after page load.
 * Paginated: 50 groups per request.
 */

namespace Modules\PredictiveAnomaly\actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use API;
use Modules\PredictiveAnomaly\services\CAnomalyEngine;
use Modules\PredictiveAnomaly\services\CMLBridge;

class CControllerPredictiveAnomalyData extends CController {

	const PAGE_SIZE = 50;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'groupids'        => 'array_id',
			'metrics'         => 'array',
			'time_range'      => 'in 1h,6h,24h,7d,30d',
			'score_threshold' => 'string',
			'model'           => 'in all,zscore,linear,arima,prophet',
			'sort_field'      => 'in name,score,anomalous,alerts,cpu,memory,disk',
			'sort_order'      => 'in ASC,DESC',
			'page'            => 'ge 1',
		];
		$ret = $this->validateInput($fields);
		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$groupids        = $this->getInput('groupids', []);
		$metrics         = $this->getInput('metrics', ['cpu', 'memory', 'disk']);
		$time_range      = $this->getInput('time_range', '24h');
		$score_threshold = (float) $this->getInput('score_threshold', '0.2');
		$model           = $this->getInput('model', 'all');
		$sort_field      = $this->getInput('sort_field', 'score');
		$sort_order      = $this->getInput('sort_order', 'DESC');
		$page            = (int) $this->getInput('page', 1);

		$time_map  = ['1h'=>3600,'6h'=>21600,'24h'=>86400,'7d'=>604800,'30d'=>2592000];
		$time_from = time() - ($time_map[$time_range] ?? 86400);
		$time_till = time();

		// ── Fetch groups ──────────────────────────────────────────────────
		$group_query = [
			'output'       => ['groupid', 'name'],
			'with_hosts'   => true,
			'preservekeys' => true,
			'sortfield'    => 'name',
		];
		if ($groupids) {
			$group_query['groupids'] = $groupids;
		}

		$all_groups   = API::HostGroup()->get($group_query);
		$total_groups = count($all_groups);
		$total_pages  = (int) ceil($total_groups / self::PAGE_SIZE);

		$paged_groups = array_slice(
			array_values($all_groups),
			($page - 1) * self::PAGE_SIZE,
			self::PAGE_SIZE
		);

		// ── Metric key map ────────────────────────────────────────────────
		$metric_keys = $this->buildMetricKeyMap($metrics);

		// ── Score each group ──────────────────────────────────────────────
		$engine = new CAnomalyEngine();
		$ml     = new CMLBridge();

		$group_results = [];
		foreach ($paged_groups as $group) {
			$result = $this->processGroup(
				$group, $metric_keys, $time_from, $time_till, $time_range, $model, $engine, $ml
			);
			if ($result['anomaly_score'] >= $score_threshold) {
				$group_results[] = $result;
			}
		}

		// ── Sort ──────────────────────────────────────────────────────────
		usort($group_results, function($a, $b) use ($sort_field, $sort_order) {
			$av = $a[$sort_field] ?? 0;
			$bv = $b[$sort_field] ?? 0;
			$cmp = is_string($av) ? strcmp($av, $bv) : ($av <=> $bv);
			return $sort_order === 'DESC' ? -$cmp : $cmp;
		});

		// ── Fleet summary ─────────────────────────────────────────────────
		$summary = [
			'anomalous_hosts'  => array_sum(array_column($group_results, 'anomalous')),
			'predicted_alerts' => array_sum(array_column($group_results, 'predicted_alerts')),
			'exhaustion_risk'  => count(array_filter($group_results, fn($g) => ($g['disk'] ?? 0) >= 80)),
		];

		$this->setResponse(new CControllerResponseData([
			'groups'       => $group_results,
			'summary'      => $summary,
			'total_groups' => $total_groups,
			'page'         => $page,
			'page_size'    => self::PAGE_SIZE,
			'total_pages'  => $total_pages,
		]));
	}

	private function processGroup(
		array $group,
		array $metric_keys,
		int $time_from,
		int $time_till,
		string $time_range,
		string $model,
		CAnomalyEngine $engine,
		CMLBridge $ml
	): array {
		$groupid = $group['groupid'];

		$hosts = API::Host()->get([
			'output'          => ['hostid', 'name'],
			'groupids'        => [$groupid],
			'monitored_hosts' => true,
			'preservekeys'    => true,
			'limit'           => 500,
		]);

		$hostids    = array_keys($hosts);
		$host_count = count($hostids);

		if (!$hostids) {
			return $this->emptyGroup($group, $host_count);
		}

		$use_trends    = in_array($time_range, ['7d', '30d']);
		$metric_scores = [];
		$worst_host    = '';
		$worst_score   = 0;
		$anomalous     = 0;
		$alerts        = 0;
		$metric_avgs   = ['cpu' => 0, 'memory' => 0, 'disk' => 0];
		$counted       = [];

		foreach ($metric_keys as $slug => $key_patterns) {
			$items = API::Item()->get([
				'output'       => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'units'],
				'hostids'      => $hostids,
				'search'       => ['key_' => $key_patterns[0]],
				'searchByAny'  => true,
				'filter'       => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]],
				'monitored'    => true,
				'preservekeys' => true,
				'limit'        => count($hostids) * 3,
			]);

			if (!$items) continue;

			$trend_data = $this->fetchTrendData(array_keys($items), $time_from, $time_till, $use_trends);

			$host_scores = [];
			foreach ($items as $item) {
				$iid  = $item['itemid'];
				$hid  = $item['hostid'];
				$vals = $trend_data[$iid] ?? [];
				if (count($vals) < 5) continue;

				$values = array_column($vals, 'value');
				$clocks = array_column($vals, 'clock');

				$z  = $engine->zScoreAnomalyScore($values);
				$lr = $engine->linearRegressionForecast($clocks, $values, $time_range);

				$ml_result = null;
				if (in_array($model, ['all', 'arima', 'prophet']) && $ml->isAvailable()) {
					$ml_result = $ml->forecast((int)$iid, $values, $clocks, $model);
				}

				$score = $this->blendScore($z, $lr, $ml_result, $model);

				if (!isset($host_scores[$hid]) || $score > $host_scores[$hid]) {
					$host_scores[$hid] = $score;
				}

				if ($score > $worst_score) {
					$worst_score = $score;
					$worst_host  = $hosts[$hid]['name'] ?? '';
				}
			}

			$all_scores = array_values($host_scores);
			if ($all_scores) {
				$avg = array_sum($all_scores) / count($all_scores);
				$metric_scores[$slug] = ['avg_score' => $avg, 'max_score' => max($all_scores)];

				foreach ($host_scores as $hid => $s) {
					if ($s >= 0.5 && !isset($counted[$hid])) {
						$anomalous++;
						$counted[$hid] = true;
					}
				}
				$alerts += count(array_filter($all_scores, fn($s) => $s >= 0.7));

				// Average current metric value for table columns
				$avg_val = 0;
				$cnt     = 0;
				foreach ($items as $item) {
					$iid  = $item['itemid'];
					$vals = $trend_data[$iid] ?? [];
					if ($vals) {
						$avg_val += end(array_column($vals, 'value'));
						$cnt++;
					}
				}
				$metric_avgs[$slug] = $cnt > 0 ? round($avg_val / $cnt, 1) : 0;
			}
		}

		$scores_flat  = array_column($metric_scores, 'avg_score');
		$anomaly_score = $scores_flat
			? round(max($scores_flat) * 0.6 + (array_sum($scores_flat) / count($scores_flat)) * 0.4, 3)
			: 0;

		return [
			'groupid'          => $groupid,
			'name'             => $group['name'],
			'host_count'       => $host_count,
			'anomalous'        => $anomalous,
			'anomaly_score'    => $anomaly_score,
			'score'            => $anomaly_score,
			'worst_host'       => $worst_host,
			'predicted_alerts' => $alerts,
			'metrics'          => $metric_scores,
			'cpu'              => $metric_avgs['cpu'] ?? 0,
			'memory'           => $metric_avgs['memory'] ?? 0,
			'disk'             => $metric_avgs['disk'] ?? 0,
		];
	}

	private function fetchTrendData(array $itemids, int $time_from, int $time_till, bool $use_trends): array {
		$data = [];
		if (!$itemids) return $data;

		if ($use_trends) {
			$raw = API::Trend()->get([
				'output'    => ['itemid', 'clock', 'value_avg', 'value_min', 'value_max'],
				'itemids'   => $itemids,
				'time_from' => $time_from,
				'time_till' => $time_till,
				'limit'     => 20000,
			]);
			foreach ($raw as $row) {
				$data[$row['itemid']][] = [
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
				'limit'     => 50000,
			]);
			foreach ($raw as $row) {
				$data[$row['itemid']][] = [
					'clock' => (int)$row['clock'],
					'value' => (float)$row['value'],
				];
			}
		}
		return $data;
	}

	private function blendScore(?array $z, ?array $lr, ?array $ml, string $model): float {
		if ($model === 'zscore') return $z['score'] ?? 0;
		if ($model === 'linear') return $lr['score'] ?? 0;

		$score  = ($z['score'] ?? 0) * 0.45 + ($lr['score'] ?? 0) * 0.35;
		$weight = 0.80;
		if ($ml) { $score += ($ml['score'] ?? 0) * 0.20; $weight = 1.0; }
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

	private function emptyGroup(array $group, int $host_count): array {
		return [
			'groupid' => $group['groupid'], 'name' => $group['name'],
			'host_count' => $host_count, 'anomalous' => 0, 'anomaly_score' => 0,
			'score' => 0, 'worst_host' => '', 'predicted_alerts' => 0,
			'metrics' => [], 'cpu' => 0, 'memory' => 0, 'disk' => 0,
		];
	}
}
