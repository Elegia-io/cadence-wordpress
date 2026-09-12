<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The refusals, and their twins.
 *
 * EVERY REFUSAL ASSERTS THAT NOTHING WAS WRITTEN, not merely that an error came
 * back. WPML destroys existing translation relations when handed a trid that is
 * not the posts' own, so "refused, but wrote anyway" is the failure this file
 * exists to catch, and a test that checked only the return value could not see
 * it.
 */
final class LinkRequestTest extends TestCase {

    protected function setUp(): void {
        WpStub::reset();
    }

    private function plan(array $over = []): array {
        return array_merge([
            'trid' => 5,
            'create_group' => false,
            'source' => ['post_id' => 1, 'language_code' => 'en',
                         'element_type' => 'post_page', 'source_language_code' => null],
            'translations' => [
                ['post_id' => 2, 'language_code' => 'de',
                 'element_type' => 'post_page', 'source_language_code' => 'en'],
            ],
        ], $over);
    }

    private function twoPosts(?int $trid = 5): void {
        WpStub::add_post(1, 'page', 'en', $trid);
        WpStub::add_post(2, 'page', 'de', $trid);
        $this->ours(1, 2);
    }

    /**
     * The named posts are ones THIS CONNECTOR PUBLISHED, which is what the
     * scope `translation.link` carries reaches. Called explicitly and never
     * folded into `add_post`: a plan over posts nobody marked is refused
     * before its group logic runs, and that refusal is a test of its own.
     */
    private function ours(int ...$ids): void {
        foreach ($ids as $id) {
            WpStub::cadence_published($id);
        }
    }

