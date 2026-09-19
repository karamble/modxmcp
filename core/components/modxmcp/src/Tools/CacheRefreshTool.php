<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Discovery\PackageScanner;
use MODXMCP\Knowledge\AdvisoryCollector;
use MODXMCP\Registry\Schema;

/**
 * Clear MODX caches.
 *
 * Rarely needed after a normal write: the processors already invalidate what
 * they touch, which is one of the reasons everything here goes through them.
 * It matters after changes made outside the processor path, such as editing a
 * static element's file on disk or altering settings directly.
 *
 * It also drops modxmcp's own two caches, which are not MODX partitions and so
 * are invisible to cacheManager->refresh(). Both PackageScanner and
 * AdvisoryCollector had a forget() that nothing called, which left the 300s TTL
 * as the only thing that ever expired a stale class list: you could install an
 * extra, call the tool whose whole job is clearing caches, and still not see
 * the extra in schema_list.
 */
final class CacheRefreshTool extends AbstractTool
{
    public function requiredScope(): string
    {
        return 'write:content';
    }

    public function name(): string
    {
        return 'modxmcp_cache_refresh';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Refresh the MODX cache',
            'description' => 'Clear MODX caches. You do not normally need this after creating or '
                . 'updating content through the other tools, because they write through MODX '
                . 'processors which invalidate the cache themselves. Use it after changes made '
                . 'outside that path, or when the site is serving stale output. Called with no '
                . 'partitions it also drops modxmcp\'s own discovery and advisory caches, which '
                . 'is what to do when an extra has just been installed or removed and '
                . 'modxmcp_schema_list or modxmcp_site_info still describes the site as it was.',
            'inputSchema' => Schema::object([
                'partitions' => Schema::arrayOf(
                    'Cache partitions to clear. Omit to clear everything.',
                    Schema::enum('Partition', ['db', 'context_settings', 'resource', 'system_settings', 'scripts'])
                ),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $requested = $this->arg($arguments, 'partitions');

        $providers = [];
        if (is_array($requested) && $requested !== []) {
            foreach ($requested as $partition) {
                $providers[(string) $partition] = [];
            }
        }

        $modx->cacheManager->refresh($providers);

        // Only on a full clear. The partitions above are MODX's own names and
        // none of them refers to this extra, so a caller narrowing to
        // 'resource' has asked for something specific and should not have the
        // class map dropped underneath them as a side effect.
        $own = [];
        if ($providers === []) {
            (new PackageScanner($modx))->forget();
            (new AdvisoryCollector($modx))->forget();
            $own = ['discovery', 'advisories'];
        }

        return [
            'refreshed'      => true,
            'partitions'     => $providers === [] ? 'all' : array_keys($providers),
            'modxmcp_caches' => $own !== []
                ? $own
                : 'kept; omit partitions to drop them too',
        ];
    }
}
