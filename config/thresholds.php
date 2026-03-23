<?php
/**
 * ============================================================================
 * PREDICTIVE ANOMALY DASHBOARD — ANOMALY DETECTION THRESHOLDS
 * ============================================================================
 *
 * Controls the sensitivity of anomaly detection and forecasting.
 * Edit this file to tune false-positive/negative rates.
 *
 * ============================================================================
 */

return [

    // ── Z-SCORE DETECTION ─────────────────────────────────────────────────
    // How many standard deviations from the rolling mean before a point
    // is flagged as anomalous.
    // Lower  = more sensitive (more alerts, more false positives)
    // Higher = less sensitive (fewer alerts, may miss real anomalies)
    // Typical range: 2.0 – 3.5  |  Default: 2.5
    'z_score_threshold' => 2.5,

    // Rolling window as a fraction of the series length.
    // 0.3 = use the last 30% of data points as the rolling baseline window.
    // Range: 0.1 – 1.0  |  Default: 0.3
    'z_window_fraction' => 0.3,

    // ── LINEAR REGRESSION ─────────────────────────────────────────────────
    // Number of steps ahead to forecast.
    // Each "step" is one data interval (e.g. 1h for 24h range, 1d for 30d).
    // Default: 12
    'forecast_steps' => 12,

    // Confidence interval coverage.
    // 1.645 = 90%,  1.96 = 95%,  2.576 = 99%
    // Default: 1.96 (95%)
    'confidence_interval_z' => 1.96,

    // ── RESOURCE EXHAUSTION THRESHOLDS ───────────────────────────────────
    // When a metric is predicted to cross these levels, it is flagged
    // for resource exhaustion warnings and maintenance window suggestions.
    // Values are percentages (0–100).
    'exhaustion_thresholds' => [
        'disk'    => 85,    // flag disk when projected to hit 85%
        'memory'  => 90,    // flag memory when projected to hit 90%
        'cpu'     => 95,    // flag CPU when projected to hit 95%
        'network' => 80,
        'iops'    => 80,
        'load'    => 0,     // 0 = disabled for this metric
    ],

    // ── ANOMALY SCORE BLENDING ────────────────────────────────────────────
    // Weights for combining Z-score and linear regression scores.
    // Must sum to 1.0 when no ML sidecar is active.
    // When ML sidecar IS active, ml_weight is taken from the remainder.
    'score_weights' => [
        'z_score' => 0.55,   // Z-score contribution
        'linear'  => 0.45,   // Linear regression contribution
        'ml'      => 0.20,   // ML sidecar contribution (reduces z+linear proportionally)
    ],

    // ── ANOMALY SCORE SEVERITY BANDS ─────────────────────────────────────
    // These define what anomaly score ranges mean visually.
    // Used for colour coding and status labels throughout the UI.
    'severity_bands' => [
        'critical' => 0.75,  // score >= 0.75 → red / Critical
        'warning'  => 0.50,  // score >= 0.50 → orange / Warning
        'info'     => 0.30,  // score >= 0.30 → yellow / Info
        // below info → green / OK
    ],

    // ── MAINTENANCE WINDOW SCHEDULING ────────────────────────────────────
    // When a breach is predicted, suggest scheduling maintenance this many
    // days BEFORE the predicted breach date (lead time).
    'maintenance_lead_days' => 7,

    // Minimum days ahead to suggest a maintenance window.
    // (Don't suggest maintenance for things breaching within 1 day — act immediately)
    'maintenance_min_days' => 1,

];
