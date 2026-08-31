<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE PERMISSION CALLBACK IS THE BOUNDARY. Everything below it assumes the
 * caller was allowed to edit every post named in the request, so a gap here is
 * not a smaller version of a gap further in -- it is the whole of it.
 */
final class RestRouteTest extends TestCase {

    /** @var list<array{string, mixed}> every question asked of WordPress */
    private array $asked = [];

    /** A `current_user_can` answering yes for the named capabilities, recording everything. */
    private function can_cap(array $caps): callable {
        return function (string $cap, $id = null) use ($caps): bool {
            $this->asked[] = [$cap, $id];
            return in_array($cap, $caps, true);
        };
    }

    /** A `current_user_can` that answers yes for the ids given and records everything. */
    private function can(array $allowed_ids): callable {
        return function (string $cap, $id) use ($allowed_ids): bool {
            $this->asked[] = [$cap, $id];
            return in_array($id, $allowed_ids, true);
        };
    }

    /** A plan in the shape CadenceLinkRequest actually reads: a source and its translations. */
    private function body(array $ids): array {
        $langs = ['en', 'de', 'fr', 'it'];
        $post = fn (int $id, int $i): array => [
            'post_id' => $id, 'language_code' => $langs[$i],
            'element_type' => 'post_page', 'source_language_code' => $i === 0 ? null : 'en',
        ];
        $ids = array_values($ids);
        return [
            'trid' => null,
            'create_group' => true,
            'source' => $post($ids[0], 0),
            'translations' => array_map($post, array_slice($ids, 1), range(1, max(1, count($ids) - 1))),
        ];
    }

    public function test_permits_when_the_caller_may_edit_every_named_post(): void {
        $this->assertTrue(CadenceRestRoute::permitted($this->body([1, 2]), $this->can([1, 2])));
    }

    /**
     * NOT "the first post", and not "any post". A caller who may edit one of
     * the two still moves the other one into a group.
     */
    public function test_refuses_when_one_of_several_posts_is_not_editable(): void {
        $this->assertFalse(CadenceRestRoute::permitted($this->body([1, 2]), $this->can([1])));
        $this->assertFalse(CadenceRestRoute::permitted($this->body([1, 2]), $this->can([2])));
    }

    /**
     * EVERY id is asked about, even after a refusal is certain. Not for the
     * answer -- for the property that the set authorised is the set written.
     */
    public function test_asks_about_every_post_with_a_per_post_capability(): void {
        CadenceRestRoute::permitted($this->body([7, 8, 9]), $this->can([7, 8, 9]));
        $this->assertSame([['edit_post', 7], ['edit_post', 8], ['edit_post', 9]], $this->asked);
    }

    /**
     * ASKED ABOUT EVERY POST **WHEN THE ANSWER IS ALREADY NO**. The test above
     * cannot see a short-circuit, because with every post permitted there is
     * nothing to short-circuit on. A `break` after the first refusal leaves the
     * later posts never asked about -- harmless here, and the habit that makes
     * "the set authorised is the set written" stop being true elsewhere.
     */
    public function test_asks_about_every_post_even_once_refusal_is_certain(): void {
        $this->assertFalse(CadenceRestRoute::permitted($this->body([7, 8, 9]), $this->can([])));
        $this->assertSame([['edit_post', 7], ['edit_post', 8], ['edit_post', 9]], $this->asked);
    }

    /**
     * A JSON OBJECT IS NOT A LIST. `{"posts": {"a": {...}, "b": {...}}}` decodes
     * to a perfectly good PHP array of two well-formed posts -- so every
     * per-post check below passes, and only the shape of the container says it
     * was never the thing this endpoint documents.
     */
    public function test_refuses_a_string_keyed_map_of_well_formed_posts(): void {
        $body = ['source' => ['post_id' => 1],
                 'translations' => ['a' => ['post_id' => 2], 'b' => ['post_id' => 3]]];
        $this->assertFalse(CadenceRestRoute::permitted($body, $this->can([1, 2, 3])));
        $this->assertSame([], $this->asked);

        // THE TWIN, so the assertion above is the container's shape being
        // rejected and not the posts inside it: the very same three posts as a
        // list are read and asked about.
        $this->assertTrue(CadenceRestRoute::permitted(
            ['source' => ['post_id' => 1], 'translations' => [['post_id' => 2], ['post_id' => 3]]],
            $this->can([1, 2, 3])
        ));
        $this->assertSame([['edit_post', 1], ['edit_post', 2], ['edit_post', 3]], $this->asked);
    }

