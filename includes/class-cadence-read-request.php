<?php
/**
 * Read one post's text back to the key that may rewrite it, or refuse.
 *
 * WHY THIS EXISTS. A rewrite names the revision it replaces, and the caller
 * can only name a revision it has seen. `/content/replace` refuses a stale
 * one; this is where the current one comes from, for a post the caller did
 * not keep a copy of (an adopted post, above all).
 *
 * WHO MAY READ WHAT. The route is authorised on `content.replace`, and the post
 * must be one this key reaches (`admits`): Cadence published or adopted it
 * (`scope_admits`), this key and not another tenant's made it (`created_by`),
 * its type is in the key's post-type scope, exactly as `/content/replace`
 * asks, and its status is one Cadence places a post in (never `trash`,
 * `auto-draft` or `inherit`). A password-protected post is never read, as
 * `/adopt` never takes one.
 *
 * NO EXISTENCE ORACLE. A link that names no post, a post that is not here and
 * a post this key does not reach all answer ONE code and ONE sentence, which
 * names no id: a distinct "missing" would let a key map which ids exist on a
 * site it shares with another tenant. The same predicate filters
 * `translations`, so the group never lists a post this key could not read.
 *
 * THE REQUEST IS SIGNED, THE REPLY IS NOT. The signature over `link` proves
 * who asked; the text travels back under TLS only, as `/adopt/preview`'s does.
 * The route takes no unsigned-publish exemption (`NO_EXEMPTION`).
 *
 * IT WRITES NOTHING: no post row, no meta, no WPML relation.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceReadRequest {

    public const REFUSAL_CODES = [
        'bad_read',
        CadenceAttestation::CODE,
        'post_out_of_scope',
        'read_password_protected',
        'group_unknown',
    ];

    /** Every name the reply carries beside the envelope. A caller asserts each one present. */
    public const REPLY_FIELDS = ['post_id', 'piece_id', 'language', 'title', 'content', 'excerpt',
                                 'seo_title', 'seo_description', 'slug', 'revision', 'translations'];

    /**
     * WHERE THE SEO TEXT LIVES: the meta keys Yoast SEO reads. `/content/replace`
     * writes the same two keys, so what this answers is what a rewrite set.
     */
    public const SEO_META = ['seo_title' => '_yoast_wpseo_title',
                             'seo_description' => '_yoast_wpseo_metadesc'];

    /**
     * @param array       $body        `link`.
     * @param array|null  $post_types  the types this key reaches, or null for any viewable type.
     * @param string|null $key_id      the asking key's public id.
     * @param string|null $attestation the signature header.
     */
    public static function run(array $body, ?array $post_types, ?string $key_id, ?string $attestation): array {
        if (!is_string($body['link'] ?? null) || trim($body['link']) === '') {
            return self::refuse('bad_read', 'link must be present and a non-blank string');
        }
        $attested = CadenceAttestation::verify($attestation, '/content/read',
                                               ['link' => $body['link']], $key_id);
        if ($attested['ok'] !== true) {
            return ['ok' => false, 'code' => $attested['code'], 'reason' => $attested['reason'],
                    'attestation_branch' => $attested['branch']];
        }
        // THE BOUNDARY: only a verified signature reads. `NO_EXEMPTION` refuses
        // first and names the branch; this refuses whatever that set says.
        if (($attested['attestation'] ?? null) !== 'verified' || !isset($attested['kid'])) {
            return ['ok' => false, 'code' => CadenceAttestation::CODE, 'attestation_branch' => 'exempt_refused',
                    'reason' => 'this route takes no exemption; nothing was read'];
        }
        // AFTER THE SIGNATURE: resolving a link reads the site.
        $post_id = CadenceAdoptRequest::resolve_link($body['link']);
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post instanceof WP_Post || !self::admits($post, $post_types, $key_id)) {
            return self::refuse('post_out_of_scope',
                'the link does not name a post this key published or adopted; nothing was read');
        }
        if ($post->post_password !== '') {
            return self::refuse('read_password_protected', sprintf(
                'post %d is password-protected, and its text is not read; nothing was read', $post_id));
        }
        // LAST, so every refusal above answers without asking WPML anything.
        $element_type = 'post_' . $post->post_type;
        $language = CadenceLinkRequest::current_language($post_id, $element_type);
        $translations = self::translations($post_id, $element_type, $post_types, $key_id);
        if ($language === null || $translations === null) {
            return self::refuse('group_unknown', sprintf(
                'WPML would not say which language post %d is in or which posts translate it; '
                . 'nothing was read', $post_id));
        }
        $seo = [];
        foreach (self::SEO_META as $name => $meta) {
            $value = get_post_meta($post_id, $meta, true);
            $seo[$name] = is_string($value) ? $value : '';
        }
        return ['ok' => true, 'attestation' => 'verified', 'attestation_kid' => $attested['kid'],
                'report' => [
                    'post_id'         => $post_id,
                    'piece_id'        => (string) get_post_meta($post_id, CadenceContentRequest::META, true),
                    'language'        => $language,
                    'title'           => $post->post_title,
                    'content'         => $post->post_content,
                    'excerpt'         => $post->post_excerpt,
                    'seo_title'       => $seo['seo_title'],
                    'seo_description' => $seo['seo_description'],
                    'slug'            => $post->post_name,
                    'revision'        => CadenceRevision::of($post->post_title, $post->post_content),
                    // AN OBJECT ON THE WIRE even when empty: `{}` and never `[]`.
                    'translations'    => (object) $translations,
                ]];
    }

    /**
     * The OTHER posts in this post's translation group, language => post id;
     * `[]` in no group; null when WPML gives no usable answer, which is never
     * read as "no translations".
     */
    private static function translations(int $post_id, string $element_type, ?array $post_types,
                                         ?string $key_id): ?array {
        $trid = CadenceLinkRequest::current_trid($post_id, $element_type);
        if ($trid === false) {
            return null;
        }
        if ($trid === null) {
            return [];
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook, which cannot carry this plugin's prefix.
        $rows = apply_filters('wpml_get_element_translations', false, $trid, $element_type, false, true);
        if (!is_array($rows)) {
            return null;
        }
        $out = [];
        foreach ($rows as $row) {
            $id = is_object($row) ? ($row->element_id ?? null) : null;
            $lang = is_object($row) ? ($row->language_code ?? null) : null;
            if ($id === null || is_bool($id) || !ctype_digit((string) $id) || !is_string($lang) || $lang === '') {
                return null;
            }
            // ONLY A MEMBER THIS KEY WOULD BE ANSWERED FOR ON ITS OWN: another
            // tenant's post, or a human's, is not listed, not even by id.
            $member = (int) $id === $post_id ? null : get_post((int) $id);
            if ($member instanceof WP_Post && self::admits($member, $post_types, $key_id)) {
                $out[$lang] = (int) $id;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * THE ONE PREDICATE a read and a listed translation both pass: a Cadence
     * piece, made by this key, of a type in its scope, in a placed status.
     */
    private static function admits(WP_Post $post, ?array $post_types, ?string $key_id): bool {
        return CadenceKey::scope_admits($post->ID)
            && CadenceKey::created_by($post->ID, $key_id)
            && CadenceKey::is_content_type($post->post_type, $post_types)
            && ($post_types === null || in_array($post->post_type, $post_types, true))
            && in_array($post->post_status, CadenceAdoptRequest::STATUSES, true);
    }

    private static function refuse(string $code, string $reason): array {
        return ['ok' => false, 'code' => $code, 'reason' => $reason];
    }
}
