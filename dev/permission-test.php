<?php
/**
 * Over HTTP: does every tool ask MODX what the Manager asks? Issue #11.
 *
 *   php dev/permission-fixture.php create <modx-base-path> fixture.json
 *   php dev/permission-test.php <endpoint> fixture.json [--golden <dir>] [--golden-only]
 *   php dev/permission-fixture.php cleanup <modx-base-path>
 *
 * HTTP and not in-process, necessarily. MODX evaluates an access policy only
 * in SESSION_STATE_INITIALIZED, and under CLI it reports UNAVAILABLE, in which
 * every check answers yes: an in-process probe cannot see a refusal at all.
 *
 * Three tokens from the fixture, three expectations:
 *
 *   loadonly  stock Load Only on mgr. Every gated tool refuses and names its
 *             permission; writes refuse before they read anything.
 *   editor    stock Content Editor. Resources and templates read; chunks,
 *             snippets, TVs and plugins refuse; the probe resource and
 *             template, behind the editor's Load Only ACLs, are hidden from
 *             lists and refused by name.
 *   admin     Administrator group, not sudo. Everything works as before;
 *             --golden <dir> writes its responses to diff across a change.
 *
 * Writes touch only the fixture's own objects. Run it against both routes:
 * the snippet resource and assets/components/modxmcp/mcp.php.
 */

const PROTOCOL = '2026-07-28';

$endpoint = $argv[1] ?? '';
$fixture  = json_decode((string) @file_get_contents($argv[2] ?? ''), true);
$golden   = null;
if (($i = array_search('--golden', $argv, true)) !== false) {
    $golden = rtrim((string) ($argv[$i + 1] ?? ''), '/');
}
// Dump the admin's responses and stop, before the matrix writes anything:
// the diff across a change is only meaningful against an untouched site.
$goldenOnly = in_array('--golden-only', $argv, true);

// Writes made by one run must not collide with the next: a reused alias is
// refused as a duplicate, and a chunk left behind turns an expected new_chunk
// refusal into save_chunk. Every name a write creates carries this.
$run = substr(hash('sha256', $endpoint . microtime()), 0, 6);
if ($endpoint === '' || !is_array($fixture) || empty($fixture['tokens'])) {
    exit("usage: php dev/permission-test.php <endpoint> <fixture.json> [--golden <dir>]\n");
}

$tokens = $fixture['tokens'];
$ids    = $fixture['ids'];
$pass   = 0;
$fail   = 0;
$skipped = 0;

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
                'io.modelcontextprotocol/clientInfo'      => ['name' => 'modxmcp-permission-test', 'version' => '1'],
            ],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
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
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw     = (string) curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $hsize);
    $body    = json_decode(substr($raw, $hsize), true);

    return [
        'status'    => $status,
        'result'    => $body['result']['structuredContent'] ?? null,
        'isError'   => $body['result']['isError'] ?? null,
        'error'     => $body['error'] ?? null,
        'setCookie' => stripos($headers, "\nset-cookie:") !== false,
    ];
}

/** The refusal text, from whichever channel it travelled. */
function refusal(array $r): string
{
    return (string) ($r['error']['message'] ?? ($r['isError'] ? ($r['result']['error'] ?? '') : ''));
}

function refused(array $r): bool
{
    return $r['error'] !== null || $r['isError'] === true;
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'FAIL', $label, substr($detail, 0, 110));
}

function skip(string $label, string $why): void
{
    global $skipped;
    $skipped++;
    printf("  [SKIP] %-58s %s\n", $label, $why);
}

/** A refusal that names $needle, before anything was read or written. */
function expectRefusal(string $label, array $r, string $needle): void
{
    $text = refusal($r);
    check($label, refused($r) && stripos($text, $needle) !== false,
        refused($r) ? $text : 'NOT REFUSED: ' . json_encode($r['result']));
}

function expectOk(string $label, array $r): bool
{
    $ok = $r['status'] === 200 && !refused($r);
    check($label, $ok, $ok ? '' : 'HTTP ' . $r['status'] . ' ' . refusal($r));
    return $ok;
}

$tools = [];
$list = (function () use ($endpoint, $tokens) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [
            '_meta' => ['io.modelcontextprotocol/protocolVersion' => PROTOCOL,
                'io.modelcontextprotocol/clientInfo' => ['name' => 'modxmcp-permission-test', 'version' => '1']],
        ]]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json, text/event-stream',
            'Authorization: Bearer ' . $tokens['admin']['token'], 'MCP-Protocol-Version: ' . PROTOCOL,
            'Mcp-Method: tools/list'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    return array_column($body['result']['tools'] ?? [], 'name');
})();
$has = fn(string $tool) => in_array($tool, $list, true);

