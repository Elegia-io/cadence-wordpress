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
 * @package CadenceConnector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// Loaded by WordPress, never fetched over HTTP: an include reached directly
// runs with none of the environment the code below assumes it has.
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ABSPATH') && !defined('CADENCE_CONNECTOR_TESTING')) {
    // Loaded outside WordPress and outside the suite: nothing to do.
    return;
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
        'wpml_unavailable',
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
     * @param array $plan the JSON body, already decoded
     * @return array{ok: bool, code?: string, reason?: string, written?: int}
     */
    public static function run(array $plan): array {
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

        foreach ($posts as $p) {
            do_action('wpml_set_element_language_details', [
                'element_id'           => $p['post_id'],
                'element_type'         => $p['element_type'],
                'trid'                 => $trid,
                'language_code'        => $p['language_code'],
                'source_language_code' => $p['source_language_code'],
            ]);
        }
        return ['ok' => true, 'written' => count($posts)];
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
