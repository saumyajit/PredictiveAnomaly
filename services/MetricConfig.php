<?php
/**
 * MetricConfig — loads config/metrics.php and exposes helpers.
 * All controllers and the view use this instead of hardcoded arrays.
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

	/**
	 * All enabled metrics as slug => config array.
	 */
	public static function enabled(): array {
		return array_filter(self::load(), fn($m) => $m['enabled'] ?? true);
	}

	/**
	 * Build item-key map for given selected metric slugs.
	 * Used by controllers to search Zabbix items.
	 * Returns: ['cpu' => ['system.cpu.util', ...], ...]
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
	 * Labels for the UI filter chips: ['cpu' => 'CPU', ...]
	 */
	public static function labels(): array {
		return array_map(fn($m) => $m['label'], self::enabled());
	}

	/**
	 * Units per slug: ['cpu' => '%', 'network' => 'bps', ...]
	 */
	public static function units(): array {
		return array_map(fn($m) => $m['unit'] ?? '%', self::load());
	}

	/**
	 * Usage threshold per slug — score is boosted when value exceeds this.
	 */
	public static function thresholds(): array {
		return array_map(fn($m) => (int)($m['threshold'] ?? 0), self::load());
	}

	/**
	 * Fallback defaults if config file is missing.
	 */
	private static function defaults(): array {
		return [
			'cpu'    => ['label'=>'CPU',     'icon'=>'⚡','unit'=>'%','enabled'=>true,'threshold'=>70,'keys'=>['system.cpu.util','system.cpu.load']],
			'memory' => ['label'=>'Memory',  'icon'=>'🧠','unit'=>'%','enabled'=>true,'threshold'=>75,'keys'=>['vm.memory.utilization','vm.memory.size']],
			'disk'   => ['label'=>'Disk',    'icon'=>'💾','unit'=>'%','enabled'=>true,'threshold'=>75,'keys'=>['vfs.fs.size','vfs.fs.inode']],
			'network'=> ['label'=>'Network', 'icon'=>'🌐','unit'=>'bps','enabled'=>true,'threshold'=>0,'keys'=>['net.if.in','net.if.out']],
			'iops'   => ['label'=>'IOPS',    'icon'=>'📀','unit'=>'ops','enabled'=>true,'threshold'=>0,'keys'=>['vfs.dev.read.ops','vfs.dev.write.ops']],
			'load'   => ['label'=>'Load',    'icon'=>'📊','unit'=>'','enabled'=>true,'threshold'=>0,'keys'=>['system.cpu.load']],
		];
	}
}
