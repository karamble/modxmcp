<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Registry\Schema;

/**
 * List elements of one type.
 */
final class ElementListTool extends AbstractTool
{
    use ElementSupport;

    public function name(): string
    {
        return 'modxmcp_element_list';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'List elements',
            'description' => 'List chunks, snippets, templates, template variables or plugins. '
                . 'Returns names and ids without bodies, so it is cheap to call. Use '
                . 'modxmcp_element_get for the body of one element.',
            'inputSchema' => Schema::object([
                'type'   => Schema::enum('Element type.', $this->elementTypeKeys()),
                'search' => Schema::string('Match against name and description.'),
                'limit'  => Schema::integer('Maximum rows.', 100),
                'offset' => Schema::integer('Rows to skip, for paging.', 0),
            ], ['type']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $typeKey = strtolower(trim((string) $this->requireArg($arguments, 'type')));
        $type    = $this->elementType($typeKey);

        // What MODX's own Element/<Type>/GetList declares.
        $this->requirePermission($modx, 'list ' . $this->elementNoun($typeKey, true), [$type['permission']]);

        $limit  = max(1, min(500, (int) $this->arg($arguments, 'limit', 100)));
        $offset = max(0, (int) $this->arg($arguments, 'offset', 0));

        $query = $modx->newQuery($type['class']);
        if (($search = $this->arg($arguments, 'search')) !== null) {
            $query->where([
                $type['name'] . ':LIKE'  => '%' . $search . '%',
                'OR:description:LIKE'    => '%' . $search . '%',
            ]);
        }

        $total = $modx->getCount($type['class'], $query);

        $query->sortby($type['name'], 'ASC');
        $query->limit($limit, $offset);

        // xPDO has already dropped what this user may not load under a
        // category policy; the list policy is GetListProcessor's, per row.
        $rows = [];
        foreach ($modx->getCollection($type['class'], $query) as $element) {
            if (!$element->checkPolicy('list')) {
                continue;
            }
            $rows[] = $this->normaliseElement($element->toArray(), $type, false);
        }

        return [
            'type'     => $typeKey,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'returned' => count($rows),
            'elements' => $rows,
        ] + $this->hiddenRows($limit, $offset, $total, count($rows), $this->elementNoun($typeKey, true));
    }
}
