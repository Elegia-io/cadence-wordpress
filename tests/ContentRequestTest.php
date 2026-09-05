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
        $this->asked = [];
        WpStub::$capabilities = ['publish_posts' => [null], 'edit_posts' => [null]];
    }

    /** A `current_user_can` that says yes to everything, recording nothing. */
    private function anyone(): callable {
        return static fn (string $cap, $id = null): bool => true;
    }

    /** One that says no to everything, and records what it was asked. */
    private function nobody(): callable {
        return function (string $cap, $id = null): bool {
            $this->asked[] = [$cap, $id];
            return false;
        };
    }

    /** @var list<array{string, mixed}> */
    private array $asked = [];

    private function body(array $over = []): array {
        return array_merge([
            'external_id' => 'piece-2026-08-31-en',
            'post_type'   => 'post',
            'status'      => 'draft',
            'title'       => 'A title',
            'content'     => '<p>Body.</p>',
        ], $over);
    }

    public function test_creates_a_post_and_returns_its_id(): void {
        $r = CadenceContentRequest::run($this->body(), $this->anyone());
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
    public function test_the_same_external_id_twice_creates_one_post(): void {
        $first  = CadenceContentRequest::run($this->body(), $this->anyone());
        $second = CadenceContentRequest::run($this->body(), $this->anyone());
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
        $first = CadenceContentRequest::run($this->body(), $this->anyone());
        $again = CadenceContentRequest::run($this->body(['title' => 'Rewritten', 'content' => 'x']), $this->anyone());
        $this->assertFalse($again['created']);
        $this->assertSame($first['post_id'], $again['post_id']);
        $this->assertCount(1, WpStub::$inserted);
        $this->assertSame([], WpStub::$updated);
    }

    /**
     * THE ID IS SCOPED TO THIS PLUGIN'S OWN META KEY, and matched exactly. A
     * lookup that matched a prefix would let `piece-1` answer for `piece-10`.
     */
    public function test_a_different_external_id_creates_a_second_post(): void {
        CadenceContentRequest::run($this->body(['external_id' => 'piece-1']), $this->anyone());
        $r = CadenceContentRequest::run($this->body(['external_id' => 'piece-10']), $this->anyone());
        $this->assertTrue($r['created']);
        $this->assertCount(2, WpStub::$inserted);
    }

    public function test_the_external_id_is_recorded_on_the_post_it_created(): void {
        $r = CadenceContentRequest::run($this->body(['external_id' => 'piece-7']), $this->anyone());
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
            $r = CadenceContentRequest::run($this->body(['status' => $status]), $this->anyone());
            $this->assertFalse($r['ok'], $status . ' was accepted');
            $this->assertSame('bad_request', $r['code']);
            $this->assertSame([], WpStub::$inserted, $status);
        }
    }

    public function test_accepts_the_three_statuses_it_does_publish(): void {
        foreach (['draft', 'pending', 'publish'] as $status) {
            WpStub::reset();
            WpStub::$capabilities = ['publish_posts' => [null], 'edit_posts' => [null]];
            $r = CadenceContentRequest::run($this->body(['status' => $status]), $this->anyone());
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
        $r = CadenceContentRequest::run($this->body(['post_type' => 'not_registered']), $this->anyone());
        $this->assertFalse($r['ok']);
        $this->assertSame('bad_request', $r['code']);
        $this->assertSame([], WpStub::$inserted);
    }

    public function test_refuses_a_body_whose_shape_it_cannot_read(): void {
        foreach ([
            'no external_id'      => ['external_id' => null],
            'external_id is int'  => ['external_id' => 7],
            'external_id is blank'=> ['external_id' => '   '],
            'title is an array'   => ['title' => ['a']],
            'content is an int'   => ['content' => 3],
            'post_type is an int' => ['post_type' => 1],
            'status is an array'  => ['status' => ['draft']],
        ] as $why => $over) {
            WpStub::reset();
            WpStub::$capabilities = ['publish_posts' => [null], 'edit_posts' => [null]];
            $r = CadenceContentRequest::run($this->body($over), $this->anyone());
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
        $first = CadenceContentRequest::run($this->body(['status' => 'publish']), $this->anyone());
        WpStub::$posts[$first['post_id']]['post_status'] = 'trash';

        $again = CadenceContentRequest::run($this->body(['status' => 'publish']), $this->anyone());
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
        $first = CadenceContentRequest::run($this->body(['status' => 'draft']), $this->anyone());
        $again = CadenceContentRequest::run($this->body(['status' => 'draft']), $this->anyone());
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
        CadenceContentRequest::run($this->body(['external_id' => 'piece-9']), $this->anyone());
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
        $r = CadenceContentRequest::run($this->body(), $this->anyone());
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
        $r = CadenceContentRequest::run($this->body(), $this->anyone());
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
        $r = CadenceContentRequest::run($this->body(), $this->anyone());
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
        CadenceContentRequest::run($this->body(), $this->anyone());
        $this->assertSame([], WpStub::$meta);

        WpStub::$insert_fails = null;
        $r = CadenceContentRequest::run($this->body(), $this->anyone());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertTrue($r['created']);
    }

    /**
     * `revision` IS THE PRECONDITION FOR A REWRITE, AND THIS ROUTE IS NOT
     * AUTHORISED TO HAND IT OUT.
     *
     * Creating is asked of the post TYPE -- `create_posts`, which any
     * contributor on that type holds -- and says nothing about the particular
     * post an identifier already in use resolves to. That post may be somebody
     * else's article. Answering with its revision turns this endpoint into a
     * reader for the one value `/content/replace` requires as proof the caller
     * has seen the text it is about to destroy: guess an identifier, read the
     * revision back, and the freshness check the replace endpoint is built on
     * is satisfied by a caller that never read the article.
     *
     * So the revision is disclosed on the same question the rewrite is
     * authorised on, and on nothing weaker.
     */
    public function test_a_repeat_does_not_hand_the_revision_to_a_caller_that_may_not_edit_the_post(): void {
        $first = CadenceContentRequest::run($this->body(), $this->anyone());

        $again = CadenceContentRequest::run($this->body(), $this->nobody());

        $this->assertTrue($again['ok'], $again['reason'] ?? '');
        $this->assertFalse($again['created']);
        $this->assertSame($first['post_id'], $again['post_id'],
            'the retry answer stopped naming the post that exists');
        $this->assertArrayNotHasKey('revision', $again,
            'the revision a rewrite has to name was handed to a caller that may not rewrite');
        $this->assertSame([['edit_post', $first['post_id']]], $this->asked,
            'the question asked was not about the post the answer is about');
    }

    /**
     * THE TWIN: a caller that MAY edit that post is answered with it. Without
     * this the refusal above holds for a version that never answers a revision
     * at all -- and a caller with no way to read the current revision has no
     * way to make a replacement after a hand edit.
     */
    public function test_a_repeat_hands_the_revision_to_a_caller_that_may_edit_the_post(): void {
        $first = CadenceContentRequest::run($this->body(), $this->anyone());
        $again = CadenceContentRequest::run($this->body(), $this->anyone());

        $this->assertFalse($again['created']);
        $this->assertSame($first['revision'], $again['revision'] ?? null);
    }

    /**
     * AND CREATING STILL ANSWERS WITH ONE, on the same permission that created
     * the post. There is nothing to disclose: the post did not exist a moment
     * ago and its text is the text this caller just sent. Gating this too
     * would leave the pipeline unable to replace anything it published without
     * a second capability it does not need to publish.
     */
    public function test_the_creator_is_answered_with_the_revision_of_what_it_just_wrote(): void {
        $r = CadenceContentRequest::run($this->body(), $this->nobody());

        $this->assertTrue($r['created']);
        $this->assertSame(CadenceRevision::of('A title', '<p>Body.</p>'), $r['revision'] ?? null);
        $this->assertSame([], $this->asked, 'creating asked a question about a post nobody had');
    }

}
