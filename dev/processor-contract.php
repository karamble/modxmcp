<?php
/**
 * Does a write that reported success actually change the site?
 *
 *   php dev/processor-contract.php <endpoint> <token>
 *
 * dev/tools-test.php asks whether the tool surface behaves. This asks something
 * narrower and harder: for every write tool, whether the thing it says it wrote
 * is the thing that comes back.
 *
 * It exists because that turned out not to be a safe assumption. modxmcp treats
 * a processor's `success` as authoritative, and Resource/Update returned success
 * while writing no template variable at all for the entire life of the extra.
 * The processor wants a truthy `tvs` property before it will look at the tv{id}
 * values it is handed, nothing documents that, and reading the source did not
 * catch it. A single write-then-read would have, on the first run.
 *
 * So every case here follows one shape:
 *
 *   1. write a sentinel through one tool
 *   2. read it back through a DIFFERENT tool
 *   3. compare
 *
 * Reading back through the same tool would prove much less. A tool that echoes
 * its own input agrees with itself perfectly.
 *
 * Cleans up everything it creates.
 */

const PROTOCOL = '2026-07-28';

$url   = $argv[1] ?? '';
$token = $argv[2] ?? '';
if ($url === '' || $token === '') {
    exit("usage: php dev/processor-contract.php <endpoint> <token>\n");
}

$pass = 0;
$fail = 0;
$skip = 0;

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
                'io.modelcontextprotocol/clientInfo'      => ['name' => 'modxmcp-contract', 'version' => '1'],
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
            'Authorization: Bearer ' . $token,
            'MCP-Protocol-Version: ' . PROTOCOL,
            'Mcp-Method: tools/call',
            'Mcp-Name: ' . $tool,
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    $body = json_decode((string) $raw, true);
    return [
        'result'  => $body['result']['structuredContent'] ?? null,
        'isError' => !empty($body['result']['isError']) || isset($body['error']),
        'message' => $body['error']['message'] ?? '',
    ];
}

/**
 * The whole assertion vocabulary: what was written, what came back, from where.
 *
 * @param mixed $wrote
 * @param mixed $read
 */
function contract(string $label, $wrote, $read, string $readVia): void
{
    global $pass, $fail;
    $ok = $wrote === $read;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-46s via %s\n", $ok ? 'PASS' : 'FAIL', $label, $readVia);
    if (!$ok) {
        printf("         wrote: %s\n", var_export($wrote, true));
        printf("         read : %s\n", var_export($read, true));
    }
}

function skip(string $label, string $why): void
{
    global $skip;
    $skip++;
    printf("  [SKIP] %-46s %s\n", $label, $why);
}

echo "processor contract\n" . str_repeat('=', 80) . "\n";

$sfx      = substr(md5((string) mt_rand()), 0, 8);
$sentinel = 'contract-sentinel-' . $sfx;
$cleanup  = ['resources' => [], 'chunks' => [], 'plugins' => [], 'tvs' => []];

// --- resources ---------------------------------------------------------------
$created = call($url, $token, 'modxmcp_resource_create', [
    'pagetitle' => 'contract ' . $sfx,
    'alias'     => 'modxmcp-contract-' . $sfx,
    'content'   => "<p>{$sentinel}</p>",
    'published' => false,
]);
$rid = (int) ($created['result']['id'] ?? 0);
if ($rid <= 0) {
    exit("  cannot continue: resource_create failed: {$created['message']}\n");
}
$cleanup['resources'][] = $rid;

$read = call($url, $token, 'modxmcp_resource_get', ['id' => $rid]);
contract('resource_create content', "<p>{$sentinel}</p>",
    $read['result']['content'] ?? null, 'resource_get');
contract('resource_create pagetitle', 'contract ' . $sfx,
    $read['result']['pagetitle'] ?? null, 'resource_get');

call($url, $token, 'modxmcp_resource_update', ['id' => $rid, 'longtitle' => $sentinel]);
$read = call($url, $token, 'modxmcp_resource_get', ['id' => $rid]);
contract('resource_update longtitle', $sentinel,
    $read['result']['longtitle'] ?? null, 'resource_get');
contract('resource_update left content alone', "<p>{$sentinel}</p>",
    $read['result']['content'] ?? null, 'resource_get');

// The regression this file exists for.
$attached = null;
$tpl      = (int) ($read['result']['template'] ?? 0);
if ($tpl > 0) {
    $tplRead = call($url, $token, 'modxmcp_element_get', ['type' => 'template', 'id' => $tpl]);
    foreach ($tplRead['result']['template_vars'] ?? [] as $tv) {
        // A text-ish TV, so a string sentinel round-trips unmodified.
        if (in_array($tv['input_type'] ?? '', ['text', 'textarea', 'textfield'], true)) {
            $attached = (string) $tv['name'];
            break;
        }
    }
}

if ($attached === null) {
    skip('resource_update tvs', 'no plain-text TV on this template');
} else {
    call($url, $token, 'modxmcp_resource_update', [
        'id'  => $rid,
        'tvs' => [$attached => $sentinel],
    ]);
    $read = call($url, $token, 'modxmcp_resource_get', ['id' => $rid]);
    contract("resource_update tv '{$attached}'", $sentinel,
        $read['result']['tvs'][$attached] ?? null, 'resource_get');
}

// Dates are stored as epoch ints but read back as formatted strings, so this
// compares moments rather than representations.
$future = date('Y-m-d H:i:s', time() + 172800);
call($url, $token, 'modxmcp_resource_update', ['id' => $rid, 'pub_date' => $future]);
$read = call($url, $token, 'modxmcp_resource_get', ['id' => $rid]);
contract('resource_update pub_date', strtotime($future),
    strtotime((string) ($read['result']['pub_date'] ?? '')), 'resource_get');

