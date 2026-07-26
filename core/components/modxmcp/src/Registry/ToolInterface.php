<?php

namespace MODXMCP\Registry;

use MODX\Revolution\modX;

/**
 * One MCP tool.
 *
 * Tools return plain PHP data. Wrapping it in the CallToolResult envelope is the
 * registry's job, so a tool never has to know how results are framed and the
 * envelope can follow the spec without touching every tool.
 */
interface ToolInterface
{
    /** Stable, unique tool name as exposed over the wire. */
    public function name(): string;

    /**
     * The MCP tool definition: name, title, description, inputSchema.
     *
     * The description is what the model reads to decide whether to call this,
     * so it should say when to use the tool, not merely what it does.
     *
     * @return array<string,mixed>
     */
    public function definition(): array;

    /**
     * Scope a token must hold to invoke this tool, e.g. "read", "write:content".
     * Checked in addition to the bound MODX user's own permissions, never
     * instead of them.
     */
    public function requiredScope(): string;

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed> structured payload
     */
    public function call(modX $modx, array $arguments): array;
}
