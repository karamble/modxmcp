<?php

namespace MODXMCP\Tools;

use MODX\Revolution\Transport\modTransportPackage;
use MODX\Revolution\modX;
use MODXMCP\Registry\Schema;
use xPDO\xPDO;

/**
 * What is installed, and what is behind.
 *
 * This reads the cache MODX itself writes rather than asking anyone.
 *
 * There is no "update available" column to read: modTransportPackage stores
 * signature, version, provider and install state and nothing about newer
 * releases. MODX answers the question in two places, and this uses both.
 *
 * The dashboard's Updates widget caches one combined entry under
 * mgr/providers/updates/modx-core holding whether MODX itself is behind and
 * which extras are, by name. That single entry is the primary source here: it is
 * exactly what an administrator sees on their dashboard, it costs one cache read,
 * and refreshing it is two HTTP calls rather than one per package.
 *
 * The Extras grid separately caches a per-package count under
 * mgr/providers/updates/<provider>/<signature>, written only when someone loads
 * that grid. Where present it is used for per-package detail.
 *
 * The distinction this tool exists to preserve is between "checked, and current"
 * and "never checked". They look identical in the Manager, and collapsing them
 * would report a site as up to date on the strength of no evidence. Both caches
 * expire, so "no entry" is the normal state on a site nobody has looked at
 * lately, and it is reported as unknown rather than as good news.
 */
final class UpdatesTool extends AbstractTool
{
    /** The dashboard widget's combined entry. */
    private const CORE_KEY = 'mgr/providers/updates/modx-core';

    /** The Extras grid's per-package entry. */
    private const PACKAGE_KEY = 'mgr/providers/updates/%d/%s';

    /** Matches modDashboardWidgetUpdates::$updatesCacheExpire. */
    private const REFRESH_TTL = 3600;

