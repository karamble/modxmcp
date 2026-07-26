<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Registry\Schema;

/**
 * Browse and search resources.
 */
final class ResourceListTool extends AbstractTool
{
    use ResourceSupport;

    public function name(): string
    {
        return 'modxmcp_resource_list';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'List resources',
            'description' => 'List or search resources (pages). Filter by parent, context, '
                . 'published state, or a search term matched against pagetitle and alias. '
                . 'Returns a summary of each resource; use modxmcp_resource_get for the full '
                . 'content and template variables of one.',
            'inputSchema' => Schema::object([
                'parent'      => Schema::integer('Only children of this resource id. Omit for any parent; use 0 for top level.'),
                'context'     => Schema::string('Context key, e.g. "web". Omit for all contexts.'),
                'search'      => Schema::string('Match against pagetitle, alias and longtitle.'),
                'published'   => Schema::boolean('Filter by published state. Omit for both.'),
                'include_deleted' => Schema::boolean('Include resources in the recycle bin.', false),
                'limit'       => Schema::integer('Maximum rows.', 25),
                'offset'      => Schema::integer('Rows to skip, for paging.', 0),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $limit  = max(1, min(200, (int) $this->arg($arguments, 'limit', 25)));
        $offset = max(0, (int) $this->arg($arguments, 'offset', 0));

        $query = $modx->newQuery(modResource::class);

        if (empty($arguments['include_deleted'])) {
            $query->where(['deleted' => 0]);
        }
        if (($parent = $this->arg($arguments, 'parent')) !== null) {
            $query->where(['parent' => (int) $parent]);
        }
        if (($context = $this->arg($arguments, 'context')) !== null) {
            $query->where(['context_key' => (string) $context]);
        }
        if (array_key_exists('published', $arguments) && $arguments['published'] !== null) {
            $query->where(['published' => !empty($arguments['published']) ? 1 : 0]);
        }
        if (($search = $this->arg($arguments, 'search')) !== null) {
            $query->where([
                'pagetitle:LIKE'    => '%' . $search . '%',
                'OR:alias:LIKE'     => '%' . $search . '%',
                'OR:longtitle:LIKE' => '%' . $search . '%',
            ]);
        }

        $total = $modx->getCount(modResource::class, $query);

        $query->sortby('menuindex', 'ASC');
        $query->sortby('id', 'ASC');
        $query->limit($limit, $offset);

        $rows = [];
        foreach ($modx->getCollection(modResource::class, $query) as $resource) {
            $rows[] = $this->pick($resource->toArray(), $this->resourceFields());
        }

        return [
            'total'     => $total,
            'limit'     => $limit,
            'offset'    => $offset,
            'returned'  => count($rows),
            'resources' => $rows,
        ];
    }
}
