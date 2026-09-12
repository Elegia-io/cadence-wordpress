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
        $r = CadenceLinkRequest::run($this->plan(), null);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    public function test_a_plan_naming_a_post_that_does_not_exist_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 5);   // post 2 absent
        $r = CadenceLinkRequest::run($this->plan(), null);
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
        $r = CadenceLinkRequest::run($this->plan(), null);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('9', $r['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_creating_a_group_requires_every_post_to_be_in_none(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', 7);   // already grouped
        $this->ours(1, 2);
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_creating_a_group_when_both_are_ungrouped_is_allowed(): void {
        $this->twoPosts(null);
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);
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
        $r = CadenceLinkRequest::run($this->plan(['trid' => 5, 'create_group' => true]), null);
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_a_plan_with_no_translations_writes_nothing(): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan(['translations' => []]), null);
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
        ]]), null);
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_the_source_appearing_among_its_own_translations_writes_nothing(): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan(['translations' => [
            ['post_id' => 1, 'language_code' => 'de', 'element_type' => 'post_page',
             'source_language_code' => 'en'],
        ]]), null);
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
        $r = CadenceLinkRequest::run($this->plan($over), null);
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
        $r = CadenceLinkRequest::run($this->plan(), null);
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
        $r = CadenceLinkRequest::run($this->plan(), null);
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
        $r = CadenceLinkRequest::run($plan, null);
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
        $r = CadenceLinkRequest::run($this->plan(), null);
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
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('unknown', $r['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_the_same_shape_with_wpml_answering_creates_the_group(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);          // the only difference
        $this->ours(1, 2);
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);
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
        $r = CadenceLinkRequest::run($this->plan(), null);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
        $this->assertSame(5, WpStub::$writes[0]['trid']);
        $this->assertSame('en', WpStub::$writes[0]['language_code']);
        $this->assertNull(WpStub::$writes[0]['source_language_code']);
        $this->assertSame('de', WpStub::$writes[1]['language_code']);
        $this->assertSame('en', WpStub::$writes[1]['source_language_code']);
    }

    /**
     * EVERY REFUSAL CARRIES A STABLE CODE, and each cause carries its own.
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
            'link_post_type_out_of_scope' => function () {
                // BOTH posts are this key's own -- neither scope predicate
                // fires -- and both are `page`, while the key below reaches
                // `post` only. So this code is reachable only through its own
                // predicate, and only after the other two have said yes.
                WpStub::add_post(1, 'page', 'en', null);
                WpStub::add_post(2, 'page', 'de', null);
                $this->ours(1, 2);
                return $this->plan(['trid' => null, 'create_group' => true]);
            },
        ];

        // WHICH KEY IS ASKING. No cause here needs a caller with an identity
        // any more: the second scope predicate no longer has a code of its own,
        // and `test_a_plan_naming_a_post_a_different_key_published_writes_nothing`
        // is where it is asked about instead.
        $asking = [];

        // AND WHAT IT IS SCOPED TO. Null is the wide case for every other
        // cause -- a key that names no type -- so only the type refusal names
        // a list, and no other cause can be reached through it.
        $scoped = ['link_post_type_out_of_scope' => ['post']];

        // EVERY REFUSAL WRITES NOTHING, EXCEPT THE TWO THAT CANNOT. The create
        // path has no group id until its own first write, so its two refusals
        // are only reachable with the source already written. Naming them here
        // is what makes a THIRD refusal that writes a failure of this test
        // rather than a number somebody adjusted.
        $wrote_the_source = ['source_group_unset' => 1, 'source_group_unreadable' => 1];

        $seen = [];
        foreach ($causes as $expected => $arrange) {
            WpStub::reset();
            $r = CadenceLinkRequest::run($arrange(), $scoped[$expected] ?? null,
                                          $asking[$expected] ?? null);
            $this->assertFalse($r['ok'], $expected . ' was supposed to be refused');
            $this->assertCount($wrote_the_source[$expected] ?? 0, WpStub::$writes, $expected);
            $this->assertSame($expected, $r['code'] ?? null, $expected);
            $seen[] = $r['code'];
        }
        // Eleven causes, eleven codes: a mapping that collapsed two of them would
        // still pass every assertion above if both expectations were changed
        // together, and the caller could no longer tell them apart. The count is the
        // union of branches that each added to it -- eight after the scope
        // narrowing, ten after the create path's two, eleven once the scope split
        // into "not this connector's" and "not this key's", TEN again when that
        // split was merged back because the PAIR was a provenance oracle, and eleven
        // once the key's post types reached this route. So it is asserted against
        // `REFUSAL_CODES` rather than retyped from any of them.
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
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);
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
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);
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
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);
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
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertFalse($r['ok']);
        $this->assertSame('group_unknown', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_a_written_plan_carries_no_code(): void {
        $this->twoPosts(5);
        $r = CadenceLinkRequest::run($this->plan(), null);
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
        $r = CadenceLinkRequest::run($this->plan(['piece_id' => 'piece-1']), null);
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
        ]]), null);
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
        $r = CadenceLinkRequest::run($this->plan(['piece_id' => 'piece-1']), null);
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
            ['trid' => null, 'create_group' => true, 'piece_id' => 'piece-1']), null);
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
            ['trid' => null, 'create_group' => true, 'piece_id' => 'piece-1']), null);
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
            ['trid' => null, 'create_group' => true, 'piece_id' => 'piece-1']), null);
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
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);
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
        $r = CadenceLinkRequest::run($this->plan(), null);
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
        $r = CadenceLinkRequest::run($this->plan(['piece_id' => $piece_id]), null);
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

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);

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

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);

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

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);

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

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);

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
     * check that asked only about the source would pass this.
     *
     * AND IT IS THE SAME REFUSAL a post this connector never published gets.
     * The pair `post_out_of_scope` / `post_other_key` used to tell those apart,
     * which sorted every id on the site into "another tenant's Cadence post"
     * and "everything else" -- a provenance oracle behind a route that verifies
     * no attestation. `CadenceKey::reaches` asks the conjunction now, so the
     * branch that fired is "this key does not reach post 2" and nothing finer.
     */
    public function test_a_plan_naming_a_post_a_different_key_published_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);
        WpStub::$meta[1][CadenceContentRequest::KEY_META] = 'aaaa1111';
        WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'bbbb2222';

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null, 'aaaa1111');

        $this->assertFalse($r['ok'], "a second key's post was linked");
        $this->assertSame('post_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$writes, 'a refused plan wrote anyway');
        $this->assertStringNotContainsString('bbbb2222', $r['reason'],
            "the refusal handed this caller another tenant's key id");
        // AND NOT A WORD THAT ONLY THIS BRANCH COULD HAVE PRODUCED. Asserted
        // over the refusal's own vocabulary rather than over a substring some
        // other sentence might also contain.
        foreach (['different connector key', 'another key', 'this connector published'] as $tell) {
            $this->assertStringNotContainsString($tell, $r['reason'], $tell);
        }
    }

    /**
     * THE ORACLE, PINNED: the three things a `translation.link` key may not
     * tell apart give back ONE refusal, identical once the id is taken out.
     *
     *   - an id this site has no post for;
     *   - a post this site has that this connector never published;
     *   - a post this connector DID publish, through a different key.
     *
     * The third is what made the pair an oracle. A caller holding a leaked
     * connector key and nothing else could walk integers and sort the site into
     * the other tenant's Cadence pieces and everything else -- and provenance,
     * which of two tenants published a given post, is the fact per-key scope
     * exists to protect. This route verifies no attestation, so nothing costs
     * that caller anything else.
     *
     * WHAT ELSE THIS FAILS UNDER, and why it is the ordering pin as well as the
     * merge pin: move `validate_against_site` or the trid loop above the scope
     * loop and the first case starts answering `bad_plan ... does not exist`
     * while the other two do not. The digits are masked rather than compared
     * away, so a refusal that named a trid, a key id or a second post id would
     * not survive either.
     */
    public function test_the_three_cases_a_link_key_may_not_separate_answer_alike(): void {
        $reasons = [];
        $codes   = [];
        foreach (['absent' => 7, 'not ours' => 8, "another key's" => 9] as $case => $probe) {
            WpStub::reset();
            WpStub::add_post(2, 'page', 'de', null);
            $this->ours(2);
            WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'aaaa1111';
            if ($case !== 'absent') {
                WpStub::add_post($probe, 'page', 'en', null);
            }
            if ($case === "another key's") {
                $this->ours($probe);
                WpStub::$meta[$probe][CadenceContentRequest::KEY_META] = 'bbbb2222';
            }
            $plan = $this->plan(['trid' => null, 'create_group' => true]);
            $plan['source']['post_id'] = $probe;

            $r = CadenceLinkRequest::run($plan, null, 'aaaa1111');

            $this->assertFalse($r['ok'], $case);
            $this->assertSame([], WpStub::$writes, $case);
            $codes[$case]   = $r['code'];
            $reasons[$case] = preg_replace('/\d+/', '<n>', $r['reason']);
        }
        $this->assertSame(['post_out_of_scope'], array_values(array_unique($codes)),
            'the three cases came back under more than one code: ' . implode(' ', $codes));
        $this->assertCount(1, array_unique($reasons),
            'the three cases came back with different sentences: ' . implode(' | ', $reasons));
    }

    /**
     * THE TWIN, and it is what makes the test above an ordering pin rather than
     * a route that never says anything.
     *
     * `does not exist` is still reachable -- for a post this key DOES reach.
     * Without this, deleting the site reads from `validate_against_site`
     * altogether would pass the pin above, and a plan naming an id this site
     * has no post for would be written rather than refused.
     */
    public function test_a_post_this_key_reaches_is_still_told_the_site_has_no_such_post(): void {
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(2);
        // Post 7 is absent, but it is INSIDE the scope: the stamp says so.
        WpStub::$meta[7][CadenceContentRequest::META] = 'piece-7';
        $plan = $this->plan(['trid' => null, 'create_group' => true]);
        $plan['source']['post_id'] = 7;

        $r = CadenceLinkRequest::run($plan, null);

        $this->assertSame('bad_plan', $r['code'], $r['reason'] ?? '');
        $this->assertStringContainsString('does not exist', $r['reason']);
        $this->assertSame([], WpStub::$writes);
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

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null, 'aaaa1111');

        $this->assertFalse($r['ok']);
        $this->assertSame('post_out_of_scope', $r['code']);
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

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null, 'aaaa1111');

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

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null, 'aaaa1111');

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * AND THAT COMPATIBILITY PATH IS A MEASURED GAP, NOT A CLOSED ONE.
     *
     * The merge that removed `post_other_key` closes the oracle over STAMPED
     * posts. Over unstamped ones there is nothing to close: a second key does
     * not merely learn that the post is another tenant's, it LINKS it, exactly
     * as it could before per-key scope existed. This test asserts the leak
     * rather than asserting it away, so the day it is closed this fails and
     * someone reads the denominator below before deciding.
     *
     * THE DENOMINATOR. Of the three predicates the connector scopes a key by --
     * `scope_admits`, `created_by`, and the key's post-type list -- two
     * separate two keys over a stamped post and NONE separates them over an
     * unstamped one on this route: `translation.link` carries no type list.
     * The set is every piece a site published before the stamp existed; it
     * never grows, because `/content` has stamped every insert since, and it
     * shrinks as those pieces are replaced. It is empty on a site that has only
     * ever run a stamping connector.
     *
     * WHY IT IS NOT CLOSED. Refusing unstamped posts would refuse every link
     * over content already live on every client's site -- an upgrade that
     * breaks the working case to narrow a case the release bar does not yet
     * have, which is one site per client holding one key.
     */
    public function test_a_second_keys_reach_over_pre_stamp_posts_is_a_measured_gap(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);   // an identifier and no stamp, as 0.3.0 wrote them

        // A key that published NEITHER of these, and says so.
        $r = CadenceLinkRequest::run(
            $this->plan(['trid' => null, 'create_group' => true]), null, 'bbbb2222');

        $this->assertTrue($r['ok'], 'the pre-stamp gap has been closed; read this docblock');
        $this->assertCount(2, WpStub::$writes);
        // AND THE TWIN, so this cannot be satisfied by a route that admits
        // everything: stamp ONE of the two and the same key is refused.
        WpStub::$writes = [];
        WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'aaaa1111';
        $refused = CadenceLinkRequest::run(
            $this->plan(['trid' => null, 'create_group' => true]), null, 'bbbb2222');
        $this->assertFalse($refused['ok']);
        $this->assertSame('post_out_of_scope', $refused['code']);
        $this->assertSame([], WpStub::$writes);
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

        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]), null);

        $this->assertStringContainsString('2', $r['reason']);
        // The IN-SCOPE post's stored identifier is what a leak would spill,
        // since the refusal has just read the meta table either side of it.
        $this->assertStringNotContainsString('piece-1', $r['reason']);
        $this->assertStringNotContainsString('Secret Draft Title', $r['reason']);
        $this->assertStringNotContainsString('page', $r['reason']);
    }

    /**
     * A KEY WHOSE TYPES NO LONGER NAME `page` CANNOT LINK ONE.
     *
     * The type scope reached `/content` and `/content/replace` first, and an
     * operator narrowing a key would reasonably read that as "this key no
     * longer touches pages". It did not reach here: both posts are this key's
     * own, so neither entitlement predicate fires, and before this the plan
     * below was written.
     *
     * WHAT A WRONG LINK COSTS, which is why a route that writes no text
     * enforces this at all: WPML's action hands the group it is given, and a
     * group written over posts that already had one DESTROYS the relations
     * they had -- including ones a human made by hand in wp-admin.
     */
    public function test_a_page_is_not_linked_by_a_key_scoped_to_posts(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);

        $r = CadenceLinkRequest::run(
            $this->plan(['trid' => null, 'create_group' => true]), ['post'], 'aaaa1111');

        $this->assertFalse($r['ok'], 'a page was linked by a key scoped to posts');
        $this->assertSame('link_post_type_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$writes, 'a refused plan wrote anyway');
        // NOT the code the other two routes refuse a type with. That one ends
        // "nothing was written" and is asked of a piece named by identifier;
        // this is asked of a post named by id and ends "nothing was linked".
        $this->assertNotSame('existing_post_type_out_of_scope', $r['code']);
    }

    /**
     * AND THE SOURCE IS ASKED TOO, not only the translations. A check that
     * looked at one end would let a key attach a type it does not reach as the
     * group's source, which is the same write with the roles swapped.
     */
    public function test_a_source_outside_the_keys_types_is_not_linked(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'post', 'de', null);
        $this->ours(1, 2);

        $r = CadenceLinkRequest::run(
            $this->plan(['trid' => null, 'create_group' => true]), ['post'], 'aaaa1111');

        $this->assertFalse($r['ok']);
        $this->assertSame('link_post_type_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * THE TWIN, AND THE ACCEPT-PROOF: the ordinary link over this key's own
     * posts, of a type the key names, still succeeds.
     *
     * Without it every test above passes on a route that refuses every link,
     * which is a scope that has stopped being a scope.
     */
    public function test_a_link_over_posts_of_a_type_the_key_names_still_succeeds(): void {
        WpStub::add_post(1, 'post', 'en', null);
        WpStub::add_post(2, 'post', 'de', null);
        $this->ours(1, 2);

        // `post_post`, because the element type has to agree with the post's
        // own type and these are posts: the fixture everywhere else in this
        // file is a pair of pages.
        $r = CadenceLinkRequest::run($this->plan([
            'trid' => null, 'create_group' => true,
            'source' => ['post_id' => 1, 'language_code' => 'en',
                         'element_type' => 'post_post', 'source_language_code' => null],
            'translations' => [['post_id' => 2, 'language_code' => 'de',
                                'element_type' => 'post_post', 'source_language_code' => 'en']],
        ]), ['post'], 'aaaa1111');

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * AND A KEY THAT NAMES NO TYPE LINKS WHAT IT ALWAYS LINKED.
     *
     * THE COMPATIBILITY PATH, asserted at the route rather than left to
     * `CadenceKey::publish_types_for`'s docblock. Keys issued before the field
     * existed are live on sites this repository does not control, and their
     * holders can neither see the field nor fill it in; a plugin update that
     * turned their working links into 403s would be an upgrade that breaks the
     * working case. Null names no type and means ANY.
     */
    public function test_a_key_that_names_no_type_links_any_type(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);

        $r = CadenceLinkRequest::run(
            $this->plan(['trid' => null, 'create_group' => true]), null, 'aaaa1111');

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * THE ORDERING IS THE GUARD, AND THIS IS WHAT PINS IT.
     *
     * The type check is the only branch on this route that can answer a
     * question about a post's own type, because unlike `/content/replace` this
     * route holds no identifier to check the post against -- there is no
     * `identifier_mismatch` here to sit in front of it. Asked before the two
     * entitlement checks it would answer "is post N inside this key's types"
     * for any post id a caller cares to name, which is the post's type by
     * another name.
     *
     * So each pair below differs ONLY in the type of post 2, and each pair has
     * to come back with one code. Move the type check above `created_by` and
     * the second pair separates; move it above `scope_admits` and the first
     * does too. Nothing else in this file fails on that move, which is why the
     * replace route shipped the same ordering argued in a comment and caught
     * only by a reviewer.
     */
    public function test_the_type_scope_cannot_be_asked_about_a_post_this_key_does_not_reach(): void {
        // NOT A PIECE THIS CONNECTOR PUBLISHED -- post 2 carries no identifier.
        $outside = [];
        foreach (['page', 'post'] as $type) {
            WpStub::reset();
            WpStub::add_post(1, 'post', 'en', null);
            WpStub::add_post(2, $type, 'de', null);
            $this->ours(1);
            $r = CadenceLinkRequest::run(
                $this->plan(['trid' => null, 'create_group' => true]), ['post'], 'aaaa1111');
            $this->assertFalse($r['ok']);
            $this->assertSame([], WpStub::$writes);
            $outside[$type] = $r['code'];
        }
        $this->assertSame('post_out_of_scope', $outside['page']);
        $this->assertSame($outside['post'], $outside['page'],
            'a post this connector never published answered differently depending on its '
            . 'type, so the pair of codes is a type oracle over every post id on the site');

        // AND A PIECE A DIFFERENT KEY PUBLISHED -- post 2 is Cadence's, and
        // another tenant's.
        $other = [];
        foreach (['page', 'post'] as $type) {
            WpStub::reset();
            WpStub::add_post(1, 'post', 'en', null);
            WpStub::add_post(2, $type, 'de', null);
            $this->ours(1, 2);
            WpStub::$meta[1][CadenceContentRequest::KEY_META] = 'aaaa1111';
            WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'bbbb2222';
            $r = CadenceLinkRequest::run(
                $this->plan(['trid' => null, 'create_group' => true]), ['post'], 'aaaa1111');
            $this->assertFalse($r['ok']);
            $this->assertSame([], WpStub::$writes);
            $other[$type] = $r['code'];
        }
        // `post_out_of_scope`, not `post_other_key`: the two codes were merged back
        // into one because the PAIR was a provenance oracle, and this assertion is
        // about a DIFFERENT oracle over the same posts -- the key's post types. Both
        // hold, and the merge makes this one sharper rather than weaker.
        $this->assertSame('post_out_of_scope', $other['page']);
        $this->assertSame($other['post'], $other['page'],
            "another tenant's post answered differently depending on its type, so the pair "
            . 'of codes is a type oracle over every piece the other key holds');
    }

    /**
     * THE REFUSAL NAMES THE KEY'S OWN SCOPE AND NEVER THE POST'S TYPE.
     *
     * The list is the caller's -- it is on the key it presented -- and the
     * post's type is the site's, the same line `post_out_of_scope` draws one
     * branch up. The assertion is over the JOINED list `implode` produces and
     * not over the word `post`, which this refusal's own sentence contains
     * whatever it discloses.
     */
    public function test_the_type_refusal_names_the_keys_scope_and_not_the_posts_type(): void {
        WpStub::add_post(1, 'attachment', 'en', null);
        WpStub::add_post(2, 'attachment', 'de', null);
        $this->ours(1, 2);

        $r = CadenceLinkRequest::run(
            $this->plan(['trid' => null, 'create_group' => true]),
            ['post', 'landing_page'], 'aaaa1111');

        $this->assertSame('link_post_type_out_of_scope', $r['code']);
        // The joined form, which only this branch can produce: a sentence that
        // had dropped the scope entirely would still contain `post`.
        $this->assertStringContainsString('post, landing_page', $r['reason']);
        $this->assertStringContainsString('1', $r['reason']);
        // NOT the type the post is actually in.
        $this->assertStringNotContainsString('attachment', $r['reason']);
    }
}
