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

    /** The `resultType` of an ordinary, finished result. */
    public const RESULT_COMPLETE = 'complete';

    /**
     * How long a client may consider a cacheable result fresh.
     *
     * The tool set changes only when an extra is installed or removed, or when
     * a plugin answering OnMCPRegisterTools changes. modxmcp does not advertise
     * listChanged, so this TTL is the client's only freshness signal: too long
     * and a newly installed extra stays invisible until it expires. Five
     * minutes is the specification's own example, and short enough that
     * installing an extra and seeing its tools is not a wait worth noticing.
     */
    public const CACHE_TTL_MS = 300000;

    /**
     * `private` rather than `public`, though definitions() currently ignores
     * the caller's scopes and so returns an identical list to every token.
     *
     * `public` licenses any shared proxy to serve one caller's tool list to
     * another, across authorization contexts. The specification permits a
     * server to filter the list by granted scopes, which is a natural thing for
     * this extra to grow; the day it does, `public` becomes a cross-token leak
     * with nothing at the call site to catch it. `private` costs one extra
     * tools/list per token and cannot fail that way.
     */
    public const CACHE_SCOPE = 'private';

    /**
     * Operations whose complete results MUST carry caching hints.
     *
     * @var array<string,true>
     */
    private const CACHEABLE = [
        'server/discover'          => true,
        'tools/list'               => true,
        'prompts/list'             => true,
        'resources/list'           => true,
        'resources/templates/list' => true,
        'resources/read'           => true,
    ];

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

    /**
     * Every result in this revision carries `resultType`, and a client is
     * entitled to reject one that does not: absence means "complete" only for
     * earlier revisions, so the older reading is not available to us.
     *
     * "complete" is the ordinary case. The other value, "input_required",
     * belongs to multi round-trip requests, which modxmcp does not implement --
     * but a result that already names its own type is left alone, so adding
     * them later needs no change here.
     *
     * Complete results of the listing operations additionally MUST carry
     * caching hints; interim results carry none.
     *
     * @param array<string,mixed>|\stdClass $result
     * @return array<string,mixed>|\stdClass
     */
    public function finalizeResult(Request $request, $result)
    {
        if ($result instanceof \stdClass) {
            if (!isset($result->resultType)) {
                $result->resultType = self::RESULT_COMPLETE;
            }

            return $result;
        }

        if (!is_array($result)) {
            return $result;
        }

        if (!array_key_exists('resultType', $result)) {
            // Prepended rather than appended: the specification's examples lead
            // with it, and a human reading the wire should see it first.
            $result = array_merge(['resultType' => self::RESULT_COMPLETE], $result);
        }

        return $this->withCachingHints($request, $result);
    }

    /**
     * Caching hints, on the operations that require them.
     *
     * modxmcp never paginates -- every list it can produce is small enough to
     * send whole -- so there is no nextCursor to add here, and each result is
     * a single page cached as itself.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function withCachingHints(Request $request, array $result): array
    {
        if (!isset(self::CACHEABLE[$request->method()])) {
            return $result;
        }

        if (($result['resultType'] ?? null) !== self::RESULT_COMPLETE) {
            return $result;
        }

        $result['ttlMs']      = $result['ttlMs'] ?? self::CACHE_TTL_MS;
        $result['cacheScope'] = $result['cacheScope'] ?? self::CACHE_SCOPE;

        return $result;
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
