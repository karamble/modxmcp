<?php

namespace MODXMCP\Registry;

use MODXMCP\Protocol\McpException;

/**
 * Checks a tool's arguments against the schema the tool already declares.
 *
 * Every tool published an inputSchema and nothing ever read it back. Two things
 * followed, and both were silent.
 *
 * A key the schema does not name was accepted and ignored. That is not a
 * theoretical tidiness problem: resource_create took `context` while every read
 * returns `context_key`, so a caller reading a resource and reusing its fields
 * to make a sibling passed the name it had just been given, had it dropped, and
 * got a resource in the default context believing it had chosen one.
 *
 * A value of the wrong type was cast rather than refused. `(int)` on a
 * non-empty array is 1 in PHP, and resource id 1 is the site's start page on a
 * stock install, so `"id": ["x"]` edited the home page and reported success.
 * `(int) "12abc"` is 12, which is worse in its way: it lands on a real resource
 * and looks like it worked.
 *
 * The rule here is deliberately not "the JSON type must match exactly". A
 * caller that quotes its numbers is not making the mistake this exists to
 * catch, and refusing "5" would break working callers to no purpose. So a value
 * that means what it says is converted, and a value that cannot mean what the
 * schema asks for is refused:
 *
 *   accepted   5, "5", " 5 ", 5.0 for an integer; "true"/1/0 for a boolean
 *   refused    "5.5", "12abc", "abc", "" and any array or object for an
 *              integer; a bool for an integer, because true would become 1 and
 *              1 is the id this whole check exists to protect
 *
 * Refusals name the accepted keys or values, because a caller that is told only
 * that it is wrong retries the same call, and one that is told what is right
 * fixes it in a step.
 *
 * Required arguments are deliberately not checked here. AbstractTool::requireArg
 * already does that at the point of use, where it knows which of two
 * alternatives was missing -- element_get wants an id or a name, and only the
 * tool knows that.
 */
