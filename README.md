# Predictive Anomaly Dashboard — Zabbix 7.x Frontend Module

A production-grade Zabbix frontend module providing predictive anomaly detection,
time-series forecasting, and resource exhaustion estimates across 25,000+ hosts.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  Zabbix Frontend (PHP 8.x)                                  │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Module: PredictiveAnomaly                           │  │
│  │                                                      │  │
│  │  Actions (MVC Controllers)                           │  │
│  │  ├── CControllerPredictiveAnomalyView   (page)       │  │
│  │  ├── CControllerPredictiveAnomalyData   (JSON/AJAX)  │  │
│  │  ├── CControllerPredictiveAnomalyHost   (JSON/AJAX)  │  │
│  │  └── CControllerPredictiveAnomalyForecast (JSON)     │  │
│  │                                                      │  │
│  │  Services                                            │  │
│  │  ├── CAnomalyEngine  ← Z-score + Linear regression   │  │
│  │  │   (pure PHP, no deps, primary engine)             │  │
│  │  └── CMLBridge       ← Optional Flask sidecar        │  │
│  │      (ARIMA + Prophet, graceful fallback)            │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                             │
│  Zabbix API calls: trend.get / history.get / item.get /    │
│                    host.get / hostgroup.get                 │
└─────────────────────────────────────────────────────────────┘
         │ optional HTTP
         ▼
┌─────────────────────────────────────────┐
│  ML Sidecar (Python 3.9+)               │
│  ml_sidecar.py                          │
│  ├── POST /forecast  ← ARIMA + Prophet  │
│  └── GET  /health    ← availability     │
└─────────────────────────────────────────┘
```

## Data flow for 25k+ hosts

```
Page load → CControllerPredictiveAnomalyView
              │ (lightweight: group list + filter state)
              │
              ▼
JS: apiFetch(predictive.anomaly.data, page=1)
              │
              ▼
CControllerPredictiveAnomalyData
  ├── API::HostGroup()->get()       ← all groups, paginated 50/req
  ├── API::Host()->get()            ← hosts per group (capped 500)
  ├── API::Trend()->get()  OR       ← 7d/30d ranges
  │   API::History()->get()         ← shorter ranges
  ├── CAnomalyEngine::zScoreAnomalyScore()
  ├── CAnomalyEngine::linearRegressionForecast()
  └── CMLBridge::forecast()  (optional)
              │
              ▼
JSON → JS renders table + summary band
              │
User drills down → JS: apiFetch(predictive.anomaly.host)
              │
              ▼
CControllerPredictiveAnomalyHost  ← per-host detail
```

---

## Directory Structure

```
predictive-anomaly-dashboard/
├── manifest.json                          ← Module manifest (Zabbix 7.x)
├── Module.php                             ← Module bootstrap + menu registration
├── actions/
│   ├── CControllerPredictiveAnomalyView.php      ← Main page controller
│   ├── CControllerPredictiveAnomalyData.php      ← Group aggregation (JSON)
│   ├── CControllerPredictiveAnomalyHost.php      ← Host drilldown (JSON)
│   └── CControllerPredictiveAnomalyForecast.php  ← Item forecast (JSON)
├── services/
│   ├── CAnomalyEngine.php                 ← Z-score + linear regression (pure PHP)
│   └── CMLBridge.php                      ← Optional Python/Flask bridge
├── views/
│   └── predictive.anomaly.view.php        ← HTML view template
├── assets/
│   ├── css/
│   │   └── predictive-anomaly.css         ← Full stylesheet (dark + light)
│   └── js/
│       └── predictive-anomaly.js          ← All frontend logic
└── ml_sidecar.py                          ← Optional Python ML service
```

---

## Installation

### 1. Copy module to Zabbix

```bash
cp -r predictive-anomaly-dashboard \
  /usr/share/zabbix/modules/predictive-anomaly-dashboard
```

### 2. Set permissions

```bash
chown -R www-data:www-data \
  /usr/share/zabbix/modules/predictive-anomaly-dashboard