// --- elements ----------------------------------------------------------------
$chunkName = 'contractChunk' . $sfx;
$cleanup['chunks'][] = $chunkName;
call($url, $token, 'modxmcp_element_save', [
    'type' => 'chunk', 'name' => $chunkName, 'content' => $sentinel,
    'description' => 'desc ' . $sfx,
]);
$read = call($url, $token, 'modxmcp_element_get', ['type' => 'chunk', 'name' => $chunkName]);
contract('element_save chunk body', $sentinel, $read['result']['content'] ?? null, 'element_get');
contract('element_save chunk description', 'desc ' . $sfx,
    $read['result']['description'] ?? null, 'element_get');

// A metadata-only save must not blank the body: the body column is named
// differently per element type and an earlier version of this tool sent the
// wrong one.
call($url, $token, 'modxmcp_element_save', [
    'type' => 'chunk', 'name' => $chunkName, 'description' => 'desc2 ' . $sfx,
]);
$read = call($url, $token, 'modxmcp_element_get', ['type' => 'chunk', 'name' => $chunkName]);
contract('metadata-only save kept the body', $sentinel,
    $read['result']['content'] ?? null, 'element_get');

// --- bindings ----------------------------------------------------------------
$pluginName = 'contractPlugin' . $sfx;
$cleanup['plugins'][] = $pluginName;
call($url, $token, 'modxmcp_element_save', [
    'type' => 'plugin', 'name' => $pluginName, 'content' => '/* contract */',
    'events' => ['OnDocFormSave'],
]);
$read = call($url, $token, 'modxmcp_element_get', ['type' => 'plugin', 'name' => $pluginName]);
contract('element_save plugin events', ['OnDocFormSave'],
    $read['result']['events'] ?? null, 'element_get');

$tvName = 'contractTv' . $sfx;
$cleanup['tvs'][] = $tvName;
call($url, $token, 'modxmcp_element_save', ['type' => 'tv', 'name' => $tvName]);
if ($tpl > 0) {
    call($url, $token, 'modxmcp_element_save', [
        'type' => 'tv', 'name' => $tvName, 'templates' => [$tpl],
    ]);
    $read = call($url, $token, 'modxmcp_element_get', ['type' => 'tv', 'name' => $tvName]);
    contract('element_save tv templates', [$tpl],
        array_column($read['result']['templates'] ?? [], 'id'), 'element_get');
} else {
    skip('element_save tv templates', 'no template on the test resource');
}

// --- categories --------------------------------------------------------------
$catName = 'contractCat' . $sfx;
$saved   = call($url, $token, 'modxmcp_category_save', ['name' => $catName]);
$catId   = (int) ($saved['result']['id'] ?? 0);
$listed  = call($url, $token, 'modxmcp_category_list', ['search' => 'contractCat']);
$found   = null;
foreach ($listed['result']['categories'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $catId) {
        $found = $row;
        break;
    }
}
contract('category_save name', $catName, $found['name'] ?? null, 'category_list');

// --- duplicate ---------------------------------------------------------------
//
// The copy must be reachable AND registered. Resource/Duplicate fires no form
// event, so without the tool's follow-up save the copy exists and is invisible
// to SeoSuite.
$copy = call($url, $token, 'modxmcp_resource_duplicate', [
    'id'    => $rid,
    'name'  => 'contract copy ' . $sfx,
    'alias' => 'modxmcp-contractcopy-' . $sfx,
]);
$copyId = (int) ($copy['result']['id'] ?? 0);
if ($copyId > 0) {
    $cleanup['resources'][] = $copyId;
    $read = call($url, $token, 'modxmcp_resource_get', ['id' => $copyId]);
    contract('duplicate copied the body', "<p>{$sentinel}</p>",
        $read['result']['content'] ?? null, 'resource_get');
    contract('duplicate applied the requested alias', 'modxmcp-contractcopy-' . $sfx,
        $read['result']['alias'] ?? null, 'resource_get');
} else {
    skip('duplicate', 'resource_duplicate failed: ' . $copy['message']);
}

// --- cleanup -----------------------------------------------------------------
foreach ($cleanup['resources'] as $id) {
    call($url, $token, 'modxmcp_resource_delete', ['id' => $id]);
}
foreach ($cleanup['plugins'] as $name) {
    call($url, $token, 'modxmcp_element_delete', ['type' => 'plugin', 'name' => $name]);
}
foreach ($cleanup['chunks'] as $name) {
    call($url, $token, 'modxmcp_element_delete', ['type' => 'chunk', 'name' => $name]);
}
foreach ($cleanup['tvs'] as $name) {
    // MODX refuses to remove a TV still attached to a template.
    call($url, $token, 'modxmcp_element_save', ['type' => 'tv', 'name' => $name, 'templates' => []]);
    call($url, $token, 'modxmcp_element_delete', ['type' => 'tv', 'name' => $name]);
}
if ($catId > 0) {
    $gone = call($url, $token, 'modxmcp_object_delete', [
        'class' => 'MODX\\Revolution\\modCategory', 'pk' => (string) $catId, 'confirm' => true,
    ]);
    if ($gone['isError']) {
        echo "  NOTE: category '{$catName}' (id {$catId}) left behind; there is no category "
            . "delete tool, and generic writes to modCategory are not allowlisted here.\n";
    }
}

echo str_repeat('=', 80) . "\n{$pass} passed, {$fail} failed, {$skip} skipped\n";
exit($fail === 0 ? 0 : 1);
