<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `/content/read`: one post's text, answered only to the key that may rewrite
 * it. Every refusal below has a twin that differs from it in the one fact the
 * refusal is about, and answers.
 */
final class ReadRequestTest extends TestCase {

    private const SIGN = "\0sign";
    private const ID = 11;

    protected function setUp(): void {
        WpStub::reset();
    }

    /** A post this key reaches, unless `$over` says otherwise. */
    private function post(array $over = [], array $meta = []): void {
        WpStub::$posts[self::ID] = array_merge([
            'post_type' => 'post', 'status' => 'publish', 'post_status' => 'publish',
            'language' => 'en', 'trid' => 5,
            'post_title' => 'The title', 'post_content' => '<p>The body.</p>',
            'post_name' => 'the-title', 'post_excerpt' => 'An excerpt.', 'post_password' => '',
        ], $over);
        // THE GROUP: 12 is this key's translation; 13 another key's; 14 a
        // human's, which Cadence never touched.
        foreach ([12 => 'it', 13 => 'de', 14 => 'fr'] as $id => $lang) {
            WpStub::$posts[$id] = ['post_type' => 'post', 'status' => 'draft', 'language' => $lang,
                                   'trid' => 5, 'post_title' => 'T ' . $lang, 'post_content' => ''];
        }
        WpStub::$meta[12] = [CadenceContentRequest::META => 'piece-1-it',
                             CadenceContentRequest::KEY_META => CadenceAttest::KEY_ID];
        WpStub::$meta[13] = [CadenceContentRequest::META => 'piece-9-de',
                             CadenceContentRequest::KEY_META => 'ca11ab1e0000key2'];
        WpStub::$meta[self::ID] = array_merge([
            CadenceContentRequest::META     => 'piece-1',
            CadenceContentRequest::KEY_META => CadenceAttest::KEY_ID,
            CadenceReadRequest::SEO_META['seo_title']       => 'SEO title',
            CadenceReadRequest::SEO_META['seo_description'] => 'SEO description',
        ], $meta);
    }

    private function read(array $body = [], ?string $key_id = CadenceAttest::KEY_ID,
                          $attestation = self::SIGN, ?array $post_types = null): array {
        $body = array_merge(['link' => 'https://example.test/?p=' . self::ID,
                             'site' => 'example.test', 'issued_at' => self::now()], $body);
        return CadenceReadRequest::run($body, $post_types, $key_id,
            $attestation === self::SIGN
                ? CadenceAttest::header('/content/read', $body, $key_id)
                : $attestation);
    }

    /** What the site was asked about posts: options (the key, `home`) are not posts. */
    private static function post_reads(): array {
        return array_filter(WpStub::$reads, static fn (string $k): bool =>
            !str_starts_with($k, 'get_option:') && !str_starts_with($k, 'wpdb:option:'),
            ARRAY_FILTER_USE_KEY);
    }

    private static function now(int $offset = 0): string {
        return gmdate('Y-m-d\TH:i:s\Z', time() + $offset);
    }

    #[Group('wpml')]
    public function test_a_signed_read_answers_the_post_and_the_revision_the_site_holds(): void {
        $this->post();
        $r = $this->read();
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame('verified', $r['attestation']);
        $this->assertSame([
            'post_id' => self::ID, 'piece_id' => 'piece-1', 'language' => 'en',
            'title' => 'The title', 'content' => '<p>The body.</p>', 'excerpt' => 'An excerpt.',
            'seo_title' => 'SEO title', 'seo_description' => 'SEO description',
            'slug' => 'the-title',
            'revision' => CadenceRevision::of('The title', '<p>The body.</p>'),
            'translations' => ['it' => 12],
        ], array_merge($r['report'], ['translations' => (array) $r['report']['translations']]));

        $body = CadenceRestRoute::respond($r)['body'];
        $this->assertSame(200, CadenceRestRoute::respond($r)['status']);
        foreach (CadenceReadRequest::REPLY_FIELDS as $name) {
            $this->assertArrayHasKey($name, $body, $name . ' is missing from the reply');
        }
        $this->assertSame('{}', json_encode((object) []), 'sanity');
        $this->assertStringContainsString('"translations":{"it":12}', json_encode($body));
    }

    #[Group('wpml')]
    public function test_a_post_alone_in_its_group_answers_translations_as_an_empty_object(): void {
        $this->post(['trid' => 9]);
        $r = $this->read();
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertStringContainsString('"translations":{}', json_encode(CadenceRestRoute::respond($r)['body']));
    }

    #[Group('wpml')]
    public function test_the_read_writes_nothing(): void {
        $this->post();
        $meta = WpStub::$meta;
        $posts = WpStub::$posts;
        $this->assertTrue($this->read()['ok']);
        $this->assertSame($meta, WpStub::$meta);
        $this->assertSame($posts, WpStub::$posts);
        $this->assertSame([], WpStub::$updated);
        $this->assertSame([], WpStub::$meta_added);
        $this->assertSame([], WpStub::$writes);
    }

