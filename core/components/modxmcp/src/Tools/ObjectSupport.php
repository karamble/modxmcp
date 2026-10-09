<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modChunk;
use MODX\Revolution\modPlugin;
use MODX\Revolution\modResource;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modTemplateVar;
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
    /**
     * The Manager permission a curated tool asks before reading rows of $class.
     *
     * Generic access has no MODX permission behind it as such -- xPDO has none
     * -- but resources and elements are not generic. Allowlisting modResource
     * must not make it readable to a user the resource tools would refuse, or
     * the allowlist becomes a second door around the gate those tools apply.
     */
    protected function curatedReadPermission(string $class): ?string
    {
        if (is_a($class, modResource::class, true)) {
            return 'view';
        }
        $elements = [
            modChunk::class       => 'view_chunk',
            modSnippet::class     => 'view_snippet',
            modTemplate::class    => 'view_template',
            modTemplateVar::class => 'view_tv',
            modPlugin::class      => 'view_plugin',
        ];
        foreach ($elements as $elementClass => $permission) {
            if (is_a($class, $elementClass, true)) {
                return $permission;
            }
        }

        return null;
    }

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
     * Comparison operators a caller may use.
     *
     * This is an allowlist because xPDO INTERPOLATES the operator into SQL
     * rather than binding it. Validating only the field name is not enough:
     * a key of "id:) OR 1=1 -- " passes a field check on the "id" half and
     * xPDO then emits
     *
     *     WHERE `x`.`id` ) OR 1=1 --  1
     *
     * which is a working injection. Values are bound and were never the risk;
     * the key always was.
     */
    private const ALLOWED_OPERATORS = [
        '=', '!=', '<>', '>', '>=', '<', '<=',
        'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT',
    ];

    /**
     * Build xPDO criteria from a caller-supplied filter map.
     *
     * Both halves of every key are validated: the field against the class's own
     * columns, the operator against a fixed allowlist. Anything else is
     * rejected outright rather than sanitised, because there is no legitimate
     * caller input that needs escaping here.
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
            $key = (string) $key;

            // Exactly "field" or "field:OPERATOR". Conjunction prefixes such as
            // "OR:" are not offered to callers: they change query semantics and
            // add a third thing to validate for no benefit here.
            $parts = explode(':', $key);
            if (count($parts) > 2) {
                throw McpException::invalidParams(
                    "Malformed filter key '{$key}'. Use \"field\" or \"field:OPERATOR\".");
            }

            $field = $parts[0];
            if (!in_array($field, $known, true)) {
                throw McpException::invalidParams(
                    "'{$field}' is not a field of {$class}. Use modxmcp_schema_describe to see its fields.");
            }

            if (count($parts) === 2) {
                $operator = strtoupper(trim($parts[1]));
                if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
                    throw McpException::invalidParams(sprintf(
                        "'%s' is not an allowed comparison operator. Use one of: %s.",
                        $parts[1],
                        implode(', ', self::ALLOWED_OPERATORS)
                    ));
                }
                // Rebuild from the validated parts so nothing of the caller's
                // original string survives into the query.
                $criteria[$field . ':' . $operator] = $value;
                continue;
            }

            $criteria[$field] = $value;
        }

        return $criteria;
    }
}
