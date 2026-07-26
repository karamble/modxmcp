<?php
/**
 * End-to-end exercise of the M3 tool surface, over real HTTP through the MCP
 * endpoint.
 *
 * The point is not that each tool returns 200. It is that going through the tool
 * surface still produces the Manager-side side effects the whole design exists
 * for: a resource created here must register with SeoSuite exactly as one
 * created in the Manager would.
 *
 *   php dev/tools-test.php <endpoint> <token>
 *
 * Cleans up everything it creates.
 */

const PROTOCOL = '2026-07-28';

$url   = $argv[1] ?? '';
$token = $argv[2] ?? '';
if ($url === '' || $token === '') {
    exit("usage: php dev/tools-test.php <endpoint> <token>\n");
}

$pass = 0;
$fail = 0;

function call(string $url, string $token, string $tool, array $args = []): array
{
    $payload = [
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'tools/call',
        'params'  => [
            'name'      => $tool,
            'arguments' => $args === [] ? new stdClass() : $args,
            '_meta'     => [
                'io.modelcontextprotocol/protocolVersion' => PROTOCOL,
                'io.modelcontextprotocol/clientInfo'      => ['name' => 'modxmcp-tools-test', 'version' => '1'],
            ],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            'Authorization: Bearer ' . $token,
            'MCP-Protocol-Version: ' . PROTOCOL,
            'Mcp-Method: tools/call',
            'Mcp-Name: ' . $tool,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = json_decode((string) $raw, true);
    return [
        'status'  => $status,
        'result'  => $body['result']['structuredContent'] ?? null,
        'isError' => $body['result']['isError'] ?? null,
        'error'   => $body['error'] ?? null,
        'raw'     => $raw,
    ];
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-54s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

echo "M3 tool surface\n" . str_repeat('=', 80) . "\n";

// --- discovery ---------------------------------------------------------------
$listPayload = [
    'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
    'params'  => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => PROTOCOL]],
];
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($listPayload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json', 'Authorization: Bearer ' . $token,
        'MCP-Protocol-Version: ' . PROTOCOL, 'Mcp-Method: tools/list',
    ],
]);
$tools = json_decode((string) curl_exec($ch), true)['result']['tools'] ?? [];
curl_close($ch);

$names = array_column($tools, 'name');
check('tools/list exposes the M3 surface', count($names) >= 11, count($names) . ' tools');
foreach (['modxmcp_site_info', 'modxmcp_resource_create', 'modxmcp_resource_update',
          'modxmcp_element_save', 'modxmcp_cache_refresh'] as $expected) {
    check("  registered: {$expected}", in_array($expected, $names, true));
}

$missingDesc = array_filter($tools, fn($t) => strlen($t['description'] ?? '') < 40);
check('every tool has a substantive description', $missingDesc === []);

// --- resource lifecycle ------------------------------------------------------
$alias = 'modxmcp-tooltest-' . substr(md5((string) mt_rand()), 0, 8);

$created = call($url, $token, 'modxmcp_resource_create', [
    'pagetitle' => 'modxmcp tool test',
    'alias'     => $alias,
    'content'   => '<p>created through the MCP tool surface</p>',
    'published' => true,
]);
check('resource_create succeeds', $created['status'] === 200 && empty($created['isError']),
    $created['error']['message'] ?? '');

$newId = (int) ($created['result']['id'] ?? 0);
check('resource_create returns an id', $newId > 0, "id {$newId}");

$got = call($url, $token, 'modxmcp_resource_get', ['id' => $newId]);
check('resource_get returns the content',
    strpos((string) ($got['result']['content'] ?? ''), 'through the MCP tool surface') !== false);

$updated = call($url, $token, 'modxmcp_resource_update', [
    'id'        => $newId,
    'longtitle' => 'set by update',
]);
check('resource_update succeeds', $updated['status'] === 200 && empty($updated['isError']),
    $updated['error']['message'] ?? '');

$reread = call($url, $token, 'modxmcp_resource_get', ['id' => $newId]);
check('update applied the changed field', ($reread['result']['longtitle'] ?? '') === 'set by update');
check('update preserved the untouched field',
    strpos((string) ($reread['result']['content'] ?? ''), 'through the MCP tool surface') !== false,
    'partial update must not blank content');

$aliasChange = call($url, $token, 'modxmcp_resource_update', [
    'id'    => $newId,
    'alias' => $alias . '-moved',
]);
$warnings = $aliasChange['result']['warnings'] ?? [];
check('alias change warns about the missing redirect',
    (bool) array_filter($warnings, fn($w) => stripos($w, 'redirect') !== false));

