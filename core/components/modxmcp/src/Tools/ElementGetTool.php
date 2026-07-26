<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Fetch one element including its body.
 */
final class ElementGetTool extends AbstractTool
{
    use ElementSupport;

    public function name(): string
    {
        return 'modxmcp_element_get';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Get an element',
            'description' => 'Fetch one chunk, snippet, template, template variable or plugin by '
                . 'id or name, including its body. Read this before saving over an element: '
                . 'modxmcp_element_save replaces the body outright.',
            'inputSchema' => Schema::object([
                'type' => Schema::enum('Element type.', $this->elementTypeKeys()),
                'id'   => Schema::integer('Element id. Either this or name is required.'),
                'name' => Schema::string('Element name, if you do not have the id.'),
            ], ['type']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $type = $this->elementType((string) $this->requireArg($arguments, 'type'));

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
            $which = $id !== null ? "id {$id}" : "name '{$name}'";
            throw McpException::invalidParams("No {$arguments['type']} with {$which}");
        }

        $out = $this->normaliseElement($element->toArray(), $type, true);
        $out['type'] = strtolower((string) $arguments['type']);

        // A static element's body lives on disk; editing the database copy would
        // be silently discarded on the next render.
        if (!empty($out['static'])) {
            $out['warnings'] = [
                'This element is static: its body is read from ' . ($out['static_file'] ?: 'a file')
                . ' on disk, and changes saved to the database will not take effect.',
            ];
        }

        return $out;
    }
}