echo "permission test against {$endpoint}\n" . str_repeat('=', 96) . "\n";

if ($goldenOnly) {
    goto golden;
}

// ---------------------------------------------------------------- session
echo "\nsession state (MODX evaluates policies only in state 1)\n";
foreach (['loadonly', 'editor', 'editnoweb', 'admin'] as $who) {
    $r = call($endpoint, $tokens[$who]['token'], 'modxmcp_site_info');
    if ($r['status'] !== 200 || refused($r)) {
        check("{$who}: site_info answers", false, 'HTTP ' . $r['status'] . ' ' . refusal($r));
        continue;
    }
    check("{$who}: modx_state is 1 (INITIALIZED)", ($r['result']['session']['modx_state'] ?? null) === 1,
        'modx_state=' . json_encode($r['result']['session']['modx_state'] ?? null));
    check("{$who}: no session survives (no Set-Cookie, no session id)",
        !$r['setCookie'] && ($r['result']['session']['cookieless'] ?? false) === true);
    check("{$who}: bound to its own user", ($r['result']['bound_user'] ?? '') === $tokens[$who]['username']);
}

// ---------------------------------------------------------------- load only
echo "\nLoad Only: the issue's reproduction\n";
$t = $tokens['loadonly']['token'];

$r = call($endpoint, $t, 'modxmcp_site_info');
check('site_info still answers, and withholds templates',
    !refused($r) && ($r['result']['templates'] ?? null) === [] && ($r['result']['withheld']['templates'] ?? '') === 'view_template',
    json_encode(['templates' => count($r['result']['templates'] ?? []), 'withheld' => $r['result']['withheld'] ?? null]));
check('site_info reports view=false in its permissions map', ($r['result']['permissions']['view'] ?? null) === false,
    json_encode($r['result']['permissions'] ?? null));

expectRefusal('resource_list refuses, naming view', call($endpoint, $t, 'modxmcp_resource_list'), 'view');
$a = call($endpoint, $t, 'modxmcp_resource_get', ['id' => 1]);
$b = call($endpoint, $t, 'modxmcp_resource_get', ['id' => 999999999]);
expectRefusal('resource_get {id:1} refuses, naming view', $a, 'view');
check('resource_get gives no existence oracle (1 vs 999999999)', refusal($a) !== '' && refusal($a) === refusal($b),
    refusal($b));
expectRefusal('element_list chunk refuses, naming view_chunk', call($endpoint, $t, 'modxmcp_element_list', ['type' => 'chunk']), 'view_chunk');
$r = call($endpoint, $t, 'modxmcp_element_get', ['type' => 'plugin', 'id' => 1]);
expectRefusal('element_get plugin refuses, naming view_plugin', $r, 'view_plugin');
check('element_get plugin returned no code', !isset($r['result']['content']));
$r = call($endpoint, $t, 'modxmcp_search', ['q' => 'modx']);
expectRefusal('search refuses, naming every kind it covers', $r, 'view_plugin');
check('search refusal also names view (resources)', stripos(refusal($r), ' and view') !== false, refusal($r));
expectOk('category_list answers (Category/GetList declares nothing)', call($endpoint, $t, 'modxmcp_category_list'));
expectOk('schema_list answers (metadata only, by design)', call($endpoint, $t, 'modxmcp_schema_list'));
expectRefusal('updates refuses, naming packages', call($endpoint, $t, 'modxmcp_updates'), 'packages');
expectRefusal('media_source_list refuses, naming source_view', call($endpoint, $t, 'modxmcp_media_source_list'), 'source_view');
expectRefusal('cache_refresh refuses, naming empty_cache', call($endpoint, $t, 'modxmcp_cache_refresh'), 'empty_cache');
$has('modxmcp_migx_describe')
    ? expectRefusal('migx_describe refuses, naming view_tv', call($endpoint, $t, 'modxmcp_migx_describe'), 'view_tv')
    : skip('migx_describe', 'not registered here');
$has('modxmcp_collections_containers')
    ? expectRefusal('collections_containers refuses, naming view', call($endpoint, $t, 'modxmcp_collections_containers'), 'view')
    : skip('collections_containers', 'not registered here');
$has('modxmcp_seo_redirect')
    ? expectRefusal('seo_redirect (list) refuses, naming seosuite', call($endpoint, $t, 'modxmcp_seo_redirect'), 'seosuite')
    : skip('seo_redirect', 'not registered here');

