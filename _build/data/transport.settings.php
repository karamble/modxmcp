<?php
/**
 * System settings shipped with the package.
 *
 * A fresh install exposes nothing, because it has no tokens: every request is
 * rejected until an administrator deliberately creates one. That single act is
 * the gate, so the endpoint itself ships enabled and the extra works as soon as
 * it is installed.
 *
 * Generic object access is the exception and ships closed. Both allowlists are
 * empty because, unlike everything else, that path has no MODX permission check
 * behind it, so a permissive default would expose whatever an installed extra
 * happens to store.
 *
 * @var \MODX\Revolution\modX $modx
 * @var array $sources
 */

$settings = [];

$entries = [
    'modxmcp.enabled' => [
        // On by default. This is a kill switch, not the access control: the
        // token is. A fresh install has zero tokens, so every request 401s
        // until an administrator deliberately creates one. Shipping this off
        // bought almost nothing and cost the out-of-box experience, forcing a
        // hunt through System Settings before the extra did anything.
        //
        // Stored as an explicit string: a PHP boolean persists as '' and reads
        // as unset rather than as a deliberate choice.
        'value' => '1',
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
