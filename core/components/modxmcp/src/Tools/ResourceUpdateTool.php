<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Update a resource through the MODX Resource/Update processor.
 *
 * The processor mirrors the Manager form, which submits every field on every
 * save. Passing only the changed fields therefore risks blanking the ones left
 * out, so this reads the current row first and merges the caller's changes over
 * it. Callers get partial-update semantics; the processor still gets a complete
 * form, which is what the Manager save path expects.
 */
final class ResourceUpdateTool extends AbstractTool
{
    use ResourceSupport;

    /**
     * Fields a caller may change. Anything outside this list is carried over
     * from the stored row untouched.
     *
     * @var string[]
     */
    private const EDITABLE = [
        'pagetitle', 'longtitle', 'description', 'introtext', 'alias', 'content',
        'parent', 'template', 'published', 'hidemenu', 'menuindex', 'menutitle',
        'searchable', 'cacheable', 'show_in_tree',
    ];

    public function requiredScope(): string
    {
        return 'write:content';
    }

    public function name(): string
    {
        return 'modxmcp_resource_update';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Update a resource',
            'description' => 'Update an existing resource. Only the fields you pass are changed; '
                . 'everything else is preserved. Writes through the MODX processor so extras and '
                . 'the cache stay consistent. Changing the alias changes the URL, which needs a '
                . 'redirect if the old URL is public.',
            'inputSchema' => Schema::object([
                'id'          => Schema::integer('Resource id. Required.'),
                'pagetitle'   => Schema::string('Page title.'),
                'longtitle'   => Schema::string('Long title.'),
                'description' => Schema::string('Description.'),
                'introtext'   => Schema::string('Summary or excerpt.'),
                'alias'       => Schema::string('URL alias. Changing this changes the public URL.'),
                'content'     => Schema::string('Body content.'),
                'parent'      => Schema::integer('Move under a different parent.'),
                'template'    => Schema::integer('Template id.'),
                'published'   => Schema::boolean('Published state.'),
                'hidemenu'    => Schema::boolean('Hide from menus.'),
                'menuindex'   => Schema::integer('Sort position among siblings.'),
                'show_in_tree' => Schema::boolean('Show in the resource tree.'),
                ...$this->publishSchema(),
                'class_key'   => Schema::string(
                    'Change the resource type. Same values as modxmcp_resource_create. No '
                    . 'type-specific data is migrated: the content field means something '
                    . 'different for a weblink or a static resource, and switching to or from '
                    . 'an extra\'s container type does not create or remove that extra\'s own '
                    . 'configuration. Also use this to repair a resource whose class_key is '
                    . 'MODX\\Revolution\\modResource, which is not a type MODX itself ever '
                    . 'creates.'),
                'tvs'         => Schema::map(
                    'Template variable values keyed by TV name. Only the TVs you pass are '
                    . 'changed. Only TVs attached to the resource\'s template can be written; '
                    . 'passing one that is not attached is rejected and nothing is updated, '
                    . 'because MODX would otherwise accept the call and silently discard the '
                    . 'value. If you change template in the same call, TVs are resolved against '
                    . 'the new template. The values actually stored come back in the tvs field '
                    . 'of the result.'),
            ], ['id']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $id = (int) $this->requireArg($arguments, 'id');

        /** @var modResource|null $resource */
        $resource = $modx->getObject(modResource::class, $id);
        if (!$resource) {
            throw McpException::invalidParams("No resource with id {$id}");
        }

        $originalAlias    = (string) $resource->get('alias');
        $originalUri      = (string) $resource->get('uri');
        $originalClassKey = (string) $resource->get('class_key');
        $originalTemplate = (int) $resource->get('template');

        // Start from the stored row so omitted fields survive the save.
        $properties = $resource->toArray();
        unset($properties['uri']);   // recomputed by the processor unless overridden

        foreach (self::EDITABLE as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            $value = $arguments[$field];
            if (in_array($field, ['published', 'hidemenu', 'searchable', 'cacheable', 'show_in_tree'], true)) {
                $value = !empty($value) ? 1 : 0;
            } elseif (in_array($field, ['parent', 'template', 'menuindex'], true)) {
                $value = (int) $value;
            }
            $properties[$field] = $value;
        }

        // Deliberately outside the EDITABLE loop. That loop applies generic casts
        // and merges blindly, and class_key needs validating against the resource
        // types this site actually has. Set unconditionally rather than relying on
        // toArray() to carry it: Resource/Update dereferences
        // $properties['class_key'] with no isset guard, so it must always be
        // present, changed or not.
        $properties['class_key'] = $this->resolveClassKey(
            $modx,
            $this->arg($arguments, 'class_key'),
            $originalClassKey
        );
        $this->guardRedirectingTypeChange($originalClassKey, (string) $properties['class_key']);

        // Throws on an unreadable date, before the processor runs.
        $publishWarnings = $this->applyPublishDates($arguments, $properties, $resource->toArray());

        // Resolve TVs against the template the resource will HAVE, not the one it
        // had. The old code read them off the in-memory object after the template
        // had already been overwritten in $properties, so a call changing template
        // and TVs together resubmitted the previous template's TV set: values MODX
        // then discarded, against a template that never declared them.
        $targetTemplate = (int) ($properties['template'] ?? 0);

        // Existing TV values must be resubmitted too, or the save blanks them.
        // These are attached to $targetTemplate by construction, so only
        // caller-supplied names can ever trigger a rejection below.
        $currentTvs = $this->readTvsForTemplate($modx, $id, $targetTemplate);
        $incoming   = $this->arg($arguments, 'tvs');
        $incoming   = is_array($incoming) ? $incoming : [];
        $merged     = array_merge($currentTvs, $incoming);

        if ($merged !== []) {
            $properties = array_replace($properties, $this->resolveTvs($modx, $merged, $targetTemplate));

            // Resource/Update::saveTemplateVariables() opens with
            // getProperty('tvs') and skips the entire block when it is empty, so
            // the tv{id} properties above are ignored without it. The Manager
            // form posts tvs=1 and nothing documents that it is load-bearing.
            //
            // Its absence meant resource_update silently never wrote a template
            // variable. Resource/Create has no such gate, which is why creating
            // with TVs always worked and updating with them never did.
            $properties['tvs'] = 1;
        }

        $this->runProcessor($modx, 'Resource/Update', $properties);

        // Read the row back: this processor strips exactly the fields an update
        // is most likely to have changed (pagetitle, longtitle, content,
        // introtext, description, menutitle).
        $result   = $this->summarise($modx, $id);
        $warnings = $this->parentWarnings(
            $modx,
            (int) ($properties['parent'] ?? 0),
            array_key_exists('show_in_tree', $properties) ? (int) $properties['show_in_tree'] : null
        );

        // An alias change silently breaks every existing link to the old URL.
        // SeoSuite is the usual owner of redirects here, but it does not create
        // one for a programmatic change, so say so rather than let it rot.
        $newAlias = (string) ($result['alias'] ?? $originalAlias);
        if ($newAlias !== $originalAlias) {
            $warnings[] = sprintf(
                'Alias changed from "%s" to "%s", so the URL moved from "%s". No redirect was '
                . 'created. If the old URL was public, add one%s.',
                $originalAlias,
                $newAlias,
                $originalUri,
                // Name the tool, not just the extra. Telling a caller a redirect
                // is missing while it is holding the tool that creates one is a
                // dead end it has no way to get out of.
                $modx->getObject(\MODX\Revolution\modNamespace::class, ['name' => 'seosuite'])
                    ? sprintf(
                        ' with modxmcp_seo_redirect {"old_url": "%s", "resource_id": %d}',
                        $originalUri,
                        $id
                    )
                    : ''
            );
        }

        $newClassKey = (string) ($result['class_key'] ?? $originalClassKey);
        $warnings    = array_merge(
            $warnings,
            $this->classKeyWarnings($modx, $newClassKey, $newClassKey !== $originalClassKey),
            // Only when the caller left it alone: if they just changed it, the
            // classKeyWarnings above already say everything worth saying.
            $newClassKey === $originalClassKey
                ? $this->legacyClassKeyWarning($originalClassKey)
                : []
        );

        $warnings = array_merge(
            $warnings,
            $this->templateChangeWarnings($modx, $originalTemplate, $targetTemplate),
            $publishWarnings,
            // $result is the re-read row. A missing publish_document permission
            // reverts these fields and still reports success, so the comparison
            // after the write is the only way to see it.
            $this->publishStateWarnings($modx, $arguments, $properties, $result)
        );

        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        if ($incoming !== []) {
            $result['tvs'] = $this->readTvValues($modx, $id, array_keys($incoming));
        }

        return $result;
    }
}
