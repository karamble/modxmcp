<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Registry\Schema;

/**
 * Read rows of any permitted class.
 */
final class ObjectListTool extends AbstractTool
{
    use ObjectSupport;

    public function name(): string
    {
        return 'modxmcp_object_list';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'List objects of any class',
            'description' => 'Read rows of any xPDO class that generic access permits, including '
                . 'classes belonging to installed extras. Call modxmcp_schema_describe first to '
                . 'learn the fields. Use the dedicated resource and element tools instead where '
                . 'they apply: those write through MODX processors and this does not.',
            'inputSchema' => Schema::object([
                'class'   => Schema::string('Fully qualified class name.'),
                'filters' => Schema::map('Field/value pairs, e.g. {"active": 1}. An xPDO operator '
                    . 'may be appended to a field, e.g. {"name:LIKE": "%draft%"}.'),
                'sort'    => Schema::string('Field to sort by.'),
                'dir'     => Schema::enum('Sort direction.', ['ASC', 'DESC'], 'ASC'),
                'limit'   => Schema::integer('Maximum rows.', 25),
                'offset'  => Schema::integer('Rows to skip, for paging.', 0),
            ], ['class']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $class  = $this->guardedClass($modx, (string) $this->requireArg($arguments, 'class'), 'read');
        $limit  = max(1, min(200, (int) $this->arg($arguments, 'limit', 25)));
        $offset = max(0, (int) $this->arg($arguments, 'offset', 0));

        $filters  = $this->arg($arguments, 'filters', []);
        $criteria = $this->buildCriteria($modx, $class, is_array($filters) ? $filters : []);

        $query = $modx->newQuery($class);
        if ($criteria !== []) {
            $query->where($criteria);
        }

        $total = $modx->getCount($class, $query);

        if (($sort = $this->arg($arguments, 'sort')) !== null) {
            $known = array_keys($modx->getFieldMeta($class) ?: []);
            if (in_array((string) $sort, $known, true)) {
                $dir = strtoupper((string) $this->arg($arguments, 'dir', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
                $query->sortby((string) $sort, $dir);
            }
        }
        $query->limit($limit, $offset);

        $rows = [];
        foreach ($modx->getCollection($class, $query) as $object) {
            $rows[] = $this->redactRow($modx, $object->toArray());
        }

        return [
            'class'    => $class,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'returned' => count($rows),
            'objects'  => $rows,
        ];
    }
}
