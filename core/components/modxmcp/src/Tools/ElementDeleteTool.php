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
    use ReferenceSupport;

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
                . 'from the Manager. MODX refuses to remove a template any resource still uses, '
                . 'and a template variable any template still declares: reassign the resources '
                . 'with modxmcp_resource_update, or detach the variable with '
                . 'modxmcp_element_save, first. A chunk, snippet or template variable that '
                . 'something still calls is removed anyway, but the result names the callers in '
                . 'warnings, and removed.content carries the body so it can be recreated.',
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

        $elementId = (int) $element->get('id');
        $row       = $element->toArray();
        $removed   = $this->normaliseElement($row, $type, true);

        // Everything needed to rebuild the element, gathered before the
        // processor runs and takes it away.
        //
        // The class docblock promises the removal is "at least recoverable from
        // the transcript", and the body alone does not deliver that: a snippet
        // or plugin without its default properties, or a TV without its caption,
        // option list and input configuration, comes back as something other
        // than what was deleted.
        //
        // A plugin's event bindings live in their own table and are gone the
        // moment Remove succeeds, so they are read here rather than after.
        // A TV's template attachments deliberately are not: MODX refuses to
        // remove a TV that any template still declares, so by the time this
        // line is reached the list is necessarily empty.
        $removed += $this->replacedFields($element, $type, $typeKey);
        if ($typeKey === 'plugin') {
            $removed['events'] = array_column($this->pluginEvents($modx, $elementId), 'name');
        }

        $warnings = [];

        // MODX refuses to remove a template any resource still uses, and its
        // own message names neither how many nor which. Refusing first turns
        // that into something actionable, and rescues a count that could never
        // be delivered: this was a warning appended to $out, which is built
        // after the processor, and the processor throws.
        if ($typeKey === 'template') {
            $inUse = $modx->getCount(\MODX\Revolution\modResource::class, [
                'template' => $elementId,
                'deleted'  => 0,
            ]);
            if ($inUse > 0) {
                throw McpException::invalidParams(sprintf(
                    '%d resource(s) still use this template, and MODX will not remove a template '
                    . 'that is in use. Move them to another template with modxmcp_resource_update '
                    . 'first. Nothing was deleted.',
                    $inUse
                ));
            }
        }

        // Gathered before the removal, because afterwards the name is all that
        // is left to search for and the caller has already lost the element.
        $references = $this->referencesTo(
            $modx,
            (string) $element->get($type['name']),
            $this->tagPatternsFor($typeKey, (string) $element->get($type['name'])),
            $type['class'],
            $elementId
        );

        $this->runProcessor($modx, $type['processor'] . '/Remove', ['id' => $elementId]);

        if ($references['total'] > 0) {
            $warnings[] = $this->describeReferences(
                $references,
                $typeKey,
                (string) $removed['name']
            );
        }

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