    /**
     * A REQUEST NAMING NO POSTS AUTHORISES NOTHING, so it cannot be permitted:
     * `array_reduce`-style "all of them passed" over an empty list is true, and
     * that is a check reporting a pass having measured nothing.
     */
    public function test_refuses_a_request_that_names_no_posts(): void {
        $this->assertFalse(CadenceRestRoute::permitted(['posts' => []], $this->can([1])));
        $this->assertFalse(CadenceRestRoute::permitted([], $this->can([1])));
        $this->assertSame([], $this->asked, 'nothing to ask about, and nothing was asked');
    }

    /**
     * WHAT CANNOT BE READ CANNOT BE AUTHORISED. A JSON body arrives with
     * whatever types the client sent; `current_user_can('edit_post', '4 OR 1')`
     * is a question about a post that does not exist, and PHP would answer it
     * after coercing. Refuse instead, and never ask.
     */
    public function test_refuses_a_body_whose_shape_it_cannot_read(): void {
        $src = ['post_id' => 1];
        foreach ([
            'translations is not a list' => ['source' => $src, 'translations' => ['post_id' => 4]],
            'translations is a string'   => ['source' => $src, 'translations' => 'all'],
            'translations is absent'     => ['source' => $src],
            'source is absent'           => ['translations' => [['post_id' => 4]]],
            'source is not an array'     => ['source' => 1, 'translations' => [['post_id' => 4]]],
            'a post is not an array'     => ['source' => $src, 'translations' => [4]],
            'id is a numeric string'     => ['source' => $src, 'translations' => [['post_id' => '4']]],
            'id is a float'              => ['source' => $src, 'translations' => [['post_id' => 4.0]]],
            'id is a bool'               => ['source' => $src, 'translations' => [['post_id' => true]]],
            'id is missing'              => ['source' => $src, 'translations' => [['language_code' => 'en']]],
            'id is negative'             => ['source' => $src, 'translations' => [['post_id' => -1]]],
        ] as $why => $body) {
            $this->assertFalse(CadenceRestRoute::permitted($body, $this->can([1, 4, -1])), $why);
        }
        $this->assertSame([], $this->asked, 'an unreadable body is never turned into a question');
    }

    /**
     * A WRITTEN PLAN IS A 200 SAYING HOW MUCH IT WROTE.
     */
    public function test_a_written_plan_answers_200(): void {
        $r = CadenceRestRoute::respond(['ok' => true, 'written' => 2]);
        $this->assertSame(200, $r['status']);
        $this->assertSame(2, $r['body']['written']);
    }

    /**
     * 201 FOR SOMETHING THAT CAME INTO EXISTENCE, 200 FOR A REPEAT that found
     * it already there. The body says `created` either way, so a caller never
     * has to read the status code to know which happened -- but a caller that
     * does read it gets the truth.
     */
    public function test_a_created_post_is_201_and_an_idempotent_repeat_is_200(): void {
        $made = CadenceRestRoute::respond(['ok' => true, 'created' => true, 'post_id' => 12]);
        $this->assertSame(201, $made['status']);
        $this->assertSame(12, $made['body']['post_id']);
        $this->assertTrue($made['body']['created']);

        $again = CadenceRestRoute::respond(['ok' => true, 'created' => false, 'post_id' => 12]);
        $this->assertSame(200, $again['status']);
        $this->assertSame(12, $again['body']['post_id']);
        $this->assertFalse($again['body']['created']);
    }

    /**
     * THE TWO KINDS OF REFUSAL ARE DIFFERENT HTTP ANSWERS, because they call
     * for opposite things from the caller: 409 means the site disagreed with
     * the plan, so re-read and try again; 400 means the plan is wrong however
     * many times it is sent.
     */
    public function test_a_stale_plan_is_409_and_a_wrong_one_is_400(): void {
        foreach (['group_unknown', 'already_grouped', 'group_disagreement'] as $code) {
            $r = CadenceRestRoute::respond(['ok' => false, 'code' => $code, 'reason' => 'x']);
            $this->assertSame(409, $r['status'], $code);
            $this->assertSame($code, $r['body']['code']);
            $this->assertSame('x', $r['body']['reason']);
        }
        foreach (['bad_plan', 'contradictory_instructions', 'no_group_named'] as $code) {
            $this->assertSame(400, CadenceRestRoute::respond(
                ['ok' => false, 'code' => $code, 'reason' => 'x'])['status'], $code);
        }
    }

