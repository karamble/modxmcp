<?php

namespace MODXMCP\Tools;

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
                'class_key'   => Schema::string(
                    'Resource type, as a class name. Defaults to MODX\\Revolution\\modDocument, '
                    . 'which is what the Manager creates and what almost every page should be. '
                    . 'Use MODX\\Revolution\\modWebLink to link to another URL, '
                    . 'MODX\\Revolution\\modSymLink to mirror another resource, '
                    . 'MODX\\Revolution\\modStaticResource to serve a file from disk, or a class '
                    . 'an installed extra provides, e.g. Collections\\Model\\CollectionContainer '
                    . 'for a Collections container. For the weblink, symlink and static types the '
                    . 'target goes in the content field, not page HTML.',
                    'MODX\\Revolution\\modDocument'),
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
                ...$this->publishSchema(),
                'tvs'         => Schema::map(
                    'Template variable values keyed by TV name, e.g. {"articleimage": "assets/x.jpg"}. '
                    . 'Only TVs attached to the template you are creating with can be written. '
                    . 'Passing one that is not attached is rejected and nothing is created, '
                    . 'because MODX would otherwise accept the call and silently discard the '
                    . 'value. Arrays are encoded for you, including MIGX item lists. The values '
                    . 'actually stored come back in the tvs field of the result.'),
            ], ['pagetitle']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $parent      = (int) $this->arg($arguments, 'parent', 0);
        $showInTree  = array_key_exists('show_in_tree', $arguments)
            ? (!empty($arguments['show_in_tree']) ? 1 : 0)
            : null;

        // Resolved before anything else so a bad class_key fails before the write.
        $classKey = $this->resolveClassKey($modx, $this->arg($arguments, 'class_key'));

        $properties = [
            'pagetitle'   => (string) $this->requireArg($arguments, 'pagetitle'),
            'parent'      => $parent,
            'context_key' => (string) $this->arg($arguments, 'context', 'web'),
            'published'   => !empty($arguments['published']) ? 1 : 0,
            'hidemenu'    => !empty($arguments['hidemenu']) ? 1 : 0,
            'menuindex'   => (int) $this->arg($arguments, 'menuindex', 0),
            'class_key'   => $classKey,
        ];

        foreach (['alias', 'content', 'longtitle', 'description', 'introtext'] as $field) {
            $value = $this->arg($arguments, $field);
            if ($value !== null) {
                $properties[$field] = (string) $value;
            }
        }

        $template   = $this->arg($arguments, 'template');
        $templateId = $template !== null
            ? (int) $template
            : (int) $modx->getOption('default_template');
        $properties['template'] = $templateId;

        if ($showInTree !== null) {
            $properties['show_in_tree'] = $showInTree;
        }

        // Before the TV block so a bad date fails alongside a bad TV name, with
        // nothing written either way.
        $publishWarnings = $this->applyPublishDates($arguments, $properties);

        $tvs = $this->arg($arguments, 'tvs');
        $tvs = is_array($tvs) ? $tvs : [];
        if ($tvs !== []) {
            // Throws before the processor runs, so a bad TV name leaves no
            // half-created resource behind.
            //
            // array_replace rather than +=: the union operator keeps the
            // left-hand value on a key collision, which is only safe here
            // because $properties provably has no tv* keys. That is an
            // invariant nobody will remember in a year.
            $properties = array_replace($properties, $this->resolveTvs($modx, $tvs, $templateId));
        }

        $object = $this->runProcessor($modx, 'Resource/Create', $properties);

        // Read the row back: this processor returns only the id.
        $newId  = (int) ($object['id'] ?? 0);
        $result = $this->summarise($modx, $newId);
        $warnings = array_merge(
            $this->parentWarnings($modx, $parent, $showInTree),
            $this->classKeyWarnings($modx, $classKey, false),
            $publishWarnings,
            // $result is the re-read row, so this compares against what was
            // actually stored rather than what the processor claimed.
            $this->publishStateWarnings($modx, $arguments, $properties, $result)
        );
        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        // Only the names the caller passed. Echoing the template's whole TV set
        // would make a one-field write cost whatever the template costs, and a
        // single MIGX blob runs to tens of kilobytes. Omitted entirely when no
        // TVs were passed, so existing callers see an unchanged shape.
        if ($tvs !== []) {
            $result['tvs'] = $this->readTvValues($modx, $newId, array_keys($tvs));
        }

        return $result;
    }
}
