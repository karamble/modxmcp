<?php
/**
 * Does the generic-object filter allowlist still refuse an injection?
 *
 *   php dev/object-filter-probe.php          (run from a MODX web root)
 *
 * This is not part of dev/tools-test.php, and cannot be. xPDO criteria keys are
 * "field:operator", and xPDO interpolates the operator half into SQL rather than
 * binding it, so a key like "id:) OR 1=1 -- " was a working injection before the
 * beta2 fix. The fix validates both halves.
 *
 * Over HTTP that validation is unreachable: modxmcp_object_list refuses an
 * un-allowlisted class first, and both allowlists ship empty. A suite assertion
 * would therefore pass on the allowlist refusal and prove nothing about the
 * operator check. So the check is exercised where it actually runs.
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
define('MODX_API_MODE', true);
require_once 'index.php';

$src = MODX_CORE_PATH . 'components/modxmcp/src/';
require_once $src . 'Autoloader.php';
\MODXMCP\Autoloader::register($src);

$probe = new class {
    use \MODXMCP\Tools\ObjectSupport;
    public function go($modx, $class, $filters) { return $this->buildCriteria($modx, $class, $filters); }
};

$class = \MODX\Revolution\modCategory::class;
$pass = 0;
$fail = 0;

/** @param bool $shouldPass */
$check = function (string $label, array $filters, bool $shouldPass) use ($probe, $modx, $class, &$pass, &$fail) {
    try {
        $probe->go($modx, $class, $filters);
        $ok = $shouldPass;
        $detail = 'accepted';
    } catch (\Throwable $e) {
        $ok = !$shouldPass;
        $detail = 'refused: ' . substr($e->getMessage(), 0, 60);
    }
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-26s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
};

echo "object filter allowlist\n" . str_repeat('=', 80) . "\n";

$check('legitimate equality',    ['category:=' => 'x'],                 true);
$check('legitimate LIKE',        ['category:LIKE' => '%x%'],            true);
$check('bare field, no operator', ['category' => 'x'],                  true);
$check('injection via OR',       ['id:) OR 1=1 -- ' => 1],              false);
$check('injection via UNION',    ['id:) UNION SELECT 1 -- ' => 1],      false);
$check('operator off the list',  ['id:REGEXP' => '.*'],                 false);
$check('field that does not exist', ['no_such_col:=' => 1],             false);

echo str_repeat('=', 80) . "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
