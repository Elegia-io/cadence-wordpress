<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Creating a post from content the pipeline produced.
 *
 * THE FAILURE THIS IS SHAPED AROUND IS THE DUPLICATE. An HTTP pipeline retries;
 * a request that timed out after WordPress committed the insert but before the
 * response arrived is indistinguishable, to the caller, from one that never
 * ran. Retry it and the site has the same article twice, published, visible to
 * real visitors and to search engines. So the caller's own identifier for the
 * piece is what decides, and a repeat is answered with the post that already
 * exists rather than a second one.
 */
final class ContentRequestTest extends TestCase {

    protected function setUp(): void {
        WpStub::reset();
        WpStub::$capabilities = ['publish_posts' => [null], 'edit_posts' => [null]];
    }

    private function body(array $over = []): array {
        return array_merge([
            'piece_id'  => 'piece-2026-08-31-en',
            'language'  => 'en',
            // DECLARED, NEVER DETECTED. No default here either: a body with no
            // declaration is refused, and a helper that supplied one would make
            // that refusal unreachable from this file.
            // The stub site has the WPML hooks and serves `en`, so a
            // monolingual declaration here would be the disagreement this
            // connector refuses -- which LanguageDeclarationTest tests on
            // purpose, and which is not what this file is about.
            'declared'  => ['multilingual' => true, 'languages' => ['en']],
            'post_type' => 'post',
            'status'    => 'draft',
            'title'     => 'A title',
            'content'   => '<p>Body.</p>',
        ], $over);
    }

    /**
     * `run`, WITH THE PRESENTED KEY'S CAPABILITY SET. Every test in this file
     * that does not itself care about disclosure runs a key holding
     * `content.replace` too, so the revision this route hands back on an
     * idempotent repeat is not silently withheld and every existing assertion
     * about the shape of the answer keeps meaning what it says. The gate
     * itself -- what a key WITHOUT that capability is and is not handed -- is
     * `test_a_key_without_content_replace_is_not_handed_the_revision` below.
     *
     * @param list<string> $capabilities
     */
    private function run(array $body, array $capabilities = ['content.replace']): array {
        return CadenceContentRequest::run(
            $body,
            static fn (string $capability): bool => in_array($capability, $capabilities, true)
        );
    }

    public function test_creates_a_post_and_returns_its_id(): void {
        $r = $this->run($this->body());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertTrue($r['created']);
        $this->assertIsInt($r['post_id']);
        $this->assertCount(1, WpStub::$inserted);
        $this->assertSame('A title', WpStub::$inserted[0]['post_title']);
        $this->assertSame('draft', WpStub::$inserted[0]['post_status']);
    }

    /**
     * THE RETRY. Same body twice: one post, the same id, and the second answer
     * says plainly that it did not create anything.
     */
    public function test_the_same_piece_id_twice_creates_one_post(): void {
        $first  = $this->run($this->body());
        $second = $this->run($this->body());
        $this->assertTrue($second['ok'], $second['reason'] ?? '');
        $this->assertFalse($second['created']);
        $this->assertSame($first['post_id'], $second['post_id']);
        $this->assertCount(1, WpStub::$inserted, 'the retry inserted a second post');
    }

    /**
     * AND A DIFFERENT BODY UNDER THE SAME ID DOES NOT QUIETLY REWRITE THE POST.
     * The identifier means "this piece"; a changed body under it means the
     * caller thinks it is publishing something new. Answering with the existing
     * post is right. Silently overwriting the live article is not, and neither
     * is creating a second one.
     */
    public function test_a_changed_body_under_a_used_id_neither_rewrites_nor_duplicates(): void {
        $first = $this->run($this->body());
        $again = $this->run($this->body(['title' => 'Rewritten', 'content' => 'x']));
        $this->assertFalse($again['created']);
        $this->assertSame($first['post_id'], $again['post_id']);
        $this->assertCount(1, WpStub::$inserted);
        $this->assertSame([], WpStub::$updated);
    }

    /**
     * THE ID IS SCOPED TO THIS PLUGIN'S OWN META KEY, and matched exactly. A
     * lookup that matched a prefix would let `piece-1` answer for `piece-10`.
     */
    public function test_a_different_piece_id_creates_a_second_post(): void {
        $this->run($this->body(['piece_id' => 'piece-1']));
        $r = $this->run($this->body(['piece_id' => 'piece-10']));
        $this->assertTrue($r['created']);
        $this->assertCount(2, WpStub::$inserted);
    }

