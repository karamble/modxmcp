<?php

namespace MODXMCP\Knowledge\Advisories;

use MODX\Revolution\modX;
use MODXMCP\Knowledge\Advisory;
use MODXMCP\Knowledge\AdvisoryInterface;

/**
 * Which writes need a cache refresh, and which do not.
 *
 * Deliberately narrower than the blanket "clear the cache after every element
 * change" advice that circulates. That rule is an artefact of tools which write
 * through newObject()+save(); every write here goes through a processor, and
 * Element/*::afterSave() runs a full cacheManager->refresh() unless it is told
 * not to. Repeating the blanket rule would tell a caller to do unnecessary work
 * and would imply this extra is as leaky as the tools it replaces.
 *
 * The genuinely useful half is which resource fields affect routing. A content
 * change is visible immediately; a change to alias or parent moves the URL and
 * needs the alias map rebuilt.
 */
final class ElementCacheAdvisory implements AdvisoryInterface
{
    public function id(): string
    {
        return 'cache.invalidation';
    }

    public function detect(modX $modx): ?Advisory
    {
        // On a site with caching off the advice is pure noise.
        if ($modx->getOption('cache_disabled', null, false)) {
            return null;
        }

        return Advisory::note(
            $this->id(),
            'Writes through modxmcp invalidate the cache themselves. Changes made outside it, '
            . 'and static elements whose body lives on disk, do not.',
            [
                'cache_disabled' => false,
                'cache_resource' => (bool) $modx->getOption('cache_resource', null, true),
            ],
            [
                'already_invalidated_by' => [
                    'modxmcp_resource_create', 'modxmcp_resource_update',
                    'modxmcp_element_save', 'modxmcp_element_delete',
                ],
                'needs_modxmcp_cache_refresh' => [
                    'editing a static element\'s file on disk',
                    'modxmcp_object_save against a class the front end reads',
                    'any change made outside this server',
                ],
                'resource_fields_affecting_routing' => [
                    'published', 'pub_date', 'unpub_date', 'parent', 'template', 'alias', 'menuindex',
                ],
                'resource_fields_not_affecting_routing' => [
                    'content', 'pagetitle', 'longtitle', 'introtext', 'description', 'tvs',
                ],
            ]
        );
    }
}