    /**
     * EVERY REFUSAL THE WRITER CAN PRODUCE IS CLASSIFIED. Without this, adding
     * a seventh refusal over in CadenceLinkRequest and not adding it here is
     * caught by nothing -- the mapping keeps passing its own tests, which only
     * ever ask about the codes it already knows.
     */
    public function test_every_published_refusal_code_is_mapped(): void {
        $published = array_merge(CadenceLinkRequest::REFUSAL_CODES, CadenceContentRequest::REFUSAL_CODES);
        $this->assertNotEmpty($published);
        foreach ($published as $code) {
            // Asked of the TABLE, not of the status the table produces. 500 is
            // both "nobody classified this" and the right answer for
            // `insert_failed`, so a test keying on the number cannot tell a
            // deliberate 500 from an unclassified one.
            $this->assertArrayHasKey($code, CadenceRestRoute::STATUS, $code . ' is not classified');
        }
        // And nothing is classified that no writer emits, which is how a code
        // renamed on one side and not the other shows up.
        $this->assertSame([], array_diff(array_keys(CadenceRestRoute::STATUS), $published));
    }

    /**
     * A SITE THAT CANNOT DO THIS AT ALL IS A 503, not a 400 blaming the request
     * and not a 409 inviting a retry that cannot succeed until someone installs
     * WPML.
     */
    public function test_a_site_without_wpml_is_503(): void {
        $r = CadenceRestRoute::respond(['ok' => false, 'code' => 'wpml_unavailable', 'reason' => 'x']);
        $this->assertSame(503, $r['status']);
    }

    /**
     * AND AN UNCLASSIFIED ONE IS NOT A SUCCESS. The test above turns red when
     * a code is added unmapped; this one says what happens in the meantime on a
     * live site. Falling back to 400 would tell the caller its plan is wrong
     * when nothing here knows that; falling back to 200 would report a write
     * that did not happen. 500 is the honest answer: this server refused and
     * cannot say why.
     */
    public function test_an_unmapped_refusal_is_a_server_error_not_a_success(): void {
        foreach ([['ok' => false, 'code' => 'invented_later', 'reason' => 'x'],
                  ['ok' => false, 'reason' => 'x'],
                  ['ok' => false]] as $i => $refusal) {
            $r = CadenceRestRoute::respond($refusal);
            $this->assertSame(500, $r['status'], (string) $i);
            $this->assertArrayNotHasKey('written', $r['body']);
        }
    }

    /**
     * PUBLISHING IS A DIFFERENT PERMISSION FROM DRAFTING, and both are asked
     * per post type rather than as `edit_posts`.
     *
     * WordPress derives a type's capabilities from its registration, so a
     * custom type's caps are not the ones `post` uses. A plugin that hard-coded
     * `edit_posts` would let anyone who may draft a blog post write into a type
     * whose whole point was that they may not.
     */
    public function test_creating_needs_the_types_create_cap(): void {
        $body = ['external_id' => 'x', 'post_type' => 'page', 'status' => 'draft'];
        $this->assertTrue(CadenceRestRoute::may_publish($body, $this->can_cap(['create_pages'])));
        $this->assertSame([['create_pages', null]], $this->asked);

        $this->asked = [];
        $this->assertFalse(CadenceRestRoute::may_publish($body, $this->can_cap(['create_posts'])),
            'the cap for a different post type let this through');
    }

    public function test_publishing_needs_the_publish_cap_as_well(): void {
        $draft   = ['external_id' => 'x', 'post_type' => 'page', 'status' => 'draft'];
        $publish = ['external_id' => 'x', 'post_type' => 'page', 'status' => 'publish'];
        $creator = $this->can_cap(['create_pages']);

        $this->assertTrue(CadenceRestRoute::may_publish($draft, $creator));
        $this->assertFalse(CadenceRestRoute::may_publish($publish, $creator),
            'a contributor who may draft was allowed to publish');
        $this->assertTrue(CadenceRestRoute::may_publish(
            $publish, $this->can_cap(['create_pages', 'publish_pages'])));
    }

