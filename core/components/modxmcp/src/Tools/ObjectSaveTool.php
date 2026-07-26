<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Discovery\ClassGuard;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Create or update a row of any permitted class.
 *
 * Unlike the resource and element tools, this cannot go through a MODX
 * processor: an arbitrary extra class has none. That means no lifecycle events
 * fire and no extra reacts to the change, which is exactly the failure mode this
 * project exists to avoid. The tool therefore says so in its own output rather
 * than letting a caller assume the two paths are equivalent.
 */
final class ObjectSaveTool extends AbstractTool
{
    use ObjectSupport;

    public function requiredScope(): string
    {
        return 'write:objects';
    }

    public function name(): string
    {
        return 'modxmcp_object_save';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Create or update an object',
            'description' => 'Create a row, or update one by primary key, for any xPDO class that '
                . 'generic write access permits. This writes directly and fires no MODX lifecycle '
                . 'events, so extras will not react to the change. Never use it for resources or '
                . 'elements: the dedicated tools exist because those must go through processors. '
                . 'Call modxmcp_schema_describe first to learn the fields.',
            'inputSchema' => Schema::object([
                'class'  => Schema::string('Fully qualified class name.'),
                'pk'     => Schema::string('Primary key of the row to update. Omit to create.'),
                'values' => Schema::map('Field/value pairs to write.'),
            ], ['class', 'values']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $class  = $this->guardedClass($modx, (string) $this->requireArg($arguments, 'class'), 'write');
        $values = $this->requireArg($arguments, 'values');
        if (!is_array($values) || $values === []) {
            throw McpException::invalidParams('values must be a non-empty object of field/value pairs');
        }

        $known = array_keys($modx->getFieldMeta($class) ?: []);
        foreach (array_keys($values) as $field) {
            if (!in_array((string) $field, $known, true)) {
                throw McpException::invalidParams(
                    "'{$field}' is not a field of {$class}. Use modxmcp_schema_describe to see its fields.");
            }
        }

        // Refuse to write a redaction marker back. Without this, a read that
        // masked a secret followed by an edited write-back would overwrite the
        // real value with the placeholder.
        foreach ($values as $field => $value) {
            if (is_string($value) && $value === ClassGuard::REDACTED) {
                throw McpException::invalidParams(
                    "Field '{$field}' still holds the redaction placeholder. Remove it from the "
                    . 'write rather than saving it over the stored value.');
            }
        }

        $pk = $this->arg($arguments, 'pk');

        if ($pk !== null) {
            $object = $modx->getObject($class, $pk);
            if (!$object) {
                throw McpException::invalidParams("No {$class} with primary key '{$pk}'");
            }
            $created = false;
        } else {
            $object  = $modx->newObject($class);
            $created = true;
        }

        $object->fromArray($values, '', true);

        if (!$object->save()) {
            throw McpException::internal("Could not save {$class}");
        }

        return [
            'class'    => $class,
            'created'  => $created,
            'pk'       => $object->getPrimaryKey(),
            'object'   => $this->redactRow($modx, $object->toArray()),
            'warnings' => [
                'Written directly through xPDO. No MODX lifecycle events fired, so extras that '
                . 'hook saves for this data have not run.',
            ],
        ];
    }
}
