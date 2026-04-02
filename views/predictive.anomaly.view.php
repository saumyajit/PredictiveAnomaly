<?php
/**
 * View: predictive.anomaly.view
 */
$filter          = $data['filter'];
$metric_defs     = $data['metric_defs']     ?? [];
$severity_levels = $data['severity_levels'] ?? [];
?>

<!-- Chart.js — required for Forecasts tab and drilldown charts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

<div id="pad-root" data-theme="dark">

<!-- ═══ FILTER BAR ═══ -->
<div class="pad-filter-bar" id="pad-filter-bar">
  <form method="get" action="zabbix.php" id="pad-filter-form">
    <input type="hidden" name="action" value="predictive.anomaly.view"/>

    <div class="pad-filter-inner">

      <!-- Host Groups -->
      <div class="pad-filter-field">
        <label class="pad-flabel"><?= _('Host Groups') ?></label>
        <div class="pad-multiselect-wrap" id="pad-group-ms">
          <input type="text" class="pad-ms-search" placeholder="<?= _('All groups') ?>"
                 id="pad-group-search" autocomplete="off"/>
          <div class="pad-ms-tags" id="pad-group-tags"></div>
          <div class="pad-ms-dropdown" id="pad-group-dropdown" style="display:none">
            <?php foreach ($data['all_groups'] as $g): ?>
            <div class="pad-ms-option"
                 data-id="<?= $g['groupid'] ?>"
                 data-name="<?= htmlspecialchars($g['name']) ?>">
              <?= htmlspecialchars($g['name']) ?>
              <span class="pad-ms-count"><?= $g['host_count'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <div id="pad-group-inputs">
            <?php foreach ($filter['groupids'] as $gid): ?>
            <input type="hidden" name="groupids[]" value="<?= $gid ?>"/>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Metrics — Q3: driven by config/metrics.php -->
      <div class="pad-filter-field">
        <label class="pad-flabel"><?= _('Metrics') ?></label>
        <div class="pad-chip-group">
          <?php foreach ($metric_defs as $slug => $def): ?>
          <label class="pad-chip <?= in_array($slug, $filter['metrics']) ? 'active' : '' ?>">
            <input type="checkbox" name="metrics[]" value="<?= $slug ?>"
                   <?= in_array($slug, $filter['metrics']) ? 'checked' : '' ?> hidden/>
            <?= htmlspecialchars($def['icon'] . ' ' . $def['label']) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Severity — simple chips + configurable score thresholds -->
      <div class="pad-filter-field">
        <label class="pad-flabel"><?= _('Severity Thresholds') ?></label>
        <div class="pad-sev-wrap">
          <label class="pad-chip sev-crit <?= in_array(3, $filter['severities']) ? 'active' : '' ?>">
            <input type="checkbox" name="severities[]" value="3"
                   <?= in_array(3, $filter['severities']) ? 'checked' : '' ?> hidden/>
            🔴 Critical
          </label>
          <div class="pad-sev-threshold">
            <span class="pad-sev-label">&gt;</span>
            <input type="number" name="critical_threshold" id="pad-crit-threshold"
                   class="pad-threshold-input" min="0" max="1" step="0.05"
                   value="<?= htmlspecialchars($filter['critical_threshold'] ?? '0.75') ?>"
                   title="Anomaly score above which hosts are Critical"/>
          </div>
          <label class="pad-chip sev-warn <?= in_array(2, $filter['severities']) ? 'active' : '' ?>" style="margin-left:8px">
            <input type="checkbox" name="severities[]" value="2"
                   <?= in_array(2, $filter['severities']) ? 'checked' : '' ?> hidden/>
            🟡 Warning
          </label>
          <div class="pad-sev-threshold">
            <span class="pad-sev-label">&gt;</span>
            <input type="number" name="warning_threshold" id="pad-warn-threshold"
                   class="pad-threshold-input" min="0" max="1" step="0.05"
                   value="<?= htmlspecialchars($filter['warning_threshold'] ?? '0.50') ?>"
                   title="Anomaly score above which hosts are Warning"/>
          </div>
        </div>
      </div>

      <!-- Time Range -->
      <div class="pad-filter-field">
        <label class="pad-flabel"><?= _('Time Range') ?></label>
        <div class="pad-radio-group">
          <?php foreach (['1h','6h','24h','7d','30d'] as $tr): ?>
          <label class="pad-radio <?= $filter['time_range'] === $tr ? 'active' : '' ?>">
            <input type="radio" name="time_range" value="<?= $tr ?>"
                   <?= $filter['time_range'] === $tr ? 'checked' : '' ?> hidden/>
            <?= $tr ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Min Score — Q4: minimum anomaly score threshold -->
      <div class="pad-filter-field pad-filter-field--narrow">
        <label class="pad-flabel"><?= _('Min Score') ?></label>
        <div class="pad-range-wrap">
          <input type="range" name="score_threshold" min="0" max="1" step="0.05"
                 value="<?= $filter['score_threshold'] ?>"
                 id="pad-score-range" class="pad-range"/>
          <span class="pad-range-val" id="pad-score-val">
            <?= number_format((float)$filter['score_threshold'], 2) ?>
          </span>
        </div>
      </div>

      <!-- Model -->
      <div class="pad-filter-field">
        <label class="pad-flabel"><?= _('Model') ?></label>
        <select name="model" class="pad-select">
          <?php foreach (['all'=>'All Models','zscore'=>'Z-Score','linear'=>'Linear Reg'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $filter['model'] === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Search -->
      <div class="pad-filter-field">
        <label class="pad-flabel"><?= _('Search') ?></label>
        <input type="text" name="search" id="pad-search-input"
               value="<?= htmlspecialchars($filter['search']) ?>"
               class="pad-text-input" placeholder="<?= _('Group name…') ?>"/>
      </div>

      <!-- Actions -->
      <div class="pad-filter-actions">
        <button type="submit" name="filter_set" value="1" class="pad-btn pad-btn--primary">
          <?= _('Apply') ?>
        </button>
        <a href="zabbix.php?action=predictive.anomaly.view&filter_rst=1" class="pad-btn pad-btn--ghost">
          <?= _('Reset') ?>
        </a>
      </div>

    </div>
  </form>
</div>

<!-- ═══ SUMMARY BAND ═══ -->
<div class="pad-summary-band">
  <div class="pad-stat" id="stat-total">
    <div class="pad-stat__label"><?= _('Monitored Hosts') ?></div>
    <div class="pad-stat__value pad-stat__value--blue"><?= number_format($data['total_hosts']) ?></div>
    <div class="pad-stat__sub"><?= _('across all groups') ?></div>
  </div>
  <div class="pad-stat" id="stat-anom">
    <div class="pad-stat__label"><?= _('Anomalous Hosts') ?></div>
    <div class="pad-stat__value pad-stat__value--red">—</div>
    <div class="pad-stat__sub"><?= _('loading…') ?></div>
  </div>
  <div class="pad-stat" id="stat-alerts">
    <div class="pad-stat__label"><?= _('Predicted Alerts') ?></div>
    <div class="pad-stat__value pad-stat__value--yellow">—</div>
    <div class="pad-stat__sub"><?= _('next 6h') ?></div>
  </div>
  <div class="pad-stat" id="stat-exhaust">
    <div class="pad-stat__label"><?= _('Exhaustion Risk') ?></div>
    <div class="pad-stat__value pad-stat__value--orange">—</div>
    <div class="pad-stat__sub"><?= _('within 7 days') ?></div>
  </div>
  <div class="pad-stat" id="stat-accuracy">
    <div class="pad-stat__label"><?= _('Forecast Accuracy') ?></div>
    <div class="pad-stat__value pad-stat__value--green">—</div>
    <div class="pad-stat__sub"><?= _('30-day avg') ?></div>
  </div>
  <div class="pad-stat" id="stat-models">
    <div class="pad-stat__label"><?= _('Active Models') ?></div>
    <div class="pad-stat__value pad-stat__value--purple">—</div>
    <div class="pad-stat__sub"><?= _('engines') ?></div>
  </div>
</div>

<!-- ═══ TOOLBAR ═══ -->
<div class="pad-toolbar">
  <div class="pad-tabs" id="pad-tabs">
    <button class="pad-tab active" data-tab="overview"><?= _('Group Overview') ?></button>
    <button class="pad-tab" data-tab="heatmap"><?= _('Anomaly Heatmap') ?></button>
    <button class="pad-tab" data-tab="exhaustion"><?= _('Exhaustion') ?></button>
    <button class="pad-tab" data-tab="forecasts"><?= _('Forecasts') ?></button>
    <button class="pad-tab" data-tab="models"><?= _('ML Models') ?></button>
  </div>
  <div class="pad-toolbar-right">
    <button class="pad-btn pad-btn--sm" id="pad-export-csv">⬇ CSV</button>
    <div class="pad-refresh-wrap">
      <select id="pad-refresh-select" class="pad-select pad-select--sm" title="Auto-refresh interval">
        <option value="0"><?= _('No refresh') ?></option>
        <option value="30">30s</option>
        <option value="60">1m</option>
        <option value="120">2m</option>
        <option value="300">5m</option>
      </select>
      <button class="pad-btn pad-btn--sm pad-btn--primary" id="pad-refresh-btn">⟳ <?= _('Refresh') ?></button>
    </div>
    <button class="pad-btn pad-btn--sm" id="pad-theme-btn">🌗</button>
    <span class="pad-live-indicator">
      <span class="pad-live-dot" id="pad-live-dot"></span>
      <span id="pad-live-label">MANUAL</span>
    </span>
  </div>
</div>

<!-- ═══ TAB: GROUP OVERVIEW ═══ -->
<div class="pad-tab-panel active" id="tab-overview">
  <div class="pad-card">
    <div class="pad-card__head">
      <div class="pad-card__title">🗂 <?= _('Host Group Anomaly Overview') ?></div>
      <div class="pad-card__actions">
        <span class="pad-tag pad-tag--native">Zabbix Trends</span>
        <span class="pad-tag pad-tag--zscore">Z-Score</span>
        <select id="pad-sort-field" class="pad-select pad-select--sm">
          <option value="score"><?= _('Sort: Anomaly Score') ?></option>
          <option value="name"><?= _('Sort: Name') ?></option>
          <option value="anomalous"><?= _('Sort: Anomalous') ?></option>
          <option value="predicted_alerts"><?= _('Sort: Alerts') ?></option>
          <option value="disk"><?= _('Sort: Disk') ?></option>
        </select>
        <button class="pad-btn pad-btn--sm" id="pad-sort-order-btn" data-order="DESC">↓</button>
      </div>
    </div>
    <div class="pad-table-wrap">
      <table class="pad-table" id="pad-group-table">
        <thead>
          <tr>
            <th><?= _('Group') ?></th>
            <th><?= _('Hosts') ?></th>
            <th><?= _('Anomalous') ?></th>
            <th><?= _('Anomaly Score') ?></th>
            <th><?= _('CPU Avg') ?></th>
            <th><?= _('Memory Avg') ?></th>
            <th><?= _('Disk Avg') ?></th>
            <th><?= _('Pred. Alerts') ?></th>
            <th><?= _('Worst Host') ?></th>
            <th><?= _('Action') ?></th>
          </tr>
        </thead>
        <tbody id="pad-group-tbody">
          <tr class="pad-loading-row">
            <td colspan="10">
              <div class="pad-spinner-wrap">
                <div class="pad-spinner"></div>
                <?= _('Analysing trends across all host groups…') ?>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="pad-pagination" id="pad-pagination">
      <button class="pad-btn pad-btn--sm" id="pad-prev-page" disabled>‹ <?= _('Prev') ?></button>
      <span class="pad-page-info" id="pad-page-info">—</span>
      <button class="pad-btn pad-btn--sm" id="pad-next-page"><?= _('Next') ?> ›</button>
    </div>
  </div>
</div>

<!-- ═══ TAB: HEATMAP ═══ -->
<div class="pad-tab-panel" id="tab-heatmap">
  <div class="pad-card">
    <div class="pad-card__head">
      <div class="pad-card__title">🔥 <?= _('Group × Hour Anomaly Heatmap') ?></div>
      <div class="pad-card__actions"><span class="pad-tag pad-tag--zscore">Z-Score</span></div>
    </div>
    <div class="pad-card__body">
      <div id="pad-heatmap-wrap"><div class="pad-spinner-wrap"><div class="pad-spinner"></div></div></div>
      <div class="pad-hm-legend">
        <span><?= _('Low') ?></span>
        <div class="pad-hm-scale" id="pad-hm-scale"></div>
        <span><?= _('Critical') ?></span>
        <span class="pad-hm-legend__help"><?= _('Click to drilldown') ?></span>
      </div>
    </div>
  </div>
</div>

<!-- ═══ TAB: EXHAUSTION ═══ -->
<div class="pad-tab-panel" id="tab-exhaustion">
  <div class="pad-grid pad-grid--2">
    <div class="pad-card">
      <div class="pad-card__head">
        <div class="pad-card__title">📉 <?= _('Resource Exhaustion Estimates') ?></div>
        <div class="pad-card__actions">
          <span class="pad-tag pad-tag--native">Linear Regression</span>
          <select id="pad-ex-metric" class="pad-select pad-select--sm">
            <?php foreach ($metric_defs as $slug => $def): ?>
            <option value="<?= $slug ?>" <?= $slug==='disk'?'selected':'' ?>><?= htmlspecialchars($def['icon'].' '.$def['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="pad-card__body" id="pad-exhaust-body">
        <div class="pad-spinner-wrap"><div class="pad-spinner"></div></div>
      </div>
    </div>

    <!-- Q6: Real maintenance windows from breach ETA -->
    <div class="pad-card">
      <div class="pad-card__head">
        <div class="pad-card__title">🗓 <?= _('Predicted Maintenance Windows') ?></div>
        <div class="pad-card__actions">
          <span class="pad-tag pad-tag--native">Breach ETA + Lead Time</span>
        </div>
      </div>
      <div class="pad-card__body" id="pad-mw-body">
        <div class="pad-spinner-wrap"><div class="pad-spinner"></div></div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ TAB: FORECASTS ═══ -->
<div class="pad-tab-panel" id="tab-forecasts">

  <!--
    FORECAST CONTROLS — independent from the global filter bar above.
    The global filter (host groups, metrics chips) is for the Group Overview
    anomaly scoring. These controls are specifically for the Forecast charts.
  -->
  <div class="pad-fc-controls">
    <div class="pad-fc-controls__label">
      <span class="pad-fc-controls__badge">📈 Forecast Controls</span>
      <span class="pad-fc-controls__note"><?= _('Independent from global filter — select group and metric to chart') ?></span>
    </div>
    <div class="pad-fc-controls__row">

      <!-- Group selector — dedicated to Forecasts tab -->
      <div class="pad-fc-control-field">
        <label class="pad-flabel"><?= _('Host Group') ?></label>
        <select id="pad-fc-group" class="pad-select" style="min-width:200px">
          <option value=""><?= _('— Select a group —') ?></option>
          <?php foreach ($data['all_groups'] as $g): ?>
          <option value="<?= $g['groupid'] ?>"><?= htmlspecialchars($g['name']).' ('.$g['host_count'].')' ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Metric selector -->
      <div class="pad-fc-control-field">
        <label class="pad-flabel"><?= _('Metric') ?></label>
        <div class="pad-forecast-bar__metrics" id="pad-fc-metrics">
          <?php foreach ($metric_defs as $slug => $def): ?>
          <button class="pad-fc-metric-btn <?= $slug==='cpu'?'active':'' ?>"
                  data-metric="<?= $slug ?>">
            <?= htmlspecialchars($def['icon'].' '.$def['label']) ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Forecast horizon -->
      <div class="pad-fc-control-field">
        <label class="pad-flabel"><?= _('Forecast Horizon') ?></label>
        <div class="pad-radio-group" id="pad-fc-horizon">
          <label class="pad-radio"><input type="radio" name="fc_horizon" value="7" hidden/>7d</label>
          <label class="pad-radio active"><input type="radio" name="fc_horizon" value="30" checked hidden/>30d</label>
          <label class="pad-radio"><input type="radio" name="fc_horizon" value="90" hidden/>90d</label>
        </div>
      </div>

      <!-- View mode -->
      <div class="pad-fc-control-field">
        <label class="pad-flabel"><?= _('View') ?></label>
        <div class="pad-radio-group" id="pad-fc-view">
          <label class="pad-radio active"><input type="radio" name="fc_view" value="avg" checked hidden/><?= _('Fleet Avg') ?></label>
          <label class="pad-radio"><input type="radio" name="fc_view" value="hosts" hidden/><?= _('Per Host') ?></label>
        </div>
      </div>

    </div>
  </div>

  <!-- Charts container — rendered dynamically by JS -->
  <div id="pad-fc-charts-wrap">
    <div class="pad-empty" style="padding:48px 0;text-align:center">
      <div style="font-size:32px;margin-bottom:10px">📈</div>
      <div><?= _('Select a host group above to load real Zabbix forecast data') ?></div>
      <div style="margin-top:6px;font-size:11px;color:var(--pad-text-3)">
        <?= _('Historical data from Zabbix trends + 30-day linear regression forecast with 95% confidence interval') ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══ TAB: ML MODELS ═══ -->
<div class="pad-tab-panel" id="tab-models">
  <div class="pad-card">
    <div class="pad-card__head">
      <div class="pad-card__title">🤖 <?= _('Detection & Forecast Model Status') ?></div>
    </div>
    <div class="pad-card__body" id="pad-model-body">
      <div class="pad-spinner-wrap"><div class="pad-spinner"></div></div>
    </div>
  </div>
</div>

</div><!-- /#pad-root -->

<!-- ═══ DRILLDOWN DRAWER ═══ -->
<div class="pad-drawer-overlay" id="pad-drawer-overlay">
  <div class="pad-drawer" id="pad-drawer">
    <div class="pad-drawer__head">
      <div>
        <div class="pad-drawer__title" id="pad-drawer-title">—</div>
        <div class="pad-drawer__sub"   id="pad-drawer-sub">—</div>
      </div>
      <div class="pad-drawer__actions">
        <span class="pad-drawer__esc-hint">ESC to close</span>
        <button class="pad-drawer__close" id="pad-drawer-close">✕</button>
      </div>
    </div>
    <div class="pad-drawer__body" id="pad-drawer-body" style="overflow-y:auto;max-height:calc(100vh - 80px)">
      <div class="pad-spinner-wrap"><div class="pad-spinner"></div></div>
    </div>
  </div>
</div>

<div class="pad-hm-tooltip" id="pad-hm-tooltip"></div>

<script>
window.PAD_CONFIG = <?= json_encode([
	'action_data'     => 'predictive.anomaly.data',
	'action_host'     => 'predictive.anomaly.host',
	'action_forecast' => 'predictive.anomaly.forecast',
	'action_fleet'    => 'predictive.anomaly.fleet',
	'filter'          => $filter,
	'total_hosts'     => $data['total_hosts'],
	'time_from'       => $data['time_from'],
	'time_till'       => $data['time_till'],
	// Q3: metric definitions for JS (labels, icons, units)
	'metric_defs'     => array_map(fn($m) => [
		'label' => $m['label'],
		'icon'  => $m['icon'],
		'unit'  => $m['unit'],
	], $metric_defs),
	'critical_threshold' => (float)($filter['critical_threshold'] ?? 0.75),
	'warning_threshold'  => (float)($filter['warning_threshold']  ?? 0.50),
	'strings' => [
		'loading'   => _('Loading…'),
		'no_data'   => _('No anomalies found for current filters.'),
		'drilldown' => _('Drilldown →'),
	],
]) ?>;
</script>
