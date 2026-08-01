<?php

namespace MODXMCP\Knowledge\Advisories;

use MODX\Revolution\modX;
use MODXMCP\Knowledge\Advisory;
use MODXMCP\Knowledge\AdvisoryInterface;
use MODXMCP\Knowledge\ExtraPresence;
use MODXMCP\Tools\CollectionsSupport;

/**
 * Children of a Collections container vanish unless show_in_tree=0.
 *
 * Emitted only when the site actually has containers. Collections being
 * installed is not the condition: a site with the extra and no containers cannot
 * trip this, and warning about it there is noise on the first call of every
 * session.
 *
 * The container ids are carried in the rule so a caller can test its own pending
 * `parent` against them rather than parse the sentence.
 */
final class CollectionsChildrenAdvisory implements AdvisoryInterface
{
    use CollectionsSupport;

    public function id(): string
    {
        return 'collections.children_hidden';
    }

    public function detect(modX $modx): ?Advisory
    {
        if (!$this->collectionsInstalled($modx)) {
            return null;
        }

        $containers = $this->collectionsContainers($modx);
        if ($containers === []) {
            return null;
        }

        $ids = array_map(static fn(array $c): int => $c['id'], $containers);

        return Advisory::blocker(
            $this->id(),
            sprintf(
                'This site has %d Collections container%s. Resources created under %s need '
                . 'show_in_tree=0 or they never appear in the listing.',
                count($containers),
                count($containers) === 1 ? '' : 's',
                count($containers) === 1 ? 'it' : 'them'
            ),
            [
                'container_count' => count($containers),
                'containers'      => $containers,
            ],
            [
                'when' => [
                    'tools'    => ['modxmcp_resource_create', 'modxmcp_resource_update'],
                    'argument' => 'parent',
                    'in'       => $ids,
                ],
                'require' => [
                    'show_in_tree' => false,
                    'menuindex'    => 'explicit, non-zero',
                ],
                'symptom' => 'the resource exists, is published and is reachable by URL, and is '
                    . 'absent from the Collections grid',
                'verify'  => 'modxmcp_collections_containers',
            ]
        )->withExtra('collections', (new ExtraPresence($modx))->version('Collections'));
    }
}
