<?php
/**
 * modxmcp - MCP endpoint for MODX 3.x
 *
 * Streamable HTTP transport, protocol revision 2026-07-28.
 * Zero third-party dependencies by design: this file and the classes it loads
 * must never pull anything into MODX's shared autoload space.
 *
 * M0 SPIKE. Auth reads a deployed config file; the real token model, scopes,
 * audit log and tool registry land in M1/M2. Structure here is the seed of
 * core/components/modxmcp/src/Protocol.
 */

// ---------------------------------------------------------------- constants

const MCP_PROTOCOL_VERSION = '2026-07-28';
const MCP_SERVER_NAME      = 'modxmcp';
const MCP_SERVER_VERSION   = '0.1.0-spike';

const MCP_META_VERSION      = 'io.modelcontextprotocol/protocolVersion';
const MCP_META_CLIENT_INFO  = 'io.modelcontextprotocol/clientInfo';
const MCP_META_CLIENT_CAPS  = 'io.modelcontextprotocol/clientCapabilities';
const MCP_META_SERVER_INFO  = 'io.modelcontextprotocol/serverInfo';

// JSON-RPC + MCP-allocated error codes
const MCP_ERR_PARSE            = -32700;
const MCP_ERR_INVALID_REQUEST  = -32600;
const MCP_ERR_METHOD_NOT_FOUND = -32601;
const MCP_ERR_INVALID_PARAMS   = -32602;
const MCP_ERR_INTERNAL         = -32603;
const MCP_ERR_HEADER_MISMATCH  = -32020;
const MCP_ERR_UNSUPPORTED_VER  = -32022;

// -------------------------------------------------------------- primitives

/**
 * Emit a JSON-RPC response and stop.
 *
 * We always answer application/json. The spec permits a JSON object or an SSE
 * stream per request; we never stream, which lets us skip subscriptions and
 * long-lived connections entirely (PHP-FPM handles those badly).
 */
function mcp_send(int $status, ?array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if ($payload !== null) {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function mcp_error(int $status, $id, int $code, string $message, ?array $data = null): void
{
    $err = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $err['data'] = $data;
    }
    mcp_send($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err]);
}

/**
 * @param array|stdClass $result Some results are empty JSON objects (ping),
 *                               which must encode as {} and so cannot be arrays.
 */
function mcp_result($id, $result): void
{
    mcp_send(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}

/** Case-insensitive header lookup over $_SERVER. */
function mcp_header(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? (string)$_SERVER[$key] : null;
}

/**
 * Decode the spec's base64 sentinel form: =?base64?<payload>?=
 * Servers MUST decode before comparing a header to its body value.
 */
function mcp_decode_header_value(string $value): string
{
    if (strlen($value) > 11 && str_starts_with($value, '=?base64?') && str_ends_with($value, '?=')) {
        $decoded = base64_decode(substr($value, 9, -2), true);
        if ($decoded !== false) {
            return $decoded;
        }
    }
    return $value;
}

// ------------------------------------------------------ transport preflight

// POST only. Older revisions used GET for a standalone SSE stream and DELETE
// to terminate a session; this revision has neither.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    header('Allow: POST');
    mcp_send(405, null);
}

// Origin validation is mandatory: it is the defence against DNS rebinding.
// A browser sends Origin; a legitimate MCP client normally does not.
$origin = mcp_header('Origin');
if ($origin !== null && $origin !== '') {
    $host       = $_SERVER['HTTP_HOST'] ?? '';
    $originHost = parse_url($origin, PHP_URL_HOST) ?: '';
    if ($originHost === '' || strcasecmp($originHost, (string)strtok($host, ':')) !== 0) {
        mcp_send(403, [
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => ['code' => MCP_ERR_INVALID_REQUEST, 'message' => 'Origin not allowed'],
        ]);
    }
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    mcp_error(400, null, MCP_ERR_PARSE, 'Parse error');
}

$id       = $body['id'] ?? null;
$rpcMeth  = isset($body['method']) ? (string)$body['method'] : '';
$params   = isset($body['params']) && is_array($body['params']) ? $body['params'] : [];
$meta     = isset($params['_meta']) && is_array($params['_meta']) ? $params['_meta'] : [];
$isNotify = !array_key_exists('id', $body);

if (($body['jsonrpc'] ?? '') !== '2.0' || $rpcMeth === '') {
    mcp_error(400, $id, MCP_ERR_INVALID_REQUEST, 'Invalid Request');
}

// A legacy client will POST `initialize`. It has no fall-forward path, so the
// spec asks modern-only servers to name their versions in the error: this may
// be the only diagnostic the user ever sees.
if ($rpcMeth === 'initialize') {
    mcp_error(400, $id, MCP_ERR_UNSUPPORTED_VER, 'This server implements MCP ' . MCP_PROTOCOL_VERSION
        . ' only, which has no initialize handshake. Upgrade the client.', [
        'supported' => [MCP_PROTOCOL_VERSION],
    ]);
}