    /** CONJUNCT `created_by`. Twin: the answering test, stamped with this key. */
    public function test_another_keys_post_is_refused(): void {
        $this->post([], [CadenceContentRequest::KEY_META => 'ca11ab1e0000key2']);
        $r = $this->read();
        $this->assertFalse($r['ok']);
        $this->assertSame('post_out_of_scope', $r['code']);
        $this->assertArrayNotHasKey('report', $r);
    }

    /** CONJUNCT `scope_admits`. Twin: the answering test, which carries a piece. */
    public function test_a_post_with_no_piece_identity_is_refused(): void {
        $this->post([], [CadenceContentRequest::META => '']);
        $r = $this->read();
        $this->assertFalse($r['ok']);
        $this->assertSame('post_out_of_scope', $r['code']);
    }

    /** ONE CODE AND ONE SENTENCE for both, so the refusal says nothing about which fact failed. */
    public function test_both_scope_refusals_share_one_code_and_one_sentence(): void {
        $this->post([], [CadenceContentRequest::KEY_META => 'ca11ab1e0000key2']);
        $other = $this->read();
        $this->post([], [CadenceContentRequest::META => '']);
        $unmade = $this->read();
        $this->assertSame([$other['code'], $other['reason']], [$unmade['code'], $unmade['reason']]);
        $this->assertSame(403, CadenceRestRoute::STATUS[$other['code']]);
    }

    /** Twin: the answering test, whose password is ''. */
    public function test_a_password_protected_post_is_refused(): void {
        $this->post(['post_password' => 'hunter2']);
        $r = $this->read();
        $this->assertFalse($r['ok']);
        $this->assertSame('read_password_protected', $r['code']);
        $this->assertArrayNotHasKey('report', $r);
        $this->assertStringNotContainsString('The body', json_encode($r));
    }

    /** Twin: the answering test, whose link is this site's. */
    public function test_a_foreign_link_is_refused(): void {
        $this->post();
        $r = $this->read(['link' => 'https://elsewhere.test/?p=' . self::ID]);
        $this->assertFalse($r['ok']);
        $this->assertSame('post_out_of_scope', $r['code']);
    }

    public function test_a_link_to_no_post_is_refused(): void {
        $this->post();
        $r = $this->read(['link' => 'https://example.test/?p=999']);
        $this->assertFalse($r['ok']);
        $this->assertSame('post_out_of_scope', $r['code']);
    }

    /**
     * NO EXISTENCE ORACLE. A link that names no post, a post that is not here,
     * and a post that is here and not this key's all answer one code and one
     * sentence, so a key cannot map which ids exist on a shared site.
     */
    public function test_missing_unresolved_and_foreign_answer_alike(): void {
        $this->post([], [CadenceContentRequest::KEY_META => 'ca11ab1e0000key2']);
        $seen = [];
        foreach (['https://example.test/?p=999', 'https://elsewhere.test/?p=11',
                  'https://example.test/no-such-path/', 'https://example.test/?p=11'] as $link) {
            $r = $this->read(['link' => $link]);
            $seen[] = [$r['code'], $r['reason']];
        }
        $this->assertCount(1, array_unique(array_map('serialize', $seen)), print_r($seen, true));
        $this->assertStringNotContainsString('11', $seen[0][1]);
    }

    /** CONJUNCT the key's post-type scope. Twin: the in-scope read below. */
    public function test_a_post_outside_the_keys_type_scope_is_refused(): void {
        $this->post();
        $r = $this->read([], CadenceAttest::KEY_ID, self::SIGN, ['page']);
        $this->assertSame('post_out_of_scope', $r['code']);
        $this->post([], [CadenceContentRequest::META => '']);
        $this->assertSame($this->read()['reason'], $r['reason'], 'the type refusal says which fact failed');
    }

    #[Group('wpml')]
    public function test_a_post_inside_the_keys_type_scope_is_read(): void {
        $this->post();
        $r = $this->read([], CadenceAttest::KEY_ID, self::SIGN, ['page', 'post']);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
    }

    /** A translation reaches the reply only when this key reaches it too. */
    #[Group('wpml')]
    public function test_translations_list_only_this_keys_posts(): void {
        $this->post();
        $this->assertSame(['it' => 12], (array) $this->read()['report']['translations']);
        // Twin: made this key's, the other tenant's post is listed.
        WpStub::$meta[13][CadenceContentRequest::KEY_META] = CadenceAttest::KEY_ID;
        $this->assertSame(['de' => 13, 'it' => 12], (array) $this->read()['report']['translations']);
    }

    /** @return array<string, array{0: string}> */
    public static function statuses(): array {
        return array_combine(CadenceAdoptRequest::STATUSES,
                             array_map(static fn (string $s): array => [$s], CadenceAdoptRequest::STATUSES));
    }

