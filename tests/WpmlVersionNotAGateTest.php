<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * THE WPML VERSION IS A RECORD, NEVER A GATE.
 *
 * The plugin supports WPML 4.5 or newer, and asks WPML's hooks whether it can
 * link, never its version: a version check would refuse a site whose hooks
 * work. The version is still recorded in the `wpml_unavailable` refusal, so a
 * support report says which WPML was in play. This file fails if that record
 * is ever turned into a condition.
 *
 * It reads the plugin's PHP tokens, comments excluded, and holds two things:
 *
 *   1. `ICL_SITEPRESS_VERSION` is named in code only inside
 *      `observed_wpml_version`, so no `version_compare` or `if` elsewhere can
 *      read it directly.
 *   2. Every call of `observed_wpml_version` is the value of a `'wpml_version'`
 *      array element, so its result only ever flows into a reply.
 *
 * WHAT IT DOES NOT SEE: a later read of the `wpml_version` key back out of a
 * refusal array. Nothing in the plugin reads a refusal's detail keys to decide
 * anything today; this file does not prove that stays true.
 */
final class WpmlVersionNotAGateTest extends TestCase {

    private const HELPER = 'observed_wpml_version';

    /** @return array<string, list<array{0:int|string,1:string}>> file => code tokens */
    private static function code_tokens(): array {
        $root = dirname(__DIR__);
        $files = array_merge([$root . '/cadence-connector.php'], glob($root . '/includes/*.php'));
        $out = [];
        foreach ($files as $f) {
            $toks = [];
            foreach (token_get_all(file_get_contents($f)) as $t) {
                $t = is_array($t) ? [$t[0], $t[1]] : [$t, $t];
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }
                $toks[] = $t;
            }
            $out[basename($f)] = $toks;
        }
        return $out;
    }

    /** [start, end) token range of the helper's body in this file, or null. */
    private static function helper_body(array $toks): ?array {
        foreach ($toks as $i => $t) {
            if ($t[0] === T_FUNCTION && ($toks[$i + 1][1] ?? '') === self::HELPER) {
                $j = $i;
                while ($toks[$j][1] !== '{') {
                    $j++;
                }
                $depth = 0;
                for ($k = $j; $k < count($toks); $k++) {
                    $depth += $toks[$k][1] === '{' ? 1 : ($toks[$k][1] === '}' ? -1 : 0);
                    if ($depth === 0) {
                        return [$j, $k];
                    }
                }
            }
        }
        return null;
    }

    public function test_the_wpml_version_constant_is_read_only_inside_the_helper(): void {
        $inside = 0;
        foreach (self::code_tokens() as $file => $toks) {
            $body = self::helper_body($toks);
            foreach ($toks as $i => $t) {
                if (!str_contains($t[1], 'ICL_SITEPRESS_VERSION')) {
                    continue;
                }
                $in = $body !== null && $i > $body[0] && $i < $body[1];
                $this->assertTrue($in, "$file names ICL_SITEPRESS_VERSION outside " . self::HELPER);
                $inside++;
            }
        }
        // Refuses on zero: a helper renamed away would otherwise pass above.
        $this->assertGreaterThan(0, $inside, 'no read of ICL_SITEPRESS_VERSION found at all');
    }

    public function test_every_call_of_the_helper_is_the_value_of_a_wpml_version_element(): void {
        $calls = 0;
        foreach (self::code_tokens() as $file => $toks) {
            foreach ($toks as $i => $t) {
                if ($t[1] !== self::HELPER || ($toks[$i - 1][0] ?? null) === T_FUNCTION) {
                    continue;
                }
                $calls++;
                // `'wpml_version' => self::observed_wpml_version()`
                $this->assertSame(
                    ["'wpml_version'", '=>', 'self', '::'],
                    array_map(static fn ($x) => $x[1], array_slice($toks, $i - 4, 4)),
                    "$file calls " . self::HELPER . ' somewhere other than a wpml_version element'
                );
            }
        }
        $this->assertGreaterThan(0, $calls, 'the helper is called nowhere, so nothing is recorded');
    }
}
