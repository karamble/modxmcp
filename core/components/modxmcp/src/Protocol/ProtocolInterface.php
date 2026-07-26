<?php

namespace MODXMCP\Protocol;

/**
 * One MCP protocol revision.
 *
 * modxmcp currently implements 2026-07-28 only. The seam exists because the
 * specification defines a fallback path for the older initialization-based
 * revisions: if clients lag behind the RC, supporting 2025-11-25 becomes a
 * second implementation of this interface rather than a refactor of the server.
 */
interface ProtocolInterface
{
    /** The revision identifier, e.g. "2026-07-28". */
    public function version(): string;

    /**
     * Enforce every rule the specification requires a server to check before
     * the request is dispatched.
     *
     * @throws McpException when the request must be rejected
     */
    public function validate(Request $request, HttpTransport $transport): void;
}