// ---------------------------------------------- header/body cross-validation
// Not a formality. Intermediaries route on headers while the server executes
// the body; letting them disagree is a real confused-deputy vector.

$hVersion = mcp_header('MCP-Protocol-Version');
$hMethod  = mcp_header('Mcp-Method');
$hName    = mcp_header('Mcp-Name');

if ($hVersion === null) {
    mcp_error(400, $id, MCP_ERR_HEADER_MISMATCH, 'Missing required header: MCP-Protocol-Version');
}
if ($hMethod === null) {
    mcp_error(400, $id, MCP_ERR_HEADER_MISMATCH, 'Missing required header: Mcp-Method');
}
if ($hMethod !== $rpcMeth) {
    mcp_error(400, $id, MCP_ERR_HEADER_MISMATCH,
        "Header mismatch: Mcp-Method header value '{$hMethod}' does not match body value '{$rpcMeth}'");
}

$bodyVersion = isset($meta[MCP_META_VERSION]) ? (string)$meta[MCP_META_VERSION] : null;
if ($bodyVersion === null) {
    mcp_error(400, $id, MCP_ERR_HEADER_MISMATCH, 'Missing ' . MCP_META_VERSION . ' in params._meta');
}
if ($hVersion !== $bodyVersion) {
    mcp_error(400, $id, MCP_ERR_HEADER_MISMATCH,
        "Header mismatch: MCP-Protocol-Version header value '{$hVersion}' does not match body value '{$bodyVersion}'");
}
if ($bodyVersion !== MCP_PROTOCOL_VERSION) {
    mcp_error(400, $id, MCP_ERR_UNSUPPORTED_VER, 'Unsupported protocol version', [
        'supported' => [MCP_PROTOCOL_VERSION],
        'requested' => $bodyVersion,
    ]);
}

// Mcp-Name is required for the three name-bearing methods.
$nameBearing = ['tools/call' => 'name', 'prompts/get' => 'name', 'resources/read' => 'uri'];
if (isset($nameBearing[$rpcMeth])) {
    $field     = $nameBearing[$rpcMeth];
    $bodyValue = isset($params[$field]) ? (string)$params[$field] : null;
    if ($hName === null) {
        mcp_error(400, $id, MCP_ERR_HEADER_MISMATCH, 'Missing required header: Mcp-Name');
    }
    if ($bodyValue === null) {
        mcp_error(400, $id, MCP_ERR_INVALID_PARAMS, "Missing params.{$field}");
    }
    if (mcp_decode_header_value($hName) !== $bodyValue) {
        mcp_error(400, $id, MCP_ERR_HEADER_MISMATCH,
            "Header mismatch: Mcp-Name header value does not match body value '{$bodyValue}'");
    }
}

// ------------------------------------------------------------ authentication

$configFile = __DIR__ . '/modxmcp.config.php';
if (!is_readable($configFile)) {
    mcp_error(503, $id, MCP_ERR_INTERNAL, 'modxmcp is not configured');
}
$config = require $configFile;

if (empty($config['enabled'])) {
    mcp_error(503, $id, MCP_ERR_INTERNAL, 'modxmcp is disabled');
}

$auth  = mcp_header('Authorization') ?? '';
$token = (stripos($auth, 'Bearer ') === 0) ? substr($auth, 7) : '';
if ($token === '' || empty($config['token_hash'])
    || !password_verify($token, (string)$config['token_hash'])) {
    header('WWW-Authenticate: Bearer');
    mcp_error(401, $id, MCP_ERR_INVALID_REQUEST, 'Unauthorized');
}

// -------------------------------------------------------------- MODX bootstrap

// $_SESSION must exist as a plain array BEFORE modX initializes. MODX then
// reports SESSION_STATE_EXTERNAL and never calls session_start(), so no cookie
// is set and nothing is persisted. Never use modUser::addSessionContext() here:
// that is the login path and it regenerates the session id and increments
// logincount/lastlogin on the user's profile on every single call.
$_SESSION = [];

define('MODX_API_MODE', true);

