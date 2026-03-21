<?php
/**
 * Predictive Anomaly Dashboard — Zabbix 7.x Frontend Module
 */

namespace Modules\PredictiveAnomaly;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {

	public function init(): void {
		$menu = APP::Component()->get('menu.main')
			->findOrAdd(_('Monitoring'))
				->getSubmenu();

		$menu->add(
			(new CMenuItem(_('Predictive Anomaly')))
				->setAction('predictive.anomaly.view')
		);
	}
}
