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
 * THE CANONICAL FORM IS DATA, AND IT IS NOT THIS FILE. It lives in the spine's
 * `connector_content_contract.json` under `attestation`, with worked vectors,
 * because two implementations in two languages cannot be shown to agree on a
 * byte layout by each describing it in prose. This class is written AGAINST
 * those vectors -- `AttestationTest` composes all three and diffs bytes -- and
 * if this file and that file disagree, that file is right.
 *
 * WHY A SECOND DIGEST AND NOT `CadenceRevision`. They answer different
 * questions over different inputs: a revision says which text a POST holds
 * right now, derived from the site; a material says which body a SPINE sent,
 * derived from the request. The `%d:%s` length-prefix convention is shared
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

    public const FIELDS = [
        '/content'         => ['piece_id', 'language', 'post_type', 'status', 'title', 'content'],
        '/content/replace' => ['piece_id', 'post_id', 'revision', 'title', 'content'],
    ];

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
     * @param string $route  `/content` or `/content/replace` -- the BARE path.
     *                       Never the mount point: a signature must not depend
     *                       on where a site mounts its REST API.
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
        if (!isset(self::FIELDS[$route])) {
            throw new InvalidArgumentException('no signed field set for route ' . $route);
        }
        $material = self::PREFIX . "\n" . $route . "\n";
        foreach (self::FIELDS[$route] as $name) {
            if (!array_key_exists($name, $fields)) {
                throw new InvalidArgumentException('signed field ' . $name . ' is absent');
            }
            $value = $fields[$name];
            if ($name === self::INTEGER_FIELD && !is_int($value)) {
                throw new InvalidArgumentException(
                    self::INTEGER_FIELD . ' is signed as an integer and is never read from a string');
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
                throw new InvalidArgumentException('signed field ' . $name . ' is not a string');
            }
            $material .= $name . ':' . strlen($value) . ':' . $value . "\n";
        }
        return $material;
    }

    /**
     * THE 32 RAW BYTES THAT ARE SIGNED.
     *
     * `hash('sha256', $m, true)`. The third argument is the whole point:
     * PHP's default is the 64-character HEX spelling, and a verifier that
     * signed the hex would verify perfectly against itself, pass every test
     * written in this repository, and fail against every body the spine ever
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
     * a Cadence spine" rather than "some encoder differed".
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
     * `absent` is a migration; `malformed` is a header no spine composed;
     * `unknown_kid` is a rotation half-done; `no_public_key` is a site where
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
            // log. The shape is what a spine operator needs to see.
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
        if ($stored === []) {
            return self::refuse('no_public_key',
                'this connector key carries no attestation public key at all, so there is nothing '
                . 'here to check a signature against; paste one on the Cadence Keys screen. '
                . 'Nothing was written');
        }

        $public_key = null;
        foreach ($stored as $record) {
            if (($record['kid'] ?? null) === $parsed['kid']) {
                $public_key = $record['pk'] ?? null;
                break;
            }
        }
        if (!is_string($public_key)) {
            return self::refuse('unknown_kid',
                'the signature names key ' . $parsed['kid'] . ', and this connector key holds no '
                . 'public key under that name; a rotation that pasted the new key here has not '
                . 'happened yet. Nothing was written');
        }

        $material = self::material($route, $fields);
        // OVER THE 32 RAW BYTES OF THE SHA-256, never its hex spelling.
        $verified = sodium_crypto_sign_verify_detached(
            $parsed['signature'], self::digest($material), self::decode_public_key($public_key));
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
    private static function decode_public_key(string $stored): string {
        $raw = base64_decode($stored, true);
        return $raw === false ? '' : $raw;
    }

    /** Unpadded base64url to raw bytes, or null if it is not that. */
    private static function base64url_decode(string $encoded): ?string {
        $raw = base64_decode(strtr($encoded, '-_', '+/') . '==', true);
        return $raw === false ? null : $raw;
    }
}
