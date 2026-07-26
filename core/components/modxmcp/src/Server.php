<?php

namespace MODXMCP;

use MODX\Revolution\modX;
use MODXMCP\Audit\AuditLogger;
use MODXMCP\Auth\Authenticator;
use MODXMCP\Auth\TokenService;
use MODXMCP\Model\ModxmcpToken;
use MODXMCP\Protocol\Errors;
use MODXMCP\Protocol\HttpTransport;
use MODXMCP\Protocol\McpException;
use MODXMCP\Protocol\Meta;
use MODXMCP\Protocol\Request;
use MODXMCP\Protocol\V20260728;
use MODXMCP\Registry\ToolRegistry;

/**
 * Request orchestration: preflight, parse, validate, authenticate, dispatch,
 * audit.
 *
 * The ordering is the design. Transport checks run before any JSON is parsed,
 * and protocol validation before authentication, so a malformed or
 * wrong-version request is rejected without touching the database.
 */
final class Server
{
    public const NAME    = 'modxmcp';
    public const VERSION = '0.3.0';

    private modX $modx;
    private HttpTransport $transport;
    private V20260728 $protocol;
    private TokenService $tokens;
    private Authenticator $auth;
    private ToolRegistry $tools;
    private AuditLogger $audit;

    public function __construct(
        modX $modx,
        HttpTransport $transport,
        V20260728 $protocol,
        TokenService $tokens,
        Authenticator $auth,
        ToolRegistry $tools,
        AuditLogger $audit
    ) {
        $this->modx      = $modx;
        $this->transport = $transport;
        $this->protocol  = $protocol;
        $this->tokens    = $tokens;
        $this->auth      = $auth;
        $this->tools     = $tools;
        $this->audit     = $audit;
    }

    public function run(): void
    {
        $startedAt = microtime(true);

        if ($this->transport->rejectedByPreflight()) {
            return;
        }

        $id      = null;
        $request = null;
        $token   = null;

        try {
            if (!$this->modx->getOption('modxmcp.enabled', null, false)) {
                throw McpException::unavailable('modxmcp is disabled');
            }

            $request = Request::fromJson($this->transport->body());
            $id      = $request->id();

            $this->protocol->validate($request, $this->transport);

            $token = $this->tokens->verify(
                $this->modx,
                $this->transport->header('Authorization'),
                $this->clientIp()
            );
            $this->auth->bindUser($this->modx, (int) $token->get('user_id'));

            // This revision defines no client-to-server notifications over
            // Streamable HTTP, but the transport rule stands: an accepted
            // notification is answered 202 with no body.
            if ($request->isNotification()) {
                $this->transport->emit(202, null);
                $this->record($request, $token, true, null, $startedAt);
                return;
            }

            $result = $this->dispatch($request, $token);
            $this->transport->emitResult($id, $result);
            $this->record($request, $token, true, null, $startedAt);
        } catch (McpException $e) {
            $this->transport->emitException($e, $id);
            $this->record($request, $token, false, $e, $startedAt);
        } catch (\Throwable $e) {
            // Never leak internals to a caller that may not be authenticated.
            $this->modx->log(modX::LOG_LEVEL_ERROR,
                'modxmcp: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->transport->emitError(500, $id, Errors::INTERNAL, 'Internal error');
            $this->record($request, $token, false,
                new McpException(500, Errors::INTERNAL, 'Internal error'), $startedAt);
        }
    }

    /**
     * @return array<string,mixed>|\stdClass
     * @throws McpException
     */
    private function dispatch(Request $request, ModxmcpToken $token)
    {
        switch ($request->method()) {
            case 'server/discover':
                return $this->discoverResult();

            case 'ping':
                // Must encode as {}, so it cannot be an array.
                return new \stdClass();

            case 'tools/list':
                return ['tools' => $this->tools->definitions()];

            case 'tools/call':
                $args = $request->param('arguments', []);
                return $this->tools->call(
                    $this->modx,
                    (string) $request->param('name', ''),
                    is_array($args) ? $args : [],
                    $this->tokens->scopesOf($token)
                );

            // Answered rather than 404'd so a probing client gets a clean empty
            // list. Populated in M3 and M5.
            case 'resources/list':
                return ['resources' => []];

            case 'resources/templates/list':
                return ['resourceTemplates' => []];

            case 'prompts/list':
                return ['prompts' => []];

            case 'resources/read':
                throw McpException::invalidParams('Unknown resource: ' . (string) $request->param('uri', ''));

            case 'prompts/get':
                throw McpException::invalidParams('Unknown prompt: ' . (string) $request->param('name', ''));

            default:
                // Unknown method is 404 in this revision: that is how a client
                // tells a modern server from a legacy one not hosting this path.
                throw McpException::methodNotFound($request->method());
        }
    }

    /** @return array<string,mixed> */
    private function discoverResult(): array
    {
        return [
            'resultType'        => 'complete',
            'supportedVersions' => $this->protocol->supportedVersions(),
            'capabilities'      => ['tools' => new \stdClass()],
            '_meta'             => [
                Meta::SERVER_INFO => ['name' => self::NAME, 'version' => self::VERSION],
            ],
            'instructions' => 'Administers a MODX 3.x site. Every write goes through a MODX '
                . 'processor, so Manager-side behaviour (SeoSuite registration, Collections '
                . 'rules, cache invalidation, lifecycle events) fires natively. Call '
                . 'modxmcp_site_info first to learn what this site contains and which '
                . 'extra-specific rules apply to it.',
        ];
    }

    private function record(
        ?Request $request,
        ?ModxmcpToken $token,
        bool $success,
        ?McpException $error,
        float $startedAt
    ): void {
        $this->audit->record($this->modx, [
            'token_id'      => $token ? (int) $token->get('id') : 0,
            'user_id'       => $token ? (int) $token->get('user_id') : 0,
            'ip'            => $this->clientIp(),
            'rpc_method'    => $request ? $request->method() : '',
            'tool'          => $request && $request->method() === 'tools/call'
                ? (string) $request->param('name', '') : null,
            'arguments'     => $request ? $request->param('arguments') : null,
            'success'       => $success,
            'error_code'    => $error ? $error->getCode() : null,
            'error_message' => $error ? $error->getMessage() : null,
            'duration_ms'   => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /**
     * The client address, honouring a proxy header only when the site is
     * configured to trust one. Believing X-Forwarded-For unconditionally would
     * let any caller forge the address recorded in the audit log and defeat a
     * token's IP allowlist.
     */
    private function clientIp(): string
    {
        $trusted = trim((string) $this->modx->getOption('modxmcp.trusted_proxy_header', null, ''));
        if ($trusted !== '') {
            $value = $this->transport->header($trusted);
            if ($value !== null && $value !== '') {
                $first = trim(explode(',', $value)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
