<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modContext;
use MODX\Revolution\modNamespace;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modX;
use MODXMCP\Build;
use MODXMCP\Knowledge\AdvisoryCollector;
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
     * Rules that depend only on an extra being present, with nothing to detect.
     *
     * The conditional ones moved to MODXMCP\Knowledge probes, which report what
     * this site actually exhibits rather than what it could. These two stayed
     * because they are properties of the extra itself: MIGX TVs store JSON
     * wherever they appear, and Tagger's tags are relations however they are
     * written. There is no site state that makes either untrue.
     */
    private const WARNINGS = [
        'migx' => 'MIGX is installed. MIGX template variables store JSON, and their structure '
            . 'is defined by a MIGX config rather than by any database schema. Read the TV '
            . 'input properties before writing a MIGX TV value.',
        'tagger' => 'Tagger is installed. Tags are relations, not a plain field: writing a '
            . 'tag TV value directly will not register the tag with Tagger.',
    ];

    /** Operator prose, capped so it cannot dominate every session's first call. */
    private const NOTES_LIMIT = 8192;

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
                'properties' => [
                    'refresh' => [
                        'type'        => 'boolean',
                        'description' => 'Recompute the advisories instead of reading the '
                            . 'cached result. Rarely needed; they are cached because one of '
                            . 'them counts across every published resource.',
                        'default'     => false,
                    ],
                ],
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

        // Orientation stays open to any bound user -- it is what tells a
        // caller what it may do -- but it must not hand over what the Manager
        // gates. Listing templates is Element/Template/GetList's job, and that
        // declares view_template.
        $withheld  = [];
        $templates = [];
        if ($modx->hasPermission('view_template')) {
            foreach ($modx->getIterator(modTemplate::class) as $template) {
                $templates[] = [
                    'id'   => (int) $template->get('id'),
                    'name' => $template->get('templatename'),
                ];
            }
        } else {
            $withheld['templates'] = 'view_template';
        }

        $extras = [];
        foreach ($modx->getIterator(modNamespace::class) as $namespace) {
            $name = (string) $namespace->get('name');
            if ($name !== 'core') {
                $extras[] = $name;
            }
        }
        sort($extras);

        // An advisory whose evidence the Manager gates says so in requires.
        // The collected list is cached for every token alike, so the filter
        // runs here, per caller, and the key is stripped before answering.
        $collector  = new AdvisoryCollector($modx);
        $advisories = [];
        foreach ($collector->collect(!empty($arguments['refresh'])) as $advisory) {
            $needs = $advisory['requires'] ?? null;
            unset($advisory['requires']);
            if ($needs !== null && !$modx->hasPermission((string) $needs)) {
                $withheld['advisories'][(string) ($advisory['id'] ?? '')] = (string) $needs;
                continue;
            }
            $advisories[] = $advisory;
        }

        // warnings stays a string[] and keeps carrying everything, because it is
        // the shape existing callers read. advisories is the machine-usable form
        // and goes alongside rather than replacing it.
        $warnings = $collector->summaries($advisories);
        foreach (self::WARNINGS as $namespace => $warning) {
            if (in_array($namespace, $extras, true)) {
                $warnings[] = $warning;
            }
        }

        return [
            'modx_version' => $this->modxVersion($modx),
            // Version plus a hash of the running code. Two builds of the same
            // version are otherwise indistinguishable, and MODX will silently
            // install a stale one; see MODXMCP\Build.
            'modxmcp'      => Build::describe(),
            'php_version'  => PHP_VERSION,
            'site_name'    => $modx->getOption('site_name'),
            'site_url'     => $modx->getOption('site_url'),
            'site_status'  => (int) $modx->getOption('site_status') === 1 ? 'online' : 'offline',
            'contexts'     => $contexts,
            'templates'    => $templates,
            'extras'       => $extras,
            'extras_count' => count($extras),
            'warnings'     => $warnings,
            'advisories'   => $advisories,
            'notes'        => $this->operatorNotes($modx),
            'bound_user'   => $modx->user ? $modx->user->get('username') : null,
            // What the tools will ask MODX for, answered for this caller, so an
            // agent can tell before its first refusal which calls will refuse.
            'permissions'  => $this->permissionMap($modx, $extras),
            // Proof, on every call, that the session contract holds.
            //
            // modx_state must read 1 (INITIALIZED): that is the only state in
            // which MODX evaluates access policies, and Server refuses a
            // non-sudo token in any other. It stays 1 after the session is torn
            // down, because getSessionState() never re-checks that value, which
            // is exactly what keeps the policies evaluating. cookieless is the
            // other half: an empty PHP session id and no Set-Cookie on the
            // response are what show no session survived.
            'session' => [
                'cookieless' => session_id() === '',
                'php_sid'    => session_id(),
                'modx_state' => $modx->getSessionState(),
            ],
        ] + ($withheld !== [] ? ['withheld' => $withheld] : []);
    }

    /**
     * The bound user's answer for every permission a tool asks for by name.
     *
     * The processor-backed write tools ask the processor itself, so their
     * names are MODX's and not listed here; delete_document is, because
     * resource_delete has to ask it explicitly.
     *
     * @param string[] $extras
     * @return array<string,bool>
     */
    private function permissionMap(modX $modx, array $extras): array
    {
        $names = [
            'view', 'view_document', 'view_chunk', 'view_snippet', 'view_template', 'view_tv',
            'view_plugin', 'source_view', 'packages', 'empty_cache', 'delete_document',
        ];
        if (in_array('seosuite', $extras, true)) {
            $names[] = 'seosuite';
        }

        $map = [];
        foreach ($names as $name) {
            $map[$name] = $modx->hasPermission($name);
        }

        return $map;
    }

    /**
     * Free-text notes the site's operator left for whoever connects.
     *
     * Local convention that nothing can be detected from: which parent new
     * articles go under, that deploys are live immediately, whatever the person
     * running the site would otherwise have to repeat every session.
     *
     * Capped, because this is on the first call of every connection and an
     * operator pasting a wiki page into it would tax every session's context
     * with nobody noticing why. Not parsed: prose stays prose, and the path for
     * machine-readable rules is OnMCPCollectAdvisories.
     *
     * @return array<string,mixed>|null
     */
    private function operatorNotes(modX $modx): ?array
    {
        $raw = trim((string) $modx->getOption('modxmcp.site_notes', null, ''));
        if ($raw === '') {
            return null;
        }

        $truncated = strlen($raw) > self::NOTES_LIMIT;

        return [
            'text'      => $truncated ? substr($raw, 0, self::NOTES_LIMIT) : $raw,
            'truncated' => $truncated,
            'source'    => 'system setting modxmcp.site_notes',
            'guidance'  => 'Written by this site\'s operator. Authoritative about local '
                . 'convention, but prose rather than a machine-checkable rule.',
        ];
    }

    /**
     * The MODX version, read the way that actually works.
     *
     * modX::$version is populated lazily by getVersionData(); until something
     * calls it the property is NULL. On a site with several extras it is usually
     * already filled by the time a tool runs, which is why this read looked fine
     * for months. On a leaner install nothing had called it and the orientation
     * call reported the version as "unknown".
     */
    private function modxVersion(modX $modx): string
    {
        $data = $modx->getVersionData();

        return (string) ($data['full_version'] ?? 'unknown');
    }
}
