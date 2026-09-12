<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
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

    /** The helper's "sign this body with the suite's key" sentinel, distinct from any header. */
    private const SIGN = "\0sign";

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
     * `CadenceContentRequest::run`, WITH THE PRESENTED KEY'S CAPABILITY SET
     * AND ITS BYLINE. Named for what it does rather than what it wraps: `run`
     * is final on PHPUnit's own TestCase and a method here of that name never
     * gets called.
     *
     * Every test in this file that does not itself care about disclosure runs
     * a key holding `content.replace` too, so the revision this route hands
     * back on an idempotent repeat is not silently withheld and every existing
     * assertion about the shape of the answer keeps meaning what it says. The
     * gate itself -- what a key WITHOUT that capability is and is not handed --
     * is `test_a_key_without_content_replace_is_not_handed_the_revision` below.
     *
     * The byline defaults to null, which is the pre-existing key: the two
     * arguments answer two unrelated questions and neither implies the other.
     *
     * @param list<string> $capabilities
     */
    private function publish(array $body, array $capabilities = ['content.replace'],
                             ?int $author = null, ?array $post_types = null,
                             ?string $key_id = CadenceAttest::KEY_ID,
                             $attestation = self::SIGN): array {
        return CadenceContentRequest::run(
            $body,
            static fn (string $capability): bool => in_array($capability, $capabilities, true),
            // NULL, WHICH IS THE KEY ISSUED BEFORE EITHER FIELD EXISTED: any
            // post type, and no stamp on what it creates. Defaulted on THIS
            // helper and not on `run` -- `run` takes it as a required argument,
            // because null is the wide case and a default there could not tell
            // a key that named no type from a call site that forgot to ask.
            $post_types,
            $author,
            $key_id,
            $attestation === self::SIGN
                ? CadenceAttest::header('/content', CadenceAttest::fields('/content', $body), $key_id)
                : $attestation
        );
    }

    /**
     * THE PUBLISH SCOPE IS A REQUIRED ARGUMENT, and the wide case is therefore
     * never a default.
     *
     * `null` means ANY registered post type. As a default it reads two ways --
     * a key that named no type, and a call site that forgot to ask -- and
     * nothing in the code can tell them apart, which is what the docblock used
     * to claim it could. `$authorises` is required for the same reason, and
     * `CadenceLinkRequest::run` defaults its `$key_id` to the NARROW case.
     */
    public function test_the_publish_scope_is_a_required_argument(): void {
        $parameter = (new ReflectionMethod(CadenceContentRequest::class, 'run'))->getParameters()[2];

        $this->assertSame('post_types', $parameter->getName());
        $this->assertFalse($parameter->isOptional(),
            'the wide case is a default again: a call site that forgets the scope publishes into any type');
    }

    /**
     * A `piece_id` ANOTHER KEY ALREADY USED DOES NOT RESOLVE TO THAT KEY'S POST.
     *
     * `piece_id` is the CALLER's name for its own piece, and two tenants on one
     * WordPress may legitimately choose the same string -- when they do, they
     * mean two different pieces. The repeat branch used to answer with whatever
     * post carried the string, of any type and made by any key, which handed a
     * key that merely GUESSED an identifier another tenant's post id and, with
     * `content.replace`, the revision that rewrites it.
     *
     * B gets its own post. Not a refusal: any refusal on this branch confirms
     * the identifier is taken, which is the same disclosure in smaller print,
     * and it would refuse a publish B is entitled to make.
     */
    #[Group('wpml')]
    public function test_a_piece_id_another_key_used_resolves_to_this_keys_own_post(): void {
        $first = $this->publish($this->body(), ['content.replace'], null, null, 'key-a');
        $this->assertTrue($first['created']);

        // B's own text, not A's -- so the revision B is handed is a hash of
        // what B just sent, and a revision equal to A's would be the
        // disclosure rather than a coincidence of two identical bodies.
        $second = $this->publish($this->body(['title' => 'B title', 'content' => '<p>B body.</p>']),
                                 ['content.replace'], null, null, 'key-b');

        $this->assertTrue($second['ok'], $second['reason'] ?? '');
        $this->assertTrue($second['created'], "a second key was answered with the first key's post");
        $this->assertNotSame($first['post_id'], $second['post_id']);
        $this->assertNotSame($first['revision'], $second['revision'],
            "a second key was handed the revision of the first key's post");
        $this->assertCount(2, WpStub::$inserted);
        $this->assertSame('key-a', WpStub::$meta[$first['post_id']][CadenceContentRequest::KEY_META]);
        $this->assertSame('key-b', WpStub::$meta[$second['post_id']][CadenceContentRequest::KEY_META]);

        // AND B CANNOT TELL A TAKEN IDENTIFIER FROM A FREE ONE. The answer for
        // a `piece_id` another key holds is the same answer, field for field,
        // as one nobody holds -- so nothing here is a probe for what is on the
        // site. A refusal on this branch, however carefully worded, would be.
        $free = $this->publish($this->body(['piece_id' => 'nobody-holds-this',
                                            'title' => 'B title', 'content' => '<p>B body.</p>']),
                               ['content.replace'], null, null, 'key-b');
        $this->assertSame(array_keys($second), array_keys($free));
        $this->assertSame($second['created'], $free['created']);
    }

    /**
     * THE ACCEPT-PROOF, and the duplicate this branch exists to stop: the SAME
     * key repeating its own `piece_id` is answered with its own post.
     *
     * Without it the test above passes on a lookup that finds nothing ever,
     * which publishes every retried piece twice.
     */
    #[Group('wpml')]
    public function test_the_same_key_repeating_its_own_piece_id_is_answered_with_that_post(): void {
        $first  = $this->publish($this->body(), ['content.replace'], null, null, 'key-a');
        $second = $this->publish($this->body(), ['content.replace'], null, null, 'key-a');

        $this->assertTrue($second['ok'], $second['reason'] ?? '');
        $this->assertFalse($second['created'], 'a key published its own piece twice');
        $this->assertSame($first['post_id'], $second['post_id']);
        $this->assertCount(1, WpStub::$inserted);
    }

    /**
     * AND THE NULL-IDENTITY PATH SURVIVES THE NARROWING.
     *
     * Every post Cadence made before the stamp existed carries none. A lookup
     * that skipped those would stop finding every client's existing posts and
     * publish each of them a second time on the next run -- a worse outcome
     * than the disclosure the scoping closes, and one that lands on sites this
     * repository does not control.
     */
    #[Group('wpml')]
    public function test_a_post_that_predates_the_stamp_is_still_found_by_the_repeat(): void {
        WpStub::add_post(42, 'post');
        WpStub::cadence_published(42, 'piece-2026-08-31-en');

        $r = $this->publish($this->body(), ['content.replace'], null, null, 'key-a');

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertFalse($r['created'], 'a post predating the stamp was published a second time');
        $this->assertSame(42, $r['post_id']);
        $this->assertSame([], WpStub::$inserted);
    }

    /**
     * A PIECE THIS KEY ALREADY HAS, PLACED IN A TYPE IT MAY NOT PUBLISH INTO,
     * IS REFUSED -- and the refusal names THAT branch.
     *
     * Otherwise the publish scope is enforced on creation and abandoned on the
     * repeat: a key narrowed to `post` would be handed the id and the revision
     * of its own `page`, which is exactly the pair `/content/replace` takes.
     *
     * A refusal rather than a second post because the post this resolves to is
     * one this key reaches, so saying so discloses nothing it could not ask
     * for -- and creating instead would put the same piece on the site twice.
     */
    #[Group('wpml')]
    public function test_a_piece_already_placed_in_a_type_out_of_scope_is_refused(): void {
        WpStub::add_post(42, 'page');
        WpStub::cadence_published(42, 'piece-2026-08-31-en');
        WpStub::$meta[42][CadenceContentRequest::KEY_META] = 'key-a';

        $r = $this->publish($this->body(['post_type' => 'post']), ['content.replace'],
                            null, ['post'], 'key-a');

        $this->assertFalse($r['ok'], "a key scoped to post was answered with its own page");
        $this->assertSame('existing_post_type_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$inserted, 'a refused repeat inserted anyway');
        $this->assertArrayNotHasKey('post_id', $r, 'the refusal handed back the post id anyway');
        $this->assertArrayNotHasKey('revision', $r, 'the refusal handed back the revision anyway');
        // NOT THE REQUEST'S TYPE. `post` is inside this key's scope and was
        // admitted; a caller told `post_type_out_of_scope` would go on
        // correcting a field that is already right.
        $this->assertNotSame('post_type_out_of_scope', $r['code']);
        // And the refusal does not name the type the piece is actually in: the
        // unstamped compatibility set reaches this branch too, and a type is a
        // fact about the site this caller did not ask for.
        $this->assertStringNotContainsString('page', $r['reason']);
    }

    /**
     * A PUBLISH INTO A TYPE THE KEY DOES NOT NAME IS REFUSED, AND NOTHING IS
     * CREATED.
     *
     * `content.publish` used to reach every post type the site registers, and
     * a key is worth what its widest grant is worth: a tenant's pipeline that
     * publishes blog posts could create a shop product, a page, or an entry in
     * whatever custom type another plugin on that site registers.
     */
    public function test_a_publish_into_a_type_the_key_does_not_name_is_refused(): void {
        $r = $this->publish($this->body(['post_type' => 'page']), ['content.replace'], null, ['post']);

        $this->assertFalse($r['ok'], 'a key scoped to post created a page');
        $this->assertSame('post_type_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$inserted, 'a refused publish inserted anyway');
        // NOT A MALFORMED BODY. `page` is a type this site registers and the
        // request is perfectly well formed; a caller told `bad_request` would
        // re-read its own JSON forever over a scope its operator has to widen.
        $this->assertNotSame('bad_request', $r['code']);
    }

    /**
     * THE ACCEPT-PROOF: the ordinary publish into the key's declared type still
     * succeeds.
     *
     * Without it the test above passes on a route that refuses every publish,
     * which is a narrowing nobody can tell from a broken connector.
     */
    #[Group('wpml')]
    public function test_a_publish_into_the_type_the_key_names_still_succeeds(): void {
        $r = $this->publish($this->body(['post_type' => 'post']), ['content.replace'], null, ['post']);

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertTrue($r['created']);
        $this->assertSame('post', WpStub::$inserted[0]['post_type'] ?? null);
    }

    /**
     * AND A KEY THAT NAMES NO TYPE PUBLISHES INTO ANY, which is the
     * compatibility path for every key already issued on a client's site.
     */
    #[Group('wpml')]
    public function test_a_key_naming_no_post_type_publishes_into_any_registered_type(): void {
        $r = $this->publish($this->body(['post_type' => 'page']));

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame('page', WpStub::$inserted[0]['post_type'] ?? null);
    }

    /**
     * THE REFUSAL IS NOT AN ENUMERATION ORACLE FOR THE SITE'S POST TYPES.
     *
     * Authorisation comes first: a key scoped to `post` gets the same answer
     * for a type this site registers and one it does not, so a caller cannot
     * ask "is `shop_order` registered here" one refusal at a time from behind a
     * key entitled to neither. `post_type_exists` is a read of the client's
     * site and it happens only after the key's own scope has admitted the type.
     */
    public function test_a_scoped_key_is_told_nothing_about_types_it_may_not_publish_into(): void {
        $registered   = $this->publish($this->body(['post_type' => 'page']), ['content.replace'], null, ['post']);
        WpStub::reset();
        WpStub::$capabilities = ['publish_posts' => [null], 'edit_posts' => [null]];
        $unregistered = $this->publish($this->body(['post_type' => 'shop_order']), ['content.replace'], null, ['post']);

        $this->assertSame('post_type_out_of_scope', $registered['code']);
        $this->assertSame('post_type_out_of_scope', $unregistered['code'],
            'the refusal told a caller which post types this site registers');
        // The reason names what the key reaches and what was asked for, and
        // says nothing about what the site has.
        $this->assertStringNotContainsString('this site', $unregistered['reason']);
    }

    /**
     * THE TWIN THAT KEEPS THE ORDERING HONEST: an UNSCOPED key asking for a
     * type this site does not register is still told so.
     *
     * Without it the check above is satisfied by never asking the site at all,
     * which would answer `bad_request` to nobody and let a publish reach
     * `wp_insert_post` with a type WordPress does not know.
     */
    public function test_an_unscoped_key_is_still_refused_a_type_this_site_does_not_register(): void {
        $r = $this->publish($this->body(['post_type' => 'shop_order']));

        $this->assertFalse($r['ok']);
        $this->assertSame('bad_request', $r['code']);
        $this->assertStringContainsString('shop_order', $r['reason']);
        $this->assertSame([], WpStub::$inserted);
    }

    /**
     * THE CREATING KEY'S ID IS STAMPED ON THE POST, in the same call that makes
     * it.
     *
     * This is what lets `translation.link` later ask whether a post is THIS
     * key's own rather than merely one of Cadence's. Written through
     * `meta_input` beside the identifier and for the same reason: a stamp
     * written afterwards leaves a window in which the post carries no identity.
     */
    #[Group('wpml')]
    public function test_the_created_post_carries_the_creating_keys_id(): void {
        $r = $this->publish($this->body(), ['content.replace'], null, null, 'aaaa1111');

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame('aaaa1111',
            WpStub::$inserted[0]['meta_input'][CadenceContentRequest::KEY_META] ?? null);
        $this->assertSame('aaaa1111',
            WpStub::$meta[$r['post_id']][CadenceContentRequest::KEY_META] ?? null);
        // The identifier is still there. The two travel together, and a stamp
        // that displaced the value the whole duplicate defence reads would be
        // invisible to every assertion about the stamp itself.
        $this->assertSame($this->body()['piece_id'],
            WpStub::$meta[$r['post_id']][CadenceContentRequest::META] ?? null);
        // NEVER THE SECRET. What is stamped travels in every database dump the
        // client's site produces.
        $this->assertStringNotContainsString('.',
            (string) WpStub::$meta[$r['post_id']][CadenceContentRequest::KEY_META]);
    }

    /**
     * AND A CALLER THIS ROUTE CANNOT NAME NOW PUBLISHES NOTHING AT ALL.
     *
     * It used to publish and merely stamp nothing. The attestation is stored
     * per connector key, so a request this route cannot name has no record to
     * hold a public key -- there is nothing on this site a signature could be
     * checked against, which is `no_public_key` and not `unknown_kid`. The
     * stamp is still absent, and now so is the post: an insert is the thing
     * that must not happen, and the meta is only how it would have been marked.
     *
     * IT IS UNREACHABLE THROUGH THE ROUTE and asserted anyway. Both
     * `permission_callback`s require a key that authenticates before this
     * runs, so `key_id` is never null there -- this is the layer below saying
     * no on its own, rather than trusting the layer above to have said it.
     */
    public function test_a_publish_this_route_cannot_name_is_refused_and_writes_nothing(): void {
        $r = $this->publish($this->body(), ['content.replace'], null, null, null);

        $this->assertFalse($r['ok'], 'a request naming no connector key published anyway');
        $this->assertSame('attestation_unverified', $r['code']);
        $this->assertStringContainsString('carries no attestation public key at all', $r['reason'],
            'the refusal did not name the no_public_key branch');
        $this->assertSame([], WpStub::$inserted);
    }

    /**
     * THE BYLINE REACHES THE INSERT.
     *
     * Nothing else is done with it: `post_author` is a field on the row. The
     * request is still authenticated by a key that confers no WordPress
     * identity, and this id does not change that.
     */
    #[Group('wpml')]
    public function test_the_keys_byline_is_the_posts_author(): void {
        $r = $this->publish($this->body(), ['content.replace'], 7);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame(7, WpStub::$inserted[0]['post_author'] ?? null);
    }

    /**
     * THE TWIN, AND THE PRE-EXISTING KEY. A key issued before keys carried a
     * byline passes none, and the insert is then exactly the insert this
     * connector has always made -- the field is absent, not 0 written by us.
     * An install that publishes today goes on publishing.
     */
    #[Group('wpml')]
    public function test_no_byline_leaves_the_author_field_untouched(): void {
        $r = $this->publish($this->body());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertArrayNotHasKey('post_author', WpStub::$inserted[0]);
    }

    #[Group('wpml')]
    public function test_creates_a_post_and_returns_its_id(): void {
        $r = $this->publish($this->body());
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
    #[Group('wpml')]
    public function test_the_same_piece_id_twice_creates_one_post(): void {
        $first  = $this->publish($this->body());
        $second = $this->publish($this->body());
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
    #[Group('wpml')]
    public function test_a_changed_body_under_a_used_id_neither_rewrites_nor_duplicates(): void {
        $first = $this->publish($this->body());
        $again = $this->publish($this->body(['title' => 'Rewritten', 'content' => 'x']));
        $this->assertFalse($again['created']);
        $this->assertSame($first['post_id'], $again['post_id']);
        $this->assertCount(1, WpStub::$inserted);
        $this->assertSame([], WpStub::$updated);
    }

    /**
     * THE ID IS SCOPED TO THIS PLUGIN'S OWN META KEY, and matched exactly. A
     * lookup that matched a prefix would let `piece-1` answer for `piece-10`.
     */
    #[Group('wpml')]
    public function test_a_different_piece_id_creates_a_second_post(): void {
        $this->publish($this->body(['piece_id' => 'piece-1']));
        $r = $this->publish($this->body(['piece_id' => 'piece-10']));
        $this->assertTrue($r['created']);
        $this->assertCount(2, WpStub::$inserted);
    }

    #[Group('wpml')]
    public function test_the_piece_id_is_recorded_on_the_post_it_created(): void {
        $r = $this->publish($this->body(['piece_id' => 'piece-7']));
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
            $r = $this->publish($this->body(['status' => $status]));
            $this->assertFalse($r['ok'], $status . ' was accepted');
            $this->assertSame('bad_request', $r['code']);
            $this->assertSame([], WpStub::$inserted, $status);
        }
    }

    #[Group('wpml')]
    public function test_accepts_the_three_statuses_it_does_publish(): void {
        foreach (['draft', 'pending', 'publish'] as $status) {
            WpStub::reset();
            WpStub::$capabilities = ['publish_posts' => [null], 'edit_posts' => [null]];
            $r = $this->publish($this->body(['status' => $status]));
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
        $r = $this->publish($this->body(['post_type' => 'not_registered']));
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
            $r = $this->publish($this->body($over));
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
    #[Group('wpml')]
    public function test_a_trashed_post_still_answers_for_its_identifier(): void {
        $first = $this->publish($this->body(['status' => 'publish']));
        WpStub::$posts[$first['post_id']]['post_status'] = 'trash';

        $again = $this->publish($this->body(['status' => 'publish']));
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
    #[Group('wpml')]
    public function test_a_draft_answers_for_its_identifier(): void {
        $first = $this->publish($this->body(['status' => 'draft']));
        $again = $this->publish($this->body(['status' => 'draft']));
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
    #[Group('wpml')]
    public function test_the_identifier_travels_in_the_insert_call(): void {
        $this->publish($this->body(['piece_id' => 'piece-9']));
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
    #[Group('wpml')]
    public function test_a_post_that_cannot_be_read_back_is_still_a_created_post(): void {
        WpStub::$post_read_fails = true;
        $r = $this->publish($this->body());
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
    #[Group('wpml')]
    public function test_an_insert_that_fails_is_reported_as_a_failure(): void {
        WpStub::$insert_fails = 'database is on fire';
        $r = $this->publish($this->body());
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
    #[Group('wpml')]
    public function test_a_zero_with_no_error_is_reported_as_a_failure(): void {
        WpStub::$insert_returns_zero = true;
        $r = $this->publish($this->body());
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
    #[Group('wpml')]
    public function test_a_failed_insert_leaves_the_identifier_free(): void {
        WpStub::$insert_fails = 'nope';
        $this->publish($this->body());
        $this->assertSame([], WpStub::$meta);

        WpStub::$insert_fails = null;
        $r = $this->publish($this->body());
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
    #[Group('wpml')]
    public function test_a_created_post_reports_what_it_placed(): void {
        $r = $this->publish($this->body(['piece_id' => 'p-1']));
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
    #[Group('wpml')]
    public function test_an_idempotent_repeat_reports_the_post_that_is_already_there(): void {
        $first  = $this->publish($this->body());
        $second = $this->publish($this->body());
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
    #[Group('wpml')]
    public function test_a_language_the_site_cannot_serve_is_reported_not_dropped(): void {
        WpStub::$active_languages = ['en' => [], 'de' => []];
        $r = $this->publish($this->body([
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
    #[Group('wpml')]
    public function test_this_route_never_reports_a_link_it_did_not_make(): void {
        $r = $this->publish($this->body());
        $this->assertSame([], $r['report']['linked']);
        $this->assertSame([], WpStub::$writes);
    }

    /** A refusal reports no placement at all, and writes nothing. */
    #[Group('wpml')]
    public function test_a_declaration_that_disagrees_with_the_site_places_nothing(): void {
        WpStub::$wpml_reads  = false;
        WpStub::$wpml_writes = false;
        $r = $this->publish($this->body([
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
    #[Group('wpml')]
    public function test_the_released_field_name_still_identifies_a_piece(): void {
        $body = $this->body();
        $body['external_id'] = $body['piece_id'];
        unset($body['piece_id']);

        $first = $this->publish($body);
        $this->assertTrue($first['created']);
        $this->assertSame('piece-2026-08-31-en', $first['report']['piece_id']);
        $this->assertFalse($this->publish($this->body())['created'],
            'the same piece under the two spellings became two posts');
    }

    /**
     * A NEWLY CREATED POST'S REVISION IS UNGATED. This text is the caller's
     * own -- it did not exist a moment ago -- so there is nothing here to
     * disclose, and a key holding only `content.publish` still gets it.
     */
    #[Group('wpml')]
    public function test_a_created_posts_revision_is_handed_to_a_key_with_no_replace_capability(): void {
        $r = $this->publish($this->body(), []);
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
    #[Group('wpml')]
    public function test_a_key_without_content_replace_is_not_handed_the_revision_on_a_repeat(): void {
        $first  = $this->publish($this->body());
        $second = $this->publish($this->body(), []);
        $this->assertFalse($second['created']);
        $this->assertSame($first['post_id'], $second['post_id']);
        $this->assertArrayNotHasKey('revision', $second,
            'a key with no content.replace capability was handed the proof that endpoint demands');
    }

    /** THE TWIN: a key that DOES hold `content.replace` is handed it. */
    #[Group('wpml')]
    public function test_a_key_with_content_replace_is_handed_the_revision_on_a_repeat(): void {
        $first  = $this->publish($this->body());
        $second = $this->publish($this->body(), ['content.replace']);
        $this->assertFalse($second['created']);
        $this->assertArrayHasKey('revision', $second);
        $this->assertSame($first['revision'], $second['revision']);
    }
}
