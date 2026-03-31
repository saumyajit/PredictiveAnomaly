<?php
/**
 * ============================================================================
 * PREDICTIVE ANOMALY DASHBOARD — METRIC CONFIGURATION
 * ============================================================================
 *
 * HOW TO CUSTOMISE:
 *   - 'keys'         — Zabbix item key prefixes (matched with searchByAny)
 *   - 'prefer_units' — If set, ONLY use items whose Zabbix 'units' field matches.
 *                      Set to '%' to ensure we only get percentage items, not bytes.
 *                      Set to null to accept any unit (bytes, bps, etc.)
 *   - 'unit'         — Display unit appended to chart axis values
 *   - 'threshold'    — Usage % above which anomaly scoring is boosted (0 = disabled)
 *   - 'enabled'      — Set false to hide metric without deleting it
 *
 * KEY EXAMPLES FOR COMMON TEMPLATES:
 *   CPU %:      system.cpu.util        (all non-idle variants)
 *   Memory %:   vm.memory.utilization  (agent2) | vm.memory.size[pused] (agent)
 *   Disk %:     vfs.fs.size[/,pused]   (specific mount) | vfs.fs.size[pused] (any)
 *   Network:    net.if.in | net.if.out (bytes/sec)
 * ============================================================================
 */

return [

    'cpu' => [
        'label'        => 'CPU',
        'icon'         => '⚡',
        'unit'         => '%',
        'enabled'      => true,
        'threshold'    => 70,
        'prefer_units' => '%',          // Only accept % items (not raw counts)
        'keys'         => [
            'system.cpu.util',          // Zabbix agent: CPU utilisation %
            'vm.cpu.util',              // VMware CPU %
        ],
    ],

    'memory' => [
        'label'        => 'Memory',
        'icon'         => '🧠',
        'unit'         => '%',
        'enabled'      => true,
        'threshold'    => 75,
        'prefer_units' => '%',          // CRITICAL: reject vm.memory.size[total] (bytes)
        'keys'         => [
            'vm.memory.utilization',    // Zabbix agent2: memory used % (preferred)
            'vm.memory.size[pused]',    // Zabbix agent:  memory used %
            'vm.memory.size[pavailable]', // Zabbix agent: memory available % (inverted)
        ],
    ],

    'disk' => [
        'label'        => 'Disk',
        'icon'         => '💾',
        'unit'         => '%',
        'enabled'      => true,
        'threshold'    => 75,
        'prefer_units' => '%',          // CRITICAL: reject vfs.fs.size[/,total] (bytes)
        'keys'         => [
            'vfs.fs.size',              // matches pused variant — will filter by unit=%
        ],
    ],

    'network' => [
        'label'        => 'Network',
        'icon'         => '🌐',
        'unit'         => 'bps',
        'enabled'      => true,
        'threshold'    => 0,
        'prefer_units' => null,         // bytes/sec acceptable
        'keys'         => [
            'net.if.in',
            'net.if.out',
        ],
    ],

    'iops' => [
        'label'        => 'IOPS',
        'icon'         => '📀',
        'unit'         => 'ops',
        'enabled'      => true,
        'threshold'    => 0,
        'prefer_units' => null,
        'keys'         => [
            'vfs.dev.read.ops',
            'vfs.dev.write.ops',
            'vfs.dev.read.rate',
        ],
    ],

    'load' => [
        'label'        => 'Load',
        'icon'         => '📊',
        'unit'         => '',
        'enabled'      => true,
        'threshold'    => 0,
        'prefer_units' => null,
        'keys'         => [
            'system.cpu.load',
        ],
    ],

    // ── ADD CUSTOM METRICS BELOW ──────────────────────────────────────────
    // 'oracle_waits' => [
    //     'label' => 'Oracle Waits', 'icon' => '🗄', 'unit' => 'ms',
    //     'enabled' => true, 'threshold' => 0, 'prefer_units' => null,
    //     'keys' => ['oracle.wait_time'],
    // ],
];