    /**
     * A TYPE THE SITE DOES NOT HAVE IS REFUSED WITHOUT ASKING ANYTHING.
     * `get_post_type_object` returns null for it, and reading `->cap` off null
     * is a fatal error in PHP 8 -- which in a permission callback is a 500 on a
     * route whose answer should have been "no".
     */
    public function test_an_unregistered_post_type_is_refused_and_asks_nothing(): void {
        foreach ([
            ['external_id' => 'x', 'post_type' => 'nope', 'status' => 'draft'],
            ['external_id' => 'x', 'post_type' => 1, 'status' => 'draft'],
            ['external_id' => 'x', 'status' => 'draft'],
            ['external_id' => 'x', 'post_type' => 'page'],
            ['external_id' => 'x', 'post_type' => 'page', 'status' => ['draft']],
            [],
        ] as $i => $body) {
            $this->assertFalse(CadenceRestRoute::may_publish($body, $this->can_cap(['create_pages'])), (string) $i);
        }
        $this->assertSame([], $this->asked);
    }

    /**
     * THE `ABSPATH` GUARD ACTUALLY GUARDS. It is one line of boilerplate that
     * says `exit`, which is exactly the kind of line that gets the constant
     * name wrong and is never noticed, because in the suite ABSPATH is defined
     * and the guard never fires. Fire it: load each include in a fresh
     * interpreter with no ABSPATH and assert the class did not come into
     * existence.
     */
    #[DataProvider('includes')]
    public function test_an_include_reached_directly_defines_nothing(string $file, string $class): void {
        $script = sprintf(
            'require %s; var_dump(class_exists(%s, false));',
            var_export(dirname(__DIR__) . '/includes/' . $file, true),
            var_export($class, true)
        );
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');
        $this->assertSame('', trim((string) $out), 'the guard let execution reach the class');

        // THE TWIN, in the same interpreter shape: with ABSPATH defined the
        // very same require does define the class -- so the assertion above is
        // the guard firing, not a broken subprocess.
        $armed = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("define('ABSPATH','/wp/'); " . $script) . ' 2>&1');
        $this->assertSame('bool(true)', trim((string) $armed));
    }

    public static function includes(): array {
        return [
            ['class-cadence-rest-route.php', 'CadenceRestRoute'],
            ['class-cadence-link-request.php', 'CadenceLinkRequest'],
        ];
    }

    /**
     * THE TWIN: the same eight bodies with the id made well-formed are asked
     * about. Without this the test above passes on a `permitted` that returns
     * false unconditionally.
     */
    public function test_a_well_formed_id_in_the_same_shape_is_asked_about(): void {
        $body = ['source' => ['post_id' => 1], 'translations' => [['post_id' => 4]]];
        $this->assertTrue(CadenceRestRoute::permitted($body, $this->can([1, 4])));
        $this->assertSame([['edit_post', 1], ['edit_post', 4]], $this->asked);
    }

    /**
     * THE SET AUTHORISED IS THE SET WRITTEN, checked against the writer rather
     * than against this file's idea of the writer.
     *
     * The two read the same body independently -- `permitted` for the ids to
     * ask WordPress about, `CadenceLinkRequest` for the posts to link -- so
     * nothing but this test stops them drifting apart, and drift here is the
     * whole failure: a caller authorised for post 1 while post 2 is the one
     * that moves. An earlier draft of this file invented a `posts` key the
     * writer has never read, which would have authorised nothing at all while
     * every test on this page passed.
     */
    public function test_the_ids_authorised_are_exactly_the_ids_written(): void {
        WpStub::reset();
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        WpStub::add_post(3, 'page', 'fr', null);
        $body = $this->body([1, 2, 3]);

        $this->assertTrue(CadenceRestRoute::permitted($body, $this->can([1, 2, 3])));
        $result = CadenceLinkRequest::run($body);
        $this->assertTrue($result['ok'], $result['reason'] ?? '');

        $authorised = array_column($this->asked, 1);
        $written    = array_column(WpStub::$writes, 'element_id');
        sort($authorised);
        sort($written);
        $this->assertSame($written, $authorised);
        $this->assertNotEmpty($written);
    }
}
