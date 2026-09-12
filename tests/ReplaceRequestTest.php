<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Replacing the text of a post this connector published.
 *
 * THE FAILURE THIS IS SHAPED AROUND IS THE OVERWRITE. Somebody opened the
 * article in wp-admin and fixed a sentence; the pipeline, holding a copy it
 * read before that, sends a rewrite. Applied, the hand edit is gone and
 * nothing anywhere reports it -- the caller is told it succeeded, because it
 * did. That is the same class of failure as a wrong translation link: it
 * destroys work somebody did by hand.
 *
 * So a replacement names the text it believes it is replacing, and this code
 * re-derives that text from the site before it writes. The two disagreeing is
 * a refusal, never a merge and never a write.
 *
 * The revision is DERIVED, never stored. A marker this plugin wrote and only
 * this plugin updates says nothing about the edit that matters, which is the
 * one made by a human who never touched it.
 */
final class ReplaceRequestTest extends TestCase {

    protected function setUp(): void {
        WpStub::reset();
    }

    /** Publish a piece the way the pipeline does, and hand back what it was told. */
    private function publish(array $over = [], ?string $key_id = null,
                             ?array $post_types = null): array {
        $r = CadenceContentRequest::run(array_merge([
            'piece_id' => 'piece-1',
            'language'    => 'en',
            // The stub site has the WPML hooks and serves `en` (see
            // ContentRequestTest); a monolingual declaration here would be
            // the disagreement CadenceLanguageDeclaration exists to refuse,
            // which is not what this file is about.
            'declared'    => ['multilingual' => true, 'languages' => ['en']],
            'post_type'   => 'post',
            'status'      => 'publish',
            'title'       => 'The original',
            'content'     => '<p>Original body.</p>',
        ], $over),
            // A KEY HOLDING `content.replace`, throughout this file. What is
            // under test here is the rewrite itself -- the disclosure gate
            // that withholds the revision from a key that could never act on
            // it is `content.replace`'s own concern, covered where that gate
            // lives (ContentRequestTest).
            static fn (string $capability): bool => true,
            // Any post type unless a test says otherwise: most of this file
            // is about replacing, not about the publish scope, and `run`
            // demands the argument either way.
            $post_types,
            // No byline.
            null,
            // AND THE STAMP, or none. Written by `/content` in the same call
            // as the identifier, so a test that wants a post belonging to a
            // named key gets one the way the pipeline makes it rather than by
            // writing the meta row by hand.
            $key_id);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        return $r;
    }

    /**
     * `run` as this file's tests mean it: any post type, and no key id.
     *
     * BOTH ARGUMENTS ARE REQUIRED ON `run` ITSELF -- null is the wide case for
     * the first and the narrow one for the second, and a call site that forgot
     * either would be silently wrong in opposite directions. They are defaulted
     * HERE because these tests are about the rewrite, and a key id is only
     * meaningful against a post that carries a stamp, which `publish` does not
     * write. The tests that are about the scope pass both explicitly.
     */
    private function replace(array $body, ?array $post_types = null, ?string $key_id = null): array {
        return CadenceReplaceRequest::run($body, $post_types, $key_id);
    }

    /** The statements `$wpdb` was given, in order. */
    private function statements(): array {
        return $GLOBALS['wpdb']->log;
    }

    /** A replacement for that piece, naming the revision publishing answered with. */
    private function body(array $published, array $over = []): array {
        return array_merge([
            'piece_id' => 'piece-1',
            'post_id'     => $published['post_id'],
            'revision'    => $published['revision'] ?? '',
            'title'       => 'The rewrite',
            'content'     => '<p>Rewritten body.</p>',
        ], $over);
    }

    /**
     * A CALLER HAS TO BE ABLE TO NAME A REVISION AT ALL. Publishing answers
     * with the one the site now holds; without that there is no way to make a
     * first replacement, and the endpoint below is unreachable in practice
     * however well it is tested here.
     */
    public function test_publishing_answers_with_the_revision_the_site_now_holds(): void {
        $r = $this->publish();
        $this->assertIsString($r['revision'] ?? null);
        $this->assertSame(CadenceRevision::of('The original', '<p>Original body.</p>'),
                          $r['revision']);
    }

    /**
     * AND THE REVISION TRACKS THE SITE, NOT THE REQUEST. A repeat under an
     * identifier already used is still answered with the post that exists --
     * and the revision it comes back with is that post's, so a caller whose
     * body no longer matches the site can see it in the answer.
     */
    public function test_a_repeat_answers_with_the_revision_the_post_actually_has(): void {
        $first = $this->publish();
        WpStub::$posts[$first['post_id']]['post_content'] = '<p>A human rewrote this.</p>';

        $again = $this->publish();
        $this->assertFalse($again['created']);
        $this->assertSame(CadenceRevision::of('The original', '<p>A human rewrote this.</p>'),
            $again['revision'], 'the revision was computed from the request, not from the site');
        $this->assertNotSame($first['revision'], $again['revision']);
    }

