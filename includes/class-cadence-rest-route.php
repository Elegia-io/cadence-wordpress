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
     * MAY THIS CALLER PUT THIS CONTENT ON THE SITE?
     *
     * Asked of the post TYPE's own capabilities, which WordPress derives from
     * the type's registration and which are not the same for every type. A
     * plugin hard-coding `edit_posts` would let anybody who may draft a blog
     * post write into a type whose entire purpose was that they may not.
     *
     * Publishing is a second question, not a louder version of the first: the
     * contributor role exists precisely to separate "may write this" from "may
     * put it in front of the public".
     *
     * @param array    $body The decoded request body.
     * @param callable $can  `fn(string $cap, mixed $id = null): bool`, i.e. current_user_can.
     */
    public static function may_publish(array $body, callable $can): bool {
        $type   = $body['post_type'] ?? null;
        $status = $body['status'] ?? null;
        if (!is_string($type) || !is_string($status)) {
            return false;
        }
        // Null for a type this site has not registered. Reading `->cap` off it
        // is a fatal in PHP 8 -- inside a permission callback, that is a 500 on
        // a route whose answer was always going to be "no".
        $object = get_post_type_object($type);
        if ($object === null || !isset($object->cap)) {
            return false;
        }
        if (!$can($object->cap->create_posts, null)) {
            return false;
        }
        if ($status === 'publish' && !$can($object->cap->publish_posts, null)) {
            return false;
        }
        return true;
    }

    /**
     * MAY THIS CALLER REWRITE THIS POST?
     *
     * `edit_post` on the one post the request names -- the linker's question,
     * not the content endpoint's. `create_posts`, which `may_publish` asks, is
     * the wrong question entirely here: the post already exists, somebody else
     * may have written it, and a caller who may only create would be rewriting
     * their work.
     *
     * One question covers the published case too, because WordPress maps the
     * `edit_post` meta capability onto `edit_published_posts` for a post that
     * is live. A second capability asked from here would have to be chosen
     * from the request, and the request does not say what the post's status is
     * -- the site does.
     *
     * @param array    $body The decoded request body.
     * @param callable $can  `fn(string $cap, mixed $id): bool`, i.e. current_user_can.
     */
    public static function may_replace(array $body, callable $can): bool {
        $id = $body['post_id'] ?? null;
        // Never coerced, and never asked about when it cannot be read:
        // `current_user_can('edit_post', '41 OR 1')` is a question about a
        // different post, and PHP would answer it.
        if (!is_int($id) || $id < 1) {
            return false;
        }
        return $can('edit_post', $id);
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
     * 409 -- the request disagrees with this site; re-read and it may not.
     * 503 -- the site cannot do this at all; nothing about the request is wrong.
     * 500 -- this server tried and failed.
     */
    public const STATUS = [
        'bad_plan'                   => 400,
        'contradictory_instructions' => 400,
        'no_group_named'             => 400,
        'bad_request'                => 400,
        'bad_replacement'            => 400,
        'group_unknown'              => 409,
        'already_grouped'            => 409,
        'group_disagreement'         => 409,
        'post_missing'               => 409,
        'identifier_mismatch'        => 409,
        'revision_mismatch'          => 409,
        'wpml_unavailable'           => 503,
        'insert_failed'              => 500,
        'update_failed'              => 500,
    ];

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
