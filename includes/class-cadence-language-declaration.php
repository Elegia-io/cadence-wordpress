<?php
/**
 * WPML DECLARED, NEVER DETECTED.
 *
 * The failure this exists to make impossible: Cadence probes the site, finds no
 * WPML, quietly publishes one language and REPORTS SUCCESS. A multilingual
 * client's translation run then ships a single language and nothing anywhere
 * says so -- an absence that reads as the reassuring answer, which is the most
 * expensive shape a check can have.
 *
 * So the tenant record states whether this client is multilingual and which
 * languages the run covers; this class reports what the site actually has; and
 * a disagreement REFUSES. The refusal names which side disagreed, because
 * "WPML was uninstalled on the client's site" and "the tenant record is wrong"
 * are different incidents with different owners.
 *
 * One plugin serves both kinds of client. There is no monolingual build: with
 * two artifacts, "does this install have WPML" is answered by whichever zip
 * somebody happened to upload, which is not a property anything verifies.
 *
 * @package cadence-connector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceLanguageDeclaration {

    /** A bare lowercase WPML language code: `de`, `pt-br`, `zh-hant-hk`. */
    private const LANGUAGE = '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/';

    /** Every code `verify` can refuse with. Published so the REST layer can be
     *  tested for covering all of them rather than the ones its tests named. */
    public const REFUSAL_CODES = ['bad_request', 'capability_mismatch', 'unsupported_language'];

    /**
     * CHECK THE DECLARATION AGAINST THE SITE.
     *
     * @param mixed  $declared The request's `declared` object.
     * @param string $language The language of the piece in this request.
     * @return array{ok: true, unsupported: list<string>}|array{ok: false, code: string, reason: string, unsupported: list<string>}
     */
    public static function verify($declared, string $language): array {
        if (!is_array($declared) || !array_key_exists('multilingual', $declared)
            || !is_bool($declared['multilingual'])) {
            return self::no('bad_request',
                'declared.multilingual must be present and a boolean; this connector does not '
                . 'infer it from the site, because an inferred "no" publishes one language and calls it success');
        }
        $languages = $declared['languages'] ?? null;
        if (!is_array($languages) || !array_is_list($languages) || $languages === []) {
            return self::no('bad_request', 'declared.languages must be a non-empty list of language codes');
        }
        foreach ($languages as $code) {
            if (!is_string($code) || preg_match(self::LANGUAGE, $code) !== 1) {
                return self::no('bad_request', 'declared.languages holds something that is not a language code');
            }
        }
        if (preg_match(self::LANGUAGE, $language) !== 1) {
            return self::no('bad_request', 'language must be a language code');
        }
        if (!in_array($language, $languages, true)) {
            return self::no('bad_request',
                'this piece names a language the declaration does not cover, so the two disagree about '
                . 'what the run is for');
        }

        // WHAT THE SITE ACTUALLY HAS. The same two hooks the linker needs: a
        // site with no WPML still runs `apply_filters` and `do_action`
        // agreeably, so presence is only answerable by asking whether anything
        // is LISTENING.
        $site_is_multilingual = has_filter('wpml_element_language_details')
                             && has_action('wpml_set_element_language_details');

        if ($declared['multilingual'] && !$site_is_multilingual) {
            return self::no('capability_mismatch',
                'the tenant record declares this client multilingual; this site implements no WPML '
                . 'translation-group hooks. Either WPML was removed or disabled here, or the record is wrong',
                $languages);
        }
        if (!$declared['multilingual'] && $site_is_multilingual) {
            return self::no('capability_mismatch',
                'the tenant record declares this client monolingual; this site implements the WPML '
                . 'translation-group hooks. Either the record has not caught up with the site, or a '
                . 'multilingual site is being published to as if it had one language');
        }

        if (!$declared['multilingual']) {
            // A monolingual tenant declaring two languages is not a partial
            // outcome to report -- it is a contradiction inside one request,
            // and reporting it as an unsupported language would suggest the
            // site is what is lacking.
            if (count(array_unique($languages)) !== 1) {
                return self::no('bad_request',
                    'a monolingual declaration names more than one language');
            }
            return ['ok' => true, 'unsupported' => [], 'multilingual' => false];
        }

        // WHICH LANGUAGES THIS SITE CAN SERVE. `wpml_active_languages` is a
        // map keyed by code; a site whose hooks are present but whose answer is
        // not a map has told us nothing, and "nothing" is not "all of them".
        $active = apply_filters('wpml_active_languages', null);
        $codes  = is_array($active) ? array_map('strval', array_keys($active)) : [];

        $unsupported = array_values(array_filter(
            array_unique($languages),
            static fn (string $code): bool => !in_array($code, $codes, true)
        ));

        if (in_array($language, $unsupported, true)) {
            // Nothing to place, so there is no post to report against. This is
            // the whole point of the class: it is a refusal the operator sees,
            // not a post that quietly appears in the wrong language.
            return self::no('unsupported_language', sprintf(
                'this site has no active WPML language %s, so the piece cannot be placed in it',
                $language
            ), $unsupported);
        }
        return ['ok' => true, 'unsupported' => $unsupported, 'multilingual' => true];
    }

    /**
     * RECORD THE PIECE'S LANGUAGE WITH WPML, AND READ BACK WHAT WPML KEPT.
     *
     * Placing a post is not telling WPML what language it is in. Nothing did,
     * so every post `/content` created took the site's DEFAULT language while
     * the reply named the language that was asked for: a piece sent as `de` was
     * stored as `en` under a `201` saying `placed: ["de"]` (#1428).
     *
     * `trid` is null, which has WPML invent a new group. That is the only safe
     * value here and only because the post is NEW: WPML's own documentation for
     * this action warns that an unestablished group *"will create a new trid for
     * the element causing any potential translation relations to/from it to
     * disappear"*. Associating translations is `/translation-group`'s write, and
     * this must never be called for a post that already existed.
     *
     * THE RETURN IS THE SITE'S ANSWER, NOT THE ARGUMENT. It is read back
     * through the same filter `/translation-group` reads, with `false` as the
     * default for the same reason: `apply_filters` hands the default straight
     * back when nothing answers, so a silent site must not be readable as
     * agreement. A caller gets `placed: []` and a reason rather than a success
     * naming a language the site did not keep.
     *
     * @param int $post_id The post just created.
     * @param string $element_type WPML's element type, e.g. `post_post`.
     * @param string $language The language the piece was published in.
     * @return string|null The language WPML now holds, or null if it could not
     *         be read at all.
     */
    public static function record(int $post_id, string $element_type, string $language): ?string {
        do_action('wpml_set_element_language_details', [
            'element_id'           => $post_id,
            'element_type'         => $element_type,
            'trid'                 => null,
            'language_code'        => $language,
            'source_language_code' => null,
        ]);
        return self::stored_language($post_id, $element_type);
    }

    /**
     * WHAT LANGUAGE WPML HOLDS FOR ONE ELEMENT, or null when it will not say.
     *
     * Separate from `record` so the repeat path can ask without writing: a post
     * that already exists must be reported on, never re-grouped.
     *
     * @return string|null
     */
    public static function stored_language(int $post_id, string $element_type): ?string {
        $details = apply_filters('wpml_element_language_details', false, [
            'element_id'   => $post_id,
            'element_type' => $element_type,
        ]);
        if (!is_object($details) || !isset($details->language_code)) {
            return null;
        }
        $code = $details->language_code;
        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * @param list<string> $unsupported
     * @return array{ok: false, code: string, reason: string, unsupported: list<string>}
     */
    private static function no(string $code, string $reason, array $unsupported = []): array {
        return ['ok' => false, 'code' => $code, 'reason' => $reason,
                'unsupported' => array_values(array_unique($unsupported))];
    }
}
