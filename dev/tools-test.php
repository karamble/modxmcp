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

// --- resource type (class_key) -----------------------------------------------
//
// The regression this section exists for: every create before 0.4.0 forced
// class_key to the abstract modResource, so resources made through this server
// did not match anything the Manager produces and extras that key on class_key
// did not recognise them.
const DOC_CLASS = 'MODX\\Revolution\\modDocument';

check('create defaults to modDocument, not the abstract base',
    ($created['result']['class_key'] ?? '') === DOC_CLASS,
    'got ' . ($created['result']['class_key'] ?? 'nothing'));

$shortName = call($url, $token, 'modxmcp_resource_create', [
    'pagetitle' => 'modxmcp tool test shortname',
    'alias'     => $alias . '-short',
    'class_key' => 'modDocument',
]);
$shortId = (int) ($shortName['result']['id'] ?? 0);
check('class_key accepts the short spelling',
    ($shortName['result']['class_key'] ?? '') === DOC_CLASS);

$weblink = call($url, $token, 'modxmcp_resource_create', [
    'pagetitle' => 'modxmcp tool test weblink',
    'alias'     => $alias . '-link',
    'class_key' => 'MODX\\Revolution\\modWebLink',
    'content'   => 'https://example.com/',
]);
$weblinkId = (int) ($weblink['result']['id'] ?? 0);
check('class_key accepts a derived core type',
    ($weblink['result']['class_key'] ?? '') === 'MODX\\Revolution\\modWebLink');
check('weblink create warns that content is the target',
    (bool) array_filter($weblink['result']['warnings'] ?? [],
        fn($w) => stripos($w, 'target') !== false));

$badClass = call($url, $token, 'modxmcp_resource_create', [
    'pagetitle' => 'modxmcp tool test bad class',
    'alias'     => $alias . '-bad',
    'class_key' => 'MODX\\Revolution\\notAClass',
]);
check('an unknown class_key is rejected', !empty($badClass['isError']) || $badClass['error'] !== null);
$badSwept = call($url, $token, 'modxmcp_resource_list', ['search' => $alias . '-bad']);
check('a rejected class_key creates nothing',
    (int) ($badSwept['result']['total'] ?? 0) === 0);

// The repair path this release exists for: a resource carrying the abstract base
// type that earlier versions wrote on every create.
$legacy = call($url, $token, 'modxmcp_resource_create', [
    'pagetitle' => 'modxmcp tool test legacy',
    'alias'     => $alias . '-legacy',
    'class_key' => 'MODX\\Revolution\\modResource',
]);
$legacyId = (int) ($legacy['result']['id'] ?? 0);
check('creating with the abstract base type warns about it',
    (bool) array_filter($legacy['result']['warnings'] ?? [],
        fn($w) => stripos($w, 'abstract base') !== false));

$legacyRead = call($url, $token, 'modxmcp_resource_get', ['id' => $legacyId]);
check('resource_get flags a legacy class_key so a site can be audited by reads',
    (bool) array_filter($legacyRead['result']['warnings'] ?? [],
        fn($w) => stripos($w, 'earlier versions of modxmcp') !== false));

$retyped = call($url, $token, 'modxmcp_resource_update', [
    'id'        => $legacyId,
    'class_key' => DOC_CLASS,
]);
check('resource_update repairs a legacy class_key',
    ($retyped['result']['class_key'] ?? '') === DOC_CLASS,
    $retyped['error']['message'] ?? '');
check('a class_key change warns that nothing is migrated',
    (bool) array_filter($retyped['result']['warnings'] ?? [],
        fn($w) => stripos($w, 'migrated') !== false));

$confirmed = call($url, $token, 'modxmcp_resource_get', ['id' => $legacyId]);
check('the repaired resource reads back as modDocument',
    ($confirmed['result']['class_key'] ?? '') === DOC_CLASS);