final class Arguments
{
    /**
     * Validate and normalise, returning the arguments the tool should receive.
     *
     * @param array<string,mixed> $schema    the tool's inputSchema
     * @param array<string,mixed> $arguments as the caller sent them
     * @return array<string,mixed>
     * @throws McpException
     */
    public static function validate(string $tool, array $schema, array $arguments): array
    {
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties) || $properties === []) {
            // A tool that declares no properties is not claiming that anything
            // goes; it is claiming it takes nothing. But there is no list to
            // check against, so leave the arguments alone rather than guess.
            return $arguments;
        }

        $out = [];
        foreach ($arguments as $key => $value) {
            $key = (string) $key;

            // Protocol extensions travel under an underscore and are not the
            // tool's business. Passed through rather than refused, so a client
            // adding metadata cannot break a call.
            if ($key !== '' && $key[0] === '_') {
                $out[$key] = $value;
                continue;
            }

            if (!array_key_exists($key, $properties)) {
                throw McpException::invalidParams(self::unknownKey($tool, $key, $properties));
            }

            $declared = is_array($properties[$key]) ? $properties[$key] : [];
            $out[$key] = self::coerce($tool, $key, $value, $declared);
        }

        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $properties
     */
    private static function unknownKey(string $tool, string $key, array $properties): string
    {
        $accepted = array_keys($properties);
        sort($accepted);

        $message = sprintf("%s does not take an argument called '%s'.", $tool, $key);

        // A near miss is usually a name from the other half of the surface --
        // context_key for context, or a column name where an argument name was
        // wanted -- so say which one was meant rather than only listing all.
        if ($close = self::closest($key, $accepted)) {
            $message .= sprintf(" Did you mean '%s'?", $close);
        }

        return $message . sprintf(
            ' It accepts: %s. Nothing was written.',
            implode(', ', $accepted)
        );
    }

    /**
     * The accepted key a misspelling most likely meant, if any is close enough.
     *
     * @param string[] $accepted
     */
    private static function closest(string $key, array $accepted): ?string
    {
        $best     = null;
        $bestCost = PHP_INT_MAX;
        foreach ($accepted as $candidate) {
            $cost = levenshtein(strtolower($key), strtolower($candidate));
            if ($cost < $bestCost) {
                $bestCost = $cost;
                $best     = $candidate;
            }
        }

        // A third of the name, at most four edits. Beyond that a suggestion is
        // a guess, and a wrong guess is worse than none.
        $limit = min(4, (int) max(1, floor(strlen($key) / 3)));

        return $bestCost <= $limit ? $best : null;
    }

    /**
     * @param array<string,mixed> $declared
     * @return mixed
     * @throws McpException
     */
    private static function coerce(string $tool, string $key, $value, array $declared)
    {
        // An explicit null is "not provided" throughout this extra: arg()
        // already treats null and '' as absent.
        if ($value === null) {
            return null;
        }

        if (isset($declared['enum']) && is_array($declared['enum'])) {
            return self::enumValue($tool, $key, $value, $declared['enum']);
        }

        $type = $declared['type'] ?? null;
        if (!is_string($type)) {
            return $value;
        }

        switch ($type) {
            case 'integer':
            case 'number':
                return self::intValue($tool, $key, $value, $type);
            case 'boolean':
                return self::boolValue($tool, $key, $value);
            case 'string':
                return self::stringValue($tool, $key, $value);
            case 'array':
                return self::arrayValue($tool, $key, $value, $declared);
            case 'object':
                if (!is_array($value)) {
                    throw self::wrongType($tool, $key, $value, 'an object of key and value pairs');
                }
                return $value;
            default:
                return $value;
        }
    }

    /**
     * @param string[] $values
     * @return mixed
     * @throws McpException
     */
    private static function enumValue(string $tool, string $key, $value, array $values)
    {
        if (is_array($value) || is_bool($value)) {
            throw self::wrongType($tool, $key, $value, 'one of ' . implode(', ', $values));
        }

        // Matched without case so a caller is not refused over capitalisation,
        // but the declared spelling is what the tool receives.
        foreach ($values as $candidate) {
            if (strcasecmp((string) $value, (string) $candidate) === 0) {
                return $candidate;
            }
        }

        throw McpException::invalidParams(sprintf(
            '%s does not accept %s for %s. Use one of: %s. Nothing was written.',
            $tool,
            self::describe($value),
            $key,
            implode(', ', $values)
        ));
    }

    /**
     * @return int
     * @throws McpException
     */
    private static function intValue(string $tool, string $key, $value, string $type): int
    {
        // Before the filter, not after: filter_var(true) is 1, and 1 is the id
        // that made this check necessary.
        if (is_bool($value) || is_array($value)) {
            throw self::wrongType($tool, $key, $value, 'a whole number');
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            throw self::wrongType($tool, $key, $value, 'a whole number');
        }

        return $parsed;
    }

    /**
     * @return bool
     * @throws McpException
     */
    private static function boolValue(string $tool, string $key, $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_array($value)) {
            throw self::wrongType($tool, $key, $value, 'true or false');
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw self::wrongType($tool, $key, $value, 'true or false');
        }

        return $parsed;
    }

    /**
     * @return string
     * @throws McpException
     */
    private static function stringValue(string $tool, string $key, $value): string
    {
        // A number is a reasonable thing to send as text. A bool is not: "1" is
        // not a page title, and an array becomes the literal "Array", which is
        // how a resource ends up named after a PHP notice.
        if (is_array($value) || is_bool($value)) {
            throw self::wrongType($tool, $key, $value, 'text');
        }

        return (string) $value;
    }

    /**
     * @param array<string,mixed> $declared
     * @return array<mixed>
     * @throws McpException
     */
    private static function arrayValue(string $tool, string $key, $value, array $declared): array
    {
        if (!is_array($value)) {
            throw self::wrongType($tool, $key, $value, 'a list');
        }

        $items = $declared['items'] ?? null;
        if (!is_array($items)) {
            return $value;
        }

        // Item types are checked only when the item is a scalar. A list of
        // objects -- element_save's default properties -- is the tool's own
        // business, and it already reports what it could not use.
        $itemTypes = $items['type'] ?? null;
        $itemTypes = is_array($itemTypes) ? $itemTypes : [$itemTypes];
        $scalar    = ['string', 'integer', 'number', 'boolean'];
        if (array_intersect($itemTypes, $scalar) === []) {
            return $value;
        }

        foreach ($value as $entry) {
            if (is_array($entry) || $entry === null) {
                throw McpException::invalidParams(sprintf(
                    '%s wants %s to be a list of %s, and one entry is %s. Nothing was written.',
                    $tool,
                    $key,
                    implode(' or ', array_filter($itemTypes, 'is_string')),
                    self::describe($entry)
                ));
            }
        }

        return $value;
    }

    /** @throws McpException */
    private static function wrongType(string $tool, string $key, $value, string $wanted): McpException
    {
        return McpException::invalidParams(sprintf(
            '%s wants %s to be %s, and got %s. Nothing was written.',
            $tool,
            $key,
            $wanted,
            self::describe($value)
        ));
    }

    /**
     * Describe what arrived without quoting it back wholesale.
     *
     * @param mixed $value
     */
    private static function describe($value): string
    {
        if ($value === null) {
            return 'nothing';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return sprintf('a list or object with %d entr%s', count($value), count($value) === 1 ? 'y' : 'ies');
        }
        $text = (string) $value;
        if ($text === '') {
            return 'an empty string';
        }
        if (strlen($text) > 40) {
            $text = substr($text, 0, 37) . '...';
        }

        return sprintf("'%s'", $text);
    }
}
