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
}
