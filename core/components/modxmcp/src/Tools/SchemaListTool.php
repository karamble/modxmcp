<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Discovery\ClassGuard;
use MODXMCP\Discovery\PackageScanner;
use MODXMCP\Registry\Schema;

/**
 * Every data class any installed extra defines.
 *
 * Metadata only, so this needs no allowlist: knowing that a class exists is not
 * the same as being able to read what is in it, and a caller that cannot
 * discover the shape of the site cannot tell the user what to enable.
 */
final class SchemaListTool extends AbstractTool
{
    public function name(): string
    {
        return 'modxmcp_schema_list';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'List data classes',
            'description' => 'List the xPDO data classes defined by MODX and by every installed '
                . 'extra, with whether generic read and write access is currently permitted for '
                . 'each. Use this to find where an extra keeps its data, then '
                . 'modxmcp_schema_describe for the fields of one class. Prefer the dedicated '
                . 'resource and element tools when they cover what you need: they write through '
                . 'MODX processors, which generic object access cannot do.',
            'inputSchema' => Schema::object([
                'extra'         => Schema::string('Only classes from this extra, by namespace name, e.g. "seosuite".'),
                'search'        => Schema::string('Match against the class name.'),
                'accessible_only' => Schema::boolean('Only classes generic reads are permitted for.', false),
            ]),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $scanner = new PackageScanner($modx);
        $guard   = new ClassGuard($modx);

        $extra  = $this->arg($arguments, 'extra');
        $search = $this->arg($arguments, 'search');
        $only   = !empty($arguments['accessible_only']);

        $byExtra    = [];
        $blocked      = 0;
        $readable     = 0;
        $totalShown   = 0;
        $mediaBlocked = false;

        foreach ($scanner->classes() as $class => $entry) {
            if ($extra !== null && $entry['extra'] !== $extra) {
                continue;
            }
            if ($search !== null && stripos($class, (string) $search) === false) {
                continue;
            }

            $hardBlocked = $guard->isHardBlocked($class);
            $canRead     = $guard->canRead($class);

            if ($hardBlocked) {
                $blocked++;
                // Only worth pointing at the media source tool when a media
                // source is actually among what was withheld. Advertising it
                // beside a blocked modUser would be noise.
                $mediaBlocked = $mediaBlocked || $guard->isMediaSource($class);
                // Named but not detailed, so a caller understands why a class it
                // expected to see is absent rather than assuming it is missing.
                continue;
            }
            if ($only && !$canRead) {
                continue;
            }
            if ($canRead) {
                $readable++;
            }

            $byExtra[$entry['extra']][] = [
                'class' => $class,
                'read'  => $canRead,
                'write' => $guard->canWrite($class),
            ];
            $totalShown++;
        }

        ksort($byExtra);

        return [
            'extras'            => $byExtra,
            'shown'             => $totalShown,
            'readable'          => $readable,
            'hard_blocked'      => $blocked,
            'note'              => $this->note(
                $totalShown,
                $readable,
                $blocked,
                $extra !== null || $search !== null || $only,
                $mediaBlocked
            ),
        ];
    }

    /**
     * The one line telling a caller what to do about what it just got back.
     *
     * Gated on `$readable === 0 && $totalShown > 0` before, which dropped the
     * note in the case that needs it most. When every discovered class is hard
     * blocked nothing is shown, so the response was an unexplained
     * {"shown":0,"readable":0,"hard_blocked":2,"note":null} with no way to tell
     * "this site defines nothing" from "you may see none of it".
     */
    private function note(
        int $shown,
        int $readable,
        int $blocked,
        bool $filtered,
        bool $mediaBlocked = false
    ): ?string
    {
        // Blocked before filtered, and the order is load-bearing. A search that
        // matches only hard-blocked classes is not a search that matched
        // nothing: "Nothing matched" would send the caller off to widen a
        // filter that was working correctly, when the real answer is that the
        // classes exist and are withheld.
        if ($shown === 0 && $blocked > 0) {
            return sprintf(
                'Nothing to show: all %d %s permanently blocked by modxmcp. They hold '
                . 'credentials, sessions, access-control rules, system settings, media sources, '
                . 'or modxmcp\'s own tables. No setting can enable them, and this is not the '
                . 'same as nothing having matched.%s',
                $blocked,
                $filtered ? 'matching class(es) are' : 'discovered class(es) are',
                $mediaBlocked
                    ? ' Media sources are readable through modxmcp_media_source_list, which'
                      . ' omits their credentials.'
                    : ''
            );
        }

        if ($shown === 0 && $filtered) {
            return 'Nothing matched. Drop the extra, search and accessible_only arguments to '
                . 'see everything this site defines.';
        }

        if ($shown === 0) {
            return 'No model classes were discovered on this site. If extras are installed, '
                . 'their namespaces may not be registered; clearing the MODX cache and '
                . 'retrying is the usual fix.';
        }

        if ($readable === 0) {
            return 'No class is currently readable through generic object access. It is opt-in '
                . 'per class via the modxmcp.read_class_allowlist system setting, because xPDO '
                . 'has no permission model of its own. The dedicated resource and element '
                . 'tools work regardless.';
        }

        return null;
    }
}
