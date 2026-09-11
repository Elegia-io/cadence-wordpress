<?php
/**
 * Create a post from content the pipeline produced, exactly once.
 *
 * THE FAILURE THIS IS SHAPED AROUND IS THE DUPLICATE. An HTTP pipeline retries,
 * and a request that timed out after WordPress committed the insert but before
 * the response arrived is, to the caller, indistinguishable from one that never
 * ran. Retried, it puts the same article on the site twice -- published, and
 * visible to real visitors and to search engines. A human then has to notice
 * and delete one.
 *
 * So the caller's own identifier for the piece decides, not the request: an
 * identifier already on a post is answered with that post, and nothing is
 * created. `created` in the answer says which happened, so the caller never has
 * to infer it.
 *
 * @package cadence-connector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceContentRequest {

    /**
     * Where the caller's identifier lives. Leading underscore: protected meta,
     * so it does not appear in the Custom Fields box for a human to edit into
     * something that no longer matches what the pipeline will send.
     */
    public const META = '_cadence_external_id';

    /**
     * The statuses this endpoint means, and no others.
     *
     * WordPress accepts ANY string as a post status and stores it. A typo, or
     * `auto-draft`, or `inherit`, produces a row that exists and appears in no
     * query and on no admin screen -- content that was published as far as the
     * caller knows and is nowhere as far as anyone else does.
     */
    public const STATUSES = ['draft', 'pending', 'publish'];

    /**
     * Every code `run` can refuse with. Published for the same reason the
     * linker's is: so the REST layer can be tested for covering all of them
     * rather than for covering the ones its own tests happened to name.
     */
    public const REFUSAL_CODES = ['bad_request', 'capability_mismatch',
                                 'unsupported_language', 'insert_failed'];

    /**
     * @return array{ok: bool, created?: bool, post_id?: int, report?: array,
     *               code?: string, reason?: string}
     */
    public static function run(array $body): array {
        $fields = self::validate($body);
        if (is_string($fields)) {
            return ['ok' => false, 'code' => 'bad_request', 'reason' => $fields];
        }

        // BEFORE ANYTHING IS WRITTEN. A declaration that disagrees with the
        // site is not a detail to report alongside a post that already exists:
        // the post is the thing that must not appear.
        $languages = CadenceLanguageDeclaration::verify($body['declared'] ?? null, $fields['language']);
        if ($languages['ok'] !== true) {
            return ['ok' => false, 'code' => $languages['code'], 'reason' => $languages['reason']];
        }
        $unsupported = $languages['unsupported'];

        $existing = self::find_by_external_id($fields['piece_id']);
        if ($existing !== null) {
            // NEITHER A SECOND POST NOR A REWRITE OF THE FIRST. The identifier
            // means "this piece"; a body that differs under it means the caller
            // believes it is publishing something new, and the live article is
            // not this code's to overwrite on that belief.
            return ['ok' => true, 'created' => false, 'post_id' => $existing,
                    'report' => self::report($fields, $existing, $unsupported)];
        }

        $id = wp_insert_post([
            'post_type'    => $fields['post_type'],
            'post_status'  => $fields['status'],
            'post_title'   => $fields['title'],
            'post_content' => $fields['content'],
            // IN THE SAME CALL THAT CREATES THE POST. Writing the identifier
            // afterwards leaves a window in which the post exists without it,
            // and a retry landing in that window is exactly the duplicate this
            // class exists to prevent.
            'meta_input'   => [self::META => $fields['piece_id']],
        ], true);

        // WP_Error, or 0, and neither is an exception. Read as an id, `0` is
        // falsy -- which is also what "no post" looks like everywhere else, so
        // an unchecked failure propagates as a plausible absence.
        if (is_wp_error($id)) {
            return ['ok' => false, 'code' => 'insert_failed',
                    'reason' => 'WordPress refused the insert: ' . $id->get_error_message()];
        }
        if (!is_int($id) || $id < 1) {
            return ['ok' => false, 'code' => 'insert_failed',
                    'reason' => 'WordPress returned no post id and no error'];
        }

        return ['ok' => true, 'created' => true, 'post_id' => $id,
                'report' => self::report($fields, $id, $unsupported)];
    }

    /**
     * WHAT THIS CALL ACTUALLY DID, in the six fields the caller's verifier
     * reads. `{ok, created, post_id}` answers "did a row appear"; these answer
     * "is the thing I asked for now true", and the two differ in exactly the
     * cases worth auditing.
     *
     * `post_id` is the INTEGER WordPress gave it, not a string of it. The
     * caller's verifier checks the type before it checks anything else, so a
     * stringified id fails earlier and less legibly than a wrong one -- and
     * this is the one field in the reply that WordPress, not Cadence, names.
     *
     * `linked` is empty here and always will be: this route places a piece,
     * and associating translations is `/translation-group`'s write. Reporting
     * a link this route did not make is the `200 {"written": 2}` on a site
     * with no WPML, one boundary further out.
     *
     * @param array{piece_id: string, language: string} $fields
     * @param list<string> $unsupported
     */
    private static function report(array $fields, int $post_id, array $unsupported): array {
        return [
            'piece_id' => $fields['piece_id'],
            'post_id'  => $post_id,
            'placed'   => [$fields['language']],
            'linked'   => [],
            // Per language, with the reason attached, so an operator reading
            // one row does not have to hold the request beside it. Disjoint
            // from `placed` by construction: a language that could not be
            // served never reached the insert.
            'refused'  => array_map(static fn (string $code): array => [$code, sprintf(
                'this site has no active WPML language %s, so nothing was placed in it', $code
            )], $unsupported),
            'observed_unsupported' => $unsupported,
        ];
    }

    /**
     * The body's own shape, checked without coercion.
     *
     * @return array{piece_id: string, language: string, post_type: string, status: string, title: string, content: string}|string
     */
    private static function validate(array $body) {
        // `external_id` is what 0.1.0 called it. Accepted, because a released
        // connector is installed on sites this repository does not control and
        // a rename is not worth a publish that stops working mid-upgrade; the
        // answer says `piece_id` either way.
        if (!isset($body['piece_id']) && isset($body['external_id'])) {
            $body['piece_id'] = $body['external_id'];
        }
        foreach (['piece_id', 'language', 'post_type', 'status', 'title', 'content'] as $key) {
            if (!isset($body[$key]) || !is_string($body[$key])) {
                return sprintf('%s must be present and a string', $key);
            }
        }
        if (trim($body['piece_id']) === '') {
            return 'piece_id must not be blank; it is what makes a retry safe';
        }
        if (!in_array($body['status'], self::STATUSES, true)) {
            return sprintf('status must be one of %s', implode(', ', self::STATUSES));
        }
        // Asked of the site, not of a list here: a site's post types are its
        // own, and a plugin that shipped its own list would refuse the custom
        // type this endpoint exists to publish into.
        if (!post_type_exists($body['post_type'])) {
            return sprintf('this site has no post type %s', $body['post_type']);
        }
        return [
            'piece_id'    => $body['piece_id'],
            'language'    => $body['language'],
            'post_type'   => $body['post_type'],
            'status'      => $body['status'],
            'title'       => $body['title'],
            'content'     => $body['content'],
        ];
    }

    /** The post already carrying this identifier, or null. */
    private static function find_by_external_id(string $piece_id): ?int {
        $found = get_posts([
            'post_type'      => 'any',
            // Every status, deliberately. A piece whose post was moved to the
            // trash still HAS this identifier, and answering "not found" would
            // publish it a second time -- resurrecting content somebody deleted.
            'post_status'    => 'any',
            'meta_key'       => self::META,
            'meta_value'     => $piece_id,
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
        return $found === [] ? null : (int) $found[0];
    }
}
