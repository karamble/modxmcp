<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modContext;
use MODX\Revolution\modNamespace;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modX;
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

        $collector  = new AdvisoryCollector($modx);
        $advisories = $collector->collect(!empty($arguments['refresh']));

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
            'advisories'   => $advisories,
            'notes'        => $this->operatorNotes($modx),
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
}
