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
 * It also drops modxmcp's own two caches explicitly, by name, and this comment
 * used to claim they were "invisible to cacheManager->refresh()". They are not.
 * Both live under keys in MODX's `default` partition, and refresh() called with
 * no providers builds a list that includes 'default' => [], so a full clear
 * already took them. Measured on 3.2.4-pl: after a scan both files are present;
 * refresh(['resource' => []]) leaves them; refresh() removes them, with
 * forget() never called.
 *
 * So the forget() calls below are explicitness rather than repair. They say
 * which caches this tool means to drop instead of relying on where a cache key
 * happens to sit, and the result names them, which is the part a caller can
 * act on. Thanks to AmaZili for measuring the original claim and finding it
 * wrong -- it had been asserted from reading the code and never tested against
 * a running site.
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
                    'Cache partitions to clear. Omit to clear everything, which is usually what '
                    . 'you want. modxmcp\'s own discovery and advisory caches sit in the '
                    . 'default partition, so naming partitions without it keeps them.',
                    // Every partition modCacheManager::refresh() knows, taken
                    // from the list it builds for itself when given none. The
                    // set used to be a subset of five, which cost nothing while
                    // nothing validated it; now that the enum is enforced, an
                    // omission here is a partition a caller can no longer
                    // clear, so the two lists have to agree.
                    Schema::enum('Partition', [
                        'auto_publish', 'system_settings', 'context_settings', 'namespaces',
                        'db', 'media_sources', 'lexicon_topics', 'scripts', 'default',
                        'resource', 'menu',
                    ])
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
