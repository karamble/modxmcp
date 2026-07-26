<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Remove a row of any permitted class.
 *
 * Deletes one row addressed by primary key, and nothing else. There is no
 * filter-based bulk delete here on purpose: a wrong filter on a generic tool
 * with no lifecycle events and no recycle bin destroys data with no way back,
 * and the convenience is not worth that.
 */
final class ObjectDeleteTool extends AbstractTool
{
    use ObjectSupport;

    public function requiredScope(): string
    {
        return 'write:objects';
    }

    public function name(): string
    {
        return 'modxmcp_object_delete';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Delete an object',
            'description' => 'Permanently delete one row, addressed by primary key, for any xPDO '
                . 'class that generic write access permits. There is no recycle bin and no '
                . 'lifecycle event, so this cannot be undone and extras will not react. Only one '
                . 'row per call; there is no bulk delete.',
            'inputSchema' => Schema::object([
                'class'   => Schema::string('Fully qualified class name.'),
                'pk'      => Schema::string('Primary key of the row to delete.'),
                'confirm' => Schema::boolean('Must be true. Present so a delete cannot happen '
                    . 'through a partially constructed call.', false),
            ], ['class', 'pk', 'confirm']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $class = $this->guardedClass($modx, (string) $this->requireArg($arguments, 'class'), 'write');
        $pk    = (string) $this->requireArg($arguments, 'pk');

        if (empty($arguments['confirm'])) {
            throw McpException::invalidParams(
                'Refusing to delete without confirm=true. This is permanent and has no recycle bin.');
        }

        $object = $modx->getObject($class, $pk);
        if (!$object) {
            throw McpException::invalidParams("No {$class} with primary key '{$pk}'");
        }

        // Returned so the deleted content survives in the transcript, which is
        // the only recovery path this operation has.
        $removed = $this->redactRow($modx, $object->toArray());

        if (!$object->remove()) {
            throw McpException::internal("Could not delete {$class} '{$pk}'");
        }

        return [
            'class'       => $class,
            'pk'          => $pk,
            'deleted'     => true,
            'recoverable' => false,
            'removed'     => $removed,
            'warnings'    => [
                'Deleted directly through xPDO. No MODX lifecycle events fired, so extras that '
                . 'hook deletes for this data have not run.',
            ],
        ];
    }
}
