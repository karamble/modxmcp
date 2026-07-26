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
        $blocked    = 0;
        $readable   = 0;
        $totalShown = 0;

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
            'note' => $readable === 0 && $totalShown > 0
                ? 'No class is currently readable through generic object access. It is opt-in per '
                  . 'class via the modxmcp.read_class_allowlist system setting, because xPDO has '
                  . 'no permission model of its own. The dedicated resource and element tools '
                  . 'work regardless.'
                : null,
        ];
    }
}