    /**
     * THE REVISION IS NOT WRITTEN ANYWHERE. A stored one is updated by this
     * plugin and by nothing else, so it agrees with the site exactly until a
     * human edits the post -- the single moment it needed to disagree.
     */
    public function test_the_revision_is_not_recorded_on_the_post(): void {
        $published = $this->publish();
        $this->assertSame([CadenceContentRequest::META],
            array_keys(WpStub::$meta[$published['post_id']]),
            'a stored revision cannot notice the hand edit it exists to notice');
    }

    public function test_a_replacement_naming_the_current_revision_rewrites_the_post(): void {
        $published = $this->publish();
        $r = $this->replace($this->body($published));

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertFalse($r['created'], 'a replacement reported creating something');
        $this->assertSame($published['post_id'], $r['post_id']);
        $this->assertCount(1, WpStub::$updated);
        $this->assertSame($published['post_id'], WpStub::$updated[0]['ID']);
        $this->assertSame('The rewrite', WpStub::$updated[0]['post_title']);
        $this->assertSame('<p>Rewritten body.</p>', WpStub::$updated[0]['post_content']);
        $this->assertCount(1, WpStub::$inserted, 'a replacement created a second post');
        // And the answer carries the new revision, so the caller can make its
        // next replacement without reading the site through some other door.
        $this->assertSame(CadenceRevision::of('The rewrite', '<p>Rewritten body.</p>'),
                          $r['revision']);
        $this->assertNotSame($published['revision'], $r['revision']);
    }

    /**
     * THE ONE THIS ENDPOINT EXISTS FOR. A human edited the post after the
     * caller read it, and the caller asks to replace anyway. The hand edit
     * stays, and the refusal names both revisions so the caller can see which
     * of the two it was wrong about.
     */
    public function test_a_post_a_human_edited_since_is_not_overwritten(): void {
        $published = $this->publish();
        $edited = '<p>Original body, corrected by hand.</p>';
        WpStub::$posts[$published['post_id']]['post_content'] = $edited;

        $r = $this->replace($this->body($published));

        $this->assertFalse($r['ok'], 'a hand edit was overwritten');
        $this->assertSame('revision_mismatch', $r['code']);
        $this->assertSame([], WpStub::$updated);
        $this->assertSame($edited, WpStub::$posts[$published['post_id']]['post_content']);
        $this->assertStringContainsString($published['revision'], $r['reason']);
        $this->assertStringContainsString(
            CadenceRevision::of('The original', $edited), $r['reason']);
    }

    /**
     * THE TWIN: the same request against a post nobody touched is written.
     * Without it the refusal above holds for a `run` that refuses everything.
     */
    public function test_the_same_replacement_against_an_untouched_post_is_written(): void {
        $published = $this->publish();
        $r = $this->replace($this->body($published));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(1, WpStub::$updated);
    }

    /**
     * A REPLACEMENT IS NOT A RETRY, AND SAYS SO. Sent twice -- because the
     * first answer was lost to a timeout, which is the case /content exists to
     * survive -- the second is refused rather than applied again. The refusal
     * names the revision the site holds, which is the one the first attempt
     * answered with: a caller comparing them can tell that its write landed.
     */
    public function test_replaying_a_replacement_that_already_landed_is_refused(): void {
        $published = $this->publish();
        $first = $this->replace($this->body($published));
        $this->assertTrue($first['ok'], $first['reason'] ?? '');

        $replay = $this->replace($this->body($published));
        $this->assertFalse($replay['ok']);
        $this->assertSame('revision_mismatch', $replay['code']);
        $this->assertCount(1, WpStub::$updated, 'the replay rewrote the post a second time');
        $this->assertStringContainsString($first['revision'], $replay['reason']);
    }

    /**
     * A POST THIS SITE DOES NOT HAVE IS NOT A POST TO WRITE. Nothing is
     * created in its place: this endpoint replaces, and a caller that wanted
     * something to exist has an endpoint for that.
     */
    public function test_a_replacement_for_a_post_this_site_does_not_have_is_refused(): void {
        $published = $this->publish();
        $r = $this->replace($this->body($published, ['post_id' => 4242]));

        $this->assertFalse($r['ok']);
        $this->assertSame('post_missing', $r['code']);
        $this->assertSame([], WpStub::$updated);
        $this->assertCount(1, WpStub::$inserted, 'a replacement created the post it could not find');
    }

