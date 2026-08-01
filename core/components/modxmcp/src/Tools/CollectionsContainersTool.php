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
    use CollectionsSupport;
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
        // The listing, the predicate and the rule wording all live in
        // CollectionsSupport, so this tool and the resource warnings cannot
        // drift apart on what counts as a container.
        $containers = $this->collectionsContainers($modx, $this->arg($arguments, 'context'));

        return [
            'containers' => $containers,
            'count'      => count($containers),
            'rule'       => $this->collectionsChildRule(),
        ];
    }
}
