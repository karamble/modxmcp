<?php

namespace MODXMCP\Registry;

/**
 * Small helpers for building JSON Schema fragments.
 *
 * Schemas are written by hand rather than derived from docblocks. The
 * description of a parameter is what the model reads to decide what to put in
 * it, so it is content, not metadata, and generating it from types would throw
 * away the only part that matters.
 */
final class Schema
{
    /**
     * @param array<string,array<string,mixed>> $properties
     * @param string[]                          $required
     * @return array<string,mixed>
     */
    public static function object(array $properties, array $required = []): array
    {
        return [
            'type'       => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'required'   => array_values($required),
            // Declared, not merely enforced at run time. A client that
            // validates arguments against the schema then refuses a misspelt
            // key before a request exists, which is a better place to catch it
            // than the server. map() sets this true for the opposite reason:
            // its keys belong to the site, not to us.
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    public static function string(string $description, ?string $default = null): array
    {
        $s = ['type' => 'string', 'description' => $description];
        if ($default !== null) {
            $s['default'] = $default;
        }
        return $s;
    }

    /** @return array<string,mixed> */
    public static function integer(string $description, ?int $default = null): array
    {
        $s = ['type' => 'integer', 'description' => $description];
        if ($default !== null) {
            $s['default'] = $default;
        }
        return $s;
    }

    /** @return array<string,mixed> */
    public static function boolean(string $description, ?bool $default = null): array
    {
        $s = ['type' => 'boolean', 'description' => $description];
        if ($default !== null) {
            $s['default'] = $default;
        }
        return $s;
    }

    /**
     * @param string[] $values
     * @return array<string,mixed>
     */
    public static function enum(string $description, array $values, ?string $default = null): array
    {
        $s = ['type' => 'string', 'description' => $description, 'enum' => array_values($values)];
        if ($default !== null) {
            $s['default'] = $default;
        }
        return $s;
    }

    /**
     * @param array<string,mixed> $items
     * @return array<string,mixed>
     */
    public static function arrayOf(string $description, array $items): array
    {
        return ['type' => 'array', 'description' => $description, 'items' => $items];
    }

    /**
     * A free-form key/value map, for things like template variable values whose
     * keys are defined by the site rather than by us.
     *
     * @return array<string,mixed>
     */
    public static function map(string $description): array
    {
        return ['type' => 'object', 'description' => $description, 'additionalProperties' => true];
    }
}
