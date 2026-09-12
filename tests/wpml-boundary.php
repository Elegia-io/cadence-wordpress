<?php
/**
 * THE LINE THIS SUITE IS SPLIT ON, CHECKED RATHER THAN STATED.
 *
 * tests/bootstrap.php stubs WordPress and WPML. The WordPress half is
 * unavoidable and harmless: a refusal that never reaches a write is the same
 * refusal on a live site. The WPML half is not. Three things this plugin
 * believes about `wpml_set_element_language_details` come from WPML's
 * documentation and from nothing else -- there is no WPML licence in the
 * environment this was built in (the header of
 * includes/class-cadence-link-request.php lists them) -- so a test whose
 * verdict rests on WPML's behaviour is checking the stub's beliefs, and would
 * pass identically if every one of them were wrong.
 *
 * So the suite runs in two lanes:
 *
 *   STUBBED  (`--exclude-group wpml`)  reaches no WPML hook at all. Every
 *            verdict in it is as true here as on a live site.
 *   WPML     (`--group wpml`)          reaches one. Every verdict in it is
 *            conditional on the stub modelling WPML correctly, and the lane
 *            exists so that when a real-WPML runner appears there is a named
 *            set for it to re-run rather than an archaeology exercise.
 *
 * WHY NOT JUST RUN IT AGAINST A REAL WPML. The operator HOLDS a WPML licence,
 * so a self-hosted runner carrying WordPress + WPML is available and the
 * blocker is COST -- a machine to keep, patch and pay for, against a plugin
 * whose whole CI is two ten-minute jobs on GitHub's runners. It is a price
 * not yet paid, NOT an impossibility, and nobody should record it as one.
 * Building that lane is the other half of Elegia-io/cadence#1262 and is not
 * what this file does.
 *
 * WHAT COUNTS AS CROSSING, and why it is not a list of names or of spellings.
 * A test crosses when its execution reaches a hook in WPML's `wpml_`
 * namespace -- recorded by `WpmlBoundary` inside the stubs themselves, so it
 * makes no difference whether the test names the hook, calls a private helper
 * that does, or gets there through three frames of the plugin. The prefix is
 * the namespace WPML publishes under, not an enumeration of the four hooks
 * this suite happens to stub, so a fifth added tomorrow is inside the
 * boundary on the day it is written.
 *
 * IT FAILS IN BOTH DIRECTIONS. A stubbed-lane test that reaches a hook is a
 * crossing; a `#[Group('wpml')]` test that reaches none is a test quietly
 * moved out of the honest lane, which is the same defect facing the other
 * way. And it refuses on an empty lane: a selector that matched nothing
 * would otherwise report "0 crossings" and exit 0, which reads exactly like a
 * pass.
 *
 * Run: `php tests/wpml-boundary.php` (PHPUNIT_PHAR, else ./.phpunit.phar).
 */
declare(strict_types=1);

const BOUNDARY_GROUP = 'wpml';

$root = dirname(__DIR__);
$phar = getenv('PHPUNIT_PHAR') ?: $root . '/.phpunit.phar';
if (!is_file($phar)) {
    fwrite(STDERR, "wpml-boundary: no PHPUnit phar at {$phar}; set PHPUNIT_PHAR\n");
    exit(2);
}

/**
 * Run one lane and return what it selected and what it reached.
 *
 * Two invocations because they answer different questions: `--list-tests`
 * names what the selector picked, including tests that would be skipped, and
 * the run is the only thing that can say what was reached. Comparing the two
 * is what finds a marked test that touches nothing.
 *
 * @param list<string> $select the group selector
 * @return array{tests: list<string>, crossings: array<string, list<string>>, prefix: string}
 */