    public function name(): string
    {
        return 'modxmcp_updates';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Installed extras and available updates',
            'description' => 'Report the extras installed on this site with their versions, and '
                . 'whether MODX or any extra has an update available. Reads the same cached '
                . 'answer the Manager dashboard shows, so it makes no network request and '
                . 'cannot disagree with what an administrator sees. When nothing has checked '
                . 'recently the state is reported as "unknown" rather than as up to date. This '
                . 'tool cannot install or update anything: applying an update runs third-party '
                . 'code and changes database schema, which belongs in the Manager with a human '
                . 'watching.',
            'inputSchema' => Schema::object([
                'refresh' => Schema::boolean(
                    'Ask the MODX update service for current versions instead of reading the '
                    . 'cache, and store the answer where the dashboard will also use it. Two '
                    . 'outbound HTTPS requests to sentinel.modx.com. Off by default.', false),
                'installed_only' => Schema::boolean(
                    'Collapse superseded rows so each extra appears once at its newest version, '
                    . 'as the Extras grid does. MODX keeps every version ever installed.', true),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $refresh = !empty($arguments['refresh']);

        $combined = $refresh
            ? $this->refreshCombined($modx)
            : $modx->cacheManager->get(self::CORE_KEY, $this->cacheOptions($modx));

        $known = is_array($combined)
            && isset($combined['modx']['updateable'])
            && is_array($combined['extras'] ?? null);

        $outdatedNames = [];
        if ($known) {
            $outdatedNames = array_map(
                'strtolower',
                array_filter((array) ($combined['extras']['names'] ?? []), 'is_string')
            );
        }

        // Absent means collapse, which is what the schema advertises. Reading it
        // with empty() alone would invert the default.
        $collapse = !array_key_exists('installed_only', $arguments)
            || $arguments['installed_only'] === null
            || !empty($arguments['installed_only']);

        $packages = $this->packages($modx, $collapse);

        $counts = ['update_available' => 0, 'current' => 0, 'unknown' => 0];
        foreach ($packages as $i => $package) {
            $state = $this->stateFor($modx, $package, $known, $outdatedNames);
            $packages[$i]['update_state'] = $state;
            $counts[$state]++;
        }

        $result = [
            'modx_version'   => $modx->version['full_version'] ?? 'unknown',
            'modx_update'    => $known
                ? (!empty($combined['modx']['updateable']) ? 'update_available' : 'current')
                : 'unknown',
            'checked'        => $known,
            'source'         => $refresh ? 'sentinel.modx.com' : 'modx dashboard cache',
            'counts'         => $counts,
            'packages'       => $packages,
        ];

        // Scoped to what is actually unknown. The two caches expire separately,
        // so the dashboard entry can be stale while per-package entries from the
        // Extras grid survive; claiming nothing is known when 40 packages have an
        // answer would be the same overclaim in the other direction.
        if ($counts['unknown'] > 0 || !$known) {
            $unknownNote = $counts['unknown'] > 0
                ? sprintf(
                    '%d of %d packages have no cached update information and are reported as '
                    . 'unknown rather than as up to date. ',
                    $counts['unknown'],
                    count($packages)
                )
                : '';

            $coreNote = $known
                ? ''
                : 'Whether MODX itself is behind is also unknown. ';

            $result['note'] = $unknownNote . $coreNote
                . 'MODX caches this for an hour when the Manager dashboard is opened. Call this '
                . 'tool with refresh=true to ask now, or open the dashboard.'
                . ((bool) $modx->getOption('auto_check_pkg_updates', null, false)
                    ? ''
                    : ' The system setting auto_check_pkg_updates is off, which also stops the '
                    . 'Extras grid recording per-package state.');
        }

        return $result;
    }

    /**
     * Installed packages, optionally collapsed to one row per extra.
     *
     * MODX never removes superseded rows: this site carries 66 transport
     * packages for far fewer distinct extras, with `installed` set on all of
     * them. The Extras grid shows the newest of each, which is what a caller
     * asking "what is installed" means.
     *
     * @return array<int,array<string,mixed>>
     */
    private function packages(modX $modx, bool $collapse): array
    {
        $rows = [];
        foreach ($modx->getIterator(modTransportPackage::class) as $package) {
            /** @var modTransportPackage $package */
            if ($package->get('installed') === null) {
                continue;
            }

            $name = (string) $package->get('package_name');
            $row  = [
                'name'      => $name,
                'signature' => (string) $package->get('signature'),
                'version'   => $this->versionOf($package),
                'installed' => $package->get('installed'),
                'provider'  => (int) $package->get('provider'),
                'disabled'  => (bool) $package->get('disabled'),
                '_sort'     => [
                    (int) $package->get('version_major'),
                    (int) $package->get('version_minor'),
                    (int) $package->get('version_patch'),
                    (int) $package->get('release_index'),
                ],
            ];

            $key = $collapse ? strtolower($name) : (string) $package->get('signature');
            if (!isset($rows[$key]) || $row['_sort'] > $rows[$key]['_sort']) {
                $rows[$key] = $row;
            }
        }

        $rows = array_values($rows);
        usort($rows, fn($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
        foreach ($rows as $i => $row) {
            unset($rows[$i]['_sort']);
        }

        return $rows;
    }

    private function versionOf(modTransportPackage $package): string
    {
        $version = implode('.', [
            (int) $package->get('version_major'),
            (int) $package->get('version_minor'),
            (int) $package->get('version_patch'),
        ]);
        $release = trim((string) $package->get('release'));

        return $release === '' ? $version : $version . '-' . $release;
    }

    /**
     * Resolve one package's state, preferring per-package detail when it exists.
     *
     * @param array<string,mixed> $package
     * @param string[]            $outdatedNames lower-cased
     */
    private function stateFor(modX $modx, array $package, bool $known, array $outdatedNames): string
    {
        $providerId = (int) $package['provider'];
        if ($providerId > 0) {
            $cached = $modx->cacheManager->get(
                sprintf(self::PACKAGE_KEY, $providerId, (string) $package['signature']),
                $this->cacheOptions($modx)
            );
            if (is_array($cached) && array_key_exists('count', $cached)) {
                return ((int) $cached['count']) >= 1 ? 'update_available' : 'current';
            }
        }

        if (!$known) {
            return 'unknown';
        }

        return in_array(strtolower((string) $package['name']), $outdatedNames, true)
            ? 'update_available'
            : 'current';
    }

    /**
     * Ask the update service and store the answer where the dashboard reads it.
     *
     * Deliberately the same two processor calls, the same cache key and the same
     * TTL as modDashboardWidgetUpdates, so a refresh here is indistinguishable
     * from someone opening the dashboard.
     *
     * @return array<string,mixed>|null
     */
    private function refreshCombined(modX $modx): ?array
    {
        $data = ['modx' => [], 'extras' => []];

        // modTransportProvider reads $_SESSION directly and warns into the MODX
        // log on a session-free request, which every request here is. Seeding it
        // costs nothing and keeps the log readable.
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        try {
            // Through runProcessor rather than by instantiating the processor as
            // the dashboard widget does. The widget runs in the Manager, where
            // the error service is already loaded; here it is not, and
            // Processor::success() would fatal on a null $modx->error.
            $core   = $this->softwareUpdate($modx, 'modx');
            $extras = $this->softwareUpdate($modx, 'extras');

            if ($core !== null) {
                $data['modx'] = $core;
            }
            if ($extras !== null) {
                $data['extras'] = $extras;
            }
        } catch (\Throwable $e) {
            // Unreachable is not a failure of this call, it is one more thing
            // that is unknown. Returning null lands in the same branch as an
            // empty cache.
            $modx->log(modX::LOG_LEVEL_WARN, 'modxmcp: update check failed: ' . $e->getMessage());
            return null;
        }

        if (!isset($data['modx']['updateable'])) {
            return null;
        }

        $payload = $data;
        $modx->cacheManager->set(self::CORE_KEY, $payload, self::REFRESH_TTL, $this->cacheOptions($modx));

        return $payload;
    }

    /**
     * One call to the update service.
     *
     * @return array<string,mixed>|null
     */
    private function softwareUpdate(modX $modx, string $softwareType): ?array
    {
        $response = $modx->runProcessor(
            'SoftwareUpdate/GetList',
            $softwareType === 'modx' ? [] : ['softwareType' => $softwareType]
        );
        if (!$response || $response->isError()) {
            return null;
        }

        $object = $response->getObject();

        return (is_array($object) && array_key_exists('updateable', $object)) ? $object : null;
    }

    /**
     * The partition and handler MODX uses for this data.
     *
     * @return array<string,mixed>
     */
    private function cacheOptions(modX $modx): array
    {
        return [
            xPDO::OPT_CACHE_KEY => $modx->cacheManager->getOption(
                'cache_packages_key',
                null,
                'packages'
            ),
            xPDO::OPT_CACHE_HANDLER => $modx->cacheManager->getOption(
                'cache_packages_handler',
                null,
                $modx->cacheManager->getOption(xPDO::OPT_CACHE_HANDLER)
            ),
        ];
    }
}
