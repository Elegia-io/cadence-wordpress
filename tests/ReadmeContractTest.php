<?php
/**
 * The README's refusal table is the CALLER-FACING contract, so it is checked
 * rather than trusted.
 *
 * Every route's refusal codes are published as a `REFUSAL_CODES` constant so
 * the REST layer can be tested for covering all of them. The README documents
 * the same codes in prose, for the person writing the caller, and nothing bound
 * the two: `language_disagreement` was added to the constant, the status map,
 * the fixture and the count test, and the README table still listed twelve
 * codes. A reviewer found it. This is that finding written as the test, which
 * is where a prose check belongs.
 *
 * ONE DIRECTION ONLY, deliberately. Every published code must appear; the table
 * may name MORE, because it also documents codes older plugin versions sent and
 * codes belonging to routes whose constants live elsewhere. A two-way assertion
 * would fail on those and invite deleting rows a caller still needs.
 *
 * @package CadenceConnector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReadmeContractTest extends TestCase {

    /** @return list<string> */
    private function documented(): array {
        $readme = file_get_contents(__DIR__ . '/../README.md');
        $this->assertIsString($readme, 'README.md could not be read');
        // The rows are `| \`code\` | <status> | <prose> |`. The code is the only
        // thing matched: a status is asserted against the map below, not here.
        preg_match_all('/^\|\s*`([a-z_]+)`\s*\|/m', $readme, $m);
        return array_values(array_unique($m[1]));
    }

    public function test_every_published_refusal_code_is_in_the_readme(): void {
        $published = array_unique(array_merge(
            CadenceContentRequest::REFUSAL_CODES,
            CadenceReplaceRequest::REFUSAL_CODES,
            CadenceLinkRequest::REFUSAL_CODES
        ));
        // A DENOMINATOR, so a constant emptied by an edit cannot make this pass
        // by having nothing to look for.
        $this->assertGreaterThan(15, count($published));

        $documented = $this->documented();
        $this->assertNotEmpty($documented, 'no refusal rows were found in the README at all');

        $missing = array_values(array_diff($published, $documented));
        $this->assertSame([], $missing, sprintf(
            'these refusal codes are published but not in the README table: %s',
            implode(', ', $missing)));
    }

    /**
     * AND THE STATUS BESIDE EACH ONE IS THE STATUS THE ROUTE ANSWERS. A row
     * naming the right code with the wrong number is worse than a missing row:
     * a caller reads it and branches on it.
     */
    public function test_the_readme_status_beside_each_code_is_the_one_served(): void {
        $readme = file_get_contents(__DIR__ . '/../README.md');
        preg_match_all('/^\|\s*`([a-z_]+)`\s*\|\s*(\d{3})\s*\|/m', $readme, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, 'no code/status rows were found in the README');

        $checked = 0;
        foreach ($m as $row) {
            [, $code, $status] = $row;
            $served = CadenceRestRoute::STATUS[$code] ?? null;
            if ($served === null) {
                continue;   // a code this version no longer answers; see the class docblock
            }
            $this->assertSame((int) $status, $served, $code);
            $checked++;
        }
        // Counted, and refused on zero: a regex that stopped matching would
        // otherwise report a pass over nothing (#1381's shape).
        $this->assertGreaterThan(10, $checked,
            'the README rows parsed but almost none matched a served code, so this measured nothing');
    }
}
