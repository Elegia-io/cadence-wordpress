<?php
/**
 * Replace the text of a post this connector published, or refuse.
 *
 * THE FAILURE THIS IS SHAPED AROUND IS THE OVERWRITE. Somebody opened the
 * article in wp-admin and fixed a sentence. The pipeline, holding a copy it
 * read before that, sends the rewrite it had queued. Applied, the hand edit is
 * gone, no revision of it is offered anywhere the caller can see, and the
 * answer says the request succeeded -- because it did. That is the same class
 * of failure as a wrong translation link: it destroys work somebody did by
 * hand, and nothing reports it.
 *
 * SO INTENT IS STATED, NEVER INFERRED. `/content` refuses to update, and that
 * refusal is right for a retry and wrong for a rewrite; the two are separated
 * here by the caller saying which it means, at its own endpoint, rather than
 * by this code guessing from a body that differs. A caller asking to replace
 * names the post AND the revision it believes that post holds, and this runs
 * where the truth is: it re-reads the post and refuses when the two disagree.
 *
 * AND WHOSE POST IT IS IS A SEPARATE QUESTION FROM WHICH POST IT IS. The
 * identifier answers the second; it is not a secret -- it travels wherever the
 * caller already shows its own work -- so a key holding
 * `content.replace` on a site that holds two keys can name another tenant's
 * piece and have every other check agree. The creating key's public id is
 * stamped on the post by `/content`, and `CadenceKey::created_by` is asked
 * here before anything is decided about the text.
 *
 * DIRECTION OF ERROR. A refused rewrite costs the caller one re-read. An
 * applied one costs a human work they cannot get back from here. Every refusal
 * below therefore writes nothing, and every precondition is checked before the
 * single write happens.
 *
 * AND THE CHECK IS MADE WHERE THE WRITE IS MADE. A check that merely happens
 * earlier in the same function is not a guard against a concurrent writer: the
 * edit that matters is the one that lands between the two. `get_post` answers
 * from WordPress's object cache, which the process doing the editing
 * invalidates in ITS process, so this one can hold a WP_Post whose text the
 * database no longer has -- and every check made against that copy passes
 * while the write destroys something else. So the text is read from the row
 * itself, `FOR UPDATE`, inside a transaction the write then happens in.
 *
 * WHAT THAT DOES AND DOES NOT CLOSE. A second `/content/replace` arriving
 * while this one runs waits on the row and then reads what this one wrote, so
 * it refuses instead of clobbering: two overlapping rewrites can no longer
 * both pass their check. A human's wp-admin save is not co-operating -- it
 * takes no lock of ours -- but it takes the row's, so it either commits before
 * the read here (and is seen, and refuses this) or blocks until this commits
 * and then lands ON TOP of the rewrite. Either way the hand edit survives; the
 * case it does not cover is a site whose `wp_posts` is MyISAM, where the
 * transaction is accepted and does nothing.
 *
 * @package cadence-connector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceReplaceRequest {

    /**
     * Every code `run` can refuse with. Published for the same reason the
     * other two writers publish theirs: so the REST layer can be tested for
     * covering all of them rather than the ones its own tests happened to name.
     */
    public const REFUSAL_CODES = [
        'bad_replacement',
        'post_missing',
        'replace_other_key',
        'identifier_mismatch',
        'existing_post_type_out_of_scope',
        'revision_mismatch',
        'update_failed',
        'no_row_lock',
        CadenceAttestation::CODE,
        'post_adopted',
        'confirmation_unsigned',
        'confirmation_wrong_site',
        'confirmation_expired',
        'confirmation_spent',
    ];

    /**
     * THE SPENT CONFIRMATIONS. One row per confirmed rewrite this post took,
     * each the sha256 of its verified material, written under the row lock in
     * the same transaction as the text.
     */
    public const SPENT_META = '_cadence_rewrite_spent';

    /** The three fields a confirmed rewrite of an adopted post carries, all or none. */
    public const CONFIRMATION = ['overwrite_adopted', 'site', 'issued_at'];

    /**
     * A REFUSAL CARRIES A CODE AS WELL AS A REASON. The reason is prose for a
     * human reading a log and is free to change. The code is the API, and it
     * tells the caller which of two things to do: re-read this site and try
     * again (`post_missing`, `identifier_mismatch`, `revision_mismatch` -- the
     * site disagrees with what the caller believed) or stop and fix the
     * request (`bad_replacement` -- no re-read can help). `no_row_lock` is
     * neither: the site cannot serialise this at all, and nothing about the
     * request is wrong.
     *
     * @param array $body the JSON body, already decoded
     * @param list<string>|null $post_types the post types the presenting key
     *                    names, or null for a key that names none -- which is
     *                    ANY registered type, the compatibility value a key
     *                    issued before the field existed carries. Required
     *                    rather than defaulted for the reason `/content` makes
     *                    it required: null is the WIDE case, so a default
     *                    would let a call site's omission read as a key that
     *                    deliberately named no type.
     * @param string|null $key_id the public id of the presenting key. Null is
     *                    a caller this route cannot name, and it reaches only
     *                    the posts that carry no key stamp -- every post that
     *                    names a key is refused. Required for the same reason,
     *                    from the other direction: a call site that forgot it
     *                    would be refusing writes rather than admitting them,
     *                    but it would be doing so silently.
     * @return array{ok: bool, created?: bool, post_id?: int, revision?: string, code?: string, reason?: string}
     */
    public static function run(array $body, ?array $post_types, ?string $key_id,
                               ?string $attestation): array {
        $fields = self::validate($body);
        if (is_string($fields)) {
            return ['ok' => false, 'code' => 'bad_replacement', 'reason' => $fields];
        }

        // THE ATTESTATION, BEFORE THIS SITE IS ASKED ANYTHING AT ALL.
        //
        // The same placement as `/content`'s and for the same reasons, and one
        // more that is this route's own: every refusal below reads the post
        // being replaced. `post_missing` says whether an id exists here,
        // `replace_other_key` which key made it, `identifier_mismatch` which
        // piece it is -- three facts about a client's site, each answerable one
        // refusal at a time by a caller holding a leaked connector key and no
        // signing key. Verified first, that caller learns nothing.
        //
        // `validate` has already refused a `post_id` that is not an integer, so
        // the material's decimal-ASCII rendering of it is never a coercion.
        $signable = self::signable($fields);
        $attested = CadenceAttestation::verify($attestation, '/content/replace', $signable, $key_id);
        if ($attested['ok'] !== true) {
            return ['ok' => false, 'code' => $attested['code'],
                    'reason' => $attested['reason'],
                    'attestation_branch' => $attested['branch']];
        }

        // READ THE POST BEFORE ANYTHING IS DECIDED ABOUT IT. `wp_update_post`
        // on an id that is not there does not fail in the way a caller would
        // expect, and every check below is a question about a post rather than
        // about the request.
        $post = get_post($fields['post_id']);
        if (!$post instanceof WP_Post) {
            return ['ok' => false, 'code' => 'post_missing', 'reason' => sprintf(
                'this site has no readable post %d, so there is nothing here to replace',
                $fields['post_id'])];
        }

        // AND IT HAS TO BE THIS KEY'S POST, ASKED BEFORE ANYTHING ELSE ABOUT
        // IT. `identifier_mismatch` below answers "is this the post you say it
        // is"; this answers "is this post yours", and they are different
        // questions -- a key holding `content.replace` that learns another
        // tenant's `piece_id` some other way satisfies the
        // first over a post it has nothing to do with, and a replace
        // overwrites a published title and body.
        //
        // THE ORDER IS THE GUARD, NOT MERELY THE PLACE THE LINE SITS.
        // `identifier_mismatch` fires on a post that exists and tells the two
        // cases apart -- "a different piece this connector published" against
        // "not a piece this connector published". Run first, it would answer
        // for every post another tenant holds whose identifier is not the one
        // named, and only the post that DOES carry the named identifier would
        // reach this branch: the pair of cases the refusal must not separate
        // would then be separated by which refusal came back. Asked first,
        // every post that names another key gets this one sentence whatever
        // identifier it carries, and `identifier_mismatch`'s finer answer is
        // only ever shown for a post this key already reaches.
        //
        // A post carrying NO stamp takes the documented null-identity path in
        // `CadenceKey::created_by` and is admitted, exactly as on the other two
        // routes: every piece already on a client's site predates the stamp,
        // and refusing those would break every rewrite of work the pipeline
        // has already done. A STAMPED post asked about by no key at all
        // (`$key_id` null) is refused there -- no identity is not a wildcard.
        if (!CadenceKey::created_by($fields['post_id'], $key_id)) {
            // The id and the claim, and nothing else: not the key id the post
            // carries, which is another tenant's identifier; not the post's
            // type, its title, its author or its revision. Its own sentence
            // and its own code, never the linking route's
            // `post_out_of_scope` -- that one says nothing was LINKED, and a
            // caller matching on it would be told about an act it did not ask
            // for. That route merges this predicate with `scope_admits`
            // because it verifies no attestation; here the verify above has
            // already run, so the finer answer costs a signing key.
            return ['ok' => false, 'code' => 'replace_other_key', 'reason' => sprintf(
                'post %d was published through a different connector key, and a key may '
                . 'replace only its own pieces; nothing was written',
                $fields['post_id'])];
        }

        // AN ADOPTED POST IS REWRITTEN ONLY OVER THE CLIENT'S CONFIRMATION.
        //
        // Its text was written by a person, not by this connector, and a
        // rewrite overwrites it with no copy kept here. So the rewrite must
        // carry a confirmation, and each of five conjuncts has its own code:
        // the confirmation is there and says yes; it was signed, because an
        // exempt key sends no header and its fields would then be nobody's
        // statement; it names this site, because a staging clone verifies the
        // same public key; it is fresh; and it has not already been spent on
        // this post, because the revision is only a hash of the text and a
        // restore from WordPress Revisions puts a post back on the revision a
        // captured confirmation names.
        //
        // AFTER the identity check, so none of the five answers anything about
        // a post this key does not reach. On a post this connector created
        // none of them is asked: its text is the pipeline's own.
        $spent = null;
        if (get_post_meta($fields['post_id'], CadenceAdoptRequest::ADOPTED_META, true) !== '') {
            $confirmed = self::confirmed($fields, $attested);
            if (isset($confirmed['code'])) {
                return $confirmed;
            }
            $spent = $confirmed['spent'];
        }

        // THE POST AND THE PIECE HAVE TO BE THE SAME THING. The caller holds a
        // map from its own identifier to a WordPress post id; that map lives on
        // another machine and goes stale -- a restore from backup, a migration,
        // a post deleted and re-created -- and post 41 is then somebody else's
        // article, which this would rewrite while every other check agreed.
        $stored = get_post_meta($fields['post_id'], CadenceContentRequest::META, true);
        if ($stored !== $fields['piece_id']) {
            // WITHOUT NAMING WHAT THE SITE HOLDS. The stored identifier is
            // protected meta, which the REST API does not expose; a refusal
            // that spelled it out would hand it to any caller holding a key
            // with `content.replace`, and that caller's next attempt would
            // pass this check for a piece it has never had anything to do
            // with. The two cases are still told apart, which is what a caller
            // debugging a stale map actually needs: this post is one of ours
            // under some other identifier, or it is not one of ours at all.
            return ['ok' => false, 'code' => 'identifier_mismatch', 'reason' => sprintf(
                'post %d is not `%s` on this site; it is %s',
                $fields['post_id'],
                $fields['piece_id'],
                is_string($stored) && $stored !== ''
                    ? 'a different piece this connector published'
                    : 'not a piece this connector published')];
        }

        // AND THE PIECE IS IN A TYPE THIS KEY STILL REACHES.
        //
        // WHAT IT PROTECTS, which is the only reason it is here: the
        // null-identity path above admits every post that predates the stamp,
        // and over that set two keys on one site do not separate at all. The
        // type scope is the one narrowing still available there -- a key that
        // names `post` cannot rewrite an unstamped `page`, so a tenant that
        // publishes posts does not reach the pre-stamp pages of a tenant that
        // publishes pages. It also makes an operator's narrowing of a live key
        // effective over the pieces that key already has: `/content` refuses
        // to hand out the id and the revision of such a piece
        // (`existing_post_type_out_of_scope`), but a caller that recorded the
        // pair before the scope was narrowed keeps it forever, so withholding
        // it is a disclosure control and not a door.
        //
        // THE SAME CODE AS `/content`'s, deliberately: it is the same fact --
        // this key's own piece sits in a type the key does not publish into --
        // and the operator's fix is the same one, re-issue the key wider. The
        // sentence differs because the act does; the code is the API and the
        // reason is prose.
        //
        // AND `CadenceKey::is_content_type` IS ASKED IN THE SAME BRANCH,
        // WITH THE SAME CODE AND THE SAME SENTENCE -- not a separate check
        // ahead of this one. A revision or an attachment is refused whatever
        // the key's scope, and a null (wide) scope also refuses a registered
        // type that is not publicly viewable (see that method's docblock);
        // merged here rather than split, the two refusals are indistinguishable
        // to a caller, which is the point -- a separate, earlier check would
        // let a caller tell "this type is never content" apart from "this key
        // was never scoped to it", and neither is the site's to disclose.
        //
        // AFTER the identity check and after `identifier_mismatch`, so it can
        // only ever fire over a post this key reaches and that IS the piece
        // named. Asked earlier it would answer whether another tenant's post,
        // or any post on the site, is inside this key's type scope -- naming
        // the target's type is exactly what the refusals here must not do.
        //
        // AND THE POSITION IS PINNED, not merely asserted here: move this
        // block above `identifier_mismatch` and
        // `ReplaceRequestTest::test_the_type_scope_cannot_be_asked_about_a_post_that_is_not_the_piece`
        // fails, because two posts this connector never published stop
        // answering with the same refusal and the pair of codes becomes a type
        // oracle over every post id a caller cares to name.
        //
        // `null` names no type and means ANY, so a key issued before the field
        // existed replaces what it always replaced.
        $actual_type = get_post_type($fields['post_id']);
        if (!CadenceKey::is_content_type($actual_type, $post_types)
                || ($post_types !== null && !in_array($actual_type, $post_types, true))) {
            // The key's own scope and the identifier the caller sent, neither
            // of which is the site's to disclose -- and NOT the type the post
            // is in, which is: neither sentence below names it, so each is
            // ONE FIXED STRING per key configuration and nothing about the
            // refusal varies with what type the post actually is. The null
            // branch states the fix: naming the type explicitly on the key's
            // scope is what admits a type that is not publicly viewable.
            return ['ok' => false, 'code' => 'existing_post_type_out_of_scope', 'reason' => $post_types !== null
                ? sprintf('the piece %s is on a post of a type this key does not publish into; '
                    . 'this key publishes into %s, and nothing was written',
                    $fields['piece_id'], implode(', ', $post_types))
                : sprintf('the piece %s is on a post of a type this key does not publish into; '
                    . 'naming that type on the key\'s own post-type scope would allow it, and '
                    . 'nothing was written', $fields['piece_id'])];
        }

        // FROM HERE THE ROW IS HELD. Everything above is decided from copies
        // -- the cache, the meta table -- and none of it is what a rewrite
        // overwrites; the title and the content are, and those are read below
        // from the row, locked, with the write inside the same transaction.
        // A refusal taken from here on releases the row before it returns:
        // holding it to the end of the request would make every other writer
        // of that post wait on a request that already decided to do nothing.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control has no WordPress API, and must not be cached.
        if ($wpdb->query('START TRANSACTION') === false) {
            return ['ok' => false, 'code' => 'no_row_lock', 'reason' =>
                'this site would not open a transaction, so the text cannot be '
                . 'checked and written as one act; nothing was attempted'];
        }

        try {
            // The two fields a replacement overwrites, and no others: a
            // refusal over anything else would refuse work that is safe.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a prepared SELECT ... FOR UPDATE row lock; no API takes one, and a cached read would defeat the lock.
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT post_title, post_content FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE",
                $fields['post_id']));
            // Null for a row that is not there and for a read that failed
            // alike, and the answer is the same either way: this connector
            // replaces, and a caller that wanted a post to exist has an
            // endpoint for that.
            if ($row === null) {
                return self::release($wpdb, ['ok' => false, 'code' => 'post_missing',
                    'reason' => sprintf(
                        'post %d could not be read for writing, so there is nothing here to replace',
                        $fields['post_id'])]);
            }

            // AND THE CACHE IS DROPPED THE MOMENT THE ROW IS LOCKED. The read
            // at the top of `run` may have filled it, and `wp_update_post`
            // below fills in any field this request does not name -- status,
            // password -- from that same cache rather than from the row this
            // code just locked. Left standing, a status change or a password
            // set that landed between the two reads is merged straight back
            // out by the write meant only to replace text, which is the same
            // failure this file exists to close one field over. Cleared here,
            // `wp_update_post`'s own read sees what the lock just read.
            //
            // BOTH CALLS, NOT EITHER ALONE. `clean_post_cache` is itself a
            // no-op while `wp_suspend_cache_invalidation()` is set -- WordPress
            // sets it during an import, and a client's own plugin can set it
            // around any bulk operation -- so the direct `wp_cache_delete`
            // is what still empties the object-cache group `clean_post_cache`
            // would otherwise have cleared, over exactly the site condition
            // this file's own header names as the one case a request-scoped
            // transaction cannot see coming.
            //
            // `wp_cache_delete` CLEARS ONLY THE POST ROW -- the `posts` cache
            // group `clean_post_cache` would also have dropped the post's
            // meta and term-relationship caches, and this fallback does not.
            // That is enough here: `status` and `password` are fields of the
            // row itself, read back through `get_post`, so the one cache this
            // write's own merge can be fed a stale copy of is the one this
            // clears. A future field read from meta or terms would need its
            // own cache dropped the same deliberate way, not assumed covered.
            clean_post_cache($fields['post_id']);
            wp_cache_delete($fields['post_id'], 'posts');

            // THE FIFTH CONJUNCT, IN THE SAME CRITICAL SECTION AS ITS WRITE.
            // Asked before the lock, two copies of one confirmed body could
            // both pass it and then both write, the second over the same text
            // when the rewrite leaves the text as it was. Here the second
            // waits on the row, and reads the first one's record. EVERY spent
            // digest is kept, one row each: a post restored twice sits on the
            // captured revision again, and only the full list still names the
            // older confirmation. The meta cache is dropped first for the
            // reason the post cache is dropped above; the read that follows is
            // the transaction's first consistent read, taken after the lock.
            if ($spent !== null) {
                wp_cache_delete($fields['post_id'], 'post_meta');
                if (in_array($spent, (array) get_post_meta($fields['post_id'], self::SPENT_META, false), true)) {
                    return self::release($wpdb, ['ok' => false, 'code' => 'confirmation_spent',
                        'reason' => sprintf('this confirmation already rewrote post %d; a new rewrite '
                            . 'needs a new confirmation, and nothing was written', $fields['post_id'])]);
                }
            }

            // AND THE TEXT HAS TO BE THE TEXT THE CALLER SAW. Read from the
            // locked row, not from the request and not from the cached copy: a
            // revision the request carried on both sides would be the caller's
            // claim checked against the caller's claim, and one taken from the
            // cache would be checked against text this site may no longer hold.
            $actual = CadenceRevision::of((string) $row->post_title, (string) $row->post_content);
            if ($actual !== $fields['revision']) {
                return self::release($wpdb, ['ok' => false, 'code' => 'revision_mismatch',
                    'reason' => sprintf(
                        'post %d holds revision %s on this site, but this replacement is for %s; refusing to overwrite text the caller has not seen',
                        $fields['post_id'], $actual, $fields['revision'])]);
            }

            return self::write($wpdb, $fields, $attested, $spent);
        } catch (Throwable $e) {
            // Any plugin on the site can hang code on `save_post`, and code
            // that throws inside an open transaction would otherwise leave the
            // row locked for the rest of the process. The exception is the
            // caller's to see, so it is re-thrown rather than turned into a
            // refusal this code cannot honestly describe.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control has no WordPress API, and must not be cached.
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /** Roll the held row back and hand the refusal on unchanged. */
    private static function release(object $wpdb, array $refusal): array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control has no WordPress API, and must not be cached.
        $wpdb->query('ROLLBACK');
        return $refusal;
    }

    /**
     * The single write, inside the transaction its precondition was checked in.
     *
     * @param array{piece_id: string, post_id: int, revision: string, title: string, content: string} $fields
     */
    private static function write(object $wpdb, array $fields, array $attested, ?string $spent): array {
        $id = wp_update_post([
            'ID'           => $fields['post_id'],
            'post_title'   => $fields['title'],
            'post_content' => $fields['content'],
            // NO `post_status`, AND NO IDENTIFIER. Whether the piece is in
            // front of the public is not a rewrite's decision: republishing
            // something a human took down is the same destruction one field
            // across, and it would be reported as a successful rewrite. The
            // identifier already on the post is what this request matched
            // against, so writing it again could only change it.
        ], true);

        // WP_Error, or 0, and neither is an exception -- exactly as on the
        // insert path. Read as an id, `0` is falsy, which is what "no post"
        // looks like everywhere else.
        if (is_wp_error($id)) {
            return self::release($wpdb, ['ok' => false, 'code' => 'update_failed',
                'reason' => 'WordPress refused the update: ' . $id->get_error_message()]);
        }
        if (!is_int($id) || $id < 1) {
            return self::release($wpdb, ['ok' => false, 'code' => 'update_failed',
                'reason' => 'WordPress returned no post id and no error']);
        }

        // THE CONFIRMATION IS SPENT IN THE SAME TRANSACTION AS THE TEXT, so a
        // rewrite that commits has always recorded it and one that rolls back
        // never has.
        if ($spent !== null && add_post_meta($id, self::SPENT_META, $spent) === false) {
            return self::release($wpdb, ['ok' => false, 'code' => 'update_failed',
                'reason' => 'the confirmation could not be recorded as spent, so the rewrite was not kept']);
        }

        // `created` is false and says so, rather than being left out: the two
        // endpoints answer in one vocabulary, and a caller reading `created`
        // never has to know which one it called.
        // Read back before the row is released, so the revision answered is
        // the one this site holds under the same lock the write was made in.
        $answer = array_merge(
            ['ok' => true, 'created' => false, 'post_id' => $id,
             // WHAT THE ATTESTATION SAID, carried down from `run` rather than
             // re-derived: one verification per request, and a second here
             // would be a second chance to disagree with the first.
             'attestation' => $attested['attestation']]
            + (isset($attested['kid']) ? ['attestation_kid' => $attested['kid']] : []),
            CadenceRevision::answer($id)
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control has no WordPress API, and must not be cached.
        $wpdb->query('COMMIT');
        return $answer;
    }

    /**
     * Four of the five conjuncts over an adopted post, in order. A refusal,
     * or the digest the fifth, `confirmation_spent`, is asked about under
     * the row lock.
     *
     * @return array{spent: string}|array{ok: false, code: string, reason: string}
     */
    private static function confirmed(array $fields, array $attested): array {
        if (($fields['overwrite_adopted'] ?? null) !== true) {
            return ['ok' => false, 'code' => 'post_adopted', 'reason' => sprintf(
                'post %d was adopted, not made by this connector; a rewrite needs the client\'s '
                . 'confirmation, and nothing was written', $fields['post_id'])];
        }
        if (($attested['attestation'] ?? null) !== 'verified') {
            return ['ok' => false, 'code' => 'confirmation_unsigned', 'reason' =>
                'a confirmation to rewrite an adopted post is honoured only when signed, and this '
                . 'request carried no signature; nothing was written'];
        }
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        if ($fields['site'] !== $host) {
            return ['ok' => false, 'code' => 'confirmation_wrong_site', 'reason' =>
                'the confirmation was signed for another site; nothing was written'];
        }
        $at = CadenceAdoptRequest::issued_at($fields['issued_at']);
        if ($at === null || abs(time() - $at) > CadenceAdoptRequest::WINDOW) {
            return ['ok' => false, 'code' => 'confirmation_expired', 'reason' => sprintf(
                'the confirmation is more than %d seconds from this site\'s clock; nothing was written',
                CadenceAdoptRequest::WINDOW)];
        }
        // THE DIGEST OF THE VERIFIED MATERIAL, so the record names exactly the
        // bytes that were signed and nothing a caller can vary without a new
        // signature. Whether it is already spent is asked under the row lock,
        // in `run`, where the record is also written.
        return ['spent' => hash('sha256',
            CadenceAttestation::material('/content/replace', self::signable($fields)))];
    }

    /**
     * The validated fields as the material signs them: the confirmation's
     * boolean rendered `true` or `false`, as `create_group` is. The decision
     * reads the raw boolean from `$fields`, never this rendering.
     */
    public static function signable(array $fields): array {
        if (array_key_exists('overwrite_adopted', $fields) && is_bool($fields['overwrite_adopted'])) {
            $fields['overwrite_adopted'] = $fields['overwrite_adopted'] ? 'true' : 'false';
        }
        return $fields;
    }

    /**
     * The body's own shape, checked without coercion.
     *
     * @return array{piece_id: string, post_id: int, revision: string, title: string, content: string}|string
     */
    private static function validate(array $body) {
        // ONE NAME FOR ONE VALUE ACROSS THE WIRE, and it is `/content`'s.
        // The wire settled on one spelling for this value, `piece_id`. This
        // route was written before that was settled and asked for
        // `external_id`, so a caller sending the same value to the two routes
        // had to spell it two ways -- reintroducing, on a route that had not
        // shipped yet, the exact mismatch a shared spelling exists to prevent.
        // Aligned here, at the cheapest moment there will ever be.
        //
        // The alias is the same one `/content` accepts and for the same reason: a
        // released connector answered to it, and a 0.1.0 caller must not break.
        // The reply says `piece_id` either way.
        if (!isset($body['piece_id']) && isset($body['external_id'])) {
            $body['piece_id'] = $body['external_id'];
        }
        foreach (['piece_id', 'revision', 'title', 'content'] as $key) {
            if (!isset($body[$key]) || !is_string($body[$key])) {
                return sprintf('%s must be present and a string', $key);
            }
        }
        if (trim($body['piece_id']) === '') {
            return 'piece_id must not be blank; it is what says which piece this replaces';
        }
        if (trim($body['revision']) === '') {
            return 'revision must not be blank; it is what says which text this replaces';
        }
        // Never coerced. `'41 OR 1'` is an integer to a loose check and a
        // different post to this site, and `true` is post 1.
        if (!is_int($body['post_id'] ?? null) || $body['post_id'] < 1) {
            return 'post_id must be a positive integer, and is never read from a string';
        }
        // THE CONFIRMATION: all three or none, tested by presence, so a
        // `null` is present and refused rather than read as absent.
        $present = array_values(array_filter(self::CONFIRMATION,
            static fn (string $name): bool => array_key_exists($name, $body)));
        if ($present !== [] && $present !== self::CONFIRMATION) {
            return 'overwrite_adopted, site and issued_at travel together: all three or none';
        }
        $confirmation = [];
        if ($present !== []) {
            if (!is_bool($body['overwrite_adopted'])) {
                return 'overwrite_adopted must be a JSON boolean';
            }
            if (!is_string($body['site']) || trim($body['site']) === '') {
                return 'site must be a non-blank string';
            }
            if (!is_string($body['issued_at']) || CadenceAdoptRequest::issued_at($body['issued_at']) === null) {
                return 'issued_at must be a UTC ISO-8601 time';
            }
            foreach (self::CONFIRMATION as $name) {
                $confirmation[$name] = $body[$name];
            }
        }
        return [
            'piece_id'    => $body['piece_id'],
            'post_id'     => $body['post_id'],
            'revision'    => $body['revision'],
            'title'       => $body['title'],
            'content'     => $body['content'],
        ] + $confirmation;
    }
}