    public function test_a_well_formed_plan_over_agreeing_posts_writes_both(): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    public function test_a_plan_naming_a_post_that_does_not_exist_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 5);   // post 2 absent
        $r = CadenceLinkRequest::run($this->plan());
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * THE CHECK THAT MAKES THIS PLUGIN MORE THAN A RELAY. The caller computed
     * the plan on another machine, from a read that has since gone stale or was
     * never taken. This code is the one running where the truth is.
     */
    public function test_a_plan_disagreeing_with_the_sites_own_group_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'page', 'de', 9);   // the site says 9, the plan says 5
        $this->ours(1, 2);
        $r = CadenceLinkRequest::run($this->plan());
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('9', $r['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_creating_a_group_requires_every_post_to_be_in_none(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', 7);   // already grouped
        $this->ours(1, 2);
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_creating_a_group_when_both_are_ungrouped_is_allowed(): void {
        $this->twoPosts(null);
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * `create_group` and a trid are contradictory instructions, and the
     * dangerous reading is the eager one: WPML drops relations when told to
     * create a group. A caller that sends both has a bug, and this refuses
     * rather than picking.
     */
    public function test_create_group_together_with_a_trid_writes_nothing(): void {
        $this->twoPosts(null);
        $r = CadenceLinkRequest::run($this->plan(['trid' => 5, 'create_group' => true]));
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_a_plan_with_no_translations_writes_nothing(): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan(['translations' => []]));
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_two_posts_claiming_one_language_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'page', 'de', 5);
        WpStub::add_post(3, 'page', 'de', 5);
        $r = CadenceLinkRequest::run($this->plan(['translations' => [
            ['post_id' => 2, 'language_code' => 'de', 'element_type' => 'post_page',
             'source_language_code' => 'en'],
            ['post_id' => 3, 'language_code' => 'de', 'element_type' => 'post_page',
             'source_language_code' => 'en'],
        ]]));
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_the_source_appearing_among_its_own_translations_writes_nothing(): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan(['translations' => [
            ['post_id' => 1, 'language_code' => 'de', 'element_type' => 'post_page',
             'source_language_code' => 'en'],
        ]]));
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * The caller's own module refuses these, and that is exactly why they are
     * here: its refusals run on another machine, and this one must not inherit
     * a guarantee it cannot check.
     */
    #[DataProvider('badScalars')]
    public function test_a_malformed_field_writes_nothing(array $over): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan($over));
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    public static function badScalars(): array {
        $src = ['post_id' => 1, 'language_code' => 'en',
                'element_type' => 'post_page', 'source_language_code' => null];
        return [
            'post id true'      => [['source' => ['post_id' => true] + $src]],
            'post id string'    => [['source' => ['post_id' => '1'] + $src]],
            'post id zero'      => [['source' => ['post_id' => 0] + $src]],
            'post id negative'  => [['source' => ['post_id' => -1] + $src]],
            'language upper'    => [['source' => ['language_code' => 'EN'] + $src]],
            'language padded'   => [['source' => ['language_code' => ' en'] + $src]],
            'language empty'    => [['source' => ['language_code' => ''] + $src]],
            'element unprefixed'=> [['source' => ['element_type' => 'page'] + $src]],
            'trid string'       => [['trid' => '5']],
            'trid zero'         => [['trid' => 0]],
            'trid negative'     => [['trid' => -5]],
            'trid true'         => [['trid' => true]],
            'create_group int'  => [['create_group' => 1]],
        ];
    }

    public function test_a_post_of_the_wrong_type_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'post', 'de', 5);   // plan says post_page
        // `ours()` IS LOAD-BEARING HERE, and it was missing. The type check now
        // runs after the scope loop, so without it this plan is refused
        // `post_out_of_scope` and the assertions below pass over a refusal that
        // has nothing to do with the type -- the test would go on passing while
        // the thing it names stopped being checked.
        $this->ours(1, 2);
        $r = CadenceLinkRequest::run($this->plan());
        $this->assertFalse($r['ok']);
        $this->assertSame('bad_plan', $r['code']);
        $this->assertStringContainsString('whose type is', $r['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * AN OUT-OF-SCOPE POST IS NOT TOLD WHETHER IT EXISTS, OR WHAT IT IS.
     *
     * The two site reads that answer those questions used to sit in
     * `validate_shape`, which runs BEFORE the scope loop. So a `translation.link`
     * key could walk post ids and read the answers off the refusal: `names post
     * 7, which does not exist on this site` against `names post 7, whose type is
     * `page` and not `post_zzz`` distinguishes a missing id from a page from a
     * post, for every id on a client's WordPress. That is an enumeration oracle
     * behind a credential whose whole point is that it reaches only the posts
     * this connector published.
     *
     * All three cases now answer the SAME refusal, and the reason names the id
     * the caller already sent and nothing else.
     */
    public function test_an_out_of_scope_post_learns_nothing_about_the_site(): void {
        WpStub::add_post(1, 'page', 'en', null);     // a human's page, real
        WpStub::add_post(2, 'post', 'de', null);     // a human's post, real
        // Post 9 is not on the site at all; the plan below names 1 and 2, so
        // this asserts the two SHAPES that used to differ: wrong type, and
        // absent. Neither is ours.
        $r = CadenceLinkRequest::run($this->plan());
        $this->assertFalse($r['ok']);
        $this->assertSame('post_out_of_scope', $r['code']);
        foreach (['does not exist', 'whose type is', 'page', 'post_post', 'post_page'] as $leak) {
            $this->assertStringNotContainsString($leak, $r['reason'], $leak);
        }
        $this->assertSame([], WpStub::$writes);

        // AND A POST THAT IS NOT THERE AT ALL IS THE SAME ANSWER. `get_post_meta`
        // on an id the site does not have answers `''`, so absence and
        // not-ours are one refusal rather than two distinguishable ones.
        WpStub::$writes = [];
        $plan = $this->plan();
        $plan['source']['post_id'] = 9;
        $r = CadenceLinkRequest::run($plan);
        $this->assertFalse($r['ok']);
        $this->assertSame('post_out_of_scope', $r['code']);
        $this->assertStringNotContainsString('does not exist', $r['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * The site says the post is in NO group while the plan says it is in
     * group 5. That is the same disagreement as the test above, from the other
     * side, and it is the one a stale read produces: the caller looked, the
     * link was removed in wp-admin, and the plan is now a claim about a past
     * that no longer exists.
     */
    public function test_a_plan_claiming_a_group_the_site_does_not_have_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'page', 'de', null);   // WPML: in no group
        $this->ours(1, 2);
        $r = CadenceLinkRequest::run($this->plan());
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * WORDPRESS KNOWS THE POST AND WPML RETURNS NOTHING FOR IT. That is not
     * "in no group" -- it is "no answer" -- and treating the two the same is
     * the destructive path, because a create-a-group write then detaches
     * whatever the post was actually attached to.
     *
     * Found by mutation, not by design: collapsing `false` into `null` passed
     * every other test here, because the shape was unreachable through the
     * stubs. Real posts reach it — one predating WPML's configuration, or of a
     * type WPML is not set to translate.
     */
    public function test_a_post_wpml_has_no_answer_for_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null, false);   // WP yes, WPML no
        $this->ours(1, 2);
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('unknown', $r['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_the_same_shape_with_wpml_answering_creates_the_group(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);          // the only difference
        $this->ours(1, 2);
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * THE TWIN FOR EVERY REFUSAL ABOVE. Without it, a `run()` that returned
     * `['ok' => false]` unconditionally would pass all of them -- and this
     * repo has an incident for exactly that shape.
     */
    public function test_the_happy_path_is_reachable_at_all(): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
        $this->assertSame(5, WpStub::$writes[0]['trid']);
        $this->assertSame('en', WpStub::$writes[0]['language_code']);
        $this->assertNull(WpStub::$writes[0]['source_language_code']);
        $this->assertSame('de', WpStub::$writes[1]['language_code']);
        $this->assertSame('en', WpStub::$writes[1]['source_language_code']);
    }

    /**
     * EVERY REFUSAL CARRIES A STABLE CODE, and the seven causes carry seven
     * different ones.
     *
     * The reason is prose for a human reading a log. The caller is a program
     * deciding whether to re-read the site and retry or to stop and fix its own
     * plan, and a program deciding that from prose is matching on spellings
     * this file is free to change. So the decision travels as a code.
     */
    public function test_each_refusal_carries_its_own_code(): void {
        $causes = [
            'bad_plan' => function () {
                $this->twoPosts(5);
                return $this->plan(['trid' => '5']);          // a string trid
            },
            'contradictory_instructions' => function () {
                $this->twoPosts(null);
                return $this->plan(['trid' => 5, 'create_group' => true]);
            },
            'no_group_named' => function () {
                $this->twoPosts(5);
                return $this->plan(['trid' => null, 'create_group' => false]);
            },
            'group_unknown' => function () {
                WpStub::add_post(1, 'page', 'en', null);
                WpStub::add_post(2, 'page', 'de', null, false);
                $this->ours(1, 2);
                return $this->plan(['trid' => null, 'create_group' => true]);
            },
            'already_grouped' => function () {
                $this->twoPosts(9);
                return $this->plan(['trid' => null, 'create_group' => true]);
            },
            'group_disagreement' => function () {
                $this->twoPosts(9);
                return $this->plan(['trid' => 5, 'create_group' => false]);
            },
            'source_group_unset' => function () {
                $this->twoPosts(null);
                WpStub::$wpml_write_detaches = [1];
                return $this->plan(['trid' => null, 'create_group' => true]);
            },
            'source_group_unreadable' => function () {
                $this->twoPosts(null);
                WpStub::$wpml_write_unreadable = [1];
                return $this->plan(['trid' => null, 'create_group' => true]);
            },
            'wpml_unavailable' => function () {
                $this->twoPosts(null);
                WpStub::$wpml_reads = false;
                WpStub::$wpml_writes = false;
                return $this->plan(['trid' => null, 'create_group' => true]);
            },
            'post_out_of_scope' => function () {
                WpStub::add_post(1, 'page', 'en', null);
                WpStub::add_post(2, 'page', 'de', null);
                // Post 1 only. A plan whose posts are ALL outside the scope
                // would be refused by a check that looked at the source alone,
                // and this route writes every post it names.
                $this->ours(1);
                return $this->plan(['trid' => null, 'create_group' => true]);
            },
            'post_other_key' => function () {
                WpStub::add_post(1, 'page', 'en', null);
                WpStub::add_post(2, 'page', 'de', null);
                // BOTH are Cadence's, so `post_out_of_scope` does not fire and
                // this code is reachable only through its own predicate. Post 2
                // carries another key's stamp; the asking key is named below.
                $this->ours(1, 2);
                WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'bbbb2222';
                return $this->plan(['trid' => null, 'create_group' => true]);
            },
        ];

        // WHICH KEY IS ASKING, for the one cause that needs a caller with an
        // identity. Every other cause is refused whoever asks.
        $asking = ['post_other_key' => 'aaaa1111'];

        // EVERY REFUSAL WRITES NOTHING, EXCEPT THE TWO THAT CANNOT. The create
        // path has no group id until its own first write, so its two refusals
        // are only reachable with the source already written. Naming them here
        // is what makes a THIRD refusal that writes a failure of this test
        // rather than a number somebody adjusted.
        $wrote_the_source = ['source_group_unset' => 1, 'source_group_unreadable' => 1];

        $seen = [];
        foreach ($causes as $expected => $arrange) {
            WpStub::reset();
            $r = CadenceLinkRequest::run($arrange(), $asking[$expected] ?? null);
            $this->assertFalse($r['ok'], $expected . ' was supposed to be refused');
            $this->assertCount($wrote_the_source[$expected] ?? 0, WpStub::$writes, $expected);
            $this->assertSame($expected, $r['code'] ?? null, $expected);
            $seen[] = $r['code'];
        }
        // Eleven causes, eleven codes: a mapping that collapsed two of them
        // would still pass every assertion above if both expectations were
        // changed together, and the caller could no longer tell them apart. The
        // count is the union of branches that each added to it -- eight after
        // the scope narrowing, ten after the create path's two, eleven once the
        // scope split into "not this connector's" and "not this key's" -- so it
        // is asserted against `REFUSAL_CODES` rather than retyped from any of
        // them.
        $this->assertCount(11, array_unique($seen));
        $this->assertSame(11, count(CadenceLinkRequest::REFUSAL_CODES));

        // AND THE PUBLISHED LIST IS THAT LIST. `REFUSAL_CODES` is what the REST
        // layer maps to HTTP statuses; if a further refusal is added here and
        // not added there, the mapping silently stops covering it. Binding the
        // constant to the codes actually observed is what makes the coverage
        // test over in RestRouteTest able to fail.
        sort($seen);
        $published = CadenceLinkRequest::REFUSAL_CODES;
        sort($published);
        $this->assertSame($seen, $published);
    }

    /**
     * NO WPML IS A REFUSAL, NOT A SUCCESS.
     *
     * Measured against a real WordPress with WPML not installed: this returned
     * `200 {"ok": true, "written": 2}`. Nothing had been written. WordPress
     * does not object to a filter nobody implements -- it returns the default
     * it was handed -- and it does not object to an action nobody listens to.
     * So the code read its own default as WPML's answer, and reported a count
     * of writes that went nowhere.
     *
     * A refusal here costs a human an install. A false success tells the
     * caller the site is linked, and the caller stops.
     */
    public function test_a_site_without_wpml_refuses_rather_than_reporting_a_write(): void {
        $this->twoPosts(null);
        WpStub::$wpml_reads = false;
        WpStub::$wpml_writes = false;
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));
        $this->assertFalse($r['ok']);
        $this->assertSame('wpml_unavailable', $r['code']);
        $this->assertSame([], WpStub::$writes);
        $this->assertArrayNotHasKey('written', $r);
    }

    /**
     * AND A READER WITHOUT A WRITER IS THE SAME REFUSAL. This is the asymmetric
     * half: every precondition can be read and agreed with, and the writes
     * still go nowhere -- which is the only configuration where the count
     * returned is both non-zero and entirely fictional.
     */
    public function test_wpml_that_answers_reads_but_performs_no_writes_is_refused(): void {
        $this->twoPosts(null);
        WpStub::$wpml_writes = false;
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));
        $this->assertFalse($r['ok']);
        $this->assertSame('wpml_unavailable', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * THE MIRROR OF THE TEST ABOVE, and the reason the guard names both hooks.
     * A site that can write links but cannot be asked about them is refused for
     * being unable, not reported as `group_unknown` -- which would tell the
     * caller its posts are in an unreadable state when the truth is that
     * nothing here reads.
     */
    public function test_wpml_that_writes_but_answers_no_reads_is_refused_as_unavailable(): void {
        $this->twoPosts(null);
        WpStub::$wpml_reads = false;
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));
        $this->assertFalse($r['ok']);
        $this->assertSame('wpml_unavailable', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * A REGISTERED FILTER THAT DECLINES TO ANSWER IS STILL NOT AN ANSWER.
     *
     * `has_filter` says yes -- WPML is installed and hooked site-wide -- and
     * for this element it hands the value straight back, because nobody enabled
     * translation for its post type. What comes back is the default this code
     * supplied, so the default IS what the code believes about silence. It is
     * `false` (nothing usable) and never `null` (known, and in no group),
     * because the second reading is the one that goes on to write.
     */
    public function test_a_filter_that_declines_to_answer_writes_nothing(): void {
        $this->twoPosts(null);
        WpStub::$wpml_declines = true;
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));
        $this->assertFalse($r['ok']);
        $this->assertSame('group_unknown', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_a_written_plan_carries_no_code(): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertArrayNotHasKey('code', $r);
    }

    // ---- the report -------------------------------------------------------
    //
    // `written` says how many links were made and nothing said WHICH, so the
    // caller's ledger recorded that nothing was linked on every run that
    // linked something. These pin the field that answers it, and -- more --
    // pin that it is READ BACK rather than copied out of the plan.

    public function test_a_plan_naming_its_piece_reports_what_the_site_now_says(): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan(['piece_id' => 'piece-1']));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame(2, $r['written']);
        // The WHOLE report, by identity: a field appearing here that nothing
        // named is as much a change to the contract as one going missing, and
        // this is also where "ids, counts and language codes only" is enforced
        // -- a title or a slug could only arrive as a new key.
        $this->assertSame([
            'piece_id' => 'piece-1',
            'post_id'  => 1,
            'placed'   => [],
            'linked'   => ['de'],
            'refused'  => [],
        ], $r['report']);
    }

    /**
     * THE REPORT IS NOT THE REQUEST WITH AN `ok` BESIDE IT. Post 2's write is
     * taken and leaves it in no group -- which is WPML's documented behaviour
     * when it drops relations, and the action returns nothing either way. The
     * plan still names `de`; the site no longer has it. A `linked` projected
     * from the plan reports `de`, beside a `written: 3` that agrees with it.
     * One read back reports `fr` alone.
     */
    public function test_a_write_that_did_not_land_is_not_reported_as_linked(): void {
        $this->twoPosts(5);
        WpStub::add_post(3, 'page', 'fr', 5);
        $this->ours(3);
        WpStub::$wpml_write_detaches = [2];
        $r = CadenceLinkRequest::run($this->plan(['piece_id' => 'piece-1', 'translations' => [
            ['post_id' => 2, 'language_code' => 'de',
             'element_type' => 'post_page', 'source_language_code' => 'en'],
            ['post_id' => 3, 'language_code' => 'fr',
             'element_type' => 'post_page', 'source_language_code' => 'en'],
        ]]));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame(3, $r['written']);
        $this->assertSame(['fr'], $r['report']['linked']);
    }

    /**
     * A read-back that says "no group" links nothing. `null` is a reading and
     * `false` is not, and neither of them is a group: a report that treated
     * either as one would claim the write landed because the call was made.
     */
    public function test_a_source_the_site_puts_in_no_group_links_nothing(): void {
        $this->twoPosts(5);
        WpStub::$wpml_write_detaches = [1];
        $r = CadenceLinkRequest::run($this->plan(['piece_id' => 'piece-1']));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame([], $r['report']['linked']);
    }

    // ---- the create path --------------------------------------------------
    //
    // This route used to write a null trid for EVERY element, and WPML's own
    // documentation says a falsy trid creates a new trid for THAT element: the
    // site ended with one group per post, nothing was linked, and `written: 2`
    // said the same thing it says now. These pin the ordering that fixes it and
    // the two ways it can stop half-way. Every one of them runs against the
    // stub, which models WPML's DOCUMENTATION -- including the undocumented
    // part, that a read in the same request sees the trid the write invented.
    // None of it is an observation of WPML 4.x.

    /**
     * THE GROUP IS LEARNED FROM THE FIRST WRITE, NOT NAMED BY THE PLAN. The
     * source goes first with no trid, because nothing else can bring the group
     * into existence; then the id WPML chose is read back out of the site and
     * every translation is written under THAT. The assertion that matters is
     * the last one: both posts end up in one group, which is the thing a group
     * of one per post looks identical to in `written`.
     */
    public function test_the_create_path_learns_its_group_from_its_own_first_write(): void {
        $this->twoPosts(null);
        $r = CadenceLinkRequest::run($this->plan(
            ['trid' => null, 'create_group' => true, 'piece_id' => 'piece-1']));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame(2, $r['written']);
        $this->assertCount(2, WpStub::$writes);

        // The source first, with no trid -- there was no id to send.
        $this->assertSame(1, WpStub::$writes[0]['element_id']);
        $this->assertNull(WpStub::$writes[0]['trid']);

        // The translation second, under the id the site now holds for the
        // source. Compared against the site rather than against 900: the
        // literal is the stub's counter, and what this pins is that the two
        // values are the same one.
        $group = WpStub::$posts[1]['trid'];
        $this->assertNotNull($group);
        $this->assertSame(2, WpStub::$writes[1]['element_id']);
        $this->assertSame($group, WpStub::$writes[1]['trid']);

        $this->assertSame($group, WpStub::$posts[2]['trid']);
        $this->assertSame(['de'], $r['report']['linked']);
    }

    /**
     * THE WRITE LANDED AND CREATED NO GROUP, so there is nothing for the
     * translations to join and they are not written. The refusal carries the
     * count of what it already did, and the report says `linked: []` -- a bare
     * `ok: false` would tell the caller's ledger that a run which changed the
     * site changed nothing.
     *
     * Nothing was destroyed: the create path refuses unless every post is in no
     * group, so the source had no relations to lose and post 2 is untouched.
     */
    public function test_a_source_left_in_no_group_by_its_own_write_stops_before_the_translations(): void {
        $this->twoPosts(null);
        WpStub::$wpml_write_detaches = [1];
        $r = CadenceLinkRequest::run($this->plan(
            ['trid' => null, 'create_group' => true, 'piece_id' => 'piece-1']));
        $this->assertFalse($r['ok']);
        $this->assertSame('source_group_unset', $r['code']);
        $this->assertSame(1, $r['written']);
        $this->assertCount(1, WpStub::$writes);
        $this->assertSame(1, WpStub::$writes[0]['element_id']);
        $this->assertNull(WpStub::$posts[2]['trid']);
        $this->assertSame([], $r['report']['linked']);
        // ONE REFUSAL, ONE CLAIM: this reason says the site answered and the
        // answer was "no group". It must not also allege an unreadable read,
        // which is the other branch and did not fire.
        $this->assertStringContainsString('puts it in no group', $r['reason']);
        $this->assertStringNotContainsString('no usable', $r['reason']);
    }

    /**
     * THE SOURCE'S GROUP CANNOT BE READ BACK AT ALL, which is not the same as
     * "no group" and is the one state that must never be written over. Same
     * half-applied shape, different claim.
     */
    public function test_a_source_whose_group_cannot_be_read_back_stops_before_the_translations(): void {
        $this->twoPosts(null);
        WpStub::$wpml_write_unreadable = [1];
        $r = CadenceLinkRequest::run($this->plan(
            ['trid' => null, 'create_group' => true, 'piece_id' => 'piece-1']));
        $this->assertFalse($r['ok']);
        $this->assertSame('source_group_unreadable', $r['code']);
        $this->assertSame(1, $r['written']);
        $this->assertCount(1, WpStub::$writes);
        $this->assertNull(WpStub::$posts[2]['trid']);
        $this->assertSame([], $r['report']['linked']);
        $this->assertStringContainsString('no usable language details', $r['reason']);
        $this->assertStringNotContainsString('puts it in no group', $r['reason']);
    }

    /**
     * A half-applied create with no piece named carries the count and no
     * report, for the reason the success path does: there is nothing for a
     * ledger to file a report under.
     */
    public function test_a_half_applied_create_naming_no_piece_still_carries_its_count(): void {
        $this->twoPosts(null);
        WpStub::$wpml_write_detaches = [1];
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));
        $this->assertSame(['ok' => false, 'code' => 'source_group_unset',
                           'reason' => $r['reason'], 'written' => 1], $r);
        $this->assertArrayNotHasKey('report', $r);
    }

    /**
     * A CALLER THAT NAMES NO PIECE GETS EXACTLY WHAT IT GOT BEFORE. The report
     * is filed under the piece; with no piece there is nothing for a ledger to
     * join it to, and an answer carrying half a report would be read as one.
     */
    public function test_a_plan_naming_no_piece_carries_no_report(): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertArrayNotHasKey('report', $r);
        $this->assertSame(['ok' => true, 'written' => 2], $r);
    }

    /**
     * A blank identifier is refused rather than reported under, for the reason
     * `/content` refuses it: it is what a ledger row is found by. And refused
     * BEFORE the write, like every other refusal in this file.
     */
    #[DataProvider('unusablePieceIds')]
    public function test_an_unusable_piece_id_writes_nothing($piece_id): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan(['piece_id' => $piece_id]));
        $this->assertFalse($r['ok']);
        $this->assertSame('bad_plan', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    public static function unusablePieceIds(): array {
        return [
            'blank'      => [''],
            'whitespace' => ["  \t "],
            'an int'     => [12],
            // `true` passes a loose string check in more than one language and
            // would be written into the ledger as `1`.
            'true'       => [true],
            'an array'   => [['piece-1']],
        ];
    }

    /**
     * A POST THIS CONNECTOR NEVER PUBLISHED IS NOT A KEY'S TO LINK.
     *
     * THE NARROWING, and the whole point of it. Before this, `translation.link`
     * authorised linking ANY post on the site: the per-post
     * `current_user_can('edit_post', $id)` it replaced had no WordPress user to
     * ask about, so what was left was a body-shape check. A stolen key could
     * therefore write a translation group over the site's front page, a shop
     * product, or a page a human wrote -- and WPML handed a group that is not
     * those posts' own DESTROYS the relations they already had.
     *
     * The refusal is over the TRANSLATION here and the source is one of ours,
     * so a check that asked only about the source would pass this.
     */
    public function test_a_plan_naming_a_post_this_connector_never_published_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1);   // post 2 is somebody else's page

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));

        $this->assertFalse($r['ok'], 'a post this connector never published was linked');
        $this->assertSame('post_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$writes, 'a refused plan wrote anyway');
        // NOT A GROUP DISAGREEMENT AND NOT A MALFORMED PLAN. The body is well
        // formed and the site agrees with it; what is wrong is that this
        // credential does not reach that post, and a caller told `bad_plan`
        // would re-read its own JSON forever.
        $this->assertNotSame('group_disagreement', $r['code']);
    }

    /**
     * THE TWIN: the same plan over posts this connector DID publish is written.
     *
     * Without it the test above passes on a `scope_admits` that returns false
     * unconditionally, which is a route that links nothing at all.
     */
    public function test_the_same_plan_over_this_connectors_own_posts_is_written(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * THE SOURCE IS ASKED TOO, not only the translations.
     *
     * The source is written like every other member -- it is first in the same
     * write loop -- so a scope check that skipped it would let a key attach the
     * site's own page to a group as its source, which is the destructive write
     * with the roles swapped.
     */
    public function test_a_source_this_connector_never_published_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(2);   // the TRANSLATION is ours; the source is not

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));

        $this->assertFalse($r['ok'], 'a source this connector never published was linked');
        $this->assertSame('post_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * META THIS CANNOT READ AS AN IDENTIFIER IS OUT OF SCOPE, in every shape it
     * can arrive in.
     *
     * `get_post_meta(..., true)` answers `''` for meta that is not there, so a
     * stored empty string and an absent row are the same answer and both are
     * refused. `/content` refuses a blank `piece_id`, so a blank stored value
     * cannot have come from this plugin -- and a truthiness check would admit
     * every post carrying `_cadence_external_id` as an empty string, an array,
     * or a zero some other plugin wrote there.
     */
    #[DataProvider('unreadableIdentifiers')]
    public function test_an_identifier_this_cannot_read_is_out_of_scope($stored): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1);
        WpStub::$meta[2][CadenceContentRequest::META] = $stored;

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));

        $this->assertFalse($r['ok']);
        $this->assertSame('post_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$writes);
        $this->assertFalse(CadenceKey::scope_admits(2));
    }

    /**
     * A POST A DIFFERENT CONNECTOR KEY PUBLISHED IS NOT THIS KEY'S TO LINK.
     *
     * The scope used to be "a piece Cadence published", which on a site holding
     * two keys -- two brands on one WordPress, an agency serving two of our
     * tenants -- is the other tenant's pieces as well as this one's. WPML's
     * action hands the group it is given, and a group written over posts that
     * already had one DESTROYS the relations they had.
     *
     * The refusal is over the TRANSLATION and both posts are Cadence's, so a
     * check that fell back to `post_out_of_scope` would be refusing the wrong
     * fact and a check that asked only about the source would pass this.
     */
    public function test_a_plan_naming_a_post_a_different_key_published_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);
        WpStub::$meta[1][CadenceContentRequest::KEY_META] = 'aaaa1111';
        WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'bbbb2222';

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), 'aaaa1111');

        $this->assertFalse($r['ok'], "a second key's post was linked");
        $this->assertSame('post_other_key', $r['code']);
        $this->assertSame([], WpStub::$writes, 'a refused plan wrote anyway');
        // THE REFUSAL NAMES THE BRANCH THAT FIRED. Post 2 IS a piece this
        // connector published; a caller told `post_out_of_scope` would be told
        // something untrue about it, and would republish a piece that is
        // already on the site rather than present the key that owns it.
        $this->assertNotSame('post_out_of_scope', $r['code']);
        $this->assertStringNotContainsString('bbbb2222', $r['reason'],
            "the refusal handed this caller another tenant's key id");
    }

    /**
     * AND THE SOURCE IS ASKED TOO, not only the translations.
     *
     * The source is written like every other member, so a check that skipped it
     * would let a key attach another tenant's piece to a group as its source --
     * the destructive write with the roles swapped.
     */
    public function test_a_source_a_different_key_published_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);
        WpStub::$meta[1][CadenceContentRequest::KEY_META] = 'bbbb2222';
        WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'aaaa1111';

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), 'aaaa1111');

        $this->assertFalse($r['ok']);
        $this->assertSame('post_other_key', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * THE TWIN: the same plan over THIS key's own posts is written.
     *
     * Without it the two tests above pass on a `created_by` that returns false
     * unconditionally, which is a route that links nothing at all.
     */
    public function test_the_same_plan_over_this_keys_own_posts_is_written(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);
        WpStub::$meta[1][CadenceContentRequest::KEY_META] = 'aaaa1111';
        WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'aaaa1111';

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), 'aaaa1111');

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * AND A PLAN OVER POSTS THAT PREDATE THE STAMP IS STILL WRITTEN.
     *
     * THE COMPATIBILITY PATH, asserted at the route rather than left to the
     * predicate's docblock. Every piece Cadence has already published on every
     * client's site carries no key stamp; refusing those would break every
     * link over content that is already live, which is worse than the widening
     * this closes. The posts here are exactly what the 0.3.0 connector left
     * behind: an identifier and no identity.
     */
    public function test_a_plan_over_posts_that_predate_the_key_stamp_is_still_written(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);   // the identifier only -- no stamp, as 0.3.0 wrote them

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), 'aaaa1111');

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    public static function unreadableIdentifiers(): array {
        return [
            'blank'      => [''],
            'whitespace' => ["  \t "],
            'an array'   => [['piece-2']],
            'an int'     => [0],
            'true'       => [true],
            'null'       => [null],
        ];
    }

    /**
     * THE REFUSAL SAYS WHICH POST AND NOTHING ELSE ABOUT IT.
     *
     * It names the id, which the caller sent, and the claim. It does not name
     * the identifier the site stores for its own posts -- protected meta the
     * REST API does not expose, and a refusal that spelled it out would hand a
     * caller the value `/content/replace` demands. Nor a title, a slug or a
     * status: the caller is not trusted with the site's copy, which is the line
     * the report one file over draws for the same reason.
     */
    public function test_the_scope_refusal_names_the_post_and_leaks_nothing_about_it(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1);
        WpStub::$meta[2]['_some_other_plugin'] = 'Secret Draft Title';

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));

        $this->assertStringContainsString('2', $r['reason']);
        // The IN-SCOPE post's stored identifier is what a leak would spill,
        // since the refusal has just read the meta table either side of it.
        $this->assertStringNotContainsString('piece-1', $r['reason']);
        $this->assertStringNotContainsString('Secret Draft Title', $r['reason']);
        $this->assertStringNotContainsString('page', $r['reason']);
    }
}
