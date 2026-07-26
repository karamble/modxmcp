<?php
/**
 * System settings shipped with the package.
 *
 * Every default here is the safe one. modxmcp is disabled on install and both
 * generic-access allowlists are empty, so a fresh install accepts no requests
 * and exposes no data until an administrator makes a deliberate choice. An
 * extra that hands out API access the moment it is installed would be a
 * liability on sites whose owners were only evaluating it.
 *
 * @var \MODX\Revolution\modX $modx
 * @var array $sources
 */

$settings = [];

$entries = [
    'modxmcp.enabled' => [
        // Stored as an explicit string. A PHP false is persisted as '', which
        // is falsy enough to fail safe but renders wrong in a combo-boolean and
        // reads as "unset" rather than "deliberately off".
        'value' => '0',
        'xtype' => 'combo-boolean',
        'area'  => 'modxmcp.main',
    ],
    'modxmcp.log_arguments' => [
        'value' => '0',
        'xtype' => 'combo-boolean',
        'area'  => 'modxmcp.main',
    ],
    'modxmcp.audit_retention_days' => [
        'value' => '90',
        'xtype' => 'textfield',
        'area'  => 'modxmcp.main',
    ],
    'modxmcp.trusted_proxy_header' => [
        'value' => '',
        'xtype' => 'textfield',
        'area'  => 'modxmcp.main',
    ],
    'modxmcp.discovery_cache_seconds' => [
        'value' => '300',
        'xtype' => 'textfield',
        'area'  => 'modxmcp.main',
    ],
    'modxmcp.read_class_allowlist' => [
        'value' => '',
        'xtype' => 'textarea',
        'area'  => 'modxmcp.generic_access',
    ],
    'modxmcp.write_class_allowlist' => [
        'value' => '',
        'xtype' => 'textarea',
        'area'  => 'modxmcp.generic_access',
    ],
];

foreach ($entries as $key => $definition) {
    $setting = $modx->newObject(\MODX\Revolution\modSystemSetting::class);
    $setting->fromArray(array_merge([
        'key'       => $key,
        'namespace' => 'modxmcp',
    ], $definition), '', true, true);
    $settings[$key] = $setting;
}

return $settings;
