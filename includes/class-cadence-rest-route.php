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
     * DOES THIS BODY NAME POSTS AT ALL, in the shape it claims to?
     *
     * What is LEFT of the old per-post `current_user_can('edit_post', $id)`
     * check, and deliberately so: a request authenticated by a Cadence key
     * carries no WordPress user, so a per-user capability question has nobody
     * to ask about and every answer would be "no". The authorisation now lives
     * in the key's own capability set (`CadenceKey::authorises`), and this is
     * the shape check that used to travel with it -- kept because a body this
     * cannot read must be refused before the handler reads it, and because the
     * ids it extracts are compared by test against the ids the handler writes.
     *
     * AND IT IS NOT THE AUTHORISATION EITHER, still. `translation.link` no
     * longer reaches any post on the site -- `CadenceKey::scope_admits`, called
     * from `CadenceLinkRequest::run`, refuses a post this plugin did not create
     * -- but that check is deliberately NOT duplicated here: this callback can
     * only answer true or false, and WordPress turns false into its own
     * `rest_forbidden` with no code a caller can match on. The refusal that
     * carries `post_out_of_scope` is the one at the write.
     */
    public static function names_posts(array $body): bool {
        $ids = self::post_ids($body);
        // No posts named is not "all of them". An empty request authorises
        // nothing, so there is nothing here to say yes to.
        return $ids !== null && $ids !== [];
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
            $body = ['ok' => true];
            // A NAMED SET, so a writer's new key does not reach the caller by
            // accident -- and does not fail to reach it silently either, since
            // `test_a_rewrite_answers_200_and_carries_the_new_revision` asks
            // for the one a replacement cannot be made without.
            foreach (['written', 'post_id', 'created', 'revision'] as $k) {
                if (array_key_exists($k, $result)) {
                    $body[$k] = $result[$k];
                }
            }
            // WHAT THE CALL ACTUALLY DID, when the handler can say. Merged
            // last, so `post_id` is the report's string form -- one field, one
            // type, rather than an int and a string under the same name in two
            // routes' answers.
            //
            // `ok` and `created` STAY. `ok` is the only field the refusal body
            // shares, so dropping it would leave a caller inferring success
            // from an HTTP status; and `created` is the fact that selects 201
            // from 200, which `placed` cannot carry -- a piece that was already
            // there is placed and was not created, and an idempotent retry has
            // to be able to say so.
            if (isset($result['report']) && is_array($result['report'])) {
                $body = array_merge($body, $result['report']);
            }
            // 201 only for something that came into existence. An idempotent
            // repeat is a 200: the caller asked for a post to exist, it does,
            // and nothing was created this time -- which `created` also says.
            return ['status' => ($result['created'] ?? false) === true ? 201 : 200, 'body' => $body];
        }

        $code = $result['code'] ?? null;
        return ['status' => self::STATUS[$code] ?? 500, 'body' => array_filter([
            'ok'     => false,
            'code'   => $code,
            'reason' => $result['reason'] ?? null,
        ], static fn ($v) => $v !== null)];
    }

    /**
     * EVERY REFUSAL CODE, AND THE ANSWER IT IS.
     *
     * An explicit table rather than three `in_array` branches with a default,
     * because a code's classification then has somewhere to be MISSING from --
     * `test_every_published_refusal_code_is_mapped` asks whether the code is a
     * key here, which it can answer. Inferring it from the status cannot: 500
     * is both "unclassified" and the honest answer for `insert_failed`, so a
     * test that keys on the number cannot tell a deliberate 500 from a code
     * nobody classified.
     *
     * 400 -- the request is wrong however many times it is sent.
     * 403 -- the key is genuine and may not act on what the request names.
     * 409 -- the request disagrees with this site; re-read and it may not.
     * 503 -- the site cannot do this at all; nothing about the request is wrong.
     * 500 -- this server tried and failed.
     *
     * 403 IS ITS OWN CLASS, not a 400 and not a 409. A 400 says the body is
     * wrong, which sends a caller to re-read its own JSON over a body that is
     * perfectly well formed; a 409 says re-read this site and try again, and
     * the retry can never succeed -- the post will not become one this
     * connector published by being asked about twice. What is wrong is neither
     * the request nor the site: it is that this credential does not reach that
     * post.
     */
    public const STATUS = [
        'bad_plan'                   => 400,
        'contradictory_instructions' => 400,
        'no_group_named'             => 400,
        'bad_request'                => 400,
        'bad_replacement'            => 400,
        'post_out_of_scope'          => 403,
        'capability_mismatch'        => 409,
        'unsupported_language'       => 409,
        'group_unknown'              => 409,
        'already_grouped'            => 409,
        'group_disagreement'         => 409,
        'post_missing'               => 409,
        'identifier_mismatch'        => 409,
        'revision_mismatch'          => 409,
        'wpml_unavailable'           => 503,
        'no_row_lock'                => 503,
        'insert_failed'              => 500,
        'update_failed'              => 500,
    ];

    /**
     * The post ids in the body, or null if the body is not the shape it claims.
     *
     * Public so the set this extracts can be compared BY TEST against the set
     * `CadenceLinkRequest` goes on to write. The two read the same body
     * independently, and nothing but that comparison stops them drifting.
     *
     * @return list<int>|null
     */
    public static function post_ids(array $body): ?array {
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