    /**
     * THE POST AND THE PIECE HAVE TO BE THE SAME THING. The caller holds a map
     * from its own identifier to a WordPress post id; that map is on another
     * machine and can be stale -- a restore from backup, a migration, a post
     * deleted and re-created -- and post 41 is then somebody else's article.
     *
     * The two posts here carry the SAME words, so the revision agrees and only
     * the identifier disagrees: a version that dropped this check would write.
     */
    public function test_a_replacement_naming_a_post_that_is_a_different_piece_is_refused(): void {
        $one = $this->publish();
        $two = $this->publish(['piece_id' => 'piece-2']);
        $this->assertSame($one['revision'], $two['revision'], 'the two pieces must be indistinguishable by revision');

        $r = $this->replace($this->body($one, ['post_id' => $two['post_id']]));

        $this->assertFalse($r['ok'], 'a replacement was written over a different piece');
        $this->assertSame('identifier_mismatch', $r['code']);
        $this->assertSame([], WpStub::$updated);
        $this->assertStringContainsString('piece-1', $r['reason'],
            'the refusal does not say which piece was asked for');
        // AND NOT THE ONE THE SITE HOLDS. The stored identifier is protected
        // meta, which the REST API does not expose; naming it in a refusal
        // hands it to any caller holding a key with `content.replace`, who can
        // then send a replacement that passes this check for a piece it never
        // had.
        $this->assertStringNotContainsString('piece-2', $r['reason'],
            'the refusal disclosed the identifier the site stores');
        // The two cases are still told apart: this post IS one of ours.
        $this->assertStringContainsString('a different piece', $r['reason']);
    }

    /**
     * AND A POST THIS CONNECTOR NEVER PUBLISHED IS NOT ITS TO REWRITE. Same
     * clause, from the side where the site has no identifier at all -- a page
     * a human wrote, named by a caller whose map points at the wrong site.
     */
    public function test_a_replacement_for_a_post_this_connector_never_published_is_refused(): void {
        WpStub::add_post(7, 'post');
        $r = $this->replace([
            'piece_id' => 'piece-1',
            'post_id'     => 7,
            // The revision the site does hold for it, so nothing but the
            // identifier is in disagreement.
            'revision'    => CadenceRevision::of('', ''),
            'title'       => 'The rewrite',
            'content'     => '<p>Rewritten body.</p>',
        ]);

        $this->assertFalse($r['ok'], 'a post this connector never made was rewritten');
        $this->assertSame('identifier_mismatch', $r['code']);
        $this->assertSame([], WpStub::$updated);
        // The other side of the same clause, and it reads differently: this
        // post carries no identifier at all, which is a different thing for
        // the caller to have got wrong.
        $this->assertStringContainsString('not a piece this connector published',
            $r['reason']);
    }

    /**
     * WHETHER THE PIECE IS IN FRONT OF THE PUBLIC IS NOT A REPLACEMENT'S
     * DECISION. A rewrite that also published a draft, or republished
     * something a human had taken down, is the same destruction one field
     * across -- and it would be reported as a successful rewrite.
     */
    public function test_a_replacement_does_not_change_whether_the_post_is_published(): void {
        $published = $this->publish(['status' => 'draft']);
        $r = $this->replace($this->body($published));

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertArrayNotHasKey('post_status', WpStub::$updated[0],
            'a replacement decided the post status');
        $this->assertSame('draft', WpStub::$posts[$published['post_id']]['post_status']);
    }

    /** And the piece keeps the identifier it is known by. */
    public function test_the_piece_keeps_its_identifier(): void {
        $published = $this->publish();
        $this->replace($this->body($published));
        $this->assertSame('piece-1',
            WpStub::$meta[$published['post_id']][CadenceContentRequest::META] ?? null);
    }

    /**
     * `external_id` IS WHAT 0.1.0 CALLED IT on `/content`, and this route was
     * written asking for it before Elegia-io/cadence#1282 settled that the wire
     * carries ONE spelling. It asks for `piece_id` now, and accepts the old name
     * for the same reason `/content` does — but the alias is tested rather than
     * assumed, because an alias nothing exercises is a line of code claiming a
     * compatibility nobody has seen work.
     */
    public function test_the_released_field_name_still_says_which_piece(): void {
        $published = $this->publish();
        $body = $this->body($published);
        $body['external_id'] = $body['piece_id'];
        unset($body['piece_id']);

        $r = $this->replace($body);
        $this->assertTrue($r['ok'], 'the 0.1.0 spelling no longer identifies a piece');
    }

    public function test_refuses_a_body_whose_shape_it_cannot_read(): void {
        $published = $this->publish();
        foreach ([
            'no piece_id'           => ['piece_id' => null],
            'piece_id is an int'    => ['piece_id' => 7],
            'piece_id is blank'     => ['piece_id' => '   '],
            'no post_id'            => ['post_id' => null],
            'post_id is a string'   => ['post_id' => '100'],
            'post_id is a float'    => ['post_id' => 100.0],
            'post_id is a bool'     => ['post_id' => true],
            'post_id is zero'       => ['post_id' => 0],
            'post_id is negative'   => ['post_id' => -1],
            'no revision'           => ['revision' => null],
            'revision is an int'    => ['revision' => 7],
            'revision is blank'     => ['revision' => ''],
            'title is an array'     => ['title' => ['a']],
            'content is an int'     => ['content' => 3],
        ] as $why => $over) {
            $r = $this->replace($this->body($published, $over));
            $this->assertFalse($r['ok'], $why);
            $this->assertSame('bad_replacement', $r['code'], $why);
            $this->assertSame([], WpStub::$updated, $why);
        }
    }

