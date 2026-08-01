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
     * @return array<string,array{class:string,processor:string,name:string,content:string,label:string}>
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
            ],
            'snippet' => [
                'class'     => modSnippet::class,
                'processor' => 'Element/Snippet',
                'name'      => 'name',
                'content'   => 'snippet',
                'label'     => 'snippet (PHP executed on render)',
            ],
            'template' => [
                'class'     => modTemplate::class,
                'processor' => 'Element/Template',
                'name'      => 'templatename',
                'content'   => 'content',
                'label'     => 'template (the page shell)',
            ],
            'tv' => [
                'class'     => modTemplateVar::class,
                'processor' => 'Element/TemplateVar',
                'name'      => 'name',
                'content'   => 'default_text',
                'label'     => 'template variable (a custom field on resources)',
            ],
            'plugin' => [
                'class'     => modPlugin::class,
                'processor' => 'Element/Plugin',
                'name'      => 'name',
                'content'   => 'plugincode',
                'label'     => 'plugin (PHP bound to system events)',
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
            throw McpException::invalidParams(sprintf(
                "'%s' applies to %s, not to %s. Nothing was written.",
                $property,
                implode(' and ', array_map(fn($t) => $t . 's', $validFor)),
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
