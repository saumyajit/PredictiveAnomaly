<?php
namespace Modules\PredictiveAnomaly\actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CProfile;
use CSettingsHelper;
use API;
use Modules\PredictiveAnomaly\services\MetricConfig;

class CControllerPredictiveAnomalyView extends CController {

	const PROFILE_KEY = 'web.predictive.anomaly';

	const FILTER_DEFAULTS = [
		'groupids'        => [],
		'metrics'         => ['cpu', 'memory', 'disk'],
		'severities'      => [3, 2],       // High + Average by default
		'time_range'      => '24h',
		'score_threshold' => '0.2',
		'model'           => 'all',
		'search'          => '',
		'sort_field'      => 'score',
		'sort_order'      => 'DESC',
		'page'            => 1,
	];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'groupids'        => 'array_id',
			'metrics'         => 'array',
			'severities'      => 'array',
			'time_range'      => 'in 1h,6h,24h,7d,30d',
			'score_threshold' => 'string',
			'model'           => 'in all,zscore,linear,arima,prophet',
			'search'          => 'string',
			'sort_field'      => 'in name,score,anomalous,alerts,cpu,memory,disk',
			'sort_order'      => 'in ASC,DESC',
			'page'            => 'ge 1',
			'filter_set'      => 'in 1',
			'filter_rst'      => 'in 1',
		];
		$ret = $this->validateInput($fields);
		if (!$ret) $this->setResponse(new CControllerResponseFatal());
		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		// ── Filter persistence ────────────────────────────────────────────
		if ($this->hasInput('filter_rst')) {
			foreach (self::FILTER_DEFAULTS as $key => $default) {
				CProfile::update(self::PROFILE_KEY . '.' . $key, json_encode($default), PROFILE_TYPE_STR);
			}
			$filter = self::FILTER_DEFAULTS;
		} elseif ($this->hasInput('filter_set')) {
			$filter = [];
			foreach (self::FILTER_DEFAULTS as $key => $default) {
				$value        = $this->hasInput($key) ? $this->getInput($key, $default) : $default;
				$filter[$key] = $value;
				CProfile::update(self::PROFILE_KEY . '.' . $key, json_encode($value), PROFILE_TYPE_STR);
			}
		} else {
			$filter = [];
			foreach (self::FILTER_DEFAULTS as $key => $default) {
				$stored       = CProfile::get(self::PROFILE_KEY . '.' . $key);
				$filter[$key] = ($stored !== null) ? json_decode($stored, true) : $default;
			}
		}

		// ── All host groups for sidebar ───────────────────────────────────
		$all_groups = API::HostGroup()->get([
			'output'       => ['groupid', 'name'],
			'with_hosts'   => true,
			'preservekeys' => true,
			'sortfield'    => 'name',
			'sortorder'    => 'ASC',
		]);

		foreach ($all_groups as &$group) {
			$group['host_count'] = (int) API::Host()->get([
				'countOutput'     => true,
				'groupids'        => [$group['groupid']],
				'monitored_hosts' => true,
			]);
		}
		unset($group);

		// ── Total hosts ───────────────────────────────────────────────────
		$total_hosts = (int) API::Host()->get([
			'countOutput'     => true,
			'monitored_hosts' => true,
		]);

		// ── Q2: Severity levels from Zabbix API ───────────────────────────
		// Zabbix severity constants: 0=Not classified, 1=Info, 2=Warning,
		// 3=Average, 4=High, 5=Disaster
		$severity_levels = [
			0 => ['label' => _('Not classified'), 'color' => '#97aab3'],
			1 => ['label' => _('Information'),    'color' => '#7499ff'],
			2 => ['label' => _('Warning'),        'color' => '#ffc859'],
			3 => ['label' => _('Average'),        'color' => '#ffa059'],
			4 => ['label' => _('High'),           'color' => '#e97659'],
			5 => ['label' => _('Disaster'),       'color' => '#e45959'],
		];

		// ── Q3: Metrics from config file ──────────────────────────────────
		$metric_defs = MetricConfig::enabled();

		// ── Time boundaries ───────────────────────────────────────────────
		$time_map  = ['1h'=>3600,'6h'=>21600,'24h'=>86400,'7d'=>604800,'30d'=>2592000];
		$time_from = time() - ($time_map[$filter['time_range']] ?? 86400);
		$time_till = time();

		// ── Layout keys expected by other modules' layout.htmlpage.php ────
		$server_check_interval = CSettingsHelper::get(CSettingsHelper::SERVER_CHECK_INTERVAL);

		$this->setResponse(new CControllerResponseData([
			'filter'           => $filter,
			'all_groups'       => array_values($all_groups),
			'total_hosts'      => $total_hosts,
			'time_from'        => $time_from,
			'time_till'        => $time_till,
			'title'            => _('Predictive Anomaly Dashboard'),
			'metric_defs'      => $metric_defs,
			'severity_levels'  => $severity_levels,
			// Layout keys
			'page'             => ['title' => _('Predictive Anomaly Dashboard')],
			'javascript'       => ['files' => []],
			'stylesheet'       => ['files' => []],
			'web_layout_mode'  => ZBX_LAYOUT_NORMAL,
			'config'           => ['server_check_interval' => $server_check_interval],
		]));
	}
}
