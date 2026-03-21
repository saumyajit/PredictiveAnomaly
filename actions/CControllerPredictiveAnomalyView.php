<?php
/**
 * Action: predictive.anomaly.view
 *
 * Renders the main dashboard page. Validates and persists filter
 * state in the session, resolves host groups for the sidebar, and
 * passes a lightweight initial payload to the view. All heavy data
 * (trend analysis, anomaly scoring) is fetched asynchronously via
 * the JSON actions.
 */

namespace Modules\PredictiveAnomaly\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CProfile;
use CWebUser;
use API;

class CControllerPredictiveAnomalyView extends CController {

	// Session profile key prefix
	const PROFILE_KEY = 'web.predictive.anomaly';

	// Allowed filter field keys and their defaults
	const FILTER_DEFAULTS = [
		'groupids'        => [],       // array of hostgroupid
		'hostids'         => [],       // array of hostid (for drilldown)
		'metrics'         => ['cpu', 'memory', 'disk'],
		'severities'      => [2, 3],   // 2=Warning 3=High/Critical (Zabbix severity)
		'time_range'      => '24h',    // 1h|6h|24h|7d|30d
		'score_threshold' => 0.2,      // minimum anomaly score to show
		'model'           => 'all',    // all|zscore|linear|arima|prophet
		'search'          => '',
		'page'            => 1,
		'sort_field'      => 'score',
		'sort_order'      => 'DESC',
	];

	protected function checkInput(): bool {
		$fields = [
			'groupids'        => 'array_id',
			'hostids'         => 'array_id',
			'metrics'         => 'array',
			'severities'      => 'array',
			'time_range'      => 'in 1h,6h,24h,7d,30d',
			'score_threshold' => 'ge 0.0|le 1.0',
			'model'           => 'in all,zscore,linear,arima,prophet',
			'search'          => 'string',
			'page'            => 'ge 1',
			'sort_field'      => 'in name,score,hosts,alerts,cpu,memory,disk',
			'sort_order'      => 'in ASC,DESC',
			'filter_set'      => 'in 1',
			'filter_rst'      => 'in 1',
		];

		$ret = $this->validateInput($fields);
		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::isLoggedIn();
	}

	protected function doAction(): void {
		// ── Filter persistence ────────────────────────────────────────────
		if ($this->hasInput('filter_rst')) {
			// Reset all filters to defaults
			foreach (self::FILTER_DEFAULTS as $key => $default) {
				CProfile::update(self::PROFILE_KEY . '.' . $key, json_encode($default), PROFILE_TYPE_STR);
			}
			$filter = self::FILTER_DEFAULTS;
		} elseif ($this->hasInput('filter_set')) {
			// Save submitted filters
			$filter = [];
			foreach (self::FILTER_DEFAULTS as $key => $default) {
				$value = $this->hasInput($key) ? $this->getInput($key, $default) : $default;
				$filter[$key] = $value;
				CProfile::update(self::PROFILE_KEY . '.' . $key, json_encode($value), PROFILE_TYPE_STR);
			}
		} else {
			// Restore from session
			$filter = [];
			foreach (self::FILTER_DEFAULTS as $key => $default) {
				$stored = CProfile::get(self::PROFILE_KEY . '.' . $key);
				$filter[$key] = ($stored !== null) ? json_decode($stored, true) : $default;
			}
		}

		// ── Resolve all host groups for sidebar ───────────────────────────
		// We only fetch metadata (id, name, host count) here — anomaly
		// scores are computed async. Limit fields to keep the query fast.
		$all_groups = API::HostGroup()->get([
			'output'             => ['groupid', 'name'],
			'with_hosts'         => true,
			'preservekeys'       => true,
			'selectHosts'        => ['hostid'],
			'sortfield'          => 'name',
			'sortorder'          => 'ASC',
			'search'             => $filter['search'] ? ['name' => $filter['search']] : null,
			'searchWildcardsEnabled' => true,
		]);

		// Annotate each group with host count
		foreach ($all_groups as &$group) {
			$group['host_count'] = count($group['hosts']);
			unset($group['hosts']); // don't send full host list to view
		}
		unset($group);

		// ── Summary counters (lightweight) ────────────────────────────────
		$total_hosts = API::Host()->get([
			'countOutput' => true,
			'monitored_hosts' => true,
		]);

		// ── Build time range boundaries ───────────────────────────────────
		$time_range_map = [
			'1h'  => 3600,
			'6h'  => 21600,
			'24h' => 86400,
			'7d'  => 604800,
			'30d' => 2592000,
		];
		$time_from = time() - ($time_range_map[$filter['time_range']] ?? 86400);
		$time_till = time();

		// ── Pass data to view ─────────────────────────────────────────────
		$data = [
			'filter'      => $filter,
			'all_groups'  => array_values($all_groups),
			'total_hosts' => (int) $total_hosts,
			'time_from'   => $time_from,
			'time_till'   => $time_till,
			'title'       => _('Predictive Anomaly Dashboard'),

			// These come from async AJAX on page load
			'summary'     => null,
		];

		$this->setResponse(new CControllerResponseData($data));
	}
}
