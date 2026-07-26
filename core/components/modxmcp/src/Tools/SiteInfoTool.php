<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modContext;
use MODX\Revolution\modNamespace;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modX;
use MODXMCP\Registry\ToolInterface;

/**
 * Orientation tool.
 *
 * Beyond inventory, this reports behavioural warnings for the extras it finds.
 * Those rules exist only in extras' event handlers, never in any schema, so a
 * model cannot infer them however hard it introspects. Surfacing them up front
 * is what stops content from silently disappearing.
 */
final class SiteInfoTool implements ToolInterface
{
    /**
     * Extra-specific rules that break content when ignored. Keyed by namespace.
     */
    private const WARNINGS = [
        'collections' => 'Collections is installed. Resources created under a Collections '
            . 'container MUST have show_in_tree=0 and a real menuindex, otherwise they '
            . 'vanish from listings even though they exist and are published.',
        'seosuite' => 'SeoSuite is installed. It inner-joins its own tables, so a resource '
            . 'written outside the Manager save path is silently absent from sitemap.xml. '
            . 'Always use the processor-backed resource tools, never raw object writes. '
            . 'Changing an alias also needs a redirect row, which is not created automatically.',
        'migx' => 'MIGX is installed. MIGX template variables store JSON, and their structure '
            . 'is defined by a MIGX config rather than by any database schema. Read the TV '
            . 'input properties before writing a MIGX TV value.',
        'tagger' => 'Tagger is installed. Tags are relations, not a plain field: writing a '
            . 'tag TV value directly will not register the tag with Tagger.',
    ];

    public function name(): string
    {
        return 'modxmcp_site_info';
    }

    public function requiredScope(): string
    {
        return 'read';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'MODX site information',
            'description' => 'Orientation call. Returns the MODX version, contexts, templates, '
                . 'installed extras, and behavioural warnings that apply to this specific site. '
                . 'Call this first whenever you do not already know what the site contains: the '
                . 'warnings describe rules that are not discoverable from any schema.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => new \stdClass(),
                'required'   => [],
            ],
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $contexts = [];
        foreach ($modx->getIterator(modContext::class) as $context) {
            $contexts[] = $context->get('key');
        }

        $templates = [];
        foreach ($modx->getIterator(modTemplate::class) as $template) {
            $templates[] = [
                'id'   => (int) $template->get('id'),
                'name' => $template->get('templatename'),
            ];
        }

        $extras = [];
        foreach ($modx->getIterator(modNamespace::class) as $namespace) {
            $name = (string) $namespace->get('name');
            if ($name !== 'core') {
                $extras[] = $name;
            }
        }
        sort($extras);

        $warnings = [];
        foreach (self::WARNINGS as $namespace => $warning) {
            if (in_array($namespace, $extras, true)) {
                $warnings[] = $warning;
            }
        }

        return [
            'modx_version' => $modx->version['full_version'] ?? 'unknown',
            'php_version'  => PHP_VERSION,
            'site_name'    => $modx->getOption('site_name'),
            'site_url'     => $modx->getOption('site_url'),
            'site_status'  => (int) $modx->getOption('site_status') === 1 ? 'online' : 'offline',
            'contexts'     => $contexts,
            'templates'    => $templates,
            'extras'       => $extras,
            'extras_count' => count($extras),
            'warnings'     => $warnings,
            'bound_user'   => $modx->user ? $modx->user->get('username') : null,
            // Proof, on every call, that the session-free contract still holds.
            //
            // cookieless is the signal that matters. modx_state is MODX's own
            // cached flag and is deliberately NOT authoritative here: on the
            // snippet route it still reads INITIALIZED(1) after teardown,
            // because getSessionState() short-circuits on that value and never
            // re-checks. An empty PHP session id and no Set-Cookie on the
            // response are what actually establish the contract.
            'session' => [
                'cookieless' => session_id() === '',
                'php_sid'    => session_id(),
                'modx_state' => $modx->getSessionState(),
            ],
        ];
    }
}
