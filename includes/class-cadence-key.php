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
     */
    public const CAPABILITIES = ['content.publish', 'content.replace', 'translation.link'];

    /** The request header the key is presented in. */
    public const HEADER = 'X-Cadence-Key';

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
     * @return array{id: string, secret: string}|string
     */
    public static function issue(string $label, array $capabilities, $author) {
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
     */
    public static function author_for($presented): ?int {
        $author = self::grant($presented)['author'] ?? null;
        return is_int($author) && $author > 0 ? $author : null;
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
     * @return array<string, array{label: string, caps: list<string>, author?: int, created: int, revoked_at: int|null}>
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
     * @return array{label: string, caps: list<string>, author?: int}|null
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

    /** @return array<string, array> */
    private static function records(): array {
        $keys = get_option(self::OPTION, []);
        return is_array($keys) ? $keys : [];
    }
}
