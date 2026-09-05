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

    /** A `current_user_can` that says yes; what it gates is ContentRequestTest's. */
    private function anyone(): callable {
        return static fn (string $cap, $id = null): bool => true;
    }

    /** Publish a piece the way the pipeline does, and hand back what it was told. */
    private function publish(array $over = []): array {
        $r = CadenceContentRequest::run(array_merge([
            'external_id' => 'piece-1',
            'post_type'   => 'post',
            'status'      => 'publish',
            'title'       => 'The original',
            'content'     => '<p>Original body.</p>',
        ], $over), $this->anyone());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        return $r;
    }

    /** The statements `$wpdb` was given, in order. */
    private function statements(): array {
        return $GLOBALS['wpdb']->log;
    }

    /** A replacement for that piece, naming the revision publishing answered with. */
    private function body(array $published, array $over = []): array {
        return array_merge([
            'external_id' => 'piece-1',
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
        $r = CadenceReplaceRequest::run($this->body($published));

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

        $r = CadenceReplaceRequest::run($this->body($published));

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
        $r = CadenceReplaceRequest::run($this->body($published));
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
        $first = CadenceReplaceRequest::run($this->body($published));
        $this->assertTrue($first['ok'], $first['reason'] ?? '');

        $replay = CadenceReplaceRequest::run($this->body($published));
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
        $r = CadenceReplaceRequest::run($this->body($published, ['post_id' => 4242]));

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
        $two = $this->publish(['external_id' => 'piece-2']);
        $this->assertSame($one['revision'], $two['revision'], 'the two pieces must be indistinguishable by revision');

        $r = CadenceReplaceRequest::run($this->body($one, ['post_id' => $two['post_id']]));

        $this->assertFalse($r['ok'], 'a replacement was written over a different piece');
        $this->assertSame('identifier_mismatch', $r['code']);
        $this->assertSame([], WpStub::$updated);
        $this->assertStringContainsString('piece-1', $r['reason'],
            'the refusal does not say which piece was asked for');
        // AND NOT THE ONE THE SITE HOLDS. The stored identifier is protected
        // meta, which the REST API does not expose; naming it in a refusal
        // hands it to any caller holding `edit_post`, who can then send a
        // replacement that passes this check for a piece it never had.
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
        $r = CadenceReplaceRequest::run([
            'external_id' => 'piece-1',
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
        $r = CadenceReplaceRequest::run($this->body($published));

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertArrayNotHasKey('post_status', WpStub::$updated[0],
            'a replacement decided the post status');
        $this->assertSame('draft', WpStub::$posts[$published['post_id']]['post_status']);
    }

    /** And the piece keeps the identifier it is known by. */
    public function test_the_piece_keeps_its_identifier(): void {
        $published = $this->publish();
        CadenceReplaceRequest::run($this->body($published));
        $this->assertSame('piece-1',
            WpStub::$meta[$published['post_id']][CadenceContentRequest::META] ?? null);
    }

    public function test_refuses_a_body_whose_shape_it_cannot_read(): void {
        $published = $this->publish();
        foreach ([
            'no external_id'        => ['external_id' => null],
            'external_id is an int' => ['external_id' => 7],
            'external_id is blank'  => ['external_id' => '   '],
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
            $r = CadenceReplaceRequest::run($this->body($published, $over));
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
        $r = CadenceReplaceRequest::run($this->body($published));

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
        $r = CadenceReplaceRequest::run($this->body($published));

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
            'identifier_mismatch' => fn (array $p): array => $this->body($p, ['external_id' => 'piece-9']),
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

        $seen = [];
        foreach ($causes as $expected => $arrange) {
            WpStub::reset();
            $r = CadenceReplaceRequest::run($arrange($this->publish()));
            $this->assertFalse($r['ok'], $expected . ' was supposed to be refused');
            $this->assertSame([], WpStub::$updated, $expected);
            $this->assertSame($expected, $r['code'] ?? null, $expected);
            $seen[] = $r['code'];
        }
        $this->assertCount(6, array_unique($seen));

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

        $r = CadenceReplaceRequest::run($this->body($published));

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

        $r = CadenceReplaceRequest::run($this->body($published));

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

        $r = CadenceReplaceRequest::run($this->body($published));

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
        $r = CadenceReplaceRequest::run($this->body($published));
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

        $r = CadenceReplaceRequest::run($this->body($published));

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
            'identifier_mismatch' => ['external_id' => 'piece-9'],
        ] as $code => $over) {
            $GLOBALS['wpdb']->log = [];
            $r = CadenceReplaceRequest::run($this->body($published, $over));
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

        $r = CadenceReplaceRequest::run($this->body($published));

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

        $r = CadenceReplaceRequest::run($this->body($published));

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
            CadenceReplaceRequest::run($this->body($published));
            $this->fail('the exception was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('a save_post hook exploded', $e->getMessage());
        }
        $log = $this->statements();
        $this->assertSame('ROLLBACK', end($log), 'the lock survived the exception');
        $this->assertNotContains('COMMIT', $log);
    }

}
