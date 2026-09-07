<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modCategory;
use MODX\Revolution\modChunk;
use MODX\Revolution\modEvent;
use MODX\Revolution\modPlugin;
use MODX\Revolution\modPluginEvent;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarTemplate;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;

/**
 * The five MODX element types, behind one set of tools.
 *
 * They are near-identical in behaviour and wildly inconsistent in naming: the
 * body lives in "snippet" for chunks and snippets, "content" for templates,
 * "plugincode" for plugins and "default_text" for template variables, and a
 * template's name is "templatename" while everything else uses "name". Five
 * separate tool families would multiply that inconsistency across the tool
 * surface; this table absorbs it once.
 */
trait ElementSupport
{
    /**
     * `fields` maps our argument name to the property the processor expects. They
     * are not the same, and often not the same as the column either: `elements`
     * is submitted as `els`, an element's default property set is `propdata`, and
     * a TV's input and output option blobs are not submitted as objects at all
     * (see explodePrefixed()). Centralising the mapping here is what keeps
     * ElementSaveTool a loop rather than a run of special cases.
     *
     * @return array<string,array<string,mixed>>
     */
    private function elementTypes(): array
    {
        return [
            'chunk' => [
                'class'     => modChunk::class,
                'processor' => 'Element/Chunk',
                'name'      => 'name',
                'content'   => 'snippet',
                'label'     => 'chunk (a reusable HTML fragment)',
                'fields'    => ['locked' => 'locked'],
            ],
            'snippet' => [
                'class'     => modSnippet::class,
                'processor' => 'Element/Snippet',
                'name'      => 'name',
                'content'   => 'snippet',
                'label'     => 'snippet (PHP executed on render)',
                'fields'    => ['locked' => 'locked'],
                'properties' => true,
            ],
            'template' => [
                'class'     => modTemplate::class,
                'processor' => 'Element/Template',
                'name'      => 'templatename',
                'content'   => 'content',
                'label'     => 'template (the page shell)',
                'fields'    => ['locked' => 'locked', 'icon' => 'icon'],
                'properties' => true,
            ],
            'tv' => [
                'class'     => modTemplateVar::class,
                'processor' => 'Element/TemplateVar',
                'name'      => 'name',
                'content'   => 'default_text',
                'label'     => 'template variable (a custom field on resources)',
                'fields'    => [
                    'locked'         => 'locked',
                    'input_type'     => 'type',
                    'caption'        => 'caption',
                    'elements'       => 'els',
                    'display'        => 'display',
                    'rank'            => 'rank',
                ],
            ],
            'plugin' => [
                'class'     => modPlugin::class,
                'processor' => 'Element/Plugin',
                'name'      => 'name',
                'content'   => 'plugincode',
                'label'     => 'plugin (PHP bound to system events)',
                'fields'    => [
                    'locked'   => 'locked',
                    'disabled' => 'disabled',
                ],
                'properties' => true,
            ],
        ];
    }

    /**
     * @return array{class:string,processor:string,name:string,content:string,label:string}
     * @throws McpException
     */
    protected function elementType(string $type): array
    {
        $types = $this->elementTypes();
        $key   = strtolower(trim($type));
        if (!isset($types[$key])) {
            throw McpException::invalidParams(
                "Unknown element type '{$type}'. Expected one of: " . implode(', ', array_keys($types)));
        }
        return $types[$key];
    }

    /** @return string[] */
    protected function elementTypeKeys(): array
    {
        return array_keys($this->elementTypes());
    }

    /**
     * Normalise an element row so callers see the same shape whatever the type.
     *
     * @param array<string,mixed> $row
     * @param array{name:string,content:string} $type
     * @return array<string,mixed>
     */
    protected function normaliseElement(array $row, array $type, bool $includeContent): array
    {
        $out = [
            'id'          => (int) ($row['id'] ?? 0),
            'name'        => $row[$type['name']] ?? '',
            'description' => $row['description'] ?? '',
            'category'    => (int) ($row['category'] ?? 0),
            'locked'      => !empty($row['locked']),
        ];

        if (array_key_exists('static', $row)) {
            $out['static']      = !empty($row['static']);
            $out['static_file'] = $row['static_file'] ?? '';
        }
        if (array_key_exists('disabled', $row)) {
            $out['disabled'] = !empty($row['disabled']);
        }
        if (isset($row['type'])) {
            $out['input_type'] = $row['type'];
        }

        if ($includeContent) {
            $out['content'] = $row[$type['content']] ?? '';
        }

        return $out;
    }

