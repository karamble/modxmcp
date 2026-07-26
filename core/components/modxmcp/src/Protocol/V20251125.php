<?php

namespace MODXMCP\Protocol;

/**
 * MCP revision 2025-11-25, the last of the initialization-based revisions.
 *
 * Exists because every shipping MCP client still speaks this era. The
 * specification anticipates exactly this and describes a dual-era server: a
 * request carrying per-request `_meta` is served under 2026-07-28, an
 * `initialize` request selects these semantics instead.
 *
 * Differences from 2026-07-28 that matter here:
 *
 *  - There is an `initialize` handshake, answered with an InitializeResult,
 *    followed by a `notifications/initialized` notification.
 *  - Protocol version, client identity and capabilities arrive once at
 *    initialize, not in `_meta` on every request.
 *  - The Mcp-Method and Mcp-Name mirror headers do not exist, so there is
 *    nothing to cross-validate.
 *  - Sessions are optional. modxmcp does not assign one: the token identifies
 *    the caller on every request, and a session would add an ambient
 *    credential for no benefit.
 */
final class V20251125 implements ProtocolInterface
{
    public const VERSION = '2025-11-25';

    /**
     * Revisions in this era that a client might announce. Older ones are close
     * enough in shape that refusing them would be unhelpful; the differences
     * are in features modxmcp does not offer anyway.
     *
     * @var string[]
     */
    private const ACCEPTED = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public function version(): string
    {
        return self::VERSION;
    }

    /** @return string[] */
    public function supportedVersions(): array
    {
        return self::ACCEPTED;
    }

    /**
     * Does this request belong to the initialization-based era?
     *
     * Absence of the modern per-request protocol version is the signal, which
     * is what the specification's era model uses.
     */
    public static function claims(Request $request): bool
    {
        return $request->protocolVersion() === null;
    }

    public function validate(Request $request, HttpTransport $transport): void
    {
        // This era mirrors nothing into headers, so there is no header/body
        // agreement to enforce. The MCP-Protocol-Version header appears from
        // 2025-06-18 onward but is advisory here; where it is present and names
        // a version from another era, say so rather than failing obscurely
        // several requests later.
        $header = $transport->header('MCP-Protocol-Version');
        if ($header !== null && $header !== '' && !in_array($header, self::ACCEPTED, true)) {
            if ($header === V20260728::VERSION) {
                throw McpException::headerMismatch(
                    'MCP-Protocol-Version says ' . V20260728::VERSION . ' but the request carries no '
                    . Meta::VERSION . ' in params._meta, which that revision requires.');
            }
            throw McpException::unsupportedVersion($header, $this->supportedVersions());
        }

        if ($request->method() === 'initialize') {
            $announced = $request->param('protocolVersion');
            if (is_string($announced) && $announced !== '' && !in_array($announced, self::ACCEPTED, true)) {
                // Answer with a version we do speak rather than refusing: the
                // client picks from what initialize returns.
                return;
            }
        }
    }

    /**
     * The InitializeResult.
     *
     * @param array<string,mixed> $serverInfo
     * @return array<string,mixed>
     */
    public function initializeResult(Request $request, array $serverInfo, string $instructions): array
    {
        $announced = $request->param('protocolVersion');

        // Echo the client's version when it is one of ours, so it does not have
        // to downgrade; otherwise name the newest we speak in this era.
        $negotiated = (is_string($announced) && in_array($announced, self::ACCEPTED, true))
            ? $announced
            : self::VERSION;

        return [
            'protocolVersion' => $negotiated,
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => $serverInfo,
            'instructions'    => $instructions,
        ];
    }
}
