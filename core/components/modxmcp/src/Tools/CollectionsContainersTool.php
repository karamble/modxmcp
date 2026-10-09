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
                'context_key' => Schema::string('Context key. Omit for all contexts.'),
                'context' => Schema::string('Older name for context_key, still accepted.'),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        // Containers are resources; listing them asks what Resource/GetList
        // asks.
        $this->requirePermission($modx, 'list resources', ['view']);

        // The listing, the predicate and the rule wording all live in
        // CollectionsSupport, so this tool and the resource warnings cannot
        // drift apart on what counts as a container.
        $hidden     = 0;
        $containers = $this->collectionsContainers(
            $modx,
            $this->renamedArg($arguments, 'context_key', 'context'),
            function (modResource $resource) use (&$hidden): bool {
                if ($resource->checkPolicy('list')) {
                    return true;
                }
                $hidden++;
                return false;
            }
        );

        $out = [
            'containers' => $containers,
            'count'      => count($containers),
            'rule'       => $this->collectionsChildRule(),
        ];
        if ($hidden > 0) {
            $out['hidden'] = $hidden;
            $out['note']   = sprintf(
                '%d container%s not shown: an access policy gives the MODX user this token acts as '
                . 'no list access to %s.',
                $hidden,
                $hidden === 1 ? ' is' : 's are',
                $hidden === 1 ? 'it' : 'them'
            );
        }

        return $out;
    }
}
