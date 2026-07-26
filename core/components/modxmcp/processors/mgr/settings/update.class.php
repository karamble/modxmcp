<?php

use MODX\Revolution\Processors\Processor;
use MODX\Revolution\modSystemSetting;

/**
 * Save the modxmcp settings from the extra's own page.
 *
 * Deliberately restricted to a fixed key list. This processor is gated on the
 * same 'settings' permission as System Settings, so it grants nothing new, but
 * an "update any setting by name" endpoint would be a needless liability when
 * seven named keys is all the page needs.
 */
class ModxmcpSettingsUpdateProcessor extends Processor
{
    public $languageTopics = ['modxmcp:default'];
    public $permission     = 'settings';

    /** short name => cast */
    private const KEYS = [
        'enabled'                => 'bool',
        'log_arguments'          => 'bool',
        'audit_retention_days'   => 'int',
        'discovery_cache_seconds' => 'int',
        'trusted_proxy_header'   => 'string',
        'read_class_allowlist'   => 'list',
        'write_class_allowlist'  => 'list',
    ];

    public function process()
    {
        $changed = [];

        // MODX 3's Processor has no hasProperty(), and getProperty() cannot
        // distinguish "absent" from "sent empty" -- which matters here, because
        // clearing an allowlist is a legitimate edit that must not be skipped.
        $submitted = $this->getProperties();

        foreach (self::KEYS as $short => $cast) {
            if (!array_key_exists($short, $submitted)) {
                continue;
            }

            $value = $this->normalise($this->getProperty($short), $cast);

            $key     = 'modxmcp.' . $short;
            $setting = $this->modx->getObject(modSystemSetting::class, ['key' => $key]);
            if (!$setting) {
                $setting = $this->modx->newObject(modSystemSetting::class);
                $setting->fromArray([
                    'key'       => $key,
                    'namespace' => 'modxmcp',
                    'area'      => 'modxmcp.main',
                    'xtype'     => $cast === 'bool' ? 'combo-boolean' : 'textfield',
                ], '', true, true);
            }

            if ((string) $setting->get('value') !== $value) {
                $setting->set('value', $value);
                $setting->save();
                $changed[] = $key;
            }
        }

        if ($changed !== []) {
            // System settings are read from the cached config, so a change is
            // invisible until this runs.
            $this->modx->cacheManager->refresh();
        }

        return $this->success('', ['changed' => $changed]);
    }

    /**
     * @param mixed $value
     */
    private function normalise($value, string $cast): string
    {
        switch ($cast) {
            case 'bool':
                // Explicit '1'/'0'. A PHP boolean persists as '' and then reads
                // as unset rather than as a deliberate choice.
                return (!empty($value) && $value !== 'false' && $value !== '0') ? '1' : '0';

            case 'int':
                return (string) max(0, (int) $value);

            case 'list':
                // Normalise separators so the stored value is predictable
                // however the administrator typed it.
                $parts = preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                return implode(', ', array_unique($parts));

            default:
                return trim((string) $value);
        }
    }
}

return 'ModxmcpSettingsUpdateProcessor';
