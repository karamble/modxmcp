<?php
/**
 * M4: discovery and generic object access.
 *
 * The security invariant matters most here. Generic access reaches classes that
 * no MODX permission check covers, so the tests below deliberately try to get at
 * credentials and modxmcp's own tokens with the allowlist opened to "*", and
 * expect to be refused anyway.
 *
 *   php dev/discovery-test.php <endpoint> <token>
 */

const PROTOCOL = '2026-07-28';

$url   = $argv[1] ?? '';
$token = $argv[2] ?? '';
if ($url === '' || $token === '') {
    exit("usage: php dev/discovery-test.php <endpoint> <token>\n");
}

$pass = 0;
$fail = 0;

function call(string $url, string $token, string $tool, array $args = []): array
{
    $payload = [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params'  => [
            'name' => $tool,
            'arguments' => $args === [] ? new stdClass() : $args,
            '_meta' => ['io.modelcontextprotocol/protocolVersion' => PROTOCOL],
        ],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json', 'Authorization: Bearer ' . $token,
            'MCP-Protocol-Version: ' . PROTOCOL, 'Mcp-Method: tools/call', 'Mcp-Name: ' . $tool,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode((string) $raw, true);
    return [
        'status' => $status,
        'result' => $body['result']['structuredContent'] ?? null,
        'error'  => $body['error']['message'] ?? ($body['result']['structuredContent']['error'] ?? null),
        'isError' => $body['result']['isError'] ?? null,
    ];
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

echo "M4 discovery and generic access\n" . str_repeat('=', 84) . "\n";

// --- discovery ---------------------------------------------------------------
$list = call($url, $token, 'modxmcp_schema_list');
check('schema_list works without any allowlist', $list['status'] === 200 && empty($list['isError']),
    $list['error'] ?? '');

$extras = array_keys($list['result']['extras'] ?? []);
check('discovery finds classes from multiple extras', count($extras) >= 2,
    implode(', ', array_slice($extras, 0, 6)));

$classes = [];
foreach ($list['result']['extras'] ?? [] as $rows) {
    foreach ($rows as $row) {
        $classes[] = $row['class'];
    }
}
check('discovery finds a PSR-4 extra model (SeoSuite)',
    (bool) array_filter($classes, fn($c) => stripos($c, 'SeoSuite') !== false));
check('hard-blocked classes are counted, not listed',
    (int) ($list['result']['hard_blocked'] ?? 0) > 0,
    ($list['result']['hard_blocked'] ?? 0) . ' blocked');
check('no user or session class is ever listed',
    !array_filter($classes, fn($c) => preg_match('/mod(User|Session|Access)/', $c)));
check('modxmcp own tables are never listed',
    !array_filter($classes, fn($c) => stripos($c, 'MODXMCP') !== false));

// --- describe ----------------------------------------------------------------
$target = null;
foreach ($classes as $c) {
    if (stripos($c, 'SeoSuiteRedirect') !== false) { $target = $c; break; }
}
$target = $target ?: ($classes[0] ?? '');

$desc = call($url, $token, 'modxmcp_schema_describe', ['class' => $target]);
check('schema_describe works without an allowlist', $desc['status'] === 200 && empty($desc['isError']),
    $target);
check('describe returns typed fields', !empty($desc['result']['fields']),
    count($desc['result']['fields'] ?? []) . ' fields');
check('describe reports the table name', !empty($desc['result']['table']),
    (string) ($desc['result']['table'] ?? ''));

$blockedDesc = call($url, $token, 'modxmcp_schema_describe', ['class' => 'MODX\\Revolution\\modUser']);
check('describe refuses a hard-blocked class',
    $blockedDesc['status'] !== 200 || !empty($blockedDesc['isError']) || !empty($blockedDesc['error']));

$phase = $argv[3] ?? 'closed';

if ($phase === 'closed') {
    // Only meaningful while the allowlist is empty: with it open, reads are
    // permitted and there is correctly nothing to explain.
    check('describe explains why reads are denied',
        !empty($desc['result']['access']['read_note']));

    // --- default deny --------------------------------------------------------
    $denied = call($url, $token, 'modxmcp_object_list', ['class' => $target]);
    check('object_list denied by default', !empty($denied['error']) || !empty($denied['isError']));
    check('denial names the setting to change',
        stripos((string) ($denied['error'] ?? ''), 'read_class_allowlist') !== false);

    $write = call($url, $token, 'modxmcp_object_save', [
        'class' => $target, 'values' => ['old_url' => '/probe'],
    ]);
    check('object_save denied by default', !empty($write['error']) || !empty($write['isError']));
} else {
    // --- allowlist wide open: the invariants must still hold ------------------
    echo "  allowlist is '*' for this phase\n";

    $open = call($url, $token, 'modxmcp_object_list', ['class' => $target, 'limit' => 3]);
    check('allowlisting a class enables reads',
        $open['status'] === 200 && empty($open['isError']), $open['error'] ?? '');

    // The point of the whole guard: wildcard must not reach credentials.
    foreach ([
        'MODX\\Revolution\\modUser',
        'MODX\\Revolution\\modUserProfile',
        'MODX\\Revolution\\modSession',
        'MODX\\Revolution\\modAccessContext',
        'MODXMCP\\Model\\ModxmcpToken',
        'MODXMCP\\Model\\ModxmcpAudit',
    ] as $forbidden) {
        $r = call($url, $token, 'modxmcp_object_list', ['class' => $forbidden, 'limit' => 1]);
        $blocked = !empty($r['error']) || !empty($r['isError']);
        $leaked  = !empty($r['result']['objects']);
        check("wildcard cannot read {$forbidden}", $blocked && !$leaked);

        $w = call($url, $token, 'modxmcp_object_save', [
            'class' => $forbidden, 'values' => ['id' => 1],
        ]);
        check("wildcard cannot write {$forbidden}", !empty($w['error']) || !empty($w['isError']));

        $d = call($url, $token, 'modxmcp_object_delete', [
            'class' => $forbidden, 'pk' => '1', 'confirm' => true,
        ]);
        check("wildcard cannot delete {$forbidden}", !empty($d['error']) || !empty($d['isError']));
    }

    // Delete must refuse without explicit confirmation.
    $noConfirm = call($url, $token, 'modxmcp_object_delete', ['class' => $target, 'pk' => '999999']);
    check('delete refuses without confirm=true',
        stripos((string) ($noConfirm['error'] ?? ''), 'confirm') !== false);

    // Unknown fields must be rejected rather than silently ignored.
    $badField = call($url, $token, 'modxmcp_object_list', [
        'class' => $target, 'filters' => ['definitely_not_a_column' => 1],
    ]);
    check('unknown filter field is rejected',
        stripos((string) ($badField['error'] ?? ''), 'not a field') !== false);

    $badWrite = call($url, $token, 'modxmcp_object_save', [
        'class' => $target, 'values' => ['definitely_not_a_column' => 1],
    ]);
    check('unknown write field is rejected',
        stripos((string) ($badWrite['error'] ?? ''), 'not a field') !== false);
}

echo str_repeat('=', 84) . "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
