<?php
/**
 * Replace the text of a post this connector published, or refuse.
 *
 * THE FAILURE THIS IS SHAPED AROUND IS THE OVERWRITE. Somebody opened the
 * article in wp-admin and fixed a sentence. The pipeline, holding a copy it
 * read before that, sends the rewrite it had queued. Applied, the hand edit is
 * gone, no revision of it is offered anywhere the caller can see, and the
 * answer says the request succeeded -- because it did. That is the same class
 * of failure as a wrong translation link: it destroys work somebody did by
 * hand, and nothing reports it.
 *
 * SO INTENT IS STATED, NEVER INFERRED. `/content` refuses to update, and that
 * refusal is right for a retry and wrong for a rewrite; the two are separated
 * here by the caller saying which it means, at its own endpoint, rather than
 * by this code guessing from a body that differs. A caller asking to replace
 * names the post AND the revision it believes that post holds, and this runs
 * where the truth is: it re-reads the post and refuses when the two disagree.
 *
 * DIRECTION OF ERROR. A refused rewrite costs the caller one re-read. An
 * applied one costs a human work they cannot get back from here. Every refusal
 * below therefore writes nothing, and every precondition is checked before the
 * single write happens.
 *
 * @package cadence-connector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceReplaceRequest {

    /**
     * Every code `run` can refuse with. Published for the same reason the
     * other two writers publish theirs: so the REST layer can be tested for
     * covering all of them rather than the ones its own tests happened to name.
     */
    public const REFUSAL_CODES = [
        'bad_replacement',
        'post_missing',
        'identifier_mismatch',
        'revision_mismatch',
        'update_failed',
    ];

    /**
     * A REFUSAL CARRIES A CODE AS WELL AS A REASON. The reason is prose for a
     * human reading a log and is free to change. The code is the API, and it
     * tells the caller which of two things to do: re-read this site and try
     * again (`post_missing`, `identifier_mismatch`, `revision_mismatch` -- the
     * site disagrees with what the caller believed) or stop and fix the
     * request (`bad_replacement` -- no re-read can help).
     *
     * @param array $body the JSON body, already decoded
     * @return array{ok: bool, created?: bool, post_id?: int, revision?: string, code?: string, reason?: string}
     */
    public static function run(array $body): array {
        $fields = self::validate($body);
        if (is_string($fields)) {
            return ['ok' => false, 'code' => 'bad_replacement', 'reason' => $fields];
        }

        // READ THE POST BEFORE ANYTHING IS DECIDED ABOUT IT. `wp_update_post`
        // on an id that is not there does not fail in the way a caller would
        // expect, and every check below is a question about a post rather than
        // about the request.
        $post = get_post($fields['post_id']);
        if (!$post instanceof WP_Post) {
            return ['ok' => false, 'code' => 'post_missing', 'reason' => sprintf(
                'this site has no readable post %d, so there is nothing here to replace',
                $fields['post_id'])];
        }

        // THE POST AND THE PIECE HAVE TO BE THE SAME THING. The caller holds a
        // map from its own identifier to a WordPress post id; that map lives on
        // another machine and goes stale -- a restore from backup, a migration,
        // a post deleted and re-created -- and post 41 is then somebody else's
        // article, which this would rewrite while every other check agreed.
        $stored = get_post_meta($fields['post_id'], CadenceContentRequest::META, true);
        if ($stored !== $fields['external_id']) {
            return ['ok' => false, 'code' => 'identifier_mismatch', 'reason' => sprintf(
                'post %d is %s on this site, and this replacement is for `%s`',
                $fields['post_id'],
                is_string($stored) && $stored !== ''
                    ? '`' . $stored . '`'
                    : 'not a piece this connector published',
                $fields['external_id'])];
        }

        // AND THE TEXT HAS TO BE THE TEXT THE CALLER SAW. Read from the post,
        // not from the request: a revision the request carried on both sides
        // would be the caller's claim checked against the caller's claim.
        $actual = CadenceRevision::of($post->post_title, $post->post_content);
        if ($actual !== $fields['revision']) {
            return ['ok' => false, 'code' => 'revision_mismatch', 'reason' => sprintf(
                'post %d holds revision %s on this site, but this replacement is for %s; refusing to overwrite text the caller has not seen',
                $fields['post_id'], $actual, $fields['revision'])];
        }

        $id = wp_update_post([
            'ID'           => $fields['post_id'],
            'post_title'   => $fields['title'],
            'post_content' => $fields['content'],
            // NO `post_status`, AND NO IDENTIFIER. Whether the piece is in
            // front of the public is not a rewrite's decision: republishing
            // something a human took down is the same destruction one field
            // across, and it would be reported as a successful rewrite. The
            // identifier already on the post is what this request matched
            // against, so writing it again could only change it.
        ], true);

        // WP_Error, or 0, and neither is an exception -- exactly as on the
        // insert path. Read as an id, `0` is falsy, which is what "no post"
        // looks like everywhere else.
        if (is_wp_error($id)) {
            return ['ok' => false, 'code' => 'update_failed',
                    'reason' => 'WordPress refused the update: ' . $id->get_error_message()];
        }
        if (!is_int($id) || $id < 1) {
            return ['ok' => false, 'code' => 'update_failed',
                    'reason' => 'WordPress returned no post id and no error'];
        }

        // `created` is false and says so, rather than being left out: the two
        // endpoints answer in one vocabulary, and a caller reading `created`
        // never has to know which one it called.
        return array_merge(
            ['ok' => true, 'created' => false, 'post_id' => $id],
            CadenceRevision::answer($id)
        );
    }

    /**
     * The body's own shape, checked without coercion.
     *
     * @return array{external_id: string, post_id: int, revision: string, title: string, content: string}|string
     */
    private static function validate(array $body) {
        foreach (['external_id', 'revision', 'title', 'content'] as $key) {
            if (!isset($body[$key]) || !is_string($body[$key])) {
                return sprintf('%s must be present and a string', $key);
            }
        }
        if (trim($body['external_id']) === '') {
            return 'external_id must not be blank; it is what says which piece this replaces';
        }
        if (trim($body['revision']) === '') {
            return 'revision must not be blank; it is what says which text this replaces';
        }
        // Never coerced. `'41 OR 1'` is an integer to a loose check and a
        // different post to this site, and `true` is post 1.
        if (!is_int($body['post_id'] ?? null) || $body['post_id'] < 1) {
            return 'post_id must be a positive integer, and is never read from a string';
        }
        return [
            'external_id' => $body['external_id'],
            'post_id'     => $body['post_id'],
            'revision'    => $body['revision'],
            'title'       => $body['title'],
            'content'     => $body['content'],
        ];
    }
}
