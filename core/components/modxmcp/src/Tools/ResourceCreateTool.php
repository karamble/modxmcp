<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modResource;
use MODX\Revolution\modX;
use MODXMCP\Registry\Schema;

/**
 * Create a resource through the MODX Resource/Create processor.
 *
 * Going through the processor rather than saving an object is the entire point:
 * it is what fires OnDocFormSave and the rest of the Manager save path, which is
 * how SeoSuite registers the resource, how Collections applies its rules, and how
 * the URI map and cache are updated. A direct object save produces a resource
 * that exists but is invisible to all of them.
 */
final class ResourceCreateTool extends AbstractTool
{
    use ResourceSupport;

    public function requiredScope(): string
    {
        return 'write:content';
    }

    public function name(): string
    {
        return 'modxmcp_resource_create';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Create a resource',
            'description' => 'Create a new resource (page). Writes through the MODX processor, '
                . 'so extras such as SeoSuite and Collections see the resource and the URI map '
                . 'and cache stay correct. Call modxmcp_site_info first if you do not know '
                . 'which templates and template variables this site has.',
            'inputSchema' => Schema::object([
                'pagetitle'   => Schema::string('Page title. Required.'),
                'parent'      => Schema::integer('Parent resource id. 0 for top level.', 0),
                'template'    => Schema::integer('Template id. Omit to use the site default.'),
                'alias'       => Schema::string('URL alias. Omit to let MODX derive it from the pagetitle.'),
                'content'     => Schema::string('Body content, usually HTML.'),
                'longtitle'   => Schema::string('Long title.'),
                'description' => Schema::string('Description.'),
                'introtext'   => Schema::string('Summary or excerpt.'),
                'context'     => Schema::string('Context key.', 'web'),
                'published'   => Schema::boolean('Publish immediately.', false),
                'hidemenu'    => Schema::boolean('Hide from menus.', false),
                'show_in_tree' => Schema::boolean('Show in the resource tree. Set false for children of a Collections container.', true),
                'menuindex'   => Schema::integer('Sort position among siblings.', 0),
                'tvs'         => Schema::map('Template variable values keyed by TV name, e.g. {"articleimage": "..."}. Arrays are JSON-encoded for MIGX-style TVs.'),
            ], ['pagetitle']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $parent      = (int) $this->arg($arguments, 'parent', 0);
        $showInTree  = array_key_exists('show_in_tree', $arguments)
            ? (!empty($arguments['show_in_tree']) ? 1 : 0)
            : null;

        $properties = [
            'pagetitle'   => (string) $this->requireArg($arguments, 'pagetitle'),
            'parent'      => $parent,
            'context_key' => (string) $this->arg($arguments, 'context', 'web'),
            'published'   => !empty($arguments['published']) ? 1 : 0,
            'hidemenu'    => !empty($arguments['hidemenu']) ? 1 : 0,
            'menuindex'   => (int) $this->arg($arguments, 'menuindex', 0),
            'class_key'   => modResource::class,
        ];

        foreach (['alias', 'content', 'longtitle', 'description', 'introtext'] as $field) {
            $value = $this->arg($arguments, $field);
            if ($value !== null) {
                $properties[$field] = (string) $value;
            }
        }

        $template = $this->arg($arguments, 'template');
        $properties['template'] = $template !== null
            ? (int) $template
            : (int) $modx->getOption('default_template');

        if ($showInTree !== null) {
            $properties['show_in_tree'] = $showInTree;
        }

        $tvs = $this->arg($arguments, 'tvs');
        if (is_array($tvs)) {
            $properties += $this->tvProperties($modx, $tvs);
        }

        $object = $this->runProcessor($modx, 'Resource/Create', $properties);

        // Read the row back: this processor returns only the id.
        $result = $this->summarise($modx, (int) ($object['id'] ?? 0));
        $result['warnings'] = $this->parentWarnings($modx, $parent, $showInTree);

        return $result;
    }
}
