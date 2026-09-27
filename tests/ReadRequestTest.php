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
            'post_type' => 'post', 'status' => 'publish', 'language' => 'en', 'trid' => 5,
            'post_title' => 'The title', 'post_content' => '<p>The body.</p>',
            'post_name' => 'the-title', 'post_excerpt' => 'An excerpt.', 'post_password' => '',
        ], $over);
        WpStub::$posts[12] = ['post_type' => 'post', 'status' => 'draft', 'language' => 'it',
                              'trid' => 5, 'post_title' => 'Il titolo', 'post_content' => ''];
        WpStub::$meta[self::ID] = array_merge([
            CadenceContentRequest::META     => 'piece-1',
            CadenceContentRequest::KEY_META => CadenceAttest::KEY_ID,
            CadenceReadRequest::SEO_META['seo_title']       => 'SEO title',
            CadenceReadRequest::SEO_META['seo_description'] => 'SEO description',
        ], $meta);
    }

    private function read(array $body = [], ?string $key_id = CadenceAttest::KEY_ID,
                          $attestation = self::SIGN): array {
        $body = array_merge(['link' => 'https://example.test/?p=' . self::ID], $body);
        return CadenceReadRequest::run($body, $key_id,
            $attestation === self::SIGN
                ? CadenceAttest::header('/content/read', ['link' => $body['link']], $key_id)
                : $attestation);
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
        $this->assertSame('read_link_unresolved', $r['code']);
    }

    public function test_a_link_to_no_post_is_refused(): void {
        $this->post();
        $r = $this->read(['link' => 'https://example.test/?p=999']);
        $this->assertFalse($r['ok']);
        $this->assertSame('post_missing', $r['code']);
    }

    /** Twin: the answering test, signed over the same link it sends. */
    public function test_a_signature_over_another_link_is_refused(): void {
        $this->post();
        $r = $this->read(['link' => 'https://example.test/?p=' . self::ID], CadenceAttest::KEY_ID,
            CadenceAttest::header('/content/read', ['link' => 'https://example.test/?p=12'],
                                  CadenceAttest::KEY_ID));
        $this->assertFalse($r['ok']);
        $this->assertSame(CadenceAttestation::CODE, $r['code']);
        $this->assertSame('mismatch', $r['attestation_branch']);
    }

    /** THE READ TAKES NO EXEMPTION: a key allowed to publish unsigned still signs its reads. */
    public function test_an_exempt_key_reading_unsigned_is_refused(): void {
        $this->post();
        CadenceAttest::header('/content/read', ['link' => 'x'], CadenceAttest::KEY_ID);
        CadenceKey::set_unsigned_ok(CadenceAttest::KEY_ID, true, 3);
        $r = $this->read([], CadenceAttest::KEY_ID, null);
        $this->assertFalse($r['ok']);
        $this->assertSame('exempt_refused', $r['attestation_branch']);
        // `NO_EXEMPTION` is the boundary and names the exemption; the check in
        // `run` is the tripwire behind it and says less.
        $this->assertStringContainsString('unsigned-publish exemption', $r['reason']);
    }

    public function test_a_body_without_a_link_is_refused(): void {
        $r = CadenceReadRequest::run([], CadenceAttest::KEY_ID, null);
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
