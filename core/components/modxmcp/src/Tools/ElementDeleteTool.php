<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Remove an element.
 *
 * Unlike resources, element removal is permanent: there is no recycle bin for
 * elements. The tool therefore returns the body it removed, so the content is
 * at least recoverable from the transcript if the call was a mistake.
 */
final class ElementDeleteTool extends AbstractTool
{
    use ElementSupport;

    public function requiredScope(): string
    {
        return 'write:elements';
    }

    public function name(): string
    {
        return 'modxmcp_element_delete';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Delete an element',
            'description' => 'Permanently remove a chunk, snippet, template, template variable '
                . 'or plugin. There is no recycle bin for elements, so this cannot be undone '
                . 'from the Manager. Deleting a template that resources still use will leave '
                . 'those resources without one.',
            'inputSchema' => Schema::object([
                'type' => Schema::enum('Element type.', $this->elementTypeKeys()),
                'id'   => Schema::integer('Element id. Either this or name is required.'),
                'name' => Schema::string('Element name, if you do not have the id.'),
            ], ['type']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $typeKey = strtolower((string) $this->requireArg($arguments, 'type'));
        $type    = $this->elementType($typeKey);

        $id   = $this->arg($arguments, 'id');
        $name = $this->arg($arguments, 'name');

        if ($id !== null) {
            $element = $modx->getObject($type['class'], (int) $id);
        } elseif ($name !== null) {
            $element = $modx->getObject($type['class'], [$type['name'] => (string) $name]);
        } else {
            throw McpException::invalidParams('Provide either id or name');
        }

        if (!$element) {
            throw McpException::invalidParams("No {$typeKey} matching the given id or name");
        }

        $row     = $element->toArray();
        $removed = $this->normaliseElement($row, $type, true);

        $warnings = [];
        if ($typeKey === 'template') {
            $inUse = $modx->getCount(\MODX\Revolution\modResource::class, [
                'template' => (int) $element->get('id'),
                'deleted'  => 0,
            ]);
            if ($inUse > 0) {
                $warnings[] = "{$inUse} resource(s) still use this template and will be left "
                    . 'without a valid one.';
            }
        }

        $this->runProcessor($modx, $type['processor'] . '/Remove', ['id' => (int) $element->get('id')]);

        $out = [
            'type'        => $typeKey,
            'deleted'     => true,
            'recoverable' => false,
            'removed'     => $removed,
        ];

        // Present only when there is something to say. The family was split on
        // this: element_save already guarded, element_delete did not.
        if ($warnings !== []) {
            $out['warnings'] = $warnings;
        }

        return $out;
    }
}
