<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NO WRITE WHILE WPML TRANSLATES EVERYTHING AUTOMATICALLY.
 *
 * With that mode on, WPML machine-translates every new or changed post by
 * itself, beside the translations this connector links. So `/content` and
 * `/content/replace` refuse while it is on, refuse while WPML will not say,
 * and write when it is off or when there is no WPML at all.
 *
 * Every test here asks WPML (or asks whether WPML is there), so all of them
 * are in the wpml lane.
 */
final class WpmlModeTest extends TestCase {

    protected function setUp(): void {
        WpStub::reset();
    }

    private static function multilingual(): array {
        return ['multilingual' => true, 'languages' => ['en']];
    }

    private static function monolingual(): void {
        WpStub::$wpml_reads = false;
        WpStub::$wpml_writes = false;
    }

    private function publish(array $declared): array {
        $body = [
            'piece_id'  => 'piece-1',
            'language'  => 'en',
            'declared'  => $declared,
            'post_type' => 'post',
            'status'    => 'publish',
            'title'     => 'The original',
            'content'   => '<p>Original body.</p>',
        ];
        return CadenceContentRequest::run($body, static fn (string $c): bool => true, null, null,
            CadenceAttest::KEY_ID,
            CadenceAttest::header('/content', CadenceAttest::fields('/content', $body), CadenceAttest::KEY_ID));
    }