function lane(string $phar, string $root, array $select): array {
    $base = array_merge(
        [PHP_BINARY, $phar, '--bootstrap', $root . '/tests/bootstrap.php',
         '--do-not-cache-result', '--colors=never', '--fail-on-empty-test-suite'],
        $select,
        [$root . '/tests']
    );
    $quote = static fn (array $argv): string => implode(' ', array_map('escapeshellarg', $argv));

    $listed = [];
    exec($quote(array_merge($base, ['--list-tests'])) . ' 2>&1', $listed, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "wpml-boundary: listing " . implode(' ', $select) . " failed:\n"
            . implode("\n", $listed) . "\n");
        exit(2);
    }
    $tests = [];
    foreach ($listed as $line) {
        if (!str_starts_with($line, ' - ')) {
            continue;
        }
        // ONE METHOD PER ENTRY, NOT ONE DATA ROW. A provider's rows are the
        // same method and cross or do not cross together, and the recorder
        // names the method. PHPUnit 11 spells a row `method#3` when the
        // provider numbers them and `method"blank"` when it names them -- the
        // second is why this is not a `#` split; a PHP method name can hold
        // neither character, so either one is the row label and nothing else.
        $name = preg_replace('/(#\d+|".*")$/', '', substr($line, 3));
        $tests[$name] = true;
    }

    // AN EMPTY LANE IS NOT A CLEAN ONE, and it is checked here rather than
    // after both lanes are in hand because a selector matching nothing -- a
    // renamed group, a moved directory -- leaves the other side unmeasured
    // while every count printed below still reads as zero crossings.
    if ($tests === []) {
        fwrite(STDERR, "wpml-boundary: the " . implode(' ', $select)
            . " lane selected 0 tests, which is not a pass\n");
        exit(2);
    }

    $log = tempnam(sys_get_temp_dir(), 'wpml-boundary');
    $out = [];
    exec('CADENCE_WPML_BOUNDARY_LOG=' . escapeshellarg($log) . ' ' . $quote($base) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "wpml-boundary: the " . implode(' ', $select) . " lane is not green, so the\n"
            . "boundary it would be measured on does not exist yet:\n" . implode("\n", $out) . "\n");
        unlink($log);
        exit(2);
    }
    $recorded = json_decode((string) file_get_contents($log), true);
    unlink($log);
    // A RUN THAT LEFT NO LOG MEASURED NOTHING, whatever its exit code says.
    if (!is_array($recorded) || !isset($recorded['prefix'], $recorded['crossings'])) {
        fwrite(STDERR, "wpml-boundary: the " . implode(' ', $select) . " lane wrote no boundary log, "
            . "so nothing about it was measured\n");
        exit(2);
    }

    return ['tests' => array_keys($tests), 'crossings' => $recorded['crossings'],
            'prefix' => $recorded['prefix']];
}

$stubbed = lane($phar, $root, ['--exclude-group', BOUNDARY_GROUP]);
$wpml    = lane($phar, $root, ['--group', BOUNDARY_GROUP]);

// Reported rather than restated: the prefix comes back from the recorder that
// keyed on it, so this file holds no second copy to drift.
$prefix = $stubbed['prefix'];
if ($wpml['prefix'] !== $prefix) {
    fwrite(STDERR, "wpml-boundary: the two lanes recorded against different prefixes\n");
    exit(2);
}

$n_stubbed = count($stubbed['tests']);
$n_wpml    = count($wpml['tests']);
printf("wpml boundary: %d test(s) in the stubbed lane, %d in the wpml lane\n", $n_stubbed, $n_wpml);

$failed = false;

// DIRECTION ONE: a stubbed-lane test that reached WPML. Its verdict depends on
// the stub's beliefs, so it belongs in the other lane and must say so.
$crossed = $stubbed['crossings'];
printf("stubbed lane: %d of %d test(s) reached a %s* hook\n",
       count($crossed), $n_stubbed, $prefix);
foreach ($crossed as $test => $hooks) {
    fwrite(STDERR, "  CROSSES: {$test} reached " . implode(', ', $hooks)
        . " -- add #[Group('" . BOUNDARY_GROUP . "')]\n");
    $failed = true;
}

// DIRECTION TWO: a marked test that reached nothing. It is out of the lane
// whose verdicts a real WordPress would confirm, for no reason it can show.
$silent = array_values(array_diff($wpml['tests'], array_keys($wpml['crossings'])));
printf("wpml lane:    %d of %d test(s) reached a %s* hook\n",
       count($wpml['crossings']), $n_wpml, $prefix);
foreach ($silent as $test) {
    fwrite(STDERR, "  MARKED BUT DOES NOT CROSS: {$test} reached no "
        . $prefix . "* hook -- drop #[Group('" . BOUNDARY_GROUP . "')]\n");
    $failed = true;
}

exit($failed ? 1 : 0);
