<?php
/**
 * Decide whether one WPML link plan may be written, then write it.
 *
 * THIS CODE DOES NOT TRUST ITS CALLER, and that is its whole reason for
 * existing as more than a relay. The caller computes a plan and has its own
 * refusals; they ran on another machine, against a read that may since have
 * gone stale -- somebody unlinked the pair in wp-admin a minute ago. This runs
 * where the truth is, so it re-derives every precondition from the database and
 * refuses when the plan disagrees with what the site actually says.
 *
 * DIRECTION OF ERROR. A missing link costs a human one action in wp-admin. A
 * wrong link tells the site the German post translates the wrong Italian one,
 * and the site serves that to real visitors under an `hreflang` that lies.
 * And WPML's own documentation for `wpml_set_element_language_details`: *"If
 * set to FALSE it will create a new trid for the element causing any potential
 * translation relations to/from it to disappear."* So a write with an
 * unestablished group DESTROYS relations that already exist, including ones a
 * human made by hand.
 *
 * Every refusal below therefore fails toward writing NOTHING, and the whole
 * plan is checked before any of it is written -- a partially applied plan is a
 * translation group with one member in it. Any future change that makes this
 * more eager is a regression even if it raises the automation rate.
 *
 * THE ONE EXCEPTION, AND WHY IT IS UNAVOIDABLE. When the plan says
 * `create_group` there is no group id yet: it is invented by WPML during the
 * first write, and the sentence quoted above says it invents ONE PER ELEMENT.
 * Writing a null trid for every element therefore builds a group of one per
 * post and links nothing, while the count of writes issued still says two. So
 * the create path writes the SOURCE first, reads the group WPML gave it back
 * out of the site, and writes each translation under that id. Two refusals
 * exist for the gap between those steps, and they are the only refusals here
 * that have already written something; both say so in the answer
 * (`written`, and the report's `linked`) rather than leaving the caller to
 * infer it from a bare `ok: false`.
 *
 * DOCUMENTED, NOT OBSERVED. Three things this file believes about WPML come
 * from WPML's documentation and from nothing else -- there is no WordPress and
 * no WPML licence in the environment this was built in, and the suite's stub
 * models the documentation rather than a measurement:
 *
 *   1. that a falsy `trid` on `wpml_set_element_language_details` creates a new
 *      trid for that element and drops its existing relations (quoted above);
 *   2. that it does so PER ELEMENT, so a set of such writes does not converge
 *      on one group -- the action is told about one element and has no way to
 *      know the call is one of a set;
 *   3. that `wpml_element_language_details` read immediately afterwards, in the
 *      SAME request, answers with the trid the write just invented. This third
 *      one is not documented at all, and the ordered write below does not work
 *      without it. If WPML defers or caches that write, the read returns the
 *      pre-write answer -- `null` -- and this route refuses
 *      `source_group_unset` on every create, which is a visible, non-destructive
 *      failure rather than a wrong link. A live check against WPML 4.x is what
 *      would settle all three; until then assume they are beliefs.
 *
 * @package CadenceConnector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// Loaded by WordPress, never fetched over HTTP: an include reached directly
// runs with none of the environment the code below assumes it has.
if (!defined('ABSPATH')) {
    exit;
}

final class CadenceLinkRequest {

    /**
     * Every code `run` can refuse with. Published so the REST layer can be
     * tested for covering all of them rather than for covering the ones its
     * own tests happened to name.
     */
    public const REFUSAL_CODES = [
        'bad_plan',
        'contradictory_instructions',
        'no_group_named',
        'group_unknown',
        'already_grouped',
        'group_disagreement',
        'source_group_unset',
        'source_group_unreadable',
        'wpml_unavailable',
        'post_out_of_scope',
        'link_post_type_out_of_scope',
    ];

    /** A bare lowercase WPML language code: `de`, `pt-br`, `zh-hant-hk`. */
    private const LANGUAGE = '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/';

    /**
     * A REFUSAL CARRIES A CODE AS WELL AS A REASON. The reason is prose, for
     * the human reading a log, and it is free to change. The code is the API:
     * the caller uses it to decide whether to re-read this site and retry
     * (`group_unknown`, `already_grouped`, `group_disagreement` -- the site
     * disagrees with the plan) or to stop and fix the plan itself (`bad_plan`,
     * `contradictory_instructions`, `no_group_named` -- no re-read can help).
     * A caller that had to tell those apart by matching the prose would be
     * matching on spellings this file changes freely.
     *
     * `source_group_unset` and `source_group_unreadable` are a third class and
     * the reason the code is not merely a status in disguise: the source WAS
     * written and the translations were not. A caller reading them as "the site
     * disagreed, nothing happened" would be wrong about the site, so they are
     * not spelled as the 409s above even though a re-read is the next step for
     * both.
     *
     * @param array $plan the JSON body, already decoded
     * @param list<string>|null $post_types the post types the presenting key
     *                    reaches, or null for a key issued before the field
     *                    existed -- which links in any type, exactly as it did
     *                    before this plugin was updated under it. REQUIRED and
     *                    without a default, unlike `$key_id` below: null here
     *                    is the WIDE case, so a call site that forgot it would
     *                    read as a key that named no type and the scope would
     *                    quietly stop applying at this route again.
     * @param string|null $key_id the public id of the presenting key. Null is
     *                    a caller this route cannot name, and it reaches only
     *                    the posts that carry no key stamp -- every post that
     *                    names one is refused. Optional so that the default is
     *                    the NARROW case rather than the wide one: no identity
     *                    is not a wildcard.
     * @return array{ok: bool, code?: string, reason?: string, written?: int,
     *               report?: array}
     */
    public static function run(array $plan, ?array $post_types, ?string $key_id = null): array {
        // BEFORE ANYTHING ELSE, INCLUDING THE PLAN'S OWN SHAPE: a server that
        // cannot perform this request at all has no standing to tell the caller
        // its request is malformed.
        //
        // WordPress is perfectly content with a filter nobody implements -- it
        // hands back the default it was given -- and with an action nobody
        // listens to. So on a site with no WPML every precondition below reads
        // as agreeable and every write goes nowhere. Measured against a real
        // WordPress 6.8 with WPML absent, this returned `200 {"written": 2}`.
        //
        // The question is asked of the CAPABILITY -- is anything listening --
        // and not of a name: `defined('ICL_SITEPRESS_VERSION')` would be a
        // check on the spelling of an implementation, and answers yes for a
        // WPML that is present but has these hooks disabled.
        if (!has_filter('wpml_element_language_details') || !has_action('wpml_set_element_language_details')) {
            return ['ok' => false, 'code' => 'wpml_unavailable', 'reason' =>
                'nothing on this site implements the WPML translation-group hooks, so a link cannot be read or written here'];
        }

        $posts = self::validate_shape($plan);
        if (is_string($posts)) {
            return ['ok' => false, 'code' => 'bad_plan', 'reason' => $posts];
        }

        // WHICH PIECE THIS LINK BELONGS TO, and the one field of the report
        // this route cannot derive from the site. OPTIONAL, and the report is
        // emitted only when it is given: a caller that names no piece gets
        // exactly the `{ok, written}` it got before this field existed, rather
        // than a report filed under an identity the ledger cannot join to
        // anything. `piece_id` and not a second spelling -- the wire carries
        // one name for this value, and `/content` already answers under it.
        $piece_id = $plan['piece_id'] ?? null;
        if ($piece_id !== null && (!is_string($piece_id) || trim($piece_id) === '')) {
            return ['ok' => false, 'code' => 'bad_plan',
                    'reason' => 'piece_id is present but is not a non-blank string'];
        }
        // THIS IS THE AUTHORISATION BOUNDARY, and it is here rather than in the
        // route's `permission_callback` for two reasons. It is the layer the
        // write cannot be reached without -- anything calling `run` passes
        // through it, including a future second caller that never registers a
        // route -- and a `permission_callback` can only answer true or false,
        // which WordPress turns into its own `rest_forbidden` with no code the
        // caller can match on. `CadenceKey::authorises` up at the route is the
        // TRIPWIRE: it refuses a key that holds nothing here, earlier and
        // cheaper, and this refuses regardless of whether it ran.
        //
        // WHAT IT NARROWS. `translation.link` used to authorise linking ANY
        // post on the site, because the per-post `current_user_can('edit_post')`
        // it replaced had no user to ask about. It now reaches only the posts
        // this plugin created, and of those only the ones THIS key created --
        // two predicates, refused separately, because they are two facts.
        //
        // AFTER EVERY `bad_plan` AND BEFORE EVERY OTHER CODE. A body this
        // cannot read is refused on its shape whichever posts it names, so
        // "fix your JSON" never depends on which credential asked -- and a
        // caller fixing a malformed body is not sent chasing a scope that was
        // never the problem.
        //
        // BEFORE ANY GROUP IS READ, not merely before any is written. A
        // `group_disagreement` names the trid the site holds for a post, which
        // is a row id in the client's database; a caller that may not touch the
        // post may not learn that about it either. So scope is settled for
        // every post in the plan first, and the plan's own semantics -- which
        // group it names, and whether the site agrees -- are interpreted only
        // over posts this key may act on.
        //
        // ONE QUESTION, ONE CODE, AND THE MERGE IS THE POINT. This asks
        // whether the presenting key REACHES the post, which is a single fact
        // about this caller's entitlement, and the refusal asserts exactly that
        // and nothing finer.
        //
        // IT USED TO BE TWO. `post_out_of_scope` said "not a piece this
        // connector published" -- absent and not-ours together, which
        // `get_post_meta` cannot tell apart anyway -- and `post_other_key` said
        // "ours, but another key's". The second code therefore partitioned the
        // id space into "another tenant's Cadence post" and everything else,
        // and a key holding `translation.link` could walk it one 403 at a time.
        // What that discloses is PROVENANCE -- which of two tenants on one site
        // published a given post -- and per-key scope was added to protect
        // precisely that. This route verifies no attestation, so the walk needs
        // only a leaked connector key.
        //
        // AND IT IS NOT ONE SENTENCE OVER TWO DENY CLASSES, which is the defect
        // the rest of this vocabulary avoids. There is one predicate here,
        // `CadenceKey::reaches`, and one branch; the sentence names the branch
        // that fired and asserts no access that never happened. Merging the
        // MESSAGES while keeping two predicates would have been the defect;
        // merging the QUESTION is what removes the oracle.
        //
        // WHAT A LEGITIMATE CALLER LOSES: it can no longer read "republish this
        // through /content" against "present the key that owns it" off the
        // code. It keeps the id it sent, the fact that this credential does not
        // reach it, and the operator's own record of what it published. The
        // one case that genuinely differs -- a rotated key over its own older
        // pieces -- is answered by the admin screen, on the site, by someone
        // entitled to both.
        //
        // WHAT IS STILL WIDE, deliberately, and measured rather than hidden: a
        // post carrying no key stamp is admitted to ANY key that reaches it,
        // because every piece published before the stamp existed carries none
        // and refusing them would break every link over content already live.
        // See `CadenceKey::created_by`. That set never grows, and
        // `LinkRequestTest::test_a_second_keys_reach_over_pre_stamp_posts_is_a_measured_gap`
        // asserts the gap rather than asserting it away.
        foreach ($posts as $p) {
            if (!CadenceKey::reaches($p['post_id'], $key_id)) {
                // The id and the claim, and nothing else. Not whether the site
                // has such a post, not whether this connector published it, not
                // the key id it carries, not its identifier, title, type or
                // status -- the same line `identifier_mismatch` draws one route
                // over, for the same reason: a refusal that spelled out what
                // the site holds would hand it to any caller holding a key.
                return ['ok' => false, 'code' => 'post_out_of_scope', 'reason' => sprintf(
                    'post %d is not one this key may link; nothing was linked',
                    $p['post_id'])];
            }
            // AND THE POST IS IN A TYPE THIS KEY STILL REACHES.
            //
            // WHY THE LINKING ROUTE NEEDS IT AT ALL, given that it writes no
            // text. The two predicates above stop at the same place the
            // replace route's do: `created_by` admits every post made before
            // the key stamp existed, and over that set two keys on one site do
            // not separate. A key scoped to `post` could therefore attach an
            // unstamped `page` -- another tenant's, or one an operator has
            // since put out of this key's reach -- into a translation group,
            // and WPML's own action DESTROYS the relations a post already had
            // when it is handed a group that is not its own. The act is
            // narrower than a rewrite; the damage it can do to a relation a
            // human made by hand is not.
            //
            // ITS OWN CODE, NOT `existing_post_type_out_of_scope`. That one is
            // asked of a PIECE the caller named by identifier, on the two
            // routes that create or overwrite text, and its sentence ends
            // "nothing was written". This is asked of a POST the caller named
            // by id, and ends "nothing was linked". A caller matching on the
            // code to decide what did not happen would otherwise be told about
            // an act it never asked for -- the same reason `replace_other_key`
            // is not `post_other_key` over the one predicate they share.
            //
            // LAST OF THE THREE, AND THE ORDER IS THE GUARD. The linking route
            // is asked about a bare post ID -- it holds no identifier for the
            // post the way `/content/replace` does, so it has no
            // `identifier_mismatch` to hide behind and this is the ONLY check
            // here that can answer a question about the post's own type. Run
            // first, it would answer "is post N inside this key's types" for
            // every post id on the site, which is the post's type by another
            // name and a type oracle over posts the caller may not touch at
            // all. Run last, it is reachable only for a post that already
            // passed both entitlement checks -- one this key published, or an
            // unstamped one it inherits -- so the only type it can be made to
            // speak about is a post the caller already reaches.
            //
            // THE POSITION IS PINNED, not merely argued here: move this block
            // above either check and
            // `LinkRequestTest::test_the_type_scope_cannot_be_asked_about_a_post_this_key_does_not_reach`
            // fails, because two posts outside the key's reach stop answering
            // with the same refusal.
            //
            // `null` names no type and means ANY, so a key issued before the
            // field existed links what it always linked.
            if ($post_types !== null && !in_array(get_post_type($p['post_id']), $post_types, true)) {
                // The id the caller sent and the key's OWN scope, which is the
                // caller's to know -- and never the type the post is in, which
                // is the site's. That is the same line the two refusals above
                // draw, and the reason this one may name a list at all.
                return ['ok' => false, 'code' => 'link_post_type_out_of_scope', 'reason' => sprintf(
                    'post %d is of a type this key does not reach; this key is scoped to '
                    . '%s, and nothing was linked',
                    $p['post_id'], implode(', ', $post_types))];
            }
        }

        // NOW the site may be asked about these posts, because the key reaches
        // every one of them. A refusal here is still `bad_plan`: the caller may
        // act on the post and its plan describes it wrongly.
        $site = self::validate_against_site($posts);
        if ($site !== null) {
            return ['ok' => false, 'code' => 'bad_plan', 'reason' => $site];
        }

        $create = $plan['create_group'];
        $trid   = $plan['trid'];

        // CONTRADICTORY INSTRUCTIONS ARE REFUSED, NEVER RESOLVED. `create_group`
        // with a trid asks for both "join group N" and "make a new one", and
        // the eager reading is the destructive one. A caller sending both has a
        // bug; picking a winner would hide it behind a plausible result.
        if ($create && $trid !== null) {
            return ['ok' => false, 'code' => 'contradictory_instructions',
                    'reason' => 'create_group is set and a trid is given; these are contradictory'];
        }
        if (!$create && $trid === null) {
            return ['ok' => false, 'code' => 'no_group_named',
                    'reason' => 'no trid and create_group is not set; there is no group to join'];
        }

        // EVERY POST IS READ BEFORE ANY IS WRITTEN. Reading them one at a time
        // as we write would leave a half-built group behind the first
        // disagreement, which is worse than the state we started in.
        foreach ($posts as $p) {
            $actual = self::current_trid($p['post_id'], $p['element_type']);
            if ($actual === false) {
                return ['ok' => false, 'code' => 'group_unknown', 'reason' => sprintf(
                    'post %d: WPML returned no language details, so its translation group is unknown; refusing to write one',
                    $p['post_id'])];
            }
            if ($create && $actual !== null) {
                return ['ok' => false, 'code' => 'already_grouped', 'reason' => sprintf(
                    'post %d is already in translation group %d, so a new group cannot be created without detaching it',
                    $p['post_id'], $actual)];
            }
            if (!$create && $actual !== $trid) {
                return ['ok' => false, 'code' => 'group_disagreement', 'reason' => sprintf(
                    'post %d is in translation group %s on this site, but the plan says %d; refusing to write a link over a disagreement',
                    $p['post_id'], $actual === null ? 'none' : (string) $actual, $trid)];
            }
        }

        // THE CREATE PATH LEARNS ITS GROUP FROM ITS OWN FIRST WRITE. Handing
        // every element the same null trid asks WPML to invent a group for each
        // of them separately (see the header); the group has to exist before
        // the translations can name it, and the only thing that can bring it
        // into existence is the source's own write.
        //
        // The source, and not an arbitrary member: it is the element the report
        // is filed under and the one `/content` already placed, so if exactly
        // one post ends up in a group of its own, that post is the one a human
        // looking for this piece will find.
        if ($create) {
            $source = $posts[0];
            self::write_element($source, null);

            // READ BACK, NEVER ASSUMED. `do_action` returns nothing, so the id
            // WPML chose is not available from the write and cannot be guessed
            // -- and the two answers that are not an id are the two refusals
            // below rather than a value to write with.
            $group = self::current_trid($source['post_id'], $source['element_type']);
            if ($group === null) {
                // WPML ANSWERED, AND PUTS THE SOURCE IN NO GROUP. Either the
                // write went nowhere or it created nothing; either way there is
                // no id for the translations to join, and writing them with a
                // null trid is the very behaviour this ordering exists to stop.
                //
                // Nothing was destroyed: the create path refuses above unless
                // EVERY post is in no group, so the source had no relations to
                // lose and the translations were not touched. A caller that
                // re-reads and sends the identical plan again is safe.
                return self::half_written('source_group_unset', sprintf(
                    'post %d was written with no trid and WPML still puts it in no group, so there is no group for the %d translation(s) to join; they were not written',
                    $source['post_id'], count($posts) - 1), $piece_id, $posts);
            }
            if (!is_int($group)) {
                // A READ THAT IS NOT A READING. `false` is WPML saying nothing
                // usable about an element it answered for a moment ago, which
                // leaves the source's group unknown rather than absent -- and an
                // unknown group is the one thing that must never be written
                // over. A re-read is the caller's next step and the pre-write
                // check will refuse `group_unknown` until the site answers.
                return self::half_written('source_group_unreadable', sprintf(
                    'post %d was written with no trid and WPML then returned no usable language details for it, so the group it is now in cannot be named; the %d translation(s) were not written',
                    $source['post_id'], count($posts) - 1), $piece_id, $posts);
            }
            foreach (array_slice($posts, 1) as $p) {
                self::write_element($p, $group);
            }
        } else {
            foreach ($posts as $p) {
                self::write_element($p, $trid);
            }
        }
        $answer = ['ok' => true, 'written' => count($posts)];
        if ($piece_id !== null) {
            $answer['report'] = self::report($piece_id, $posts);
        }
        return $answer;
    }

    /**
     * One element into one translation group. The only place this file writes.
     *
     * @param array $p one validated plan entry
     * @param int|null $trid the group to write, or null to have WPML invent one
     */
    private static function write_element(array $p, ?int $trid): void {
        do_action('wpml_set_element_language_details', [
            'element_id'           => $p['post_id'],
            'element_type'         => $p['element_type'],
            'trid'                 => $trid,
            'language_code'        => $p['language_code'],
            'source_language_code' => $p['source_language_code'],
        ]);
    }

    /**
     * A REFUSAL THAT HAS ALREADY WRITTEN THE SOURCE, and the only kind here.
     *
     * It carries the same two fields a success does -- `written`, and the
     * report when a piece was named -- because a half-applied plan the caller
     * cannot see is worse than either outcome: the ledger would file nothing
     * for a run that changed the site. `written` is 1 by construction and not a
     * count of the loop: the loop below the refusal never ran.
     *
     * The report is the same read-back the success path emits, and on this path
     * it reports `linked: []` for the same reason it reports it anywhere -- the
     * source's group could not be read as an id, so no translation can be shown
     * to share it.
     *
     * @param list<array> $posts the validated plan, source first
     */
    private static function half_written(string $code, string $reason,
                                        ?string $piece_id, array $posts): array {
        $answer = ['ok' => false, 'code' => $code, 'reason' => $reason, 'written' => 1];
        if ($piece_id !== null) {
            $answer['report'] = self::report($piece_id, $posts);
        }
        return $answer;
    }

    /**
     * WHAT THIS CALL ACTUALLY DID, in the fields the caller's verifier reads.
     *
     * `linked` IS RE-READ FROM THE SITE, NEVER PROJECTED FROM THE PLAN. The
     * languages are all sitting in `$posts` and copying them out would be
     * cheaper, truthful-looking and wrong: it would report a link for every
     * element the plan named, which is the request echoed back with an `ok`
     * beside it. `do_action` returns nothing at all, so the only evidence a
     * write landed is what WPML says afterwards -- and the same silence that
     * produced `200 {"written": 2}` on a site with no WPML produces an empty
     * `linked` here, which is the direction of error this field is for.
     *
     * So: the SOURCE's group is read back first, and a translation is reported
     * as linked only when the site puts it in that same group. A group that
     * cannot be read at all (`false`, or `null` for "in no group") links
     * nothing, and `written` beside an empty `linked` is the honest shape for
     * writes that went nowhere.
     *
     * `placed` is empty here and always will be, for the mirror image of the
     * reason `linked` is empty on `/content`: this route associates posts that
     * already exist and creates none. Reporting a placement it did not make is
     * the same error one boundary over.
     *
     * `observed_unsupported` is ABSENT rather than empty. This route never asks
     * the site which languages it serves, and an empty list would be an absence
     * nothing measured -- the one field here that would be a claim rather than
     * a reading.
     *
     * Ids, counts and language codes only. No title, no slug, no excerpt: a
     * report travels to a caller that is trusted with the link it asked for and
     * not with the site's copy.
     *
     * @param list<array> $posts the validated plan, source first
     */
    private static function report(string $piece_id, array $posts): array {
        $source = $posts[0];
        // The SOURCE's post id: `piece_id` names the piece, and the source is
        // the post that piece is. It is the pair `/content` already reported
        // when it placed that piece, so the two rows join on both fields; a
        // translation's id would be a different piece's, and picking one of
        // several would be arbitrary.
        $group  = self::current_trid($source['post_id'], $source['element_type']);

        $linked = [];
        if (is_int($group)) {
            foreach (array_slice($posts, 1) as $p) {
                if (self::current_trid($p['post_id'], $p['element_type']) === $group) {
                    $linked[] = $p['language_code'];
                }
            }
        }

        return [
            'piece_id' => $piece_id,
            'post_id'  => $source['post_id'],
            'placed'   => [],
            // The source's own language is deliberately NOT in here. It is
            // what `/content` reported under `placed` for this piece, and
            // leaving it out makes an empty `linked` unambiguous: nothing was
            // associated, rather than "only the piece itself".
            'linked'   => $linked,
            // Nothing was refused per language. Every refusal this route has is
            // total -- it returns `ok: false` and writes nothing -- so a report
            // exists only where there is no refusal to list. Measured, not
            // assumed empty.
            'refused'  => [],
        ];
    }

    /**
     * The plan's own shape, checked without coercion.
     *
     * PHP WILL HAPPILY MAKE `"5"` INTO `5` AND `true` INTO `1`, which is how a
     * boolean post id becomes post 1 and a JSON string trid becomes a real
     * group. Every scalar here is checked with `is_int` / `is_string` / `is_bool`
     * and never cast. Returns the flat post list, or a reason string.
     *
     * @return list<array>|string
     */
    /**
     * The checks that ask the SITE, run only over posts scope already admitted.
     *
     * Split out of `validate_shape` because that runs before the authorisation
     * boundary and these two reads disclose, for any post id, whether it exists
     * and what type it really is. Scope needs only the id -- `get_post_meta` on
     * an id the site does not have answers `''`, so a missing post is refused as
     * out of scope and stops being distinguishable from a post this connector
     * did not publish, which is the right answer to give a caller that may not
     * touch either.
     *
     * Returns a string on refusal, exactly as `validate_shape` does, so the
     * caller's `bad_plan` mapping is unchanged for a caller whose posts it may
     * act on: a Cadence post named with the wrong `element_type` is still a bad
     * plan, and is still told so.
     */
    private static function validate_against_site(array $posts) {
        foreach ($posts as $index => $p) {
            $where = $index === 0 ? 'source' : "translation $index";
            // The post has to be one this site actually has, of the type the
            // plan claims. A plan naming a post id that does not exist is not a
            // link to write; it is a caller talking about a different site.
            $post_type = get_post_type($p['post_id']);
            if ($post_type === false || get_post_status($p['post_id']) === false) {
                return "$where names post {$p['post_id']}, which does not exist on this site";
            }
            if ('post_' . $post_type !== $p['element_type']) {
                return sprintf('%s names post %d, whose type is `%s` and not `%s`',
                    $where, $p['post_id'], $post_type, $p['element_type']);
            }
        }
        return null;
    }

    private static function validate_shape(array $plan) {
        foreach (['trid', 'create_group', 'source', 'translations'] as $key) {
            if (!array_key_exists($key, $plan)) {
                return "the plan has no '$key'";
            }
        }
        if (!is_bool($plan['create_group'])) {
            return 'create_group is not a boolean';
        }
        // `is_bool` first: `true` is an int to a loose check but not a trid.
        if ($plan['trid'] !== null
            && (is_bool($plan['trid']) || !is_int($plan['trid']) || $plan['trid'] <= 0)) {
            return 'trid is neither null nor a positive integer';
        }
        if (!is_array($plan['source']) || !is_array($plan['translations'])) {
            return 'source or translations is not an object';
        }
        if ($plan['translations'] === []) {
            return 'the plan has no translations, so there is nothing to link';
        }

        $posts = [];
        foreach (array_merge([$plan['source']], array_values($plan['translations'])) as $i => $p) {
            $where = $i === 0 ? 'source' : "translation $i";
            if (!is_array($p)) {
                return "$where is not an object";
            }
            foreach (['post_id', 'language_code', 'element_type'] as $key) {
                if (!array_key_exists($key, $p)) {
                    return "$where has no '$key'";
                }
            }
            if (is_bool($p['post_id']) || !is_int($p['post_id']) || $p['post_id'] <= 0) {
                return "$where has a post_id that is not a positive integer";
            }
            if (!is_string($p['language_code'])
                || !preg_match(self::LANGUAGE, $p['language_code'])) {
                return "$where has a language_code that is not a bare lowercase code";
            }
            // The WRITE spelling, which is what WPML's action expects. `page`
            // and `post_page` are different element types to WPML and the wrong
            // one matches nothing -- silently.
            if (!is_string($p['element_type']) || !str_starts_with($p['element_type'], 'post_')) {
                return "$where has an element_type that is not a `post_`-prefixed WPML type";
            }
            $src = $p['source_language_code'] ?? null;
            if ($src !== null && (!is_string($src) || !preg_match(self::LANGUAGE, $src))) {
                return "$where has a source_language_code that is not a bare lowercase code";
            }
            $p['source_language_code'] = $src;

            // WHETHER THE POST EXISTS, AND WHAT TYPE IT IS, ARE ASKED OF THE
            // SITE -- so they are NOT asked here. `validate_shape` runs before
            // the scope loop, and two site reads in it answered, for any post
            // id on the site, whether that post exists and what its real type
            // is: `names post 7, which does not exist on this site` against
            // `names post 7, whose type is 'page' and not 'post_zzz'` is an
            // enumeration oracle for every post on a client's WordPress,
            // reachable with a `translation.link` key alone. Moved to
            // `validate_against_site`, which runs AFTER scope.
            $posts[] = $p;
        }

        // ONE POST PER LANGUAGE, and one post named once. Two candidates for a
        // language is a coin flip whose losing side is served under a lying
        // hreflang; the same post twice would have it written into the group
        // under two languages.
        $by_language = [];
        $ids = [];
        foreach ($posts as $p) {
            if (isset($by_language[$p['language_code']])) {
                return "two posts claim language `{$p['language_code']}`; the mapping is ambiguous";
            }
            if (isset($ids[$p['post_id']])) {
                return "post {$p['post_id']} appears twice in this plan";
            }
            $by_language[$p['language_code']] = true;
            $ids[$p['post_id']] = true;
        }
        return $posts;
    }

    /**
     * The post's CURRENT translation group, read from WPML.
     *
     * Three outcomes, deliberately distinct: an int is the group, `null` means
     * WPML knows this element and it is in no group, and `false` means WPML
     * returned nothing usable -- which is not the same as "no group" and must
     * never be treated as one. Collapsing the last two is precisely the
     * destructive path.
     *
     * @return int|null|false
     */
    private static function current_trid(int $post_id, string $element_type) {
        // THE DEFAULT IS THE UNUSABLE ONE. `apply_filters` returns this
        // unchanged when nothing answers, so the default is what the code
        // believes about a silent site -- and `null` would make silence mean
        // "known, and in no group", which is the reading that writes.
        $details = apply_filters('wpml_element_language_details', false, [
            'element_id'   => $post_id,
            'element_type' => $element_type,
        ]);
        if ($details === null) {
            return null;
        }
        if (!is_object($details) || !isset($details->trid)) {
            return false;
        }
        $trid = $details->trid;
        if (is_bool($trid) || (!is_int($trid) && !ctype_digit((string) $trid))) {
            return false;
        }
        return (int) $trid;
    }
}