    private function replace(array $published): array {
        $body = [
            'piece_id' => 'piece-1',
            'post_id'  => $published['post_id'],
            'revision' => $published['revision'],
            'title'    => 'The rewrite',
            'content'  => '<p>Rewritten body.</p>',
            'overwrite_adopted' => true,
            'site'     => 'example.test',
            'issued_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ];
        return CadenceReplaceRequest::run($body, null, CadenceAttest::KEY_ID,
            CadenceAttest::header('/content/replace',
                CadenceAttest::fields('/content/replace', $body), CadenceAttest::KEY_ID));
    }

    private static function point_at(string $class): void {
        (new ReflectionProperty(CadenceWpmlMode::class, 'option_class'))->setValue(null, $class);
    }

    /** @return array<string, array{callable(): void}> */
    public static function cannot_tell(): array {
        return [
            'class missing'  => [static function (): void { self::point_at('WPML\\Setup\\NoSuchOption'); }],
            'method missing' => [static function (): void { self::point_at(stdClass::class); }],
            'throws'         => [static function (): void { WpStub::$wpml_translate_everything_throws = true; }],
            'null'           => [static function (): void { WpStub::$wpml_translate_everything = null; }],
            'an array'       => [static function (): void { WpStub::$wpml_translate_everything = [true]; }],
            'another string' => [static function (): void { WpStub::$wpml_translate_everything = 'yes'; }],
            'autoloader throws' => [static function (): void {
                self::point_at('WPML\\Setup\\ThrowingOption');
                spl_autoload_register(self::$throwing_autoloader = static function (string $c): void {
                    if ($c === 'WPML\\Setup\\ThrowingOption') {
                        throw new RuntimeException('autoloader failed');
                    }
                });
            }],
        ];
    }

    private static ?Closure $throwing_autoloader = null;

    protected function tearDown(): void {
        if (self::$throwing_autoloader !== null) {
            spl_autoload_unregister(self::$throwing_autoloader);
            self::$throwing_autoloader = null;
        }
    }

    /** @return array<string, array{mixed, bool}> WPML's answer => whether it reads as on */
    public static function plain_answers(): array {
        return [
            'true' => [true, true], 'int 1' => [1, true], "string '1'" => ['1', true],
            'false' => [false, false], 'int 0' => [0, false], "string '0'" => ['0', false],
            'empty string' => ['', false],
        ];
    }

    private function assertNothingPublished(): void {
        $this->assertSame([], WpStub::$inserted, 'a post was created');
        $this->assertSame([], WpStub::$writes, 'WPML was asked to write');
        $this->assertSame([], WpStub::$meta, 'meta was written');
    }

    // ---- /content ---------------------------------------------------------

    #[Group('wpml')]
    public function test_content_is_refused_while_translate_everything_is_on(): void {
        WpStub::$wpml_translate_everything = true;
        $r = $this->publish(self::multilingual());

        $this->assertFalse($r['ok']);
        $this->assertSame('wpml_translate_everything_on', $r['code']);
        $this->assertStringContainsString('Switch off "Translate Everything Automatically"', $r['reason']);
        $this->assertNothingPublished();
    }

    /** The twin: the same request with the mode off is published. */
    #[Group('wpml')]
    public function test_content_is_published_while_translate_everything_is_off(): void {
        $r = $this->publish(self::multilingual());

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertTrue($r['created']);
        $this->assertCount(1, WpStub::$inserted);
    }

    #[Group('wpml')]
    #[DataProvider('cannot_tell')]
    public function test_content_is_refused_when_wpml_will_not_say(callable $arrange): void {
        $arrange();
        $r = $this->publish(self::multilingual());

        $this->assertFalse($r['ok']);
        $this->assertSame('wpml_translate_everything_unknown', $r['code']);
        $this->assertNothingPublished();
    }

    /** Each plain encoding of on is refused, and each of off is published. */
    #[Group('wpml')]
    #[DataProvider('plain_answers')]
    public function test_content_reads_the_plain_encodings_of_the_mode($answer, bool $on): void {
        WpStub::$wpml_translate_everything = $answer;
        $r = $this->publish(self::multilingual());

        if ($on) {
            $this->assertSame('wpml_translate_everything_on', $r['code'] ?? null);
            $this->assertNothingPublished();
        } else {
            $this->assertTrue($r['ok'], $r['reason'] ?? '');
            $this->assertCount(1, WpStub::$inserted);
        }
    }

    /** No WPML, no such mode: published, even with the stub saying "on". */
    #[Group('wpml')]
    public function test_content_on_a_site_with_no_wpml_is_published(): void {
        self::monolingual();
        WpStub::$wpml_translate_everything = true;
        $r = $this->publish(['multilingual' => false, 'languages' => ['en']]);

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(1, WpStub::$inserted);
    }

    // ---- /content/replace -------------------------------------------------

    #[Group('wpml')]
    public function test_a_rewrite_is_refused_while_translate_everything_is_on(): void {
        $published = $this->publish(self::multilingual());
        $this->assertTrue($published['ok'], $published['reason'] ?? '');
        WpStub::$wpml_translate_everything = true;

        $r = $this->replace($published);

        $this->assertFalse($r['ok']);
        $this->assertSame('wpml_translate_everything_on', $r['code']);
        $this->assertSame([], WpStub::$updated, 'the post was rewritten');
        $this->assertSame([], $GLOBALS['wpdb']->log, 'a transaction was opened');
    }

    /** The twin: the same rewrite with the mode off is written. */
    #[Group('wpml')]
    public function test_a_rewrite_is_written_while_translate_everything_is_off(): void {
        $published = $this->publish(self::multilingual());

        $r = $this->replace($published);

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(1, WpStub::$updated);
    }

    #[Group('wpml')]
    #[DataProvider('cannot_tell')]
    public function test_a_rewrite_is_refused_when_wpml_will_not_say(callable $arrange): void {
        $published = $this->publish(self::multilingual());
        $this->assertTrue($published['ok'], $published['reason'] ?? '');
        $arrange();

        $r = $this->replace($published);

        $this->assertFalse($r['ok']);
        $this->assertSame('wpml_translate_everything_unknown', $r['code']);
        $this->assertSame([], WpStub::$updated, 'the post was rewritten');
        $this->assertSame([], $GLOBALS['wpdb']->log, 'a transaction was opened');
    }

    #[Group('wpml')]
    public function test_a_rewrite_on_a_site_with_no_wpml_is_written(): void {
        self::monolingual();
        $published = $this->publish(['multilingual' => false, 'languages' => ['en']]);
        $this->assertTrue($published['ok'], $published['reason'] ?? '');
        WpStub::$wpml_translate_everything = true;

        $r = $this->replace($published);

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(1, WpStub::$updated);
    }

    // ---- the REST layer ---------------------------------------------------

    public function test_both_codes_answer_409(): void {
        $this->assertSame(409, CadenceRestRoute::STATUS['wpml_translate_everything_on']);
        $this->assertSame(409, CadenceRestRoute::STATUS['wpml_translate_everything_unknown']);
        foreach ([CadenceContentRequest::REFUSAL_CODES, CadenceReplaceRequest::REFUSAL_CODES] as $codes) {
            $this->assertContains('wpml_translate_everything_on', $codes);
            $this->assertContains('wpml_translate_everything_unknown', $codes);
        }
    }
}
