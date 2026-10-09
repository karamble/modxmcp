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
    // For attachedTvs(): a template's TV list is the only place the resource
    // tools' "attached" rule is visible before you trip over it.
    use TemplateVarSupport;

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
        $typeKey = strtolower(trim((string) $this->requireArg($arguments, 'type')));
        $type    = $this->elementType($typeKey);

        // What MODX's own Element/<Type>/Get declares. Before the lookup, so a
        // user without it cannot tell an existing name from a missing one.
        $this->requirePermission($modx, 'view a ' . $this->elementNoun($typeKey), [$type['permission']]);

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
            throw McpException::invalidParams("No {$typeKey} with {$which}");
        }

        // The object policy GetProcessor applies before returning the row: an
        // element in a category can refuse a user who holds view_<type>. Before
        // the body, the default properties or the bindings are assembled.
        $this->requirePolicy($modx, $element, 'view', sprintf(
            "%s '%s'",
            $this->elementNoun($typeKey),
            (string) $element->get($type['name'])
        ));

        $out = $this->normaliseElement($element->toArray(), $type, true);
        $out['type'] = $typeKey;

        // Bindings live in their own tables and decide whether the element does
        // anything at all, so they belong in a read of it. A plugin bound to no
        // events never runs; a TV attached to no template renders nowhere and
        // cannot be written by the resource tools.
        $elementId = (int) $element->get('id');
        if ($typeKey === 'plugin') {
            $out['events'] = array_column($this->pluginEvents($modx, $elementId), 'name');
        }
        if ($typeKey === 'tv') {
            $out['templates'] = $this->tvTemplates($modx, $elementId);
        }
        if ($typeKey === 'template') {
            // The only TVs the resource tools can write on a resource using this
            // template, which is not discoverable anywhere else in the surface.
            $out['template_vars'] = array_values(array_map(
                fn($tv) => [
                    'id'         => (int) $tv->get('id'),
                    'name'       => (string) $tv->get('name'),
                    'input_type' => (string) $tv->get('type'),
                ],
                $this->attachedTvs($modx, $elementId)
            ));
        }

        // Everything element_save replaces wholesale rather than merging. Its
        // description tells the caller to "send every option you want to keep",
        // which is only actionable if a read can show what is there now.
        $out += $this->replacedFields($element, $type, $typeKey);

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
