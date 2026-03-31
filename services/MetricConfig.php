<?php
/**
 * MetricConfig — loads config/metrics.php and exposes helpers.
 */

namespace Modules\PredictiveAnomaly\services;

class MetricConfig {

	private static ?array $metrics = null;

	public static function load(): array {
		if (self::$metrics !== null) return self::$metrics;
		$file = __DIR__ . '/../config/metrics.php';
		self::$metrics = file_exists($file) ? require $file : self::defaults();
		return self::$metrics;
	}

	public static function enabled(): array {
		return array_filter(self::load(), fn($m) => $m['enabled'] ?? true);
	}

	/**
	 * Build item key search map: ['cpu' => ['system.cpu.util', ...], ...]
	 * Keys are prefix-stripped (no brackets) for Zabbix API search.
	 */
	public static function keyMap(array $selected_slugs = []): array {
		$all    = self::enabled();
		$result = [];
		$slugs  = $selected_slugs ?: array_keys($all);
		foreach ($slugs as $slug) {
			if (isset($all[$slug])) {
				$result[$slug] = $all[$slug]['keys'];
			}
		}
		return $result;
	}

	/**
	 * Return the preferred unit filter for a metric slug.
	 * e.g. 'memory' => '%'  means only keep items where units == '%'
	 * Returns null if no filter should be applied.
	 */
	public static function preferUnits(string $slug): ?string {
		$def = self::load()[$slug] ?? null;
		return $def ? ($def['prefer_units'] ?? null) : null;
	}

	/**
	 * Filter an array of Zabbix items to only those matching the preferred unit.
	 * If prefer_units is null, all items are returned unchanged.
	 * If prefer_units is '%', only items with units='%' OR units='%%' are kept.
	 *
	 * Also handles 'pavailable' inversion flag (memory available → used).
	 */
	public static function filterItemsByUnit(array $items, string $slug): array {
		$prefer = self::preferUnits($slug);
		if ($prefer === null) return $items;

		return array_filter($items, function($item) use ($prefer, $slug) {
			$unit = trim($item['units'] ?? '');

			if ($prefer === '%') {
				// Accept %, %%, or empty unit on items whose key contains pused/utilization
				if (in_array($unit, ['%', '%%', 'pused'])) return true;
				// Also accept items explicitly named with pused/utilization/pavailable
				$key = $item['key_'] ?? '';
				if (str_contains($key, 'pused')       ) return true;
				if (str_contains($key, 'utilization')  ) return true;
				if (str_contains($key, 'pavailable')   ) return true;
				return false;
			}

			return $unit === $prefer;
		});
	}

	/**
	 * Normalize a value for a metric — e.g. pavailable → invert to used %.
	 */
	public static function normalizeValue(float $value, string $item_key, string $slug): float {
		// pavailable means "% available" — invert to get % used
		if (str_contains($item_key, 'pavailable')) {
			return max(0.0, 100.0 - $value);
		}
		return $value;
	}

	public static function labels(): array {
		return array_map(fn($m) => $m['label'], self::enabled());
	}

	public static function units(): array {
		return array_map(fn($m) => $m['unit'] ?? '%', self::load());
	}

	public static function thresholds(): array {
		return array_map(fn($m) => (int)($m['threshold'] ?? 0), self::load());
	}

	private static function defaults(): array {
		return [
			'cpu'    => ['label'=>'CPU',    'icon'=>'⚡','unit'=>'%','enabled'=>true,'threshold'=>70,'prefer_units'=>'%','keys'=>['system.cpu.util']],
			'memory' => ['label'=>'Memory', 'icon'=>'🧠','unit'=>'%','enabled'=>true,'threshold'=>75,'prefer_units'=>'%','keys'=>['vm.memory.utilization','vm.memory.size[pused]']],
			'disk'   => ['label'=>'Disk',   'icon'=>'💾','unit'=>'%','enabled'=>true,'threshold'=>75,'prefer_units'=>'%','keys'=>['vfs.fs.size']],
			'network'=> ['label'=>'Network','icon'=>'🌐','unit'=>'bps','enabled'=>true,'threshold'=>0,'prefer_units'=>null,'keys'=>['net.if.in','net.if.out']],
			'iops'   => ['label'=>'IOPS',   'icon'=>'📀','unit'=>'ops','enabled'=>true,'threshold'=>0,'prefer_units'=>null,'keys'=>['vfs.dev.read.ops','vfs.dev.write.ops']],
			'load'   => ['label'=>'Load',   'icon'=>'📊','unit'=>'','enabled'=>true,'threshold'=>0,'prefer_units'=>null,'keys'=>['system.cpu.load']],
		];
	}
}
