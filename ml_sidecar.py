#!/usr/bin/env python3
"""
Predictive Anomaly Dashboard — ML Sidecar Service
--------------------------------------------------
Optional Flask microservice providing Prophet and ARIMA forecasts.
The Zabbix module works fully without this service (falls back to
native Z-score + linear regression in PHP). Only start this if you
want ML-enriched confidence intervals and seasonal decomposition.

Install:
  pip install flask prophet statsmodels numpy scipy

Run:
  python3 ml_sidecar.py
  # or: gunicorn -w 4 -b 127.0.0.1:5001 ml_sidecar:app

The PHP CMLBridge pings GET /health before each request batch.
Heavy models are cached per itemid to avoid re-fitting every call.
"""

import os
import json
import logging
import hashlib
import numpy as np
from datetime import datetime, timedelta
from functools import lru_cache
from threading import Lock

from flask import Flask, request, jsonify

# Optional heavy deps — graceful import
try:
    from prophet import Prophet
    PROPHET_AVAILABLE = True
except ImportError:
    PROPHET_AVAILABLE = False
    logging.warning("Prophet not installed. /forecast?model=prophet will be skipped.")

try:
    from statsmodels.tsa.arima.model import ARIMA
    from statsmodels.tools.sm_exceptions import ConvergenceWarning
    import warnings
    warnings.filterwarnings("ignore", category=ConvergenceWarning)
    ARIMA_AVAILABLE = True
except ImportError:
    ARIMA_AVAILABLE = False
    logging.warning("statsmodels not installed. /forecast?model=arima will be skipped.")

# ─────────────────────────────────────────────────────────────────────────────
app = Flask(__name__)
logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
log = logging.getLogger(__name__)

# Simple in-memory model cache (itemid → fitted model + timestamp)
MODEL_CACHE      = {}
MODEL_CACHE_TTL  = 300  # seconds — refit if older than 5 min
MODEL_CACHE_LOCK = Lock()

FORECAST_STEPS   = 12  # points ahead to forecast

# ─────────────────────────────────────────────────────────────────────────────
# HEALTH
# ─────────────────────────────────────────────────────────────────────────────

@app.get('/health')
def health():
    return jsonify({
        'status':   'ok',
        'prophet':  PROPHET_AVAILABLE,
        'arima':    ARIMA_AVAILABLE,
        'version':  '1.0.0',
        'cached_models': len(MODEL_CACHE),
    })

# ─────────────────────────────────────────────────────────────────────────────
# FORECAST
# ─────────────────────────────────────────────────────────────────────────────

@app.post('/forecast')
def forecast():
    body = request.get_json(force=True, silent=True) or {}

    itemid = body.get('itemid')
    values = body.get('values', [])
    clocks = body.get('clocks', [])
    model  = body.get('model', 'all')   # all | arima | prophet

    if not values or not clocks or len(values) < 6:
        return jsonify({'error': 'Insufficient data'}), 400

    values = [float(v) for v in values]
    clocks = [int(c) for c in clocks]

    result = {}

    # Determine step size from data
    step = int((clocks[-1] - clocks[0]) / max(1, len(clocks) - 1))
    step = max(step, 60)

    # ── ARIMA ──────────────────────────────────────────────────────────────
    if ARIMA_AVAILABLE and model in ('all', 'arima'):
        try:
            arima_result = run_arima(itemid, values, clocks, step)
            result.update(arima_result)
            result['model'] = 'arima'
        except Exception as e:
            log.warning(f"ARIMA failed for item {itemid}: {e}")

    # ── Prophet ────────────────────────────────────────────────────────────
    if PROPHET_AVAILABLE and model in ('all', 'prophet') and not result:
        try:
            prophet_result = run_prophet(itemid, values, clocks, step)
            result.update(prophet_result)
            result['model'] = 'prophet'
        except Exception as e:
            log.warning(f"Prophet failed for item {itemid}: {e}")

    if not result:
        return jsonify({'error': 'No model available or all models failed'}), 503

    return jsonify(result)

# ─────────────────────────────────────────────────────────────────────────────
# ARIMA IMPLEMENTATION
# ─────────────────────────────────────────────────────────────────────────────

def run_arima(itemid, values, clocks, step):
    cache_key = f"arima_{itemid}_{hash(tuple(values[-10:]))}"
    cached    = _get_cached(cache_key)
    if cached:
        return cached

    # Auto-select order using simple heuristic
    order = _select_arima_order(values)

    model  = ARIMA(values, order=order)
    fitted = model.fit()

    forecast_result = fitted.get_forecast(steps=FORECAST_STEPS)
    fc_mean  = forecast_result.predicted_mean.tolist()
    fc_ci    = forecast_result.conf_int(alpha=0.05)
    fc_upper = fc_ci.iloc[:, 1].tolist()
    fc_lower = fc_ci.iloc[:, 0].tolist()

    # Anomaly score from residuals
    residuals   = fitted.resid.tolist()
    res_std     = float(np.std(residuals)) if residuals else 1.0
    max_residual= float(max(abs(r) for r in residuals)) if residuals else 0
    score       = min(1.0, max_residual / (res_std * 3 + 1e-9))

    # Accuracy (in-sample MAPE)
    actuals    = values
    preds      = fitted.fittedvalues.tolist()
    mape       = _mape(actuals, preds)
    accuracy   = round(max(0, 100 - mape), 1)

    result = {
        'forecast_series': fc_mean,
        'ci_upper':        fc_upper,
        'ci_lower':        fc_lower,
        'score':           round(score, 4),
        'accuracy':        accuracy,
        'mape':            round(mape, 2),
        'order':           list(order),
        'aic':             round(fitted.aic, 2),
    }

    _set_cached(cache_key, result)
    return result