    public function test_the_piece_id_is_recorded_on_the_post_it_created(): void {
        $r = $this->run($this->body(['piece_id' => 'piece-7']));
        $this->assertSame('piece-7', WpStub::$meta[$r['post_id']]['_cadence_external_id'] ?? null);
    }

    /**
     * ONLY THE STATUSES THIS ENDPOINT MEANS. WordPress accepts any string as a
     * post status and stores it; `auto-draft`, `inherit` or a typo produce a
     * post that exists and appears nowhere, which is worse than a refusal
     * because nothing reports it.
     */
    public function test_refuses_a_status_it_does_not_publish(): void {
        foreach (['auto-draft', 'inherit', 'trash', 'publised', '', 'future'] as $status) {
            WpStub::reset();
            WpStub::$capabilities = ['publish_posts' => [null], 'edit_posts' => [null]];
            $r = $this->run($this->body(['status' => $status]));
            $this->assertFalse($r['ok'], $status . ' was accepted');
            $this->assertSame('bad_request', $r['code']);
            $this->assertSame([], WpStub::$inserted, $status);
        }
    }

    public function test_accepts_the_three_statuses_it_does_publish(): void {
        foreach (['draft', 'pending', 'publish'] as $status) {
            WpStub::reset();
            WpStub::$capabilities = ['publish_posts' => [null], 'edit_posts' => [null]];
            $r = $this->run($this->body(['status' => $status]));
            $this->assertTrue($r['ok'], $status . ': ' . ($r['reason'] ?? ''));
            $this->assertSame($status, WpStub::$inserted[0]['post_status']);
        }
    }

    /**
     * A POST TYPE THE SITE DOES NOT HAVE IS A REFUSAL, not a post of that type.
     * `wp_insert_post` writes the row regardless, producing content that is
     * invisible to every query and every admin screen.
     */
    public function test_refuses_a_post_type_the_site_does_not_have(): void {
        $r = $this->run($this->body(['post_type' => 'not_registered']));
        $this->assertFalse($r['ok']);
        $this->assertSame('bad_request', $r['code']);
        $this->assertSame([], WpStub::$inserted);
    }

    public function test_refuses_a_body_whose_shape_it_cannot_read(): void {
        foreach ([
            'no piece_id'         => ['piece_id' => null],
            'piece_id is int'     => ['piece_id' => 7],
            'piece_id is blank'   => ['piece_id' => '   '],
            'title is an array'   => ['title' => ['a']],
            'content is an int'   => ['content' => 3],
            'post_type is an int' => ['post_type' => 1],
            'status is an array'  => ['status' => ['draft']],
            'no language'         => ['language' => null],
            'language is an int'  => ['language' => 7],
            'no declaration'      => ['declared' => null],
        ] as $why => $over) {
            WpStub::reset();
            WpStub::$capabilities = ['publish_posts' => [null], 'edit_posts' => [null]];
            $r = $this->run($this->body($over));
            $this->assertFalse($r['ok'], $why);
            $this->assertSame('bad_request', $r['code'], $why);
            $this->assertSame([], WpStub::$inserted, $why);
        }
    }

    /**
     * A TRASHED POST STILL HOLDS ITS IDENTIFIER, so the piece is not published
     * again. WordPress's own default for this lookup is publish-only, and
     * taking that default would mean every piece somebody deleted comes back
     * on the next run of the pipeline -- content resurrected by a retry, which
     * is worse than a duplicate because a human deliberately removed it.
     */
    public function test_a_trashed_post_still_answers_for_its_identifier(): void {
        $first = $this->run($this->body(['status' => 'publish']));
        WpStub::$posts[$first['post_id']]['post_status'] = 'trash';

        $again = $this->run($this->body(['status' => 'publish']));
        $this->assertTrue($again['ok'], $again['reason'] ?? '');
        $this->assertFalse($again['created'], 'a deleted piece was published again');
        $this->assertSame($first['post_id'], $again['post_id']);
        $this->assertCount(1, WpStub::$inserted);
    }

    /**
     * AND A DRAFT DOES TOO -- the same assertion one status away from the
     * default, so the test above is about the scope of the lookup and not
     * about the word `trash`.
     */
    public function test_a_draft_answers_for_its_identifier(): void {
        $first = $this->run($this->body(['status' => 'draft']));
        $again = $this->run($this->body(['status' => 'draft']));
        $this->assertFalse($again['created']);
        $this->assertSame($first['post_id'], $again['post_id']);
    }

