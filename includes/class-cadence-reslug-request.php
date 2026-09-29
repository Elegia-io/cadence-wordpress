<?php
/**
 * Change the slug of a post this key reaches, or refuse.
 *
 * A SLUG IS AN ADDRESS OTHER PEOPLE HOLD. Changing it breaks every link to the
 * old one, so it is never part of a rewrite: it is its own route, it always
 * takes the client's signed confirmation (the same trio `/content/replace`
 * takes for an adopted post), and that confirmation is spent once, in the same
 * meta `/content/replace` spends its own in.
 *
 * AND IT IS BOUND TO THE SLUG THE CALLER SAW. `old_slug` must be the slug the
 * post holds now, read under the row lock; a slug changed by hand since is
 * refused, as a text edited by hand since is refused by `revision`.
 *
 * THE ANSWER IS THE SLUG WORDPRESS STORED, read back: core suffixes a slug
 * another post holds (`-2`), and the caller must learn the one it got.
 *
 * DIRECTION OF ERROR, as `/content/replace`: every refusal writes nothing, and
 * every precondition is checked before the single write.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceReslugRequest {

    public const REFUSAL_CODES = [
        'bad_reslug',
        CadenceAttestation::CODE,
        'post_out_of_scope',
        'identifier_mismatch',
        'post_adopted',
        'confirmation_wrong_site',
        'confirmation_expired',
        'confirmation_spent',
        'slug_mismatch',
        'no_row_lock',
        'update_failed',
    ];

    /** Every name the reply carries beside the envelope. */
    public const REPLY_FIELDS = ['post_id', 'slug'];

    /**
     * @param array       $body        `piece_id`, `post_id`, `old_slug`, `slug`, `overwrite_adopted`, `site`, `issued_at`.
     * @param array|null  $post_types  the types this key reaches, or null for any.
     * @param string|null $key_id      the asking key's public id.
     * @param string|null $attestation the signature header.
     */
    public static function run(array $body, ?array $post_types, ?string $key_id, ?string $attestation): array {
        $fields = self::validate($body);
        if (is_string($fields)) {
            return self::refuse('bad_reslug', $fields);
        }
        $signable = ['overwrite_adopted' => $fields['overwrite_adopted'] ? 'true' : 'false'] + $fields;
        $attested = CadenceAttestation::verify($attestation, '/content/reslug', $signable, $key_id);
        if ($attested['ok'] !== true) {
            return ['ok' => false, 'code' => $attested['code'], 'reason' => $attested['reason'],
                    'attestation_branch' => $attested['branch']];
        }
        // THE BOUNDARY is `NO_EXEMPTION`, which refuses first and names the
        // branch; this is the tripwire behind it.
        if (($attested['attestation'] ?? null) !== 'verified' || !isset($attested['kid'])) {
            return ['ok' => false, 'code' => CadenceAttestation::CODE, 'attestation_branch' => 'exempt_refused',
                    'reason' => 'this route takes no exemption; nothing was written'];
        }
        $post_id = $fields['post_id'];
        // ONE CODE AND ONE SENTENCE for a post that is not there, not a
        // connector piece, another key's, or out of this key's type scope:
        // the same gate `/content/replace` asks. Only the refusals below it,
        // over a post this key reaches, may say anything finer.
        if (!CadenceReplaceRequest::admits($post_id, $post_types, $key_id)) {
            return CadenceReplaceRequest::out_of_scope();
        }
        if (get_post_meta($post_id, CadenceContentRequest::META, true) !== $fields['piece_id']) {
            return self::refuse('identifier_mismatch', sprintf(
                'post %d is not `%s` on this site; nothing was written', $post_id, $fields['piece_id']));
        }
        if ($fields['overwrite_adopted'] !== true) {
            return self::refuse('post_adopted', sprintf(
                'changing the slug of post %d needs the client\'s confirmation, and this one says no; '
                . 'nothing was written', $post_id));
        }
        if ($fields['site'] !== CadenceAttestation::site()) {
            return self::refuse('confirmation_wrong_site',
                'the confirmation was signed for another site; nothing was written');
        }
        $at = CadenceAdoptRequest::issued_at($fields['issued_at']);
        if ($at === null || abs(time() - $at) > CadenceAdoptRequest::WINDOW) {
            return self::refuse('confirmation_expired', sprintf(
                'the confirmation is more than %d seconds from this site\'s clock; nothing was written',
                CadenceAdoptRequest::WINDOW));
        }
        $spent = hash('sha256', CadenceAttestation::material('/content/reslug', $signable));

        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            return self::refuse('no_row_lock', 'this site would not open a transaction, so the slug '
                . 'cannot be checked and written as one act; nothing was attempted');
        }
        try {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT post_name FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $post_id));
            if ($row === null) {
                return self::release($wpdb, CadenceReplaceRequest::out_of_scope());
            }
            clean_post_cache($post_id);
            wp_cache_delete($post_id, 'posts');
            wp_cache_delete($post_id, 'post_meta');
            // SPENT BEFORE THE SLUG: a replay after the slug was put back by
            // hand must still be refused as the replay it is.
            if (in_array($spent, (array) get_post_meta($post_id, CadenceReplaceRequest::SPENT_META, false), true)) {
                return self::release($wpdb, self::refuse('confirmation_spent', sprintf(
                    'this confirmation already changed the slug of post %d; a new change needs a new '
                    . 'confirmation, and nothing was written', $post_id)));
            }
            if ((string) $row->post_name !== $fields['old_slug']) {
                return self::release($wpdb, self::refuse('slug_mismatch', sprintf(
                    'post %d no longer has the slug `%s`; refusing to change an address the caller has '
                    . 'not seen, and nothing was written', $post_id, $fields['old_slug'])));
            }
            $id = wp_update_post(['ID' => $post_id, 'post_name' => $fields['slug']], true);
            if (is_wp_error($id) || !is_int($id) || $id !== $post_id) {
                return self::release($wpdb, self::refuse('update_failed', 'WordPress refused the update'
                    . (is_wp_error($id) ? ': ' . $id->get_error_message() : '')));
            }
            if (add_post_meta($id, CadenceReplaceRequest::SPENT_META, $spent) === false) {
                return self::release($wpdb, self::refuse('update_failed',
                    'the confirmation could not be recorded as spent, so the slug was not kept'));
            }
            clean_post_cache($id);
            $post = get_post($id);
            if (!$post instanceof WP_Post || $post->post_name === '') {
                return self::release($wpdb, self::refuse('update_failed',
                    'the post could not be read back after the change, so the slug was not kept'));
            }
            $wpdb->query('COMMIT');
            return ['ok' => true, 'created' => false, 'attestation' => 'verified',
                    'attestation_kid' => $attested['kid'],
                    'report' => ['post_id' => $id, 'slug' => $post->post_name]];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private static function release(object $wpdb, array $refusal): array {
        $wpdb->query('ROLLBACK');
        return $refusal;
    }

    private static function validate(array $body) {
        foreach (['piece_id', 'old_slug', 'slug', 'site', 'issued_at'] as $name) {
            if (!is_string($body[$name] ?? null) || trim($body[$name]) === '') {
                return sprintf('%s must be present and a non-blank string', $name);
            }
        }
        if (!is_int($body['post_id'] ?? null) || $body['post_id'] < 1) {
            return 'post_id must be a positive integer, and is never read from a string';
        }
        if (!is_bool($body['overwrite_adopted'] ?? null)) {
            return 'overwrite_adopted must be present and a JSON boolean';
        }
        if (CadenceAdoptRequest::issued_at($body['issued_at']) === null) {
            return 'issued_at must be a UTC ISO-8601 time';
        }
        return ['piece_id' => $body['piece_id'], 'post_id' => $body['post_id'],
                'old_slug' => $body['old_slug'], 'slug' => $body['slug'],
                'overwrite_adopted' => $body['overwrite_adopted'],
                'site' => $body['site'], 'issued_at' => $body['issued_at']];
    }

    private static function refuse(string $code, string $reason): array {
        return ['ok' => false, 'code' => $code, 'reason' => $reason];
    }
}