// Changing away from a redirecting type is declined rather than attempted:
// modWebLink::process() ends in sendRedirect(), which exits, so MODX would
// apply the change and then kill the response before the caller saw it.
if ($weblinkId > 0) {
    $fromWeblink = call($url, $token, 'modxmcp_resource_update', [
        'id'        => $weblinkId,
        'class_key' => DOC_CLASS,
    ]);
    check('changing away from a weblink is declined, not attempted blind',
        !empty($fromWeblink['isError']) || $fromWeblink['error'] !== null,
        'a write whose response is lost cannot be confirmed by the caller');

    $stillWeblink = call($url, $token, 'modxmcp_resource_get', ['id' => $weblinkId]);
    check('the declined change left the weblink untouched',
        ($stillWeblink['result']['class_key'] ?? '') === 'MODX\\Revolution\\modWebLink');
}

// --- template variables ------------------------------------------------------
//
// A TV that exists but is not attached to the resource's template used to be
// accepted, encoded as tv{id} and then discarded inside the processor's
// template-joined loop, so the call reported success and wrote nothing.
$sfx     = substr($alias, -8);
$strayTv = 'modxmcpToolTestTv' . $sfx;
$tvSaved = call($url, $token, 'modxmcp_element_save', [
    'type'    => 'tv',
    'name'    => $strayTv,
    'caption' => 'modxmcp tool test',
]);
check('element_save creates a TV', $tvSaved['status'] === 200 && empty($tvSaved['isError']),
    $tvSaved['error']['message'] ?? '');
check('a TV with no template assignment warns that it renders nowhere',
    (bool) array_filter($tvSaved['result']['warnings'] ?? [],
        fn($w) => stripos($w, 'attached to no template') !== false));

$unknownTv = call($url, $token, 'modxmcp_resource_update', [
    'id'  => $newId,
    'tvs' => ['modxmcpNoSuchTvAnywhere' => 'x'],
]);
check('an unknown TV name is rejected',
    !empty($unknownTv['isError']) || $unknownTv['error'] !== null);

// A newly created TV is attached to no template, which makes this deterministic
// without assuming anything about the site's own template/TV wiring.
$unattached = call($url, $token, 'modxmcp_resource_update', [
    'id'        => $newId,
    'pagetitle' => 'SHOULD NOT LAND',
    'tvs'       => [$strayTv => 'x'],
]);
check('an unattached TV is rejected rather than silently dropped',
    !empty($unattached['isError']) || $unattached['error'] !== null);

// The assertion that proves validation happens before the write: if the TV
// rejection had come after runProcessor, the pagetitle would have landed.
$intact = call($url, $token, 'modxmcp_resource_get', ['id' => $newId]);
check('a rejected TV writes nothing at all',
    ($intact['result']['pagetitle'] ?? '') !== 'SHOULD NOT LAND',
    'pagetitle is ' . ($intact['result']['pagetitle'] ?? 'missing'));

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

// --- element bindings and categories -----------------------------------------
//
// A plugin bound to no events is never executed and a TV attached to no template
// renders nowhere, yet both used to save with a clean success.
$catName  = 'modxmcpToolTestCategory' . $sfx;
$catSaved = call($url, $token, 'modxmcp_category_save', ['name' => $catName]);
$catId    = (int) ($catSaved['result']['id'] ?? 0);
check('category_save creates a category', $catId > 0, $catSaved['error']['message'] ?? '');

$catList = call($url, $token, 'modxmcp_category_list', ['search' => 'modxmcpToolTest']);
check('category_list finds it by name',
    (bool) array_filter($catList['result']['categories'] ?? [],
        fn($c) => ($c['name'] ?? '') === $catName));

$categorised = 'modxmcpToolTestCategorised' . $sfx;
$byName = call($url, $token, 'modxmcp_element_save', [
    'type'     => 'chunk',
    'name'     => $categorised,
    'content'  => 'x',
    'category' => $catName,
]);
check('element_save resolves a category by name',
    (int) ($byName['result']['category'] ?? 0) === $catId && $catId > 0);

$badCat = call($url, $token, 'modxmcp_element_save', [
    'type'     => 'chunk',
    'name'     => 'modxmcpToolTestBadCategory',
    'category' => 'modxmcpNoSuchCategory',
]);
check('an unknown category name is refused, not created',
    !empty($badCat['isError']) || $badCat['error'] !== null);