    /** Twin of the refusal below: every status Cadence places a post in is read. */
    #[Group('wpml')]
    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function test_a_post_in_a_placed_status_is_read(string $status): void {
        $this->post(['post_status' => $status]);
        $this->assertTrue($this->read()['ok']);
    }

    /** A trashed post, an auto-draft or a revision row is not text anyone placed; refused alike. */
    public function test_a_trashed_post_is_refused(): void {
        foreach (['trash', 'auto-draft', 'inherit'] as $status) {
            $this->post(['post_status' => $status]);
            $r = $this->read();
            $this->assertSame('post_out_of_scope', $r['code'] ?? null, $status);
        }
    }

    /** Twin: the answering test, signed over the same link it sends. */
    public function test_a_signature_over_another_link_is_refused(): void {
        $this->post();
        $r = $this->read(['link' => 'https://example.test/?p=' . self::ID], CadenceAttest::KEY_ID,
            CadenceAttest::header('/content/read', ['link' => 'https://example.test/?p=12',
                                  'site' => 'example.test', 'issued_at' => self::now()],
                                  CadenceAttest::KEY_ID));
        $this->assertFalse($r['ok']);
        $this->assertSame(CadenceAttestation::CODE, $r['code']);
        $this->assertSame('mismatch', $r['attestation_branch']);
    }

    /** THE READ TAKES NO EXEMPTION: a key allowed to publish unsigned still signs its reads. */
    public function test_an_exempt_key_reading_unsigned_is_refused(): void {
        $this->post();
        CadenceAttest::header('/content/read', ['link' => 'x', 'site' => 'example.test',
                                                'issued_at' => self::now()], CadenceAttest::KEY_ID);
        CadenceKey::set_unsigned_ok(CadenceAttest::KEY_ID, true, 3);
        $r = $this->read([], CadenceAttest::KEY_ID, null);
        $this->assertFalse($r['ok']);
        $this->assertSame('exempt_refused', $r['attestation_branch']);
        // `NO_EXEMPTION` is the boundary and names the exemption; the check in
        // `run` is the tripwire behind it and says less.
        $this->assertStringContainsString('unsigned-publish exemption', $r['reason']);
    }

    /** A read signed for another site, or two sites sharing a host, reads nothing. */
    public function test_a_read_signed_for_another_site_is_refused(): void {
        $this->post();
        WpStub::$reads = [];
        foreach (['other.test', 'example.test/blog'] as $site) {
            $r = $this->read(['site' => $site]);
            $this->assertSame('read_wrong_site', $r['code'] ?? null, $site);
            $this->assertSame(403, CadenceRestRoute::STATUS['read_wrong_site']);
        }
        $this->assertSame([], self::post_reads());
    }

    /** Twin: the same body signed for this site is read. */
    #[Group('wpml')]
    public function test_twin_a_read_signed_for_this_site_is_read(): void {
        $this->post();
        $r = $this->read(['site' => 'example.test']);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
    }

    /** A captured read replays for at most the window, either way. */
    public function test_a_read_outside_the_window_is_expired(): void {
        $this->post();
        WpStub::$reads = [];
        foreach ([-301, 301] as $offset) {
            $r = $this->read(['issued_at' => self::now($offset)]);
            $this->assertSame('read_expired', $r['code'] ?? null, (string) $offset);
        }
        $this->assertSame(403, CadenceRestRoute::STATUS['read_expired']);
        $this->assertSame([], self::post_reads());
    }

    /** Twin: inside the window, at either edge, the read answers. */
    #[Group('wpml')]
    public function test_twin_a_read_inside_the_window_is_read(): void {
        $this->post();
        foreach ([-299, 299] as $offset) {
            $r = $this->read(['issued_at' => self::now($offset)]);
            $this->assertTrue($r['ok'], $r['reason'] ?? (string) $offset);
        }
    }

    public function test_a_site_or_instant_missing_from_the_body_is_refused(): void {
        foreach (['site', 'issued_at'] as $name) {
            $body = ['link' => 'https://example.test/?p=' . self::ID, 'site' => 'example.test',
                     'issued_at' => self::now()];
            unset($body[$name]);
            $r = CadenceReadRequest::run($body, null, CadenceAttest::KEY_ID, null);
            $this->assertSame('bad_read', $r['code'], $name);
        }
    }

    public function test_a_body_without_a_link_is_refused(): void {
        $r = CadenceReadRequest::run([], null, CadenceAttest::KEY_ID, null);
        $this->assertSame('bad_read', $r['code']);
        $this->assertSame(400, CadenceRestRoute::STATUS['bad_read']);
    }

    #[Group('wpml')]
    public function test_a_post_wpml_gives_no_language_is_refused(): void {
        $this->post(['wpml_knows' => false]);
        $r = $this->read();
        $this->assertFalse($r['ok']);
        $this->assertSame('group_unknown', $r['code']);
    }
}
