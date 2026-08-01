<?php
/**
 * Minimal post-rollout smoke test for modxmcp_file_upload. Non-destructive:
 * lists tools, uploads one 1x1 PNG, fetches it back over HTTP.
 *
 *   php dev/upload-smoke.php <endpoint> <token> <dir>
 *   php dev/upload-smoke.php https://example.com/mcp-x.html TOKEN assets/tmp/
 */
const PROTOCOL = '2026-07-28';

[$self, $url, $token, $dir] = $argv + [null, null, null, null];
if (!$url || !$token || !$dir) {
    fwrite(STDERR, "usage: php dev/upload-smoke.php <endpoint> <token> <dir>\n");
    exit(2);
}

function rpc(string $url, string $token, string $method, array $params): array
{
    $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params + [
        '_meta' => ['io.modelcontextprotocol/protocolVersion' => PROTOCOL,
                    'io.modelcontextprotocol/clientInfo' => ['name' => 'upload-smoke', 'version' => '1']],
    ]];
    $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream',
        'Authorization: Bearer ' . $token, 'MCP-Protocol-Version: ' . PROTOCOL,
        'Mcp-Method: ' . $method];
    if ($method === 'tools/call') {
        $headers[] = 'Mcp-Name: ' . $params['name'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode((string) $raw, true) ?: [];
}

$list  = rpc($url, $token, 'tools/list', []);
$names = array_column($list['result']['tools'] ?? [], 'name');
printf("[%s] tools/list includes modxmcp_file_upload (%d tools)\n",
    in_array('modxmcp_file_upload', $names, true) ? 'PASS' : 'FAIL', count($names));

$png  = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$name = 'modxmcp-smoke-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.png';
$up   = rpc($url, $token, 'tools/call', ['name' => 'modxmcp_file_upload', 'arguments' => [
    'path' => $dir, 'filename' => $name, 'content_base64' => base64_encode($png),
]]);
$res = $up['result']['structuredContent'] ?? [];
$err = $up['error']['message'] ?? ($up['result']['isError'] ?? null ? 'tool error' : '');
printf("[%s] upload %s%s %s\n", ($res['uploaded'] ?? false) ? 'PASS' : 'FAIL', $dir, $name, $err);

if (($res['url'] ?? '') !== '') {
    $fetched = @file_get_contents($res['url']);
    printf("[%s] HTTP round-trip via %s\n", $fetched === $png ? 'PASS' : 'FAIL', $res['url']);
    echo "NOTE: delete {$res['path']} when convenient; there is no remove tool.\n";
}
