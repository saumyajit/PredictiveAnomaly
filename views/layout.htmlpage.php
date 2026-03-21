<?php declare(strict_types = 0);
/**
 * views/layout.htmlpage.php — Predictive Anomaly Dashboard
 *
 * This file takes priority over WorkflowOps/IncidentInvestigation layout files
 * in Zabbix's module view resolution order. It is an exact copy of their layout
 * but with null-safe defaults on every $data access so it never throws warnings
 * regardless of what Zabbix core populates in $data.
 */

function pad_local_showHeader(array $data): void {
	header('Content-Type: text/html; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	header('X-XSS-Protection: 1; mode=block');

	$x_frame_options = $data['config']['x_frame_options'] ?? 'SAMEORIGIN';
	if (strcasecmp($x_frame_options, 'null') != 0) {
		if (strcasecmp($x_frame_options, 'SAMEORIGIN') == 0) {
			header('X-Frame-Options: SAMEORIGIN');
		} elseif (strcasecmp($x_frame_options, 'DENY') == 0) {
			header('X-Frame-Options: DENY');
		} else {
			header('Content-Security-Policy: frame-ancestors ' . $x_frame_options);
		}
	}

	echo (new CPartial('layout.htmlpage.header', [
		'javascript'      => ['files' => $data['javascript']['files'] ?? []],
		'stylesheet'      => ['files' => $data['stylesheet']['files'] ?? []],
		'page'            => ['title' => $data['page']['title'] ?? _('Predictive Anomaly Dashboard')],
		'user'            => [
			'lang'  => CWebUser::$data['lang']  ?? 'en_GB',
			'theme' => CWebUser::$data['theme'] ?? 'blue-theme',
		],
		'web_layout_mode' => $data['web_layout_mode'] ?? ZBX_LAYOUT_NORMAL,
		'config'          => [
			'server_check_interval' => $data['config']['server_check_interval'] ?? 10,
		],
	]))->getOutput();
}

function pad_local_showSidebar(array $data): void {
	global $ZBX_SERVER_NAME;
	if (($data['web_layout_mode'] ?? ZBX_LAYOUT_NORMAL) == ZBX_LAYOUT_NORMAL) {
		echo (new CPartial('layout.htmlpage.aside', [
			'server_name' => isset($ZBX_SERVER_NAME) ? $ZBX_SERVER_NAME : '',
		]))->getOutput();
	}
}

function pad_local_showFooter(array $data): void {
	echo (new CPartial('layout.htmlpage.footer', [
		'user' => [
			'username'   => CWebUser::$data['username']   ?? '',
			'debug_mode' => CWebUser::$data['debug_mode'] ?? 0,
		],
		'web_layout_mode' => $data['web_layout_mode'] ?? ZBX_LAYOUT_NORMAL,
	]))->getOutput();
}

pad_local_showHeader($data);
echo '<body>';
pad_local_showSidebar($data);
echo '<div class="' . ZBX_STYLE_LAYOUT_WRAPPER .
	(($data['web_layout_mode'] ?? ZBX_LAYOUT_NORMAL) == ZBX_LAYOUT_KIOSKMODE ? ' ' . ZBX_STYLE_LAYOUT_KIOSKMODE : '') . '">';
echo get_prepared_messages(['with_current_messages' => true]);
echo $data['main_block'] ?? '';
makeServerStatusOutput()->show();
pad_local_showFooter($data);
require_once 'include/views/js/common.init.js.php';
insertPagePostJs();
echo '</div></body></html>';