    /**
     * WORDPRESS FAILING IS NOT WORDPRESS SUCCEEDING, on this path as on the
     * insert. `wp_update_post` returns a WP_Error rather than throwing.
     */
    public function test_an_update_that_fails_is_reported_as_a_failure(): void {
        $published = $this->publish();
        WpStub::$update_fails = 'database is on fire';
        $r = $this->replace($this->body($published));

        $this->assertFalse($r['ok']);
        $this->assertSame('update_failed', $r['code']);
        $this->assertStringContainsString('database is on fire', $r['reason']);
        $this->assertArrayNotHasKey('revision', $r,
            'a revision was answered for a rewrite that did not happen');
    }

    /**
     * AND SO IS A ZERO WITH NO ERROR ATTACHED, which is what a filter in a
     * plugin this one does not control produces. Read as an id it is falsy,
     * and falsy is what "no post" looks like everywhere else.
     */
    public function test_a_zero_with_no_error_is_reported_as_a_failure(): void {
        $published = $this->publish();
        WpStub::$update_returns_zero = true;
        $r = $this->replace($this->body($published));

        $this->assertFalse($r['ok']);
        $this->assertSame('update_failed', $r['code']);
        $this->assertSame('The original', WpStub::$posts[$published['post_id']]['post_title'],
            'a rewrite that failed was reported as one that happened');
    }

    /**
     * EVERY REFUSAL CARRIES ITS OWN STABLE CODE, and the five causes carry five
     * different ones. The reason is prose for a human reading a log; the caller
     * is a program deciding whether to re-read this site or to stop and fix
     * itself, and it decides from the code.
     */
    public function test_each_refusal_carries_its_own_code(): void {
        $causes = [
            'bad_replacement' => fn (array $p): array => $this->body($p, ['revision' => 7]),
            'post_missing'    => fn (array $p): array => $this->body($p, ['post_id' => 4242]),
            'identifier_mismatch' => fn (array $p): array => $this->body($p, ['piece_id' => 'piece-9']),
            'revision_mismatch'   => fn (array $p): array => $this->body($p, [
                'revision' => CadenceRevision::of('something', 'else')]),
            'update_failed' => function (array $p): array {
                WpStub::$update_fails = 'nope';
                return $this->body($p);
            },
            'no_row_lock' => function (array $p): array {
                $GLOBALS['wpdb']->fails_on = 'START TRANSACTION';
                return $this->body($p);
            },
        ];
        // The two that need the call itself narrowed rather than the body
        // changed: a scope is a property of the asking key, not of the request.
        $scoped = [
            'replace_other_key' => [null, 'key-b'],
            'existing_post_type_out_of_scope' => [['page'], 'key-a'],
        ];

        $seen = [];
        foreach ($causes as $expected => $arrange) {
            WpStub::reset();
            $r = $this->replace($arrange($this->publish()));
            $this->assertFalse($r['ok'], $expected . ' was supposed to be refused');
            $this->assertSame([], WpStub::$updated, $expected);
            $this->assertSame($expected, $r['code'] ?? null, $expected);
            $seen[] = $r['code'];
        }
        foreach ($scoped as $expected => [$types, $asking]) {
            WpStub::reset();
            $r = $this->replace($this->body($this->publish([], 'key-a')), $types, $asking);
            $this->assertFalse($r['ok'], $expected . ' was supposed to be refused');
            $this->assertSame([], WpStub::$updated, $expected);
            $this->assertSame($expected, $r['code'] ?? null, $expected);
            $seen[] = $r['code'];
        }
        $this->assertCount(8, array_unique($seen));

        // AND THE PUBLISHED LIST IS THAT LIST, so the coverage test over in
        // RestRouteTest has something real to be measured against: a code added
        // here and not classified there would otherwise be a live 500.
        sort($seen);
        $codes = CadenceReplaceRequest::REFUSAL_CODES;
        sort($codes);
        $this->assertSame($seen, $codes);
    }

    public function test_the_same_bytes_are_one_revision_and_different_bytes_are_not(): void {
        $this->assertSame(CadenceRevision::of('t', 'c'), CadenceRevision::of('t', 'c'));
        $this->assertNotSame(CadenceRevision::of('t', 'c'), CadenceRevision::of('t', 'c '));
        $this->assertNotSame(CadenceRevision::of('t', 'c'), CadenceRevision::of('t ', 'c'));
    }