$listed = call($url, $token, 'modxmcp_resource_list', ['search' => 'modxmcp tool test']);
check('resource_list finds it', (int) ($listed['result']['total'] ?? 0) >= 1);

// --- elements ----------------------------------------------------------------
$chunkName = 'modxmcpToolTestChunk';

$chunk = call($url, $token, 'modxmcp_element_save', [
    'type'    => 'chunk',
    'name'    => $chunkName,
    'content' => '<p>chunk body</p>',
]);
check('element_save creates a chunk', $chunk['status'] === 200 && !empty($chunk['result']['created']),
    $chunk['error']['message'] ?? '');

$chunkAgain = call($url, $token, 'modxmcp_element_save', [
    'type'    => 'chunk',
    'name'    => $chunkName,
    'content' => '<p>chunk body v2</p>',
]);
check('element_save updates rather than duplicates',
    $chunkAgain['status'] === 200 && ($chunkAgain['result']['created'] ?? true) === false);

$chunkGet = call($url, $token, 'modxmcp_element_get', ['type' => 'chunk', 'name' => $chunkName]);
check('element_get returns the updated body',
    strpos((string) ($chunkGet['result']['content'] ?? ''), 'v2') !== false);

// Regression: modElement::toArray() emits a virtual "content" alongside the real
// column, and if it survives it overwrites the update while the processor still
// reports success. The guard must not overcorrect either: for templates the real
// column IS "content", so a metadata-only update must not blank the body.
call($url, $token, 'modxmcp_element_save', [
    'type' => 'chunk', 'name' => $chunkName, 'description' => 'metadata only',
]);
$afterMeta = call($url, $token, 'modxmcp_element_get', ['type' => 'chunk', 'name' => $chunkName]);
check('metadata-only save preserves a chunk body',
    strpos((string) ($afterMeta['result']['content'] ?? ''), 'v2') !== false);

$tplName = 'modxmcpToolTestTemplate';
call($url, $token, 'modxmcp_element_save', [
    'type' => 'template', 'name' => $tplName, 'content' => '<html>TPL_BODY</html>',
]);
call($url, $token, 'modxmcp_element_save', [
    'type' => 'template', 'name' => $tplName, 'description' => 'metadata only',
]);
$tplAfter = call($url, $token, 'modxmcp_element_get', ['type' => 'template', 'name' => $tplName]);
check('metadata-only save preserves a template body',
    strpos((string) ($tplAfter['result']['content'] ?? ''), 'TPL_BODY') !== false,
    'content IS the real column for templates');
call($url, $token, 'modxmcp_element_delete', ['type' => 'template', 'name' => $tplName]);

$badType = call($url, $token, 'modxmcp_element_list', ['type' => 'nonsense']);
check('unknown element type is rejected clearly',
    $badType['status'] !== 200 || !empty($badType['isError']) || !empty($badType['error']));

// --- error quality -----------------------------------------------------------
$dupe = call($url, $token, 'modxmcp_resource_create', [
    'pagetitle' => 'duplicate alias probe',
    'alias'     => $alias . '-moved',
    'published' => true,
]);
$msg = $dupe['error']['message'] ?? ($dupe['result']['error'] ?? '');
check('processor field errors reach the caller',
    stripos((string) $msg, 'alias') !== false, substr((string) $msg, 0, 60));

// --- cleanup -----------------------------------------------------------------
if ($newId > 0) {
    $deleted = call($url, $token, 'modxmcp_resource_delete', ['id' => $newId]);
    check('resource_delete succeeds', $deleted['status'] === 200 && empty($deleted['isError']));
    check('delete reports it is recoverable', ($deleted['result']['recoverable'] ?? null) === true);
}
$chunkDeleted = call($url, $token, 'modxmcp_element_delete', ['type' => 'chunk', 'name' => $chunkName]);
check('element_delete succeeds', $chunkDeleted['status'] === 200 && empty($chunkDeleted['isError']));
check('element delete reports it is NOT recoverable',
    ($chunkDeleted['result']['recoverable'] ?? null) === false);

// Anything created by the duplicate-alias probe would be a bug, but sweep anyway.
$stray = call($url, $token, 'modxmcp_resource_list', ['search' => 'duplicate alias probe']);
foreach ($stray['result']['resources'] ?? [] as $row) {
    call($url, $token, 'modxmcp_resource_delete', ['id' => (int) $row['id']]);
    echo "  swept stray resource {$row['id']}\n";
}

echo str_repeat('=', 80) . "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
