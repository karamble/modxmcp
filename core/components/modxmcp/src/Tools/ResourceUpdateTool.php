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
                'tvs'         => Schema::map('Template variable values keyed by TV name. Only the TVs you pass are changed.'),
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

        $originalAlias = (string) $resource->get('alias');
        $originalUri   = (string) $resource->get('uri');

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

        // Existing TV values must be resubmitted too, or the save blanks them.
        $currentTvs = $this->readTvs($modx, $resource);
        $incoming   = $this->arg($arguments, 'tvs');
        if (is_array($incoming)) {
            $currentTvs = array_merge($currentTvs, $incoming);
        }
        if ($currentTvs !== []) {
            $properties += $this->tvProperties($modx, $currentTvs);
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
                $modx->getObject(\MODX\Revolution\modNamespace::class, ['name' => 'seosuite'])
                    ? ' (SeoSuite is installed and manages redirects for this site)'
                    : ''
            );
        }

        $result['warnings'] = $warnings;

        return $result;
    }
}
