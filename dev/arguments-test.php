<?php
/**
 * Unit exercise of Registry\Arguments, the argument check added for issue #8.
 *
 * Runs without MODX: Arguments reads a schema array and throws McpException,
 * and neither needs a site. Everything the tools were casting silently is
 * asserted here, in both directions -- what must be refused, and what must
 * still be accepted, because a check that refuses a caller who quoted a number
 * is a regression of its own.
 *
 *   php dev/arguments-test.php
 */

$root = dirname(__DIR__) . '/core/components/modxmcp/src/';
require $root . 'Protocol/Errors.php';
require $root . 'Protocol/McpException.php';
require $root . 'Registry/Schema.php';
require $root . 'Registry/Arguments.php';

use MODXMCP\Protocol\McpException;
use MODXMCP\Registry\Arguments;
use MODXMCP\Registry\Schema;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

/** Validate, returning ['ok' => array] or ['err' => message]. */
function run(array $schema, array $args): array
{
    try {
        return ['ok' => Arguments::validate('modxmcp_probe', $schema, $args)];
    } catch (McpException $e) {
        return ['err' => $e->getMessage()];
    }
}

$schema = Schema::object([
    'id'        => Schema::integer('Resource id.'),
    'pagetitle' => Schema::string('Page title.'),
    'published' => Schema::boolean('Publish immediately.', false),
    'type'      => Schema::enum('Element type.', ['chunk', 'snippet', 'template']),
    'templates' => Schema::arrayOf('Template names or ids.', ['type' => ['string', 'integer']]),
    'tvs'       => Schema::map('Template variable values.'),
], ['id']);

echo "argument validation\n" . str_repeat('=', 80) . "\n";

// ---- the bug that started it: an array where an id was wanted -------------
echo "\nthe reported bug\n";

$r = run($schema, ['id' => ['x']]);
check('an array id is refused rather than cast to 1',
    isset($r['err']) && stripos($r['err'], 'whole number') !== false,
    substr($r['err'] ?? json_encode($r['ok']), 0, 46));

$r = run($schema, ['id' => true]);
check('a boolean id is refused, because true would cast to 1',
    isset($r['err']),
    substr($r['err'] ?? '', 0, 46));

$r = run($schema, ['id' => '12abc']);
check('a half-numeric id is refused rather than becoming 12',
    isset($r['err']),
    substr($r['err'] ?? '', 0, 46));

$r = run($schema, ['id' => 'abc']);
check('a non-numeric id is refused', isset($r['err']));

$r = run($schema, ['id' => '']);
check('an empty id is refused', isset($r['err']));

// ---- and what must keep working ------------------------------------------
echo "\nwhat the hybrid rule must still accept\n";

$r = run($schema, ['id' => 5]);
check('an integer id is accepted', ($r['ok']['id'] ?? null) === 5);

$r = run($schema, ['id' => '5']);
check('a quoted id is accepted and converted', ($r['ok']['id'] ?? null) === 5,
    'callers that quote numbers are not making the mistake');

$r = run($schema, ['id' => ' 5 ']);
check('a padded quoted id is accepted', ($r['ok']['id'] ?? null) === 5);

$r = run($schema, ['id' => 5.0]);
check('a whole float id is accepted', ($r['ok']['id'] ?? null) === 5);

$r = run($schema, ['id' => '5.5']);
check('a fractional id is refused', isset($r['err']));

$r = run($schema, ['id' => 0]);
check('zero is a value, not a failure', array_key_exists('id', $r['ok'] ?? []) && $r['ok']['id'] === 0,
    'filter_var returns false for failure and 0 for zero');

$r = run($schema, ['id' => '-3']);
check('a negative id is accepted', ($r['ok']['id'] ?? null) === -3);

// ---- unknown keys, the context_key round trip ------------------------------
echo "\nunknown keys\n";

$r = run($schema, ['id' => 1, 'context_key' => 'web']);
check('an unknown key is refused',
    isset($r['err']) && stripos($r['err'], 'context_key') !== false);

$r = run($schema, ['id' => 1, 'context_key' => 'web']);
check('the refusal lists what is accepted',
    isset($r['err']) && stripos($r['err'], 'pagetitle') !== false,
    'so the caller can correct in one step');