    /**
     * THE IDENTIFIER IS WRITTEN BY THE INSERT ITSELF, not after it. A separate
     * `update_post_meta` leaves a window in which the post exists and carries
     * no identifier, and a retry landing inside that window creates the
     * duplicate. The window is too small to test by racing it and too real to
     * leave to a comment, so the mechanism is asserted instead.
     */
    public function test_the_identifier_travels_in_the_insert_call(): void {
        $this->run($this->body(['piece_id' => 'piece-9']));
        $this->assertSame('piece-9',
            WpStub::$inserted[0]['meta_input'][CadenceContentRequest::META] ?? null);
    }

    /**
     * A POST THAT CANNOT BE READ BACK IS STILL A POST THAT WAS CREATED. The
     * revision is derived from what the site holds, so a site that will not
     * answer produces no revision -- and the honest answer is to leave the key
     * out. Answering with one computed from the request instead would hand the
     * caller a token this site never agreed to, which is the one value that
     * would make its next replacement wrong in the direction that writes.
     * Refusing the whole request would be a second lie: the post exists.
     */
    public function test_a_post_that_cannot_be_read_back_is_still_a_created_post(): void {
        WpStub::$post_read_fails = true;
        $r = $this->run($this->body());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertTrue($r['created']);
        $this->assertCount(1, WpStub::$inserted);
        $this->assertArrayNotHasKey('revision', $r,
            'a revision was answered for content nothing could read');
    }

    /**
     * WORDPRESS FAILING IS NOT WORDPRESS SUCCEEDING. `wp_insert_post` returns a
     * WP_Error rather than throwing, and a caller that reads the return value
     * as an id gets `0` -- which is falsy, and is also what "no post" looks
     * like everywhere else.
     */
    public function test_an_insert_that_fails_is_reported_as_a_failure(): void {
        WpStub::$insert_fails = 'database is on fire';
        $r = $this->run($this->body());
        $this->assertFalse($r['ok']);
        $this->assertSame('insert_failed', $r['code']);
        $this->assertStringContainsString('database is on fire', $r['reason']);
        $this->assertArrayNotHasKey('post_id', $r);
    }

    /**
     * AND SO IS A ZERO WITH NO ERROR ATTACHED. Asking `wp_insert_post` for
     * errors does not guarantee getting one: `0` is the non-error form's
     * failure return, and a filter on `wp_insert_post_empty_content` in a
     * plugin this one does not control can put it on that path. Read as an id
     * it is falsy, which is what "no post" looks like everywhere else -- so it
     * propagates as a plausible absence instead of a failure.
     */
    public function test_a_zero_with_no_error_is_reported_as_a_failure(): void {
        WpStub::$insert_returns_zero = true;
        $r = $this->run($this->body());
        $this->assertFalse($r['ok']);
        $this->assertSame('insert_failed', $r['code']);
        $this->assertArrayNotHasKey('post_id', $r);
        $this->assertSame([], WpStub::$meta, 'a post that does not exist claimed the identifier');
    }

    /**
     * AND A FAILED INSERT LEAVES NO CLAIM ON THE IDENTIFIER. Recording the id
     * against a post that was never created would make every future retry
     * answer with a post that does not exist.
     */
    public function test_a_failed_insert_leaves_the_identifier_free(): void {
        WpStub::$insert_fails = 'nope';
        $this->run($this->body());
        $this->assertSame([], WpStub::$meta);

        WpStub::$insert_fails = null;
        $r = $this->run($this->body());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertTrue($r['created']);
    }

    /**
     * WHAT THE CALL DID, in the six fields the caller's verifier reads.
     *
     * `{ok, created, post_id}` answers "did a row appear". These answer "is the
     * thing I asked for now true", and the two differ in exactly the cases
     * worth auditing -- which is why the verifier on the other side of the wire
     * had nothing to parse until now.
     */
    public function test_a_created_post_reports_what_it_placed(): void {
        $r = $this->run($this->body(['piece_id' => 'p-1']));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame([
            'piece_id'             => 'p-1',
            'post_id'              => $r['post_id'],
            'placed'               => ['en'],
            'linked'               => [],
            'refused'              => [],
            'observed_unsupported' => [],
        ], $r['report']);
        // AN INTEGER, which is what WordPress named it. The caller's verifier
        // type-checks this before it verifies anything, so a stringified id
        // fails earlier and less legibly than a wrong one.
        $this->assertIsInt($r['report']['post_id']);
    }

    /** The idempotent repeat reports the same placement, and `created` false. */
    public function test_an_idempotent_repeat_reports_the_post_that_is_already_there(): void {
        $first  = $this->run($this->body());
        $second = $this->run($this->body());
        $this->assertFalse($second['created']);
        $this->assertSame($first['report'], $second['report']);
        $this->assertSame(['en'], $second['report']['placed'],
            'a piece that is on the site was reported as not placed');
    }

