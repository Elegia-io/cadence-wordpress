<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `/content/reslug`: change one post's slug, bound to the slug the caller saw
 * and to a signed confirmation spent once. Every refusal has a twin: the
 * answering test differs from it in the one fact the refusal is about.
 */
final class ReslugRequestTest extends TestCase {

    private const SIGN = "\0sign";
    private const ID = 21;

    protected function setUp(): void {
        WpStub::reset();
        WpStub::$posts[self::ID] = ['post_type' => 'post', 'status' => 'publish', 'language' => 'en',
                                    'trid' => null, 'post_title' => 'T', 'post_content' => 'C',
                                    'post_name' => 'old-slug'];
        WpStub::$meta[self::ID] = [CadenceContentRequest::META => 'piece-1',
                                   CadenceContentRequest::KEY_META => CadenceAttest::KEY_ID];
    }

    private function body(array $over = []): array {
        return array_merge(['piece_id' => 'piece-1', 'post_id' => self::ID, 'old_slug' => 'old-slug',
                            'slug' => 'new-slug', 'overwrite_adopted' => true, 'site' => 'example.test',
                            'issued_at' => gmdate('Y-m-d\TH:i:s\Z')], $over);
    }

    private function reslug(array $body, ?string $key_id = CadenceAttest::KEY_ID,
                            $attestation = self::SIGN, ?array $post_types = null): array {
        return CadenceReslugRequest::run($body, $post_types, $key_id,
            $attestation === self::SIGN
                ? CadenceAttest::header('/content/reslug',
                    CadenceAttest::fields('/content/reslug', $body), $key_id)
                : $attestation);
    }

    public function test_a_confirmed_reslug_answers_the_slug_wordpress_stored(): void {
        $r = $this->reslug($this->body());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame(['post_id' => self::ID, 'slug' => 'new-slug'], $r['report']);
        $this->assertSame('new-slug', WpStub::$posts[self::ID]['post_name']);
        $this->assertSame([['ID' => self::ID, 'post_name' => 'new-slug']], WpStub::$updated);
        $body = CadenceRestRoute::respond($r)['body'];
        foreach (CadenceReslugRequest::REPLY_FIELDS as $name) {
            $this->assertArrayHasKey($name, $body, $name . ' is missing from the reply');
        }
    }

    public function test_a_taken_slug_answers_the_suffixed_one(): void {
        WpStub::$posts[22] = ['post_type' => 'post', 'post_name' => 'new-slug', 'trid' => null];
        $r = $this->reslug($this->body());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame('new-slug-2', $r['report']['slug']);
    }

    /** @return array<string, array{0: array}> one confirmation field missing per row */
    public static function missing_confirmation(): array {
        return ['all three' => [['overwrite_adopted', 'site', 'issued_at']],
                'overwrite_adopted' => [['overwrite_adopted']], 'site' => [['site']],
                'issued_at' => [['issued_at']]];
    }

    /** Twin: the answering test, which carries all three. */
    #[\PHPUnit\Framework\Attributes\DataProvider('missing_confirmation')]
    public function test_a_reslug_without_the_confirmation_trio_is_refused(array $drop): void {
        $r = $this->reslug(array_diff_key($this->body(), array_flip($drop)));
        $this->assertFalse($r['ok']);
        $this->assertSame('bad_reslug', $r['code']);
        $this->assertSame([], WpStub::$updated);
    }

    /** Twin: the answering test, whose confirmation says true. */
    public function test_a_confirmation_that_says_false_is_refused(): void {
        $r = $this->reslug($this->body(['overwrite_adopted' => false]));
        $this->assertSame('post_adopted', $r['code']);
        $this->assertSame([], WpStub::$updated);
    }

    /** Twin: the answering test, signed for this site. */
    public function test_a_confirmation_for_another_site_is_refused(): void {
        $r = $this->reslug($this->body(['site' => 'other.test']));
        $this->assertSame('confirmation_wrong_site', $r['code']);
    }

    /** Twin: the answering test, issued now. */
    public function test_an_expired_confirmation_is_refused(): void {
        $r = $this->reslug($this->body(['issued_at' => '2020-01-01T00:00:00Z']));
        $this->assertSame('confirmation_expired', $r['code']);
    }

    /** Twin: the answering test, signed over the slug it sends. */
    public function test_a_slug_changed_after_signing_is_refused(): void {
        $r = $this->reslug($this->body(['slug' => 'evil']), CadenceAttest::KEY_ID,
            CadenceAttest::header('/content/reslug',
                CadenceAttest::fields('/content/reslug', $this->body()), CadenceAttest::KEY_ID));
        $this->assertSame(CadenceAttestation::CODE, $r['code']);
        $this->assertSame('mismatch', $r['attestation_branch']);
        $this->assertSame('old-slug', WpStub::$posts[self::ID]['post_name']);
    }

    /** THE ROUTE TAKES NO EXEMPTION. */
    public function test_an_exempt_key_reslugging_unsigned_is_refused(): void {
        CadenceAttest::header('/content/reslug', CadenceAttest::fields('/content/reslug', $this->body()),
                              CadenceAttest::KEY_ID);
        CadenceKey::set_unsigned_ok(CadenceAttest::KEY_ID, true, 3);
        $r = $this->reslug($this->body(), CadenceAttest::KEY_ID, null);
        $this->assertSame('exempt_refused', $r['attestation_branch']);
        $this->assertStringContainsString('unsigned-publish exemption', $r['reason']);
        $this->assertSame([], WpStub::$updated);
    }

