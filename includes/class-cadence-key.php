<?php
/**
 * The connector's own credential: scoped to a capability, not to a user.
 *
 * WHY THIS EXISTS AT ALL. The alternative -- and what publishing to WordPress
 * means without it -- is a WordPress application password or an admin account.
 * Those are scoped to a USER: a credential that can create a draft can also
 * edit every published post, read every draft on the site, enumerate users and,
 * depending on the role, install code. Cadence performs one narrow operation
 * and would be holding all of that, for every tenant, forever.
 *
 * A key here grants named capabilities and confers NO WordPress identity.
 * `wp_get_current_user()` stays 0 for a request authenticated this way, so
 * every other REST route on the site -- core's included -- still refuses it.
 * The capability boundary is therefore the route surface itself, which is two
 * routes, rather than a role someone has to remember to keep narrow.
 *
 * @package cadence-connector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceKey {

    /** Where the records live. One row per key, keyed by its public id. */
    public const OPTION = 'cadence_connector_keys';

    /**
     * EVERY CAPABILITY A KEY MAY CARRY, and no others.
     *
     * An explicit set rather than free text, so `issue` has something to refuse
     * against. A grant spelled `content.publsh` would otherwise be stored, be
     * held by nothing, and read on the admin screen as a key that works.
     *
     * `content.publish` AND `content.replace` ARE SEPARATE, DELIBERATELY. A key
     * that may create must not silently also be able to overwrite: creating
     * writes a post nobody has seen yet; replacing destroys text a human may
     * have hand-edited since. A pipeline that needs both holds both, granted
     * as two ticks on the same key -- but one is never implied by the other.
     *
     * WHAT EACH ONE MAY ACT ON, which is a second question from what it may do.
     * `translation.link` is scoped to this plugin's own posts by `scope_admits`
     * AND to the posts THIS key created by `created_by`. `content.replace` is
     * scoped by `created_by` too -- the identifier is not a secret, so "is this
     * the post you name" was never the same question as "is this post yours"
     * -- and then by the stricter comparison behind `identifier_mismatch`,
     * which demands the stored identifier BE the one named.
     *
     * `content.publish` IS SCOPED BY THE POST TYPES NAMED ON THE KEY -- and so
     * is `content.replace`, which is the one narrowing available over the posts
     * that predate the key stamp, where `created_by` admits everything. It is
     * the one scope an operator has to keep current: creating cannot be scoped
     * by meta the post does not have yet, so there is nothing on the site to
     * derive it from. `null` -- a key issued before the field existed, or one
     * issued with the field left blank -- means ANY registered post type, which
     * is what every key did before this and what keys already live on clients'
     * sites go on doing. See `publish_types_for`.
     */
    public const CAPABILITIES = ['content.publish', 'content.replace', 'translation.link'];

    /** The request header the key is presented in. */
    public const HEADER = 'X-Cadence-Key';

    /**
     * IS THIS POST INSIDE WHAT A KEY MAY ACT ON?
     *
     * THE SCOPE IS THIS PLUGIN'S OWN POSTS -- the ones it created, identified
     * by the `_cadence_external_id` it writes in the same `wp_insert_post` call
     * that makes the row. Nothing else on the site is in scope, so a page a
     * human wrote, a shop product, the site's front page and every draft
     * somebody has not finished are all outside it.
     *
     * WHY THIS SET AND NOT A POST TYPE OR A LIST OF IDS. It is exactly the set
     * Cadence created, so it needs no configuration on the client's site and
     * nothing for an operator to keep current: a key issued today covers the
     * posts the pipeline makes tomorrow and never covers anything else. A post
     * type would cover every `page` on the site including the ones a human
     * wrote; a list of ids would have to be edited on every publish.
     *
     * IT FAILS CLOSED, AND THE DIRECTION IS THE POINT. Meta this cannot read as
     * a non-blank string -- absent, blank, an array something else wrote -- is
     * not in scope. `/content` refuses a blank `piece_id`, so a blank stored
     * value cannot have come from this plugin, and admitting it would put every
     * post carrying an empty `_cadence_external_id` inside the grant.
     *
     * WHAT THIS ON ITS OWN DOES NOT SEPARATE: two keys on one site. This
     * predicate asks "did Cadence make this post", which is one question short
     * of "did YOU make it" -- so it is now one of two, and `created_by` asks
     * the other. THE PREDICATES STAY SEPARATE; WHAT THE LINKING ROUTE MERGED IS
     * THE ANSWER. They used to be refused separately, as two facts a caller
     * needs apart -- a post nobody here published is not the same answer as one
     * a different key published. On `/translation-group` the PAIR of codes
     * turned out to be a provenance oracle: any id on the site sorted into
     * another tenant'''s Cadence posts or everything else, behind a credential
     * that route verifies no signature for. `CadenceKey::reaches` asks the
     * conjunction there and answers once. `/content/replace` keeps the finer
     * answer, because it verifies an attestation before it reads anything about
     * the post, so its answer costs a signing key rather than a leaked
     * connector key. Do not re-split the linking route'''s codes.
     *
     * NOT ASKED OF `content.publish`. Creating a post cannot be scoped by meta
     * the post does not have yet; that capability is scoped by the post types
     * named on the key instead -- see `publish_types_for`.
     */
    public static function scope_admits(int $post_id): bool {
        // `get_post_meta(..., true)` answers `''` for meta that is not there,
        // which is indistinguishable from a stored empty string -- so both are
        // refused rather than told apart, because neither is a piece this
        // plugin published.
        $stored = get_post_meta($post_id, CadenceContentRequest::META, true);
        return is_string($stored) && trim($stored) !== '';
    }

    /**
     * DID *THIS* KEY CREATE THIS POST?
     *
     * The second half of the scope, and the one that separates two keys on one
     * site. `scope_admits` asks whether Cadence made the post; on a site with
     * one connector key those are the same question, and on a site with two --
     * a client with two brands on one WordPress, an agency serving two tenants
     * -- they are not: the first admits the other tenant's pieces, and a
     * translation group written over them DESTROYS the relations they had.
     *
     * The stamp is `CadenceContentRequest::KEY_META`, written by `/content` in
     * the same `wp_insert_post` call as the identifier, and it is the key's
     * PUBLIC ID -- never its secret, which this class stores only a hash of and
     * which must not end up in a meta row any database dump carries.
     *
     * THE NULL-IDENTITY PATH IS DELIBERATE, EXPLICIT, AND THE REASON THIS CAN
     * SHIP. Every post Cadence has already created on every client's site
     * carries no stamp, because the stamp did not exist when it was made.
     * Refusing those would refuse every link over every piece already
     * published -- an upgrade that breaks the working case, which is a worse
     * outcome than the widening it closes. So an UNSTAMPED post stays inside
     * the scope of any key that reaches it through `scope_admits`, exactly as
     * before, and only a post that NAMES a key is compared against one.
     *
     * It is a path and not a falsy accident: the check is `is_string` plus a
     * trimmed emptiness test on the stamp, so an absent row, a blank string and
     * an array some other plugin wrote all take the same documented branch,
     * and none of them is read as a key id that happens not to match.
     *
     * WHAT IT COSTS: two keys on one site do not separate over the posts that
     * predate the stamp. That set never grows -- every post created from here
     * on is stamped -- and the alternative was refusing all of it.
     *
     * A stamped post asked about by no key (`$key_id` null -- nothing
     * presented, or a key that did not authenticate) is refused. Fail closed:
     * "no identity" is not a wildcard.
     */
    public static function created_by(int $post_id, ?string $key_id): bool {
        $stamp = get_post_meta($post_id, CadenceContentRequest::KEY_META, true);
        if (!is_string($stamp) || trim($stamp) === '') {
            return true;   // THE NULL-IDENTITY PATH -- see above.
        }
        return is_string($key_id) && $stamp === $key_id;
    }

    /**
     * DOES THIS KEY REACH THIS POST AT ALL? The two predicates above asked as
     * ONE, and the only form the link route is allowed to ask them in.
     *
     * `scope_admits` and `created_by` are separate facts and stay separately
     * testable, but a caller must not be able to tell which of them said no.
     * Answered apart, the pair is a provenance oracle: "absent or not ours"
     * against "ours but another key's" partitions every id on the site into
     * the other tenant's Cadence posts and everything else, and a key holding
     * `translation.link` can walk it one refusal at a time. Provenance --
     * which of two tenants on one site published a given post -- is the thing
     * per-key scope exists to protect, so the connector answers the
     * conjunction and the caller sees one bit: does this key reach post N.
     *
     * SHORT-CIRCUIT ON PURPOSE. `created_by` is not asked about a post the
     * connector never published; it would answer `true` for one (no stamp, so
     * the null-identity path), and a reader could mistake that for entitlement.
     *
     * `/content/replace` asks `created_by` on its own instead, and is not the
     * same exposure: it verifies the request's attestation before it reads
     * anything about the post, so the finer answers there cost a signing key
     * rather than a leaked connector key. Any route that asks WITHOUT
     * verification first should ask here.
     */
    public static function reaches(int $post_id, ?string $key_id): bool {
        return self::scope_admits($post_id) && self::created_by($post_id, $key_id);
    }

    /**
     * IS THIS A TYPE CADENCE MAY PUBLISH, REPLACE OR LINK A POST OF?
     *
     * `revision` AND `attachment` ARE REFUSED UNCONDITIONALLY, whatever
     * `$scope` says. `revision` because a key naming no types reaches a
     * revision that happens to carry this piece's stamp (a plugin that
     * copies meta onto revisions, as ACF does with its own fields, can leave
     * one carrying `_cadence_external_id`), and a write to it is not an act
     * on the piece -- it is a write to a row `/content` never returned and no
     * reader will ever see. `attachment` on this plugin's own grounds and
     * NOT core's: `is_post_type_viewable('attachment')` is true, because an
     * attachment has its own public page, but Cadence never creates one and
     * a piece landing on one is not a piece it could have published.
     *
     * EVERY OTHER TYPE IS CHECKED AGAINST `is_post_type_viewable` ONLY WHEN
     * `$scope` IS NULL. `null` on the type-scope field of a key means ANY
     * REGISTERED TYPE -- the compatibility value a key issued before the
     * field existed carries -- and viewability is the one narrowing left
     * over a set that wide: a plugin's own type registered `public => false`
     * (headless, or a page-builder's private working type) is content this
     * plugin might reasonably be pointed at, but a null-scope key was never
     * told to reach it. A key that NAMES a type, by contrast, has an
     * operator's explicit say-so behind it -- `product`, `landing_page`,
     * whatever a client's site calls its own content -- and viewability is
     * not asked again: a scoped key is checked against its own named list
     * elsewhere (`post_type_out_of_scope` / `existing_post_type_out_of_scope`
     * / `link_post_type_out_of_scope`), and `is_post_type_viewable` would
     * otherwise refuse a type an operator deliberately scoped a key to,
     * which would make that type uncreatable, unreplaceable and unlinkable
     * by the very key issued to act on it.
     *
     * `is_post_type_viewable` ITSELF CHECKS ONLY `publicly_queryable` FOR A
     * TYPE THAT IS NOT ONE OF WORDPRESS'S OWN BUILT-INS -- core also asks
     * `public` for a built-in, but that distinction never reaches a plugin's
     * own custom type, which is the case this scope exists to cover.
     *
     * @param list<string>|null $scope the post types the presenting key
     *                    names, exactly as passed to `run` on the calling
     *                    route -- null for a key that names none.
     */
    public static function is_content_type(string $type, ?array $scope): bool {
        return $type !== 'revision' && $type !== 'attachment'
            && ($scope !== null || is_post_type_viewable($type));
    }

    /**
     * THE PUBLIC ID A PRESENTED KEY AUTHENTICATES AS, or null.
     *
     * The id and never the secret: this is what gets written into a post's meta
     * and compared on a later request, so it has to be a value that is safe
     * sitting in the option table's neighbour for the life of the post.
     *
     * Authentication comes FIRST -- `grant` verifies the hash before anything
     * here reads the id off the presented string. Splitting the id out without
     * that would let any caller claim any tenant's identity by spelling its id
     * in front of a dot, which is the whole boundary handed away.
     */
    public static function key_id_for($presented): ?string {
        if (self::grant($presented) === null) {
            return null;
        }
        return explode('.', (string) $presented, 2)[0];
    }

    /**
     * THE POST TYPES THIS KEY MAY CREATE IN, or null for ANY.
     *
     * `content.publish` cannot be scoped the way the other two capabilities are
     * -- there is no post yet to ask meta about -- so the scope is declared on
     * the key at issue time and validated there against the site's own
     * registered types. `CadenceContentRequest` refuses a publish outside it
     * with `post_type_out_of_scope`, and a repeat over a piece already placed
     * outside it with `existing_post_type_out_of_scope`.
     *
     * AND THE SAME LIST SCOPES `content.replace`, which CAN be scoped by meta
     * and is, by `created_by` -- but that predicate admits every post made
     * before the stamp existed, and over that set two keys on one site do not
     * separate. The type list is the only narrowing left there.
     * `CadenceReplaceRequest` refuses a rewrite outside it with
     * `existing_post_type_out_of_scope`, the same code and the same operator
     * fix as the repeat above.
     *
     * NULL MEANS ANY, and that is the compatibility path. Keys are live on
     * sites this repository does not control; a new required field would turn a
     * working publish into a 403 the moment the plugin updated, for a key whose
     * holder can neither see the field nor fill it in. A key issued before the
     * field existed has no `post_types` at all and goes on publishing into
     * every registered type exactly as it did. The admin screen marks those
     * rows, and re-issuing is the operator's fix -- the same shape as the
     * byline.
     *
     * AN ARRAY IS TAKEN LITERALLY, including an empty one. `issue` refuses an
     * empty list, so a stored `[]` cannot have been issued here; read as "any"
     * it would widen a key on corrupt data, and read as "none" it refuses every
     * publish, which is visible and safe. Only the ABSENCE of the field means
     * any.
     *
     * @return list<string>|null
     */
    public static function publish_types_for($presented): ?array {
        $types = self::grant($presented)['post_types'] ?? null;
        return is_array($types) ? array_values($types) : null;
    }

    /**
     * ISSUE ONE. Returns `['id' => ..., 'secret' => ...]`, or a string saying
     * why not.
     *
     * The secret is returned HERE AND NOWHERE ELSE. What is stored is a
     * SHA-256 of it, so the option table -- which is readable by anything that
     * gets a database dump or a `get_option` -- carries nothing that can be
     * presented at the route. No password stretching: the secret is 256 bits
     * from the CSPRNG rather than something a human chose, so there is no
     * dictionary for a stretch to slow down.
     *
     * @param list<string> $capabilities
     * @param int|string    $author the user id posts made with this key carry
     * @param list<string>|null $post_types the post types `content.publish` may
     *                          create in, or null for any -- see
     *                          `publish_types_for` for why null is a path and
     *                          not an oversight
     * @return array{id: string, secret: string}|string
     */
    public static function issue(string $label, array $capabilities, $author, ?array $post_types = null) {
        if (trim($label) === '') {
            return 'a key needs a label, so a tenant can be told apart from another at revocation time';
        }
        if ($capabilities === []) {
            return 'a key granting nothing is not a key; name at least one capability';
        }
        foreach ($capabilities as $capability) {
            if (!is_string($capability) || !in_array($capability, self::CAPABILITIES, true)) {
                return sprintf('unknown capability; this connector grants only %s',
                               implode(', ', self::CAPABILITIES));
            }
        }
        // THE BYLINE. Checked here and not on the screen, for the same reason
        // the capability names are: a value the admin file decided by itself is
        // a decision nothing runs a test against. `get_userdata` is the SITE's
        // answer -- a plugin has no list of the users a site has, and a byline
        // naming a user who does not exist is the empty byline this exists to
        // stop, written down.
        $author = self::user_id($author);
        if ($author === null) {
            return 'the author must be an id of a user this site has; a post this key creates carries it as its byline';
        }
        // THE PUBLISH SCOPE, VALIDATED HERE AND NOT AT THE PUBLISH.
        //
        // `post_type_exists` is the SITE's answer, asked at the one moment a
        // human is on the screen to fix a wrong one. A key naming `artcle` is
        // otherwise stored, reads on the admin screen as a key that works, and
        // fails every publish it is ever presented for -- with a 403 whose
        // holder cannot see the typo that caused it. The capability names are
        // refused above for the same reason and by the same argument.
        if ($post_types !== null) {
            if (!array_is_list($post_types) || $post_types === []) {
                return 'the publish scope is a list of post types, or is left unset to mean any; '
                     . 'an empty list is a key whose every publish is refused';
            }
            $scope = [];
            foreach ($post_types as $type) {
                if (!is_string($type) || trim($type) === '') {
                    return 'each post type in the publish scope must be a non-blank string';
                }
                $type = trim($type);
                if (!post_type_exists($type)) {
                    return sprintf('this site registers no post type %s, so a key scoped to it '
                                   . 'could never publish anything', $type);
                }
                $scope[] = $type;
            }
            $post_types = array_values(array_unique($scope));
        }
        $id     = bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(32));
        $keys   = self::records();
        $keys[$id] = [
            'label'      => $label,
            'hash'       => hash('sha256', $secret),
            'caps'       => array_values(array_unique($capabilities)),
            'author'     => $author,
            'created'    => time(),
            'revoked_at' => null,
        ];
        // ABSENT, not null, when the key is unscoped. The two read the same
        // through `?? null`, and the admin screen's `isset` marks an unscoped
        // key from the absence -- the same shape the byline already uses for a
        // key issued before keys carried one.
        if ($post_types !== null) {
            $keys[$id]['post_types'] = $post_types;
        }
        update_option(self::OPTION, $keys);
        return ['id' => $id, 'secret' => $id . '.' . $secret];
    }

    /**
     * The user id this value names, or null.
     *
     * A string is accepted because that is what a form posts. `ctype_digit`
     * rather than a bare cast: `(int) 'editor'` is 0 and `(int) '3 posts'` is
     * 3, so a cast turns something nobody typed into a user id, and only the
     * site not happening to have that user would refuse it.
     *
     * @param mixed $author
     */
    private static function user_id($author): ?int {
        if (is_string($author) && ctype_digit($author)) {
            $author = (int) $author;
        }
        if (!is_int($author) || $author < 1 || get_userdata($author) === false) {
            return null;
        }
        return $author;
    }

    /**
     * THE BYLINE A POST THIS KEY CREATES CARRIES, or null for a key issued
     * before a key carried one.
     *
     * IT GRANTS NOTHING, AND IS READ NOWHERE ELSE. The id fills `post_author`
     * and that is all: nothing here calls `wp_set_current_user`, and a request
     * authenticated by this key still carries no WordPress identity, which is
     * the entire safety argument for the key existing. Making this id the
     * current user would replace a credential scoped to two capabilities with
     * one scoped to whatever that user's role can do.
     *
     * NULL FOR A KEY ISSUED BEFORE THIS CHANGE, deliberately. The insert then
     * omits `post_author` exactly as every insert did before, so an install
     * that publishes today goes on publishing: refusing would break it over a
     * field it never had, and substituting any user would put a byline on a
     * post that person did not choose. The admin screen marks those keys, so
     * the fix is visible and is the operator's to make -- re-issue the key.
     *
     * NULL ALSO FOR A BYLINE WHOSE USER HAS SINCE BEEN DELETED, for the same
     * reason and by the same path. `issue()` checked `get_userdata` once, at
     * the moment a human was on the screen to fix a bad answer; a key lives
     * far longer than that moment, and a byline the site could name yesterday
     * can be one it cannot name today. Re-checking HERE, on every call, is
     * what keeps that from being bypassed by time passing -- `issue()` runs
     * once per key, `author_for()` runs once per publish.
     *
     * Refusing the publish instead was the other option, and it is worse: a
     * post that is otherwise complete would fail for a reason the caller
     * neither caused nor can fix from where it sits, over a field that names
     * nobody, on a request that names a post type, a title and a body the
     * site can accept. Omitting the byline is not a wrong write -- it is the
     * same value that field has always taken when nothing sets it, so a
     * client's content still publishes and the missing byline is exactly as
     * visible on the admin screen as the pre-byline-key case already is.
     */
    public static function author_for($presented): ?int {
        $author = self::grant($presented)['author'] ?? null;
        if (!is_int($author) || $author < 1 || get_userdata($author) === false) {
            return null;
        }
        return $author;
    }

    /**
     * REVOKE ONE. The record stays, with its hash destroyed: revocation has to
     * be visible afterwards ("this tenant's key was withdrawn on the 4th") and
     * the id must never come back round to a second holder.
     */
    public static function revoke(string $id): bool {
        $keys = self::records();
        if (!isset($keys[$id]) || $keys[$id]['revoked_at'] !== null) {
            return false;
        }
        $keys[$id]['hash']       = null;
        $keys[$id]['revoked_at'] = time();
        update_option(self::OPTION, $keys);
        return true;
    }

    /**
     * The keys, for a human to look at. Never the hashes: an admin screen has
     * no use for them and a template that prints one is a template that leaks
     * the only stored half of the credential.
     *
     * @return array<string, array{label: string, caps: list<string>, author?: int, post_types?: list<string>, created: int, revoked_at: int|null}>
     */
    public static function all(): array {
        $out = [];
        foreach (self::records() as $id => $record) {
            unset($record['hash']);
            $out[$id] = $record;
        }
        return $out;
    }

    /**
     * DOES THIS PRESENTED KEY CARRY THIS CAPABILITY?
     *
     * The only question the routes ask. Everything that could go wrong -- no
     * header, a header of the wrong shape, an unknown id, a revoked key, a
     * wrong secret, a key that is genuine and simply does not hold this
     * capability -- is the same answer: false. The route may not say which,
     * because the caller is unauthenticated and each distinction it can observe
     * is a key it does not have to guess.
     */
    public static function authorises($presented, string $capability): bool {
        $record = self::grant($presented);
        return $record !== null && in_array($capability, $record['caps'], true);
    }

    /**
     * The record a presented key authenticates as, or null.
     *
     * @return array{label: string, caps: list<string>, author?: int, post_types?: list<string>}|null
     */
    private static function grant($presented): ?array {
        if (!is_string($presented)) {
            return null;
        }
        // Exactly two parts. `explode(..., 2)` puts everything after the first
        // dot in the secret, so a secret is never truncated by its own content.
        $parts = explode('.', $presented, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        [$id, $secret] = $parts;
        $keys = self::records();
        if (!isset($keys[$id]) || $keys[$id]['revoked_at'] !== null
            || !is_string($keys[$id]['hash'])) {
            return null;
        }
        // `hash_equals` and not `===`: the comparison is against a value an
        // attacker supplies and repeats, which is the one place a byte-by-byte
        // early exit is measurable.
        if (!hash_equals($keys[$id]['hash'], hash('sha256', $secret))) {
            return null;
        }
        return $keys[$id];
    }

    /**
     * HOW MANY ATTESTATION PUBLIC KEYS ONE CONNECTOR KEY MAY HOLD, and why it
     * is two rather than one or many.
     *
     * ONE would make every rotation an outage: the moment the caller starts
     * signing with a new private key, every body it sends fails `mismatch`
     * until a human has pasted the new public key here -- and every body it
     * sent with the old one fails from the moment they paste it. Two gives the
     * rotation an overlap window, in which both the retiring and the arriving
     * key verify and the operator can see from the reply's `kid` which one is
     * actually live before removing the other.
     *
     * MANY would make the window unbounded. Each stored key is a private key
     * somewhere that can still publish as this tenant, so the list is the
     * blast radius of every seed that was ever compromised and never pruned;
     * a third entry is refused rather than rotated out silently, because the
     * one thing an operator must not be able to do by accident is leave a
     * retired key live.
     */
    public const MAX_VERIFY_KEYS = 2;

    /**
     * PASTE AN ATTESTATION PUBLIC KEY ONTO A CONNECTOR KEY.
     *
     * MANUAL, AND THERE IS NO ROUTE THAT DOES THIS. Not an upload endpoint,
     * not a field on the key issue API, nothing a request can reach: whoever
     * can set the verifying key IS the boundary this whole class draws, so a
     * grant that could set it would be a grant that could sign anything. It is
     * a paste on a screen behind `manage_options`, on purpose, and the cost of
     * that -- an operator typing 44 characters during a rotation -- is the
     * price of the property.
     *
     * REFUSES rather than stores, in every case a wrong paste can produce: a
     * key that is not base64, one that is not 32 bytes (a 31- or 33-byte value
     * is a truncated or over-long paste, and `sodium_crypto_sign_verify_detached`
     * throws on one rather than returning false), a kid that is not 16
     * lowercase hex, a kid this key already holds, and a third entry. Stored
     * wrong, every one of them surfaces later as `mismatch` on a client's
     * site -- a branch that says the body was tampered with, over a paste.
     *
     * @param string $id     The connector key's public id.
     * @param string $kid    16 lowercase hex, as the signing side names the key.
     * @param string $public The 32-byte Ed25519 public key, standard base64.
     *
     * @return true|string True, or the sentence to put in front of a human.
     */
    public static function add_verify_key(string $id, string $kid, string $public) {
        $keys = self::records();
        if (!isset($keys[$id]) || !is_array($keys[$id])) {
            return 'no connector key on this site has that id, so there is nothing to attach a public key to';
        }
        $kid = trim($kid);
        if (preg_match('/\A[0-9a-f]{16}\z/', $kid) !== 1) {
            return 'the key id must be exactly 16 lowercase hex characters, as the signing side spells it; '
                 . 'an uppercase or shortened one is a different name for the same key';
        }
        $public = trim($public);
        $raw = $public === '' ? false : base64_decode($public, true);
        if ($raw === false) {
            return 'the public key must be standard base64, exactly as the signing side prints it; '
                 . 'this value is not base64 at all and nothing was stored';
        }
        if (strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return sprintf('an Ed25519 public key is %d bytes and this one decodes to %d; '
                           . 'a truncated or over-long paste would refuse every publish as tampered with, '
                           . 'so nothing was stored',
                           SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($raw));
        }
        $stored = self::verify_keys($id);
        foreach ($stored as $record) {
            if (($record['kid'] ?? null) === $kid) {
                return sprintf('this connector key already holds a public key under the name %s; '
                               . 'remove it before pasting another, so one name never means two keys', $kid);
            }
        }
        if (count($stored) >= self::MAX_VERIFY_KEYS) {
            return sprintf('this connector key already holds %d attestation public keys, which is the most '
                           . 'one may hold; remove the retired one before pasting a third, so a rotation '
                           . 'never leaves a key live that nobody meant to keep',
                           self::MAX_VERIFY_KEYS);
        }
        $stored[] = ['kid' => $kid, 'pk' => $public, 'added' => time()];
        $keys[$id]['verify'] = array_values($stored);
        update_option(self::OPTION, $keys);
        return true;
    }

    /**
     * Take one attestation public key off a connector key, by its kid.
     *
     * The other half of the two-key window: a rotation that cannot remove the
     * retired key is a rotation that can only ever be done once.
     */
    public static function remove_verify_key(string $id, string $kid): bool {
        $keys = self::records();
        if (!isset($keys[$id]) || !is_array($keys[$id])) {
            return false;
        }
        $kept = [];
        $found = false;
        foreach (self::verify_keys($id) as $record) {
            if (($record['kid'] ?? null) === $kid) {
                $found = true;
                continue;
            }
            $kept[] = $record;
        }
        if (!$found) {
            return false;
        }
        $keys[$id]['verify'] = $kept;
        update_option(self::OPTION, $keys);
        return true;
    }

    /**
     * THE ATTESTATION PUBLIC KEYS THIS CONNECTOR KEY HOLDS.
     *
     * A LIST, AND NEVER A SINGLE KEY. `CadenceAttestation::verify` looks the
     * presented kid up in it rather than taking the first -- during an overlap
     * window the first is as likely to be the retiring one as the arriving
     * one, and a verifier that took it would refuse every body signed with the
     * other half of its own rotation.
     *
     * FAILS TO THE EMPTY LIST. A record with no `verify` key, one holding
     * something that is not a list, and a key id this site does not have are
     * all "no public key here", which is a refusal at the call site and never
     * an admission.
     *
     * @return list<array{kid: string, pk: string, added: int}>
     */
    public static function verify_keys(string $id): array {
        $keys = self::records();
        $stored = $keys[$id]['verify'] ?? null;
        if (!is_array($stored) || !array_is_list($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $record) {
            if (is_array($record) && isset($record['kid'], $record['pk'])
                && is_string($record['kid']) && is_string($record['pk'])) {
                $out[] = $record;
            }
        }
        return $out;
    }

    /**
     * IS THE UNSIGNED-PUBLISH EXEMPTION SET ON THIS KEY, and who set it when?
     *
     * `['set_at' => int, 'by_user' => int|null]` or null. The record and not a
     * bool, because the flag is a migration affordance and the two questions
     * an operator asks about one are "is it still on" and "who turned it on":
     * a bare `true` on a site nobody remembers configuring is a boundary
     * nobody can account for.
     *
     * WHAT IT EXEMPTS IS AN ABSENCE AND NEVER A FAILURE -- see
     * `CadenceAttestation::verify`. It is stored here, read there, and the
     * distinction lives in exactly one place.
     *
     * @return array{set_at: int, by_user: int|null}|null
     */
    public static function unsigned_ok(string $id): ?array {
        $keys = self::records();
        $flag = $keys[$id]['unsigned_ok'] ?? null;
        if (!is_array($flag) || !isset($flag['set_at'])) {
            return null;
        }
        return ['set_at' => (int) $flag['set_at'],
                'by_user' => isset($flag['by_user']) ? (int) $flag['by_user'] : null];
    }

    /**
     * Turn the unsigned-publish exemption on or off for one connector key.
     *
     * SETTING IT RECORDS WHEN AND BY WHOM. A migration affordance with no
     * provenance is one an audit cannot close: the question a year later is
     * never "is it on" alone but "was this deliberate", and a bare flag cannot
     * answer it. Clearing it removes the record rather than writing a false
     * one -- absent means off, and there is one spelling of off.
     */
    public static function set_unsigned_ok(string $id, bool $on, $by_user = null): bool {
        $keys = self::records();
        if (!isset($keys[$id]) || !is_array($keys[$id])) {
            return false;
        }
        if (!$on) {
            unset($keys[$id]['unsigned_ok']);
            update_option(self::OPTION, $keys);
            return true;
        }
        $keys[$id]['unsigned_ok'] = [
            'set_at'  => time(),
            'by_user' => self::user_id($by_user),
        ];
        update_option(self::OPTION, $keys);
        return true;
    }

    /** @return array<string, array> */
    private static function records(): array {
        $keys = get_option(self::OPTION, []);
        return is_array($keys) ? $keys : [];
    }
}