    /**
     * The fields element_save replaces wholesale rather than merging.
     *
     * Kept out of normaliseElement() on purpose: element_list shares that one
     * and has to stay cheap. Two callers need these, for the same underlying
     * reason — a caller told to "send every option you want to keep" has to be
     * able to see what is there.
     *
     *   element_get    so a read-modify-write cycle does not destroy the set
     *   element_delete so the echo of a permanent deletion is actually enough
     *                  to rebuild what was removed
     *
     * @param mixed $element
     * @param array<string,mixed> $type
     * @return array<string,mixed>
     */
    protected function replacedFields($element, array $type, string $typeKey): array
    {
        $out = [];

        if (!empty($type['properties'])) {
            // Re-emitted as the list element_save accepts, not the map the
            // column stores, so neither caller has to reshape it.
            $out['properties'] = array_values($this->storedArray($element->get('properties')));
        }

        if ($typeKey === 'tv') {
            $out['caption']           = (string) $element->get('caption');
            $out['display']           = (string) $element->get('display');
            $out['elements']          = (string) $element->get('elements');
            $out['rank']              = (int) $element->get('rank');
            $out['input_properties']  = $this->storedArray($element->get('input_properties'));
            $out['output_properties'] = $this->storedArray($element->get('output_properties'));
        }

        return $out;
    }

    /**
     * Normalise one of xPDO's serialised blob columns to an array.
     *
     * Whether the array or the raw string comes back depends on how the field
     * is declared in the model, and that is not consistent across the element
     * classes, so both are handled rather than betting on one. Objects are
     * refused during unserialisation: this data is written by the Manager and
     * by this extra, but it is still a blob from a database column.
     *
     * @param mixed $value
     * @return array<mixed>
     */
    protected function storedArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = @unserialize($value, ['allowed_classes' => false]);
        if (is_array($decoded)) {
            return $decoded;
        }

