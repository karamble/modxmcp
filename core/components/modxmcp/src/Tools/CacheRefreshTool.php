<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Registry\Schema;

/**
 * Clear MODX caches.
 *
 * Rarely needed after a normal write: the processors already invalidate what
 * they touch, which is one of the reasons everything here goes through them.
 * It matters after changes made outside the processor path, such as editing a
 * static element's file on disk or altering settings directly.
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
                . 'outside that path, or when the site is serving stale output.',
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

        return [
            'refreshed'  => true,
            'partitions' => $providers === [] ? 'all' : array_keys($providers),
        ];
    }
}
