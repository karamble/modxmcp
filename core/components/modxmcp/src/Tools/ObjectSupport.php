<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Discovery\ClassGuard;
use MODXMCP\Discovery\PackageScanner;
use MODXMCP\Protocol\McpException;

/**
 * Shared gating for the generic object tools.
 *
 * Every entry point here goes through the guard. There is deliberately no path
 * that reaches xPDO with a caller-supplied class name without one, because for
 * arbitrary classes the guard is the only access control there is.
 */
trait ObjectSupport
{
    /**
     * Resolve a caller-supplied class name and confirm the operation is allowed.
     *
     * @throws McpException
     */
    protected function guardedClass(modX $modx, string $class, string $operation): string
    {
        $scanner = new PackageScanner($modx);
        $guard   = new ClassGuard($modx);

        $classes = $scanner->classes();
        if (!isset($classes[$class])) {
            throw McpException::invalidParams(
                "Unknown class '{$class}'. Use modxmcp_schema_list to see what this site defines.");
        }

        $permitted = $operation === 'write' ? $guard->canWrite($class) : $guard->canRead($class);
        if (!$permitted) {
            throw McpException::forbidden($guard->explainDenial($class, $operation));
        }

        return $class;
    }

    /**
     * Redact a row and note what was masked.
     *
     * The note matters: silently removing a value would leave a caller
     * believing the field is empty and, worse, liable to write that emptiness
     * back on a later update.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function redactRow(modX $modx, array $row): array
    {
        $result = (new ClassGuard($modx))->redact($row);
        if ($result['redacted'] !== []) {
            $result['row']['_redacted_fields'] = $result['redacted'];
        }
        return $result['row'];
    }

    /**
     * Build xPDO criteria from a caller-supplied filter map.
     *
     * Values are passed as bound criteria, never interpolated. Field names are
     * checked against the class's own field list so an operator suffix cannot
     * smuggle in something that is not a column.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     * @throws McpException
     */
    protected function buildCriteria(modX $modx, string $class, array $filters): array
    {
        if ($filters === []) {
            return [];
        }

        $known    = array_keys($modx->getFieldMeta($class) ?: []);
        $criteria = [];

        foreach ($filters as $key => $value) {
            // Accept "field" and "field:OPERATOR", validating the field half.
            $field = explode(':', (string) $key, 2)[0];
            if (!in_array($field, $known, true)) {
                throw McpException::invalidParams(
                    "'{$field}' is not a field of {$class}. Use modxmcp_schema_describe to see its fields.");
            }
            $criteria[(string) $key] = $value;
        }

        return $criteria;
    }
}
