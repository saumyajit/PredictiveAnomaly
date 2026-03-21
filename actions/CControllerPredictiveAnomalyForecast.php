<?php
/**
 * Action: predictive.anomaly.forecast  (JSON — no view/layout in manifest)
 *
 * Full forecast series for a single item. Used in drilldown chart.
 */

namespace Modules\PredictiveAnomaly\actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use API;
use Modules\PredictiveAnomaly\services\CAnomalyEngine;
use Modules\PredictiveAnomaly\services\CMLBridge;

class CControllerPredictiveAnomalyForecast extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'itemid'     => 'required|id',
			'time_range' => 'in 1h,6h,24h,7d,30d',
			'model'      => 'in all,zscore,linear,arima,prophet',
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
		$itemid     = $this->getInput('itemid');
		$time_range = $this->getInput('time_range', '24h');
		$model      = $this->getInput('model', 'all');

		$time_map   = ['1h'=>3600,'6h'=>21600,'24h'=>86400,'7d'=>604800,'30d'=>2592000];
		$time_from  = time() - ($time_map[$time_range] ?? 86400);
		$time_till  = time();
		$use_trends = in_array($time_range, ['7d', '30d']);

		// Item metadata
		$items = API::Item()->get([
			'output'      => ['itemid', 'name', 'key_', 'value_type', 'units'],
			'itemids'     => [$itemid],
			'preservekeys'=> true,
		]);

		if (!$items) {
			$this->setResponse(new CControllerResponseData(['error' => 'Item not found']));
			return;
		}
		$item = reset($items);

		// Fetch data
		$raw_series = [];
		if ($use_trends) {
			$raw = API::Trend()->get([
				'output'    => ['clock', 'value_avg', 'value_min', 'value_max'],
				'itemids'   => [$itemid],
				'time_from' => $time_from,
				'time_till' => $time_till,
			]);
			foreach ($raw as $row) {
				$raw_series[] = [
					'clock' => (int)$row['clock'],
					'value' => (float)$row['value_avg'],
				];
			}
		} else {
			$raw = API::History()->get([
				'output'    => ['clock', 'value'],
				'itemids'   => [$itemid],
				'time_from' => $time_from,
				'time_till' => $time_till,
				'history'   => $item['value_type'],
				'sortfield' => 'clock',
				'sortorder' => 'ASC',
			]);
			foreach ($raw as $row) {
				$raw_series[] = [
					'clock' => (int)$row['clock'],
					'value' => (float)$row['value'],
				];
			}
		}

		if (count($raw_series) < 5) {
			$this->setResponse(new CControllerResponseData(['error' => 'Insufficient data']));
			return;
		}

		$values = array_column($raw_series, 'value');
		$clocks = array_column($raw_series, 'clock');

		$engine = new CAnomalyEngine();
		$ml     = new CMLBridge();

		$z  = $engine->zScoreAnomalyScore($values);
		$lr = $engine->linearRegressionForecast($clocks, $values, $time_range);

		$ml_result = null;
		if (in_array($model, ['all', 'arima', 'prophet']) && $ml->isAvailable()) {
			$ml_result = $ml->forecast((int)$itemid, $values, $clocks, $model);
		}

		// Build output series with anomaly flags
		$anomaly_set = array_flip($z['anomaly_indices']);
		$series_out  = [];
		foreach ($raw_series as $i => $point) {
			$series_out[] = [
				'x'       => $point['clock'] * 1000,
				'y'       => round($point['value'], 2),
				'anomaly' => isset($anomaly_set[$i]),
				'z'       => round($z['z_scores'][$i] ?? 0, 2),
			];
		}

		// Forecast series
		$n    = count($clocks);
		$step = $n >= 2 ? max((int)(($clocks[$n-1] - $clocks[0]) / ($n - 1)), 60) : 3600;
		$last = end($clocks);
		$forecast_out = [];
		foreach ($lr['forecast_series'] as $i => $fval) {
			$forecast_out[] = [
				'x'     => ($last + ($i + 1) * $step) * 1000,
				'y'     => round($fval, 2),
				'upper' => round($lr['ci_upper'][$i] ?? $fval, 2),
				'lower' => round($lr['ci_lower'][$i] ?? $fval, 2),
			];
		}

		// Optional ML overlay
		$ml_out = null;
		if ($ml_result && !empty($ml_result['forecast_series'])) {
			$ml_out = [];
			foreach ($ml_result['forecast_series'] as $i => $fval) {
				$ml_out[] = [
					'x'     => ($last + ($i + 1) * $step) * 1000,
					'y'     => round($fval, 2),
					'upper' => round($ml_result['ci_upper'][$i] ?? $fval, 2),
					'lower' => round($ml_result['ci_lower'][$i] ?? $fval, 2),
				];
			}
		}

		$this->setResponse(new CControllerResponseData([
			'itemid'      => $itemid,
			'item_name'   => $item['name'],
			'units'       => $item['units'],
			'series'      => $series_out,
			'forecast'    => $forecast_out,
			'ml_forecast' => $ml_out,
			'stats'       => [
				'mean'       => round($z['mean'], 2),
				'std'        => round($z['std'], 2),
				'max_z'      => round($z['max_z'], 2),
				'score'      => round($z['score'], 3),
				'slope'      => round($lr['slope'], 5),
				'breach_eta' => $lr['breach_eta'],
				'model'      => $ml_result ? ($ml_result['model'] ?? 'linear') : 'linear+zscore',
				'accuracy'   => $ml_result['accuracy'] ?? null,
			],
		]));
	}
}