    /**
     * THE BOUNDARY BETWEEN TITLE AND CONTENT IS PART OF WHAT IS HASHED. Under
     * plain concatenation, title `ab` with content `c` and title `a` with
     * content `bc` are one revision -- so a replacement naming either would be
     * accepted against the other, and the check would pass while overwriting
     * a post whose title the caller has never seen.
     */
    public function test_moving_a_character_from_the_title_into_the_content_changes_the_revision(): void {
        $this->assertNotSame(CadenceRevision::of('ab', 'c'), CadenceRevision::of('a', 'bc'));
    }

    /**
     * THE WINDOW BETWEEN THE CHECK AND THE ACT.
     *
     * A check made strictly before a write is not a guard against a concurrent
     * writer: the hand edit that matters is the one that lands in between. Here
     * `get_post` answers what this process read -- WordPress's object cache,
     * which the editing process invalidated in ITS process and not in this one
     * -- while the row itself already holds the correction somebody typed. The
     * revision the request names matches the stale copy exactly, so every check
     * made against that copy passes and the write goes through.
     *
     * The text has to be read where the write happens: from the row, under the
     * lock the write is then made inside.
     */
    public function test_an_edit_that_landed_after_the_cached_read_is_not_overwritten(): void {
        $published = $this->publish();
        $id = $published['post_id'];
        $edited = '<p>Original body, corrected by hand.</p>';
        // `get_post` still answers the pre-edit text; the row does not.
        WpStub::$row_override[$id] = ['post_content' => $edited];

        $r = $this->replace($this->body($published));

        $this->assertFalse($r['ok'], 'a hand edit that landed mid-request was overwritten');
        $this->assertSame('revision_mismatch', $r['code']);
        $this->assertSame([], WpStub::$updated);
        $this->assertStringContainsString(CadenceRevision::of('The original', $edited),
            $r['reason'], 'the refusal named a revision the row does not hold');
    }

    /**
     * THE TWIN. The same arrangement with the row AGREEING with the cached
     * copy is written -- so the refusal above is about the disagreement and
     * not about the override being set at all.
     */
    public function test_a_row_that_agrees_with_the_cached_read_is_still_written(): void {
        $published = $this->publish();
        WpStub::$row_override[$published['post_id']] =
            ['post_title' => 'The original', 'post_content' => '<p>Original body.</p>'];

        $r = $this->replace($this->body($published));

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(1, WpStub::$updated);
    }

    /**
     * AND THE SAME CLAUSE FROM THE TITLE'S SIDE. Both fields are what a
     * replacement overwrites, so both have to be read from the row: a version
     * reading only the content from there would pass this.
     */
    public function test_a_title_edited_after_the_cached_read_is_not_overwritten(): void {
        $published = $this->publish();
        WpStub::$row_override[$published['post_id']] = ['post_title' => 'Retitled by hand'];

        $r = $this->replace($this->body($published));

        $this->assertFalse($r['ok'], 'a hand-edited title was overwritten');
        $this->assertSame('revision_mismatch', $r['code']);
        $this->assertSame([], WpStub::$updated);
    }

    /**
     * THE ORDER IS THE GUARANTEE. Lock the row, read it, write it, release --
     * with nothing between the read and the write that another writer could
     * get through. A `SELECT` without `FOR UPDATE` reads the same bytes and
     * holds nothing, so the sequence is asserted, not just the values.
     */
    public function test_the_text_is_read_under_a_lock_the_write_happens_inside(): void {
        $published = $this->publish();
        $r = $this->replace($this->body($published));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');

        $log = $this->statements();
        $this->assertSame('START TRANSACTION', $log[0] ?? null);
        $this->assertStringContainsString('FOR UPDATE', $log[1] ?? '',
            'the row was read without being locked');
        $this->assertStringContainsString('FROM wp_posts', $log[1] ?? '');
        $this->assertStringContainsString('WHERE ID = ' . $published['post_id'], $log[1] ?? '');
        $this->assertSame('UPDATE (wp_update_post)', $log[2] ?? null,
            'the write did not happen between the locking read and the release');
        $this->assertSame('COMMIT', $log[3] ?? null);
        $this->assertCount(4, $log, 'the row was touched more times than the sequence allows');
    }

    /**
     * A REFUSAL TAKEN UNDER THE LOCK RELEASES IT, and says the honest thing
     * while doing so: nothing was written, so the transaction is rolled back
     * rather than committed. A refusal that returned without either would hold
     * the row until the process ended, and every other writer would wait.
     */
    public function test_a_refusal_taken_under_the_lock_rolls_back_and_releases(): void {
        $published = $this->publish();
        WpStub::$row_override[$published['post_id']] = ['post_content' => 'edited by hand'];

        $r = $this->replace($this->body($published));

        $this->assertSame('revision_mismatch', $r['code']);
        $log = $this->statements();
        $this->assertSame('ROLLBACK', end($log), 'the lock was not released');
        $this->assertNotContains('COMMIT', $log);
    }

