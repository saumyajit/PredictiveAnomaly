<?php
/**
 * ============================================================================
 * PREDICTIVE ANOMALY DASHBOARD — METRIC CONFIGURATION
 * ============================================================================
 *
 * Edit this file to define which Zabbix item keys map to each metric slug.
 * This file is loaded by all controllers at runtime.
 *
 * HOW TO CUSTOMISE:
 *   - Add a new metric slug with its item key patterns
 *   - Each pattern is a prefix match against item key_ (no brackets needed)
 *   - Multiple patterns per metric are OR'd — the first matching item is used
 *   - Set 'enabled' => false to hide a metric from the UI without deleting it
 *   - 'label'    — display name shown in filter chips
 *   - 'unit'     — suffix appended to values (%, MB, GB, etc.)
 *   - 'icon'     — emoji shown in the UI
 *   - 'threshold'— usage % above which anomaly scoring is boosted
 *                  (e.g. 70 means: only score seriously if value > 70%)
 *
 * ITEM KEY PATTERNS:
 *   Zabbix item keys like "system.cpu.util[,idle]" are matched by prefix.
 *   Write just the base key without brackets: "system.cpu.util"
 *
 * ============================================================================
 */

return [

    'cpu' => [
        'label'     => 'CPU',
        'icon'      => '⚡',
        'unit'      => '%',
        'enabled'   => true,
        'threshold' => 70,   // boost anomaly score when CPU > 70%
        'keys'      => [
            'system.cpu.util',          // Zabbix agent: CPU utilisation
            'system.cpu.load',          // Zabbix agent: CPU load
            'vm.cpu.util',              // VMware CPU
            'proc.cpu.util',            // per-process CPU
        ],
    ],

    'memory' => [
        'label'     => 'Memory',
        'icon'      => '🧠',
        'unit'      => '%',
        'enabled'   => true,
        'threshold' => 75,
        'keys'      => [
            'vm.memory.utilization',    // Zabbix agent 2: memory %
            'vm.memory.size',           // Zabbix agent: memory size variants
            'system.swap.size',         // Swap usage
        ],
    ],

    'disk' => [
        'label'     => 'Disk',
        'icon'      => '💾',
        'unit'      => '%',
        'enabled'   => true,
        'threshold' => 75,   // alert when disk > 75% used
        'keys'      => [
            'vfs.fs.size',              // Zabbix agent: filesystem size (pused variant)
            'vfs.fs.inode',             // Inode usage
        ],
    ],

    'network' => [
        'label'     => 'Network',
        'icon'      => '🌐',
        'unit'      => 'bps',
        'enabled'   => true,
        'threshold' => 0,    // 0 = always score, no threshold
        'keys'      => [
            'net.if.in',               // Zabbix agent: incoming traffic
            'net.if.out',              // Zabbix agent: outgoing traffic
            'net.if.total',            // Combined traffic
        ],
    ],

    'iops' => [
        'label'     => 'IOPS',
        'icon'      => '📀',
        'unit'      => 'ops',
        'enabled'   => true,
        'threshold' => 0,
        'keys'      => [
            'vfs.dev.read.ops',        // Disk read operations/sec
            'vfs.dev.write.ops',       // Disk write operations/sec
            'vfs.dev.read.rate',       // Alternative read rate key
        ],
    ],

    'load' => [
        'label'     => 'Load',
        'icon'      => '📊',
        'unit'      => '',
        'enabled'   => true,
        'threshold' => 0,
        'keys'      => [
            'system.cpu.load',         // 1/5/15 min load average
        ],
    ],

    // ── ADD CUSTOM METRICS BELOW ──────────────────────────────────────────
    //
    // 'oracle_waits' => [
    //     'label'     => 'Oracle Waits',
    //     'icon'      => '🗄',
    //     'unit'      => 'ms',
    //     'enabled'   => true,
    //     'threshold' => 0,
    //     'keys'      => [
    //         'oracle.wait_time',
    //         'db.oracle.session',
    //     ],
    // ],
    //
    // 'jvm_heap' => [
    //     'label'     => 'JVM Heap',
    //     'icon'      => '☕',
    //     'unit'      => '%',
    //     'enabled'   => true,
    //     'threshold' => 80,
    //     'keys'      => [
    //         'jmx[java.lang:type=Memory,HeapMemoryUsage.used]',
    //     ],
    // ],

];
