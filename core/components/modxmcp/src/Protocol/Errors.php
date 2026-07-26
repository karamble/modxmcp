<?php

namespace MODXMCP\Protocol;

/**
 * JSON-RPC and MCP-allocated error codes, with the HTTP status each must be
 * returned under.
 *
 * The status codes are not incidental. A client distinguishes a modern MCP
 * server from a legacy one by inspecting the body of a 4xx, so returning the
 * wrong pairing breaks version detection rather than merely being untidy.
 */
final class Errors
{
    // Standard JSON-RPC 2.0
    public const PARSE           = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS  = -32602;
    public const INTERNAL        = -32603;

    // Allocated to the MCP specification
    public const HEADER_MISMATCH      = -32020;
    public const UNSUPPORTED_VERSION  = -32022;

    private function __construct()
    {
    }
}
