<?php
/**
 * Action: predictive.anomaly.forecast  (JSON)
 *
 * Returns the full forecast series for a single item.
 * Used when opening the detailed forecast chart in the drilldown
 * drawer. Returns actual series + forecast + confidence band.
 */

namespace Modules\PredictiveAnomaly\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use API;
use Modules\PredictiveAnomaly\Services\CAnomalyEngine;
use Modules\PredictiveAnomaly\Services\CMLBridge;

class CControllerPredictiveAnomalyForecast extends CController {

	public function init(): void {
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
		return true;
	}

	protected function doAction(): void {
		$itemid     = $this->getInput('itemid');
		$time_range = $this->getInput('time_range', '24h');
		$model      = $this->getInput('model', 'all');

		$time_map  = ['1h'=>3600,'6h'=>21600,'24h'=>86400,'7d'=>604800,'30d'=>2592000];
		$time_from = time() - ($time_map[$time_range] ?? 86400);
		$time_till = time();
		$use_trends = in_array($time_range, ['7d', '30d']);

		// Item metadata
		$items = API::Item()->get([
			'output'      => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'units'],
			'itemids'     => [$itemid],
			'preservekeys' => true,
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
				'output'    => ['clock', 'value_avg', 'value_min', 'value_max', 'num'],
				'itemids'   => [$itemid],
				'time_from' => $time_from,
				'time_till' => $time_till,
			]);
			foreach ($raw as $row) {
				$raw_series[] = [
					'clock' => (int)$row['clock'],
					'value' => (float)$row['value_avg'],
					'min'   => (float)$row['value_min'],
					'max'   => (float)$row['value_max'],
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

		// Z-score anomaly detection
		$z_result = $engine->zScoreAnomalyScore($values);

		// Linear regression + forecast
		$lr_result = $engine->linearRegressionForecast($clocks, $values, $time_range);

		// Optional ML enrichment
		$ml_result = null;
		if (in_array($model, ['all', 'arima', 'prophet']) && $ml->isAvailable()) {
			$ml_result = $ml->forecast((int)$itemid, $values, $clocks, $model);
		}

		// Mark anomaly points in series
		$anomaly_set = array_flip($z_result['anomaly_indices']);
		$series_out  = [];
		foreach ($raw_series as $i => $point) {
			$series_out[] = [
				'x'       => $point['clock'] * 1000, // JS timestamp
				'y'       => round($point['value'], 2),
				'anomaly' => isset($anomaly_set[$i]),
				'z'       => round($z_result['z_scores'][$i] ?? 0, 2),
			];
		}

		// Build forecast series
		$forecast_out = [];
		$step         = $clocks[count($clocks)-1] - $clocks[max(0, count($clocks)-2)];
		$step         = max($step, 60);
		$last_clock   = end($clocks);

		foreach ($lr_result['forecast_series'] as $i => $fval) {
			$forecast_out[] = [
				'x'     => ($last_clock + ($i + 1) * $step) * 1000,
				'y'     => round($fval, 2),
				'upper' => round($lr_result['ci_upper'][$i] ?? $fval, 2),
				'lower' => round($lr_result['ci_lower'][$i] ?? $fval, 2),
			];
		}

		// ML forecast overlay
		$ml_out = null;
		if ($ml_result && !empty($ml_result['forecast_series'])) {
			$ml_out = [];
			foreach ($ml_result['forecast_series'] as $i => $fval) {
				$ml_out[] = [
					'x'     => ($last_clock + ($i + 1) * $step) * 1000,
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
				'mean'      => round($z_result['mean'], 2),
				'std'       => round($z_result['std'], 2),
				'max_z'     => round($z_result['max_z'], 2),
				'score'     => round($z_result['score'], 3),
				'slope'     => round($lr_result['slope'], 5),
				'breach_eta'=> $lr_result['breach_eta'],
				'model'     => $ml_result ? ($ml_result['model'] ?? 'linear') : 'linear+zscore',
				'accuracy'  => $ml_result['accuracy'] ?? null,
			],
		]));
	}
}
