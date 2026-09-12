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
     * WHICH KEY CREATED THIS POST. Its PUBLIC ID, never its secret: a meta row
     * travels in every database dump and every export, and the secret exists
     * only as a hash in the option table for exactly that reason.
     *
     * Written in the same `wp_insert_post` call as `META`, and for the same
     * argument: a stamp written afterwards leaves a window in which the post
     * exists carrying no identity, and a link request landing in that window
     * takes the null-identity path -- which is the compatibility path for posts
     * that predate the stamp, not a hole for posts made a moment ago.
     *
     * Leading underscore: protected meta, so it is not in the Custom Fields box
     * for a human to edit into another tenant's key id.
     */
    public const KEY_META = '_cadence_key';

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
                                 'unsupported_language', 'post_type_out_of_scope',
                                 'existing_post_type_out_of_scope', 'insert_failed'];

    /**
     * @param array    $body       the JSON body, already decoded
     * @param callable $authorises `fn(string $capability): bool`, i.e. does the
     *                             presented key hold this capability. Required,
     *                             with no default: what it gates is whether
     *                             this route hands out the token a rewrite has
     *                             to name, and a default would be a licence to
     *                             skip that at the one call site that forgot it.
     * @param int|null $author     the byline the presenting key names, or null
     *                             for a key issued before a key named one.
     *                             OPTIONAL, unlike `$authorises`, and the
     *                             asymmetry is deliberate: a missing byline
     *                             leaves `post_author` untouched, which is
     *                             exactly what a pre-byline key already does,
     *                             while a missing capability probe would hand
     *                             out a revision token nobody authorised.
     * @param list<string>|null $post_types the post types this key may create
     *                          in, or null for ANY -- the key issued before the
     *                          field existed, which is live on sites this
     *                          repository does not control. REQUIRED, with no
     *                          default, for the same reason `$authorises` is:
     *                          null is the WIDE case, and a default cannot tell
     *                          a key that named no type from a call site that
     *                          forgot to ask. The docblock used to claim it
     *                          could, which is a property no code enforced.
     * @param string|null $key_id the public id of the presenting key. It is
     *                          stamped on a post this call creates, so a later
     *                          `translation.link` can ask whether the post is
     *                          this key's own -- AND it scopes the lookup that
     *                          decides whether anything is created at all, so
     *                          one tenant's `piece_id` never resolves to
     *                          another's post. Null omits the stamp, exactly as
     *                          every insert before the field did, and finds
     *                          only unstamped posts -- the narrow reading, the
     *                          same one `CadenceLinkRequest::run` gives it.
     * @return array{ok: bool, created?: bool, post_id?: int, report?: array,
     *               revision?: string, code?: string, reason?: string}
     */
    public static function run(array $body, callable $authorises, ?array $post_types,
                               ?int $author = null, ?string $key_id = null): array {
        $fields = self::validate($body);
        if (is_string($fields)) {
            return ['ok' => false, 'code' => 'bad_request', 'reason' => $fields];
        }

        // AUTHORISATION FIRST, AND BEFORE THE SITE IS ASKED ANYTHING.
        //
        // `content.publish` opens this route; WHICH post types it opens it for
        // is this. A key names them at issue time because there is nothing on
        // the site to derive them from -- the post does not exist yet, so it
        // carries no meta to scope by, which is how the other two capabilities
        // are scoped.
        //
        // AND IT RUNS BEFORE `post_type_exists`, DELIBERATELY. That call is a
        // read of the client's site, and its refusal says whether a type is
        // registered there. Asked first, it would answer that question for
        // every type name a caller cared to send, over a type the key may not
        // publish into anyway -- a list of the site's post types, enumerable
        // one 400 at a time from behind a key entitled to none of them. A
        // caller learns what this site registers only for the types its own
        // key already names.
        //
        // The refusal names THIS branch and no other: it says the key does not
        // reach that type, and says nothing about whether the type exists.
        if ($post_types !== null && !in_array($fields['post_type'], $post_types, true)) {
            return ['ok' => false, 'code' => 'post_type_out_of_scope', 'reason' => sprintf(
                'this key publishes into %s, and the request names %s; nothing was created',
                implode(', ', $post_types), $fields['post_type'])];
        }

        // NOW the site may be asked about this type, because the key reaches
        // it. Still a `bad_request`: the credential is fine and the body names
        // something this site does not have.
        if (!post_type_exists($fields['post_type'])) {
            return ['ok' => false, 'code' => 'bad_request',
                    'reason' => sprintf('this site has no post type %s', $fields['post_type'])];
        }

        // BEFORE ANYTHING IS WRITTEN. A declaration that disagrees with the
        // site is not a detail to report alongside a post that already exists:
        // the post is the thing that must not appear.
        $languages = CadenceLanguageDeclaration::verify($body['declared'] ?? null, $fields['language']);
        if ($languages['ok'] !== true) {
            return ['ok' => false, 'code' => $languages['code'], 'reason' => $languages['reason']];
        }
        $unsupported = $languages['unsupported'];

        // SCOPED TO THE ASKING KEY, so this branch cannot answer with a post
        // the key may not act on -- see `find_by_external_id`.
        $existing = self::find_by_external_id($fields['piece_id'], $key_id);
        if ($existing !== null) {
            // AND THE FOUND POST'S TYPE IS INSIDE THE KEY'S SCOPE TOO, or the
            // scope is enforced on creation and abandoned on the repeat: a key
            // narrowed to `post` would otherwise be handed the id and the
            // revision of its own `page`, which is exactly the pair
            // `/content/replace` takes.
            //
            // A REFUSAL AND NOT A SECOND POST. The post this resolves to is one
            // this key reaches -- its own stamp, or the unstamped compatibility
            // set -- so saying so discloses nothing it could not already ask
            // for; creating instead would put the same piece on the site twice,
            // which is the failure this whole class exists to prevent.
            //
            // It names THIS branch: the piece is already placed in a type this
            // key does not publish into. It does not name the type, and it is
            // not `post_type_out_of_scope` -- that one is the REQUEST's type,
            // which was admitted above, and a caller told it would go on
            // correcting a `post_type` field that is already right.
            if ($post_types !== null && !in_array(get_post_type($existing), $post_types, true)) {
                return ['ok' => false, 'code' => 'existing_post_type_out_of_scope', 'reason' => sprintf(
                    'the piece %s is already on a post of a type this key does not publish into; '
                    . 'this key publishes into %s, and nothing was created or changed',
                    $fields['piece_id'], implode(', ', $post_types))];
            }
            // NEITHER A SECOND POST NOR A REWRITE OF THE FIRST. The identifier
            // means "this piece"; a body that differs under it means the caller
            // believes it is publishing something new, and the live article is
            // not this code's to overwrite on that belief.
            $answer = ['ok' => true, 'created' => false, 'post_id' => $existing,
                       'report' => self::report($fields, $existing, $unsupported)];
            // WITH THE REVISION THE POST ACTUALLY HOLDS, which is how a caller
            // whose body no longer matches the site finds that out -- and how
            // it gets the value a replacement has to name. Read from the post,
            // so a hand edit since publication is in the answer.
            //
            // BUT ONLY FOR A KEY THAT HOLDS `content.replace`. This route is
            // authorised on `content.publish`, a capability that says nothing
            // about whether the same key may ever rewrite a post -- a key
            // scoped to create only has no use for the proof `/content/replace`
            // demands that the caller has SEEN the text it is about to
            // overwrite, and is not handed it.
            if ($authorises('content.replace')) {
                $answer = array_merge($answer, CadenceRevision::answer($existing));
            }
            return $answer;
        }

        $postarr = [
            'post_type'    => $fields['post_type'],
            'post_status'  => $fields['status'],
            'post_title'   => $fields['title'],
            'post_content' => $fields['content'],
            // IN THE SAME CALL THAT CREATES THE POST. Writing the identifier
            // afterwards leaves a window in which the post exists without it,
            // and a retry landing in that window is exactly the duplicate this
            // class exists to prevent.
            'meta_input'   => [self::META => $fields['piece_id']],
        ];
        // THE CREATING KEY'S IDENTITY, in the same call and for the same
        // reason as the identifier beside it. Null for a caller this route
        // cannot name -- which is what every insert wrote before the stamp
        // existed, and what `CadenceKey::created_by` reads as the documented
        // null-identity path rather than as a key that does not match.
        if ($key_id !== null && trim($key_id) !== '') {
            $postarr['meta_input'][self::KEY_META] = $key_id;
        }
        // THE ONLY THING THE AUTHOR ID DOES IS FILL THIS FIELD.
        //
        // Left out, `post_author` takes 0 -- no user -- and a theme printing a
        // byline prints an empty one or fatals on `get_userdata(0)`, while the
        // posts list sorts and filters by author and these rows sit outside
        // every filter. So the key names a user the client chose.
        //
        // It is NOT an identity. The request is still authenticated by a key
        // that confers none: nothing here calls `wp_set_current_user`, nothing
        // asks `current_user_can` about this id, and the capability boundary is
        // still the key's own grant. A post carries this person's name; the
        // caller did not become them.
        //
        // Null is the pre-existing key, and omits the field exactly as this
        // insert did before the field existed -- see `CadenceKey::author_for`.
        //
        // This is the one place the connector creates a post, and the only
        // place an author is set. `/content/replace` does call
        // `wp_update_post`, but it overwrites the title and the content and
        // nothing else -- a rewrite never moves a post to another author --
        // and an identifier already on a post is answered here with that post,
        // never a rewrite of it.
        if ($author !== null) {
            $postarr['post_author'] = $author;
        }

        $id = wp_insert_post($postarr, true);

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

        // The revision is read back rather than computed from the body: what
        // WordPress stores is what it was given after `wp_kses` and the save
        // filters have had it, and a revision of the request would disagree
        // with the post from the moment it was made.
        //
        // UNGATED, DELIBERATELY, unlike the branch above. This post did not
        // exist a moment ago and its text is the text this caller just sent,
        // so there is nothing here to disclose -- and gating it would leave a
        // pipeline unable to replace what it published without a capability
        // it does not need in order to publish.
        return array_merge(['ok' => true, 'created' => true, 'post_id' => $id,
                             'report' => self::report($fields, $id, $unsupported)],
                           CadenceRevision::answer($id));
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
        // WHETHER THE SITE HAS THIS POST TYPE IS NOT ASKED HERE. It is a read
        // of the client's site and it happens in `run`, after the key's own
        // publish scope has admitted the type -- see the note there. This
        // method reads the BODY and nothing else, which is what makes its
        // refusals safe to give a caller whose entitlement is not yet settled.
        return [
            'piece_id'    => $body['piece_id'],
            'language'    => $body['language'],
            'post_type'   => $body['post_type'],
            'status'      => $body['status'],
            'title'       => $body['title'],
            'content'     => $body['content'],
        ];
    }

    /**
     * THE POST THIS KEY ALREADY HAS UNDER THIS IDENTIFIER, or null.
     *
     * SCOPED TO THE ASKING KEY, AND THAT IS THE FIX RATHER THAN A REFUSAL
     * BOLTED ON AFTERWARDS. `piece_id` is the CALLER's name for its own piece:
     * two tenants on one WordPress may legitimately choose the same string, and
     * when they do they mean two different pieces. An unscoped lookup answered
     * whichever post carried the string -- any type, any creating key -- so a
     * key that guessed a `piece_id` was handed another tenant's post id and,
     * if it also held `content.replace`, the revision that rewrites it.
     *
     * A REFUSAL ON THAT BRANCH WOULD HAVE BEEN WORSE ON BOTH COUNTS: it
     * confirms the identifier is taken, which is the disclosure itself in
     * smaller print, and it refuses a publish the caller is entitled to. Not
     * finding another tenant's post is simply correct -- B's piece does not
     * exist yet, so B creates it, and each tenant's identifier space is its
     * own.
     *
     * THE NULL-IDENTITY PATH STILL APPLIES, through `created_by`. Every post
     * Cadence made before the stamp existed carries none, and a lookup that
     * skipped those would stop finding every client's existing posts and
     * duplicate them on the next publish -- a worse outcome than the
     * disclosure this closes. That set never grows.
     *
     * EVERY CANDIDATE IS ASKED, not just the first row. `posts_per_page => 1`
     * would hand back whichever post the database happened to order first and
     * then refuse it here, leaving this key unable to find its OWN post behind
     * another tenant's row -- which is the duplicate again, arrived at by a
     * different route.
     */
    private static function find_by_external_id(string $piece_id, ?string $key_id): ?int {
        $found = get_posts([
            'post_type'      => 'any',
            // Every status, deliberately. A piece whose post was moved to the
            // trash still HAS this identifier, and answering "not found" would
            // publish it a second time -- resurrecting content somebody deleted.
            'post_status'    => 'any',
            'meta_key'       => self::META,
            'meta_value'     => $piece_id,
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);
        foreach ($found as $id) {
            if (CadenceKey::created_by((int) $id, $key_id)) {
                return (int) $id;
            }
        }
        return null;
    }
}
