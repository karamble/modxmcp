<?php

namespace MODXMCP\Protocol;

/**
 * One MCP protocol revision.
 *
 * modxmcp is dual-era. 2026-07-28 is the target revision; 2025-11-25 exists
 * because every shipping client still speaks the initialization-based era, and
 * the specification explicitly describes serving both from one endpoint. Which
 * one handles a request is decided per request by how the client opens.
 */
interface ProtocolInterface
{
    /** The revision identifier, e.g. "2026-07-28". */
    public function version(): string;

    /**
     * Every revision this implementation accepts, newest first.
     *
     * @return string[]
     */
    public function supportedVersions(): array;

    /**
     * Enforce every rule the specification requires a server to check before
     * the request is dispatched.
     *
     * @throws McpException when the request must be rejected
     */
    public function validate(Request $request, HttpTransport $transport): void;
}