$pluginName  = 'modxmcpToolTestPlugin' . $sfx;
$boundPlugin = call($url, $token, 'modxmcp_element_save', [
    'type'    => 'plugin',
    'name'    => $pluginName,
    'content' => '/* modxmcp tool test */',
    'events'  => ['OnDocFormSave'],
]);
check('element_save binds a plugin to its events',
    in_array('OnDocFormSave', $boundPlugin['result']['events'] ?? [], true),
    $boundPlugin['error']['message'] ?? '');
check('a bound plugin does not warn about never executing',
    !array_filter($boundPlugin['result']['warnings'] ?? [],
        fn($w) => stripos($w, 'never execute') !== false));

$badEvent = call($url, $token, 'modxmcp_element_save', [
    'type'   => 'plugin',
    'name'   => $pluginName,
    'events' => ['OnNoSuchEventEver'],
]);
check('an unknown system event is rejected',
    !empty($badEvent['isError']) || $badEvent['error'] !== null,
    'a binding to an invented event would never fire');

$misplaced = call($url, $token, 'modxmcp_element_save', [
    'type'   => 'chunk',
    'name'   => $chunkName,
    'events' => ['OnDocFormSave'],
]);
check('a type-scoped argument sent for the wrong type is rejected',
    !empty($misplaced['isError']) || $misplaced['error'] !== null,
    'events applies to plugins, not chunks');

// The TV created earlier is unattached, which is what made the resource-side
// rejection deterministic. Attaching it should now be possible from here.
$tvTemplates = call($url, $token, 'modxmcp_element_list', ['type' => 'template', 'limit' => 1]);
$anyTemplate = (int) ($tvTemplates['result']['elements'][0]['id'] ?? 0);
if ($anyTemplate > 0) {
    $attached = call($url, $token, 'modxmcp_element_save', [
        'type'      => 'tv',
        'name'      => $strayTv,
        'templates' => [$anyTemplate],
    ]);
    check('element_save attaches a TV to a template',
        in_array($anyTemplate, array_column($attached['result']['templates'] ?? [], 'id'), true),
        $attached['error']['message'] ?? '');
    check('an attached TV no longer warns that it renders nowhere',
        !array_filter($attached['result']['warnings'] ?? [],
            fn($w) => stripos($w, 'attached to no template') !== false));

    $tplRead = call($url, $token, 'modxmcp_element_get', ['type' => 'template', 'id' => $anyTemplate]);
    check('element_get lists a template\'s attached TVs',
        in_array($strayTv, array_column($tplRead['result']['template_vars'] ?? [], 'name'), true),
        'this is how a caller discovers which TVs it may write');
}

// --- search ------------------------------------------------------------------
//
// The question this exists for is "who calls this element", which has to be
// answered before any rename and which nothing else in the surface could answer.
$needleChunk = 'modxmcpToolTestCalled' . $sfx;
call($url, $token, 'modxmcp_element_save', [
    'type' => 'chunk', 'name' => $needleChunk, 'content' => 'x',
]);
$callerChunk = 'modxmcpToolTestCaller' . $sfx;
call($url, $token, 'modxmcp_element_save', [
    'type'    => 'chunk',
    'name'    => $callerChunk,
    'content' => "before [[\${$needleChunk}]] after\nsecond [[!{$needleChunk}]] line",
]);

$refs = call($url, $token, 'modxmcp_search', [
    'q'    => $needleChunk,
    'mode' => 'tag_reference',
    'in'   => 'elements',
]);
$callers = array_column($refs['result']['elements'] ?? [], 'name');
check('search tag_reference finds the calling element',
    in_array($callerChunk, $callers, true), $refs['error']['message'] ?? '');
check('search reports every call site, not just the first',
    (int) (array_values(array_filter($refs['result']['elements'] ?? [],
        fn($e) => ($e['name'] ?? '') === $callerChunk))[0]['match_count'] ?? 0) >= 2);
check('search quotes the surrounding line with a line number',
    !empty(array_values(array_filter($refs['result']['elements'] ?? [],
        fn($e) => ($e['name'] ?? '') === $callerChunk))[0]['excerpts'][0]['line'] ?? null));

