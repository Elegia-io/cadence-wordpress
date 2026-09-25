<?php
/**
 * DID THIS BODY COME FROM THE TENANT WHO HOLDS THE SIGNING KEY?
 *
 * The connector key on `X-Cadence-Key` says a caller is entitled to publish
 * here. It does not say that THIS body is the body that tenant composed: a
 * key is a bearer credential, so anything that ever reads one -- a proxy, a
 * log, a misconfigured mirror -- can replay a publish or edit the text inside
 * one. This class asks the second question, over the bytes themselves.
 *
 * THE CANONICAL FORM IS DATA, AND IT IS NOT THIS FILE. It lives in a shared
 * attestation contract, with worked vectors, because two implementations in
 * two languages cannot be shown to agree on a byte layout by each describing
 * it in prose. This class is written AGAINST those vectors -- `AttestationTest`
 * composes every one of them and diffs bytes -- and if this file and that
 * file disagree, that file is right.
 *
 * WHY A SECOND DIGEST AND NOT `CadenceRevision`. They answer different
 * questions over different inputs: a revision says which text a POST holds
 * right now, derived from the site; a material says which body the caller
 * sent, derived from the request. The `%d:%s` length-prefix convention is shared
 * deliberately -- one spelling of "where this field ends" in the plugin rather
 * than two to keep true -- but the two are never interchangeable.
 *
 * @package cadence-connector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceAttestation {

    /** The request header the signature is presented in. */
    public const HEADER = 'X-Cadence-Attestation';

    /** The first line of every material, and the only version this reads. */
    public const PREFIX = 'cadence-attest-v1';

    /** The token that opens a well-formed header value. */
    public const VERSION = 'v1';

    /**
     * ONE CODE OVER FIVE BRANCHES.
     *
     * The branches are five different repairs and each refusal says which one
     * fired in its own sentence -- but they are one HTTP answer, because a
     * caller's handling of all five is identical: stop, and put a human on it.
     */
    public const CODE = 'attestation_unverified';

    /** The HTTP status every branch answers. */
    public const STATUS = 403;

    /**
     * WHICH FIELDS EACH ROUTE SIGNS, IN ORDER.
     *
     * Order is part of the form: a verifier that sorted these would accept a
     * body whose fields were swapped in transit, since the length prefixes
     * would still line up under a different assignment of values to names.
     *
     * `/content` signs six of its seven wire fields. `declared` is acted on and
     * never displayed, so tampering with it can only cause a refusal -- and a
     * payload key outside a route's list is IGNORED rather than refused,
     * because the body legitimately carries that one.
     *
     * `/content/replace` signs all five it requires. `post_id` and `revision`
     * are what say WHICH post and WHICH text the rewrite was written against;
     * unsigned, they aim a signed rewrite wherever a man in the middle likes.
     *
     * THE IDENTIFIER IS `piece_id` ON BOTH, never `external_id`: the alias is
     * resolved by each route's own validation before anything reaches here, so
     * the spelling a 0.1.0 caller used never appears in a material.
     */
    /**
     * THE ONE SIGNED FIELD THAT IS NOT A STRING.
     *
     * Named rather than inferred from the value's type, because inferring it
     * IS the coercion: a `post_id` that arrives as `'41'` is then signed as
     * the string `41`, which is the same bytes, and `'007'` is signed as three
     * bytes the signer never produced. One post, two signatures, and the
     * disagreement appears only on the bodies a leading zero reaches.
     */
    public const INTEGER_FIELD = 'post_id';

    /**
     * THE ONE ENTRY IN A FIELD ORDER THAT IS NOT A FIELD.
     *
     * `/translation-group` is the first route whose signed set is not a fixed
     * list: a translation group has as many members as it has languages. This
     * token stands where those members go, and `signed_field_order` expands it
     * into `member.<i>.<name>` for i counting from 0 over the members in
     * CANONICAL order. Everything else about the line format is unchanged -- a
     * member field is length-prefixed exactly like a top-level one.
     */
    public const MEMBERS = '@members';

    /** The four fields every member signs, in order, under its own prefix. */
    public const MEMBER_FIELDS = ['post_id', 'language_code', 'element_type',
                                  'source_language_code'];

    public const FIELDS = [
        '/content'           => ['piece_id', 'language', 'post_type', 'status', 'title', 'content'],
        '/content/replace'   => ['piece_id', 'post_id', 'revision', 'title', 'content'],
        // `/translation-group` signs `source_language_code` on every member
        // BECAUSE IT REACHES A WRITE: it goes straight into WPML's
        // `wpml_set_element_language_details`, so an intermediary that added or
        // changed one would change what the site records as a translation's
        // source. That is the test `/content`'s unsigned `declared` fails --
        // tampering there can only cause a refusal -- and it is why a member is
        // four fields and not three.
        //
        // NO MEMBER COUNT IS SIGNED, and that is not an oversight: every member
        // name carries its index, so dropping a member renumbers every one after
        // it and adding one appends a block. Either way the material differs. A
        // count would be a second spelling of a fact the framing already carries.
        '/translation-group' => ['trid', 'create_group', 'piece_id', self::MEMBERS],
        // THE ADOPT ROUTES SIGN WHICH SITE AND WHEN, last. Without `site` one
        // signed body serves every site the tenant's public key is pasted on;
        // without `issued_at` it serves again after a release.
        '/adopt/preview'     => ['piece_id', 'link', 'site', 'issued_at'],
        '/adopt'             => ['piece_id', 'post_id', 'language', 'site', 'issued_at'],
        '/adopt/release/preview' => ['link', 'site', 'issued_at'],
        '/adopt/release'     => ['piece_id', 'post_id', 'site', 'issued_at'],
    ];

    /**
     * ROUTES THE UNSIGNED-PUBLISH EXEMPTION DOES NOT REACH. They read and
     * stamp posts this plugin did not write, and their refusals are finer
     * than one "out of scope": every one of them must cost a signing key,
     * not a connector key alone. A key carrying the exemption and sending no
     * header is refused here under its own branch.
     */
    public const NO_EXEMPTION = ['/adopt', '/adopt/preview', '/adopt/release', '/adopt/release/preview'];

    /**
     * THE SIGNED FIELD ORDER FOR ONE BODY, with `@members` expanded.
     *
     * Derived from the fields themselves rather than carried beside them: the
     * member count is a property of the plan, and a verifier handed a count
     * would be trusting the sender about how much of its own body to read.
     *
     * @param array $fields The route's signable fields.
     * @return list<string>
     *
     * @throws InvalidArgumentException on a route this does not sign, or on a
     *         member map whose indices are not 0..n-1. Never reachable from a
     *         route: `link_fields` is the only thing that builds one.
     */
    public static function signed_field_order(string $route, array $fields): array {
        if (!isset(self::FIELDS[$route])) {
            throw new InvalidArgumentException('no signed field set for route ' . esc_html($route));
        }
        $order = [];
        foreach (self::FIELDS[$route] as $name) {
            if ($name !== self::MEMBERS) {
                $order[] = $name;
                continue;
            }
            // COUNTED FROM THE KEYS AND THEN REQUIRED TO BE CONTIGUOUS. A map
            // holding `member.0.` and `member.2.` has no reading -- signing the
            // two it can see would silently drop a member from the material and
            // verify a body nobody sent.
            $seen = [];
            foreach (array_keys($fields) as $key) {
                if (preg_match('/\Amember\.(\d+)\./', (string) $key, $m) === 1) {
                    $seen[(int) $m[1]] = true;
                }
            }
            for ($i = 0, $n = count($seen); $i < $n; $i++) {
                if (!isset($seen[$i])) {
                    throw new InvalidArgumentException('member indices are not 0..n-1');
                }
                foreach (self::MEMBER_FIELDS as $field) {
                    $order[] = 'member.' . $i . '.' . $field;
                }
            }
        }
        return $order;
    }

    /**
     * THE SIGNABLE REDUCTION OF ONE LINK PLAN, or why it has none.
     *
     * `/translation-group` is the one route whose body is not flat, so the plan
     * becomes the flat, ordered field map the material is composed from here --
     * in ONE place, because a second rendering of a boolean or of an absent
     * language is a second thing for two languages to keep true.
     *
     * THE SOURCE IS MEMBER 0, because it is the plan's `source` field. Which
     * member is the source is never inferred -- not from a language, not from an
     * order, not from a `source_language_code`.
     *
     * THE TRANSLATIONS ARE SORTED, NEVER TAKEN IN WIRE ORDER. They arrive as a
     * JSON object, and object key order is not a thing a signature may depend
     * on: an intermediary that re-serialises the body, a parser that sorts, or a
     * client library backed by a hash map would reorder it and every honest
     * request would be refused as `mismatch` -- blaming a tenant's key for a
     * proxy's JSON. So both halves sort, by `language_code` ascending AS BYTES,
     * ties broken by `post_id` ascending. The tie-break exists so the form is
     * TOTAL: two translations sharing a language is a plan neither side will
     * ever compose -- this route refuses it as `bad_plan` -- but verification
     * runs before that refusal, so the ordering has to be decided anyway.
     *
     * THE MAP KEY IS NOT SIGNED. `translations` is keyed by language, and the
     * key selects nothing: the write reads each member's own `language_code`.
     * It is also what makes the tie-break reachable at all, since two keys may
     * hold two members naming one language.
     *
     * A STRING RETURN IS A PLAN THAT CANNOT BE SIGNED AT ALL, and the caller
     * answers `bad_plan` over it -- not a sixth attestation branch. A
     * `create_group` that is not a JSON boolean, or a `post_id` that is a
     * string, has no rendering: `'true'` and `true` rendered alike would let a
     * plan the shape check refuses verify against a signature over a plan it
     * accepts. That refusal discloses nothing about the site, which is why it
     * may run before the signature is checked.
     *
     * @param array $plan The decoded body, NOT yet shape-checked.
     * @return array<string, string|int>|string
     */
    public static function link_fields(array $plan) {
        if (!array_key_exists('create_group', $plan) || !is_bool($plan['create_group'])) {
            return 'create_group is absent or is not a boolean, so this plan cannot be signed';
        }
        // ABSENT AND NULL RENDER ALIKE, for `trid` and `piece_id` both: a plan
        // that names no group and one that names none by omission are the same
        // zero-length value. A plan missing `trid` altogether is `bad_plan`
        // further down on its own grounds; it is not an attestation failure.
        $trid = $plan['trid'] ?? null;
        if ($trid !== null && (is_bool($trid) || !is_int($trid))) {
            return 'trid is neither null nor an integer, so this plan cannot be signed';
        }
        $piece_id = $plan['piece_id'] ?? null;
        if ($piece_id !== null && !is_string($piece_id)) {
            return 'piece_id is present and is not a string, so this plan cannot be signed';
        }
        $source = $plan['source'] ?? null;
        $translations = $plan['translations'] ?? null;
        if (!is_array($source) || !is_array($translations)) {
            return 'source or translations is not an object, so this plan cannot be signed';
        }

        $members = [];
        foreach (array_values($translations) as $i => $member) {
            $reason = self::signable_member($member, 'translation ' . ($i + 1));
            if (is_string($reason)) {
                return $reason;
            }
            $members[] = $member;
        }
        usort($members, static function (array $a, array $b): int {
            // AS BYTES. `strcmp` and never a locale- or case-aware comparison:
            // the other side sorts by the same rule in its own language, and a
            // collation would make the two implementations disagree on exactly
            // the codes an operator adds last.
            return strcmp($a['language_code'], $b['language_code'])
                ?: $a['post_id'] <=> $b['post_id'];
        });
        $reason = self::signable_member($source, 'source');
        if (is_string($reason)) {
            return $reason;
        }
        array_unshift($members, $source);

        $fields = [
            // `trid` IS NOT AN INTEGER FIELD. It renders as decimal ASCII of the
            // integer the check above has already required, so the rendering is
            // exact rather than a coercion -- and a null one renders empty,
            // which no decimal spelling collides with.
            'trid'         => $trid === null ? '' : (string) $trid,
            // THE ASCII LITERAL, lowercase, no quotes, never `1`/`0`.
            'create_group' => $plan['create_group'] ? 'true' : 'false',
            'piece_id'     => $piece_id === null ? '' : $piece_id,
        ];
        foreach ($members as $i => $member) {
            $prefix = 'member.' . $i . '.';
            // `post_id` STAYS AN INT so the material's own integer discipline
            // refuses a string one there too, in the place the bytes are made.
            $fields[$prefix . 'post_id']       = $member['post_id'];
            $fields[$prefix . 'language_code'] = $member['language_code'];
            $fields[$prefix . 'element_type']  = $member['element_type'];
            $fields[$prefix . 'source_language_code'] =
                $member['source_language_code'] ?? '';
        }
        return $fields;
    }

    /**
     * CAN THIS MEMBER BE RENDERED AT ALL, and if not, which value stopped it.
     *
     * Only the questions the BYTES ask: is there a value of the right type for
     * each of the four names. Whether a language code is well formed, whether an
     * element type is one WPML knows, whether two members claim one language --
     * all of that is the route's own `bad_plan`, decided after verification on
     * its own grounds, and folding it in here would answer for a body that
     * verifies perfectly.
     *
     * @param mixed $member
     * @return string|null
     */
    private static function signable_member($member, string $where): ?string {
        if (!is_array($member)) {
            return $where . ' is not an object, so this plan cannot be signed';
        }
        if (is_bool($member['post_id'] ?? null) || !is_int($member['post_id'] ?? null)) {
            return $where . ' has a post_id that is not an integer, so this plan cannot be signed';
        }
        foreach (['language_code', 'element_type'] as $name) {
            if (!is_string($member[$name] ?? null)) {
                return $where . ' has a ' . $name
                    . ' that is not a string, so this plan cannot be signed';
            }
        }
        $src = $member['source_language_code'] ?? null;
        if ($src !== null && !is_string($src)) {
            return $where
                . ' has a source_language_code that is neither absent nor a string, '
                . 'so this plan cannot be signed';
        }
        return null;
    }

    /**
     * THE EXACT BYTES A SIGNATURE IS TAKEN OVER.
     *
     * `cadence-attest-v1` LF route LF, then per signed field in order:
     * name `:` byte-length `:` value bytes LF.
     *
     * THE LENGTH IS BYTES. `strlen`, never `mb_strlen` -- the vector
     * `content-unicode-untrimmed-unnormalised` exists to kill the other
     * reading, and a character count agrees with a byte count on exactly the
     * ASCII bodies a first implementation tests with.
     *
     * NOTHING IS NORMALISED AND NOTHING IS TRIMMED. Not NFC, not a whitespace
     * collapse: the same vector carries `e` + U+0301 beside a precomposed
     * U+00E9 on purpose, and normalising either side collapses the two
     * spellings and moves the digest.
     *
     * THE LAST LINE IS TERMINATED, NOT SEPARATED. The material's final byte is
     * 0x0a.
     *
     * @param string $route  `/content`, `/content/replace` or
     *                       `/translation-group` -- the BARE path. Never the
     *                       mount point: a signature must not depend on where a
     *                       site mounts its REST API.
     * @param array  $fields The route's validated fields. Values as decoded,
     *                       with `post_id` still an int.
     *
     * @throws InvalidArgumentException on a route this does not sign, a field
     *         that is not there, or a `post_id` that is not an int. Never
     *         reachable from a route -- each one's own validation refuses all
     *         three before this is called -- and an exception rather than a
     *         sixth branch, because inventing one would widen a vocabulary
     *         whose closedness is the thing a caller reads.
     */
    public static function material(string $route, array $fields): string {
        $material = self::PREFIX . "\n" . $route . "\n";
        foreach (self::signed_field_order($route, $fields) as $name) {
            if (!array_key_exists($name, $fields)) {
                throw new InvalidArgumentException('signed field ' . esc_html($name) . ' is absent');
            }
            $value = $fields[$name];
            if (self::is_integer_field($name) && !is_int($value)) {
                throw new InvalidArgumentException(
                    esc_html($name) . ' is signed as an integer and is never read from a string');
            }
            // `post_id` IS SIGNED AS DECIMAL ASCII OF AN INTEGER, and a string
            // is REFUSED rather than coerced: `'007'` and `7` are one post and
            // two signatures, and a coercing verifier paired with a
            // non-coercing signer disagrees on exactly the bodies a leading
            // zero reaches. `CadenceReplaceRequest::validate` already refuses a
            // string `post_id` as `bad_replacement`; this is the same refusal
            // said again where the bytes are made, so a future call site cannot
            // reintroduce the coercion by arriving from somewhere else.
            if (is_int($value)) {
                $value = (string) $value;
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException('signed field ' . esc_html($name) . ' is not a string');
            }
            $material .= $name . ':' . strlen($value) . ':' . $value . "\n";
        }
        return $material;
    }

    /**
     * IS THIS NAME SIGNED AS DECIMAL ASCII OF AN INTEGER?
     *
     * `post_id` at the top level, and a member's own `post_id` under its index.
     * Named rather than inferred from the value's type, for the reason
     * `INTEGER_FIELD` gives: inferring it IS the coercion.
     */
    private static function is_integer_field(string $name): bool {
        return $name === self::INTEGER_FIELD
            || preg_match('/\Amember\.\d+\.' . self::INTEGER_FIELD . '\z/', $name) === 1;
    }

    /**
     * THE 32 RAW BYTES THAT ARE SIGNED.
     *
     * `hash('sha256', $m, true)`. The third argument is the whole point:
     * PHP's default is the 64-character HEX spelling, and a verifier that
     * signed the hex would verify perfectly against itself, pass every test
     * written in this repository, and fail against every body the caller ever
     * sent. It is the highest-risk item in the contract for exactly that
     * reason -- it fails in only one direction, across the language boundary.
     */
    public static function digest(string $material): string {
        return hash('sha256', $material, true);
    }

    /**
     * IS THIS HEADER VALUE WELL FORMED, AND WHAT IS IN IT?
     *
     * `['kid' => ..., 'signature' => <64 raw bytes>]`, or null for anything
     * else. Null is `malformed` at the call site and never a value this
     * tolerates: a verifier that skips what it does not understand is a
     * verifier an attacker appends to.
     *
     * EXACTLY THREE SPACE-SEPARATED TOKENS. A fourth is malformed rather than
     * an ignored extra.
     *
     * THE SIGNATURE IS UNPADDED base64url, 86 characters. A padded
     * 88-character token is malformed and is NOT decoded and tolerated: one
     * spelling, so `malformed` goes on meaning "this header was not composed by
     * the caller" rather than "some encoder differed".
     *
     * THE KID IS 16 LOWERCASE HEX. An uppercase or mixed-case kid is malformed
     * and not `unknown_kid` -- a case-insensitive lookup makes one key two
     * names, and two names for one key is what a rotation record must never
     * have to disambiguate.
     *
     * @return array{kid: string, signature: string}|null
     */
    public static function parse(string $value): ?array {
        // `explode` and not a split on runs of whitespace: two spaces between
        // two tokens is a fourth, empty token, which is malformed. A verifier
        // that collapsed runs would accept two spellings of one header.
        $tokens = explode(' ', $value);
        if (count($tokens) !== 3 || $tokens[0] !== self::VERSION) {
            return null;
        }
        [, $kid, $encoded] = $tokens;
        if (preg_match('/\A[0-9a-f]{16}\z/', $kid) !== 1) {
            return null;
        }
        if (preg_match('/\A[A-Za-z0-9_-]{86}\z/', $encoded) !== 1) {
            return null;
        }
        $signature = self::base64url_decode($encoded);
        if ($signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        return ['kid' => $kid, 'signature' => $signature];
    }

    /**
     * WHETHER THIS BODY WAS SIGNED BY A KEY THIS TENANT PASTED, and if not,
     * which of five different things went wrong.
     *
     * THE FIVE ARE A CLOSED VOCABULARY AND EACH GETS ITS OWN SENTENCE.
     * `absent` is a migration; `malformed` is a header the caller never
     * composed; `unknown_kid` is a rotation half-done; `no_public_key` is a site where
     * nobody pasted one; `mismatch` is the only one that is evidence rather
     * than configuration. One sentence covering two of them asserts an access
     * that never happened, and sends an operator to look at the wrong thing.
     *
     * THE EXEMPTION EXEMPTS AN ABSENCE AND NEVER A FAILURE. A header that
     * verifies answers `verified` WHATEVER the flag says; a header that is
     * absent on an exempt key answers `exempt`; a header that is PRESENT and
     * bad is refused even there. Absence is a site that has not been upgraded
     * yet, which is the thing the flag was set for. A bad signature is not a
     * migration and there is no reading of it under which it becomes one.
     *
     * @param string|null $presented The raw header, or null when it is absent.
     * @param string      $route     The bare path.
     * @param array       $fields    The route's validated fields.
     * @param string|null $key_id    The connector key this request authenticated
     *                               as. The verifying keys are stored per KEY,
     *                               so a tenant's rotation is a tenant's own.
     *
     * @return array{ok: bool, attestation?: string, kid?: string, branch?: string,
     *               code?: string, reason?: string}
     */
    public static function verify(?string $presented, string $route, array $fields,
                                  ?string $key_id): array {
        $exempt = $key_id !== null && CadenceKey::unsigned_ok($key_id) !== null;

        // ABSENT FIRST, because it is the only branch the exemption reaches and
        // the only one that is not evidence of anything. A header that is there
        // falls through to every check below it even on an exempt key.
        if ($presented === null || trim($presented) === '') {
            if ($exempt && in_array($route, self::NO_EXEMPTION, true)) {
                return self::refuse('exempt_refused',
                    'this route takes no exemption: this key carries the unsigned-publish exemption, '
                    . 'and a request here must be signed all the same; nothing was read or written');
            }
            if ($exempt) {
                return ['ok' => true, 'attestation' => 'exempt'];
            }
            return self::refuse('absent',
                'this request carried no ' . self::HEADER . ' header at all, and this key does not '
                . 'carry the unsigned-publish exemption; nothing was read, written or recorded');
        }

        $parsed = self::parse($presented);
        if ($parsed === null) {
            // IT NAMES THE SHAPE AND QUOTES NOTHING. The header is attacker
            // supplied, and echoing it back puts chosen bytes in a client's
            // log. The shape is what the person debugging this needs to see.
            return self::refuse('malformed',
                'the ' . self::HEADER . ' header was not readable as `v1 <16 lowercase hex> '
                . '<86 characters of unpadded base64url>`; it was not decoded, no key was looked '
                . 'up, and nothing was written');
        }

        // NO PUBLIC KEY BEFORE UNKNOWN KID, because the two are different
        // repairs and the wider one is true first: a key record carrying no
        // public key at all has nobody to compare a kid against, and answering
        // `unknown_kid` there would send an operator to check a rotation that
        // has not begun.
        $stored = $key_id === null ? [] : CadenceKey::verify_keys($key_id);
        // DECODED BEFORE ANY OF IT IS USED, so a stored value that is not a key is
        // skipped rather than handed to libsodium. `sodium_crypto_sign_verify_detached`
        // THROWS on a key that is not 32 bytes and the call below sits outside any try,
        // so a row written straight into the option -- a hand edit, a restored backup, a
        // migration -- made this site answer 500 to a publish. A 500 is not one of the
        // five branches and names no repair. Skipping also means a record holding one
        // good key and one corrupt one still verifies, which is what a rotation
        // half-pasted into the database looks like.
        $usable = [];
        foreach ($stored as $record) {
            $raw = is_string($record['pk'] ?? null) ? self::decode_public_key($record['pk']) : '';
            if ($raw !== '') {
                $usable[] = ['kid' => $record['kid'] ?? null, 'raw' => $raw];
            }
        }
        // ONE BRANCH, ONE ORIGIN, two sentences: `test_the_branch_vocabulary_is_exactly_five`
        // asserts each branch is refused from exactly one place, and it is right to --
        // but the repair differs between a screen showing nothing and a screen showing
        // something that is not a key, and a refusal that said the first over the second
        // would send an operator to paste a key they can already see.
        if ($usable === []) {
            return self::refuse('no_public_key', $stored === []
                ? 'this connector key carries no attestation public key at all, so there is '
                  . 'nothing here to check a signature against; paste one on the Cadence Keys '
                  . 'screen. Nothing was written'
                : 'this connector key carries attestation public keys and not one of them '
                  . 'decodes to a key, so there is nothing here to check a signature against; '
                  . 're-paste this tenant\'s public key on the Cadence Keys screen. '
                  . 'Nothing was written');
        }

        $public_key = null;
        foreach ($usable as $record) {
            if ($record['kid'] === $parsed['kid']) {
                $public_key = $record['raw'];
                break;
            }
        }
        if ($public_key === null) {
            return self::refuse('unknown_kid',
                'the signature names key ' . $parsed['kid'] . ', and this connector key holds no '
                . 'public key under that name; a rotation that pasted the new key here has not '
                . 'happened yet. Nothing was written');
        }

        $material = self::material($route, $fields);
        // OVER THE 32 RAW BYTES OF THE SHA-256, never its hex spelling.
        $verified = sodium_crypto_sign_verify_detached(
            $parsed['signature'], self::digest($material), $public_key);
        if ($verified !== true) {
            return self::refuse('mismatch',
                'the signature is well formed and names a public key this site holds, and it does '
                . 'not verify over the bytes of this request: the body was changed after it was '
                . 'signed, or it was signed by a different private key. Nothing was written');
        }

        // WITH THE KID THAT VERIFIED IT, and never the first one on the record.
        // Two keys exist so a rotation has an overlap window, and during one an
        // operator's only way to see that the new key is live is that the reply
        // names it.
        return ['ok' => true, 'attestation' => 'verified', 'kid' => $parsed['kid']];
    }

    /** @return array{ok: false, branch: string, code: string, reason: string} */
    private static function refuse(string $branch, string $reason): array {
        return ['ok' => false, 'branch' => $branch, 'code' => self::CODE, 'reason' => $reason];
    }

    /**
     * THE 32 RAW BYTES OF A STORED PUBLIC KEY.
     *
     * Stored in standard, padded base64 -- the spelling an operator pastes --
     * and validated to 32 bytes by `CadenceKey::add_verify_key` before it is
     * ever stored, so this is a decode and not a second check.
     */
    /**
     * A STORED PUBLIC KEY THAT IS NOT ONE IS NOT A KEY, and must not become a crash.
     *
     * Placement validates what it accepts, so this can only be reached by a value
     * written straight into the option -- a hand-edited row, a restored backup, a
     * migration. `sodium_crypto_sign_verify_detached` THROWS on a key that is not 32
     * bytes, and the verify here sits outside any try, so the site answered 500 to a
     * publish. A 500 says nothing an operator can act on and is not one of the five
     * branches; an unusable stored key is honestly `no_public_key`, and the entry is
     * skipped so a record holding one good key and one corrupt one still verifies.
     */
    private static function decode_public_key(string $stored): string {
        $raw = base64_decode($stored, true);
        return ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
            ? '' : $raw;
    }

    /**
     * Unpadded base64url to raw bytes, or null if it is not EXACTLY that.
     *
     * ONE SIGNATURE, ONE SPELLING. base64 does not use the last character's trailing
     * bits, so four different 86-character tokens decode to the same 64 bytes -- and a
     * decoder that accepts all four gives one signature four headers. Re-encoding the
     * decoded bytes and requiring the token back is the only check that refuses the
     * other three, and it costs one encode. This is the same rule that makes a padded
     * token `malformed`: an alternative spelling is not something the caller would
     * produce, and `malformed` should keep meaning that.
     */
    private static function base64url_decode(string $encoded): ?string {
        $raw = base64_decode(strtr($encoded, '-_', '+/') . '==', true);
        if ($raw === false) {
            return null;
        }
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') === $encoded ? $raw : null;
    }
}
