<?php
/**
 * View: predictive.anomaly.view
 *
 * Zabbix 7.x MVC view. Receives $data from the controller.
 * Renders the shell: topbar, filter panel, summary band, and
 * the main content containers. Real data is injected by JS
 * after the page loads via async API calls.
 */

declare(strict_types=1);

use Modules\PredictiveAnomaly\Actions\CControllerPredictiveAnomalyView;

$filter = $data['filter'];
?>
<?= (new CTag('h1', true, _('Predictive Anomaly Dashboard')))->toString() ?>

<div class="pad-anomaly" id="pad-root" data-theme="dark">

<!-- ══════════════════════════════════════════════════════
     FILTER BAR
══════════════════════════════════════════════════════ -->
<div class="pad-filter-bar" id="pad-filter-bar">
  <form method="get" action="<?= (new CUrl('zabbix.php'))->setArgument('action', 'predictive.anomaly.view') ?>"
        id="pad-filter-form">
    <input type="hidden" name="action"     value="predictive.anomaly.view"/>
    <input type="hidden" name="filter_set" value="1" id="pad-filter-set-flag"/>

    <div class="pad-filter-inner">

      <!-- Host Group multiselect -->
      <div class="pad-filter-field">
        <label class="pad-flabel"><?= _('Host Groups') ?></label>
        <div class="pad-multiselect-wrap" id="pad-group-multiselect">
          <input type="text" class="pad-ms-search" placeholder="<?= _('All groups') ?>"
                 id="pad-group-search" autocomplete="off"/>
          <div class="pad-ms-tags" id="pad-group-tags"></div>
          <div class="pad-ms-dropdown" id="pad-group-dropdown" style="display:none">
            <?php foreach ($data['all_groups'] as $g): ?>
            <div class="pad-ms-option" data-id="<?= $g['groupid'] ?>" data-name="<?= htmlspecialchars($g['name']) ?>">
              <?= htmlspecialchars($g['name']) ?>
              <span class="pad-ms-count"><?= $g['host_count'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <!-- Hidden inputs populated by JS -->
          <div id="pad-group-inputs"></div>
        </div>
      </div>

      <!-- Metrics -->
      <div class="pad-filter-field">
        <label class="pad-flabel"><?= _('Metrics') ?></label>
        <div class="pad-chip-group" id="pad-metric-chips">
          <?php
          $metric_options = [
            'cpu'     => _('CPU'),
            'memory'  => _('Memory'),
            'disk'    => _('Disk'),
            'network' => _('Network'),
            'iops'    => _('IOPS'),
            'load'    => _('Load'),
          ];
          foreach ($metric_options as $key => $label):
            $checked = in_array($key, $filter['metrics']);
          ?>
          <label class="pad-chip <?= $checked ? 'active' : '' ?>">
            <input type="checkbox" name="metrics[]" value="<?= $key ?>"
                   <?= $checked ? 'checked' : '' ?> hidden/>
            <?= $label ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Severity -->
      <div class="pad-filter-field">
        <label class="pad-flabel"><?= _('Severity') ?></label>
        <div class="pad-chip-group">
          <?php
          $sev_map = [3 => ['Critical','sev-crit'], 2 => ['Warning','sev-warn'], 1 => ['Info','sev-info']];
          foreach ($sev_map as $sev_id => [$sev_name, $cls]):
            $checked = in_array($sev_id, $filter['severities']);
          ?>
          <label class="pad-chip <?= $cls ?> <?= $checked ? 'active' : '' ?>">
            <input type="checkbox" name="severities[]" value="<?= $sev_id ?>"
                   <?= $checked ? 'checked' : '' ?> hidden/>
            <?= $sev_name ?>
          </label>
          <?php endforeach; ?>
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

      <!-- Min Anomaly Score -->
      <div class="pad-filter-field pad-filter-field--narrow">
        <label class="pad-flabel"><?= _('Min Score') ?></label>
        <div class="pad-range-wrap">
          <input type="range" name="score_threshold" min="0" max="1" step="0.05"
                 value="<?= $filter['score_threshold'] ?>"
                 id="pad-score-range" class="pad-range"/>
          <span class="pad-range-val" id="pad-score-val">
            <?= number_format($filter['score_threshold'], 2) ?>
          </span>
        </div>
      </div>

      <!-- ML Model -->
      <div class="pad-filter-field">
        <label class="pad-flabel"><?= _('Model') ?></label>
        <select name="model" class="pad-select">
          <?php foreach (['all'=>'All Models','zscore'=>'Z-Score','linear'=>'Linear Reg','arima'=>'ARIMA','prophet'=>'Prophet'] as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= $filter['model'] === $val ? 'selected' : '' ?>>
            <?= $lbl ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Search -->
      <div class="pad-filter-field">
        <label class="pad-flabel"><?= _('Search') ?></label>
        <input type="text" name="search" value="<?= htmlspecialchars($filter['search']) ?>"
               class="pad-text-input" placeholder="<?= _('Group or host name…') ?>"/>
      </div>

      <!-- Actions -->
      <div class="pad-filter-actions">
        <button type="submit" class="pad-btn pad-btn--primary">
          <?= _('Apply') ?>
        </button>
        <a href="<?= (new CUrl('zabbix.php'))
                        ->setArgument('action', 'predictive.anomaly.view')
                        ->setArgument('filter_rst', 1) ?>"
           class="pad-btn pad-btn--ghost">
          <?= _('Reset') ?>
        </a>
      </div>

    </div><!-- /.pad-filter-inner -->
  </form>
</div><!-- /.pad-filter-bar -->

<!-- ══════════════════════════════════════════════════════
     SUMMARY BAND
══════════════════════════════════════════════════════ -->
<div class="pad-summary-band" id="pad-summary-band">
  <?php
  $summary_items = [
    ['id'=>'stat-total',   'label'=>_('Monitored Hosts'),  'val'=>number_format($data['total_hosts']), 'color'=>'blue',   'sub'=>_('across all groups')],
    ['id'=>'stat-anom',    'label'=>_('Anomalous Hosts'),  'val'=>'—',  'color'=>'red',    'sub'=>_('loading…')],
    ['id'=>'stat-alerts',  'label'=>_('Predicted Alerts'), 'val'=>'—',  'color'=>'yellow', 'sub'=>_('next 6h')],
    ['id'=>'stat-exhaust', 'label'=>_('Exhaustion Risk'),  'val'=>'—',  'color'=>'orange', 'sub'=>_('within 7 days')],
    ['id'=>'stat-accuracy','label'=>_('Forecast Accuracy'),'val'=>'—',  'color'=>'green',  'sub'=>_('30-day avg')],
    ['id'=>'stat-models',  'label'=>_('Active Models'),    'val'=>'—',  'color'=>'purple', 'sub'=>_('detection engines')],
  ];
  foreach ($summary_items as $s):
  ?>
  <div class="pad-stat" id="<?= $s['id'] ?>">
    <div class="pad-stat__label"><?= $s['label'] ?></div>
    <div class="pad-stat__value pad-stat__value--<?= $s['color'] ?>"><?= $s['val'] ?></div>
    <div class="pad-stat__sub"><?= $s['sub'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════
     VIEW TABS + TOOLBAR
══════════════════════════════════════════════════════ -->
<div class="pad-toolbar">
  <div class="pad-tabs" id="pad-tabs" role="tablist">
    <button class="pad-tab active" data-tab="overview"   role="tab"><?= _('Group Overview') ?></button>
    <button class="pad-tab"        data-tab="heatmap"    role="tab"><?= _('Anomaly Heatmap') ?></button>
    <button class="pad-tab"        data-tab="exhaustion" role="tab"><?= _('Exhaustion') ?></button>
    <button class="pad-tab"        data-tab="forecasts"  role="tab"><?= _('Forecasts') ?></button>
    <button class="pad-tab"        data-tab="models"     role="tab"><?= _('ML Models') ?></button>
  </div>
  <div class="pad-toolbar-right">
    <button class="pad-btn pad-btn--sm" id="pad-export-csv">⬇ CSV</button>
    <button class="pad-btn pad-btn--sm pad-btn--primary" id="pad-refresh-btn">⟳ Refresh</button>
    <button class="pad-btn pad-btn--sm" id="pad-theme-btn">🌗</button>
    <span class="pad-live-indicator">
      <span class="pad-live-dot" id="pad-live-dot"></span>
      <span id="pad-live-label">LIVE</span>
    </span>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     TAB: GROUP OVERVIEW
══════════════════════════════════════════════════════ -->
<div class="pad-tab-panel active" id="tab-overview">

  <div class="pad-card">
    <div class="pad-card__head">
      <div class="pad-card__title">
        <span>🗂</span> <?= _('Host Group Anomaly Overview') ?>
      </div>
      <div class="pad-card__actions">
        <span class="pad-tag pad-tag--native">Zabbix Trends</span>
        <span class="pad-tag pad-tag--zscore">Z-Score</span>
        <div class="pad-sort-controls">
          <select id="pad-sort-field" class="pad-select pad-select--sm">
            <option value="score">Sort: Anomaly Score</option>
            <option value="name">Sort: Name</option>
            <option value="anomalous">Sort: Anomalous Hosts</option>
            <option value="alerts">Sort: Predicted Alerts</option>
            <option value="disk">Sort: Disk Usage</option>
          </select>
          <button class="pad-btn pad-btn--sm" id="pad-sort-order-btn" data-order="DESC">↓</button>
        </div>
      </div>
    </div>

    <div class="pad-table-wrap">
      <table class="pad-table" id="pad-group-table">
        <thead>
          <tr>
            <th class="sortable" data-col="name"><?= _('Group') ?></th>
            <th class="sortable" data-col="host_count"><?= _('Hosts') ?></th>
            <th class="sortable" data-col="anomalous"><?= _('Anomalous') ?></th>
            <th class="sortable" data-col="score"><?= _('Anomaly Score') ?></th>
            <th><?= _('CPU Avg') ?></th>
            <th><?= _('Memory Avg') ?></th>
            <th><?= _('Disk Avg') ?></th>
            <th class="sortable" data-col="predicted_alerts"><?= _('Pred. Alerts') ?></th>
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

    <!-- Pagination -->
    <div class="pad-pagination" id="pad-pagination">
      <button class="pad-btn pad-btn--sm" id="pad-prev-page" disabled>‹ Prev</button>
      <span class="pad-page-info" id="pad-page-info">Page 1</span>
      <button class="pad-btn pad-btn--sm" id="pad-next-page">Next ›</button>
    </div>
  </div>

</div><!-- /#tab-overview -->

<!-- ══════════════════════════════════════════════════════
     TAB: ANOMALY HEATMAP
══════════════════════════════════════════════════════ -->
<div class="pad-tab-panel" id="tab-heatmap">
  <div class="pad-card">
    <div class="pad-card__head">
      <div class="pad-card__title"><span>🔥</span> <?= _('Group × Hour Anomaly Heatmap') ?></div>
      <div class="pad-card__actions">
        <span class="pad-tag pad-tag--zscore">Z-Score Rolling 3σ</span>
        <select id="pad-hm-metric" class="pad-select pad-select--sm">
          <option value="all">All Metrics</option>
          <option value="cpu">CPU</option>
          <option value="memory">Memory</option>
          <option value="disk">Disk</option>
        </select>
        <button class="pad-btn pad-btn--sm" id="pad-hm-export">PNG</button>
      </div>
    </div>
    <div class="pad-card__body">
      <div class="pad-heatmap-wrap" id="pad-heatmap-wrap">
        <div class="pad-spinner-wrap"><div class="pad-spinner"></div><?= _('Building heatmap…') ?></div>
      </div>
      <div class="pad-hm-legend">
        <span class="pad-hm-legend__label"><?= _('Low') ?></span>
        <div class="pad-hm-scale" id="pad-hm-scale"></div>
        <span class="pad-hm-legend__label"><?= _('Critical') ?></span>
        <span class="pad-hm-legend__help"><?= _('Hover for details · Click to drilldown') ?></span>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     TAB: EXHAUSTION
══════════════════════════════════════════════════════ -->
<div class="pad-tab-panel" id="tab-exhaustion">
  <div class="pad-grid pad-grid--2">

    <div class="pad-card">
      <div class="pad-card__head">
        <div class="pad-card__title"><span>📉</span> <?= _('Resource Exhaustion Estimates') ?></div>
        <div class="pad-card__actions">
          <span class="pad-tag pad-tag--native">Zabbix Linear Trend</span>
          <select id="pad-ex-metric" class="pad-select pad-select--sm">
            <option value="disk">Disk</option>
            <option value="memory">Memory</option>
            <option value="cpu">CPU</option>
          </select>
        </div>
      </div>
      <div class="pad-card__body" id="pad-exhaust-body">
        <div class="pad-spinner-wrap"><div class="pad-spinner"></div></div>
      </div>
    </div>

    <div class="pad-card">
      <div class="pad-card__head">
        <div class="pad-card__title"><span>🗓</span> <?= _('Predicted Maintenance Windows') ?></div>
        <div class="pad-card__actions">
          <button class="pad-btn pad-btn--sm">Schedule ›</button>
        </div>
      </div>
      <div class="pad-card__body" id="pad-mw-body">
        <div class="pad-spinner-wrap"><div class="pad-spinner"></div></div>
      </div>
    </div>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     TAB: FORECASTS
══════════════════════════════════════════════════════ -->
<div class="pad-tab-panel" id="tab-forecasts">
  <div class="pad-grid pad-grid--2">

    <div class="pad-card">
      <div class="pad-card__head">
        <div class="pad-card__title"><span>⚡</span> <?= _('Fleet CPU — Avg + Forecast') ?></div>
        <div class="pad-card__actions">
          <span class="pad-tag pad-tag--native">Native</span>
          <span class="pad-tag pad-tag--zscore">Z-Score</span>
        </div>
      </div>
      <div class="pad-card__body">
        <div class="pad-chart-wrap" style="height:220px"><canvas id="chart-fleet-cpu"></canvas></div>
        <div class="pad-legend" id="legend-fleet-cpu"></div>
      </div>
    </div>

    <div class="pad-card">
      <div class="pad-card__head">
        <div class="pad-card__title"><span>🧠</span> <?= _('Fleet Memory — Avg + Forecast') ?></div>
        <div class="pad-card__actions">
          <span class="pad-tag pad-tag--arima">ARIMA</span>
        </div>
      </div>
      <div class="pad-card__body">
        <div class="pad-chart-wrap" style="height:220px"><canvas id="chart-fleet-mem"></canvas></div>
        <div class="pad-legend" id="legend-fleet-mem"></div>
      </div>
    </div>

    <div class="pad-card pad-card--full">
      <div class="pad-card__head">
        <div class="pad-card__title"><span>💾</span> <?= _('Fleet Disk — 30-day + 14-day Forecast') ?></div>
        <div class="pad-card__actions">
          <span class="pad-tag pad-tag--native">Zabbix Trends</span>
          <span class="pad-tag pad-tag--prophet">Prophet (if available)</span>
        </div>
      </div>
      <div class="pad-card__body">
        <div class="pad-chart-wrap" style="height:240px"><canvas id="chart-fleet-disk"></canvas></div>
        <div class="pad-legend" id="legend-fleet-disk"></div>
      </div>
    </div>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     TAB: ML MODELS
══════════════════════════════════════════════════════ -->
<div class="pad-tab-panel" id="tab-models">
  <div class="pad-card">
    <div class="pad-card__head">
      <div class="pad-card__title"><span>🤖</span> <?= _('Detection & Forecast Model Status') ?></div>
      <div class="pad-card__actions">
        <button class="pad-btn pad-btn--sm pad-btn--primary"><?= _('Configure Models') ?></button>
      </div>
    </div>
    <div class="pad-card__body" id="pad-model-body">
      <div class="pad-spinner-wrap"><div class="pad-spinner"></div></div>
    </div>
  </div>
</div>

</div><!-- /#pad-root -->

<!-- ══════════════════════════════════════════════════════
     DRILLDOWN DRAWER
══════════════════════════════════════════════════════ -->
<div class="pad-drawer-overlay" id="pad-drawer-overlay">
  <div class="pad-drawer" id="pad-drawer" role="dialog" aria-modal="true">
    <div class="pad-drawer__head">
      <div>
        <div class="pad-drawer__title" id="pad-drawer-title">—</div>
        <div class="pad-drawer__sub"   id="pad-drawer-sub">—</div>
      </div>
      <div class="pad-drawer__actions">
        <span class="pad-tag pad-tag--zscore">Drilldown</span>
        <button class="pad-drawer__close" id="pad-drawer-close">✕</button>
      </div>
    </div>
    <div class="pad-drawer__body" id="pad-drawer-body">
      <div class="pad-spinner-wrap"><div class="pad-spinner"></div><?= _('Loading host data…') ?></div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     HEATMAP TOOLTIP
══════════════════════════════════════════════════════ -->
<div class="pad-hm-tooltip" id="pad-hm-tooltip" role="tooltip"></div>

<!-- ══════════════════════════════════════════════════════
     PASS PHP STATE TO JS
══════════════════════════════════════════════════════ -->
<script>
window.PAD_CONFIG = <?= json_encode([
  'action_data'     => 'predictive.anomaly.data',
  'action_host'     => 'predictive.anomaly.host',
  'action_forecast' => 'predictive.anomaly.forecast',
  'filter'          => $filter,
  'csrf_token'      => CCsrfTokenHelper::get('predictive.anomaly'),
  'total_hosts'     => $data['total_hosts'],
  'time_from'       => $data['time_from'],
  'time_till'       => $data['time_till'],
  'strings'         => [
    'loading'          => _('Loading…'),
    'no_data'          => _('No anomalies found for current filter settings.'),
    'drilldown'        => _('Drilldown →'),
    'page_of'          => _('Page %d of %d'),
    'groups_found'     => _('%d groups · %d anomalous'),
    'hosts_found'      => _('%d hosts'),
    'forecast_label'   => _('Forecast'),
    'actual_label'     => _('Actual'),
    'ci_label'         => _('Confidence 95%'),
    'anomaly_label'    => _('Anomaly point'),
    'breach_in'        => _('Breach in'),
    'no_breach'        => _('No breach predicted'),
  ],
]) ?>;
</script>
