<?php
/**
 * Predictive Anomaly Dashboard — Zabbix 7.x Frontend Module
 *
 * Entry point. Registers navigation menu item and bootstraps
 * module assets. All heavy lifting is in the Action controllers.
 */

namespace Modules\PredictiveAnomaly;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {

	/**
	 * Called once when module is initialised.
	 * Register the top-level menu entry under "Monitoring".
	 */
	public function init(): void {
		$menu = APP::Component()->get('menu.main');

		$menu->findOrAdd(_('Monitoring'))
			->getSubmenu()
			->add(
				(new CMenuItem(_('Predictive Anomaly')))
					->setAction('predictive.anomaly.view')
					->setIcon('icon-monitoring')
			);
	}

	/**
	 * JavaScripts and stylesheets registered here are included on
	 * every page that belongs to this module.
	 */
	public function getAssets(): array {
		return [
			'js'  => ['predictive-anomaly.js'],
			'css' => ['predictive-anomaly.css'],
		];
	}
}
