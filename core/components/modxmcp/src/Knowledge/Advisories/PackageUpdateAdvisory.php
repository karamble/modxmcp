<?php

namespace MODXMCP\Knowledge\Advisories;

use MODX\Revolution\modX;
use MODXMCP\Knowledge\Advisory;
use MODXMCP\Knowledge\AdvisoryInterface;
use xPDO\xPDO;

/**
 * Whether anything is behind, from MODX's own cache.
 *
 * Cache-only by construction. site_info is the first call of every session, and
 * an outbound request there would put a network timeout in front of every
 * connection. modxmcp_updates does the asking, with refresh=true, and this reads
 * whatever the last ask left behind.
 *
 * The three-state rule applies here too: no cached entry means unknown, and
 * unknown is reported rather than quietly treated as up to date.
 */
final class PackageUpdateAdvisory implements AdvisoryInterface
{
    private const CORE_KEY = 'mgr/providers/updates/modx-core';

    public function id(): string
    {
        return 'packages.updates_available';
    }

    public function detect(modX $modx): ?Advisory
    {
        $options = [
            xPDO::OPT_CACHE_KEY => $modx->cacheManager->getOption('cache_packages_key', null, 'packages'),
            xPDO::OPT_CACHE_HANDLER => $modx->cacheManager->getOption(
                'cache_packages_handler',
                null,
                $modx->cacheManager->getOption(xPDO::OPT_CACHE_HANDLER)
            ),
        ];

        $cached = $modx->cacheManager->get(self::CORE_KEY, $options);
        if (!is_array($cached) || !isset($cached['modx']['updateable'])) {
            // Silent rather than a note. An unchecked site is the normal state,
            // and modxmcp_updates says so properly when actually asked.
            return null;
        }

        $modxBehind = !empty($cached['modx']['updateable']);
        $names      = array_values(array_filter((array) ($cached['extras']['names'] ?? []), 'is_string'));

        if (!$modxBehind && $names === []) {
            return null;
        }

        $parts = [];
        if ($modxBehind) {
            $parts[] = 'MODX itself';
        }
        if ($names !== []) {
            $parts[] = count($names) . ' extra' . (count($names) === 1 ? '' : 's')
                . ' (' . implode(', ', $names) . ')';
        }

        return Advisory::note(
            $this->id(),
            implode(' and ', $parts) . ' ' . (count($parts) === 1 && !$modxBehind && count($names) === 1
                ? 'has'
                : 'have') . ' an update available.',
            [
                'modx_updateable'  => $modxBehind,
                'installed' => $this->modxVersion($modx),
                'outdated_extras'  => $names,
                'source'           => 'the cache the Manager dashboard writes',
            ],
            [
                'detail' => 'modxmcp_updates',
                'apply'  => 'Package Management in the Manager. modxmcp deliberately cannot '
                    . 'install or update: doing so runs third-party code and changes database '
                    . 'schema inside a web request.',
            ]
        );
    }

    /**
     * The MODX version, read the way that actually works.
     *
     * modX::$version is populated lazily by getVersionData(); until something
     * calls it the property is NULL. On a site with several extras it is usually
     * already filled by the time a tool runs, which is why this read looked fine
     * for months. On a leaner install nothing had called it and the orientation
     * call reported the version as "unknown".
     */
    private function modxVersion(modX $modx): string
    {
        $data = $modx->getVersionData();

        return (string) ($data['full_version'] ?? 'unknown');
    }
}
