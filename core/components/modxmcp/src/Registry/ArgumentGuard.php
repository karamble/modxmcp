<?php

namespace MODXMCP\Registry;

use MODXMCP\Protocol\McpException;

/**
 * Holds call arguments against the inputSchema their tool declares.
 *
 * Every tool publishes a schema and none of them was held to it. The tools cast
 * what they read, and a cast never fails: `(int)` of an array is 1, so
 * resource_update called with `"id": ["x"]` wrote to resource 1 -- on most sites
 * the start page -- and reported success. The schema said integer; nothing
 * checked.
 *
 * Two different answers, on purpose:
 *
 * - A value that cannot be what the schema declares is refused before the tool
 *   runs, so nothing is written. The line is drawn where a cast would invent a
 *   value rather than convert one: an array or an object where a scalar is
 *   declared, and anything but a whole number where an integer is. "12" for an
 *   integer is still accepted, and so is any scalar for a string or a boolean,
 *   because the tools have always read those leniently and a caller relying on
 *   it is doing nothing wrong.
 *
 * - An argument the schema does not name is NOT refused. ElementSupport already
 *   records why -- no existing caller should break on an argument a tool never
 *   claimed to read -- and that holds here too. It is reported instead, as a
 *   warning naming what the tool does accept, which is the part that was
 *   missing: a caller passing `context_key` to resource_create was told nothing,
 *   and the resource went to the default context.
 *
 * Refusals take the tool's own channel, like every other refusal since #3. MCP
 * files input validation under tool execution errors rather than protocol
 * errors, because it is the kind of failure a model can correct and retry.
 *
 * Deliberately not a JSON Schema validator. The schemas here use a small subset
 * (type, properties, items, additionalProperties), and `enum` and `required` are
 * left to the tools, which already answer those with better messages than a
 * generic check could.
 */
final class ArgumentGuard
{
    /**
     * @param array<string,mixed> $schema    the tool's inputSchema
     * @param array<string,mixed> $arguments
     * @return string[] warnings about arguments that were ignored
     * @throws McpException when a value cannot be of the declared type
     */
    public static function check(array $schema, array $arguments): array
    {
        $warnings = [];
        self::object($schema, $arguments, '', $warnings);

        return $warnings;
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<mixed>        $value
     * @param string[]            $warnings
     * @throws McpException
     */
    private static function object(array $schema, array $value, string $path, array &$warnings): void
    {
        // No `properties` at all means a free-form object, such as the items of
        // element_save's `properties` list: nothing to hold the keys against.
        if (!array_key_exists('properties', $schema)) {
            return;
        }

        $properties = $schema['properties'];
        if (!is_array($properties)) {
            // Schema::object() writes an empty stdClass for a tool with no
            // arguments, so that it serialises as {} rather than [].
            $properties = [];
        }

        $open    = ($schema['additionalProperties'] ?? false) === true;
        $unknown = [];

        foreach ($value as $key => $item) {
            $key = (string) $key;
            if (isset($properties[$key]) && is_array($properties[$key])) {
                self::value($properties[$key], $item, self::join($path, $key), $warnings);
            } elseif (!$open) {
                $unknown[] = $key;
            }
        }

        if ($unknown === []) {
            return;
        }

        $quoted = implode(', ', array_map(static fn(string $k) => "'{$k}'", $unknown));
        $where  = $path === '' ? '' : " inside '{$path}'";
        $known  = array_keys($properties);

        $warnings[] = sprintf(
            'Ignored %s %s%s: not an argument this tool reads, so it had no effect. %s',
            count($unknown) === 1 ? 'argument' : 'arguments',
            $quoted,
            $where,
            $known === []
                ? 'This tool takes no arguments.'
                : 'Accepted here: ' . implode(', ', $known) . '.'
        );
    }

    /**
     * @param array<string,mixed> $schema
     * @param mixed               $value
     * @param string[]            $warnings
     * @throws McpException
     */
    private static function value(array $schema, $value, string $path, array &$warnings): void
    {
        // Null has always meant "not given": AbstractTool::arg() reads it as
        // absent, and the tools skip it. Refusing it now would break callers
        // that send explicit nulls for what they leave alone.
        if ($value === null) {
            return;
        }

        $types = (array) ($schema['type'] ?? []);
        if ($types === []) {
            return;
        }

        foreach ($types as $type) {
            if (!self::fits((string) $type, $value)) {
                continue;
            }

            if ($type === 'array' && isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    self::value($schema['items'], $item, "{$path}[{$i}]", $warnings);
                }
            } elseif ($type === 'object') {
                self::object($schema, $value, $path, $warnings);
            }

            return;
        }

        throw McpException::invalidParams(sprintf(
            "Argument '%s' must be %s; got %s. Nothing was written.",
            $path,
            self::describeTypes($types),
            self::describeValue($value)
        ));
    }

    /** @param mixed $value */
    private static function fits(string $type, $value): bool
    {
        switch ($type) {
            case 'integer':
                // A boolean is excluded on purpose: (int) true is 1, the same
                // accident as an array.
                return is_int($value)
                    || (is_float($value) && is_finite($value) && floor($value) === $value)
                    || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
            case 'number':
                return is_int($value) || is_float($value)
                    || (is_string($value) && is_numeric($value));
            case 'string':
            case 'boolean':
                return is_scalar($value);
            case 'array':
                return is_array($value) && array_is_list($value);
            case 'object':
                // json_decode(..., true) gives [] for both {} and [], so an empty
                // array is an empty object as far as anyone can tell.
                return is_array($value) && ($value === [] || !array_is_list($value));
            default:
                return true;
        }
    }

    /** @param string[] $types */
    private static function describeTypes(array $types): string
    {
        $names = array_map(static function ($type): string {
            switch ((string) $type) {
                case 'integer': return 'a whole number';
                case 'number':  return 'a number';
                case 'string':  return 'a string';
                case 'boolean': return 'a boolean';
                case 'array':   return 'a list';
                case 'object':  return 'an object';
                default:        return (string) $type;
            }
        }, $types);

        return implode(' or ', $names);
    }

    /** @param mixed $value */
    private static function describeValue($value): string
    {
        if (is_array($value)) {
            return $value !== [] && !array_is_list($value) ? 'an object' : 'a list';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return 'the string "' . (strlen($value) > 40 ? substr($value, 0, 40) . '...' : $value) . '"';
        }

        return 'the number ' . $value;
    }

    private static function join(string $path, string $key): string
    {
        return $path === '' ? $key : "{$path}.{$key}";
    }
}
