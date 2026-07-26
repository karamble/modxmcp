<?php

namespace MODXMCP\Protocol;

/**
 * The `_meta` key namespace defined by the specification.
 *
 * From revision 2026-07-28 there is no initialize handshake: protocol version,
 * client identity and client capabilities travel in `_meta` on every single
 * request, and the Streamable HTTP binding mirrors some of them into headers.
 */
final class Meta
{
    public const VERSION      = 'io.modelcontextprotocol/protocolVersion';
    public const CLIENT_INFO  = 'io.modelcontextprotocol/clientInfo';
    public const CLIENT_CAPS  = 'io.modelcontextprotocol/clientCapabilities';
    public const SERVER_INFO  = 'io.modelcontextprotocol/serverInfo';

    private function __construct()
    {
    }
}
