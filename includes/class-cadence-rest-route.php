<?php
/**
 * The REST boundary: who may ask, and what the answer looks like.
 *
 * @package cadence-connector
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceRestRoute {

    /**
     * MAY THIS CALLER LINK THESE POSTS?
     *
     * Per post, never blanket. `edit_posts` (plural) is "may edit posts of this
     * type at all" and is held by a contributor; `edit_post` (singular, a meta
     * capability) is "may edit THIS one" and is the only question whose answer
     * matches what the request will actually change.
     *
     * The body is read in full before any question is asked, so a body this
     * cannot read produces no questions at all: `current_user_can('edit_post',
     * $id)` on a value PHP coerced is a question about a different post than
     * the one named, and its answer would be believed.
     *
     * @param array    $body The decoded request body.
     * @param callable $can  `fn(string $cap, mixed $id): bool`, i.e. current_user_can.
     */
    public static function permitted(array $body, callable $can): bool {
        $ids = self::post_ids($body);
        // No posts named is not "all of them are permitted". An empty request
        // authorises nothing, so there is nothing here to say yes to.
        if ($ids === null || $ids === []) {
            return false;
        }
        $permitted = true;
        foreach ($ids as $id) {
            // Not short-circuited: every named post is asked about, so the set
            // this authorised is the set the handler goes on to write.
            if (!$can('edit_post', $id)) {
                $permitted = false;
            }
        }
        return $permitted;
    }

    /**
     * WHICH HTTP ANSWER A RESULT FROM `CadenceLinkRequest::run` IS.
     *
     * Not a lookup with a friendly default: an unrecognised refusal is a 500,
     * because the only two things a default could say are both false. 400 tells
     * the caller its plan is wrong, which nothing here established; 200 tells
     * it the write happened, which it did not.
     *
     * @param array $result
     * @return array{status: int, body: array}
     */
    public static function respond(array $result): array {
        if (($result['ok'] ?? false) === true) {
            return ['status' => 200, 'body' => ['ok' => true, 'written' => $result['written']]];
        }

        // The plan is wrong on its face; sending it again cannot help.
        $wrong_plan = ['bad_plan', 'contradictory_instructions', 'no_group_named'];
        // The plan disagrees with this site; a fresh read might.
        $stale_plan = ['group_unknown', 'already_grouped', 'group_disagreement'];

        $code = $result['code'] ?? null;
        // Neither the caller's fault nor transient: the site is missing what
        // the request needs. 503 says so; 400 would blame the request and 409
        // would invite a retry that cannot succeed until someone installs it.
        if ($code === 'wpml_unavailable') {
            $status = 503;
        } elseif (in_array($code, $wrong_plan, true)) {
            $status = 400;
        } elseif (in_array($code, $stale_plan, true)) {
            $status = 409;
        } else {
            $status = 500;
        }
        return ['status' => $status, 'body' => array_filter([
            'ok'     => false,
            'code'   => $code,
            'reason' => $result['reason'] ?? null,
        ], static fn ($v) => $v !== null)];
    }

    /**
     * The post ids in the body, or null if the body is not the shape it claims.
     *
     * @return list<int>|null
     */
    private static function post_ids(array $body): ?array {
        // A body naming neither is not malformed -- it simply names no posts,
        // and is refused by the caller for that. Kept distinct from null so
        // that refusal has something to be reachable through.
        if (!isset($body['source']) && !isset($body['translations'])) {
            return [];
        }
        if (!isset($body['source']) || !is_array($body['source'])
            || !isset($body['translations']) || !is_array($body['translations'])) {
            return null;
        }
        // The source is a post like any other, and is written like any other,
        // so it is authorised like any other. It leads because that is the
        // order `run` writes in, and the two orders are compared by test.
        $posts = array_merge([$body['source']], $body['translations']);
        // A JSON object decodes to a PHP array too, so only the KEYS separate
        // the documented `[{...}, {...}]` from an `{"a": {...}}` that would
        // otherwise pass every per-post check below. `array_is_list` and not
        // `array_keys(...) === range(0, count - 1)`: `range(0, -1)` is `[0, -1]`,
        // so that spelling rejects the empty list as malformed and the
        // deliberate refusal below it never runs.
        if (!array_is_list($body['translations'])) {
            return null;
        }
        $ids = [];
        foreach ($posts as $p) {
            if (!is_array($p) || !isset($p['post_id'])) {
                return null;
            }
            $id = $p['post_id'];
            // `is_int` is false for '4', for 4.0 and -- unlike the same test in
            // several other languages -- for true. No separate bool check: a
            // guard that cannot fail is not a guard, and mutation says so.
            if (!is_int($id) || $id < 1) {
                return null;
            }
            $ids[] = $id;
        }
        return $ids;
    }
}
