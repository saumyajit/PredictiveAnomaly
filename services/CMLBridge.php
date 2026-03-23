<?php
/**
 * CMLBridge
 *
 * HTTP bridge to the Python/Flask ML sidecar (ml_sidecar.py).
 * Uses curl which is more reliable than file_get_contents for loopback
 * connections on servers where allow_url_fopen is disabled.
 *
 * The sidecar is completely optional — if unavailable, isAvailable()
 * returns false and all callers silently use native PHP engine.
 *
 * To start sidecar:
 *   pip install flask prophet statsmodels numpy
 *   python3 /path/to/modules/PredictiveAnomaly/ml_sidecar.py
 *
 * Configure URL (optional, defaults to 127.0.0.1:5001):
 *   define('PREDICTIVE_ML_API_URL', 'http://127.0.0.1:5001');
 */

namespace Modules\PredictiveAnomaly\services;

class CMLBridge {

	const DEFAULT_URL   = 'http://127.0.0.1:5001';
	const TIMEOUT_PING  = 1;   // seconds for health check
	const TIMEOUT_FETCH = 3;   // seconds for forecast call
	const CACHE_TTL     = 300; // seconds in-memory result cache

	private string $api_url;
	private bool   $available;
	private array  $cache = [];

	public function __construct() {
		$this->api_url   = defined('PREDICTIVE_ML_API_URL') ? PREDICTIVE_ML_API_URL : self::DEFAULT_URL;
		$this->available = $this->ping();
	}

	public function isAvailable(): bool {
		return $this->available;
	}

	/**
	 * Request a forecast from the ML sidecar for a single item.
	 */
	public function forecast(int $itemid, array $values, array $clocks, string $model = 'all'): ?array {
		if (!$this->available) return null;

		$last_clock = end($clocks);
		$cache_key  = "{$itemid}_{$model}_{$last_clock}";
		if (isset($this->cache[$cache_key])) return $this->cache[$cache_key];

		$payload = json_encode([
			'itemid' => $itemid,
			'values' => $values,
			'clocks' => $clocks,
			'model'  => $model,
		]);

		$result = $this->curlPost($this->api_url . '/forecast', $payload, self::TIMEOUT_FETCH);
		if ($result === null) { $this->available = false; return null; }

		$decoded = json_decode($result, true);
		if (!$decoded || !isset($decoded['forecast_series'])) return null;

		$this->cache[$cache_key] = $decoded;
		return $decoded;
	}

	/**
	 * Check if the sidecar is reachable.
	 * Called once at construction time.
	 */
	private function ping(): bool {
		if (!function_exists('curl_init')) {
			// curl not available — try file_get_contents as fallback
			$ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => self::TIMEOUT_PING, 'ignore_errors' => true]]);
			$res = @file_get_contents($this->api_url . '/health', false, $ctx);
			return $res !== false && strlen($res) > 0;
		}

		$ch = curl_init($this->api_url . '/health');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::TIMEOUT_PING,
			CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_PING,
			CURLOPT_NOBODY         => false,
			CURLOPT_FAILONERROR    => false,
		]);
		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return $response !== false && $http_code === 200;
	}

	/**
	 * POST JSON payload via curl.
	 */
	private function curlPost(string $url, string $json_payload, int $timeout): ?string {
		if (!function_exists('curl_init')) {
			// Fallback to file_get_contents
			$ctx = stream_context_create(['http' => [
				'method'        => 'POST',
				'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
				'content'       => $json_payload,
				'timeout'       => $timeout,
				'ignore_errors' => true,
			]]);
			$result = @file_get_contents($url, false, $ctx);
			return $result !== false ? $result : null;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $json_payload,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_FAILONERROR    => false,
		]);
		$response  = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return ($response !== false && $http_code >= 200 && $http_code < 300) ? $response : null;
	}
}
