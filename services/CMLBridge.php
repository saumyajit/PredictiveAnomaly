<?php
/**
 * CMLBridge
 *
 * Optional bridge to an external Python/Flask ML sidecar service
 * running Prophet or ARIMA. The module works fully without it —
 * if the service is unreachable, isAvailable() returns false and
 * all callers fall back to native Z-score + linear regression.
 *
 * Configuration: set PREDICTIVE_ML_API_URL in zabbix.conf.php or
 * define the constant before including this file.
 *
 * Example sidecar endpoint contract:
 *   POST /forecast
 *   Body: { itemid, values, clocks, model }
 *   Returns: { model, forecast_series, ci_upper, ci_lower, accuracy, score }
 */

namespace Modules\PredictiveAnomaly\Services;

class CMLBridge {

	// Override via define('PREDICTIVE_ML_API_URL', 'http://...');
	const DEFAULT_URL = 'http://127.0.0.1:5001';
	const TIMEOUT_MS  = 2000; // 2s hard timeout — never block page load
	const CACHE_TTL   = 300;  // cache results 5 min per item

	private string $api_url;
	private bool   $available;
	private array  $cache = [];

	public function __construct() {
		$this->api_url   = defined('PREDICTIVE_ML_API_URL')
			? PREDICTIVE_ML_API_URL
			: self::DEFAULT_URL;
		$this->available = $this->ping();
	}

	public function isAvailable(): bool {
		return $this->available;
	}

	/**
	 * Request a forecast from the ML sidecar.
	 * Returns null on failure — callers must handle gracefully.
	 */
	public function forecast(
		int    $itemid,
		array  $values,
		array  $clocks,
		string $model = 'all'
	): ?array {
		if (!$this->available) return null;

		// Check in-memory cache (per request lifecycle)
		$cache_key = "{$itemid}_{$model}_" . end($clocks);
		if (isset($this->cache[$cache_key])) {
			return $this->cache[$cache_key];
		}

		$payload = json_encode([
			'itemid' => $itemid,
			'values' => $values,
			'clocks' => $clocks,
			'model'  => $model,
		]);

		$ctx = stream_context_create([
			'http' => [
				'method'  => 'POST',
				'header'  => "Content-Type: application/json\r\n" .
				             "Accept: application/json\r\n",
				'content' => $payload,
				'timeout' => self::TIMEOUT_MS / 1000,
				'ignore_errors' => true,
			],
		]);

		$result = @file_get_contents($this->api_url . '/forecast', false, $ctx);

		if ($result === false) {
			$this->available = false; // don't retry on subsequent calls
			return null;
		}

		$decoded = json_decode($result, true);

		if (!$decoded || !isset($decoded['forecast_series'])) {
			return null;
		}

		$this->cache[$cache_key] = $decoded;
		return $decoded;
	}

	/**
	 * Quick availability check — HEAD request to /health endpoint.
	 */
	private function ping(): bool {
		$ctx = stream_context_create([
			'http' => [
				'method'  => 'GET',
				'timeout' => 0.5,
				'ignore_errors' => true,
			],
		]);

		$result = @file_get_contents($this->api_url . '/health', false, $ctx);
		return $result !== false;
	}
}
