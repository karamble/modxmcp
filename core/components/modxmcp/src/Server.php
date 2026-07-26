<?php

namespace MODXMCP;

use MODX\Revolution\modX;
use MODXMCP\Auth\Authenticator;
use MODXMCP\Protocol\Errors;
use MODXMCP\Protocol\HttpTransport;
use MODXMCP\Protocol\McpException;
use MODXMCP\Protocol\Meta;
use MODXMCP\Protocol\Request;
use MODXMCP\Protocol\V20260728;
use MODXMCP\Registry\ToolRegistry;

/**
 * Request orchestration: preflight, parse, validate, authenticate, dispatch.
 *
 * The ordering is the design. Transport checks run before any JSON is parsed,
 * protocol validation before authentication, and authentication before MODX is
 * bootstrapped, so an unauthenticated caller never costs a full CMS init.
 */
final class Server
{
    public const NAME    = 'modxmcp';
    public const VERSION = '0.2.0';

    private HttpTransport $transport;
    private V20260728 $protocol;
    private Authenticator $auth;
    private ToolRegistry $tools;

    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        HttpTransport $transport,
        V20260728 $protocol,
        Authenticator $auth,
        ToolRegistry $tools,
        array $config
    ) {
        $this->transport = $transport;
        $this->protocol  = $protocol;
        $this->auth      = $auth;
        $this->tools     = $tools;
        $this->config    = $config;
    }

    public function run(): void
    {
        if ($this->transport->rejectedByPreflight()) {
            return;
        }

        $id = null;
        try {
            $request = Request::fromJson($this->transport->body());
            $id      = $request->id();

            $this->protocol->validate($request, $this->transport);
            $this->auth->verifyToken($this->transport->header('Authorization'), $this->config);

            $modx = Bootstrap::modx();
            $this->auth->bindUser($modx, (int) ($this->config['user_id'] ?? 0));

            // This revision defines no client-to-server notifications over
            // Streamable HTTP, but the transport rule still applies: an accepted
            // notification is answered 202 with no body.
            if ($request->isNotification()) {
                $this->transport->emit(202, null);
                return;
            }

            $this->dispatch($modx, $request);
        } catch (McpException $e) {
            $this->transport->emitException($e, $id);
        } catch (\Throwable $e) {
            // Never leak internals to an unauthenticated or semi-trusted caller.
            error_log('modxmcp: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->transport->emitError(500, $id, Errors::INTERNAL, 'Internal error');
        }
    }

    /**
     * @throws McpException
     */
    private function dispatch(modX $modx, Request $request): void
    {
        switch ($request->method()) {
            case 'server/discover':
                $this->transport->emitResult($request->id(), $this->discoverResult());
                return;

            case 'ping':
                // Must encode as {}, so it cannot be an array.
                $this->transport->emitResult($request->id(), new \stdClass());
                return;

            case 'tools/list':
                $this->transport->emitResult($request->id(), ['tools' => $this->tools->definitions()]);
                return;

            case 'tools/call':
                $name = (string) $request->param('name', '');
                $args = $request->param('arguments', []);
                $result = $this->tools->call(
                    $modx,
                    $name,
                    is_array($args) ? $args : [],
                    $this->grantedScopes()
                );
                $this->transport->emitResult($request->id(), $result);
                return;

            // Declared so clients that probe them get a clean empty answer rather
            // than a 404. Populated in M3 and M5.
            case 'resources/list':
                $this->transport->emitResult($request->id(), ['resources' => []]);
                return;

            case 'resources/templates/list':
                $this->transport->emitResult($request->id(), ['resourceTemplates' => []]);
                return;

            case 'prompts/list':
                $this->transport->emitResult($request->id(), ['prompts' => []]);
                return;

            case 'resources/read':
                throw McpException::invalidParams(
                    'Unknown resource: ' . (string) $request->param('uri', ''));

            case 'prompts/get':
                throw McpException::invalidParams(
                    'Unknown prompt: ' . (string) $request->param('name', ''));

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

    /** @return string[] */
    private function grantedScopes(): array
    {
        $scopes = $this->config['scopes'] ?? ['read'];
        return is_array($scopes) ? array_values(array_map('strval', $scopes)) : ['read'];
    }
}
