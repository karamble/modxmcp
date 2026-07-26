<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Discovery\ClassGuard;
use MODXMCP\Discovery\PackageScanner;
use MODXMCP\Discovery\SchemaMapper;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Schema;

/**
 * Full field detail for one discovered class.
 */
final class SchemaDescribeTool extends AbstractTool
{
    public function name(): string
    {
        return 'modxmcp_schema_describe';
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => 'Describe a data class',
            'description' => 'Fields, types, defaults and relations for one xPDO class, with '
                . 'descriptions taken from the extra\'s own lexicon where it has them. Read this '
                . 'before using the generic object tools, so you know what the fields mean and '
                . 'which are required.',
            'inputSchema' => Schema::object([
                'class' => Schema::string('Fully qualified class name, as returned by modxmcp_schema_list.'),
            ], ['class']),
        ];
    }

    public function call(modX $modx, array $arguments): array
    {
        $class   = (string) $this->requireArg($arguments, 'class');
        $scanner = new PackageScanner($modx);
        $guard   = new ClassGuard($modx);

        $classes = $scanner->classes();
        if (!isset($classes[$class])) {
            throw McpException::invalidParams(
                "Unknown class '{$class}'. Use modxmcp_schema_list to see what this site defines.");
        }

        if ($guard->isHardBlocked($class)) {
            throw McpException::forbidden($guard->explainDenial($class, 'read'));
        }

        $described = (new SchemaMapper($modx))->describe($class, $classes[$class]);

        $described['access'] = [
            'read'  => $guard->canRead($class),
            'write' => $guard->canWrite($class),
        ];
        if (!$described['access']['read']) {
            $described['access']['read_note'] = $guard->explainDenial($class, 'read');
        }
        if (!$described['access']['write']) {
            $described['access']['write_note'] = $guard->explainDenial($class, 'write');
        }

        return $described;
    }
}
