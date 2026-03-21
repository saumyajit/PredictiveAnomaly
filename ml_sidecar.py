#!/usr/bin/env python3
"""
Predictive Anomaly Dashboard — Optional ML Sidecar
Module works fully without this. Start only for Prophet/ARIMA enrichment.

Install: pip install flask prophet statsmodels numpy
Run:     python3 ml_sidecar.py
"""
import os, json, logging, numpy as np
from datetime import datetime
from threading import Lock
from flask import Flask, request, jsonify

try:
    from prophet import Prophet
    PROPHET = True
except ImportError:
    PROPHET = False

try:
    from statsmodels.tsa.arima.model import ARIMA
    ARIMA_OK = True
except ImportError:
    ARIMA_OK = False

app    = Flask(__name__)
CACHE  = {}
LOCK   = Lock()
STEPS  = 12

@app.get('/health')
def health():
    return jsonify({'status':'ok','prophet':PROPHET,'arima':ARIMA_OK,'cached':len(CACHE)})

@app.post('/forecast')
def forecast():
    body   = request.get_json(force=True, silent=True) or {}
    values = [float(v) for v in body.get('values',[])]
    clocks = [int(c) for c in body.get('clocks',[])]
    model  = body.get('model','all')
    iid    = body.get('itemid',0)
    if len(values) < 6:
        return jsonify({'error':'Insufficient data'}), 400

    step = max(int((clocks[-1]-clocks[0])/max(len(clocks)-1,1)), 60) if len(clocks)>1 else 3600
    result = {}

    if ARIMA_OK and model in ('all','arima') and not result:
        try:
            result = run_arima(iid, values, clocks, step)
            result['model'] = 'arima'
        except Exception as e:
            logging.warning(f'ARIMA failed: {e}')

    if PROPHET and model in ('all','prophet') and not result:
        try:
            result = run_prophet(iid, values, clocks, step)
            result['model'] = 'prophet'
        except Exception as e:
            logging.warning(f'Prophet failed: {e}')

    return jsonify(result) if result else (jsonify({'error':'No model available'}), 503)

def run_arima(iid, values, clocks, step):
    key = f'a{iid}_{hash(tuple(values[-5:]))}'
    cached = _get(key)
    if cached: return cached
    best_order, best_aic = (1,1,1), float('inf')
    for order in [(1,1,1),(2,1,2),(1,1,0),(0,1,1)]:
        try:
            aic = ARIMA(values, order=order).fit().aic
            if aic < best_aic: best_aic, best_order = aic, order
        except: pass
    fit = ARIMA(values, order=best_order).fit()
    fc  = fit.get_forecast(STEPS)
    ci  = fc.conf_int(alpha=0.05)
    res = fit.resid.tolist()
    mape = _mape(values, fit.fittedvalues.tolist())
    r = {'forecast_series':fc.predicted_mean.tolist(),'ci_upper':ci.iloc[:,1].tolist(),'ci_lower':ci.iloc[:,0].tolist(),'score':round(min(1.0,max(map(abs,res))/(np.std(res)*3+1e-9)),4),'accuracy':round(max(0,100-mape),1),'mape':round(mape,2)}
    _set(key, r)
    return r

def run_prophet(iid, values, clocks, step):
    import pandas as pd
    key = f'p{iid}_{hash(tuple(values[-5:]))}'
    cached = _get(key)
    if cached: return cached
    df = pd.DataFrame({'ds':[datetime.utcfromtimestamp(c) for c in clocks],'y':values})
    m  = Prophet(interval_width=0.95,changepoint_prior_scale=0.05,daily_seasonality=len(values)>48,weekly_seasonality=False,yearly_seasonality=False)
    m.fit(df)
    freq = {60:'1min',300:'5min',900:'15min',3600:'1h',21600:'6h'}.get(step,'1D')
    future = m.make_future_dataframe(periods=STEPS, freq=freq)
    fc = m.predict(future).tail(STEPS)
    hist = m.predict(df)
    mape = _mape(values, hist['yhat'].tolist())
    r = {'forecast_series':fc['yhat'].tolist(),'ci_upper':fc['yhat_upper'].tolist(),'ci_lower':fc['yhat_lower'].tolist(),'score':round(min(1.0,np.mean([abs(a-p) for a,p in zip(values,hist['yhat'].tolist())])/(np.std(values)+1e-9)),4),'accuracy':round(max(0,100-mape),1),'mape':round(mape,2)}
    _set(key, r)
    return r

def _mape(a, p):
    pairs = [(x,y) for x,y in zip(a,p) if abs(x)>1e-9]
    return float(np.mean([abs(x-y)/abs(x)*100 for x,y in pairs])) if pairs else 0

def _get(k):
    with LOCK:
        e = CACHE.get(k)
        return e['d'] if e and (datetime.utcnow()-e['t']).seconds<300 else None

def _set(k, d):
    with LOCK:
        if len(CACHE)>2000:
            oldest = min(CACHE, key=lambda x: CACHE[x]['t'])
            del CACHE[oldest]
        CACHE[k] = {'d':d,'t':datetime.utcnow()}

if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO)
    port = int(os.environ.get('PAD_ML_PORT',5001))
    host = os.environ.get('PAD_ML_HOST','127.0.0.1')
    logging.info(f'ML Sidecar: {host}:{port} | Prophet:{PROPHET} | ARIMA:{ARIMA_OK}')
    app.run(host=host, port=port, debug=False)