    /**
     * PARTIAL LANGUAGE SUPPORT IS SAID OUT LOUD. The run asks for three
     * languages, the site serves two: the piece is placed, and the language
     * nobody can serve is named with its reason rather than dropped.
     *
     * Without this the client discovers it on their own site.
     */
    public function test_a_language_the_site_cannot_serve_is_reported_not_dropped(): void {
        WpStub::$active_languages = ['en' => [], 'de' => []];
        $r = $this->run($this->body([
            'declared' => ['multilingual' => true, 'languages' => ['en', 'de', 'it']],
        ]));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame(['en'], $r['report']['placed']);
        $this->assertSame(['it'], $r['report']['observed_unsupported']);
        $this->assertCount(1, $r['report']['refused']);
        $this->assertSame('it', $r['report']['refused'][0][0]);
        $this->assertNotSame('', $r['report']['refused'][0][1]);
        // DISJOINT. A report that both places and disclaims a language is one
        // the verifier on the other side refuses outright.
        $this->assertSame([], array_intersect($r['report']['placed'], $r['report']['observed_unsupported']));
    }

    /** `linked` is empty and says so: linking is the other route's write. */
    public function test_this_route_never_reports_a_link_it_did_not_make(): void {
        $r = $this->run($this->body());
        $this->assertSame([], $r['report']['linked']);
        $this->assertSame([], WpStub::$writes);
    }

    /** A refusal reports no placement at all, and writes nothing. */
    public function test_a_declaration_that_disagrees_with_the_site_places_nothing(): void {
        WpStub::$wpml_reads  = false;
        WpStub::$wpml_writes = false;
        $r = $this->run($this->body([
            'declared' => ['multilingual' => true, 'languages' => ['en']],
        ]));
        $this->assertFalse($r['ok']);
        $this->assertSame('capability_mismatch', $r['code']);
        $this->assertArrayNotHasKey('report', $r);
        $this->assertSame([], WpStub::$inserted);
    }

    /**
     * `external_id` IS WHAT 0.1.0 CALLED IT, and 0.1.0 is installed on sites
     * this repository does not control. Accepted, and answered as `piece_id`.
     */
    public function test_the_released_field_name_still_identifies_a_piece(): void {
        $body = $this->body();
        $body['external_id'] = $body['piece_id'];
        unset($body['piece_id']);

        $first = $this->run($body);
        $this->assertTrue($first['created']);
        $this->assertSame('piece-2026-08-31-en', $first['report']['piece_id']);
        $this->assertFalse($this->run($this->body())['created'],
            'the same piece under the two spellings became two posts');
    }

    /**
     * A NEWLY CREATED POST'S REVISION IS UNGATED. This text is the caller's
     * own -- it did not exist a moment ago -- so there is nothing here to
     * disclose, and a key holding only `content.publish` still gets it.
     */
    public function test_a_created_posts_revision_is_handed_to_a_key_with_no_replace_capability(): void {
        $r = $this->run($this->body(), []);
        $this->assertTrue($r['created']);
        $this->assertArrayHasKey('revision', $r,
            'a key that just created this post was not told its own revision');
    }

    /**
     * BUT AN IDEMPOTENT REPEAT'S REVISION IS THE PROOF `/content/replace`
     * DEMANDS THAT THE CALLER HAS SEEN THE TEXT IT IS ABOUT TO OVERWRITE. A
     * key holding only `content.publish` -- which is all this route asks --
     * could otherwise guess a piece_id and read the revision back having read
     * no article; it is never handed to a key that cannot act on it.
     */
    public function test_a_key_without_content_replace_is_not_handed_the_revision_on_a_repeat(): void {
        $first  = $this->run($this->body());
        $second = $this->run($this->body(), []);
        $this->assertFalse($second['created']);
        $this->assertSame($first['post_id'], $second['post_id']);
        $this->assertArrayNotHasKey('revision', $second,
            'a key with no content.replace capability was handed the proof that endpoint demands');
    }

    /** THE TWIN: a key that DOES hold `content.replace` is handed it. */
    public function test_a_key_with_content_replace_is_handed_the_revision_on_a_repeat(): void {
        $first  = $this->run($this->body());
        $second = $this->run($this->body(), ['content.replace']);
        $this->assertFalse($second['created']);
        $this->assertArrayHasKey('revision', $second);
        $this->assertSame($first['revision'], $second['revision']);
    }
}
