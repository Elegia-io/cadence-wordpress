<?php
/**
 * Delete one draft this connector created, for the key that created it, or refuse.
 *
 * WHY THIS EXISTS. A caller that publishes a test draft (the Cadence canary)
 * must be able to remove it again without a WordPress credential of its own.
 *
 * IT REACHES ONE KIND OF POST AND NO OTHER: a `draft` that `/content` itself
 * inserted (`CadenceContentRequest::CREATED_META`, written in the insert),
 * for the key that inserted it, of a type in the key's scope. An adopted post
 * carries the connector's other stamps but never the creation one, so it is
 * refused, as is a published, scheduled, pending or private post. Each
 * refusal answers its own code, which names the branch that fired; only the
 * first gate keeps the one shared `post_out_of_scope`, so a key cannot map
 * which ids exist on a site it shares with another tenant.
 *
 * TRASHED, NOT ERASED: `wp_trash_post`, as WordPress's own REST delete does
 * without `force`. A draft an editor was still working on is one restore away,
 * and the trash empties itself after `EMPTY_TRASH_DAYS`.
 *
 * DIRECTION OF ERROR, as `/content/reslug`: every refusal writes nothing, and
 * the status is read again under the row lock before the one write, so a draft
 * published by hand in the meantime is refused rather than trashed.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceDeleteRequest {

    public const REFUSAL_CODES = [
        'bad_delete',
        CadenceAttestation::CODE,
        'post_out_of_scope',
        'identifier_mismatch',
        'existing_post_type_out_of_scope',
        'delete_not_created',
        'delete_not_draft',
        'no_row_lock',
        'delete_failed',
    ];

    /** Every name the reply carries beside the envelope. */
    public const REPLY_FIELDS = ['post_id', 'post_status'];

    /**
     * @param array       $body        `piece_id`, `post_id`.
     * @param array|null  $post_types  the types this key reaches, or null for any.
     * @param string|null $key_id      the asking key's public id.
     * @param string|null $attestation the signature header.
     */
    public static function run(array $body, ?array $post_types, ?string $key_id, ?string $attestation): array {
        if (!is_string($body['piece_id'] ?? null) || trim($body['piece_id']) === '') {
            return self::refuse('bad_delete', 'piece_id must be present and a non-blank string; nothing was deleted');
        }
        if (!is_int($body['post_id'] ?? null) || $body['post_id'] < 1) {
            return self::refuse('bad_delete',
                'post_id must be present and a positive integer, and is never read from a string; nothing was deleted');
        }
        $fields = ['piece_id' => $body['piece_id'], 'post_id' => $body['post_id']];
        $attested = CadenceAttestation::verify($attestation, '/content/delete', $fields, $key_id);
        if ($attested['ok'] !== true) {
            return ['ok' => false, 'code' => $attested['code'], 'reason' => $attested['reason'],
                    'attestation_branch' => $attested['branch']];
        }
        // THE BOUNDARY is `NO_EXEMPTION`, which refuses first and names the
        // branch; this is the tripwire behind it.
        if (($attested['attestation'] ?? null) !== 'verified' || !isset($attested['kid'])) {
            return ['ok' => false, 'code' => CadenceAttestation::CODE, 'attestation_branch' => 'exempt_refused',
                    'reason' => 'this route takes no exemption; nothing was deleted'];
        }
        $post_id = $fields['post_id'];
        // Not here, not a connector piece, or another key's: one code, one sentence.
        if (!CadenceReplaceRequest::admits($post_id, $post_types, $key_id)) {
            return ['ok' => false, 'code' => 'post_out_of_scope', 'reason' =>
                    'the post_id does not name a post this key published or adopted; nothing was deleted'];
        }
        if (get_post_meta($post_id, CadenceContentRequest::META, true) !== $fields['piece_id']) {
            return self::refuse('identifier_mismatch', sprintf(
                'post %d is not `%s` on this site; nothing was deleted', $post_id, $fields['piece_id']));
        }
        if ($post_types !== null && !in_array(get_post_type($post_id), $post_types, true)) {
            return self::refuse('existing_post_type_out_of_scope', sprintf(
                'post %d is of a type this key does not publish into; this key publishes into %s, '
                . 'and nothing was deleted', $post_id, implode(', ', $post_types)));
        }
        // THE CREATION RECORD, which only `/content`'s insert writes. An adopted
        // post, or one created before the record existed, has none.
        if (get_post_meta($post_id, CadenceContentRequest::CREATED_META, true) !== '1') {
            return self::refuse('delete_not_created', sprintf(
                'post %d was not created by this connector (adopted, or created before the connector '
                . 'recorded creation); nothing was deleted', $post_id));
        }

        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            return self::refuse('no_row_lock', 'this site would not open a transaction, so the status '
                . 'cannot be checked and the post trashed as one act; nothing was attempted');
        }
        try {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $post_id));
            if ($row === null) {
                return self::release($wpdb, self::refuse('delete_failed', sprintf(
                    'post %d could not be read under the row lock; nothing was deleted', $post_id)));
            }
            $status = (string) $row->post_status;
            if ($status !== 'draft') {
                return self::release($wpdb, self::refuse('delete_not_draft', sprintf(
                    'post %d is `%s`, not `draft`; only a draft is ever deleted, and nothing was deleted',
                    $post_id, $status)));
            }
            $trashed = wp_trash_post($post_id);
            clean_post_cache($post_id);
            $after = get_post($post_id);
            if (!$trashed instanceof WP_Post || !$after instanceof WP_Post || $after->post_status !== 'trash') {
                return self::release($wpdb, self::refuse('delete_failed', sprintf(
                    'WordPress did not move post %d to the trash; nothing was deleted', $post_id)));
            }
            $wpdb->query('COMMIT');
            return ['ok' => true, 'created' => false, 'attestation' => 'verified',
                    'attestation_kid' => $attested['kid'],
                    'report' => ['post_id' => $post_id, 'post_status' => 'trash']];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private static function release(object $wpdb, array $refusal): array {
        $wpdb->query('ROLLBACK');
        return $refusal;
    }

    private static function refuse(string $code, string $reason): array {
        return ['ok' => false, 'code' => $code, 'reason' => $reason];
    }
}
