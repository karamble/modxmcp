<?php
/**
 * Every tool asks MODX before it acts, or says why it does not need to.
 *
 * Issue #11 was a whole class of tools that checked the token's scope and
 * nothing else: every read went straight to xPDO, and the one tool that asked
 * MODX for a permission was the exception. This makes the opposite the rule a
 * new tool cannot quietly break. A Tools/*Tool.php file must contain a call
 * that asks MODX -- requirePermission(), requirePolicy(),
 * requireProcessorPermission(), hasPermission() or checkPolicy() -- or be
 * listed below with the reason it is exempt.
 *
 * Textual, and deliberately so: it runs without MODX, in a second, before
 * anything reaches a site. It cannot tell a gate in the right place from one in
 * the wrong place; dev/permission-test.php, which runs over HTTP against a
 * real site, is what checks that the gates refuse.
 *
 *   php dev/permission-guard-test.php
 */

$tools = glob(dirname(__DIR__) . '/core/components/modxmcp/src/Tools/*Tool.php') ?: [];

// Tools that read no row a MODX policy governs. Each needs a reason, and the
// list is exhaustive: anything else must ask.
$exempt = [
    'AbstractTool.php'       => 'the base class, which defines the helpers',
    'SchemaListTool.php'     => 'model metadata only, no rows; credential-bearing classes are hard-blocked',
    'SchemaDescribeTool.php' => 'model metadata only, no rows; credential-bearing classes are hard-blocked',
];

$asks = '/->(requirePermission|requirePolicy|requireProcessorPermission|hasPermission|checkPolicy)\(/';

$pass = 0;
$fail = 0;

echo "permission guard\n" . str_repeat('=', 80) . "\n";

foreach ($tools as $file) {
    $name = basename($file);
    $src  = (string) file_get_contents($file);

    if (isset($exempt[$name])) {
        $asksAnyway = preg_match($asks, $src) === 1;
        printf("  [EXEMPT] %-30s %s%s\n", $name, $exempt[$name],
            $asksAnyway && $name !== 'AbstractTool.php' ? ' (asks anyway: drop it from the list)' : '');
        if ($asksAnyway && $name !== 'AbstractTool.php') {
            $fail++;
        }
        continue;
    }

    $ok = preg_match($asks, $src) === 1;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $name . ($ok ? '' : ' asks MODX nothing: gate it, or exempt it here with a reason'));
}

// The exemption list must not outlive the files it names.
foreach (array_keys($exempt) as $name) {
    if (!in_array($name, array_map('basename', $tools), true)) {
        $fail++;
        printf("  [FAIL] exemption for %s names a file that no longer exists\n", $name);
    }
}

if ($tools === []) {
    $fail++;
    echo "  [FAIL] no tool files found; the glob is wrong\n";
}

echo str_repeat('=', 80) . "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