$r = run($schema, ['id' => 1, 'pagetitl' => 'x']);
check('a near miss is named',
    isset($r['err']) && stripos($r['err'], "mean 'pagetitle'") !== false,
    substr($r['err'] ?? '', 0, 46));

$r = run($schema, ['id' => 1, 'zzzzzzzzzzzzzz' => 'x']);
check('a wild key gets no guess',
    isset($r['err']) && stripos($r['err'], 'did you mean') === false,
    'a wrong guess is worse than none');

$r = run($schema, ['id' => 1, '_meta' => ['a' => 'b']]);
check('an underscore key passes through untouched',
    isset($r['ok']['_meta']),
    'protocol extensions are not the tool\'s business');

// ---- strings, booleans, enums, lists, maps ---------------------------------
echo "\nthe other declared types\n";

$r = run($schema, ['id' => 1, 'pagetitle' => ['x']]);
check('an array title is refused rather than becoming "Array"', isset($r['err']));

$r = run($schema, ['id' => 1, 'pagetitle' => 42]);
check('a numeric title is accepted as text', ($r['ok']['pagetitle'] ?? null) === '42');

$r = run($schema, ['id' => 1, 'pagetitle' => true]);
check('a boolean title is refused', isset($r['err']));

$r = run($schema, ['id' => 1, 'published' => 'true']);
check('a quoted boolean is accepted', ($r['ok']['published'] ?? null) === true);

$r = run($schema, ['id' => 1, 'published' => 0]);
check('zero is false', ($r['ok']['published'] ?? null) === false);

$r = run($schema, ['id' => 1, 'published' => 'yes please']);
check('an unparseable boolean is refused', isset($r['err']));

$r = run($schema, ['id' => 1, 'type' => 'SNIPPET']);
check('an enum matches without case and returns the declared spelling',
    ($r['ok']['type'] ?? null) === 'snippet');

$r = run($schema, ['id' => 1, 'type' => 'widget']);
check('an enum value outside the list is refused, naming the list',
    isset($r['err']) && stripos($r['err'], 'chunk, snippet, template') !== false);

$r = run($schema, ['id' => 1, 'templates' => ['BaseTemplate', 3]]);
check('a list of strings and ids is accepted',
    ($r['ok']['templates'] ?? null) === ['BaseTemplate', 3]);

$r = run($schema, ['id' => 1, 'templates' => 'BaseTemplate']);
check('a bare string where a list is declared is refused', isset($r['err']));

$r = run($schema, ['id' => 1, 'templates' => [['nested']]]);
check('a nested list inside a scalar list is refused', isset($r['err']));

$r = run($schema, ['id' => 1, 'tvs' => ['articleimage' => 'a.jpg', 'migx' => [['x' => 1]]]]);
check('a map keeps arbitrary keys and nested values',
    isset($r['ok']['tvs']['migx']),
    'its keys belong to the site, not to the schema');

$r = run($schema, ['id' => 1, 'tvs' => 'nope']);
check('a scalar where a map is declared is refused', isset($r['err']));

// ---- absent and null -------------------------------------------------------
echo "\nabsence\n";

$r = run($schema, ['id' => 1, 'pagetitle' => null]);
check('an explicit null is left alone, as arg() treats it as absent',
    array_key_exists('pagetitle', $r['ok'] ?? []) && $r['ok']['pagetitle'] === null);

$r = run($schema, []);
check('no arguments at all is not this check\'s business',
    isset($r['ok']) && $r['ok'] === [],
    'requireArg reports a missing one, where it knows the alternatives');

$r = run(Schema::object([]), ['anything' => 1]);
check('a tool declaring no properties is left alone',
    isset($r['ok']['anything']));

// ---- the schema itself -----------------------------------------------------
echo "\nthe advertised schema\n";

$built = Schema::object(['a' => Schema::string('x')], ['a']);
check('object() declares additionalProperties false',
    ($built['additionalProperties'] ?? null) === false,
    'so a validating client refuses before sending');

$map = Schema::map('free-form');
check('map() still declares additionalProperties true',
    ($map['additionalProperties'] ?? null) === true);

echo str_repeat('=', 80) . "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
