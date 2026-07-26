<?php

namespace MODXMCP\Registry;

use MODX\Revolution\modX;
use MODXMCP\Protocol\McpException;

/**
 * Holds the tool set and frames their results.
 *
 * Kept deliberately small: the interesting decisions (which tools exist, what
 * they may touch) belong to registration and to the tools themselves.
 */
final class ToolRegistry
{
    /** @var array<string,ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return array<int,array<string,mixed>> */
    public function definitions(): array
    {
        $out = [];
        foreach ($this->tools as $tool) {
            $out[] = $tool->definition();
        }
        return $out;
    }

    /**
     * Invoke a tool and frame the result as a CallToolResult.
     *
     * @param array<string,mixed> $arguments
     * @param string[]            $grantedScopes
     * @return array<string,mixed>
     * @throws McpException
     */
    public function call(modX $modx, string $name, array $arguments, array $grantedScopes): array
    {
        if (!$this->has($name)) {
            // Unknown tool is a routing failure, so it takes the same shape as an
            // unknown method: 404 plus -32601.
            throw McpException::methodNotFound("Unknown tool: {$name}");
        }

        $tool = $this->tools[$name];

        $need = $tool->requiredScope();
        if ($need !== '' && !in_array($need, $grantedScopes, true)) {
            throw McpException::forbidden("Token lacks required scope '{$need}' for tool '{$name}'");
        }

        try {
            $payload = $tool->call($modx, $arguments);
        } catch (McpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // A tool blowing up is a tool-level failure, not a protocol failure:
            // it is reported inside a successful JSON-RPC result with isError.
            return $this->frame(['error' => $e->getMessage()], true);
        }

        return $this->frame($payload, false);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function frame(array $payload, bool $isError): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
            'structuredContent' => $payload,
            'isError'           => $isError,
        ];
    }
}
