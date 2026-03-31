<?php
/**
 * ItemFetcher
 *
 * Shared helper for fetching Zabbix items by metric key patterns.
 * Handles:
 *  - Exact key matching (full key including [params]) when pattern contains "["
 *  - Prefix matching when pattern has no "["
 *  - Unit filtering: skips items whose unit doesn't match prefer_units
 *  - Inversion: vm.memory.size[pavailable] values are inverted (100 - value)
 *    since "pavailable" means "percent available" but we want "percent used"
 */

namespace Modules\PredictiveAnomaly\services;

use API;

class ItemFetcher {

	// Items whose values should be inverted (100 - value) to get "% used"
	const INVERT_KEYS = [
		'vm.memory.size[pavailable]',
	];

	/**
	 * Fetch items for a single metric slug across given host IDs.
	 * Returns items filtered to only those with usable (% or expected) units.
	 *
	 * @param string $slug          metric slug from config (e.g. 'memory')
	 * @param array  $key_patterns  key patterns from MetricConfig::keyMap()
	 * @param array  $hostids       host IDs to search
	 * @param int    $limit         max items to return
	 * @return array [itemid => item_array_with_invert_flag]
	 */
	public static function fetchItems(
		string $slug,
		array  $key_patterns,
		array  $hostids,
		int    $limit = 500
	): array {
		if (!$hostids || !$key_patterns) return [];

		// Separate exact keys (contain "[") from prefix keys
		$exact_keys  = array_filter($key_patterns, fn($k) => str_contains($k, '['));
		$prefix_keys = array_filter($key_patterns, fn($k) => !str_contains($k, '['));

		$all_items = [];

		// ── Exact key search ──────────────────────────────────────────────
		// For keys like "vfs.fs.size[/,pused]" — use filter not search
		if ($exact_keys) {
			$raw = API::Item()->get([
				'output'       => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'units'],
				'hostids'      => $hostids,
				'filter'       => [
					'key_'       => array_values($exact_keys),
					'value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64],
				],
				'monitored'    => true,
				'preservekeys' => true,
				'limit'        => $limit,
			]);
			foreach ($raw as $iid => $item) {
				$all_items[$iid] = $item;
			}
		}

		// ── Prefix search ─────────────────────────────────────────────────
		// For keys like "system.cpu.util" — matches any variant
		if ($prefix_keys && count($all_items) < $limit) {
			$raw = API::Item()->get([
				'output'       => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'units'],
				'hostids'      => $hostids,
				'search'       => ['key_' => array_values($prefix_keys)],
				'searchByAny'  => true,
				'filter'       => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]],
				'monitored'    => true,
				'preservekeys' => true,
				'limit'        => $limit,
			]);
			foreach ($raw as $iid => $item) {
				if (!isset($all_items[$iid])) {
					$all_items[$iid] = $item;
				}
			}
		}

		if (!$all_items) return [];

		// ── Unit filtering ────────────────────────────────────────────────
		$prefer_units = MetricConfig::preferredUnits($slug);
		if ($prefer_units !== null) {
			$all_items = array_filter($all_items, function($item) use ($prefer_units) {
				$u = trim($item['units'] ?? '');
				// Accept exact match or empty string (some templates omit units)
				return $u === $prefer_units || $u === '';
			});
		}

		// ── Mark items that need value inversion ──────────────────────────
		foreach ($all_items as $iid => &$item) {
			$item['_invert'] = in_array($item['key_'], self::INVERT_KEYS);
		}
		unset($item);

		// ── Deduplicate: one item per host per slug ────────────────────────
		// Prefer items with units='%' over items without
		$per_host = [];
		foreach ($all_items as $iid => $item) {
			$hid = $item['hostid'];
			if (!isset($per_host[$hid])) {
				$per_host[$hid] = $item;
			} else {
				// Prefer '%' unit if we currently have a non-% item
				$existing = $per_host[$hid];
				if (trim($existing['units'] ?? '') !== '%' && trim($item['units'] ?? '') === '%') {
					$per_host[$hid] = $item;
				}
				// Prefer non-invert over invert (pused > pavailable)
				if ($existing['_invert'] && !$item['_invert']) {
					$per_host[$hid] = $item;
				}
			}
		}

		return $per_host;
	}

	/**
	 * Apply value inversion to a series if the item is marked _invert.
	 * "pavailable" → "pused": value = 100 - value
	 */
	public static function applyInversion(array $item, array $values): array {
		if (!($item['_invert'] ?? false)) return $values;
		return array_map(fn($v) => round(100.0 - (float)$v, 3), $values);
	}
}
