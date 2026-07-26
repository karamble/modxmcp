<?php
/**
 * Minimal MCP client speaking Streamable HTTP revision 2026-07-28.
 *
 * No shipping MCP client implements this revision yet, so this is how modxmcp
 * gets exercised end to end.
 *
 * Usage:
 *   php dev/mcp-client.php <endpoint> <token> discover
 *   php dev/mcp-client.php <endpoint> <token> list
 *   php dev/mcp-client.php <endpoint> <token> call <tool> ['{"json":"args"}']
 *   php dev/mcp-client.php <endpoint> <token> conformance
 */

const PROTOCOL = '2026-07-28';
const META_VER = 'io.modelcontextprotocol/protocolVersion';

/**
 * Send one JSON-RPC request.
 *
 * $overrides lets conformance tests deliberately break the header/body contract:
 *   headers      => extra or replacement headers
 *   dropHeaders  => header names to omit
 *   httpMethod   => override POST
 */
function mcp_call(string $url, string $token, string $method, array $params = [], array $overrides = []): array
{
    $params['_meta'] = ($params['_meta'] ?? []) + [
        META_VER                                     => $overrides['bodyVersion'] ?? PROTOCOL,
        'io.modelcontextprotocol/clientInfo'         => ['name' => 'modxmcp-dev-client', 'version' => '0.1.0'],
        'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
    ];

    $payload = ['jsonrpc' => '2.0', 'id' => $overrides['id'] ?? 1, 'method' => $method, 'params' => $params];
    if (array_key_exists('notification', $overrides)) {
        unset($payload['id']);
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
        'Authorization: Bearer ' . $token,
        'MCP-Protocol-Version: ' . ($overrides['headerVersion'] ?? PROTOCOL),
        'Mcp-Method: ' . ($overrides['headerMethod'] ?? $method),
    ];

    // Mcp-Name is required for the name-bearing methods.
    $nameField = ['tools/call' => 'name', 'prompts/get' => 'name', 'resources/read' => 'uri'][$method] ?? null;
    if ($nameField !== null && isset($params[$nameField])) {
        $headers[] = 'Mcp-Name: ' . ($overrides['headerName'] ?? $params[$nameField]);
    }

    foreach ($overrides['headers'] ?? [] as $h) {
        $headers[] = $h;
    }
    if (!empty($overrides['dropHeaders'])) {
        $headers = array_values(array_filter($headers, function ($h) use ($overrides) {
            foreach ($overrides['dropHeaders'] as $drop) {
                if (stripos($h, $drop . ':') === 0) {
                    return false;
                }
            }
            return true;
        }));
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $overrides['httpMethod'] ?? 'POST',
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => json_decode((string)$raw, true), 'raw' => $raw, 'curl_error' => $err];
}

function show(string $label, array $r): void
{
    echo "\n--- {$label}\nHTTP {$r['status']}\n";
    if ($r['curl_error']) {
        echo "curl: {$r['curl_error']}\n";
    }
    echo (is_array($r['body']) ? json_encode($r['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $r['raw']) . "\n";
}

/** Assert a conformance expectation. */
function expect(string $label, array $r, int $wantStatus, ?int $wantCode = null): bool
{
    $gotCode = $r['body']['error']['code'] ?? null;
    $ok      = $r['status'] === $wantStatus && ($wantCode === null || $gotCode === $wantCode);
    printf("  [%s] %-46s want %d/%-6s got %d/%s\n",
        $ok ? 'PASS' : 'FAIL', $label, $wantStatus, $wantCode ?? '-', $r['status'], $gotCode ?? '-');
    return $ok;
}

// ------------------------------------------------------------------- driver

$url   = $argv[1] ?? '';
$token = $argv[2] ?? '';
$cmd   = $argv[3] ?? 'discover';

if ($url === '' || $token === '') {
    exit("usage: php mcp-client.php <endpoint> <token> <discover|list|call|conformance> [tool] [json-args]\n");
}

switch ($cmd) {
    case 'discover':
        show('server/discover', mcp_call($url, $token, 'server/discover'));
        break;

    case 'list':
        show('tools/list', mcp_call($url, $token, 'tools/list'));
        break;

    case 'call':
        $tool = $argv[4] ?? '';
        $args = json_decode($argv[5] ?? '{}', true) ?: new stdClass();
        show("tools/call {$tool}", mcp_call($url, $token, 'tools/call', ['name' => $tool, 'arguments' => $args]));
        break;

    case 'conformance':
        echo "Conformance suite - MCP {$url}\n" . str_repeat('=', 78) . "\n";
        $pass = 0;
        $total = 0;

        $checks = [
            // happy paths
            ['server/discover succeeds', fn() => mcp_call($url, $token, 'server/discover'), 200, null],
            ['tools/list succeeds', fn() => mcp_call($url, $token, 'tools/list'), 200, null],
            ['ping succeeds', fn() => mcp_call($url, $token, 'ping'), 200, null],

            // transport preflight
            ['GET rejected', fn() => mcp_call($url, $token, 'ping', [], ['httpMethod' => 'GET']), 405, null],
            ['DELETE rejected', fn() => mcp_call($url, $token, 'ping', [], ['httpMethod' => 'DELETE']), 405, null],
            ['bad Origin rejected', fn() => mcp_call($url, $token, 'ping', [], ['headers' => ['Origin: https://evil.example']]), 403, null],

            // header/body cross-validation
            ['missing MCP-Protocol-Version', fn() => mcp_call($url, $token, 'ping', [], ['dropHeaders' => ['MCP-Protocol-Version']]), 400, -32020],
            ['missing Mcp-Method', fn() => mcp_call($url, $token, 'ping', [], ['dropHeaders' => ['Mcp-Method']]), 400, -32020],
            ['Mcp-Method disagrees with body', fn() => mcp_call($url, $token, 'ping', [], ['headerMethod' => 'tools/list']), 400, -32020],
            ['version header disagrees with body', fn() => mcp_call($url, $token, 'ping', [], ['headerVersion' => '2025-11-25']), 400, -32020],
            ['Mcp-Name disagrees with body', fn() => mcp_call($url, $token, 'tools/call', ['name' => 'modxmcp_site_info', 'arguments' => new stdClass()], ['headerName' => 'other_tool']), 400, -32020],

            // versioning
            ['unsupported version rejected', fn() => mcp_call($url, $token, 'ping', [], ['headerVersion' => '1900-01-01', 'bodyVersion' => '1900-01-01']), 400, -32022],
            ['legacy initialize gets named versions', fn() => mcp_call($url, $token, 'initialize'), 400, -32022],

            // routing + auth
            ['unknown method is 404', fn() => mcp_call($url, $token, 'does/notexist'), 404, -32601],
            ['unknown tool is 404', fn() => mcp_call($url, $token, 'tools/call', ['name' => 'nope', 'arguments' => new stdClass()]), 404, -32601],
            ['bad token rejected', fn() => mcp_call($url, 'wrong-token', 'ping'), 401, null],

            // notifications
            ['notification returns 202', fn() => mcp_call($url, $token, 'ping', [], ['notification' => true]), 202, null],
        ];

        foreach ($checks as [$label, $fn, $wantStatus, $wantCode]) {
            $total++;
            if (expect($label, $fn(), $wantStatus, $wantCode)) {
                $pass++;
            }
        }

        echo str_repeat('=', 78) . "\n{$pass}/{$total} passed\n";
        exit($pass === $total ? 0 : 1);

    default:
        exit("unknown command: {$cmd}\n");
}