$literal = call($url, $token, 'modxmcp_search', ['q' => 'through the MCP tool surface']);
check('search finds text in resource content',
    (bool) array_filter($literal['result']['resources'] ?? [], fn($r) => (int) $r['id'] === $newId));

// A LIKE wildcard passed through unescaped would match every row in the table.
$wild = call($url, $token, 'modxmcp_search', ['q' => '%', 'in' => 'resources', 'limit' => 5]);
check('a LIKE wildcard is escaped, not honoured',
    (int) ($wild['result']['total_matches'] ?? 0) === 0
    || !array_filter($wild['result']['resources'] ?? [], fn($r) => (int) $r['id'] === $newId),
    'searching for "%" must not match everything');

call($url, $token, 'modxmcp_element_delete', ['type' => 'chunk', 'name' => $callerChunk]);
call($url, $token, 'modxmcp_element_delete', ['type' => 'chunk', 'name' => $needleChunk]);

$badSort = call($url, $token, 'modxmcp_resource_list', ['sort' => 'no_such_column']);
check('an invalid sort column is rejected, not interpolated',
    !empty($badSort['isError']) || $badSort['error'] !== null);

$sorted   = call($url, $token, 'modxmcp_resource_list', ['sort' => 'id', 'dir' => 'DESC', 'limit' => 5]);
$ids      = array_column($sorted['result']['resources'] ?? [], 'id');
$expected = $ids;
rsort($expected);
check('resource_list sorts by a validated column', $ids === $expected,
    'ids came back as ' . implode(',', $ids));

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

// Fixtures from the class_key, TV and binding sections.
foreach ([$shortId ?? 0, $weblinkId ?? 0, $legacyId ?? 0] as $extraId) {
    if ((int) $extraId > 0) {
        call($url, $token, 'modxmcp_resource_delete', ['id' => (int) $extraId]);
    }
}
// MODX refuses to remove a TV that is still attached to a template, so detach
// it first. Passing an empty list means "attached to nothing".
call($url, $token, 'modxmcp_element_save', ['type' => 'tv', 'name' => $strayTv, 'templates' => []]);
$tvGone = call($url, $token, 'modxmcp_element_delete', ['type' => 'tv', 'name' => $strayTv]);
check('a TV can be detached and then deleted', empty($tvGone['isError']) && $tvGone['error'] === null,
    $tvGone['error']['message'] ?? '');
call($url, $token, 'modxmcp_element_delete', ['type' => 'plugin', 'name' => $pluginName]);
call($url, $token, 'modxmcp_element_delete', ['type' => 'chunk', 'name' => $categorised]);
// The category last: deleting it earlier would orphan the chunk filed under it.
//
// There is no category delete tool, by design, so this goes through the generic
// object path. That needs write:objects AND modCategory in the writable-classes
// list, which most sites will not have, so it is best effort and says so rather
// than leaving an unexplained category behind.
if (($catId ?? 0) > 0) {
    $catGone = call($url, $token, 'modxmcp_object_delete', [
        'class'   => 'MODX\\Revolution\\modCategory',
        'pk'      => (string) $catId,
        'confirm' => true,
    ]);
    if (!empty($catGone['isError']) || $catGone['error'] !== null) {
        echo "  NOTE: category '{$catName}' (id {$catId}) was left behind; delete it in the "
            . "Manager, or allowlist MODX\\Revolution\\modCategory for generic writes.\n";
    }
}

// Anything created by the duplicate-alias probe would be a bug, but sweep anyway.
$stray = call($url, $token, 'modxmcp_resource_list', ['search' => 'duplicate alias probe']);
foreach ($stray['result']['resources'] ?? [] as $row) {
    call($url, $token, 'modxmcp_resource_delete', ['id' => (int) $row['id']]);
    echo "  swept stray resource {$row['id']}\n";
}
$strayBad = call($url, $token, 'modxmcp_resource_list', ['search' => 'modxmcp tool test bad class']);
foreach ($strayBad['result']['resources'] ?? [] as $row) {
    call($url, $token, 'modxmcp_resource_delete', ['id' => (int) $row['id']]);
    echo "  swept stray resource {$row['id']} (a rejected class_key should not have created one)\n";
}

echo str_repeat('=', 80) . "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
