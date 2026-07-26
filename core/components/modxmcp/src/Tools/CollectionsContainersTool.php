<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Registry\Schema;

/**
 * List Collections containers.
 *
 * Collections changes what a correct child resource looks like: children must
 * carry show_in_tree=0 and a real menuindex or they vanish from the grid while
 * remaining published and reachable. Nothing in the resource table records that
 * a parent is a container, so a caller cannot tell from modxmcp_resource_list
 * alone. This makes the distinction visible before a resource is created under
 * the wrong parent.
 */
final class CollectionsContainersTool extends AbstractTool
{
    public function name(): string
    {
        return 'modxmcp_collections_containers';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'List Collections containers',
            'description' => 'List resources that are Collections containers. Check this before '
                . 'creating a resource: children of a container must be created with '
                . 'show_in_tree=false and an explicit menuindex, or they disappear from the '
                . 'Collections grid even though they exist and are published.',
            'inputSchema' => Schema::object([
                'context' => Schema::string('Context key. Omit for all contexts.'),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $query = $modx->newQuery(modResource::class);
        $query->where(['deleted' => 0, 'class_key:LIKE' => '%Collection%']);
        if (($context = $this->arg($arguments, 'context')) !== null) {
            $query->where(['context_key' => (string) $context]);
        }
        $query->sortby('id', 'ASC');

        $containers = [];
        foreach ($modx->getCollection(modResource::class, $query) as $resource) {
            $id = (int) $resource->get('id');
            $containers[] = [
                'id'          => $id,
                'pagetitle'   => $resource->get('pagetitle'),
                'uri'         => $resource->get('uri'),
                'class_key'   => $resource->get('class_key'),
                'context_key' => $resource->get('context_key'),
                'child_count' => $modx->getCount(modResource::class, ['parent' => $id, 'deleted' => 0]),
            ];
        }

        return [
            'containers' => $containers,
            'count'      => count($containers),
            'rule'       => 'When creating a resource whose parent is one of these, pass '
                . 'show_in_tree=false and an explicit menuindex to modxmcp_resource_create.',
        ];
    }
}
