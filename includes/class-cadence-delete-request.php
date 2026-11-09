<?php
/**
 * Delete one draft this connector created, for the key that created it, or refuse.
 *
 * WHY THIS EXISTS. A caller that publishes a test draft (the Cadence canary)
 * must be able to remove it again without a WordPress credential of its own.
 *
 * IT REACHES ONE KIND OF POST AND NO OTHER: a `draft` that `/content` itself
 * inserted (`CadenceContentRequest::CREATED_META`, the post's own id),
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
        'delete_wrong_site',
        'delete_expired',
        'delete_not_created',
        'delete_trash_disabled',
        'group_unknown',
        'delete_grouped',
        'delete_not_draft',
        'no_row_lock',
        'delete_failed',
    ];

    /** Every name the reply carries beside the envelope. */
    public const REPLY_FIELDS = ['post_id', 'post_status'];

    /**
     * @param array       $body        `piece_id`, `post_id`, `site`, `issued_at`.
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
        foreach (['site', 'issued_at'] as $name) {
            if (!is_string($body[$name] ?? null) || trim($body[$name]) === '') {
                return self::refuse('bad_delete', $name . ' must be present and a non-blank string; nothing was deleted');
            }
        }
        $fields = ['piece_id' => $body['piece_id'], 'post_id' => $body['post_id'],
                   'site' => $body['site'], 'issued_at' => $body['issued_at']];
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
        // WHICH SITE AND WHEN, signed: a captured request does not re-trash a
        // draft restored from the trash, nor serve on another site the key is on.
        if ($fields['site'] !== CadenceAttestation::site()) {
            return self::refuse('delete_wrong_site', 'the request was signed for another site; nothing was deleted');
        }
        $at = CadenceAdoptRequest::issued_at($fields['issued_at']);
        if ($at === null || abs(time() - $at) > CadenceAdoptRequest::WINDOW) {
            return self::refuse('delete_expired', sprintf(
                'issued_at is not a UTC ISO-8601 time within %d seconds of this site\'s clock; nothing was deleted',
                CadenceAdoptRequest::WINDOW));
        }
        $post_id = $fields['post_id'];
        // Not here, not a connector piece, or another key's: one code, one sentence.
        if (!CadenceReplaceRequest::admits($post_id, $post_types, $key_id)) {
            return ['ok' => false, 'code' => 'post_out_of_scope', 'reason' =>
                    'the post_id does not name a post this key published or adopted; nothing was deleted'];
        }
        // THIS KEY'S STAMP, EXACTLY: no null-identity path here. A post that
        // lost or never got its key stamp is no key's to delete.
        if (!is_string($key_id) || trim($key_id) === ''
                || get_post_meta($post_id, CadenceContentRequest::KEY_META, true) !== $key_id) {
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
        // Bound to the row: a copy of the stamp on another post holds another id.
        if (get_post_meta($post_id, CadenceContentRequest::CREATED_META, true) !== (string) $post_id
                || get_post_meta($post_id, CadenceAdoptRequest::ADOPTED_META, true) !== '') {
            return self::refuse('delete_not_created', sprintf(
                'post %d was not created by this connector (adopted, copied from another post, or created '
                . 'before the connector recorded creation); nothing was deleted', $post_id));
        }
        // WITH THE TRASH OFF, `wp_trash_post` ERASES: core calls
        // `wp_delete_post($id, true)` when `EMPTY_TRASH_DAYS` is 0. This route
        // only ever trashes, so it refuses rather than erase.
        if (!self::trash_kept(defined('EMPTY_TRASH_DAYS') ? (int) EMPTY_TRASH_DAYS : 0)) {
            return self::refuse('delete_trash_disabled', sprintf(
                'this site has the trash switched off (EMPTY_TRASH_DAYS is below 1), so trashing post %d '
                . 'would erase it; nothing was deleted', $post_id));
        }
        // ALONE IN ITS TRANSLATION GROUP, as `/adopt/release` asks: a trash-sync
        // bridge can trash a whole group with its draft, published posts
        // included. Asked only of a multilingual site; a silent WPML refuses.
        if (has_filter('wpml_element_language_details')) {
            $element_type = 'post_' . get_post_type($post_id);
            $trid = CadenceLinkRequest::current_trid($post_id, $element_type);
            $outside = $trid === null ? []
                : ($trid === false ? false
                    : CadenceLinkRequest::members_outside_plan($trid, $element_type, [['post_id' => $post_id]]));
            if ($outside === false) {
                return self::refuse('group_unknown', sprintf(
                    'WPML would not say which posts share post %d\'s translation group; nothing was deleted',
                    $post_id));
            }
            if ($outside !== []) {
                return self::refuse('delete_grouped', sprintf(
                    'post %d has translations (%s); trashing it could trash them too, so nothing was deleted',
                    $post_id, implode(', ', $outside)));
            }
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

    /** Whether `wp_trash_post` keeps a post, for an `EMPTY_TRASH_DAYS` of this value. */
    public static function trash_kept(int $days): bool {
        return $days >= 1;
    }

    private static function release(object $wpdb, array $refusal): array {
        $wpdb->query('ROLLBACK');
        return $refusal;
    }

    private static function refuse(string $code, string $reason): array {
        return ['ok' => false, 'code' => $code, 'reason' => $reason];
    }
}
