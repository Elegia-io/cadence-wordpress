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
        $r = CadenceLinkRequest::run($this->plan());
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('9', $r['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_creating_a_group_requires_every_post_to_be_in_none(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', 7);   // already grouped
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
        $r = CadenceLinkRequest::run($this->plan());
        $this->assertFalse($r['ok']);
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
        $r = CadenceLinkRequest::run($this->plan(['trid' => null, 'create_group' => true]));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('unknown', $r['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    public function test_the_same_shape_with_wpml_answering_creates_the_group(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);          // the only difference
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
            'wpml_unavailable' => function () {
                $this->twoPosts(null);
                WpStub::$wpml_reads = false;
                WpStub::$wpml_writes = false;
                return $this->plan(['trid' => null, 'create_group' => true]);
            },
        ];

        $seen = [];
        foreach ($causes as $expected => $arrange) {
            WpStub::reset();
            $r = CadenceLinkRequest::run($arrange());
            $this->assertFalse($r['ok'], $expected . ' was supposed to be refused');
            $this->assertSame([], WpStub::$writes, $expected);
            $this->assertSame($expected, $r['code'] ?? null, $expected);
            $seen[] = $r['code'];
        }
        // Seven causes, seven codes: a mapping that collapsed two of them would
        // still pass every assertion above if both expectations were changed
        // together, and the caller could no longer tell them apart.
        $this->assertCount(7, array_unique($seen));

        // AND THE PUBLISHED LIST IS THAT LIST. `REFUSAL_CODES` is what the REST
        // layer maps to HTTP statuses; if a seventh refusal is added here and
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
}