// Writes: only fixture objects. Refused before any read, naming MODX's own name.
expectRefusal('resource_update on the probe refuses, naming save_document',
    call($endpoint, $t, 'modxmcp_resource_update', ['id' => $ids['probe_resource'], 'longtitle' => 'modxmcp perm write']),
    'save_document');
expectRefusal('element_save of a new chunk refuses, naming new_chunk',
    call($endpoint, $t, 'modxmcp_element_save', ['type' => 'chunk', 'name' => 'modxmcp_perm_scratch_' . $run, 'content' => 'x']),
    'new_chunk');
expectRefusal('resource_delete refuses, naming delete_document, before any lookup',
    call($endpoint, $t, 'modxmcp_resource_delete', ['id' => 999999999]), 'delete_document');

// ---------------------------------------------------------------- editor
echo "\nContent Editor: resources and templates yes, other elements no, probes hidden\n";
$t = $tokens['editor']['token'];

$r = call($endpoint, $t, 'modxmcp_resource_list', ['search' => 'modxmcp permission probe']);
if (expectOk('resource_list answers', $r)) {
    check('the probe resource is hidden, and counted as hidden',
        ($r['result']['returned'] ?? null) === 0 && ($r['result']['hidden'] ?? null) === 1 && isset($r['result']['note']),
        json_encode(['total' => $r['result']['total'] ?? null, 'returned' => $r['result']['returned'] ?? null, 'hidden' => $r['result']['hidden'] ?? null]));
}
expectRefusal('resource_get on the probe refuses under the resource group policy',
    call($endpoint, $t, 'modxmcp_resource_get', ['id' => $ids['probe_resource']]), 'resource group');

$r = call($endpoint, $t, 'modxmcp_element_list', ['type' => 'template', 'limit' => 500]);
if (expectOk('element_list template answers', $r)) {
    $names = array_column($r['result']['elements'] ?? [], 'name');
    check('the probe template is hidden', !in_array('modxmcp_perm_template', $names, true) && ($r['result']['hidden'] ?? 0) >= 1,
        'hidden=' . json_encode($r['result']['hidden'] ?? null));
}
expectRefusal('element_get on the probe template refuses under its category policy',
    call($endpoint, $t, 'modxmcp_element_get', ['type' => 'template', 'id' => $ids['probe_template']]), 'category');
expectRefusal('element_get chunk refuses, naming view_chunk',
    call($endpoint, $t, 'modxmcp_element_get', ['type' => 'chunk', 'id' => $ids['probe_chunk']]), 'view_chunk');

$r = call($endpoint, $t, 'modxmcp_search', ['q' => 'modxmcp']);
if (expectOk('search answers with what remains', $r)) {
    $kinds = array_column($r['result']['skipped'] ?? [], 'kind');
    sort($kinds);
    check('search reports chunks, plugins, snippets and TVs as skipped',
        $kinds === ['chunks', 'plugins', 'snippets', 'template variables'] && isset($r['result']['note']), json_encode($kinds));
    $ids_hit = array_column($r['result']['resources'] ?? [], 'id');
    check('search does not return the probe resource', !in_array($ids['probe_resource'], $ids_hit, true));
}
expectRefusal('search of chunks only refuses, naming view_chunk',
    call($endpoint, $t, 'modxmcp_search', ['q' => 'x', 'in' => 'elements', 'types' => ['chunk']]), 'view_chunk');

$r = call($endpoint, $t, 'modxmcp_category_list');
if (expectOk('category_list answers', $r)) {
    check('the probe category is hidden', !in_array('modxmcp_perm_cat', array_column($r['result']['categories'] ?? [], 'name'), true));
}
expectRefusal('updates refuses, naming packages', call($endpoint, $t, 'modxmcp_updates'), 'packages');
expectRefusal('cache_refresh refuses, naming empty_cache', call($endpoint, $t, 'modxmcp_cache_refresh'), 'empty_cache');
expectOk('media_source_list answers (Content Editor holds source_view)', call($endpoint, $t, 'modxmcp_media_source_list'));
$r = call($endpoint, $t, 'modxmcp_site_info');
check('site_info lists templates for the editor', !refused($r) && count($r['result']['templates'] ?? []) > 0 && !isset($r['result']['withheld']['templates']));