        $json = json_decode($value, true);
        return is_array($json) ? $json : [];
    }

    /**
     * Describe an element by re-reading it after a write.
     *
     * The same argument ResourceSupport::summarise() makes for resources, which
     * had not been applied here: the processors cannot be trusted to echo what
     * they saved. Element/*::cleanup() returns a hand-picked subset that omits
     * static, static_file and source entirely, so those could never be confirmed
     * from a response, and modElement::toArray() emits a virtual `content` key
     * alongside the real column, so the echo is a structure with a known
     * shadow-field problem.
     *
     * A processor that returns success having written nothing is not
     * hypothetical here. Resource/Update did exactly that for template variables
     * for the whole life of this extra.
     *
     * @param array{class:string,name:string,content:string} $type
     * @return array<string,mixed>
     * @throws McpException
     */
    protected function summariseElement(modX $modx, array $type, int $id, bool $includeContent): array
    {
        $element = $modx->getObject($type['class'], $id);
        if (!$element) {
            throw McpException::internal("Element {$id} could not be read back after saving");
        }

        return $this->normaliseElement($element->toArray(), $type, $includeContent);
    }

    /**
     * Fold a type's own definition fields into the processor properties.
     *
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $properties
     * @param array<string,mixed> $type
     * @throws McpException
     */
    protected function applyElementFields(array $arguments, array &$properties, array $type): void
    {
        foreach ($type['fields'] ?? [] as $argument => $property) {
            if (!array_key_exists($argument, $arguments) || $arguments[$argument] === null) {
                continue;
            }
            $value = $arguments[$argument];

            if (in_array($property, ['locked', 'disabled'], true)) {
                $properties[$property] = !empty($value) ? 1 : 0;
                continue;
            }

            if ($property === 'rank') {
                $properties['rank'] = (int) $value;
                continue;
            }

            $properties[$property] = is_array($value) ? $value : (string) $value;
        }

        // The two option blobs are the odd ones out: the processor does not read
        // them as objects. It scans every submitted property for a prefix and
        // rebuilds the column from what it finds, so a map has to be flattened
        // into one prefixed property per entry before the call.
        //
        // Safe to omit. Element/TemplateVar/Update only writes the column when at
        // least one prefixed key was present, so a save that does not mention
        // them leaves an existing MIGX configuration alone.
        $this->explodePrefixed($arguments, $properties, 'input_properties', 'inopt_');
        $this->explodePrefixed($arguments, $properties, 'output_properties', 'prop_');
    }

    /**
     * Write an element's default property set.
     *
     * Not part of applyElementFields(), because it cannot ride on the element
     * save at all. `propdata` is read by Element/Create and by nothing else:
     * every Update processor documents it in its docblock and then never looks
     * at it, so sending it on an update is a silent no-op. The Manager works
     * around this by posting the properties grid separately to
     * Element/PropertySet/UpdateFromElement, and so does this.
     *
     * Called after the save because it needs the element's id, and it is the one
     * part of a save that cannot be validated beforehand.
     *
     * @param array<string,mixed> $properties raw property definitions from the caller
     * @throws McpException
     */
    protected function applyElementProperties(
        modX $modx,
        string $typeKey,
        int $elementId,
        array $properties
    ): void {
        $response = $modx->runProcessor('Element/PropertySet/UpdateFromElement', [
            'id'          => 'Default',
            'elementId'   => $elementId,
            'elementType' => $this->propertySetElementType($typeKey),
            'data'        => json_encode(
                array_values($properties),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
        ]);

        if (!$response || $response->isError()) {
            throw McpException::internal(sprintf(
                'The element saved but its default properties did not: %s',
                $response ? $response->getMessage() : 'the processor could not be loaded'
            ));
        }
    }

    /**
     * The class name Element/PropertySet/UpdateFromElement expects.
     */
    private function propertySetElementType(string $typeKey): string
    {
        $type = $this->elementType($typeKey);

        return $type['class'];
    }

    /**
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $properties
     * @throws McpException
     */
    private function explodePrefixed(
        array $arguments,
        array &$properties,
        string $argument,
        string $prefix
    ): void {
        if (!array_key_exists($argument, $arguments) || $arguments[$argument] === null) {
            return;
        }
        if (!is_array($arguments[$argument])) {
            throw McpException::invalidParams("{$argument} must be an object of option names to values.");
        }

        foreach ($arguments[$argument] as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $properties[$prefix . $key] = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : $value;
        }
    }

    /**
     * Refuse arguments that mean nothing for the type being saved.
     *
     * Two element types carry a binding that lives in its own table, and each
     * argument for one is meaningless for the other four. Silently ignoring a
     * misplaced key is how the missing plugin-event binding went unnoticed for
     * so long: the call looked like it had done what was asked.
     *
     * Scoped to the keys named here on purpose. Genuinely unknown keys are
     * still ignored, exactly as before, so no existing caller can break on an
     * argument this tool never claimed to read.
     *
     * @param array<string,mixed>   $arguments
     * @param array<string,string[]> $scoped property => element types it applies to
     * @throws McpException
     */
    protected function rejectMisplaced(array $arguments, string $typeKey, array $scoped): void
    {
        foreach ($scoped as $property => $validFor) {
            if (!array_key_exists($property, $arguments) || $arguments[$property] === null) {
                continue;
            }
            if (in_array($typeKey, $validFor, true)) {
                continue;
            }
            $plural = array_map(static fn($t) => $t . 's', $validFor);
            $last   = array_pop($plural);
            $names  = $plural === [] ? $last : implode(', ', $plural) . ' and ' . $last;

            throw McpException::invalidParams(sprintf(
                "'%s' applies to %s, not to %s. Nothing was written.",
                $property,
                $names,
                $typeKey . 's'
            ));
        }
    }

    /**
     * System events a plugin is bound to, in priority order.
     *
     * @return array<int,array{name:string,priority:int,propertyset:int}>
     */
    protected function pluginEvents(modX $modx, int $pluginId): array
    {
        $events = [];
        foreach ($modx->getIterator(modPluginEvent::class, ['pluginid' => $pluginId]) as $binding) {
            $events[] = [
                'name'        => (string) $binding->get('event'),
                'priority'    => (int) $binding->get('priority'),
                'propertyset' => (int) $binding->get('propertyset'),
            ];
        }
        usort($events, fn($a, $b) => [$a['priority'], $a['name']] <=> [$b['priority'], $b['name']]);
        return $events;
    }

    /**
     * Templates a template variable is attached to.
     *
     * @return array<int,array{id:int,name:string}>
     */
    protected function tvTemplates(modX $modx, int $tvId): array
    {
        $ids = [];
        foreach ($modx->getIterator(modTemplateVarTemplate::class, ['tmplvarid' => $tvId]) as $link) {
            $ids[] = (int) $link->get('templateid');
        }
        if ($ids === []) {
            return [];
        }

        $templates = [];
        foreach ($modx->getIterator(modTemplate::class, ['id:IN' => $ids]) as $template) {
            $templates[] = [
                'id'   => (int) $template->get('id'),
                'name' => (string) $template->get('templatename'),
            ];
        }
        usort($templates, fn($a, $b) => $a['id'] <=> $b['id']);
        return $templates;
    }

    /**
     * Resolve a category given as an id or a name.
     *
     * A name that does not exist is refused rather than created. Auto-creating
     * on a save is how a site ends up with "Blog", "blog" and "Blog " as three
     * separate categories, and the caller cannot see it happening. Creating one
     * is a deliberate act with its own tool.
     *
     * @param int|string $entry
     * @throws McpException
     */
    protected function resolveCategoryId(modX $modx, $entry): int
    {
        if (is_int($entry) || ctype_digit((string) $entry)) {
            return (int) $entry;
        }

        $name = trim((string) $entry);
        if ($name === '') {
            return 0;
        }

        /** @var modCategory|null $category */
        $category = $modx->getObject(modCategory::class, ['category' => $name]);
        if ($category) {
            return (int) $category->get('id');
        }

        $available = [];
        foreach ($modx->getIterator(modCategory::class) as $row) {
            $available[] = (string) $row->get('category');
        }
        sort($available);

        throw McpException::invalidParams(sprintf(
            "No category named '%s'. %s Create it with modxmcp_category_save, or pass 0 for "
            . 'uncategorised. Nothing was written.',
            $name,
            $available === []
                ? 'This site has no categories yet.'
                : 'This site has: ' . implode(', ', array_slice($available, 0, 40)) . '.'
        ));
    }

    /**
     * Turn a caller's event list into what Element/Plugin expects.
     *
     * Each entry is handed to the Plugin/Event/Update processor, which keys on
     * 'event' and treats 'enabled' as the switch: truthy binds, falsy unbinds.
     * Unbinding an event that was never bound makes that sub-processor fail and
     * log, so only currently-bound events are emitted with enabled=false.
     *
     * @param array<int,mixed> $wanted event names
     * @return array<int,array{name:string,enabled:bool,priority:int,propertyset:int}>
     * @throws McpException
     */
    protected function pluginEventPayload(modX $modx, int $pluginId, array $wanted): array
    {
        $names = [];
        foreach ($wanted as $entry) {
            if (!is_string($entry) && !is_numeric($entry)) {
                throw McpException::invalidParams(
                    'events must be a list of system event names, e.g. ["OnDocFormSave"]. '
                    . 'Nothing was written.');
            }
            $name = trim((string) $entry);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        $names = array_values(array_unique($names));

        $unknown = [];
        foreach ($names as $name) {
            if ($modx->getCount(modEvent::class, ['name' => $name]) === 0) {
                $unknown[] = $name;
            }
        }
        if ($unknown !== []) {
            throw McpException::invalidParams(sprintf(
                'No such system event%s: %s. MODX only fires events it defines, so a binding to '
                . 'an invented name would never run. Check the spelling, which is '
                . 'case-sensitive and conventionally starts with "On". Nothing was written.',
                count($unknown) === 1 ? '' : 's',
                implode(', ', $unknown)
            ));
        }

        $existing = [];
        foreach ($this->pluginEvents($modx, $pluginId) as $bound) {
            $existing[$bound['name']] = $bound;
        }

        $payload = [];
        foreach ($names as $name) {
            $payload[] = [
                'name'        => $name,
                'enabled'     => true,
                'priority'    => $existing[$name]['priority'] ?? 0,
                'propertyset' => $existing[$name]['propertyset'] ?? 0,
            ];
        }

        foreach ($existing as $name => $bound) {
            if (!in_array($name, $names, true)) {
                $payload[] = [
                    'name'        => $name,
                    'enabled'     => false,
                    'priority'    => $bound['priority'],
                    'propertyset' => $bound['propertyset'],
                ];
            }
        }

        return $payload;
    }

    /**
     * Turn a caller's template list into what Element/TemplateVar expects.
     *
     * The processor takes [['id' => n, 'access' => bool], ...] and applies it
     * differentially: truthy attaches, falsy detaches, and a template not in
     * the list is left alone. So "set these" has to be expressed as "attach
     * these, detach everything currently attached that is not in the list",
     * which is what a caller passing a list actually means.
     *
     * @param array<int,int|string> $wanted ids or template names
     * @return array<int,array{id:int,access:bool}>
     * @throws McpException
     */
    protected function templateAccessPayload(modX $modx, int $tvId, array $wanted): array
    {
        $wantedIds = [];
        foreach ($wanted as $entry) {
            $wantedIds[] = $this->resolveTemplateId($modx, $entry);
        }
        $wantedIds = array_values(array_unique($wantedIds));

        $payload = [];
        foreach ($wantedIds as $id) {
            $payload[] = ['id' => $id, 'access' => true];
        }

        foreach ($this->tvTemplates($modx, $tvId) as $current) {
            if (!in_array($current['id'], $wantedIds, true)) {
                $payload[] = ['id' => $current['id'], 'access' => false];
            }
        }

        return $payload;
    }

    /**
     * @param int|string $entry
     * @throws McpException
     */
    private function resolveTemplateId(modX $modx, $entry): int
    {
        if (is_int($entry) || ctype_digit((string) $entry)) {
            $id = (int) $entry;
            if ($modx->getCount(modTemplate::class, ['id' => $id]) > 0) {
                return $id;
            }
            throw McpException::invalidParams("No template with id {$id}. Nothing was written.");
        }

        /** @var modTemplate|null $template */
        $template = $modx->getObject(modTemplate::class, ['templatename' => (string) $entry]);
        if (!$template) {
            throw McpException::invalidParams(sprintf(
                "No template named '%s'. Use modxmcp_element_list with type=template to see "
                . 'what exists. Nothing was written.',
                $entry
            ));
        }
        return (int) $template->get('id');
    }
}