```

### 3. Enable in Zabbix UI

1. Go to **Administration → General → Modules**
2. Click **Scan directory**
3. Find **Predictive Anomaly Dashboard** and click **Enable**
4. Navigate to **Monitoring → Predictive Anomaly**

---

## Configuration

### PHP (optional — add to zabbix.conf.php)

```php
// ML sidecar URL (leave unset to use only native PHP engine)
define('PREDICTIVE_ML_API_URL', 'http://127.0.0.1:5001');
```

### Metric key mapping

Edit `CControllerPredictiveAnomalyData::buildMetricKeyMap()` to match your
Zabbix template item keys if they differ from the Zabbix standard templates:

```php
$all_keys = [
    'cpu'     => ['system.cpu.util', 'system.cpu.load'],
    'memory'  => ['vm.memory.utilization', 'vm.memory.size[pavailable]'],
    'disk'    => ['vfs.fs.size[/,pused]', 'vfs.fs.size[/data,pused]'],
    // Add your custom keys here
    'custom'  => ['your.custom.item.key'],
];
```

### Anomaly thresholds

Edit `CAnomalyEngine`:

```php
const Z_THRESHOLD   = 2.5;  // σ — lower = more sensitive
const FORECAST_STEPS = 12;  // steps ahead to forecast
const CI_Z           = 1.96; // 95% confidence interval
```

---

## Optional: ML Sidecar Setup

The module works fully without the sidecar. Install it only if you need
Prophet seasonality decomposition or ARIMA model accuracy metrics.

```bash
# Install dependencies
pip3 install flask prophet statsmodels numpy scipy gunicorn

# Run (development)
python3 ml_sidecar.py

# Run (production with gunicorn)
gunicorn -w 4 -b 127.0.0.1:5001 ml_sidecar:app

# Run as systemd service
cat > /etc/systemd/system/pad-ml.service << 'EOF'
[Unit]
Description=Predictive Anomaly ML Sidecar
After=network.target

[Service]
User=www-data
WorkingDirectory=/usr/share/zabbix/modules/predictive-anomaly-dashboard
ExecStart=/usr/bin/gunicorn -w 2 -b 127.0.0.1:5001 ml_sidecar:app
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl enable --now pad-ml
```

---

## Filters Reference

| Filter | Type | Description |
|--------|------|-------------|
| Host Groups | Multi-select | Filter to specific groups |
| Metrics | Chip toggle | CPU / Memory / Disk / Network / IOPS / Load |
| Severity | Chip toggle | Critical / Warning / Info |
| Time Range | Radio | 1h / 6h / 24h / 7d / 30d |
| Min Score | Slider | 0.00–1.00 anomaly score threshold |
| Model | Select | All / Z-Score / Linear Regression / ARIMA / Prophet |
| Search | Text | Group or host name search |

All filters persist in session via `CProfile`.

---

## Anomaly Score Interpretation

| Score | Colour | Meaning |
|-------|--------|---------|
| 0.00–0.19 | 🟢 Green | Normal — no anomaly |
| 0.20–0.39 | 🔵 Cyan | Mild deviation |
| 0.40–0.54 | 🟡 Yellow | Moderate — watch |
| 0.55–0.74 | 🟠 Orange | High — investigate |
| 0.75–1.00 | 🔴 Red | Critical anomaly |

Score blending weights (when all models run):
- Z-score: **45%**
- Linear regression: **35%**
- ML sidecar (ARIMA/Prophet): **20%**

---

## Performance Notes for 25k+ Hosts

- **Aggregation-first**: Main view shows host groups, not individual hosts
- **Page size**: 50 groups per API request; JS fetches all pages in parallel
- **Host cap per group**: 500 hosts processed per group (summary view)
- **Trend vs history**: Uses `trend.get` for 7d/30d, `history.get` for ≤24h
- **ML sidecar timeout**: 2 second hard limit — never blocks page render
- **Session caching**: Filter state persists via Zabbix `CProfile`
- **Auto-refresh**: 60s interval, only refreshes active tab
- **Drilldown lazy load**: Host data only fetched on drawer open

---

## Browser Support

Chrome 90+, Firefox 88+, Safari 14+, Edge 90+

Requires: Chart.js 4.x (loaded from Zabbix CDN or bundled)

---

## License

MIT — see LICENSE file.
