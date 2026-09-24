<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

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

    /** The helper's "sign this plan with the suite's key" sentinel, distinct from any header. */
    private const SIGN = "\0sign";

    protected function setUp(): void {
        WpStub::reset();
    }

    /**
     * `CadenceLinkRequest::run`, WITH A SIGNED ATTESTATION OVER THE PLAN.
     *
     * Every test here that is not itself about the signature presents a valid
     * one, because `run` verifies before it reads anything about the site: an
     * unattested call is `attestation_unverified` and never reaches the refusal
     * the test is about. Named for what it does rather than what it wraps --
     * `run` is final on PHPUnit's own TestCase.
     *
     * THE KEY IS A REAL ONE AND NOT NULL, which is the one behaviour this
     * helper changes for the tests below: a null key has no stored public key
     * to check a signature against, so every request it makes is refused.
     * `CadenceKey::created_by` admits an unstamped post to ANY key, so the
     * scope answers here are the ones they were.
     *
     * @param string|null $attestation the header to present, or the sentinel to
     *                    sign this plan; null presents none at all.
     */
    private function link(array $plan, ?array $post_types = null,
                          ?string $key_id = CadenceAttest::KEY_ID,
                          $attestation = self::SIGN): array {
        return CadenceLinkRequest::run(
            $plan,
            $post_types,
            $key_id,
            $attestation === self::SIGN
                ? CadenceAttest::link_header($plan, $key_id)
                : $attestation
        );
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

    #[Group('wpml')]
    public function test_a_well_formed_plan_over_agreeing_posts_writes_both(): void {
        $this->twoPosts(5);
        $r = $this->link($this->plan(), null);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    #[Group('wpml')]
    public function test_a_plan_naming_a_post_that_does_not_exist_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 5);   // post 2 absent
        $r = $this->link($this->plan(), null);
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * THE CHECK THAT MAKES THIS PLUGIN MORE THAN A RELAY. The caller computed
     * the plan on another machine, from a read that has since gone stale or was
     * never taken. This code is the one running where the truth is.
     */
    #[Group('wpml')]
    public function test_a_plan_disagreeing_with_the_sites_own_group_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'page', 'de', 9);   // the site says 9, the plan says 5
        $this->ours(1, 2);
        $r = $this->link($this->plan(), null);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('9', $r['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * CREATING A GROUP REFUSES OVER WHAT IT WOULD DETACH, not over the mere
     * existence of a trid.
     *
     * On a real WPML site every post already has a trid the moment it is
     * saved, so a refusal keyed on "in none" would never fire. Post 3 is what
     * the refusal is actually for: an element in the group that this plan
     * does not name, whose relation a new trid would destroy.
     */
    #[Group('wpml')]
    public function test_creating_a_group_refuses_over_a_member_the_plan_does_not_name(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', 7);
        WpStub::add_post(3, 'page', 'fr', 7);   // in post 2's group, and not in the plan
        $this->ours(1, 2);
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertFalse($r['ok']);
        $this->assertSame('already_grouped', $r['code']);
        $this->assertStringContainsString('3', $r['reason'],
            'the refusal names the post whose relation it is protecting');
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * AND A POST ALONE IN ITS OWN GROUP IS REGROUPED, because nothing is lost.
     *
     * The state WPML leaves every post in, and the one the publish-then-link
     * workflow arrives in. Without this, a group-membership refusal could
     * still be keyed on a different spelling of the same wrong question.
     */
    #[Group('wpml')]
    public function test_creating_a_group_joins_posts_each_alone_in_their_own(): void {
        WpStub::add_post(1, 'page', 'en', 7);
        WpStub::add_post(2, 'page', 'de', 8);
        $this->ours(1, 2);
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame(2, $r['written']);
        $this->assertCount(2, WpStub::$writes);
        // ONE group, and not the two they started in.
        $this->assertSame(WpStub::$posts[1]['trid'], WpStub::$posts[2]['trid']);
        $this->assertNotSame(7, WpStub::$posts[2]['trid']);
    }

    /**
     * A PLAN THAT WOULD SWAP TWO LANGUAGES IS REFUSED.
     *
     * The scenario the widened create path made reachable. `/content` placed A
     * as `en` and B as `de`, each alone in its own group as WPML leaves them. A
     * stale plan names them the other way round. Nothing is outside the plan,
     * so the members check passes; `write_element` writes `language_code` from
     * the PLAN, so the site would start serving each post as the other's
     * language under an `hreflang` that lies. `linked` could not show it: it
     * compares trids, and the trids would be exactly what was asked for.
     */
    #[Group('wpml')]
    public function test_a_plan_that_swaps_two_languages_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 7);
        WpStub::add_post(2, 'page', 'de', 8);
        $this->ours(1, 2);
        // `plan()` builds source `en` / translation `de`; the site says the
        // opposite of each.
        WpStub::$posts[1]['language'] = 'de';
        WpStub::$posts[2]['language'] = 'en';
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertFalse($r['ok'], 'a plan that swaps two languages was written');
        $this->assertSame('language_disagreement', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * AND THE MEMBERS ARE FOUND WHEN THEY ARE DRAFTS, which is the state
     * Cadence actually publishes into.
     *
     * Asked with three arguments, WPML reports only PUBLISHED posts outside an
     * admin request, and a REST request is outside one. So the group below
     * reads as EMPTY, the plan looks safe, and post 3's relation is destroyed.
     * Measured live on WPML 5.0.1 in a REST request: three arguments gave `[]`
     * for a group holding two drafts, `all_statuses` gave both.
     *
     * This test is the reason the stub models the status filter at all. Revert
     * `members_outside_plan` to the three-argument call and this is the test
     * that fails; the published case above keeps passing.
     */
    #[Group('wpml')]
    public function test_a_group_of_drafts_is_not_read_as_an_empty_group(): void {
        WpStub::add_post(1, 'page', 'en', null, true, 'draft');
        WpStub::add_post(2, 'page', 'de', 7, true, 'draft');
        WpStub::add_post(3, 'page', 'fr', 7, true, 'draft');   // not in the plan
        $this->ours(1, 2);
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertFalse($r['ok'], 'a group of drafts was read as empty and the plan was allowed');
        $this->assertSame('already_grouped', $r['code']);
        $this->assertStringContainsString('3', $r['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * A GROUP WPML WILL NOT ENUMERATE IS NOT AN EMPTY ONE.
     *
     * The destructive reading of silence: `apply_filters` hands the default
     * straight back, so code treating `[]` as "no members" would regroup a post
     * whose relations it never managed to read.
     */
    #[Group('wpml')]
    public function test_creating_a_group_refuses_when_the_group_cannot_be_enumerated(): void {
        WpStub::add_post(1, 'page', 'en', 7);
        WpStub::add_post(2, 'page', 'de', 8);
        WpStub::$wpml_group_unreadable = [7];
        $this->ours(1, 2);
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertFalse($r['ok']);
        $this->assertSame('group_unknown', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    #[Group('wpml')]
    public function test_creating_a_group_when_both_are_ungrouped_is_allowed(): void {
        $this->twoPosts(null);
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * `create_group` and a trid are contradictory instructions, and the
     * dangerous reading is the eager one: WPML drops relations when told to
     * create a group. A caller that sends both has a bug, and this refuses
     * rather than picking.
     */
    #[Group('wpml')]
    public function test_create_group_together_with_a_trid_writes_nothing(): void {
        $this->twoPosts(null);
        $r = $this->link($this->plan(['trid' => 5, 'create_group' => true]), null);
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    #[Group('wpml')]
    public function test_a_plan_with_no_translations_writes_nothing(): void {
        $this->twoPosts(5);
        $r = $this->link($this->plan(['translations' => []]), null);
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    #[Group('wpml')]
    public function test_two_posts_claiming_one_language_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'page', 'de', 5);
        WpStub::add_post(3, 'page', 'de', 5);
        $r = $this->link($this->plan(['translations' => [
            ['post_id' => 2, 'language_code' => 'de', 'element_type' => 'post_page',
             'source_language_code' => 'en'],
            ['post_id' => 3, 'language_code' => 'de', 'element_type' => 'post_page',
             'source_language_code' => 'en'],
        ]]), null);
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    #[Group('wpml')]
    public function test_the_source_appearing_among_its_own_translations_writes_nothing(): void {
        $this->twoPosts(5);
        $r = $this->link($this->plan(['translations' => [
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
    #[Group('wpml')]
    public function test_a_malformed_field_writes_nothing(array $over): void {
        $this->twoPosts(5);
        $r = $this->link($this->plan($over), null);
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

    #[Group('wpml')]
    public function test_a_post_of_the_wrong_type_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'post', 'de', 5);   // plan says post_page
        // `ours()` IS LOAD-BEARING HERE, and it was missing. The type check now
        // runs after the scope loop, so without it this plan is refused
        // `post_out_of_scope` and the assertions below pass over a refusal that
        // has nothing to do with the type -- the test would go on passing while
        // the thing it names stopped being checked.
        $this->ours(1, 2);
        $r = $this->link($this->plan(), null);
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
    #[Group('wpml')]
    public function test_an_out_of_scope_post_learns_nothing_about_the_site(): void {
        WpStub::add_post(1, 'page', 'en', null);     // a human's page, real
        WpStub::add_post(2, 'post', 'de', null);     // a human's post, real
        // Post 9 is not on the site at all; the plan below names 1 and 2, so
        // this asserts the two SHAPES that used to differ: wrong type, and
        // absent. Neither is ours.
        $r = $this->link($this->plan(), null);
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
        $r = $this->link($plan, null);
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
    #[Group('wpml')]
    public function test_a_plan_claiming_a_group_the_site_does_not_have_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'page', 'de', null);   // WPML: in no group
        $this->ours(1, 2);
        $r = $this->link($this->plan(), null);
        $this->assertFalse($r['ok']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * WORDPRESS KNOWS THE POST AND WPML RETURNS NOTHING FOR IT. That is not
     * "in no group" -- it is "no answer" -- and treating the two the same is
     * the destructive path, because a create-a-group write then detaches
     * whatever the post was actually attached to.
     *
     * This shape is reachable on a real site -- a post predating WPML's
     * configuration, or of a type WPML is not set to translate -- so a
     * refusal here must not collapse `false` into `null`.
     */
    #[Group('wpml')]
    public function test_a_post_wpml_has_no_answer_for_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null, false);   // WP yes, WPML no
        $this->ours(1, 2);
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('unknown', $r['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    #[Group('wpml')]
    public function test_the_same_shape_with_wpml_answering_creates_the_group(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);          // the only difference
        $this->ours(1, 2);
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * THE TWIN FOR EVERY REFUSAL ABOVE. Without it, a `run()` that returned
     * `['ok' => false]` unconditionally would pass all of them and go
     * unnoticed.
     */
    #[Group('wpml')]
    public function test_the_happy_path_is_reachable_at_all(): void {
        $this->twoPosts(5);
        $r = $this->link($this->plan(), null);
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
    #[Group('wpml')]
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
                // A THIRD ELEMENT IN THAT GROUP, which the plan does not name.
                // Two posts alone together in group 9 are all this plan's
                // members and regroup safely; the refusal is about post 3's
                // relation going away.
                WpStub::add_post(3, 'page', 'fr', 9);
                return $this->plan(['trid' => null, 'create_group' => true]);
            },
            'group_disagreement' => function () {
                $this->twoPosts(9);
                return $this->plan(['trid' => 5, 'create_group' => false]);
            },
            'language_disagreement' => function () {
                // The site holds post 2 as `fr`; the plan calls it `de`, and
                // the plan's code is what would go into WPML's write.
                WpStub::add_post(1, 'page', 'en', 9);
                WpStub::add_post(2, 'page', 'fr', 9);
                $this->ours(1, 2);
                return $this->plan(['trid' => 9, 'create_group' => false]);
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
            // THE SIGNATURE, WHICH IS THE ONLY CAUSE THE PLAN ITSELF CANNOT
            // CARRY. The plan is perfectly good and the posts agree; what is
            // wrong is the header, and the branch that fired travels beside the
            // code in `attestation_branch` rather than as a code of its own.
            'attestation_unverified' => function () {
                $this->twoPosts(5);
                return $this->plan();
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

        // WHICH KEY IS ASKING. Every cause needs a caller with an identity now,
        // which is the attestation's doing rather than the scope's: a key this
        // route cannot name holds no attestation public key either, so a
        // nameless caller is refused before any of these causes is reached. No
        // cause needs a PARTICULAR one -- the second scope predicate no longer
        // has a code of its own, and
        // `test_a_plan_naming_a_post_a_different_key_published_writes_nothing`
        // is where it is asked about instead.
        $asking = [];

        // AND WHAT IT PRESENTS. One cause is the header rather than the plan,
        // and it is the only one that does not sign: a header no sending
        // service composed is `malformed`, which needs no key installed and
        // so cannot be confused with the `no_public_key` branch an
        // un-set-up site answers.
        $presenting = ['attestation_unverified' => 'this is not a v1 header'];

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
            $r = $this->link($arrange(), $scoped[$expected] ?? null,
                             $asking[$expected] ?? CadenceAttest::KEY_ID,
                             $presenting[$expected] ?? self::SIGN);
            $this->assertFalse($r['ok'], $expected . ' was supposed to be refused');
            $this->assertCount($wrote_the_source[$expected] ?? 0, WpStub::$writes, $expected);
            $this->assertSame($expected, $r['code'] ?? null, $expected);
            $seen[] = $r['code'];
        }
        // Thirteen causes, thirteen codes: a mapping that collapsed two of them
        // would still pass every assertion above if both expectations were changed
        // together, and the caller could no longer tell them apart. The count is the
        // union of branches that each added to it -- eight after the scope
        // narrowing, ten after the create path's two, eleven once the scope split
        // into "not this connector's" and "not this key's", TEN again when that
        // split was merged back because the PAIR was a provenance oracle, eleven
        // once the key's post types reached this route, twelve once the route
        // verified an attestation over the plan, and thirteen once the plan's
        // language was checked against the site's -- a check that only became
        // reachable once the create path stopped refusing every real post.
        // So it is asserted against `REFUSAL_CODES` rather than retyped
        // from any of them.
        $this->assertCount(13, array_unique($seen));
        $this->assertSame(13, count(CadenceLinkRequest::REFUSAL_CODES));

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
     * AN UNATTESTED PLAN IS REFUSED BEFORE THIS SITE IS ASKED ANYTHING.
     *
     * The harm property, and not merely the place the lines sit. Every refusal
     * below the verification reads the client's site: `post_out_of_scope` sorts
     * an id into this key's Cadence posts or everything else, `group_unknown`
     * and `group_disagreement` name what WPML holds for a post, and
     * `wpml_unavailable` says whether WPML is installed at all. A caller
     * holding a leaked connector key and no signing key learns none of them.
     *
     * The plan here names posts that do not exist, are not this connector's,
     * and are in no group -- three separate refusals it could have been told
     * apart by. It is told the signature instead.
     */
    public function test_an_unattested_plan_is_refused_before_the_site_is_read(): void {
        // Nothing arranged: no posts, no stamps, no groups.
        $r = $this->link($this->plan(), null, CadenceAttest::KEY_ID, null);
        $this->assertFalse($r['ok']);
        $this->assertSame('attestation_unverified', $r['code']);
        $this->assertSame([], WpStub::$writes);
        // AND THE REASON SAYS NOTHING ABOUT THE SITE.
        $this->assertStringNotContainsString('post 1', $r['reason']);
        $this->assertStringNotContainsString('post 2', $r['reason']);
    }

    /**
     * AND BEFORE THE PLAN'S OWN SHAPE CHECK.
     *
     * Two posts claiming one language is `bad_plan` -- but `bad_plan` is a
     * refusal this route hands to anyone who can reach it, and the checks under
     * it are what the attestation is in front of. The canonical form carries a
     * tie-break over exactly this plan so that an ambiguous one still has one
     * material to verify against: the ordering question is answered by the form
     * rather than by which check happened to run first.
     */
    #[Group('wpml')]
    public function test_verification_runs_before_the_plans_own_shape_check(): void {
        $this->twoPosts(5);
        $plan = $this->plan(['translations' => [
            ['post_id' => 2, 'language_code' => 'en', 'element_type' => 'post_page'],
        ]]);
        // Attested, it is the `bad_plan` it has always been.
        $this->assertSame('bad_plan', $this->link($plan, null)['code']);
        // Unattested, the shape is never reached.
        $r = $this->link($plan, null, CadenceAttest::KEY_ID, null);
        $this->assertSame('attestation_unverified', $r['code']);
        $this->assertSame('absent', $r['attestation_branch']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * AND BEFORE THIS SITE IS ASKED WHETHER IT HAS WPML. That answer is a fact
     * about the client's installation, and an unattested caller does not earn it.
     */
    public function test_verification_runs_before_the_site_is_asked_for_wpml(): void {
        $this->twoPosts(null);
        WpStub::$wpml_reads = false;
        WpStub::$wpml_writes = false;
        $r = $this->link($this->plan(), null, CadenceAttest::KEY_ID, null);
        $this->assertSame('attestation_unverified', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * A TAMPERED `source_language_code` DOES NOT VERIFY, AND NOTHING IS WRITTEN.
     *
     * THE REASON THIS ROUTE SIGNS FOUR FIELDS PER MEMBER AND NOT THREE. This
     * value goes straight into WPML's `wpml_set_element_language_details`, so an
     * intermediary that changed it changes what the site records as the
     * translation's source -- a tamper that ALTERS A WRITE rather than one that
     * can only cause a refusal. Everything else about the plan is the plan the
     * sending service signed.
     */
    public function test_a_tampered_source_language_code_does_not_verify(): void {
        $this->twoPosts(5);
        $signed = $this->plan();
        $tampered = $signed;
        $tampered['translations'][0]['source_language_code'] = 'fr';
        $r = $this->link($tampered, null, CadenceAttest::KEY_ID,
                         CadenceAttest::link_header($signed, CadenceAttest::KEY_ID));
        $this->assertFalse($r['ok']);
        $this->assertSame('attestation_unverified', $r['code']);
        $this->assertSame('mismatch', $r['attestation_branch']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * AND SO DOES A SWAPPED `post_id`, which is what aims the write.
     */
    public function test_a_tampered_member_post_id_does_not_verify(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'page', 'de', 5);
        WpStub::add_post(3, 'page', 'de', 5);
        $this->ours(1, 2, 3);
        $signed = $this->plan();
        $tampered = $signed;
        $tampered['translations'][0]['post_id'] = 3;
        $r = $this->link($tampered, null, CadenceAttest::KEY_ID,
                         CadenceAttest::link_header($signed, CadenceAttest::KEY_ID));
        $this->assertSame('mismatch', $r['attestation_branch'] ?? null);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * AND A BODY RE-SERIALISED IN A DIFFERENT KEY ORDER STILL VERIFIES.
     *
     * The other half of the sort, and the reason the canonical order is derived
     * rather than taken from the wire: an intermediary that re-serialises the
     * JSON, a parser that sorts, or a client library backed by a hash map
     * reorders `translations` without changing a value -- and a verifier that
     * trusted the wire would refuse every honest request as `mismatch`, which
     * blames a tenant's signing key for a proxy.
     */
    #[Group('wpml')]
    public function test_the_same_plan_in_a_different_wire_order_still_verifies(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'page', 'de', 5);
        WpStub::add_post(3, 'page', 'it', 5);
        $this->ours(1, 2, 3);
        $members = [
            'de' => ['post_id' => 2, 'language_code' => 'de', 'element_type' => 'post_page',
                     'source_language_code' => 'en'],
            'it' => ['post_id' => 3, 'language_code' => 'it', 'element_type' => 'post_page',
                     'source_language_code' => 'en'],
        ];
        $signed = $this->plan(['translations' => $members]);
        $reordered = $this->plan(['translations' => ['it' => $members['it'],
                                                     'de' => $members['de']]]);
        $this->assertNotSame(array_keys($signed['translations']),
            array_keys($reordered['translations']),
            'both bodies are in one order, so this proves nothing');

        $r = $this->link($reordered, null, CadenceAttest::KEY_ID,
                         CadenceAttest::link_header($signed, CadenceAttest::KEY_ID));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame('verified', $r['attestation']);
        $this->assertCount(3, WpStub::$writes);
    }

    /**
     * THE EXEMPTION EXEMPTS AN ABSENCE AND NEVER A FAILURE.
     *
     * An un-upgraded site sends no header at all, and the flag is what keeps it
     * linking while its operator catches up. A header that is PRESENT and bad is
     * refused on an exempt key exactly as it is anywhere else: a bad signature is
     * not a migration and there is no reading of it under which it becomes one.
     */
    #[Group('wpml')]
    public function test_the_unsigned_exemption_covers_an_absent_header_and_never_a_bad_one(): void {
        $this->twoPosts(5);
        $key = CadenceAttest::exempt_key();

        $r = $this->link($this->plan(), null, $key, null);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame('exempt', $r['attestation']);
        $this->assertArrayNotHasKey('attestation_kid', $r);
        $this->assertCount(2, WpStub::$writes);

        WpStub::reset();
        $this->twoPosts(5);
        $key = CadenceAttest::exempt_key();
        $r = $this->link($this->plan(), null, $key, 'v1 ' . str_repeat('a', 16) . ' '
            . str_repeat('B', 86));
        $this->assertFalse($r['ok']);
        $this->assertSame('attestation_unverified', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * NO WPML IS A REFUSAL, NOT A SUCCESS.
     *
     * With WPML not installed, WordPress does not object to a filter nobody
     * implements -- it returns the default it was handed -- and it does not
     * object to an action nobody listens to. Code that read that default as
     * WPML's answer would report `200 {"ok": true, "written": 2}` while
     * nothing had actually been written.
     *
     * A refusal here costs a human an install. A false success tells the
     * caller the site is linked, and the caller stops.
     */
    #[Group('wpml')]
    public function test_a_site_without_wpml_refuses_rather_than_reporting_a_write(): void {
        $this->twoPosts(null);
        WpStub::$wpml_reads = false;
        WpStub::$wpml_writes = false;
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
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
    #[Group('wpml')]
    public function test_wpml_that_answers_reads_but_performs_no_writes_is_refused(): void {
        $this->twoPosts(null);
        WpStub::$wpml_writes = false;
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
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
    #[Group('wpml')]
    public function test_wpml_that_writes_but_answers_no_reads_is_refused_as_unavailable(): void {
        $this->twoPosts(null);
        WpStub::$wpml_reads = false;
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
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
    #[Group('wpml')]
    public function test_a_filter_that_declines_to_answer_writes_nothing(): void {
        $this->twoPosts(null);
        WpStub::$wpml_declines = true;
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertFalse($r['ok']);
        $this->assertSame('group_unknown', $r['code']);
        $this->assertSame([], WpStub::$writes);
    }

    #[Group('wpml')]
    public function test_a_written_plan_carries_no_code(): void {
        $this->twoPosts(5);
        $r = $this->link($this->plan(), null);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertArrayNotHasKey('code', $r);
    }

    // ---- the report -------------------------------------------------------
    //
    // `written` says how many links were made and nothing said WHICH, so the
    // caller's ledger recorded that nothing was linked on every run that
    // linked something. These pin the field that answers it, and -- more --
    // pin that it is READ BACK rather than copied out of the plan.

    #[Group('wpml')]
    public function test_a_plan_naming_its_piece_reports_what_the_site_now_says(): void {
        $this->twoPosts(5);
        $r = $this->link($this->plan(['piece_id' => 'piece-1']), null);
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
    #[Group('wpml')]
    public function test_a_write_that_did_not_land_is_not_reported_as_linked(): void {
        $this->twoPosts(5);
        WpStub::add_post(3, 'page', 'fr', 5);
        $this->ours(3);
        WpStub::$wpml_write_detaches = [2];
        $r = $this->link($this->plan(['piece_id' => 'piece-1', 'translations' => [
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
    #[Group('wpml')]
    public function test_a_source_the_site_puts_in_no_group_links_nothing(): void {
        $this->twoPosts(5);
        WpStub::$wpml_write_detaches = [1];
        $r = $this->link($this->plan(['piece_id' => 'piece-1']), null);
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
    #[Group('wpml')]
    public function test_the_create_path_learns_its_group_from_its_own_first_write(): void {
        $this->twoPosts(null);
        $r = $this->link($this->plan(
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
    #[Group('wpml')]
    public function test_a_source_left_in_no_group_by_its_own_write_stops_before_the_translations(): void {
        $this->twoPosts(null);
        WpStub::$wpml_write_detaches = [1];
        $r = $this->link($this->plan(
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
    #[Group('wpml')]
    public function test_a_source_whose_group_cannot_be_read_back_stops_before_the_translations(): void {
        $this->twoPosts(null);
        WpStub::$wpml_write_unreadable = [1];
        $r = $this->link($this->plan(
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
    #[Group('wpml')]
    public function test_a_half_applied_create_naming_no_piece_still_carries_its_count(): void {
        $this->twoPosts(null);
        WpStub::$wpml_write_detaches = [1];
        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);
        $this->assertSame(['ok' => false, 'code' => 'source_group_unset',
                           'reason' => $r['reason'], 'written' => 1], $r);
        $this->assertArrayNotHasKey('report', $r);
    }

    /**
     * A CALLER THAT NAMES NO PIECE GETS EXACTLY WHAT IT GOT BEFORE. The report
     * is filed under the piece; with no piece there is nothing for a ledger to
     * join it to, and an answer carrying half a report would be read as one.
     */
    #[Group('wpml')]
    public function test_a_plan_naming_no_piece_carries_no_report(): void {
        $this->twoPosts(5);
        $r = $this->link($this->plan(), null);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertArrayNotHasKey('report', $r);
        // THE ATTESTATION FIELDS ARE NOT A REPORT and are there whether a piece
        // was named or not: they say what was verified about the REQUEST, which
        // a ledger needs for a call it cannot join to a piece as much as for one
        // it can.
        $this->assertSame(['ok' => true, 'written' => 2, 'attestation' => 'verified',
                           'attestation_kid' => CadenceAttest::KID], $r);
    }

    /**
     * A blank identifier is refused rather than reported under, for the reason
     * `/content` refuses it: it is what a ledger row is found by. And refused
     * BEFORE the write, like every other refusal in this file.
     */
    #[DataProvider('unusablePieceIds')]
    #[Group('wpml')]
    public function test_an_unusable_piece_id_writes_nothing($piece_id): void {
        $this->twoPosts(5);
        $r = $this->link($this->plan(['piece_id' => $piece_id]), null);
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
    #[Group('wpml')]
    public function test_a_plan_naming_a_post_this_connector_never_published_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1);   // post 2 is somebody else's page

        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);

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
    #[Group('wpml')]
    public function test_the_same_plan_over_this_connectors_own_posts_is_written(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);

        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);

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
    #[Group('wpml')]
    public function test_a_source_this_connector_never_published_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(2);   // the TRANSLATION is ours; the source is not

        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);

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
    #[Group('wpml')]
    public function test_an_identifier_this_cannot_read_is_out_of_scope($stored): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1);
        WpStub::$meta[2][CadenceContentRequest::META] = $stored;

        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);

        $this->assertFalse($r['ok']);
        $this->assertSame('post_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$writes);
        $this->assertFalse(CadenceKey::scope_admits(2));
    }

    /**
     * A POST A DIFFERENT CONNECTOR KEY PUBLISHED IS NOT THIS KEY'S TO LINK.
     *
     * The scope used to be "a piece Cadence published", which on a site holding
     * two keys -- two brands on one WordPress, an agency serving two separate
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
    #[Group('wpml')]
    public function test_a_plan_naming_a_post_a_different_key_published_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);
        WpStub::$meta[1][CadenceContentRequest::KEY_META] = 'aaaa1111';
        WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'bbbb2222';

        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null, 'aaaa1111');

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
    #[Group('wpml')]
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

            $r = $this->link($plan, null, 'aaaa1111');

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
    /**
     * AND A TYPED KEY GETS THE SAME SENTENCE AS AN UNTYPED ONE.
     *
     * `get_post_type()` answers `false` for an id with no row, and `false` is in no
     * key's type list -- so without this, a stamped post since deleted would be
     * refused `link_post_type_out_of_scope` for a key scoped to `post`, and
     * `bad_plan ... does not exist` for a key scoped to nothing. Same input must
     * give one sentence, not two: a type-scope refusal reads as an invitation to
     * widen the key, over a post that is simply gone.
     *
     * Nothing leaked: reaching the type check means the entitlement check already
     * said yes.
     */
    #[Group('wpml')]
    public function test_an_absent_post_is_not_reported_as_a_type_this_key_cannot_reach(): void {
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(2);
        WpStub::$meta[7][CadenceContentRequest::META] = 'piece-7';
        $plan = $this->plan(['trid' => null, 'create_group' => true]);
        $plan['source']['post_id'] = 7;

        // `page`, because post 2 IS a page and must pass its own type check -- the
        // post under test is the absent one, and a fixture where another post fires
        // first would prove nothing about it.
        $typed = $this->link($plan, ['page']);
        $untyped = $this->link($plan, null);

        $this->assertSame('bad_plan', $typed['code'], $typed['reason'] ?? '');
        $this->assertStringContainsString('does not exist', $typed['reason']);
        // The two keys differ only in their type list, and the post is absent for
        // both: one input, one answer.
        $this->assertSame($untyped['code'], $typed['code']);
        $this->assertSame($untyped['reason'], $typed['reason']);
        $this->assertSame([], WpStub::$writes);
    }

    #[Group('wpml')]
    public function test_a_post_this_key_reaches_is_still_told_the_site_has_no_such_post(): void {
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(2);
        // Post 7 is absent, but it is INSIDE the scope: the stamp says so.
        WpStub::$meta[7][CadenceContentRequest::META] = 'piece-7';
        $plan = $this->plan(['trid' => null, 'create_group' => true]);
        $plan['source']['post_id'] = 7;

        $r = $this->link($plan, null);

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
    #[Group('wpml')]
    public function test_a_source_a_different_key_published_writes_nothing(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);
        WpStub::$meta[1][CadenceContentRequest::KEY_META] = 'bbbb2222';
        WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'aaaa1111';

        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null, 'aaaa1111');

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
    #[Group('wpml')]
    public function test_the_same_plan_over_this_keys_own_posts_is_written(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);
        WpStub::$meta[1][CadenceContentRequest::KEY_META] = 'aaaa1111';
        WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'aaaa1111';

        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null, 'aaaa1111');

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
    #[Group('wpml')]
    public function test_a_plan_over_posts_that_predate_the_key_stamp_is_still_written(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);   // the identifier only -- no stamp, as 0.3.0 wrote them

        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null, 'aaaa1111');

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * AND THAT COMPATIBILITY PATH'S OWN SCOPE LIMIT, stated rather than left
     * implicit.
     *
     * Per-key scope rests on three predicates -- `scope_admits`, `created_by`,
     * and the key's post-type list. The first two separate two keys over a
     * STAMPED post; neither separates them over an unstamped one, so a second
     * key can still LINK an unstamped post, exactly as it could before
     * per-key scope existed. This test drives the widest case
     * (`$post_types = null`), which is the one with nothing left to separate
     * the keys, and asserts that behaviour rather than assuming it away.
     *
     * The post-type list narrows this without closing it: an unstamped `page`
     * is refused to a key scoped to `post`, so two keys sharing a post type,
     * or a key naming no types, still reach each other's unstamped pieces.
     * That residual is itself only a type-membership distinction, strictly
     * narrower than the reach it sits on top of, since a caller that can LINK
     * an unstamped post can already learn its type by linking it.
     *
     * The set this applies to is exactly the posts a site published before
     * per-key stamping existed; it never grows, since `/content` stamps every
     * insert since, and it shrinks as those pieces are replaced. It is empty
     * on a site that has only ever run a stamping connector.
     *
     * Refusing every unstamped post outright would refuse every link over
     * content already live on such a site, which is why the scope check
     * stops at the post-type list here rather than closing this fully.
     */
    #[Group('wpml')]
    public function test_a_second_key_can_link_posts_published_before_stamping(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);   // an identifier and no stamp, as 0.3.0 wrote them

        // A key that published NEITHER of these, and says so.
        $r = $this->link(
            $this->plan(['trid' => null, 'create_group' => true]), null, 'bbbb2222');

        $this->assertTrue($r['ok'], 'an unstamped post was refused to a second key; see this docblock');
        $this->assertCount(2, WpStub::$writes);
        // AND THE TWIN, so this cannot be satisfied by a route that admits
        // everything: stamp ONE of the two and the same key is refused.
        WpStub::$writes = [];
        WpStub::$meta[2][CadenceContentRequest::KEY_META] = 'aaaa1111';
        $refused = $this->link(
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
    #[Group('wpml')]
    public function test_the_scope_refusal_names_the_post_and_leaks_nothing_about_it(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1);
        WpStub::$meta[2]['_some_other_plugin'] = 'Secret Draft Title';

        $r = $this->link($this->plan(['trid' => null, 'create_group' => true]), null);

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
    #[Group('wpml')]
    public function test_a_page_is_not_linked_by_a_key_scoped_to_posts(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);

        $r = $this->link(
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
    #[Group('wpml')]
    public function test_a_source_outside_the_keys_types_is_not_linked(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'post', 'de', null);
        $this->ours(1, 2);

        $r = $this->link(
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
    #[Group('wpml')]
    public function test_a_link_over_posts_of_a_type_the_key_names_still_succeeds(): void {
        WpStub::add_post(1, 'post', 'en', null);
        WpStub::add_post(2, 'post', 'de', null);
        $this->ours(1, 2);

        // `post_post`, because the element type has to agree with the post's
        // own type and these are posts: the fixture everywhere else in this
        // file is a pair of pages.
        $r = $this->link($this->plan([
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
    #[Group('wpml')]
    public function test_a_key_that_names_no_type_links_any_type(): void {
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        $this->ours(1, 2);

        $r = $this->link(
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
    #[Group('wpml')]
    public function test_the_type_scope_cannot_be_asked_about_a_post_this_key_does_not_reach(): void {
        // NOT A PIECE THIS CONNECTOR PUBLISHED -- post 2 carries no identifier.
        $outside = [];
        foreach (['page', 'post'] as $type) {
            WpStub::reset();
            WpStub::add_post(1, 'post', 'en', null);
            WpStub::add_post(2, $type, 'de', null);
            $this->ours(1);
            $r = $this->link(
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
            $r = $this->link(
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
    #[Group('wpml')]
    public function test_the_type_refusal_names_the_keys_scope_and_not_the_posts_type(): void {
        WpStub::add_post(1, 'attachment', 'en', null);
        WpStub::add_post(2, 'attachment', 'de', null);
        $this->ours(1, 2);

        $r = $this->link(
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