    /**
     * AND A REFUSAL TAKEN BEFORE THE LOCK NEVER TAKES ONE. A request naming a
     * post this site does not have, or naming a different piece, is decided
     * without holding a row anybody else may be waiting for.
     */
    public function test_a_refusal_decided_before_the_lock_opens_no_transaction(): void {
        $published = $this->publish();
        foreach ([
            'bad_replacement'     => ['revision' => 7],
            'post_missing'        => ['post_id' => 4242],
            'identifier_mismatch' => ['piece_id' => 'piece-9'],
        ] as $code => $over) {
            $GLOBALS['wpdb']->log = [];
            $r = $this->replace($this->body($published, $over));
            $this->assertSame($code, $r['code'] ?? null);
            $this->assertSame([], $this->statements(), $code . ' locked a row to refuse');
        }
    }

    /**
     * A SITE THAT CANNOT SERIALISE THIS REFUSES IT. The transaction is what
     * makes the check and the write one act; without it the endpoint is back
     * to checking one copy and writing over another, and the direction of
     * error says which way that resolves -- a refused rewrite costs the caller
     * a re-read, an applied one costs a human work they cannot get back.
     */
    public function test_a_site_that_cannot_open_a_transaction_is_refused_rather_than_written(): void {
        $published = $this->publish();
        $GLOBALS['wpdb']->fails_on = 'START TRANSACTION';

        $r = $this->replace($this->body($published));

        $this->assertFalse($r['ok'], 'a rewrite was written with no lock over it');
        $this->assertSame('no_row_lock', $r['code']);
        $this->assertSame([], WpStub::$updated);
        $this->assertNotContains('COMMIT', $this->statements());
    }

    /**
     * A ROW THAT IS GONE BY THE TIME IT IS LOCKED is the same answer as one
     * that was never there -- and, again, not a write. `get_post` answered for
     * it a moment ago; something removed it in between, and this endpoint does
     * not create.
     */
    public function test_a_row_that_is_gone_by_the_time_it_is_locked_is_refused(): void {
        $published = $this->publish();
        WpStub::$rows_gone = [$published['post_id']];

        $r = $this->replace($this->body($published));

        $this->assertFalse($r['ok']);
        $this->assertSame('post_missing', $r['code']);
        $this->assertSame([], WpStub::$updated);
        $log = $this->statements();
        $this->assertSame('ROLLBACK', end($log));
    }

