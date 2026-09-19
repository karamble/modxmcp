<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modPluginEvent;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarTemplate;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
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
                'category'    => Schema::string(
                    'Category, as an id or a name. 0 for uncategorised. A name that does not '
                    . 'exist is refused rather than created: use modxmcp_category_list to see '
                    . 'what this site has, and modxmcp_category_save to add one.'),
                'events'      => Schema::arrayOf(
                    'PLUGINS ONLY. System event names to bind this plugin to, e.g. '
                    . '["OnDocFormSave", "OnWebPagePrerender"]. A plugin bound to no events is '
                    . 'never executed by MODX, so this is effectively required when creating '
                    . 'one. Passing a list replaces the current bindings: events you omit are '
                    . 'unbound. Omit the argument entirely to leave existing bindings alone.',
                    ['type' => 'string']),
                'input_type'  => Schema::string(
                    'TEMPLATE VARIABLES ONLY. What kind of field this is: text, textarea, '
                    . 'richtext, image, file, listbox, listbox-multiple, checkbox, option, date, '
                    . 'number, email, url, tag, autotag, resourcelist, hidden, or a type an extra '
                    . 'provides such as migx. Note the radio type is called "option"; "radio" is '
                    . 'not a type and a TV given it silently renders as plain text.'),
                'caption'     => Schema::string(
                    'TEMPLATE VARIABLES ONLY. The label shown on the resource edit form. MODX '
                    . 'falls back to the TV name when creating without one.'),
                'elements'    => Schema::string(
                    'TEMPLATE VARIABLES ONLY. The option list for listbox, checkbox and option '
                    . 'types. Options are separated by || and an option may be written '
                    . 'label==value, e.g. "Red==r||Green==g". A @SELECT or @EVAL binding works '
                    . 'here too.'),
                'input_properties' => Schema::map(
                    'TEMPLATE VARIABLES ONLY. Configuration for the chosen input type, e.g. '
                    . '{"configs": "myMigxConfig"} for a MIGX TV. Passing this REPLACES the '
                    . 'whole set rather than merging, so send every option you want to keep. '
                    . 'Omit it entirely to leave the existing configuration alone.'),
                'output_properties' => Schema::map(
                    'TEMPLATE VARIABLES ONLY. Configuration for the output renderer named by '
                    . 'display. Same replace-not-merge behaviour as input_properties.'),
                'display'     => Schema::string(
                    'TEMPLATE VARIABLES ONLY. Output renderer: date, delim, htmltag, image, '
                    . 'richtext, string, text, url, or default.'),
                'rank'        => Schema::integer(
                    'TEMPLATE VARIABLES ONLY. Sort position on the resource edit form.'),
                'properties'  => Schema::arrayOf(
                    'SNIPPETS, PLUGINS AND TEMPLATES ONLY. Default properties, as a list of '
                    . '{"name": ..., "value": ..., "type": "textfield", "desc": ""} objects. '
                    . 'Always give a string value; a null value is silently mangled by MODX. '
                    . 'Passing this replaces the whole set.',
                    ['type' => 'object']),
                'disabled'    => Schema::boolean(
                    'PLUGINS ONLY. A disabled plugin stays bound to its events but does not run.'),
                'locked'      => Schema::boolean(
                    'Restrict editing to administrators.'),
                'templates'   => Schema::arrayOf(
                    'TEMPLATE VARIABLES ONLY. Templates this TV is attached to, as ids or '
                    . 'template names. A TV attached to no template renders on no resource, and '
                    . 'modxmcp_resource_create and modxmcp_resource_update cannot write a value '
                    . 'to it. Passing a list replaces the current attachments: templates you '
                    . 'omit are detached. Omit the argument entirely to leave them alone.',
                    ['type' => ['string', 'integer']]),
            ], ['type']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $typeKey = strtolower((string) $this->requireArg($arguments, 'type'));
        $type    = $this->elementType($typeKey);

        $this->rejectMisplaced($arguments, $typeKey, [
            'events'            => ['plugin'],
            'templates'         => ['tv'],
            'input_type'        => ['tv'],
            'caption'           => ['tv'],
            'elements'          => ['tv'],
            'input_properties'  => ['tv'],
            'output_properties' => ['tv'],
            'display'           => ['tv'],
            'rank'              => ['tv'],
            'disabled'          => ['plugin'],
            'properties'        => ['snippet', 'plugin', 'template'],
        ]);

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
            $properties['category'] = $this->resolveCategoryId($modx, $arguments['category']);
        }

        // Definition fields, mapped per type in ElementSupport::elementTypes().
        $this->applyElementFields($arguments, $properties, $type);

        // Bindings live in their own tables, and both Create and Update accept
        // them as a property, so they ride along on the same processor call and
        // there is no window where the element exists unbound. Resolved before
        // the save so an unknown event or template fails before anything is
        // written. The differential is computed against the existing element,
        // or against nothing when creating.
        $existingId = $existing ? (int) $existing->get('id') : 0;

        if (($events = $this->arg($arguments, 'events')) !== null) {
            if (!is_array($events)) {
                throw McpException::invalidParams('events must be an array of event names.');
            }
            $properties['events'] = $this->pluginEventPayload($modx, $existingId, $events);
        }

        if (($templates = $this->arg($arguments, 'templates')) !== null) {
            if (!is_array($templates)) {
                throw McpException::invalidParams(
                    'templates must be an array of template ids or names.');
            }
            $properties['templates'] = $this->templateAccessPayload($modx, $existingId, $templates);
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

        // The id is the only thing taken from the processor's echo, exactly as
        // ResourceCreateTool does. Guarded because runProcessor() hands back the
        // whole envelope when a processor omits `object`, and the Element
        // processors are less uniform about that than the Resource ones, so a
        // missing id would otherwise become a confident read of element 0.
        $savedId = (int) ($object['id'] ?? 0);
        if ($savedId <= 0) {
            throw McpException::internal(sprintf(
                "Processor '%s' reported success without returning an element id, so the save "
                . 'cannot be confirmed. Read the element back with modxmcp_element_get.',
                $action
            ));
        }

        // Default properties cannot ride on the save: propdata is read by the
        // Create processors and ignored by every Update processor, so this goes
        // through the same separate processor the Manager uses.
        $elementProperties = $this->arg($arguments, 'properties');
        if (is_array($elementProperties)) {
            $this->applyElementProperties($modx, $typeKey, $savedId, $elementProperties);
        }

        $saved             = $this->summariseElement($modx, $type, $savedId, false);
        $result            = $saved;
        $result['type']    = $typeKey;
        $result['created'] = $created;
        if ($typeKey === 'plugin' && $savedId > 0) {
            $result['events'] = array_column($this->pluginEvents($modx, $savedId), 'name');
        }
        if ($typeKey === 'tv' && $savedId > 0) {
            $result['templates'] = $this->tvTemplates($modx, $savedId);
        }

        $warnings = [];

        // From the re-read row, not the processor's echo: cleanup() does not
        // return `static` at all, so the echo could never answer this.
        if (!empty($saved['static'])) {
            $warnings[] = 'This element is static: MODX reads its body from disk, so this '
                . 'database change will not take effect until the file is updated. modxmcp '
                . 'does not change which file that is.';
        }

        $warnings = array_merge($warnings, $this->bindingWarnings($modx, $typeKey, $saved));
        $warnings = array_merge($warnings, $this->inputTypeWarnings($modx, $typeKey, $saved));

        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * The template variable input types MODX itself ships.
     *
     * Read off the render directory rather than invented: MODX resolves an
     * input type by looking for <type>.class.php under
     * {processors_path}Element/TemplateVar/Renders/mgr/input/, and these are
     * the seventeen files it ships. Deliberately the same list this tool's
     * description recites, so the two cannot disagree.
     */
    private const KNOWN_INPUT_TYPES = [
        'text', 'textarea', 'richtext', 'image', 'file', 'listbox',
        'listbox-multiple', 'checkbox', 'option', 'date', 'number', 'email',
        'url', 'tag', 'autotag', 'resourcelist', 'hidden',
    ];

    /**
     * Wrong names people reach for, and what MODX actually calls the thing.
     *
     * `radio` heads the list because it is the one this tool's own description
     * warns about and then accepted anyway.
     */
    private const INPUT_TYPE_HINTS = [
        'radio'       => 'option',
        'radiobutton' => 'option',
        'radiogroup'  => 'option',
        'select'      => 'listbox',
        'dropdown'    => 'listbox',
        'multiselect' => 'listbox-multiple',
        'checkboxes'  => 'checkbox',
        'textfield'   => 'text',
        'string'      => 'text',
        'int'         => 'number',
        'integer'     => 'number',
        'float'       => 'number',
        'datetime'    => 'date',
        'bool'        => 'checkbox',
        'boolean'     => 'checkbox',
        'wysiwyg'     => 'richtext',
        'html'        => 'richtext',
    ];

    /**
     * Warn when a TV's input type is one nothing can render.
     *
     * MODX stores whatever it is given and falls back to a plain text input
     * when no renderer matches, with nothing logged and nothing returned to say
     * so. This tool's own description warns that "radio is not a type and a TV
     * given it silently renders as plain text", and then accepted `radio`
     * without a word. It accepted `totalnonsense_xyz` too.
     *
     * A warning rather than a refusal, because extras register their own types
     * and this cannot enumerate them. Two sources are consulted instead of one:
     * the types MODX ships, and the types template variables on this site
     * already use, which is how an extra's type earns recognition without being
     * named here. MIGX's `migx` passes on any site that has a MIGX TV.
     *
     * What this deliberately does NOT do is ask MODX. modTemplateVar::
     * getRenderDirectories('OnTVInputRenderList', 'input') is the authoritative
     * answer and it is unusable here: it invokes the event, which runs every
     * extra's plugin, and those plugins assume a Manager request. Ace's
     * initialize() calls addLexiconTopic() on a lexicon that is null outside
     * one, so asking the authoritative question fatals the entire tool call on
     * any site with Ace installed. A warning must never be able to break the
     * write it is commenting on.
     *
     * @param array<string,mixed> $saved normaliseElement() output, which renames
     *                                      the `type` column to `input_type`
     * @return string[]
     */
    private function inputTypeWarnings(modX $modx, string $typeKey, array $saved): array
    {
        if ($typeKey !== 'tv') {
            return [];
        }

        // `input_type`, not `type`: this is the normalised summary, where
        // normaliseElement() has already renamed the column. Reading `type`
        // here silently found nothing and returned, which is a quiet way for a
        // warning to never appear.
        $type = strtolower(trim((string) ($saved['input_type'] ?? '')));
        if ($type === '' || in_array($type, self::KNOWN_INPUT_TYPES, true)) {
            return [];
        }
        // Excluding the row just written. It is in the table by now, so
        // counting it would let any unknown type vouch for itself and the
        // warning would never fire at all -- which is exactly what happened
        // the first time this ran.
        if (in_array($type, $this->inputTypesInUse($modx, (int) ($saved['id'] ?? 0)), true)) {
            return [];
        }

        $hint = self::INPUT_TYPE_HINTS[$type] ?? null;

        return [sprintf(
            "input_type '%s' is not one MODX ships, and no template variable on this site uses "
            . 'it. %sMODX stores it and falls back to a plain text input, with nothing logged and '
            . 'no error anywhere, so this is easy to miss. The types MODX provides are: %s. If an '
            . "extra on this site provides '%s', disregard this.",
            $type,
            $hint !== null ? sprintf("Did you mean '%s'? ", $hint) : '',
            implode(', ', self::KNOWN_INPUT_TYPES),
            $type
        )];
    }

    /**
     * Input types template variables on this site already use.
     *
     * One grouped query rather than loading every TV, and it answers the only
     * question worth asking about an extra's type: whether anything already
     * uses it. An extra that is installed but whose type is unused anywhere
     * still draws the warning, which is why the warning says so rather than
     * asserting the type is wrong.
     *
     * $excludeId keeps the row just saved out of the answer. Without it the
     * check is circular: the TV carrying the unknown type is already stored by
     * the time this runs, so the type is always "in use on this site".
     *
     * @return string[]
     */
    private function inputTypesInUse(modX $modx, int $excludeId = 0): array
    {
        $query = $modx->newQuery(modTemplateVar::class);
        $query->select(['type']);
        if ($excludeId > 0) {
            $query->where(['id:!=' => $excludeId]);
        }
        $query->groupby('type');

        $statement = $query->prepare();
        if (!$statement || !$statement->execute()) {
            // No opinion beats a wrong one: a query that will not run must not
            // become a warning that the caller's type is unknown.
            return self::KNOWN_INPUT_TYPES;
        }

        return array_map(
            static fn($value) => strtolower(trim((string) $value)),
            $statement->fetchAll(\PDO::FETCH_COLUMN) ?: []
        );
    }

    /**
     * Warn when an element saved successfully but cannot possibly run.
     *
     * Two element types are inert without a binding that lives in a separate
     * table, and this tool does not write either one yet. A plugin with no rows
     * in modPluginEvent is never invoked by anything; a template variable with
     * no rows in modTemplateVarTemplate is attached to no template, so it
     * renders nowhere and the Resource processors will discard any value written
     * to it. In both cases the save genuinely succeeded, which is exactly what
     * makes the silence dangerous: the caller has every reason to believe the
     * job is done.
     *
     * Checked rather than assumed, because a caller may well be updating an
     * element that was bound in the Manager years ago.
     *
     * @param array<string,mixed> $object
     * @return string[]
     */
    private function bindingWarnings(modX $modx, string $typeKey, array $object): array
    {
        $id = (int) ($object['id'] ?? 0);
        if ($id <= 0) {
            return [];
        }

        if ($typeKey === 'plugin') {
            if ($modx->getCount(modPluginEvent::class, ['pluginid' => $id]) > 0) {
                return [];
            }
            return ['This plugin is bound to no system events, so MODX will never execute it. '
                . 'Event bindings live in a separate table that this tool does not write yet: '
                . 'attach it to its events in the Manager under Elements > Plugins > '
                . 'System Events.'];
        }

        if ($typeKey === 'tv') {
            if ($modx->getCount(modTemplateVarTemplate::class, ['tmplvarid' => $id]) > 0) {
                return [];
            }
            return ['This template variable is attached to no template, so it renders on no '
                . 'resource, and modxmcp_resource_create and modxmcp_resource_update cannot '
                . 'write a value to it: the MODX Resource processors only write TVs that the '
                . 'resource\'s template declares. Attach it to a template in the Manager under '
                . 'Elements > Template Variables > Template Access.'];
        }

        return [];
    }
}
