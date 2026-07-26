<?php

namespace MODXMCP\Protocol;

/**
 * MCP revision 2026-07-28.
 *
 * Stateless: no initialize handshake, no Mcp-Session-Id, no resumable streams.
 * Every request carries its own protocol version, client identity and
 * capabilities in `_meta`, and the Streamable HTTP binding mirrors some of
 * those into headers.
 */
final class V20260728 implements ProtocolInterface
{
    public const VERSION = '2026-07-28';

    /**
     * Methods whose name is mirrored into the Mcp-Name header, and the body
     * field it must equal.
     */
    private const NAME_BEARING = [
        'tools/call'     => 'name',
        'prompts/get'    => 'name',
        'resources/read' => 'uri',
    ];

    public function version(): string
    {
        return self::VERSION;
    }

    /** @return string[] */
    public function supportedVersions(): array
    {
        return [self::VERSION];
    }

    public function validate(Request $request, HttpTransport $transport): void
    {
        $this->rejectLegacyHandshake($request);
        $this->validateProtocolVersion($request, $transport);
        $this->validateMethodHeader($request, $transport);
        $this->validateNameHeader($request, $transport);
    }

    /**
     * A legacy client opens with `initialize`. It has no way to fall forward, so
     * the specification asks a modern-only server to name its supported versions
     * in the error: this message may be the only diagnostic the user ever sees.
     */
    private function rejectLegacyHandshake(Request $request): void
    {
        if ($request->method() === 'initialize') {
            throw new McpException(400, Errors::UNSUPPORTED_VERSION,
                'This server implements MCP ' . self::VERSION . ' only, which has no '
                . 'initialize handshake. Upgrade the client to a revision that sends '
                . 'per-request _meta.',
                ['supported' => $this->supportedVersions()]);
        }
    }

    /**
     * The header and the body must agree, and the version must be one we speak.
     *
     * This is a security control, not bookkeeping: intermediaries route on the
     * header while the server executes the body, so letting the two disagree is
     * a confused-deputy vector.
     */
    private function validateProtocolVersion(Request $request, HttpTransport $transport): void
    {
        $headerVersion = $transport->header('MCP-Protocol-Version');
        if ($headerVersion === null) {
            throw McpException::headerMismatch('Missing required header: MCP-Protocol-Version');
        }

        $bodyVersion = $request->protocolVersion();
        if ($bodyVersion === null) {
            throw McpException::headerMismatch('Missing ' . Meta::VERSION . ' in params._meta');
        }

        if ($headerVersion !== $bodyVersion) {
            throw McpException::headerMismatch(
                "Header mismatch: MCP-Protocol-Version header value '{$headerVersion}' "
                . "does not match body value '{$bodyVersion}'");
        }

        if ($bodyVersion !== self::VERSION) {
            throw McpException::unsupportedVersion($bodyVersion, $this->supportedVersions());
        }
    }

    private function validateMethodHeader(Request $request, HttpTransport $transport): void
    {
        $headerMethod = $transport->header('Mcp-Method');
        if ($headerMethod === null) {
            throw McpException::headerMismatch('Missing required header: Mcp-Method');
        }
        if ($headerMethod !== $request->method()) {
            throw McpException::headerMismatch(
                "Header mismatch: Mcp-Method header value '{$headerMethod}' "
                . "does not match body value '{$request->method()}'");
        }
    }

    private function validateNameHeader(Request $request, HttpTransport $transport): void
    {
        $field = self::NAME_BEARING[$request->method()] ?? null;
        if ($field === null) {
            return;
        }

        $headerName = $transport->header('Mcp-Name');
        if ($headerName === null) {
            throw McpException::headerMismatch('Missing required header: Mcp-Name');
        }

        $bodyValue = $request->param($field);
        if (!is_string($bodyValue) || $bodyValue === '') {
            throw McpException::invalidParams("Missing params.{$field}");
        }

        if ($transport->decodeHeaderValue($headerName) !== $bodyValue) {
            throw McpException::headerMismatch(
                "Header mismatch: Mcp-Name header value does not match body value '{$bodyValue}'");
        }
    }
}
