<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * WPML DECLARED, NEVER DETECTED.
 *
 * The failure being made impossible: probe the site, find no WPML, publish one
 * language, report success. A multilingual client's run then ships a single
 * language and nothing says so. Every test here is about a disagreement
 * REFUSING, and about the refusal naming which side disagreed -- "WPML was
 * removed from the site" and "the tenant record is wrong" have different owners.
 */
final class LanguageDeclarationTest extends TestCase {

    protected function setUp(): void {
        WpStub::reset();
    }

    /** No WPML on this site, in the only way a site can say so: nothing listens. */
    private function monolingual_site(): void {
        WpStub::$wpml_reads  = false;
        WpStub::$wpml_writes = false;
    }

    #[Group('wpml')]
    public function test_a_monolingual_declaration_on_a_site_without_wpml_is_accepted(): void {
        $this->monolingual_site();
        $r = CadenceLanguageDeclaration::verify(['multilingual' => false, 'languages' => ['en']], 'en');
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['unsupported']);
    }

    /**
     * THE EXPENSIVE ONE. Declared multilingual, site has no WPML. Detection
     * would publish monolingual and call it success; this refuses.
     */
    #[Group('wpml')]
    public function test_declared_multilingual_on_a_site_without_wpml_refuses(): void {
        $this->monolingual_site();
        $r = CadenceLanguageDeclaration::verify(['multilingual' => true, 'languages' => ['en', 'de']], 'en');
        $this->assertFalse($r['ok']);
        $this->assertSame('capability_mismatch', $r['code']);
        $this->assertStringContainsString('tenant record declares', $r['reason']);
        $this->assertStringContainsString('this site implements no WPML', $r['reason']);
    }

    /** And the other direction, which is a stale record rather than a stale site. */
    #[Group('wpml')]
    public function test_declared_monolingual_on_a_multilingual_site_refuses(): void {
        $r = CadenceLanguageDeclaration::verify(['multilingual' => false, 'languages' => ['en']], 'en');
        $this->assertFalse($r['ok']);
        $this->assertSame('capability_mismatch', $r['code']);
        $this->assertStringContainsString('declares this client monolingual', $r['reason']);
        $this->assertStringContainsString('this site implements the WPML', $r['reason']);
    }

    /** A language the site does not serve is REPORTED, never quietly dropped. */
    #[Group('wpml')]
    public function test_a_declared_language_the_site_does_not_serve_is_reported(): void {
        WpStub::$active_languages = ['en' => [], 'de' => []];
        $r = CadenceLanguageDeclaration::verify(
            ['multilingual' => true, 'languages' => ['en', 'de', 'it']], 'en');
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame(['it'], $r['unsupported']);
    }

    /** And when it is THIS piece's own language, there is nothing to place. */
    #[Group('wpml')]
    public function test_the_pieces_own_language_being_unserved_refuses(): void {
        WpStub::$active_languages = ['en' => []];
        $r = CadenceLanguageDeclaration::verify(
            ['multilingual' => true, 'languages' => ['en', 'it']], 'it');
        $this->assertFalse($r['ok']);
        $this->assertSame('unsupported_language', $r['code']);
        $this->assertSame(['it'], $r['unsupported']);
    }

    /**
     * WPML PRESENT AND CONFIGURED WITH NOTHING is not "every language is fine".
     * The hooks answer, the map is empty, and an empty answer is an answer.
     */
    #[Group('wpml')]
    public function test_wpml_with_no_active_languages_serves_none_of_them(): void {
        WpStub::$active_languages = [];
        $r = CadenceLanguageDeclaration::verify(['multilingual' => true, 'languages' => ['en']], 'en');
        $this->assertFalse($r['ok']);
        $this->assertSame('unsupported_language', $r['code']);
    }

    /** A declaration that is missing, or not a declaration, is not a default. */
    #[Group('wpml')]
    public function test_an_absent_or_malformed_declaration_is_refused_rather_than_assumed(): void {
        foreach ([
            null,
            [],
            ['languages' => ['en']],
            ['multilingual' => 'false', 'languages' => ['en']],
            ['multilingual' => 0, 'languages' => ['en']],
            ['multilingual' => false],
            ['multilingual' => false, 'languages' => []],
            ['multilingual' => false, 'languages' => 'en'],
            ['multilingual' => false, 'languages' => ['en' => 'English']],
            ['multilingual' => false, 'languages' => [1]],
            ['multilingual' => false, 'languages' => ['EN']],
            ['multilingual' => false, 'languages' => ['en_US']],
            ['multilingual' => true, 'languages' => ['en', 'de']],   // two, for a piece in `it`
        ] as $i => $declared) {
            $r = CadenceLanguageDeclaration::verify($declared, 'it');
            $this->assertFalse($r['ok'], (string) $i);
            $this->assertSame('bad_request', $r['code'], (string) $i);
        }
        // THE TWIN: the same call with a declaration that IS one is accepted,
        // so the run above is the refusals firing rather than a blanket no.
        WpStub::$active_languages = ['it' => []];
        $this->assertTrue(CadenceLanguageDeclaration::verify(
            ['multilingual' => true, 'languages' => ['it']], 'it')['ok']);
    }

    /** A monolingual tenant naming two languages contradicts itself. */
    #[Group('wpml')]
    public function test_a_monolingual_declaration_naming_two_languages_is_refused(): void {
        $this->monolingual_site();
        $r = CadenceLanguageDeclaration::verify(['multilingual' => false, 'languages' => ['en', 'de']], 'en');
        $this->assertFalse($r['ok']);
        $this->assertSame('bad_request', $r['code']);
    }

    /** Every code it can refuse with is published, and every one is reachable. */
    public function test_every_published_refusal_code_is_mapped_by_the_rest_layer(): void {
        foreach (CadenceLanguageDeclaration::REFUSAL_CODES as $code) {
            $this->assertArrayHasKey($code, CadenceRestRoute::STATUS, $code);
        }
    }
}
