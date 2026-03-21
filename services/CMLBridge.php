<?php
/**
 * CMLBridge
 * Optional HTTP bridge to Python/Flask ML sidecar.
 * If unreachable, isAvailable() returns false and all callers
 * fall back to native PHP engine silently.
 *
 * Configure: define('PREDICTIVE_ML_API_URL', 'http://127.0.0.1:5001');
 */

namespace Modules\PredictiveAnomaly\services;

class CMLBridge {

	const DEFAULT_URL = 'http://127.0.0.1:5001';
	const TIMEOUT     = 2; // seconds — never block page render
	const CACHE_TTL   = 300;

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

	public function forecast(int $itemid, array $values, array $clocks, string $model = 'all'): ?array {
		if (!$this->available) return null;

		$cache_key = "{$itemid}_{$model}_" . end($clocks);
		if (isset($this->cache[$cache_key])) return $this->cache[$cache_key];

		$payload = json_encode(['itemid'=>$itemid,'values'=>$values,'clocks'=>$clocks,'model'=>$model]);
		$ctx = stream_context_create([
			'http' => [
				'method'        => 'POST',
				'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
				'content'       => $payload,
				'timeout'       => self::TIMEOUT,
				'ignore_errors' => true,
			],
		]);

		$result = @file_get_contents($this->api_url . '/forecast', false, $ctx);
		if ($result === false) { $this->available = false; return null; }

		$decoded = json_decode($result, true);
		if (!$decoded || !isset($decoded['forecast_series'])) return null;

		$this->cache[$cache_key] = $decoded;
		return $decoded;
	}

	private function ping(): bool {
		$ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>0.5,'ignore_errors'=>true]]);
		return @file_get_contents($this->api_url . '/health', false, $ctx) !== false;
	}
}
