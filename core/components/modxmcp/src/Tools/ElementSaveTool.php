<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Registry\Schema;

/**
 * Create or update an element through its MODX processor.
 *
 * Create and update share a tool because the caller's intent is the same
 * ("this element should have this body") and the distinction is mechanical:
 * whether a matching element already exists.
 */
final class ElementSaveTool extends AbstractTool
{
    use ElementSupport;

    public function requiredScope(): string
    {
        return 'write:elements';
    }

    public function name(): string
    {
        return 'modxmcp_element_save';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Create or update an element',
            'description' => 'Create an element, or replace an existing one. Pass id to update a '
                . 'known element; otherwise the name decides: an existing element with that name '
                . 'is updated, and a new one is created if there is none. The body is replaced '
                . 'outright, so read the current one with modxmcp_element_get first if you mean '
                . 'to edit rather than overwrite.',
            'inputSchema' => Schema::object([
                'type'        => Schema::enum('Element type.', $this->elementTypeKeys()),
                'name'        => Schema::string('Element name. Required when creating.'),
                'id'          => Schema::integer('Element id, to update a specific element.'),
                'content'     => Schema::string('The body. For a snippet or plugin this is PHP '
                    . 'WITHOUT the opening <?php tag, matching how MODX stores it.'),
                'description' => Schema::string('Description.'),
                'category'    => Schema::integer('Category id. 0 for uncategorised.', 0),
            ], ['type']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $typeKey = strtolower((string) $this->requireArg($arguments, 'type'));
        $type    = $this->elementType($typeKey);

        $id   = $this->arg($arguments, 'id');
        $name = $this->arg($arguments, 'name');

        $existing = null;
        if ($id !== null) {
            $existing = $modx->getObject($type['class'], (int) $id);
        } elseif ($name !== null) {
            $existing = $modx->getObject($type['class'], [$type['name'] => (string) $name]);
        }

        $properties = [];
        if ($existing) {
            // As with resources, these processors mirror a Manager form, so the
            // stored row is resubmitted and the caller's changes merged over it.
            $properties = $existing->toArray();

            // modElement::toArray() emits a VIRTUAL "content" key in addition to
            // the real column (snippet / plugincode / default_text). If both
            // survive, the stale virtual value is applied last and silently
            // overwrites the update: the processor reports success and the body
            // never changes. Drop the shadow, unless "content" IS the real
            // column for this type (templates), where dropping it would blank
            // the body on any update that does not resend it.
            if ($type['content'] !== 'content') {
                unset($properties['content']);
            }
        }

        if ($name !== null) {
            $properties[$type['name']] = (string) $name;
        }
        if (($content = $this->arg($arguments, 'content')) !== null) {
            $properties[$type['content']] = (string) $content;
        }
        if (($description = $this->arg($arguments, 'description')) !== null) {
            $properties['description'] = (string) $description;
        }
        if (array_key_exists('category', $arguments) && $arguments['category'] !== null) {
            $properties['category'] = (int) $arguments['category'];
        }

        $created = $existing === null;

        if ($existing) {
            $properties['id'] = (int) $existing->get('id');
            $action = $type['processor'] . '/Update';
        } else {
            // Creating requires a name; updating can be addressed by id alone.
            $this->requireArg($arguments, 'name');
            $action = $type['processor'] . '/Create';
        }

        $object = $this->runProcessor($modx, $action, $properties);

        $result            = $this->normaliseElement($object, $type, false);
        $result['type']    = $typeKey;
        $result['created'] = $created;

        if (!empty($object['static'])) {
            $result['warnings'] = [
                'This element is static: MODX reads its body from disk, so this database change '
                . 'will not take effect until the file is updated.',
            ];
        }

        return $result;
    }
}
