<?php

namespace MODXMCP\Tools;

use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\ToolInterface;

/**
 * Base for modxmcp's tools.
 *
 * The important thing here is runProcessor(): it is the single place every write
 * goes through. Writing via a processor rather than saving an xPDO object is the
 * whole premise of this extra, because the Manager's save path is what fires the
 * lifecycle events extras depend on. Bypassing it is what makes resources vanish
 * from sitemaps and Collections listings.
 */
abstract class AbstractTool implements ToolInterface
{
    public function requiredScope(): string
    {
        return 'read';
    }

    /**
     * Run a MODX processor and return its object payload.
     *
     * Processor failures are surfaced with their field errors intact. A model
     * that is told "alias: must be unique" can fix its own call; one told
     * "operation failed" cannot.
     *
     * @param array<string,mixed> $properties
     * @return array<string,mixed>
     * @throws McpException
     */
    protected function runProcessor(modX $modx, string $action, array $properties = []): array
    {
        $response = $modx->runProcessor($action, $properties);
        if (!$response) {
            throw McpException::internal("Processor '{$action}' could not be loaded");
        }

        $result = $response->getResponse();
        if (!is_array($result)) {
            $decoded = json_decode((string) $result, true);
            $result  = is_array($decoded) ? $decoded : [];
        }

        if (empty($result['success'])) {
            throw McpException::invalidParams($this->describeFailure($action, $result));
        }

        return is_array($result['object'] ?? null) ? $result['object'] : $result;
    }

    /**
     * Turn a processor's error structure into one actionable sentence.
     *
     * @param array<string,mixed> $result
     */
    private function describeFailure(string $action, array $result): string
    {
        $parts = [];

        foreach ((array) ($result['errors'] ?? []) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $field   = (string) ($error['id'] ?? $error['field'] ?? '');
            $message = (string) ($error['msg'] ?? $error['message'] ?? '');
            if ($message === '') {
                continue;
            }
            $parts[] = $field !== '' ? "{$field}: {$message}" : $message;
        }

        $top = trim((string) ($result['message'] ?? ''));
        if ($top !== '' && !in_array($top, $parts, true)) {
            array_unshift($parts, $top);
        }

        if ($parts === []) {
            $parts[] = 'the processor reported no detail';
        }

        return "MODX processor '{$action}' rejected the request (" . implode('; ', $parts) . ')';
    }

    /**
     * Trim an object down to the fields worth returning.
     *
     * Resources carry ~40 columns, most of which are noise to a caller and
     * collectively burn a lot of context when several are listed at once.
     *
     * @param array<string,mixed> $row
     * @param string[]            $fields
     * @return array<string,mixed>
     */
    protected function pick(array $row, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $out[$field] = $row[$field];
            }
        }
        return $out;
    }

    /**
     * Read an argument, treating '' and null as absent.
     *
     * @param array<string,mixed> $arguments
     * @return mixed
     */
    protected function arg(array $arguments, string $key, $default = null)
    {
        $value = $arguments[$key] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * @param array<string,mixed> $arguments
     * @throws McpException
     */
    protected function requireArg(array $arguments, string $key)
    {
        $value = $this->arg($arguments, $key);
        if ($value === null) {
            throw McpException::invalidParams("Missing required argument: {$key}");
        }
        return $value;
    }
}
