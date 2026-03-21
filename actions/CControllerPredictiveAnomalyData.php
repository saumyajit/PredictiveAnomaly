<?php
/**
 * Action: predictive.anomaly.data  (JSON)
 *
 * Aggregates anomaly scores at the HOST GROUP level.
 * Called asynchronously from the frontend after the page loads.
 *
 * Pipeline per group:
 *   1. Fetch items matching selected metrics
 *   2. Pull Zabbix trend data (trend.get) for each item
 *   3. Run Z-score anomaly detection (native PHP, no deps)
 *   4. Run linear regression on trend values → forecast
 *   5. Return per-group aggregated scores + per-metric summaries
 *
 * Scale strategy: Process groups in pages (default 50 groups/request).
 * Frontend fetches pages in parallel and merges results.
 */

namespace Modules\PredictiveAnomaly\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use API;
use Modules\PredictiveAnomaly\Services\CAnomalyEngine;
use Modules\PredictiveAnomaly\Services\CMLBridge;

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
			'sort_field'      => 'in name,score,hosts,alerts,cpu,memory,disk',
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

		// ── Time range ────────────────────────────────────────────────────
		$time_seconds = $this->timeRangeToSeconds($time_range);
		$time_from    = time() - $time_seconds;
		$time_till    = time();

		// ── Resolve groups ────────────────────────────────────────────────
		$group_query = [
			'output'       => ['groupid', 'name'],
			'with_hosts'   => true,
			'preservekeys' => true,
			'sortfield'    => 'name',
		];
		if ($groupids) {
			$group_query['groupids'] = $groupids;
		}

		$all_groups = API::HostGroup()->get($group_query);
		$total_groups = count($all_groups);

		// Paginate
		$paged_groups = array_slice(
			array_values($all_groups),
			($page - 1) * self::PAGE_SIZE,
			self::PAGE_SIZE,
			true
		);

		// ── Metric-to-item-key mapping ────────────────────────────────────
		// These are Zabbix standard template keys. In production you may
		// need to adapt these to your template naming conventions.
		$metric_keys = $this->buildMetricKeyMap($metrics);

		// ── Process each group ────────────────────────────────────────────
		$engine = new CAnomalyEngine();
		$ml     = new CMLBridge();

		$group_results = [];

		foreach ($paged_groups as $group) {
			$result = $this->processGroup(
				$group,
				$metric_keys,
				$time_from,
				$time_till,
				$time_range,
				$model,
				$engine,
				$ml
			);

			if ($result['anomaly_score'] >= $score_threshold) {
				$group_results[] = $result;
			}
		}

		// ── Sort ──────────────────────────────────────────────────────────
		usort($group_results, function ($a, $b) use ($sort_field, $sort_order) {
			$av = $a[$sort_field] ?? 0;
			$bv = $b[$sort_field] ?? 0;
			if (is_string($av)) {
				$cmp = strcmp($av, $bv);
			} else {
				$cmp = $av <=> $bv;
			}
			return $sort_order === 'DESC' ? -$cmp : $cmp;
		});

		// ── Fleet summary ─────────────────────────────────────────────────
		$summary = $this->buildFleetSummary($group_results, $time_from, $time_till);

		$this->setResponse(new CControllerResponseData([
			'groups'       => $group_results,
			'summary'      => $summary,
			'total_groups' => $total_groups,
			'page'         => $page,
			'page_size'    => self::PAGE_SIZE,
			'total_pages'  => (int) ceil($total_groups / self::PAGE_SIZE),
		]));
	}

	// ─────────────────────────────────────────────────────────────────────
	// Process one host group → anomaly + forecast result
	// ─────────────────────────────────────────────────────────────────────
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

		// Fetch hosts in group
		$hosts = API::Host()->get([
			'output'      => ['hostid', 'name', 'status'],
			'groupids'    => [$groupid],
			'monitored_hosts' => true,
			'preservekeys' => true,
			'limit'       => 500, // cap for perf; summary view aggregates anyway
		]);

		$hostids       = array_keys($hosts);
		$host_count    = count($hostids);
		$metric_scores = [];
		$worst_host    = '';
		$worst_score   = 0;
		$anomalous     = 0;
		$predicted_alerts = 0;
		$metric_avgs   = ['cpu' => 0, 'memory' => 0, 'disk' => 0];

		if (!$hostids) {
			return $this->emptyGroupResult($group, $host_count);
		}

		// For each metric, pull trends and score
		foreach ($metric_keys as $metric_slug => $key_patterns) {
			// Get items matching this metric across the group
			$items = API::Item()->get([
				'output'       => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'units', 'lastvalue'],
				'hostids'      => $hostids,
				'search'       => ['key_' => $key_patterns[0]],
				'searchByAny'  => true,
				'filter'       => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]],
				'monitored'    => true,
				'preservekeys' => true,
				'limit'        => count($hostids) * 3,
			]);

			if (!$items) continue;

			// Use trend data for longer ranges, history for short
			$use_trends = in_array($time_range, ['7d', '30d']);
			$trend_data = [];

			if ($use_trends) {
				$raw = API::Trend()->get([
					'output'   => ['itemid', 'clock', 'value_avg', 'value_min', 'value_max', 'num'],
					'itemids'  => array_keys($items),
					'time_from' => $time_from,
					'time_till' => $time_till,
					'limit'    => 10000,
				]);
				foreach ($raw as $row) {
					$trend_data[$row['itemid']][] = [
						'clock' => (int) $row['clock'],
						'value' => (float) $row['value_avg'],
						'min'   => (float) $row['value_min'],
						'max'   => (float) $row['value_max'],
					];
				}
			} else {
				$raw = API::History()->get([
					'output'    => ['itemid', 'clock', 'value'],
					'itemids'   => array_keys($items),
					'time_from' => $time_from,
					'time_till' => $time_till,
					'history'   => ITEM_VALUE_TYPE_FLOAT,
					'sortfield' => 'clock',
					'sortorder' => 'ASC',
					'limit'     => 20000,
				]);
				foreach ($raw as $row) {
					$trend_data[$row['itemid']][] = [
						'clock' => (int) $row['clock'],
						'value' => (float) $row['value'],
					];
				}
			}

			// Score each item and aggregate per host
			$host_metric_scores = [];
			foreach ($items as $item) {
				$iid  = $item['itemid'];
				$hid  = $item['hostid'];
				$vals = $trend_data[$iid] ?? [];
				if (count($vals) < 5) continue;

				$values  = array_column($vals, 'value');
				$clocks  = array_column($vals, 'clock');

				// Z-score anomaly detection (always run, no deps)
				$z_result = $engine->zScoreAnomalyScore($values);

				// Linear regression forecast (native PHP)
				$lr_result = $engine->linearRegressionForecast($clocks, $values, $time_range);

				// Optionally enrich with ML sidecar if available
				$ml_result = null;
				if (in_array($model, ['all', 'arima', 'prophet']) && $ml->isAvailable()) {
					$ml_result = $ml->forecast($iid, $values, $clocks, $model);
				}

				$score = $this->blendScore($z_result, $lr_result, $ml_result, $model);

				$host_metric_scores[$hid][$metric_slug] = [
					'score'       => $score,
					'z_score'     => $z_result['max_z'],
					'current_val' => end($values),
					'forecast'    => $lr_result['forecast'],
					'trend_slope' => $lr_result['slope'],
					'breach_eta'  => $lr_result['breach_eta'],
					'series'      => array_slice($vals, -24), // last 24 points for sparkline
					'itemid'      => $iid,
					'item_name'   => $item['name'],
				];
			}

			// Aggregate metric score across hosts in group
			$all_scores = [];
			foreach ($host_metric_scores as $hid => $hmetrics) {
				if (isset($hmetrics[$metric_slug])) {
					$s = $hmetrics[$metric_slug]['score'];
					$all_scores[] = $s;

					// Track worst host
					if ($s > $worst_score) {
						$worst_score = $s;
						$worst_host  = $hosts[$hid]['name'] ?? '';
					}

					// Count anomalous hosts (score >= 0.5)
					if ($s >= 0.5 && !isset($counted[$hid])) {
						$anomalous++;
						$counted[$hid] = true;
					}
				}
			}

			if ($all_scores) {
				$metric_scores[$metric_slug] = [
					'avg_score'   => array_sum($all_scores) / count($all_scores),
					'max_score'   => max($all_scores),
					'avg_value'   => $this->avgMetricValue($host_metric_scores, $metric_slug),
					'breach_count' => count(array_filter($all_scores, fn($s) => $s >= 0.7)),
				];
				$metric_avgs[$metric_slug] = $metric_scores[$metric_slug]['avg_value'];

				// Count predicted alerts (high-confidence high-score items)
				$predicted_alerts += $metric_scores[$metric_slug]['breach_count'];
			}
		}

		// ── Composite group anomaly score ─────────────────────────────────
		$scores_flat = array_column($metric_scores, 'avg_score');
		$anomaly_score = $scores_flat
			? round(max($scores_flat) * 0.6 + (array_sum($scores_flat) / count($scores_flat)) * 0.4, 3)
			: 0;

		return [
			'groupid'          => $groupid,
			'name'             => $group['name'],
			'host_count'       => $host_count,
			'anomalous'        => $anomalous,
			'anomaly_score'    => $anomaly_score,
			'score'            => $anomaly_score, // alias for sort
			'worst_host'       => $worst_host,
			'predicted_alerts' => $predicted_alerts,
			'metrics'          => $metric_scores,
			// Flat avg values for table columns
			'cpu'              => round($metric_avgs['cpu'] ?? 0, 1),
			'memory'           => round($metric_avgs['memory'] ?? 0, 1),
			'disk'             => round($metric_avgs['disk'] ?? 0, 1),
		];
	}

	// ─────────────────────────────────────────────────────────────────────
	// Blend multiple model scores into one composite
	// ─────────────────────────────────────────────────────────────────────
	private function blendScore(
		array $z_result,
		array $lr_result,
		?array $ml_result,
		string $model
	): float {
		if ($model === 'zscore') {
			return $z_result['score'];
		}
		if ($model === 'linear') {
			return $lr_result['score'];
		}

		// Default: weighted blend
		$score = $z_result['score'] * 0.45 + $lr_result['score'] * 0.35;
		$weight = 0.80;

		if ($ml_result) {
			$score += $ml_result['score'] * 0.20;
			$weight = 1.0;
		}

		return round(min(1.0, $score / $weight), 3);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Fleet-wide summary aggregation
	// ─────────────────────────────────────────────────────────────────────
	private function buildFleetSummary(array $results, int $time_from, int $time_till): array {
		$total_anomalous  = array_sum(array_column($results, 'anomalous'));
		$total_alerts     = array_sum(array_column($results, 'predicted_alerts'));
		$scores           = array_column($results, 'anomaly_score');
		$exhaustion_count = count(array_filter($results, fn($g) => ($g['disk'] ?? 0) >= 80));

		return [
			'anomalous_hosts'  => $total_anomalous,
			'predicted_alerts' => $total_alerts,
			'exhaustion_risk'  => $exhaustion_count,
			'avg_score'        => $scores ? round(array_sum($scores) / count($scores), 3) : 0,
			'max_score'        => $scores ? max($scores) : 0,
			'time_from'        => $time_from,
			'time_till'        => $time_till,
		];
	}

	// ─────────────────────────────────────────────────────────────────────
	// Metric key patterns per slug
	// ─────────────────────────────────────────────────────────────────────
	private function buildMetricKeyMap(array $metrics): array {
		$all_keys = [
			'cpu'     => ['system.cpu.util', 'system.cpu.load'],
			'memory'  => ['vm.memory.utilization', 'vm.memory.size[pavailable]'],
			'disk'    => ['vfs.fs.size[/,pused]', 'vfs.fs.size[/data,pused]', 'vfs.fs.inode'],
			'network' => ['net.if.in', 'net.if.out'],
			'iops'    => ['vfs.dev.read.ops', 'vfs.dev.write.ops'],
			'load'    => ['system.cpu.load[percpu,avg5]'],
		];

		$result = [];
		foreach ($metrics as $m) {
			if (isset($all_keys[$m])) {
				$result[$m] = $all_keys[$m];
			}
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

	private function emptyGroupResult(array $group, int $host_count): array {
		return [
			'groupid'          => $group['groupid'],
			'name'             => $group['name'],
			'host_count'       => $host_count,
			'anomalous'        => 0,
			'anomaly_score'    => 0,
			'score'            => 0,
			'worst_host'       => '',
			'predicted_alerts' => 0,
			'metrics'          => [],
			'cpu'              => 0,
			'memory'           => 0,
			'disk'             => 0,
		];
	}

	private function avgMetricValue(array $host_metric_scores, string $metric_slug): float {
		$vals = [];
		foreach ($host_metric_scores as $hmetrics) {
			if (isset($hmetrics[$metric_slug])) {
				$vals[] = $hmetrics[$metric_slug]['current_val'];
			}
		}
		return $vals ? array_sum($vals) / count($vals) : 0;
	}
}