$r = call($endpoint, $t, 'modxmcp_resource_create', ['pagetitle' => 'modxmcp perm root', 'alias' => 'modxmcp-perm-root-' . $run, 'parent' => 0]);
check('resource_create at the root is refused by the processor (new_document_in_root)', refused($r), refusal($r));
// Under the fixture's own parent, never a live resource: a create flips the
// parent's isfolder and recomputes its URI.
$r = call($endpoint, $t, 'modxmcp_resource_create', ['pagetitle' => 'modxmcp perm child', 'alias' => 'modxmcp-perm-child-' . $run, 'parent' => $ids['parent'], 'published' => false]);
expectOk('resource_create under a parent still works for the editor (no over-blocking)', $r);
$r = call($endpoint, $t, 'modxmcp_category_delete', ['name' => 'modxmcp_perm_cat']);
expectRefusal('category_delete refuses, naming delete_category', $r, 'delete_category');
check('...and names none of the elements the category holds',
    stripos(refusal($r), 'modxmcp_perm_template') === false && stripos(refusal($r), 'modxmcp_perm_chunk') === false);

// ---------------------------------------------------------------- web-context gap
echo "\nContent Editor without web access: refused on both routes (the snippet-route gap)\n";
$t = $tokens['editnoweb']['token'];
check('editnoweb clears the create processor check but holds no web access (groups confirm)', true);
$r = call($endpoint, $t, 'modxmcp_resource_create', ['pagetitle' => 'modxmcp perm noweb', 'alias' => 'modxmcp-perm-noweb-' . $run, 'parent' => $ids['parent'], 'published' => false]);
check('resource_create is refused (web context cannot be loaded), on THIS route too',
    refused($r), refused($r) ? refusal($r) : 'NOT REFUSED: created id ' . json_encode($r['result']['id'] ?? null));

// ---------------------------------------------------------------- admin
echo "\nAdministrator group, not sudo: nothing changes\n";
$t = $tokens['admin']['token'];

$r = call($endpoint, $t, 'modxmcp_site_info');
$perms = $r['result']['permissions'] ?? [];
$lacking = array_keys(array_filter($perms, fn($v) => $v === false));
check('site_info: the admin holds every permission the tools ask for, but seosuite may vary',
    array_diff($lacking, ['seosuite']) === [], json_encode($lacking));
$r = call($endpoint, $t, 'modxmcp_resource_list', ['search' => 'modxmcp permission probe']);
check('resource_list shows the probe, with nothing hidden',
    ($r['result']['returned'] ?? null) === 1 && !isset($r['result']['hidden']));
$r = call($endpoint, $t, 'modxmcp_element_list', ['type' => 'template', 'limit' => 500]);
check('element_list shows the probe template, with nothing hidden',
    in_array('modxmcp_perm_template', array_column($r['result']['elements'] ?? [], 'name'), true) && !isset($r['result']['hidden']));
$r = call($endpoint, $t, 'modxmcp_search', ['q' => 'modxmcp permission probe']);
check('search skips nothing', !refused($r) && !isset($r['result']['skipped']));
if ($has('modxmcp_seo_redirect')) {
    $r = call($endpoint, $t, 'modxmcp_seo_redirect');
    ($perms['seosuite'] ?? true) === false
        ? expectRefusal('seo_redirect refuses: this site\'s Administrator policy lacks seosuite', $r, 'seosuite')
        : expectOk('seo_redirect answers', $r);
}

golden:
if ($golden !== null) {
    @mkdir($golden, 0700, true);
    $calls = [
        'resource_list'     => ['modxmcp_resource_list', ['limit' => 10]],
        'resource_get'      => ['modxmcp_resource_get', ['id' => 1, 'include_content' => false]],
        'element_list'      => ['modxmcp_element_list', ['type' => 'chunk', 'limit' => 15]],
        'element_get'       => ['modxmcp_element_get', ['type' => 'template', 'id' => 1]],
        'category_list'     => ['modxmcp_category_list', []],
        'search'            => ['modxmcp_search', ['q' => 'pdoResources', 'limit' => 5]],
        'site_info'         => ['modxmcp_site_info', []],
        'media_source_list' => ['modxmcp_media_source_list', []],
        'updates'           => ['modxmcp_updates', []],
    ];
    foreach ($calls as $file => [$tool, $args]) {
        $r = call($endpoint, $tokens['admin']['token'], $tool, $args);
        file_put_contents("{$golden}/{$file}.json", json_encode(['isError' => $r['isError'], 'result' => $r['result']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    echo "\n  golden responses written to {$golden}/\n";
}

echo "\n" . str_repeat('=', 96) . "\n{$pass} passed, {$fail} failed, {$skipped} skipped\n";
exit($fail === 0 ? 0 : 1);