def _select_arima_order(values):
    """Heuristic order selection: try (1,1,1) and (2,1,2), pick lower AIC."""
    best_order = (1, 1, 1)
    best_aic   = float('inf')

    for order in [(1, 1, 1), (2, 1, 2), (1, 1, 0), (0, 1, 1)]:
        try:
            m   = ARIMA(values, order=order)
            fit = m.fit()
            if fit.aic < best_aic:
                best_aic   = fit.aic
                best_order = order
        except Exception:
            continue

    return best_order

# ─────────────────────────────────────────────────────────────────────────────
# PROPHET IMPLEMENTATION
# ─────────────────────────────────────────────────────────────────────────────

def run_prophet(itemid, values, clocks, step):
    import pandas as pd

    cache_key = f"prophet_{itemid}_{hash(tuple(values[-10:]))}"
    cached    = _get_cached(cache_key)
    if cached:
        return cached

    # Build Prophet dataframe
    df = pd.DataFrame({
        'ds': [datetime.utcfromtimestamp(c) for c in clocks],
        'y':  values,
    })

    m = Prophet(
        interval_width       = 0.95,
        changepoint_prior_scale = 0.05,
        daily_seasonality    = len(values) > 48,   # only if we have enough data
        weekly_seasonality   = len(values) > 336,
        yearly_seasonality   = False,
    )
    m.fit(df)

    # Future dataframe
    freq_str = _step_to_freq(step)
    future   = m.make_future_dataframe(periods=FORECAST_STEPS, freq=freq_str)
    forecast = m.predict(future)

    # Forecast only (not history)
    fc_rows  = forecast.tail(FORECAST_STEPS)
    fc_mean  = fc_rows['yhat'].tolist()
    fc_upper = fc_rows['yhat_upper'].tolist()
    fc_lower = fc_rows['yhat_lower'].tolist()

    # Score from historical forecast error
    hist    = forecast.head(len(values))
    residuals = [abs(a - p) for a, p in zip(values, hist['yhat'].tolist())]
    std_vals  = float(np.std(values)) or 1.0
    score     = min(1.0, float(np.mean(residuals)) / (std_vals + 1e-9))

    mape     = _mape(values, hist['yhat'].tolist())
    accuracy = round(max(0, 100 - mape), 1)

    result = {
        'forecast_series': fc_mean,
        'ci_upper':        fc_upper,
        'ci_lower':        fc_lower,
        'score':           round(score, 4),
        'accuracy':        accuracy,
        'mape':            round(mape, 2),
        'changepoints':    len(m.changepoints),
    }

    _set_cached(cache_key, result)
    return result


def _step_to_freq(step_seconds):
    if step_seconds <= 60:   return '1min'
    if step_seconds <= 300:  return '5min'
    if step_seconds <= 900:  return '15min'
    if step_seconds <= 3600: return '1h'
    if step_seconds <= 21600:return '6h'
    return '1D'

# ─────────────────────────────────────────────────────────────────────────────
# CACHE HELPERS
# ─────────────────────────────────────────────────────────────────────────────

def _get_cached(key):
    with MODEL_CACHE_LOCK:
        entry = MODEL_CACHE.get(key)
        if entry and (datetime.utcnow() - entry['ts']).seconds < MODEL_CACHE_TTL:
            return entry['data']
    return None

def _set_cached(key, data):
    with MODEL_CACHE_LOCK:
        # Cap cache size
        if len(MODEL_CACHE) > 2000:
            oldest = min(MODEL_CACHE, key=lambda k: MODEL_CACHE[k]['ts'])
            del MODEL_CACHE[oldest]
        MODEL_CACHE[key] = {'data': data, 'ts': datetime.utcnow()}

# ─────────────────────────────────────────────────────────────────────────────
# UTILS
# ─────────────────────────────────────────────────────────────────────────────

def _mape(actuals, predictions):
    """Mean Absolute Percentage Error — ignores zeros in actuals."""
    pairs = [(a, p) for a, p in zip(actuals, predictions) if abs(a) > 1e-9]
    if not pairs:
        return 0.0
    return float(np.mean([abs(a - p) / abs(a) * 100 for a, p in pairs]))

# ─────────────────────────────────────────────────────────────────────────────

if __name__ == '__main__':
    port = int(os.environ.get('PAD_ML_PORT', 5001))
    host = os.environ.get('PAD_ML_HOST', '127.0.0.1')
    log.info(f"ML Sidecar starting on {host}:{port}")
    log.info(f"Prophet: {'available' if PROPHET_AVAILABLE else 'NOT installed'}")
    log.info(f"ARIMA:   {'available' if ARIMA_AVAILABLE else 'NOT installed'}")
    app.run(host=host, port=port, debug=False)
