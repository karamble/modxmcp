<?php

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modResource;
use MODX\Revolution\modSystemSetting;

/**
 * Everything the modxmcp page needs to render its Settings tab.
 *
 * Administration of this extra belongs on the extra's own page. Sending an
 * administrator to System Settings to turn the endpoint on, or to Components to
 * find the page in the first place, is the kind of scavenger hunt that makes an
 * extra feel unfinished.
 */
class ModxmcpSettingsGetProcessor extends Processor
{
    public $languageTopics = ['modxmcp:default'];
    public $permission     = 'settings';

    /** Only these are editable here. Nothing else in system settings is exposed. */
    private const KEYS = [
        'modxmcp.enabled',
        'modxmcp.log_arguments',
        'modxmcp.audit_retention_days',
        'modxmcp.discovery_cache_seconds',
        'modxmcp.trusted_proxy_header',
        'modxmcp.read_class_allowlist',
        'modxmcp.write_class_allowlist',
    ];

    public function process()
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $setting = $this->modx->getObject(modSystemSetting::class, ['key' => $key]);
            // Short name, so the form fields read as modxmcp's own rather than
            // as raw system-setting keys.
            $values[substr($key, strlen('modxmcp.'))] = $setting ? $setting->get('value') : '';
        }

        return $this->success('', [
            'settings' => $values,
            'endpoint' => $this->endpointUrl(),
            'stats'    => $this->stats(),
        ]);
    }

    /**
     * Resolved from the resource that actually hosts the snippet rather than
     * from a remembered alias, so renaming the resource cannot leave a stale
     * URL on screen.
     */
    private function endpointUrl(): string
    {
        $resource = $this->modx->getObject(modResource::class, [
            'content:LIKE' => '%[[!modxmcp%',
            'deleted'      => 0,
        ]);
        if (!$resource) {
            return '';
        }

        return rtrim((string) $this->modx->getOption('site_url'), '/')
            . '/' . ltrim((string) $resource->get('uri'), '/');
    }

    /**
     * @return array<string,mixed>
     */
    private function stats(): array
    {
        $prefix = $this->modx->getOption('table_prefix');

        $count = function (string $sql): int {
            try {
                $stmt = $this->modx->prepare($sql);
                $stmt->execute();
                return (int) $stmt->fetchColumn();
            } catch (\Throwable $e) {
                return 0;
            }
        };

        return [
            'tokens_active' => $count("SELECT COUNT(*) FROM {$prefix}modxmcp_token WHERE active = 1"),
            'tokens_total'  => $count("SELECT COUNT(*) FROM {$prefix}modxmcp_token"),
            'audit_total'   => $count("SELECT COUNT(*) FROM {$prefix}modxmcp_audit"),
            'audit_failed'  => $count("SELECT COUNT(*) FROM {$prefix}modxmcp_audit WHERE success = 0"),
        ];
    }
}

return 'ModxmcpSettingsGetProcessor';
