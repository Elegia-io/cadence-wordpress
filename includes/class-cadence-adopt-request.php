<?php
/**
 * Bring a post this plugin did not create into one key's scope, so the
 * linking route can make it the source of a translation group.
 *
 * ADOPTION WRITES THREE META ROWS AND NOTHING ELSE. The post's title, text,
 * status and translation group are untouched: `_cadence_external_id` and
 * `_cadence_key` are the two rows `/content` writes on a post it creates, so
 * `CadenceKey::reaches` admits the adopted post to the linking route with no
 * change there, and `_cadence_adopted` is the record that says the post was
 * brought into scope rather than made, by which key and when.
 *
 * EVERY REFUSAL BELOW COMES AFTER THE SIGNATURE, and there is no unsigned
 * exemption on these routes. They answer finer than "out of scope" about
 * posts this plugin never wrote, so each answer must cost a signing key and
 * not a connector key alone.
 *
 * THE ORDER IS THE CONTRACT. One code per branch, in a fixed order, and
 * nothing is written before both claims are held. Every fact about a post
 * the key may not adopt is asked in one gate (`admits`) and answered with one
 * code, so a key learns nothing about a post outside it.
 *
 * @package cadence-connector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceAdoptRequest {

    /** The audit record: which key adopted the post, when, and what it was before. */
    public const ADOPTED_META = '_cadence_adopted';

    /** Every code `run` and `preview` can refuse with, in the order they are checked. */
    public const REFUSAL_CODES = [
        'bad_adoption',
        CadenceAttestation::CODE,
        'adopt_wrong_site',
        'adopt_expired',
        'adopt_link_unresolved',
        'wpml_unavailable',
        'adopt_types_unscoped',
        'adopt_post_out_of_scope',
        'post_already_identified',
        'adopt_repeat',
        'adopt_piece_taken',
        'group_unknown',
        'already_grouped',
        'language_disagreement',
        'adopt_busy',
        'adopt_failed',
    ];

    /**
     * Every code `release` can refuse with, in the order they are checked.
     * `release_preview` adds `adopt_link_unresolved` after the window.
     */
    public const RELEASE_REFUSAL_CODES = [
        'bad_release',
        CadenceAttestation::CODE,
        'adopt_wrong_site',
        'adopt_expired',
        'wpml_unavailable',
        'not_adopted',
        'post_already_identified',
        'group_unknown',
        'already_grouped',
        'adopt_failed',
    ];

    /** The three rows adoption writes, in the order a release removes them. */
    private const ROWS = [CadenceContentRequest::META, CadenceContentRequest::KEY_META, self::ADOPTED_META];

    /** The one sentence `adopt_post_out_of_scope` carries. It names no id. */
    public const OUT_OF_SCOPE_REASON =
        'the post is not one this key may adopt: it is absent, of a type or in a state this key may not '
        . 'adopt, one of the site\'s own pages, or already another key\'s; nothing was written';

    /** The one sentence a key's release carries for a post it did not adopt. It names no id. */
    public const NOT_ADOPTED_REASON =
        'the post_id does not name a post this key adopted, so there is nothing to release; nothing was changed';

    /**
     * THE STATUSES A POST MAY BE ADOPTED IN, an allow-list. Not in it: `trash`,
     * `auto-draft`, `inherit` (revisions, autosaves, attachments), `request-*`
     * and any status a plugin registers.
     */
    public const STATUSES = ['publish', 'draft', 'pending', 'future', 'private'];

    /** The options naming the site's own pages, which nothing here reaches. */
    public const SITE_PAGES = ['page_on_front', 'page_for_posts', 'wp_page_for_privacy_policy'];

    /** How far `issued_at` may be from this site's clock, either way, in seconds. */
    public const WINDOW = 300;

    /** How long a claim holds before another adopt may take it over, in seconds. */
    public const CLAIM_TTL = 60;

    private const LANGUAGE = '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/';

    private const ISSUED_AT = '/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,6})?(?:Z|\+00:00)\z/';

    /**
     * Adopt the post: rows 1 to 18.
     *
     * @param array       $body        `piece_id`, `post_id`, `language`, `site`, `issued_at`.
     * @param array|null  $post_types  The types the key names; null for a key issued blank.
     * @param string|null $key_id      The presenting key's public id.
     * @param string|null $attestation The signature header, or null when absent.
     */
    public static function run(array $body, ?array $post_types, ?string $key_id, ?string $attestation): array {
        $shape = self::shape($body, ['post_id', 'language']);
        if ($shape !== null) {
            return self::refuse('bad_adoption', $shape);
        }
        $fields = ['piece_id' => $body['piece_id'], 'post_id' => $body['post_id'],
                   'language' => $body['language'], 'site' => $body['site'],
                   'issued_at' => $body['issued_at']];
        $gate = self::gate('/adopt', $fields, $key_id, $attestation);
        if (isset($gate['code'])) {
            return $gate;
        }

        $post_id  = $body['post_id'];
        $piece_id = $body['piece_id'];
        $checked  = self::check($post_id, $piece_id, $post_types, $key_id, false);
        if (isset($checked['code'])) {
            return $checked;
        }
        // ROW 16: the site serves the post in the language the caller names.
        if ($checked['language'] !== $body['language']) {
            return self::refuse('language_disagreement', sprintf(
                'post %d is in %s on this site, but the request calls it %s; nothing was written',
                $post_id, $checked['language'], $body['language']));
        }

        // ROW 17: two claims, then the rows that another adopt could have
        // changed are asked again under them. A second adopt that ran to
        // completion between the checks above and the claims holds no claim
        // any more, so only this re-check sees it.
        $claims = [self::claim_name_post($post_id), self::claim_name_piece($piece_id, $key_id)];
        $held = [];
        foreach ($claims as $name) {
            if (!self::claim($name)) {
                self::release_claims($held);
                return self::refuse('adopt_busy', sprintf(
                    'another adoption holds post %d or this piece right now; nothing was written', $post_id));
            }
            $held[] = $name;
        }
        // THE RE-CHECK READS PAST THIS REQUEST'S OWN CACHES. A copy of the
        // post or its meta read before the claims cannot show what another
        // request wrote since, and the piece lookup is a query on the meta
        // table itself for the same reason.
        wp_cache_delete($post_id, 'posts');
        wp_cache_delete($post_id, 'post_meta');
        if (self::identified($post_id) || self::piece_elsewhere($piece_id, $post_id, $key_id)) {
            self::release_claims($held);
            return self::refuse('adopt_busy', sprintf(
                'another adoption reached post %d or this piece while this one was being checked; '
                . 'nothing was written', $post_id));
        }
        // Rows 7 to 9 again, on a fresh read: the post's type, status or
        // password may have changed since they were checked.
        $fresh = self::admits($post_id, $post_types, $key_id);
        if ($fresh === null) {
            self::release_claims($held);
            return self::out_of_scope();
        }
        $checked['post'] = $fresh;

        $written = self::write_rows($post_id, $piece_id, $key_id, $gate['kid'], $checked);
        self::release_claims($held);
        if ($written !== null) {
            return $written;
        }

        return ['ok' => true, 'attestation' => 'verified', 'attestation_kid' => $gate['kid'],
                'report' => self::reply(true, $piece_id, $checked, true)];
    }

    /**
     * ROW 18: three unique rows, all or nothing, in one transaction. The
     * record goes first and `_cadence_external_id` last, so a state the
     * rollback cannot undo still carries the record, and a release, which
     * only reaches a post with the record, can clear it. A lone
     * `_cadence_external_id` would read as a post this plugin created.
     * `add_post_meta` unslashes what it is given, so each value is slashed.
     *
     * @return array|null the refusal, or null when all three landed
     */
    private static function write_rows(int $post_id, string $piece_id, ?string $key_id, string $kid,
                                       array $checked): ?array {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            return self::refuse('adopt_failed', sprintf(
                'this site would not open a transaction for post %d; nothing was written', $post_id));
        }
        $record = wp_json_encode([
            'at'           => gmdate('Y-m-d\TH:i:s\Z'),
            'key'          => $key_id,
            'kid'          => $kid,
            'prior_status' => $checked['post']->post_status,
            'prior_trid'   => $checked['trid'],
        ]);
        $rows = [self::ADOPTED_META => $record, CadenceContentRequest::KEY_META => $key_id,
                 CadenceContentRequest::META => $piece_id];
        $written = [];
        foreach ($rows as $meta => $value) {
            if (!is_string($value) || add_post_meta($post_id, $meta, wp_slash($value), true) === false) {
                break;
            }
            $written[] = $meta;
        }
        $landed = count($written) === count($rows);
        wp_cache_delete($post_id, 'post_meta');
        foreach ($rows as $meta => $value) {
            $landed = $landed && get_post_meta($post_id, $meta, true) === $value;
        }
        if ($landed && $wpdb->query('COMMIT') !== false) {
            return null;
        }

        // UNDO, newest row first, and stop at the first delete that fails so
        // the record is never removed while another row stays.
        $wpdb->query('ROLLBACK');
        foreach (array_reverse(array_keys($rows)) as $meta) {
            if (in_array($meta, $written, true) && !delete_post_meta($post_id, $meta)) {
                break;
            }
        }
        wp_cache_delete($post_id, 'post_meta');
        if (self::identified($post_id)) {
            return self::refuse('adopt_failed', sprintf(
                'the adoption record for post %d could not be written, and part of it could not be removed; '
                . 'release the post, then adopt it again', $post_id));
        }
        return self::refuse('adopt_failed', sprintf(
            'the adoption record for post %d could not be written; nothing was left on the post', $post_id));
    }

    /**
     * Resolve the link and run rows 1 to 16 on the post it names. Writes
     * nothing.
     *
     * @param array $body `piece_id`, `link`, `site`, `issued_at`.
     */
    public static function preview(array $body, ?array $post_types, ?string $key_id, ?string $attestation): array {
        $shape = self::shape($body, ['link']);
        if ($shape !== null) {
            return self::refuse('bad_adoption', $shape);
        }
        $fields = ['piece_id' => $body['piece_id'], 'link' => $body['link'],
                   'site' => $body['site'], 'issued_at' => $body['issued_at']];
        $gate = self::gate('/adopt/preview', $fields, $key_id, $attestation);
        if (isset($gate['code'])) {
            return $gate;
        }
        // AFTER THE SIGNATURE: resolving a link reads the site.
        $post_id = self::resolve_link($body['link']);
        if ($post_id < 1) {
            return self::refuse('adopt_link_unresolved',
                'the link is not an edit link or a permalink of a post on this site; nothing was read');
        }
        $checked = self::check($post_id, $body['piece_id'], $post_types, $key_id, true);
        if (isset($checked['code'])) {
            return $checked;
        }
        return ['ok' => true, 'attestation' => 'verified', 'attestation_kid' => $gate['kid'],
                'report' => self::reply(false, $body['piece_id'], $checked, false)];
    }

    /**
     * LET AN ADOPTED POST GO: remove the three rows, in the order
     * `_cadence_external_id`, `_cadence_key`, `_cadence_adopted`, and nothing
     * else. The record goes last, so a failure part-way leaves it and a retry
     * is still a release. WPML relations are untouched.
     *
     * @param array $body `piece_id`, `post_id`, `site`, `issued_at`.
     */
    public static function release(array $body, ?string $key_id, ?string $attestation): array {
        $shape = self::shape($body, ['post_id']);
        if ($shape !== null) {
            return self::refuse('bad_release', $shape);
        }
        $fields = ['piece_id' => $body['piece_id'], 'post_id' => $body['post_id'],
                   'site' => $body['site'], 'issued_at' => $body['issued_at']];
        $gate = self::gate('/adopt/release', $fields, $key_id, $attestation);
        if (isset($gate['code'])) {
            return $gate;
        }
        $checked = self::check_release($body['post_id'], $body['piece_id'], $key_id);
        if (isset($checked['code'])) {
            return $checked;
        }
        return self::remove_rows($body['post_id'], $body['piece_id'], $gate['kid']);
    }

    /**
     * Resolve the link and run the release rows on the post it names, all but
     * the identifier (the preview names none). Writes nothing.
     *
     * @param array $body `link`, `site`, `issued_at`.
     */
    public static function release_preview(array $body, ?string $key_id, ?string $attestation): array {
        $shape = self::shape($body, ['link'], false);
        if ($shape !== null) {
            return self::refuse('bad_release', $shape);
        }
        $fields = ['link' => $body['link'], 'site' => $body['site'], 'issued_at' => $body['issued_at']];
        $gate = self::gate('/adopt/release/preview', $fields, $key_id, $attestation);
        if (isset($gate['code'])) {
            return $gate;
        }
        $post_id = self::resolve_link($body['link']);
        if ($post_id < 1) {
            return self::refuse('adopt_link_unresolved',
                'the link is not an edit link or a permalink of a post on this site; nothing was read');
        }
        $checked = self::check_release($post_id, null, $key_id);
        if (isset($checked['code'])) {
            return $checked;
        }
        $post = $checked['post'];
        return ['ok' => true, 'attestation' => 'verified', 'attestation_kid' => $gate['kid'],
                'report' => ['post_id' => $post->ID, 'post_type' => $post->post_type,
                             'title' => $post->post_title, 'status' => $post->post_status,
                             'language' => $checked['language']]];
    }

    /**
     * THE ADMINISTRATOR'S RELEASE, from the post row in wp-admin: the same
     * rows with no key and no signature, because the administrator is the
     * authority that issued the key. So there is no key or identifier check,
     * and a post whose adopting key was revoked can still be let go. The
     * caller checks `manage_options` and the nonce; this checks the post.
     */
    public static function release_by_admin(int $post_id): array {
        $checked = self::check_release($post_id, null, null);
        if (isset($checked['code'])) {
            return $checked;
        }
        $piece = get_post_meta($post_id, CadenceContentRequest::META, true);
        return self::remove_rows($post_id, is_string($piece) ? $piece : '', null);
    }

    /**
     * Release rows 5 to 10 on one post. `$key_id` null is the administrator,
     * who is not asked for a key; `$piece_id` null is a preview, which names
     * no identifier.
     *
     * @return array refusal, or {post: WP_Post, language: string}
     */
    private static function check_release(int $post_id, ?string $piece_id, ?string $key_id): array {
        // ROW 5: without WPML the group check cannot run, and a release that
        // cannot check refuses.
        if (!has_filter('wpml_element_language_details') || !has_action('wpml_set_element_language_details')) {
            return self::refuse('wpml_unavailable',
                'nothing on this site implements the WPML translation-group hooks, so nothing can be released');
        }
        // ROWS 6 AND 7, ONE GATE FOR A KEY: the post exists and carries an
        // adoption record naming this key. An absent post, a post with no
        // record and a post another key adopted all answer `not_adopted` with
        // one fixed sentence that names no id, so a key cannot sort post ids
        // into "absent" and "someone else's". The administrator, who issued
        // every key, still gets `post_missing` apart, with the id. A post
        // this plugin created carries no record and is never un-stamped here.
        $post = get_post($post_id);
        if ($key_id !== null) {
            if (!$post instanceof WP_Post || !self::adopted_by($post_id, $key_id)) {
                return self::refuse('not_adopted', self::NOT_ADOPTED_REASON);
            }
        } else {
            if (!$post instanceof WP_Post) {
                return self::refuse('post_missing', sprintf('there is no readable post %d on this site', $post_id));
            }
            $raw = get_post_meta($post_id, self::ADOPTED_META, true);
            if (!is_string($raw) || $raw === '') {
                return self::refuse('not_adopted', sprintf(
                    'post %d was not adopted, so there is nothing to release; nothing was changed', $post_id));
            }
        }
        // ROW 8: this key's adoption, of this piece. A row already removed by
        // a release that failed part-way is not a mismatch, so a retry passes;
        // the record, removed last, still names the key.
        if ($key_id !== null) {
            $stamp = get_post_meta($post_id, CadenceContentRequest::KEY_META, true);
            $piece = get_post_meta($post_id, CadenceContentRequest::META, true);
            if (($stamp !== '' && $stamp !== $key_id)
                    || ($piece_id !== null && $piece !== '' && $piece !== $piece_id)) {
                return self::refuse('post_already_identified', sprintf(
                    'post %d already carries a piece identity; nothing was changed', $post_id));
            }
        }
        // ROW 9.
        $element_type = 'post_' . $post->post_type;
        $trid = CadenceLinkRequest::current_trid($post_id, $element_type);
        $language = CadenceLinkRequest::current_language($post_id, $element_type);
        if ($trid === false || $language === null) {
            return self::refuse('group_unknown', sprintf(
                'WPML reports no language for post %d; nothing was changed', $post_id));
        }
        // ROW 10: alone in its group, counting drafts. Otherwise the group's
        // source would leave scope while the group persists.
        if ($trid !== null) {
            $outside = CadenceLinkRequest::members_outside_plan($trid, $element_type, [['post_id' => $post_id]]);
            if ($outside === false) {
                return self::refuse('group_unknown', sprintf(
                    'WPML would not say which posts share post %d\'s translation group; nothing was changed',
                    $post_id));
            }
            if ($outside !== []) {
                return self::refuse('already_grouped', sprintf(
                    'post %d has translations; remove it from its translation group first, then release it; '
                    . 'nothing was changed', $post_id));
            }
        }
        return ['post' => $post, 'language' => $language];
    }

    /** Row 11: remove the rows that are there, in order, and re-read that none is left. */
    private static function remove_rows(int $post_id, string $piece_id, ?string $kid): array {
        foreach (self::ROWS as $meta) {
            if (get_post_meta($post_id, $meta, true) === '') {
                continue;
            }
            if (!delete_post_meta($post_id, $meta)) {
                break;
            }
        }
        foreach (self::ROWS as $meta) {
            if (get_post_meta($post_id, $meta, true) !== '') {
                return self::refuse('adopt_failed', sprintf(
                    'the adoption record for post %d could not be removed in full; release it again', $post_id));
            }
        }
        $done = ['ok' => true, 'report' => ['released' => true, 'piece_id' => $piece_id, 'post_id' => $post_id]];
        return $kid === null ? $done : $done + ['attestation' => 'verified', 'attestation_kid' => $kid];
    }

    /**
     * THE POST A LINK NAMES, or 0. The wp-admin edit link (`post.php?post=N`)
     * is read here and answers 0 on any host but the admin's own; everything
     * else goes to core's `url_to_postid`, which answers 0 for another host
     * and for a path that is no post's. One URL, one answer or none: never a
     * search.
     */
    public static function resolve_link(string $link): int {
        // A fragment is not part of what names a post, and a link carrying
        // one is not the link a human was shown.
        if (str_contains($link, '#')) {
            return 0;
        }
        $parts = wp_parse_url($link);
        $admin = wp_parse_url(admin_url());
        if (!is_array($parts) || !is_array($admin)) {
            return 0;
        }
        if (($parts['path'] ?? '') === ($admin['path'] ?? '') . 'post.php') {
            if (strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($admin['host'] ?? ''))) {
                return 0;
            }
            parse_str((string) ($parts['query'] ?? ''), $query);
            $id = $query['post'] ?? null;
            return is_string($id) && ctype_digit($id) ? (int) $id : 0;
        }
        return (int) url_to_postid($link);
    }

    /** Row 1: the body's own shape. Reads nothing. The release preview names no piece. */
    private static function shape(array $body, array $own, bool $piece = true): ?string {
        foreach ($piece ? ['piece_id', 'site', 'issued_at'] : ['site', 'issued_at'] as $name) {
            if (!is_string($body[$name] ?? null) || trim($body[$name]) === '') {
                return $name . ' is not a non-blank string';
            }
        }
        if (in_array('post_id', $own, true) && (!is_int($body['post_id'] ?? null) || $body['post_id'] < 1)) {
            return 'post_id is not a positive JSON integer';
        }
        if (in_array('language', $own, true)
                && (!is_string($body['language'] ?? null) || preg_match(self::LANGUAGE, $body['language']) !== 1)) {
            return 'language is not a WPML language code';
        }
        if (in_array('link', $own, true) && (!is_string($body['link'] ?? null) || trim($body['link']) === '')) {
            return 'link is not a non-blank string';
        }
        if (self::issued_at($body['issued_at']) === null) {
            return 'issued_at is not a UTC ISO-8601 time';
        }
        return null;
    }

    /** A UTC ISO-8601 instant as a timestamp, or null. `/content/replace` reads its confirmation with it. */
    public static function issued_at(string $value): ?int {
        if (preg_match(self::ISSUED_AT, $value, $m) !== 1) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $m[1], new DateTimeZone('UTC'));
        return $at !== false && $at->format('Y-m-d\TH:i:s') === $m[1] ? $at->getTimestamp() : null;
    }

    /** Rows 2 to 4: the signature, then which site and when. */
    private static function gate(string $route, array $fields, ?string $key_id, ?string $attestation): array {
        $attested = CadenceAttestation::verify($attestation, $route, $fields, $key_id);
        if ($attested['ok'] !== true) {
            return ['ok' => false, 'code' => $attested['code'], 'reason' => $attested['reason'],
                    'attestation_branch' => $attested['branch']];
        }
        // THE BOUNDARY ON THESE ROUTES: only a verified signature passes. The
        // `NO_EXEMPTION` set in `CadenceAttestation::verify` is what refuses
        // first and names the branch; this refuses whatever that set says.
        if (($attested['attestation'] ?? null) !== 'verified' || !isset($attested['kid'])) {
            return ['ok' => false, 'code' => CadenceAttestation::CODE, 'attestation_branch' => 'exempt_refused',
                    'reason' => 'this route takes no exemption; nothing was read or written'];
        }
        if ($fields['site'] !== CadenceAttestation::site()) {
            return self::refuse('adopt_wrong_site',
                'the request was signed for another site; nothing was read or written');
        }
        $at = self::issued_at($fields['issued_at']);
        if ($at === null || abs(time() - $at) > self::WINDOW) {
            return self::refuse('adopt_expired', sprintf(
                'issued_at is more than %d seconds from this site\'s clock; nothing was read or written',
                self::WINDOW));
        }
        return ['kid' => $attested['kid']];
    }

    /**
     * Rows 5 to 15 on one post. On success, what the replies carry.
     *
     * @return array refusal, or {post: WP_Post, type: string, trid: int|null, language: string}
     */
    private static function check(int $post_id, string $piece_id, ?array $post_types, ?string $key_id,
                                  bool $preview): array {
        // ROW 5.
        if (!has_filter('wpml_element_language_details') || !has_action('wpml_set_element_language_details')) {
            return self::refuse('wpml_unavailable',
                'nothing on this site implements the WPML translation-group hooks, so nothing can be adopted');
        }
        // ROW 6: a key issued blank publishes anywhere and adopts nothing.
        if ($post_types === null || $post_types === []) {
            return self::refuse('adopt_types_unscoped',
                'this key names no post types, and adopting needs a key that names the types it may reach');
        }
        // ROWS 7 TO 11, ONE GATE, ONE CODE, ONE SENTENCE. See `admits`.
        $post = self::admits($post_id, $post_types, $key_id);
        if ($post === null) {
            return self::out_of_scope();
        }
        $type = $post->post_type;
        $element_type = 'post_' . $type;
        // ROWS 11 AND 12: this key's own rows, except the exact repeat. Only
        // a post the gate admitted reaches here, so the rows are this key's
        // and naming them discloses nothing the key does not own.
        if (self::identified($post_id)) {
            if (!self::is_repeat($post_id, $piece_id, $key_id)) {
                return self::refuse('post_already_identified', sprintf(
                    'post %d already carries a piece identity; nothing was written', $post_id));
            }
            $language = CadenceLinkRequest::current_language($post_id, $element_type);
            return ['ok' => false, 'code' => 'adopt_repeat',
                    'reason' => sprintf('post %d is already adopted under this piece by this key', $post_id),
                    'report' => self::reply(false, $piece_id, ['post' => $post, 'language' => $language],
                                            !$preview)];
        }
        // ROW 13: this key's piece on another post. See `piece_elsewhere`.
        if (self::piece_elsewhere($piece_id, $post_id, $key_id)) {
            return self::refuse('adopt_piece_taken',
                'this piece is already on another post this key published or adopted; nothing was written');
        }
        // ROW 14.
        $trid = CadenceLinkRequest::current_trid($post_id, $element_type);
        $language = CadenceLinkRequest::current_language($post_id, $element_type);
        if ($trid === false || $language === null) {
            return self::refuse('group_unknown', sprintf(
                'WPML reports no language for post %d; nothing was written', $post_id));
        }
        // ROW 15: alone in its group, counting drafts.
        if ($trid !== null) {
            $outside = CadenceLinkRequest::members_outside_plan($trid, $element_type, [['post_id' => $post_id]]);
            if ($outside === false) {
                return self::refuse('group_unknown', sprintf(
                    'WPML would not say which posts share post %d\'s translation group; nothing was written',
                    $post_id));
            }
            if ($outside !== []) {
                return self::refuse('already_grouped', sprintf(
                    'post %d already has translations; nothing was written', $post_id));
            }
        }
        return ['post' => $post, 'trid' => $trid, 'language' => $language];
    }

    /**
     * THE POST THIS KEY MAY ADOPT, or null. Rows 7 to 11 asked as ONE
     * predicate, the form the adopt routes answer in:
     *
     * - ROW 7: the post exists;
     * - ROW 8: its type is one the key names AND a viewable one. The null
     *   scope asks `is_content_type` for viewability whatever the key names,
     *   which is stricter than the other routes and is what adopt requires;
     * - ROW 9: its status is in the allow-list, and it has no password;
     * - ROW 10: it is not one of the site's own pages, compared as ints;
     * - ROW 11: it carries no Cadence row, or only this key's.
     *
     * The refusal is `adopt_post_out_of_scope` for every one of them, with
     * one fixed sentence and no id. They used to answer `post_missing`,
     * `adopt_post_type_out_of_scope`, `adopt_post_unavailable`,
     * `adopt_site_page` and `post_already_identified` apart, and the
     * difference sorted any post id into absent, another tenant's piece, a
     * page, or a type, one signed request at a time. A signature in front
     * narrows who can ask; it does not make the partition safe to hand back.
     * The operator adopting a post sees it in wp-admin, and the sentence
     * lists what to look for there.
     */
    private static function admits(int $post_id, array $post_types, ?string $key_id): ?WP_Post {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post
                || !in_array($post->post_type, $post_types, true)
                || !CadenceKey::is_content_type($post->post_type, null)
                || !in_array($post->post_status, self::STATUSES, true)
                || $post->post_password !== '') {
            return null;
        }
        foreach (self::SITE_PAGES as $option) {
            if ((int) get_option($option) === $post_id) {
                return null;
            }
        }
        if (self::identified($post_id) && !self::mine($post_id, $key_id)) {
            return null;
        }
        return $post;
    }

    /** @return array{ok: false, code: string, reason: string} */
    public static function out_of_scope(): array {
        return self::refuse('adopt_post_out_of_scope', self::OUT_OF_SCOPE_REASON);
    }

    /**
     * WHETHER THE POST'S CADENCE ROWS ARE THIS KEY'S: its stamp names this
     * key, or its adoption record does. A post with a piece identity and no
     * stamp (one that predates the stamp) is nobody's here, so it is refused
     * like another key's: adopting it was never possible.
     */
    private static function mine(int $post_id, ?string $key_id): bool {
        return is_string($key_id)
            && (get_post_meta($post_id, CadenceContentRequest::KEY_META, true) === $key_id
                || self::adopted_by($post_id, $key_id));
    }

    /** Whether the post's adoption record names this key. */
    private static function adopted_by(int $post_id, string $key_id): bool {
        $raw = get_post_meta($post_id, self::ADOPTED_META, true);
        $record = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($record) && ($record['key'] ?? null) === $key_id;
    }

    /** Whether the post carries any of the three rows. */
    private static function identified(int $post_id): bool {
        foreach ([CadenceContentRequest::META, CadenceContentRequest::KEY_META, self::ADOPTED_META] as $meta) {
            $value = get_post_meta($post_id, $meta, true);
            if ($value !== '' && $value !== null && $value !== false && $value !== []) {
                return true;
            }
        }
        return false;
    }

    /** Same piece, this key's stamp, and an adoption record naming this key. */
    private static function is_repeat(int $post_id, string $piece_id, ?string $key_id): bool {
        if (!is_string($key_id) || get_post_meta($post_id, CadenceContentRequest::META, true) !== $piece_id
                || get_post_meta($post_id, CadenceContentRequest::KEY_META, true) !== $key_id) {
            return false;
        }
        return self::adopted_by($post_id, $key_id);
    }

    /**
     * WHETHER THIS KEY'S PIECE IS ON ANY OTHER POST, in ANY status and ANY
     * type. A query on the meta table itself: no status or type filter can
     * leave a post out, and no cached result from earlier in this request
     * answers for it. A failed query counts as found.
     *
     * ONLY A POST THIS KEY MADE OR ADOPTED COUNTS, asked by
     * `CadenceKey::created_by` as `/content` asks it when it looks a piece
     * up. The piece id is the caller's name for its own piece, and two keys
     * on one site may choose the same string for two different pieces. A
     * piece on another key's post answered `adopt_piece_taken` apart from
     * every other case, so any key that may adopt could ask whether a piece
     * id exists anywhere on the site, one request at a time. It now answers
     * as if no post carried it. A post with no stamp (one that predates the
     * stamp) still counts for every key, as it does on `/content`, so one
     * piece id is never on two of this key's posts.
     */
    private static function piece_elsewhere(string $piece_id, int $post_id, ?string $key_id): bool {
        global $wpdb;
        $found = $wpdb->get_col($wpdb->prepare(
            "SELECT `post_id` FROM `{$wpdb->postmeta}` WHERE `meta_key` = %s AND `meta_value` = %s",
            CadenceContentRequest::META, $piece_id));
        if (!is_array($found) || $wpdb->last_error !== '') {
            return true;
        }
        foreach ($found as $id) {
            if ((int) $id === $post_id) {
                continue;
            }
            // The stamp is read past this request's cache too, for the
            // re-check under the claims.
            wp_cache_delete((int) $id, 'post_meta');
            if (CadenceKey::created_by((int) $id, $key_id)) {
                return true;
            }
        }
        return false;
    }

    private static function claim_name_post(int $post_id): string {
        return 'cadence_adopt_post_' . $post_id;
    }

    /**
     * THE PIECE CLAIM IS THIS KEY'S, as the piece id is. A claim shared by
     * every key would make one key's adopt busy while another key adopts the
     * same string, telling it so and letting it hold the other off. Two
     * adopts of one piece by one key still take the same claim.
     */
    private static function claim_name_piece(string $piece_id, ?string $key_id): string {
        return 'cadence_adopt_piece_' . substr(hash('sha256', (string) $key_id . "\0" . $piece_id), 0, 32);
    }

    /**
     * ONE CLAIM, `INSERT IGNORE` into the options table: the shape
     * `WP_Upgrader::create_lock` uses. `add_option` inserts with `ON DUPLICATE
     * KEY UPDATE`, so two callers would both succeed. A claim older than
     * `CLAIM_TTL` is from a request that died holding it, and is taken over.
     */
    private static function claim(string $name, bool $retry = true): bool {
        global $wpdb;
        $got = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
            $name, (string) time()));
        if ($got) {
            return true;
        }
        // THE TABLE, NOT `get_option`: a persistent object cache can hold this
        // name in `notoptions` from a read made before the claim was written,
        // and a crashed request's claim would then never be taken over.
        $since = $wpdb->get_var($wpdb->prepare(
            "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", $name));
        if (!$retry || $since === null || (int) $since > time() - self::CLAIM_TTL) {
            return false;
        }
        // COMPARE-AND-DELETE: only the stale claim this request read goes. A
        // request that took it over first holds a fresh value, and this one
        // then deletes nothing and stops.
        $gone = $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s",
            $name, (string) $since));
        if ((int) $gone !== 1) {
            return false;
        }
        return self::claim($name, false);
    }

    private static function release_claims(array $names): void {
        foreach ($names as $name) {
            delete_option($name);
        }
    }

    private static function reply(bool $adopted, string $piece_id, array $checked, bool $content): array {
        $post = $checked['post'];
        return ['adopted' => $adopted, 'piece_id' => $piece_id, 'post_id' => $post->ID,
                'language' => $checked['language'], 'post_type' => $post->post_type,
                'title' => $post->post_title]
            + ($content ? ['content' => $post->post_content] : [])
            + ['status' => $post->post_status];
    }

    private static function refuse(string $code, string $reason): array {
        return ['ok' => false, 'code' => $code, 'reason' => $reason];
    }
}