    /** Twin: the answering test, naming the piece the post holds. */
    public function test_a_stale_piece_id_is_refused(): void {
        $r = $this->reslug($this->body(['piece_id' => 'piece-0']));
        $this->assertSame('identifier_mismatch', $r['code']);
        $this->assertSame([], WpStub::$updated);
    }

    /** Twin: the answering test, whose type is in scope. */
    public function test_a_post_of_a_type_out_of_scope_is_refused(): void {
        $r = $this->reslug($this->body(), CadenceAttest::KEY_ID, self::SIGN, ['page']);
        $this->assertSame('existing_post_type_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$updated);
    }

    /** Twin: the answering test, whose old_slug is the post's. */
    public function test_an_old_slug_that_is_not_the_posts_is_refused(): void {
        WpStub::$posts[self::ID]['post_name'] = 'edited-by-hand';
        $r = $this->reslug($this->body());
        $this->assertSame('slug_mismatch', $r['code']);
        $this->assertSame([], WpStub::$updated);
        $this->assertSame('edited-by-hand', WpStub::$posts[self::ID]['post_name']);
    }

    public function test_a_second_reslug_with_the_same_confirmation_is_refused(): void {
        $body = $this->body();
        $this->assertTrue($this->reslug($body)['ok']);
        // The slug put back by hand, so only the spent confirmation stands in the way.
        WpStub::$posts[self::ID]['post_name'] = 'old-slug';
        $r = $this->reslug($body);
        $this->assertSame('confirmation_spent', $r['code']);
        $this->assertCount(1, WpStub::$updated);
    }

    public function test_the_spent_digest_lives_in_the_replace_routes_meta(): void {
        $this->assertTrue($this->reslug($this->body())['ok']);
        $this->assertArrayHasKey(CadenceReplaceRequest::SPENT_META, WpStub::$meta[self::ID]);
    }

    /** THE KIND NOT RECORDED: the slug change rolls back and spends nothing. */
    public function test_a_kind_that_cannot_be_recorded_rolls_the_slug_back(): void {
        WpStub::$meta_add_fails = [CadenceReplaceRequest::KIND_META];
        $r = $this->reslug($this->body());
        $this->assertSame('update_failed', $r['code'] ?? null, $r['reason'] ?? '');
        $log = $GLOBALS['wpdb']->log;
        $this->assertSame('ROLLBACK', end($log));
        $this->assertNotContains('COMMIT', $log);
        $this->assertArrayNotHasKey(CadenceReplaceRequest::SPENT_META, WpStub::$meta[self::ID]);
    }

    /** The site's record says a slug change was confirmed, not a text. */
    public function test_the_record_says_a_slug_change_was_confirmed(): void {
        $this->assertTrue($this->reslug($this->body())['ok']);
        $record = CadenceReplaceRequest::confirmations(self::ID);
        $this->assertSame(['slug'], array_column($record, 'kind'));
        $this->assertSame('client confirmed this slug change', $record[0]['note']);
    }

    /**
     * ONE ANSWER FOR EVERY POST THIS KEY DOES NOT REACH. A missing id,
     * another key's post (in or out of this key's type scope) and a human's
     * post all refuse with the same code and the same sentence, and
     * the sentence names no id. Each case differs from the answering test in
     * the one fact its conjunct is about; twins: `a_confirmed_reslug...`.
     */
    #[DataProvider('unreached')]
    public function test_a_post_this_key_does_not_reach_answers_one_refusal(callable $arrange): void {
        [$over, $post_types] = $arrange();
        $body = $this->body($over);
        $r = $this->reslug($body, CadenceAttest::KEY_ID, self::SIGN, $post_types);
        $this->assertSame(['ok' => false, 'code' => 'post_out_of_scope',
                           'reason' => CadenceReplaceRequest::OUT_OF_SCOPE_REASON], $r);
        $this->assertStringNotContainsString((string) $body['post_id'], $r['reason']);
        $this->assertSame([], WpStub::$updated);
    }

    public static function unreached(): array {
        return [
            'missing id' => [fn () => [['post_id' => 999], null]],
            'another key\'s post' => [function () {
                WpStub::$meta[self::ID][CadenceContentRequest::KEY_META] = 'ca11ab1e0000key2';
                return [[], null];
            }],
            'a human\'s post' => [function () {
                WpStub::$meta[self::ID] = [];
                return [[], null];
            }],
            // THE TWIN of the hint: another key's post in a type this key
            // does not reach still answers the one refusal.
            'another key, type out of scope' => [function () {
                WpStub::$meta[self::ID][CadenceContentRequest::KEY_META] = 'ca11ab1e0000key2';
                return [[], ['page']];
            }],
        ];
    }

    /** Twin of the type case: the same key, scoped to the post's own type, answers. */
    public function test_a_key_scoped_to_the_posts_type_reslugs(): void {
        $this->assertTrue($this->reslug($this->body(), CadenceAttest::KEY_ID, self::SIGN, ['post'])['ok']);
    }

    public function test_a_blank_slug_is_refused(): void {
        $r = $this->reslug($this->body(['slug' => ' ']));
        $this->assertSame('bad_reslug', $r['code']);
    }
}