// Walk up for config.core.php rather than assuming a fixed depth. Keeps the
// endpoint location-independent, which matters because the conventional spot
// (assets/components/<ns>/) is PHP-denied on hardened installs.
$configCore = null;
for ($dir = __DIR__, $i = 0; $i < 6; $i++) {
    if (is_readable($dir . '/config.core.php')) {
        $configCore = $dir . '/config.core.php';
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}
if ($configCore === null) {
    mcp_error(503, $id, MCP_ERR_INTERNAL, 'Could not locate MODX config.core.php');
}
require_once $configCore;
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
// MODX 3 is PSR-4 autoloaded. This is core's own autoloader, not a bundled one:
// modxmcp adds nothing to the shared autoload space.
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = new \MODX\Revolution\modX();
$modx->initialize('mgr');
$modx->setLogLevel(\MODX\Revolution\modX::LOG_LEVEL_ERROR);

$user = $modx->getObject(\MODX\Revolution\modUser::class, (int)$config['user_id']);
if (!$user || !$user->get('active')) {
    mcp_error(403, $id, MCP_ERR_INTERNAL, 'Bound MODX user is missing or inactive');
}
$modx->user = $user;
// Loads access attributes for the principal_targets system setting. Session-free.
$modx->user->getAttributes([], 'mgr', true);

// ------------------------------------------------------------------ dispatch

// This revision defines no client-to-server notifications over Streamable HTTP,
// but the transport rule still stands: accepted notification => 202, no body.
if ($isNotify) {
    mcp_send(202, null);
}

switch ($rpcMeth) {
    case 'server/discover':
        mcp_result($id, [
            'resultType'        => 'complete',
            'supportedVersions' => [MCP_PROTOCOL_VERSION],
            'capabilities'      => ['tools' => new stdClass()],
            '_meta'             => [
                MCP_META_SERVER_INFO => [
                    'name'    => MCP_SERVER_NAME,
                    'version' => MCP_SERVER_VERSION,
                ],
            ],
            'instructions' => 'Administers a MODX 3.x site. All writes go through MODX '
                . 'processors so Manager-side behaviour (SeoSuite registration, Collections '
                . 'rules, cache invalidation) fires natively.',
        ]);
        // no break: mcp_result exits

    case 'ping':
        mcp_result($id, new stdClass());

    case 'tools/list':
        mcp_result($id, ['tools' => [modxmcp_site_info_definition()]]);

    case 'tools/call':
        $toolName = (string)$params['name'];
        if ($toolName !== 'modxmcp_site_info') {
            mcp_error(404, $id, MCP_ERR_METHOD_NOT_FOUND, "Unknown tool: {$toolName}");
        }
        try {
            $payload = modxmcp_site_info($modx);
            mcp_result($id, [
                'content'           => [['type' => 'text', 'text' => json_encode($payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
                'structuredContent' => $payload,
                'isError'           => false,
            ]);
        } catch (\Throwable $e) {
            mcp_error(200, $id, MCP_ERR_INTERNAL, 'Tool execution failed: ' . $e->getMessage());
        }
        // no break: both branches exit

    default:
        // Unknown method is 404 in this revision, which is how a client tells a
        // modern server apart from a legacy one that does not host this endpoint.
        mcp_error(404, $id, MCP_ERR_METHOD_NOT_FOUND, "Method not found: {$rpcMeth}");
}

// ----------------------------------------------------------------- the tool

function modxmcp_site_info_definition(): array
{
    return [
        'name'        => 'modxmcp_site_info',
        'title'       => 'MODX site information',
        'description' => 'Orientation call. Returns MODX version, contexts, templates, '
            . 'installed extras and any active behavioural warnings for this site. '
            . 'Call this first when you do not yet know what the site contains.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => new stdClass(),
            'required'   => [],
        ],
    ];
}

function modxmcp_site_info(\MODX\Revolution\modX $modx): array
{
    $contexts = [];
    foreach ($modx->getIterator(\MODX\Revolution\modContext::class) as $ctx) {
        $contexts[] = $ctx->get('key');
    }

    $templates = [];
    foreach ($modx->getIterator(\MODX\Revolution\modTemplate::class) as $tpl) {
        $templates[] = ['id' => (int)$tpl->get('id'), 'name' => $tpl->get('templatename')];
    }

    $extras   = [];
    $warnings = [];
    foreach ($modx->getIterator(\MODX\Revolution\modNamespace::class) as $ns) {
        $name = $ns->get('name');
        if ($name === 'core') {
            continue;
        }
        $extras[] = $name;
    }

    // Behavioural warnings the model cannot infer from any schema. These are the
    // rules that silently break content when ignored.
    if (in_array('collections', $extras, true)) {
        $warnings[] = 'Collections is installed: resources created under a Collections '
            . 'container must have show_in_tree=0 and a real menuindex, or they vanish '
            . 'from listings.';
    }
    if (in_array('seosuite', $extras, true)) {
        $warnings[] = 'SeoSuite is installed: it inner-joins its own tables, so a resource '
            . 'saved outside the Manager save path is silently absent from sitemap.xml. '
            . 'Always write via processor-backed tools.';
    }

    return [
        'modx_version'   => $modx->version['full_version'] ?? 'unknown',
        'php_version'    => PHP_VERSION,
        'site_name'      => $modx->getOption('site_name'),
        'site_url'       => $modx->getOption('site_url'),
        'contexts'       => $contexts,
        'templates'      => $templates,
        'extras'         => $extras,
        'extras_count'   => count($extras),
        'warnings'       => $warnings,
        'bound_user'     => $modx->user->get('username'),
        'session_state'  => $modx->getSessionState(),
        'php_session_id' => session_id(),
    ];
}