    /**
     * AND A HOOK THAT THROWS MID-WRITE DOES NOT LEAVE THE ROW LOCKED. Any
     * plugin on the site can hang code on `save_post`, and code that throws
     * inside an open transaction leaves every other writer of that row waiting
     * on a request that is already over.
     */
    public function test_a_write_that_throws_releases_the_row(): void {
        $published = $this->publish();
        WpStub::$update_throws = 'a save_post hook exploded';

        try {
            $this->replace($this->body($published));
            $this->fail('the exception was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('a save_post hook exploded', $e->getMessage());
        }
        $log = $this->statements();
        $this->assertSame('ROLLBACK', end($log), 'the lock survived the exception');
        $this->assertNotContains('COMMIT', $log);
    }

    /**
     * WHOSE POST IT IS, WHICH IS NOT WHICH POST IT IS.
     *
     * `identifier_mismatch` answers "is this the post you say it is". The
     * identifier is not a secret -- it travels in plan payloads, ledger rows
     * and operator surfaces -- so on a site holding two keys a caller that
     * learns another tenant's `piece_id` satisfies that check over an article
     * it has nothing to do with, and a replace overwrites a published title
     * and body.
     */
    public function test_a_post_another_key_published_is_not_rewritten(): void {
        $published = $this->publish([], 'key-a');
        $r = $this->replace($this->body($published), null, 'key-b');

        $this->assertFalse($r['ok'], "a second key rewrote the first key's post");
        $this->assertSame('replace_other_key', $r['code']);
        $this->assertSame([], WpStub::$updated);
        $this->assertSame('The original', WpStub::$posts[$published['post_id']]['post_title']);
    }

    /**
     * AND THE REFUSAL IS DECIDED BEFORE THE ROW IS TOUCHED. A refusal over the
     * credential has no reason to make every other writer of that post wait on
     * a request that already decided to do nothing.
     */
    public function test_the_identity_refusal_opens_no_transaction(): void {
        $this->replace($this->body($this->publish([], 'key-a')), null, 'key-b');
        $this->assertSame([], $this->statements(), 'the row was held for a refusal decided off it');
    }

    /**
     * THE REFUSAL IS NOT AN ORACLE ABOUT THE TARGET, AND THAT IS THE ORDERING.
     *
     * Two posts another key published: one carrying the identifier the request
     * names, one carrying a different one. `identifier_mismatch` tells those
     * apart -- it has to, for a caller debugging a stale map -- so had it been
     * asked first, only the post that DOES carry the named identifier would
     * ever have reached the identity branch, and which refusal came back would
     * say which case it was. Asked first, identity answers both with one
     * sentence.
     *
     * The reasons are compared as whole strings rather than for a substring:
     * a check that both merely CONTAIN the same clause passes while one of
     * them appends the fact that separates them.
     */
    public function test_the_identity_refusal_cannot_tell_the_two_targets_apart(): void {
        $reasons = [];
        foreach (['piece-1' => 'names the identifier the post carries',
                  'piece-9' => 'names a different identifier'] as $named => $case) {
            WpStub::reset();
            $published = $this->publish([], 'key-a');
            $r = $this->replace($this->body($published, ['piece_id' => $named]), null, 'key-b');
            $this->assertSame('replace_other_key', $r['code'] ?? null, $case);
            $reasons[] = $r['reason'];
        }
        $this->assertSame($reasons[0], $reasons[1],
            'the refusal says which of the two cases the target is');
    }

    /**
     * AND IT NAMES NOTHING ABOUT THE POST BUT THE ID THE CALLER ALREADY SENT.
     *
     * Not the key id the post carries -- another tenant's identifier -- and
     * not the post's type, title, author or revision. Asserted against the
     * values this test put on the site, so a refusal that started quoting any
     * of them fails here rather than being read past.
     */
    public function test_the_identity_refusal_names_nothing_the_caller_did_not_send(): void {
        $published = $this->publish(['title' => 'A secret headline',
                                     'content' => '<p>A secret body.</p>',
                                     // A type the sentence cannot name by
                                     // accident: `post` is a word the refusal
                                     // uses for the row itself, so a check
                                     // over it could not fail.
                                     'post_type' => 'page'], 'key-a');
        $r = $this->replace($this->body($published), null, 'key-b');

        foreach (['key-a', 'A secret headline', 'A secret body', 'page',
                  $published['revision']] as $withheld) {
            $this->assertStringNotContainsString($withheld, $r['reason'], $withheld);
        }
        // And it is a sentence about THIS act: a caller matching on the code to
        // decide what did not happen is not told about linking.
        $this->assertStringContainsString('nothing was written', $r['reason']);
        $this->assertStringNotContainsString('linked', $r['reason']);
    }

    /** THE ACCEPT-PROOF: a key rewrites the piece it published itself. */
    public function test_a_key_rewrites_its_own_piece(): void {
        $published = $this->publish([], 'key-a');
        $r = $this->replace($this->body($published), null, 'key-a');

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame('The rewrite', WpStub::$posts[$published['post_id']]['post_title']);
    }

    /**
     * THE COMPATIBILITY PATH, AND THE SECOND HALF OF THE ACCEPT-PROOF. Every
     * piece already on a client's site was created before the stamp existed
     * and carries none. Refusing those would turn a working rewrite into a 403
     * the moment the plugin updated under it, for a holder who can neither see
     * the stamp nor add it.
     */
    public function test_a_piece_that_predates_the_key_stamp_is_rewritten_by_any_key(): void {
        $published = $this->publish();
        $this->assertArrayNotHasKey(CadenceContentRequest::KEY_META,
            WpStub::$meta[$published['post_id']], 'this post was stamped, so it proves nothing');

        $r = $this->replace($this->body($published), null, 'key-b');
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame('The rewrite', WpStub::$posts[$published['post_id']]['post_title']);
    }

    /**
     * AND NO IDENTITY IS NOT A WILDCARD. A stamped post asked about by a caller
     * this route cannot name -- nothing presented, or a key that did not
     * authenticate -- is refused. The compatibility path is a property of the
     * POST, never of the caller.
     */
    public function test_a_stamped_piece_is_not_rewritten_by_a_caller_with_no_identity(): void {
        $published = $this->publish([], 'key-a');
        $r = $this->replace($this->body($published), null, null);

        $this->assertFalse($r['ok'], 'an unnamed caller rewrote a stamped post');
        $this->assertSame('replace_other_key', $r['code']);
        $this->assertSame([], WpStub::$updated);
    }

    /**
     * THE TYPE SCOPE, AND WHAT IT IS FOR HERE. `created_by` admits every post
     * that predates the stamp, and over that set two keys on one site do not
     * separate at all -- so the post types named on the key are the only
     * narrowing left there, and they narrow the stamped case too: an operator
     * who takes `page` off a live key stops that key rewriting the pages it
     * already made, rather than only stopping `/content` telling it their
     * revisions.
     */
    public function test_a_piece_in_a_type_this_key_does_not_reach_is_not_rewritten(): void {
        // The piece is a PAGE and the key does not name `page`, so the two
        // type names are different strings and the pair of assertions below
        // can each fail: the sentence has to carry one and not the other.
        //
        // AND THE SCOPE IS NOT SPELLED THE WAY THE PROSE IS. `post` on its own
        // is a word this refusal uses for the row it is about -- "is on a post
        // of a type" -- so a check for it is satisfied by the sentence with
        // the scope removed from it entirely, which is exactly the mutation
        // this test has to fail on. The scope asserted below is therefore the
        // JOINED list, separator and all: a form only `implode` produces, and
        // one carrying a second type name the sentence has no other use for.
        $published = $this->publish(['post_type' => 'page'], 'key-a');
        $r = $this->replace($this->body($published), ['post', 'cadence_brief'], 'key-a');

        $this->assertFalse($r['ok'], 'a key rewrote a piece in a type it does not reach');
        $this->assertSame('existing_post_type_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$updated);
        $this->assertSame([], $this->statements(), 'the row was held for a refusal decided off it');
        // The key's own scope is the caller's to know; the type the post is
        // actually in is the site's, and is not in the sentence.
        $this->assertStringContainsString('post, cadence_brief', $r['reason'],
            'the refusal does not state the scope this key actually has');
        $this->assertStringNotContainsString('page', $r['reason']);
    }

    /** THE ACCEPT-PROOFS EITHER SIDE OF IT: the named type, and no type named. */
    public function test_a_piece_in_a_type_the_key_names_is_rewritten(): void {
        $published = $this->publish([], 'key-a');
        $r = $this->replace($this->body($published), ['post'], 'key-a');
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame('The rewrite', WpStub::$posts[$published['post_id']]['post_title']);
    }

    public function test_a_key_that_names_no_type_rewrites_in_any_type(): void {
        $published = $this->publish([], 'key-a');
        $r = $this->replace($this->body($published), null, 'key-a');
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
    }

    /**
     * AND THE TYPE SCOPE IS ASKED LAST, WHICH IS THE WHOLE OF WHAT MAKES IT
     * SAFE -- pinned here rather than argued in a comment beside the line.
     *
     * The check answers "is this post's type one this key publishes into",
     * and that is a fact about the POST. Asked before `identifier_mismatch`
     * it answers that question for any post id a caller cares to name --
     * including every post on the site this connector never touched -- and
     * the refusal code alone then reports the type: one id answers
     * `existing_post_type_out_of_scope` and the next answers
     * `identifier_mismatch`, which is a type oracle over the whole site
     * needing no knowledge of any identifier at all. Asked last, it can only
     * ever fire over a post this key reaches AND that is the piece named.
     *
     * So: two posts this connector never published, alike in everything but
     * their type, against a key that names one of those types and a piece_id
     * the caller guessed. Both must come back with the SAME refusal, and the
     * same sentence bar the id the caller itself sent. Move the check above
     * `identifier_mismatch` and post 7 separates from post 8.
     */
    public function test_the_type_scope_cannot_be_asked_about_a_post_that_is_not_the_piece(): void {
        // NEITHER POST IS THIS CONNECTOR'S: no identifier meta and no key
        // stamp, which is every post that was on the site before the pipeline
        // ever ran. They differ in type and in nothing else.
        foreach ([7 => 'page', 8 => 'post'] as $id => $type) {
            WpStub::$posts[$id] = ['post_type' => $type, 'post_status' => 'publish',
                                   'post_title' => 'Somebody else', 'post_content' => '<p>Theirs.</p>'];
        }
        $this->assertSame([], WpStub::$meta[7] ?? [], 'post 7 carries meta, so it proves nothing');
        $this->assertSame([], WpStub::$meta[8] ?? [], 'post 8 carries meta, so it proves nothing');

        $answers = [];
        foreach ([7, 8] as $id) {
            $answers[$id] = $this->replace([
                'piece_id' => 'a-guess', 'post_id' => $id,
                'revision' => 'whatever-the-caller-believes',
                'title' => 'Taken', 'content' => '<p>Taken.</p>',
            ], ['post'], 'key-a');
            $this->assertFalse($answers[$id]['ok'], 'a guessed identifier rewrote post ' . $id);
        }
        $this->assertSame([], WpStub::$updated);
        $this->assertSame([], $this->statements(), 'the row was held for a refusal decided off it');

        // ONE CODE OVER BOTH, and it is the one that says nothing about type.
        $this->assertSame('identifier_mismatch', $answers[7]['code'],
            'the page answered about its type to a caller that named no piece of this key');
        $this->assertSame('identifier_mismatch', $answers[8]['code']);

        // AND ONE SENTENCE OVER BOTH. The post id is the caller's own, so it
        // is put back to a placeholder before the two are compared; anything
        // else that differs is the site telling the caller the posts differ.
        $this->assertSame(
            str_replace('post 7', 'post N', $answers[7]['reason']),
            str_replace('post 8', 'post N', $answers[8]['reason']),
            'the two posts get different sentences, which separates them by type');
        // Neither sentence names a type -- not the scope, which would be the
        // caller's own, and not the post's, which is the site's.
        $this->assertStringNotContainsString('page', $answers[7]['reason']);
        $this->assertStringNotContainsString('publish into', $answers[7]['reason']);
    }

}
