<?php
/**
 * THE WIRE ATTESTATION: the canonical form, the five branches, and the flag.
 *
 * THE CONTRACT VECTORS ARE THE ORACLE AND NOTHING ELSE HERE IS. Every other
 * test in this file signs with a keypair the suite generated, through
 * `CadenceAttestation::material` -- which makes them agree with the verifier
 * BY CONSTRUCTION and proves nothing whatever about the byte layout. A wrong
 * layout is self-consistent: it signs cleanly, verifies cleanly against
 * itself, passes every such test, and fails only against the sending service,
 * on a client's site, as `mismatch` -- a branch that says the body was
 * tampered with. The three vectors below were composed and signed by the
 * sending service with a key this repository does not hold, and they are what
 * stands between this suite and that.
 *
 * They are COPIED IN, not read live from the sending service: this plugin
 * ships on its own and a test that needed a second checkout would not run at
 * all. They are re-copied here whenever the shared contract they come from
 * moves.
 *
 * @package cadence-connector
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class AttestationTest extends TestCase {

    /** The public key the contract's vectors were signed with. */
    private const VECTOR_PK = '+i1Vuctx8r3KSxWjQoTs8SLzLxqR2n2ve5pmiSr4GOI=';

    /** The kid those vectors name. */
    private const VECTOR_KID = '45da37c57dd21b36';

    /** The connector key these tests attach public keys to. */
    private const KEY = 'ca11ab1e0000key2';

    /**
     * THE CONTRACT'S TWELVE VECTORS, verbatim: eight flat ones here, four link ones below.
     *
     * `material` is base64 of the exact bytes hashed -- carried so a failure
     * can be diffed as BYTES. A digest that differs tells you the layout is
     * wrong and nothing about where, which is the one thing a second
     * implementation needs to know.
     */
    private function vectors(): array {
        $content = [
        [
            'label'    => 'content-ascii',
            'route'    => '/content',
            'fields'   => ['piece_id' => 'acme-blog-2026-09-12-attestation', 'language' => 'en-gb', 'post_type' => 'post', 'status' => 'draft', 'title' => 'Signing what we send', 'content' => '<p>The wire carries a digest now.</p>'],
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL2NvbnRlbnQKcGllY2VfaWQ6MzI6YWNtZS1ibG9nLTIwMjYtMDktMTItYXR0ZXN0YXRpb24KbGFuZ3VhZ2U6NTplbi1nYgpwb3N0X3R5cGU6NDpwb3N0CnN0YXR1czo1OmRyYWZ0CnRpdGxlOjIwOlNpZ25pbmcgd2hhdCB3ZSBzZW5kCmNvbnRlbnQ6Mzc6PHA+VGhlIHdpcmUgY2FycmllcyBhIGRpZ2VzdCBub3cuPC9wPgo=',
            'bytes'    => 200,
            'digest'   => '1da177bdf93e0d96c44b65b7d31c5766ce68bf459d07712c6d92e5bc97b7ebb2',
            'header'   => 'v1 45da37c57dd21b36 sr1LTZv6EUQctJOJELX8S5OUPjCQpbr06Wmw1JfalPfpodCYL7JPHvRJ5wKCo906p6suOJhK9fFdTuDgttwYDQ',
        ],
        [
            'label'    => 'content-unicode-untrimmed-unnormalised',
            'route'    => '/content',
            'fields'   => ['piece_id' => 'acme-blog-2026-09-12-attestation__it', 'language' => 'it', 'post_type' => 'post', 'status' => 'publish', 'title' => '  Firmare ciò che inviamo — 🔑  ', 'content' => '<p>Perché conta: é non è é — e 日本語 non è ASCII.</p>'],
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL2NvbnRlbnQKcGllY2VfaWQ6MzY6YWNtZS1ibG9nLTIwMjYtMDktMTItYXR0ZXN0YXRpb25fX2l0Cmxhbmd1YWdlOjI6aXQKcG9zdF90eXBlOjQ6cG9zdApzdGF0dXM6NzpwdWJsaXNoCnRpdGxlOjM4OiAgRmlybWFyZSBjaW/MgCBjaGUgaW52aWFtbyDigJQg8J+UkSAgCmNvbnRlbnQ6NjY6PHA+UGVyY2hlzIEgY29udGE6IGXMgSBub24gw6ggw6kg4oCUIGUg5pel5pys6KqeIG5vbiDDqCBBU0NJSS48L3A+Cg==',
            'bytes'    => 250,
            'digest'   => 'd4813246abe79c5c389a326369d201e7bc4d951d70e3fa148d64849e7212b15e',
            'header'   => 'v1 45da37c57dd21b36 p-eFK_r1dEo9O8xwBuFrEG0VomFjN-nKU26PEOUcZa95pwEj7By4jnvkL-y_-aNfAR1-wf7uYtGixFvAyK6ADA',
        ],
        [
            'label'    => 'replace',
            'route'    => '/content/replace',
            'fields'   => ['piece_id' => 'acme-blog-2026-09-12-attestation', 'post_id' => 41, 'revision' => 'sha256:653d0e03211c28bf6d86ba229a45a6f055ee1586e700e99b0bd141423652a024', 'title' => 'Signing what we send (corrected)', 'content' => '<p>The wire carries a digest now, and the rewrite carries one too.</p>'],
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL2NvbnRlbnQvcmVwbGFjZQpwaWVjZV9pZDozMjphY21lLWJsb2ctMjAyNi0wOS0xMi1hdHRlc3RhdGlvbgpwb3N0X2lkOjI6NDEKcmV2aXNpb246NzE6c2hhMjU2OjY1M2QwZTAzMjExYzI4YmY2ZDg2YmEyMjlhNDVhNmYwNTVlZTE1ODZlNzAwZTk5YjBiZDE0MTQyMzY1MmEwMjQKdGl0bGU6MzI6U2lnbmluZyB3aGF0IHdlIHNlbmQgKGNvcnJlY3RlZCkKY29udGVudDo3MDo8cD5UaGUgd2lyZSBjYXJyaWVzIGEgZGlnZXN0IG5vdywgYW5kIHRoZSByZXdyaXRlIGNhcnJpZXMgb25lIHRvby48L3A+Cg==',
            'bytes'    => 301,
            'digest'   => 'a4b919391a033f9c28d86d20ede57a56bc04a8529631d0ae5ab605bda18feb8b',
            'header'   => 'v1 45da37c57dd21b36 H_xyN7u_R9yeNfg5T6X6gPDY9u4aHf0mBXO6faB_FHjbwLrCxXFJxtfEMKHo7xFtAUwbgbxeenITyUv_gYg2Ag',
        ],
        [
            'label'    => 'adopt-preview',
            'route'    => '/adopt/preview',
            'fields'   => ['piece_id' => 'acme-blog-2026-09-24-adopt', 'link' => 'https://example.test/?p=41', 'site' => 'example.test', 'issued_at' => '2026-09-24T12:00:00Z'],
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL2Fkb3B0L3ByZXZpZXcKcGllY2VfaWQ6MjY6YWNtZS1ibG9nLTIwMjYtMDktMjQtYWRvcHQKbGluazoyNjpodHRwczovL2V4YW1wbGUudGVzdC8/cD00MQpzaXRlOjEyOmV4YW1wbGUudGVzdAppc3N1ZWRfYXQ6MjA6MjAyNi0wOS0yNFQxMjowMDowMFoK',
            'bytes'    => 162,
            'digest'   => 'c1223fc10238415554bc20ac29d77f4f6185bf839a15e96f183728d3a2b4421b',
            'header'   => 'v1 45da37c57dd21b36 FY3jy4t-GUvhdRtzTgIUwi_wNvATch7L0JXSYDWQW1z-4y1wNk8P13LDHG9bXWfifnKirWmUnFjV4u8LN9N4Dg',
        ],
        [
            'label'    => 'adopt',
            'route'    => '/adopt',
            'fields'   => ['piece_id' => 'acme-blog-2026-09-24-adopt', 'post_id' => 41, 'language' => 'en-gb', 'site' => 'example.test/blog', 'issued_at' => '2026-09-24T12:00:00Z'],
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL2Fkb3B0CnBpZWNlX2lkOjI2OmFjbWUtYmxvZy0yMDI2LTA5LTI0LWFkb3B0CnBvc3RfaWQ6Mjo0MQpsYW5ndWFnZTo1OmVuLWdiCnNpdGU6MTc6ZXhhbXBsZS50ZXN0L2Jsb2cKaXNzdWVkX2F0OjIwOjIwMjYtMDktMjRUMTI6MDA6MDBaCg==',
            'bytes'    => 154,
            'digest'   => '896169b19bc6dd10b085ba1818803a47010d8359c93e60a77be1c26336541348',
            'header'   => 'v1 45da37c57dd21b36 hQkFAaLQj6disj41wAPBQj8Zb9YgwxKMTwRHzDtS3QbIA5oKLmaePr0lvmE4eys2zSzDIVMlEsxF33bQs79FAg',
        ],
        [
            'label'    => 'adopt-release-preview',
            'route'    => '/adopt/release/preview',
            'fields'   => ['link' => 'https://example.test/?p=41', 'site' => 'example.test', 'issued_at' => '2026-09-24T12:00:00Z'],
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL2Fkb3B0L3JlbGVhc2UvcHJldmlldwpsaW5rOjI2Omh0dHBzOi8vZXhhbXBsZS50ZXN0Lz9wPTQxCnNpdGU6MTI6ZXhhbXBsZS50ZXN0Cmlzc3VlZF9hdDoyMDoyMDI2LTA5LTI0VDEyOjAwOjAwWgo=',
            'bytes'    => 131,
            'digest'   => 'cc4681e501d5d20131a840f1ceef8b141fabb049f8288a212eb3412a27200190',
            'header'   => 'v1 45da37c57dd21b36 qTP7trX5QwoeLCZwe3AP7kGR3VNrvjk2XsMHngsiG7aHhNKWjW7qiYQhzYvwNrgQBnTYx-U75T1K7pnGm_VOAQ',
        ],
        [
            'label'    => 'adopt-release',
            'route'    => '/adopt/release',
            'fields'   => ['piece_id' => 'acme-blog-2026-09-24-adopt', 'post_id' => 41, 'site' => 'example.test/blog', 'issued_at' => '2026-09-24T12:00:00Z'],
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL2Fkb3B0L3JlbGVhc2UKcGllY2VfaWQ6MjY6YWNtZS1ibG9nLTIwMjYtMDktMjQtYWRvcHQKcG9zdF9pZDoyOjQxCnNpdGU6MTc6ZXhhbXBsZS50ZXN0L2Jsb2cKaXNzdWVkX2F0OjIwOjIwMjYtMDktMjRUMTI6MDA6MDBaCg==',
            'bytes'    => 145,
            'digest'   => 'e8c2434e8cfe8d645bfa7798a5b708dfa70ea8381d522c8a16791fde3535ca3e',
            'header'   => 'v1 45da37c57dd21b36 LGYJIvzqgoznGAeTQfozUl5xwE0SlxvspTlXOP_LVA5ghxfDVW1AT3OTZMaJcML015iaHTlViWHzM8MFvVBbDg',
        ],
        [
            'label'    => 'replace-with-rewrite-confirmation',
            'route'    => '/content/replace',
            // THE BODY'S OWN BOOLEAN, reduced by the route's own rendering: a
            // `1` or a `True` would fail the byte diff below.
            'fields'   => CadenceReplaceRequest::signable(['piece_id' => 'acme-blog-2026-09-12-attestation', 'post_id' => 41, 'revision' => 'sha256:653d0e03211c28bf6d86ba229a45a6f055ee1586e700e99b0bd141423652a024', 'title' => 'Signing what we send (corrected)', 'content' => '<p>The wire carries a digest now, and the rewrite carries one too.</p>', 'overwrite_adopted' => true, 'site' => 'example.test/blog', 'issued_at' => '2026-09-24T12:00:00Z']),
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL2NvbnRlbnQvcmVwbGFjZQpwaWVjZV9pZDozMjphY21lLWJsb2ctMjAyNi0wOS0xMi1hdHRlc3RhdGlvbgpwb3N0X2lkOjI6NDEKcmV2aXNpb246NzE6c2hhMjU2OjY1M2QwZTAzMjExYzI4YmY2ZDg2YmEyMjlhNDVhNmYwNTVlZTE1ODZlNzAwZTk5YjBiZDE0MTQyMzY1MmEwMjQKdGl0bGU6MzI6U2lnbmluZyB3aGF0IHdlIHNlbmQgKGNvcnJlY3RlZCkKY29udGVudDo3MDo8cD5UaGUgd2lyZSBjYXJyaWVzIGEgZGlnZXN0IG5vdywgYW5kIHRoZSByZXdyaXRlIGNhcnJpZXMgb25lIHRvby48L3A+Cm92ZXJ3cml0ZV9hZG9wdGVkOjQ6dHJ1ZQpzaXRlOjE3OmV4YW1wbGUudGVzdC9ibG9nCmlzc3VlZF9hdDoyMDoyMDI2LTA5LTI0VDEyOjAwOjAwWgo=',
            'bytes'    => 386,
            'digest'   => 'fa2212dd17ca803f80d29e2a6c242be83769ac47f1560d811aec6c04475cada3',
            'header'   => 'v1 45da37c57dd21b36 uQK4W-BHVNM0-MeseKH8ke3aDKFN_INtuLa2O6IHLy-SvZdMXWMbqU1U1H_Q3oWYgF4AxD3lEIBvv9VXk5ImDg',
        ],
        ];
        // THE LINK VECTORS JOIN THEM, reduced to the same flat shape by the ONE
        // implementation that reduces a plan. So every assertion in this file
        // about bytes, byte counts, raw digests and end-to-end verification
        // covers them too -- and a reduction that sorted wrongly, rendered a
        // boolean as `1`, or dropped a member fails the BYTE DIFF rather than
        // only the test written for it.
        foreach ($this->link_vectors() as $v) {
            $fields = CadenceAttestation::link_fields($v['plan']);
            $this->assertIsArray($fields, $v['label'] . ': the plan has no signable reduction');
            $content[] = ['label' => $v['label'], 'route' => $v['route'], 'fields' => $fields,
                          'material' => $v['material'], 'bytes' => $v['bytes'],
                          'digest' => $v['digest'], 'header' => $v['header']];
        }
        return $content;
    }

    /**
     * THE CONTRACT'S FOUR `/translation-group` VECTORS, verbatim.
     *
     * They carry the PLAN as it goes on the wire, not a flat field map: this
     * route's signed set is not a fixed list, so the map is something the two
     * halves DERIVE and therefore something a vector must be able to catch them
     * deriving differently. `order` is the expanded field order the contract
     * says that derivation must produce, and `members` the canonical order the
     * members go in -- both asserted against a derivation here rather than read
     * out and composed from, which would pin nothing at all.
     */
    private function link_vectors(): array {
        return [
        [
            'label'    => 'link-join-existing-group',
            'route'    => '/translation-group',
            'plan'     => ['trid' => 4471, 'create_group' => false, 'source' => ['post_id' => 101, 'language_code' => 'en', 'element_type' => 'post_post'], 'translations' => ['it' => ['post_id' => 102, 'language_code' => 'it', 'element_type' => 'post_post']], 'piece_id' => 'acme-blog-2026-09-12-attestation'],
            'members'  => ['en', 'it'],
            'order'    => ['trid', 'create_group', 'piece_id', 'member.0.post_id', 'member.0.language_code', 'member.0.element_type', 'member.0.source_language_code', 'member.1.post_id', 'member.1.language_code', 'member.1.element_type', 'member.1.source_language_code'],
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL3RyYW5zbGF0aW9uLWdyb3VwCnRyaWQ6NDo0NDcxCmNyZWF0ZV9ncm91cDo1OmZhbHNlCnBpZWNlX2lkOjMyOmFjbWUtYmxvZy0yMDI2LTA5LTEyLWF0dGVzdGF0aW9uCm1lbWJlci4wLnBvc3RfaWQ6MzoxMDEKbWVtYmVyLjAubGFuZ3VhZ2VfY29kZToyOmVuCm1lbWJlci4wLmVsZW1lbnRfdHlwZTo5OnBvc3RfcG9zdAptZW1iZXIuMC5zb3VyY2VfbGFuZ3VhZ2VfY29kZTowOgptZW1iZXIuMS5wb3N0X2lkOjM6MTAyCm1lbWJlci4xLmxhbmd1YWdlX2NvZGU6MjppdAptZW1iZXIuMS5lbGVtZW50X3R5cGU6OTpwb3N0X3Bvc3QKbWVtYmVyLjEuc291cmNlX2xhbmd1YWdlX2NvZGU6MDoK',
            'bytes'    => 351,
            'digest'   => '6689e84d3e8282fb687227ce81dd223fa3827af250bb2467a5d581fe14090860',
            'header'   => 'v1 45da37c57dd21b36 SXC0X3-tIPhgf4u_KijTKQpHDL3v89NyrVzITQk9uAR49kz0SiK0OQn_zPDVZS9UsP0yUJWjiPXzS2vNKO63BA',
        ],
        [
            'label'    => 'link-create-group-unicode-piece-id',
            'route'    => '/translation-group',
            'plan'     => ['trid' => null, 'create_group' => true, 'source' => ['post_id' => 7, 'language_code' => 'en-gb', 'element_type' => 'post_page'], 'translations' => ['de' => ['post_id' => 8, 'language_code' => 'de', 'element_type' => 'post_page'], 'it' => ['post_id' => 9, 'language_code' => 'it', 'element_type' => 'post_page']], 'piece_id' => 'acme-Fußnoten-2026'],
            'members'  => ['en-gb', 'de', 'it'],
            'order'    => ['trid', 'create_group', 'piece_id', 'member.0.post_id', 'member.0.language_code', 'member.0.element_type', 'member.0.source_language_code', 'member.1.post_id', 'member.1.language_code', 'member.1.element_type', 'member.1.source_language_code', 'member.2.post_id', 'member.2.language_code', 'member.2.element_type', 'member.2.source_language_code'],
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL3RyYW5zbGF0aW9uLWdyb3VwCnRyaWQ6MDoKY3JlYXRlX2dyb3VwOjQ6dHJ1ZQpwaWVjZV9pZDoxOTphY21lLUZ1w59ub3Rlbi0yMDI2Cm1lbWJlci4wLnBvc3RfaWQ6MTo3Cm1lbWJlci4wLmxhbmd1YWdlX2NvZGU6NTplbi1nYgptZW1iZXIuMC5lbGVtZW50X3R5cGU6OTpwb3N0X3BhZ2UKbWVtYmVyLjAuc291cmNlX2xhbmd1YWdlX2NvZGU6MDoKbWVtYmVyLjEucG9zdF9pZDoxOjgKbWVtYmVyLjEubGFuZ3VhZ2VfY29kZToyOmRlCm1lbWJlci4xLmVsZW1lbnRfdHlwZTo5OnBvc3RfcGFnZQptZW1iZXIuMS5zb3VyY2VfbGFuZ3VhZ2VfY29kZTowOgptZW1iZXIuMi5wb3N0X2lkOjE6OQptZW1iZXIuMi5sYW5ndWFnZV9jb2RlOjI6aXQKbWVtYmVyLjIuZWxlbWVudF90eXBlOjk6cG9zdF9wYWdlCm1lbWJlci4yLnNvdXJjZV9sYW5ndWFnZV9jb2RlOjA6Cg==',
            'bytes'    => 448,
            'digest'   => '5eac1419949bb10107f5383698b2e17f7b9c19fb729925a6549b4d2c4d0ae086',
            'header'   => 'v1 45da37c57dd21b36 DFABbje4uTRlYh6I3fVoLi0aujnjnEuol8UEuXr5WyAebtkNaebbe8xwHkYsXaduCkuItZz-OfGXG3aIvF9dCQ',
        ],
        [
            'label'    => 'link-wire-order-is-not-canonical-order',
            'route'    => '/translation-group',
            'plan'     => ['trid' => 900001, 'create_group' => false, 'source' => ['post_id' => 1000, 'language_code' => 'en', 'element_type' => 'post_post'], 'translations' => ['pt-br' => ['post_id' => 1003, 'language_code' => 'pt-br', 'element_type' => 'post_post', 'source_language_code' => 'en'], 'de' => ['post_id' => 1001, 'language_code' => 'de', 'element_type' => 'post_post', 'source_language_code' => 'en'], 'it' => ['post_id' => 1002, 'language_code' => 'it', 'element_type' => 'post_post']]],
            'members'  => ['en', 'de', 'it', 'pt-br'],
            'order'    => ['trid', 'create_group', 'piece_id', 'member.0.post_id', 'member.0.language_code', 'member.0.element_type', 'member.0.source_language_code', 'member.1.post_id', 'member.1.language_code', 'member.1.element_type', 'member.1.source_language_code', 'member.2.post_id', 'member.2.language_code', 'member.2.element_type', 'member.2.source_language_code', 'member.3.post_id', 'member.3.language_code', 'member.3.element_type', 'member.3.source_language_code'],
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL3RyYW5zbGF0aW9uLWdyb3VwCnRyaWQ6Njo5MDAwMDEKY3JlYXRlX2dyb3VwOjU6ZmFsc2UKcGllY2VfaWQ6MDoKbWVtYmVyLjAucG9zdF9pZDo0OjEwMDAKbWVtYmVyLjAubGFuZ3VhZ2VfY29kZToyOmVuCm1lbWJlci4wLmVsZW1lbnRfdHlwZTo5OnBvc3RfcG9zdAptZW1iZXIuMC5zb3VyY2VfbGFuZ3VhZ2VfY29kZTowOgptZW1iZXIuMS5wb3N0X2lkOjQ6MTAwMQptZW1iZXIuMS5sYW5ndWFnZV9jb2RlOjI6ZGUKbWVtYmVyLjEuZWxlbWVudF90eXBlOjk6cG9zdF9wb3N0Cm1lbWJlci4xLnNvdXJjZV9sYW5ndWFnZV9jb2RlOjI6ZW4KbWVtYmVyLjIucG9zdF9pZDo0OjEwMDIKbWVtYmVyLjIubGFuZ3VhZ2VfY29kZToyOml0Cm1lbWJlci4yLmVsZW1lbnRfdHlwZTo5OnBvc3RfcG9zdAptZW1iZXIuMi5zb3VyY2VfbGFuZ3VhZ2VfY29kZTowOgptZW1iZXIuMy5wb3N0X2lkOjQ6MTAwMwptZW1iZXIuMy5sYW5ndWFnZV9jb2RlOjU6cHQtYnIKbWVtYmVyLjMuZWxlbWVudF90eXBlOjk6cG9zdF9wb3N0Cm1lbWJlci4zLnNvdXJjZV9sYW5ndWFnZV9jb2RlOjI6ZW4K',
            'bytes'    => 567,
            'digest'   => '6868f55280aee8f8c009f038a04b8a62a85b15f3f13f716f1ee13940f47684f5',
            'header'   => 'v1 45da37c57dd21b36 SBUINpo86LmZmb5y__hXzDnAjuy8X99_50q6IjjObIpGnu_Bc_yplUR5vW9RnVQSTAZNch8cMWO2tVh_LV1PCQ',
        ],
        [
            'label'    => 'link-two-translations-share-a-language',
            'route'    => '/translation-group',
            'plan'     => ['trid' => 55, 'create_group' => false, 'source' => ['post_id' => 1, 'language_code' => 'en', 'element_type' => 'post_post'], 'translations' => ['a' => ['post_id' => 30, 'language_code' => 'it', 'element_type' => 'post_post'], 'b' => ['post_id' => 20, 'language_code' => 'it', 'element_type' => 'post_post']], 'piece_id' => 'tie'],
            'members'  => ['en', 'it', 'it'],
            'order'    => ['trid', 'create_group', 'piece_id', 'member.0.post_id', 'member.0.language_code', 'member.0.element_type', 'member.0.source_language_code', 'member.1.post_id', 'member.1.language_code', 'member.1.element_type', 'member.1.source_language_code', 'member.2.post_id', 'member.2.language_code', 'member.2.element_type', 'member.2.source_language_code'],
            'material' => 'Y2FkZW5jZS1hdHRlc3QtdjEKL3RyYW5zbGF0aW9uLWdyb3VwCnRyaWQ6Mjo1NQpjcmVhdGVfZ3JvdXA6NTpmYWxzZQpwaWVjZV9pZDozOnRpZQptZW1iZXIuMC5wb3N0X2lkOjE6MQptZW1iZXIuMC5sYW5ndWFnZV9jb2RlOjI6ZW4KbWVtYmVyLjAuZWxlbWVudF90eXBlOjk6cG9zdF9wb3N0Cm1lbWJlci4wLnNvdXJjZV9sYW5ndWFnZV9jb2RlOjA6Cm1lbWJlci4xLnBvc3RfaWQ6MjoyMAptZW1iZXIuMS5sYW5ndWFnZV9jb2RlOjI6aXQKbWVtYmVyLjEuZWxlbWVudF90eXBlOjk6cG9zdF9wb3N0Cm1lbWJlci4xLnNvdXJjZV9sYW5ndWFnZV9jb2RlOjA6Cm1lbWJlci4yLnBvc3RfaWQ6MjozMAptZW1iZXIuMi5sYW5ndWFnZV9jb2RlOjI6aXQKbWVtYmVyLjIuZWxlbWVudF90eXBlOjk6cG9zdF9wb3N0Cm1lbWJlci4yLnNvdXJjZV9sYW5ndWFnZV9jb2RlOjA6Cg==',
            'bytes'    => 433,
            'digest'   => '5bb08a1112e5b2c2473f791c8dc3b6e405f147c1582abd731afdd08302dbc05d',
            'header'   => 'v1 45da37c57dd21b36 MnN_N6aKFEMZ_49CGXXWwwqhuPRmIEo8VVjjV-xMSIZW5Ix39gdYunoxagFqIrzPOC4uyIcpvk7VFIpn8j7aCw',
        ],
        ];
    }

    protected function setUp(): void {
        WpStub::reset();
        CadenceAttest::install(self::KEY);
    }

    /** Attach the contract's own public key, so a vector's header verifies. */
    private function install_vector_key(): void {
        $keys = get_option(CadenceKey::OPTION, []);
        $keys[self::KEY]['verify'] = [['kid' => self::VECTOR_KID, 'pk' => self::VECTOR_PK,
                                       'added' => 0]];
        update_option(CadenceKey::OPTION, $keys);
    }

    /**
     * SODIUM IS A RED AND NEVER A SKIP.
     *
     * A suite that skipped the attestation tests where libsodium is missing
     * would report a pass on an image where this plugin cannot verify a single
     * signature -- and the connector would then refuse every publish on a
     * client's site while CI was green. `sodium` has been a bundled PHP
     * extension since 7.2 and is present in the image `run-tests.sh` uses; if
     * it ever is not, this is the failure that says so.
     */
    public function test_libsodium_is_present_and_this_is_not_skipped(): void {
        $this->assertTrue(extension_loaded('sodium'),
            'libsodium is missing, so this plugin can verify nothing; this is a red and not a skip');
        $this->assertTrue(function_exists('sodium_crypto_sign_verify_detached'));
        $this->assertSame(32, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $this->assertSame(64, SODIUM_CRYPTO_SIGN_BYTES);
    }

    /**
     * THE MATERIAL IS THE CONTRACT'S MATERIAL, BYTE FOR BYTE.
     *
     * Diffed as bytes and not as a digest, and the byte COUNT asserted beside
     * them: a length that matches while the bytes do not is a different
     * character in the same place, and a length that differs is a field
     * separator, a missing terminator or an mb_strlen.
     */
    public function test_every_contract_vector_composes_the_exact_bytes(): void {
        foreach ($this->vectors() as $v) {
            $material = CadenceAttestation::material($v['route'], $v['fields']);
            $this->assertSame(base64_decode($v['material'], true), $material, $v['label']);
            $this->assertSame($v['bytes'], strlen($material), $v['label'] . ': byte count');
        }
    }

    /** THE MATERIAL'S LAST BYTE IS LF. A terminator, never a separator. */
    public function test_the_material_ends_with_a_line_feed(): void {
        foreach ($this->vectors() as $v) {
            $material = CadenceAttestation::material($v['route'], $v['fields']);
            $this->assertSame("\n", substr($material, -1), $v['label']);
            // And the one before it is not: a doubled terminator is the other
            // way to end with LF and is a different 200 bytes.
            $this->assertNotSame("\n", substr($material, -2, 1), $v['label']);
        }
    }

    /**
     * THE DIGEST IS 32 RAW BYTES AND THE SIGNATURE IS OVER THOSE.
     *
     * `hash('sha256', $m)` returns HEX by default, and this is the assertion
     * that says so out loud: the raw digest is 32 bytes, the hex spelling is
     * 64 characters, and the contract signs the first.
     */
    public function test_the_digest_is_thirty_two_raw_bytes_and_matches_the_contract(): void {
        foreach ($this->vectors() as $v) {
            $digest = CadenceAttestation::digest(
                CadenceAttestation::material($v['route'], $v['fields']));
            $this->assertSame(32, strlen($digest), $v['label'] . ': not raw bytes');
            $this->assertSame($v['digest'], bin2hex($digest), $v['label']);
        }
    }

    /**
     * AND THE HEX SPELLING DOES NOT VERIFY, which is the whole reason the
     * raw-digest requirement exists. A hex implementation is self-consistent
     * and fails only against the other side of the wire, so the boundary is
     * asserted here.
     */
    public function test_signing_the_hex_spelling_verifies_against_nothing(): void {
        $pk = base64_decode(self::VECTOR_PK, true);
        foreach ($this->vectors() as $v) {
            $material = CadenceAttestation::material($v['route'], $v['fields']);
            $parsed = CadenceAttestation::parse($v['header']);
            $this->assertNotNull($parsed, $v['label']);
            $this->assertTrue(sodium_crypto_sign_verify_detached(
                $parsed['signature'], hash('sha256', $material, true), $pk), $v['label']);
            $this->assertFalse(sodium_crypto_sign_verify_detached(
                $parsed['signature'], hash('sha256', $material), $pk),
                $v['label'] . ': the hex spelling verified, so the digest is not raw');
        }
    }

    /**
     * THE CONTRACT'S OWN HEADERS VERIFY END TO END, through the class the
     * routes call and against the public key an operator would have pasted.
     */
    public function test_every_contract_vector_verifies_through_the_public_entry_point(): void {
        $this->install_vector_key();
        foreach ($this->vectors() as $v) {
            $r = CadenceAttestation::verify($v['header'], $v['route'], $v['fields'], self::KEY);
            $this->assertTrue($r['ok'], $v['label'] . ': ' . ($r['reason'] ?? ''));
            $this->assertSame('verified', $r['attestation'], $v['label']);
            $this->assertSame(self::VECTOR_KID, $r['kid'], $v['label']);
        }
    }

    /**
     * THE LENGTH IS BYTES AND NOTHING IS NORMALISED OR TRIMMED.
     *
     * Asserted over the unicode vector's own fields rather than over a digest:
     * an `mb_strlen` implementation produces a different number in the
     * material and this says which number is right, in the place a reader can
     * see it. The title keeps its leading and trailing spaces, and the content
     * carries `e`+U+0301 beside a precomposed U+00E9 -- normalise either and
     * the two spellings collapse.
     */
    public function test_the_length_prefix_counts_bytes_and_the_value_is_untouched(): void {
        $v = $this->vectors()[1];
        $this->assertSame('content-unicode-untrimmed-unnormalised', $v['label']);
        $material = CadenceAttestation::material($v['route'], $v['fields']);

        $title = $v['fields']['title'];
        $this->assertNotSame(strlen($title), mb_strlen($title),
            'this title no longer separates a byte count from a character count');
        $this->assertStringContainsString("title:" . strlen($title) . ":" . $title . "\n", $material);
        $this->assertStringNotContainsString("title:" . mb_strlen($title) . ":", $material,
            'the length prefix is a character count');

        // `e` + U+0301 in the fixture, beside a precomposed U+00E9 -- NFC
        // collapses the two and moves the digest, so both must survive.
        $this->assertStringContainsString("e\xCC\x81", $v['fields']['content'],
            'the combining acute is gone from the fixture, so this proves nothing');
        $this->assertStringContainsString("\xC3\xA9", $v['fields']['content'],
            'the precomposed form is gone from the fixture, so this proves nothing');
        $this->assertStringContainsString("\xCC\x81", $material, 'the material was normalised');
        $this->assertStringContainsString("\xC3\xA9", $material, 'the material was normalised');
        $this->assertSame('  ', substr($title, 0, 2), 'the fixture title lost its leading spaces');
        $this->assertStringContainsString(':' . $title . "\n", $material, 'the value was trimmed');
    }

    /** THE ROUTE IS THE BARE PATH, and never the mount point. */
    public function test_the_route_line_is_the_bare_path(): void {
        $material = CadenceAttestation::material('/content', $this->vectors()[0]['fields']);
        $this->assertStringStartsWith("cadence-attest-v1\n/content\n", $material);
        $this->assertStringNotContainsString('/wp-json/', $material);
    }

    /**
     * AND THE TWO ROUTES DO NOT SHARE A SIGNATURE. The four field names they
     * have in common would otherwise let a captured publish verify a REWRITE
     * of the same piece, which is the boundary `content.replace` exists to draw.
     */
    public function test_a_publish_signature_does_not_verify_a_rewrite(): void {
        $fields = ['piece_id' => 'p', 'post_id' => 4, 'revision' => 'r',
                   'title' => 'T', 'content' => 'C'];
        $publish = CadenceAttestation::material('/content/replace', $fields);
        $this->assertStringContainsString("\n/content/replace\n", $publish);
        $this->assertNotSame(
            CadenceAttestation::digest($publish),
            CadenceAttestation::digest("cadence-attest-v1\n/content\n"
                . substr($publish, strlen("cadence-attest-v1\n/content/replace\n"))));
    }

    /**
     * A `post_id` THAT IS A STRING IS REFUSED AND NEVER COERCED. `'007'` and
     * `7` are one post and two signatures.
     */
    public function test_a_string_post_id_is_refused_by_the_composer(): void {
        $fields = ['piece_id' => 'p', 'post_id' => '007', 'revision' => 'r',
                   'title' => 'T', 'content' => 'C'];
        $this->expectException(InvalidArgumentException::class);
        CadenceAttestation::material('/content/replace', $fields);
    }

    /** An integer `post_id` is decimal ASCII, and its length is that string's. */
    public function test_an_integer_post_id_is_decimal_ascii(): void {
        $material = CadenceAttestation::material('/content/replace',
            ['piece_id' => 'p', 'post_id' => 41, 'revision' => 'r', 'title' => 'T', 'content' => 'C']);
        $this->assertStringContainsString("post_id:2:41\n", $material);
    }

    /** AN EMPTY VALUE IS WELL FORMED. `title:0:` and an LF, signed normally. */
    public function test_an_empty_value_signs_normally(): void {
        $material = CadenceAttestation::material('/content',
            ['piece_id' => 'p', 'language' => 'en', 'post_type' => 'post',
             'status' => 'draft', 'title' => '', 'content' => 'C']);
        $this->assertStringContainsString("title:0:\n", $material);
    }

    /**
     * A KEY OUTSIDE THE ROUTE'S SET IS IGNORED, NEVER REFUSED. `/content`
     * legitimately carries `declared`, which selects a refusal and displays
     * nothing -- the exact complement of "nothing signed may be missing".
     */
    public function test_an_unsigned_payload_key_changes_nothing(): void {
        $fields = $this->vectors()[0]['fields'];
        $this->assertSame(
            CadenceAttestation::material('/content', $fields),
            CadenceAttestation::material('/content',
                $fields + ['declared' => 'anything at all', 'nonsense' => 'x']));
    }

    /** A body signed by the suite's own key, and the header for it. */
    private function signed(string $route, array $fields, ?string $kid = null): string {
        return CadenceAttest::header($route, $fields, self::KEY, $kid);
    }

    private function content_fields(array $over = []): array {
        return $over + ['piece_id' => 'p-1', 'language' => 'en-gb', 'post_type' => 'post',
                        'status' => 'draft', 'title' => 'T', 'content' => 'C'];
    }

    /**
     * THE FIVE BRANCHES, AND EACH ONE'S OWN SENTENCE.
     *
     * ONE CODE AND FIVE REASONS. The assertion is over a phrase only THAT
     * branch's sentence contains, never over a word the others also use --
     * a needle that also appears in another branch's sentence could still
     * pass while the fact under test had been deleted, so each needle below
     * is checked to appear in exactly one of the five.
     *
     * A REFUSAL MUST NAME THE BRANCH THAT FIRED. Folding two into one sentence
     * asserts an access that never happened: told `unknown_kid` for a site
     * where nobody pasted a key at all, an operator goes and audits a rotation
     * that never began.
     */
    public function test_each_branch_answers_403_with_its_own_sentence(): void {
        $fields = $this->content_fields();
        $good   = $this->signed('/content', $fields);

        $cases = [
            // nothing sent at all
            'absent' => [null, 'carried no ' . CadenceAttestation::HEADER . ' header at all'],
            // sent, unreadable
            'malformed' => ['v1 ' . CadenceAttest::KID, 'was not readable as'],
            // sent, readable, names a key this record does not hold
            'unknown_kid' => ['v1 ffffffffffffffff ' . explode(' ', $good)[2],
                              'holds no public key under that name'],
            // sent, readable, and this record holds none at all
            'no_public_key' => [$good, 'carries no attestation public key at all'],
            // sent, readable, names a key we hold, over different bytes
            'mismatch' => [$this->signed('/content', $this->content_fields(['title' => 'OTHER'])),
                           'does not verify over the bytes of this request'],
        ];

        $reasons = [];
        foreach ($cases as $branch => [$header, $needle]) {
            // `no_public_key` is the one that needs the record EMPTIED, and the
            // others need it populated -- the arrangement is the branch.
            $keys = get_option(CadenceKey::OPTION, []);
            $keys[self::KEY]['verify'] = $branch === 'no_public_key'
                ? []
                : [['kid' => CadenceAttest::KID, 'pk' => CadenceAttest::public_key_base64(),
                    'added' => 0]];
            update_option(CadenceKey::OPTION, $keys);

            $r = CadenceAttestation::verify($header, '/content', $fields, self::KEY);

            $this->assertFalse($r['ok'], $branch . ' was not refused');
            $this->assertSame($branch, $r['branch'], 'the wrong branch fired for ' . $branch);
            $this->assertSame('attestation_unverified', $r['code'], $branch);
            $this->assertSame(403, CadenceRestRoute::STATUS[$r['code']], $branch);
            $this->assertStringContainsString($needle, $r['reason'],
                $branch . ' did not name its own branch');
            $reasons[$branch] = $r['reason'];
        }

        $this->assertCount(5, $reasons);
        $this->assertSame(CadenceAttestation::FIELDS === [] ? [] : array_keys($cases),
                          array_keys($reasons));

        // AND NO NEEDLE MATCHES A SECOND BRANCH. Without this the five
        // assertions above are satisfiable by one sentence shared by all five,
        // which is the exact defect this vocabulary exists to prevent.
        foreach ($cases as $branch => [, $needle]) {
            $hits = 0;
            foreach ($reasons as $other) {
                $hits += (int) str_contains($other, $needle);
            }
            $this->assertSame(1, $hits, $needle . ' appears in more than one branch\'s sentence');
        }

        // AND THE FIVE SENTENCES ARE FIVE. Distinct strings, so a future edit
        // that collapses two of them fails here rather than passing on a
        // needle that happens to survive.
        $this->assertCount(5, array_unique($reasons));
    }

    /**
     * A STORED VALUE THAT IS NOT A KEY IS A REFUSAL, NEVER A 500.
     *
     * Placement validates what it accepts, so this is reachable only by a row written
     * straight into the option: a hand edit, a restored backup, a migration.
     * `sodium_crypto_sign_verify_detached` throws on a key that is not 32 bytes and
     * the verify sits outside any try, so the site answered 500 to a publish -- a
     * status that is not one of the five branches and names no repair.
     */
    public function test_a_corrupt_stored_public_key_refuses_and_does_not_crash(): void {
        $fields = $this->content_fields();
        $good   = $this->signed('/content', $fields);

        $keys = get_option(CadenceKey::OPTION, []);
        // Valid base64 and the wrong length: what a truncated paste produces.
        $keys[self::KEY]['verify'] = [['kid' => CadenceAttest::KID,
                                       'pk' => base64_encode('short'), 'added' => 0]];
        update_option(CadenceKey::OPTION, $keys);

        $r = CadenceAttestation::verify($good, '/content', $fields, self::KEY);

        $this->assertFalse($r['ok']);
        $this->assertSame('no_public_key', $r['branch']);
        // And the sentence is the one for THIS state: the screen shows a key and it is
        // not one. Telling an operator to paste a key they can already see is the
        // wrong repair.
        $this->assertStringContainsString('not one of them decodes to a key', $r['reason']);
    }

    /** AND ONE CORRUPT ENTRY BESIDE ONE GOOD ONE STILL VERIFIES. */
    public function test_a_corrupt_entry_does_not_disable_the_good_key_beside_it(): void {
        $fields = $this->content_fields();
        $keys = get_option(CadenceKey::OPTION, []);
        $keys[self::KEY]['verify'] = [
            ['kid' => 'ffffffffffffffff', 'pk' => base64_encode('short'), 'added' => 0],
            ['kid' => CadenceAttest::KID, 'pk' => CadenceAttest::public_key_base64(self::KEY),
             'added' => 1],
        ];
        update_option(CadenceKey::OPTION, $keys);

        $r = CadenceAttestation::verify($this->signed('/content', $fields), '/content',
                                        $fields, self::KEY);

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame(CadenceAttest::KID, $r['kid'],
            'the verifying kid is the good entry, not the first on the record');
    }

    /**
     * ONE SIGNATURE, ONE SPELLING: the last character's unused bits are not a licence.
     *
     * 64 bytes is 512 bits and 86 base64 characters carry 516, so the final
     * character's low four bits are never read -- sixteen tokens decode to the same
     * signature and a plain decoder accepts all sixteen. That gives one signature
     * sixteen headers, which is the same thing a padded token would be, and
     * `malformed` has to keep meaning "no Cadence sending service composed this".
     */
    public function test_an_alternative_spelling_of_the_same_signature_is_malformed(): void {
        $fields = $this->content_fields();
        $good   = $this->signed('/content', $fields);
        [$v, $kid, $token] = explode(' ', $good);

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $at       = strpos($alphabet, $token[85]);
        $variant  = substr($token, 0, 85) . $alphabet[$at ^ 1];

        $this->assertNotSame($token, $variant);
        // The premise: it really is the same 64 bytes, so only a re-encode check can
        // tell them apart. If this ever fails the test below proves nothing.
        $this->assertSame(
            base64_decode(strtr($token, '-_', '+/') . '==', true),
            base64_decode(strtr($variant, '-_', '+/') . '==', true),
            'the variant is a different signature, so this test is not measuring the spelling');

        $r = CadenceAttestation::verify($v . ' ' . $kid . ' ' . $variant, '/content',
                                        $fields, self::KEY);

        $this->assertFalse($r['ok']);
        $this->assertSame('malformed', $r['branch'],
            'a second spelling of one signature was accepted as a header');
    }

    /**
     * THE FIELD ORDER IS DERIVED FROM THE PLAN, and it is the contract's.
     *
     * DERIVED AND THEN COMPARED, never read out of the vector and composed
     * from: an implementation handed the order it is supposed to produce has
     * been told the answer, and the one thing these vectors exist to catch is a
     * second implementation producing a different one.
     *
     * `@members` is the only entry in a field order that is not a field, and
     * this is where its expansion is pinned: four names per member, prefixed
     * `member.<i>.`, i counting from 0 over the CANONICAL order.
     */
    public function test_every_link_vector_derives_the_contracts_own_field_order(): void {
        foreach ($this->link_vectors() as $v) {
            $fields = CadenceAttestation::link_fields($v['plan']);
            $this->assertIsArray($fields, $v['label']);
            $this->assertSame($v['order'],
                CadenceAttestation::signed_field_order($v['route'], $fields), $v['label']);
            // AND THE MATERIAL IS COMPOSED OVER THAT ORDER AND NOTHING ELSE --
            // the map's own key order could agree with it by accident, so the
            // expansion is what the bytes are checked against.
            $this->assertSame(array_keys($fields),
                CadenceAttestation::signed_field_order($v['route'], $fields), $v['label']);
        }
    }

    /**
     * THE SOURCE IS MEMBER 0 AND THE TRANSLATIONS ARE SORTED.
     *
     * Read back out of the composed fields, so what is asserted is the order
     * the BYTES are in. The source is member 0 because it is the plan's
     * `source`, never because of its language: in
     * `link-create-group-unicode-piece-id` it is `en-gb`, which sorts after
     * `de` and would be member 1 if the sort reached it.
     */
    public function test_the_canonical_member_order_is_the_source_then_sorted_translations(): void {
        foreach ($this->link_vectors() as $v) {
            $fields = CadenceAttestation::link_fields($v['plan']);
            $languages = [];
            for ($i = 0; array_key_exists('member.' . $i . '.language_code', $fields); $i++) {
                $languages[] = $fields['member.' . $i . '.language_code'];
            }
            $this->assertSame($v['members'], $languages, $v['label']);
        }
    }

    /**
     * AND THE WIRE ORDER IS NOT THE CANONICAL ORDER.
     *
     * The case that separates a correct implementation from a plausible one.
     * The translations arrive `pt-br`, `de`, `it`; the material carries `de`,
     * `it`, `pt-br` after the source. A verifier that composed them in the
     * order its JSON parser handed back would hash different bytes and refuse
     * every honest request as `mismatch` -- blaming a tenant's signing key for
     * a proxy that re-serialised the body.
     */
    public function test_the_translations_are_sorted_and_never_taken_in_wire_order(): void {
        $v = $this->vector_named('link-wire-order-is-not-canonical-order');
        $wire = array_keys($v['plan']['translations']);
        $this->assertSame(['pt-br', 'de', 'it'], $wire,
            'the fixture arrived in sorted order, so this proves nothing');
        $this->assertNotSame($wire, array_slice($v['members'], 1),
            'the fixture no longer separates a sorting implementation from a wire-order one');

        $fields = CadenceAttestation::link_fields($v['plan']);
        $this->assertSame(['en', 'de', 'it', 'pt-br'], [
            $fields['member.0.language_code'], $fields['member.1.language_code'],
            $fields['member.2.language_code'], $fields['member.3.language_code'],
        ]);
        // The bytes, not merely the order: composing in wire order is a
        // different material and a different digest, and this is the vector
        // that says which one the sending service signed.
        $this->assertSame(base64_decode($v['material'], true),
            CadenceAttestation::material($v['route'], $fields));
    }

    /**
     * THE TIE-BREAK, over a plan the route itself refuses.
     *
     * Two translations sharing a `language_code` make a sort by language alone
     * ambiguous, and two implementations could order them differently over one
     * honest body. `post_id` ascending breaks it. Neither half will ever compose
     * this plan -- this connector refuses it as `bad_plan` -- but verification
     * runs BEFORE that refusal, so the form has to answer for it anyway, which
     * is why this is driven through the composer and not through `run`.
     */
    public function test_two_translations_sharing_a_language_are_ordered_by_post_id(): void {
        $v = $this->vector_named('link-two-translations-share-a-language');
        $fields = CadenceAttestation::link_fields($v['plan']);
        $this->assertIsArray($fields);
        $this->assertSame([30, 20], array_column(array_values($v['plan']['translations']), 'post_id'),
            'the fixture is already in post_id order, so the tie-break is not exercised');
        $this->assertSame([1, 20, 30], [$fields['member.0.post_id'],
            $fields['member.1.post_id'], $fields['member.2.post_id']]);
        $this->assertSame(base64_decode($v['material'], true),
            CadenceAttestation::material($v['route'], $fields));
    }

    /**
     * AN ABSENT `trid`, `piece_id` OR `source_language_code` IS A ZERO-LENGTH
     * VALUE, and never a missing line.
     *
     * `trid:0:` is well formed and signs normally, exactly as an empty title
     * does on `/content`. A composer that omitted the line instead would
     * produce a shorter material that still parses, and the two halves would
     * disagree on every plan that creates a group.
     */
    public function test_an_absent_optional_renders_as_a_zero_length_value(): void {
        $v = $this->vector_named('link-wire-order-is-not-canonical-order');
        $this->assertArrayNotHasKey('piece_id', $v['plan'],
            'the fixture names a piece, so the empty rendering is not exercised');
        $material = CadenceAttestation::material(
            $v['route'], CadenceAttestation::link_fields($v['plan']));
        $this->assertStringContainsString("\npiece_id:0:\n", $material);
        $this->assertStringContainsString("\nmember.2.source_language_code:0:\n", $material);
        // AND THE BOOLEAN IS THE ASCII LITERAL, never `1`/`0`.
        $this->assertStringContainsString("\ncreate_group:5:false\n", $material);
        $create = $this->vector_named('link-create-group-unicode-piece-id');
        $this->assertStringContainsString("\ncreate_group:4:true\n", CadenceAttestation::material(
            $create['route'], CadenceAttestation::link_fields($create['plan'])));
    }

    /**
     * A PLAN WITH NO RENDERING IS REFUSED BEFORE A SIGNATURE IS COMPOSED.
     *
     * `'true'` and `true` rendered alike would let a plan the shape check
     * refuses verify against a signature over a plan it accepts, and a `post_id`
     * that is a string is the same coercion `/content/replace` refuses. The
     * return is a REASON and not an exception: this runs on an unvalidated body,
     * ahead of every other check, and an exception there is a 500 naming no
     * repair.
     *
     * @param array $plan
     */
    #[DataProvider('unsignable_plans')]
    public function test_a_plan_with_no_rendering_has_no_material(array $plan, string $names): void {
        $reason = CadenceAttestation::link_fields($plan);
        $this->assertIsString($reason, $names . ' was signed rather than refused');
        $this->assertStringContainsString($names, $reason);
        $this->assertStringContainsString('cannot be signed', $reason);
    }

    public static function unsignable_plans(): array {
        $ok = ['trid' => 5, 'create_group' => false,
               'source' => ['post_id' => 1, 'language_code' => 'en', 'element_type' => 'post_post'],
               'translations' => [['post_id' => 2, 'language_code' => 'de',
                                   'element_type' => 'post_post']]];
        return [
            'a string boolean'   => [array_merge($ok, ['create_group' => 'false']), 'create_group'],
            'an integer boolean' => [array_merge($ok, ['create_group' => 0]), 'create_group'],
            'an absent boolean'  => [array_diff_key($ok, ['create_group' => null]), 'create_group'],
            'a string trid'      => [array_merge($ok, ['trid' => '5']), 'trid'],
            'a boolean trid'     => [array_merge($ok, ['trid' => true]), 'trid'],
            'a non-string piece' => [array_merge($ok, ['piece_id' => 7]), 'piece_id'],
            'a scalar source'    => [array_merge($ok, ['source' => 'post 1']), 'source'],
            'a string post_id'   => [array_merge($ok, ['translations' => [
                ['post_id' => '2', 'language_code' => 'de', 'element_type' => 'post_post']]]),
                'post_id'],
            'a boolean post_id'  => [array_merge($ok, ['translations' => [
                ['post_id' => true, 'language_code' => 'de', 'element_type' => 'post_post']]]),
                'post_id'],
            'a non-string code'  => [array_merge($ok, ['translations' => [
                ['post_id' => 2, 'language_code' => 3, 'element_type' => 'post_post']]]),
                'language_code'],
            'a non-string type'  => [array_merge($ok, ['translations' => [
                ['post_id' => 2, 'language_code' => 'de', 'element_type' => []]]]),
                'element_type'],
            'a non-string source code' => [array_merge($ok, ['translations' => [
                ['post_id' => 2, 'language_code' => 'de', 'element_type' => 'post_post',
                 'source_language_code' => 5]]]), 'source_language_code'],
        ];
    }

    /**
     * A MEMBER `post_id` THAT IS NOT AN INT IS REFUSED WHERE THE BYTES ARE MADE
     * TOO, and not only by the reduction above -- the same refusal said twice,
     * so a future call site that arrived from somewhere else cannot reintroduce
     * the coercion. `'007'` and `7` are one post and two signatures.
     */
    public function test_the_material_refuses_a_member_post_id_that_is_a_string(): void {
        $fields = CadenceAttestation::link_fields(
            $this->vector_named('link-join-existing-group')['plan']);
        $fields['member.1.post_id'] = '102';
        $this->expectException(InvalidArgumentException::class);
        CadenceAttestation::material('/translation-group', $fields);
    }

    /** One vector by its label, so a test naming one cannot silently pick another. */
    private function vector_named(string $label): array {
        foreach ($this->link_vectors() as $v) {
            if ($v['label'] === $label) {
                return $v;
            }
        }
        $this->fail('no link vector labelled ' . $label);
    }

    /** THE BRANCH VOCABULARY IS CLOSED, and the contract's list is that list. */
    public function test_the_branch_vocabulary_is_exactly_six(): void {
        $source = file_get_contents(__DIR__ . '/../includes/class-cadence-attestation.php');
        foreach (['absent', 'exempt_refused', 'malformed', 'unknown_kid', 'no_public_key', 'mismatch'] as $branch) {
            $this->assertSame(1, substr_count($source, "self::refuse('" . $branch . "'"),
                $branch . ' is refused from a number of places that is not one');
        }
        $this->assertSame(6, substr_count($source, 'self::refuse('),
            'a seventh branch was added, or one was removed');
    }

    /** A HEADER THAT VERIFIES IS `verified`, AND CARRIES THE KID THAT VERIFIED IT. */
    public function test_a_good_header_verifies_and_names_its_own_kid(): void {
        $fields = $this->content_fields();
        $r = CadenceAttestation::verify($this->signed('/content', $fields), '/content',
                                        $fields, self::KEY);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame('verified', $r['attestation']);
        $this->assertSame(CadenceAttest::KID, $r['kid']);
        $this->assertArrayNotHasKey('branch', $r);
    }

    /**
     * AND THE KID IT NAMES IS THE ONE THAT VERIFIED, NEVER THE FIRST ON THE
     * RECORD.
     *
     * The whole reason two keys may be held is that a rotation gets an overlap
     * window instead of an outage -- and during one, the reply naming the live
     * key is the only way an operator can see the new key has taken effect
     * before removing the old one. A verifier that reported the first entry
     * would say "rotated" from the moment the second key was pasted.
     */
    public function test_the_reported_kid_is_the_one_that_verified(): void {
        $retiring = 'aaaaaaaaaaaaaaaa';
        $arriving = 'bbbbbbbbbbbbbbbb';
        $keys = get_option(CadenceKey::OPTION, []);
        // The retiring key FIRST, and a public key that is not ours, so the
        // only entry that can verify is the second.
        $keys[self::KEY]['verify'] = [
            ['kid' => $retiring, 'pk' => self::VECTOR_PK, 'added' => 0],
            ['kid' => $arriving, 'pk' => CadenceAttest::public_key_base64(), 'added' => 1],
        ];
        update_option(CadenceKey::OPTION, $keys);

        $fields = $this->content_fields();
        $header = 'v1 ' . $arriving . ' '
                . CadenceAttest::sign(CadenceAttestation::material('/content', $fields));
        $r = CadenceAttestation::verify($header, '/content', $fields, self::KEY);

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame($arriving, $r['kid'],
            'the reply named a key other than the one that verified the signature');
        $this->assertNotSame($retiring, $r['kid']);
    }

    /**
     * THE EXEMPTION EXEMPTS AN ABSENCE AND NEVER A FAILURE.
     *
     * Three facts in one test because they are one rule: "the flag means skip
     * verification" is the mutation, and it is killed only by asserting all
     * three sides of the boundary at once.
     */
    public function test_the_exemption_covers_an_absent_header_and_nothing_else(): void {
        CadenceKey::set_unsigned_ok(self::KEY, true, 3);
        $fields = $this->content_fields();

        // 1. ABSENT + flag -> exempt, and no kid, because no key was used.
        $absent = CadenceAttestation::verify(null, '/content', $fields, self::KEY);
        $this->assertTrue($absent['ok']);
        $this->assertSame('exempt', $absent['attestation']);
        $this->assertArrayNotHasKey('kid', $absent);

        // 2. A HEADER THAT VERIFIES -> verified, WHATEVER the flag says. The
        // flag is not a mode the reply reports; it is a rule about absence.
        $good = CadenceAttestation::verify($this->signed('/content', $fields), '/content',
                                           $fields, self::KEY);
        $this->assertTrue($good['ok']);
        $this->assertSame('verified', $good['attestation'],
            'an exempt key reported `exempt` for a signature that actually verified');
        $this->assertSame(CadenceAttest::KID, $good['kid']);

        // 3. PRESENT BUT BAD + flag -> still refused. Absence is a migration;
        // a bad signature is not, and there is no reading under which it is.
        $signature = CadenceAttest::sign(CadenceAttestation::material('/content', $fields));
        foreach ([['v1 not-a-kid xxx', 'malformed'],
                  [$this->signed('/content', $this->content_fields(['title' => 'X'])), 'mismatch'],
                  // A kid this record does not hold -- composed by hand, since
                  // the helper would paste the key on its way past and make
                  // the branch under test unreachable.
                  ['v1 ffffffffffffffff ' . $signature, 'unknown_kid']] as [$h, $branch]) {
            $r = CadenceAttestation::verify($h, '/content', $fields, self::KEY);
            $this->assertFalse($r['ok'],
                'the exemption let a ' . $branch . ' header through, so the flag skips verification');
            $this->assertSame($branch, $r['branch']);
        }
    }

    /** Setting the flag records WHEN and BY WHOM; clearing it removes the record. */
    public function test_the_exemption_records_who_set_it_and_when(): void {
        $this->assertNull(CadenceKey::unsigned_ok(self::KEY));

        CadenceKey::set_unsigned_ok(self::KEY, true, 7);
        $flag = CadenceKey::unsigned_ok(self::KEY);
        $this->assertIsArray($flag);
        $this->assertSame(7, $flag['by_user']);
        $this->assertGreaterThan(0, $flag['set_at']);

        CadenceKey::set_unsigned_ok(self::KEY, false);
        $this->assertNull(CadenceKey::unsigned_ok(self::KEY),
            'cleared means absent, and there is one spelling of off');
    }

    /**
     * MALFORMED IS EVERY SPELLING THAT IS NOT THE ONE SPELLING.
     *
     * Each row is a shape a tolerant verifier would have accepted, and every
     * one of them is a second spelling of a header that has exactly one.
     */
    public function test_every_tolerated_spelling_is_malformed(): void {
        $fields = $this->content_fields();
        $good   = $this->signed('/content', $fields);
        [, $kid, $signature] = explode(' ', $good);

        $bad = [
            'a fourth token'        => $good . ' extra',
            'two tokens'            => 'v1 ' . $kid,
            'a padded signature'    => 'v1 ' . $kid . ' ' . $signature . '==',
            'standard base64'       => 'v1 ' . $kid . ' ' . strtr($signature, '-_', '+/') . '=',
            'an uppercase kid'      => 'v1 ' . strtoupper($kid) . ' ' . $signature,
            'a short kid'           => 'v1 ' . substr($kid, 0, 15) . ' ' . $signature,
            'a v2 header'           => 'v2 ' . $kid . ' ' . $signature,
            'no version token'      => $kid . ' ' . $signature,
            'a doubled space'       => 'v1  ' . $kid . ' ' . $signature,
            'leading whitespace'    => ' ' . $good,
            'a truncated signature' => 'v1 ' . $kid . ' ' . substr($signature, 0, 85),
            'not base64url at all'  => 'v1 ' . $kid . ' ' . str_repeat('*', 86),
        ];
        foreach ($bad as $why => $header) {
            $this->assertNull(CadenceAttestation::parse($header), $why . ' parsed');
            $r = CadenceAttestation::verify($header, '/content', $fields, self::KEY);
            $this->assertFalse($r['ok'], $why . ' was accepted');
            $this->assertSame('malformed', $r['branch'], $why);
        }
        // And the one spelling still parses, so the rows above are not passing
        // because parse refuses everything.
        $this->assertNotNull(CadenceAttestation::parse($good));
    }

    /**
     * A PASTE THAT COULD NOT VERIFY ANYTHING IS REFUSED AT PLACEMENT.
     *
     * Stored wrong, every one of these surfaces later as `mismatch` on a
     * client's site -- a branch that says the body was tampered with, over a
     * typo. And a 31- or 33-byte key makes
     * `sodium_crypto_sign_verify_detached` THROW rather than answer false,
     * which is a 500 where a 403 was meant.
     */
    public function test_a_public_key_that_could_never_verify_is_refused_at_placement(): void {
        $ok = CadenceAttest::public_key_base64();
        $this->assertTrue(CadenceKey::remove_verify_key(self::KEY, CadenceAttest::KID));

        $short = base64_encode(random_bytes(31));
        $long  = base64_encode(random_bytes(33));
        $bad = [
            '31 bytes'    => [$short, 'decodes to 31'],
            '33 bytes'    => [$long, 'decodes to 33'],
            'not base64'  => ['not base64 at all!!', 'not base64 at all'],
            'empty'       => ['', 'not base64 at all'],
        ];
        foreach ($bad as $why => [$value, $needle]) {
            $r = CadenceKey::add_verify_key(self::KEY, 'a1a1a1a1a1a1a1a1', $value);
            $this->assertIsString($r, $why . ' was stored');
            $this->assertStringContainsString($needle, $r, $why);
            $this->assertSame([], CadenceKey::verify_keys(self::KEY), $why . ' wrote something');
        }

        foreach (['A1A1A1A1A1A1A1A1', 'a1a1', 'a1a1a1a1a1a1a1a1a1', 'zzzzzzzzzzzzzzzz'] as $kid) {
            $r = CadenceKey::add_verify_key(self::KEY, $kid, $ok);
            $this->assertIsString($r, $kid . ' was stored');
            $this->assertStringContainsString('16 lowercase hex', $r, $kid);
        }

        // And a good one still lands, so the rows above are not passing
        // because placement refuses everything.
        $this->assertTrue(CadenceKey::add_verify_key(self::KEY, 'a1a1a1a1a1a1a1a1', $ok));
        $this->assertCount(1, CadenceKey::verify_keys(self::KEY));
    }

    /** TWO KEYS, AND A THIRD IS REFUSED. The list is the blast radius. */
    public function test_a_third_public_key_is_refused(): void {
        $ok = CadenceAttest::public_key_base64();
        $this->assertTrue(CadenceKey::remove_verify_key(self::KEY, CadenceAttest::KID));

        $this->assertTrue(CadenceKey::add_verify_key(self::KEY, '1111111111111111', $ok));
        $this->assertTrue(CadenceKey::add_verify_key(self::KEY, '2222222222222222', $ok));
        $this->assertSame(2, CadenceKey::MAX_VERIFY_KEYS);

        $r = CadenceKey::add_verify_key(self::KEY, '3333333333333333', $ok);
        $this->assertIsString($r, 'a third public key was stored');
        $this->assertStringContainsString('which is the most', $r);
        $this->assertCount(2, CadenceKey::verify_keys(self::KEY));

        // AND REMOVING ONE MAKES ROOM, or the window is one-way and a second
        // rotation is impossible.
        $this->assertTrue(CadenceKey::remove_verify_key(self::KEY, '1111111111111111'));
        $this->assertTrue(CadenceKey::add_verify_key(self::KEY, '3333333333333333', $ok));
        $this->assertCount(2, CadenceKey::verify_keys(self::KEY));
    }

    /** ONE KID NEVER MEANS TWO KEYS. */
    public function test_a_duplicate_kid_is_refused(): void {
        $r = CadenceKey::add_verify_key(self::KEY, CadenceAttest::KID, self::VECTOR_PK);
        $this->assertIsString($r, 'a second key was stored under one name');
        $this->assertStringContainsString('already holds a public key under the name', $r);
        $this->assertCount(1, CadenceKey::verify_keys(self::KEY));
    }

    /**
     * THE VERIFYING KEYS ARE PER CONNECTOR KEY, NEVER PER SITE.
     *
     * A site-wide list would let one tenant's signing key attest a body sent
     * under another tenant's connector key, which is the isolation the whole
     * per-key record exists to draw.
     */
    public function test_one_tenants_public_key_does_not_verify_anothers_request(): void {
        $other = 'ca11ab1e0000key3';
        $pair = sodium_crypto_sign_keypair();
        CadenceAttest::install($other);
        $this->assertTrue(CadenceKey::add_verify_key($other, 'cccccccccccccccc',
            base64_encode(sodium_crypto_sign_publickey($pair))));
        $fields = $this->content_fields();

        // Signed by the other tenant's key, naming the kid only the OTHER record holds.
        $header = 'v1 cccccccccccccccc ' . CadenceAttest::sign(CadenceAttestation::material('/content', $fields),
                                                                sodium_crypto_sign_secretkey($pair));
        $r = CadenceAttestation::verify($header, '/content', $fields, self::KEY);

        $this->assertFalse($r['ok'], 'a key on another tenant\'s record verified this request');
        $this->assertSame('unknown_kid', $r['branch']);
        // And it verifies against the record that actually holds it.
        $this->assertTrue(CadenceAttestation::verify($header, '/content', $fields, $other)['ok']);
    }

    /**
     * ONE PUBLIC KEY IS NEVER ON TWO CONNECTOR KEYS. The signed bytes do not
     * name the connector key, so the same public key on two of them would
     * let a body signed for one verify as the other's.
     */
    public function test_a_public_key_already_on_another_connector_key_is_refused(): void {
        $other = 'ca11ab1e0000key3';
        CadenceAttest::install(self::KEY);
        CadenceAttest::install($other);
        $r = CadenceKey::add_verify_key($other, 'cccccccccccccccc', ' ' . CadenceAttest::public_key_base64(self::KEY));
        $this->assertIsString($r);
        $this->assertStringContainsString('already attached to another connector key', $r);

        $fields = $this->content_fields();
        $header = CadenceAttest::header('/content', $fields, self::KEY);
        $this->assertTrue(CadenceAttestation::verify($header, '/content', $fields, self::KEY)['ok'],
                          'the twin: it verifies under the key it was made for');
        $this->assertFalse(CadenceAttestation::verify($header, '/content', $fields, $other)['ok']);

        // THE TWIN: the same key id on its own record takes a different public key.
        $this->assertTrue(CadenceKey::add_verify_key($other, 'cccccccccccccccc',
            base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))));
    }

    /**
     * VERIFICATION RUNS BEFORE THE INSERT, AND BEFORE THE SITE IS ASKED
     * ANYTHING AT ALL.
     *
     * Asserted through the CODE rather than through a call counter: this body
     * names a post type the site does not register AND carries a declaration
     * that disagrees with it, so a verifier placed after either of those
     * checks answers `bad_request` or `unsupported_language`. Only a verifier
     * placed before both can answer `attestation_unverified` -- and each of
     * those refusals is a read of the client's site whose answer an
     * unattested caller must not be able to collect one 400 at a time.
     *
     * A verifier placed after `wp_insert_post` is not a guard at all, which
     * the empty insert log says separately.
     */
    public function test_an_unattested_publish_is_refused_before_the_site_is_read(): void {
        $r = CadenceContentRequest::run([
            'piece_id'  => 'p-1',
            'language'  => 'en',
            'post_type' => 'no_such_type_on_this_site',
            'status'    => 'draft',
            'title'     => 'T',
            'content'   => 'C',
            'declared'  => ['multilingual' => false],
        ], static fn (string $c): bool => true, ['post'], null, self::KEY, null);

        $this->assertFalse($r['ok'], 'an unattested publish was accepted');
        $this->assertSame('attestation_unverified', $r['code'],
            'a check that reads the site ran before the signature did');
        $this->assertStringContainsString('carried no ' . CadenceAttestation::HEADER
            . ' header at all', $r['reason'], 'the refusal did not name the absent branch');
        $this->assertSame([], WpStub::$inserted, 'an unattested publish reached wp_insert_post');
    }

    /**
     * AND THE SAME BODY, ATTESTED, GETS PAST THE ATTESTATION TO THE CHECK THAT
     * WAS BEHIND IT.
     *
     * Without this the test above passes on a route that refuses everything.
     * The second refusal is a DIFFERENT code, which is what says the
     * attestation let it through rather than that both answers are the same.
     */
    public function test_the_same_body_attested_reaches_the_check_behind_the_guard(): void {
        $body = [
            'piece_id'  => 'p-1',
            'language'  => 'en',
            'post_type' => 'no_such_type_on_this_site',
            'status'    => 'draft',
            'title'     => 'T',
            'content'   => 'C',
            'declared'  => ['multilingual' => false],
        ];
        $r = CadenceContentRequest::run($body, static fn (string $c): bool => true, ['post'],
            null, self::KEY,
            $this->signed('/content', CadenceAttest::fields('/content', $body)));

        $this->assertFalse($r['ok']);
        $this->assertNotSame('attestation_unverified', $r['code'],
            'an attested body was still refused by the attestation');
        $this->assertSame('post_type_out_of_scope', $r['code']);
    }

    /**
     * EVERY BRANCH REACHES THE WIRE, AND THIS IS ASKED OF THE REPLY BODY.
     *
     * `test_each_branch_answers_403_with_its_own_sentence` above asks
     * `CadenceAttestation::verify()` directly, which would still pass even if
     * the branch were DROPPED at the route -- both request classes return
     * only `ok`, `code` and `reason`, and `respond()` has no slot for it
     * unless this is asked of the wire too. Dropped there, every refusal
     * would arrive at the caller as one it could not name: a first setup
     * with no key pasted and a half-finished rotation would be
     * indistinguishable.
     *
     * The lesson is the assertion's SUBJECT, not its strength: a branch is a
     * thing the caller reads off the wire, so it has to be asked of the
     * wire, not merely of the class that computed it.
     */
    public function test_every_branch_reaches_the_reply_body_through_the_route(): void {
        $body = ['piece_id' => 'p-1', 'language' => 'en', 'post_type' => 'post',
                 'status' => 'draft', 'title' => 'T', 'content' => 'C',
                 'declared' => ['multilingual' => true, 'languages' => ['en']]];
        $fields = CadenceAttest::fields('/content', $body);
        $good   = $this->signed('/content', $fields);

        $cases = [
            'absent'        => null,
            'malformed'     => 'v1 ' . CadenceAttest::KID,
            'unknown_kid'   => 'v1 ffffffffffffffff ' . explode(' ', $good)[2],
            'no_public_key' => $good,
            'mismatch'      => $this->signed(
                '/content', CadenceAttest::fields('/content', ['title' => 'OTHER'] + $body)),
        ];

        $seen = [];
        foreach ($cases as $branch => $header) {
            $keys = get_option(CadenceKey::OPTION, []);
            $keys[self::KEY]['verify'] = $branch === 'no_public_key'
                ? []
                : [['kid' => CadenceAttest::KID, 'pk' => CadenceAttest::public_key_base64(),
                    'added' => 0]];
            update_option(CadenceKey::OPTION, $keys);
            WpStub::$inserted = [];

            $r      = CadenceContentRequest::run($body, static fn (string $c): bool => false,
                                                 null, null, self::KEY, $header);
            $answer = CadenceRestRoute::respond($r);

            $this->assertSame(403, $answer['status'], $branch);
            $this->assertSame('attestation_unverified', $answer['body']['code'], $branch);
            $this->assertArrayHasKey('attestation_branch', $answer['body'],
                $branch . ' was refused and the reply body does not say which branch fired');
            $this->assertSame($branch, $answer['body']['attestation_branch'], $branch);
            $this->assertCount(0, WpStub::$inserted, $branch . ' wrote something');
            $seen[] = $answer['body']['attestation_branch'];
        }

        // THE DENOMINATOR, so a loop that stopped arranging branches cannot report a
        // pass over fewer than five.
        $this->assertSame(array_keys($cases), $seen);
        $this->assertCount(5, array_unique($seen), 'two branches answered the same name');
    }

    /** A REPLY THAT IS NOT AN ATTESTATION REFUSAL CARRIES NO BRANCH. */
    public function test_an_ordinary_refusal_does_not_name_an_attestation_branch(): void {
        // A type OUTSIDE this key's scope: an ordinary refusal, taken after the
        // attestation verified, so the reply is a refusal with no branch to name.

        $body = ['piece_id' => 'p-1', 'language' => 'en', 'post_type' => 'page',
                 'status' => 'draft', 'title' => 'T', 'content' => 'C',
                 'declared' => ['multilingual' => true, 'languages' => ['en']]];
        $r = CadenceContentRequest::run($body, static fn (string $c): bool => false, ['post'],
            null, self::KEY,
            $this->signed('/content', CadenceAttest::fields('/content', $body)));
        $answer = CadenceRestRoute::respond($r);

        $this->assertFalse($answer['body']['ok']);
        $this->assertSame('post_type_out_of_scope', $answer['body']['code']);
        $this->assertNotSame('attestation_unverified', $answer['body']['code']);
        $this->assertArrayNotHasKey('attestation_branch', $answer['body'],
            'a refusal that is not about the attestation named an attestation branch');
    }

    /** AND AN ATTESTED PUBLISH OF A GOOD BODY WRITES, AND SAYS SO ON THE WIRE. */
    #[Group('wpml')]
    public function test_an_attested_publish_writes_and_reports_verified(): void {
        $body = ['piece_id' => 'p-1', 'language' => 'en', 'post_type' => 'post',
                 'status' => 'draft', 'title' => 'T', 'content' => 'C',
                 'declared' => ['multilingual' => true, 'languages' => ['en']]];
        $r = CadenceContentRequest::run($body, static fn (string $c): bool => false, null,
            null, self::KEY,
            $this->signed('/content', CadenceAttest::fields('/content', $body)));

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertCount(1, WpStub::$inserted);
        $this->assertSame('verified', $r['attestation']);
        $this->assertSame(CadenceAttest::KID, $r['attestation_kid']);

        $answer = CadenceRestRoute::respond($r);
        $this->assertSame('verified', $answer['body']['attestation'],
            'the reply dropped the attestation state on its way through respond()');
        $this->assertSame(CadenceAttest::KID, $answer['body']['attestation_kid']);
        $this->assertSame(2, $answer['body']['reply_schema']);
    }

    /** AN EXEMPT PUBLISH SAYS `exempt`, AND NAMES NO KID. */
    #[Group('wpml')]
    public function test_an_exempt_publish_reports_exempt_with_no_kid(): void {
        CadenceKey::set_unsigned_ok(self::KEY, true, 1);
        $body = ['piece_id' => 'p-1', 'language' => 'en', 'post_type' => 'post',
                 'status' => 'draft', 'title' => 'T', 'content' => 'C',
                 'declared' => ['multilingual' => true, 'languages' => ['en']]];
        $r = CadenceContentRequest::run($body, static fn (string $c): bool => false, null,
            null, self::KEY, null);

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $answer = CadenceRestRoute::respond($r);
        $this->assertSame('exempt', $answer['body']['attestation']);
        $this->assertArrayNotHasKey('attestation_kid', $answer['body'],
            'an exempt reply named a key, and no key was used');
    }

    /**
     * THE REWRITE ROUTE, SAME PLACEMENT. This body names a post that does not
     * exist on the site; only a verifier that runs before `get_post` answers
     * `attestation_unverified` rather than `post_missing`.
     */
    public function test_an_unattested_rewrite_is_refused_before_the_post_is_read(): void {
        $r = CadenceReplaceRequest::run([
            'piece_id' => 'p-1', 'post_id' => 4242,
            'revision' => 'sha256:whatever', 'title' => 'T', 'content' => 'C',
        ], null, self::KEY, null);

        $this->assertFalse($r['ok']);
        $this->assertSame('attestation_unverified', $r['code'],
            'the site was read before the signature was checked');
        $this->assertSame([], WpStub::$updated);
    }

    /** THE PLUGIN AND THE ROUTE DECLARE ONE VERSION, AND THE SCHEMA IS 2. */
    public function test_the_reply_schema_moved_with_the_field_it_introduces(): void {
        $this->assertSame(2, CadenceRestRoute::REPLY_SCHEMA);
        $header = file_get_contents(__DIR__ . '/../cadence-connector.php');
        $this->assertSame(1, preg_match('/^ \* Version: +(\S+)$/m', $header, $m));
        $this->assertSame(CadenceRestRoute::VERSION, $m[1],
            'the plugin header and the route declare different versions');
    }

    /**
     * THE REWRITE'S REPLY CARRIES THE ATTESTATION TOO.
     *
     * It did not, for one commit: the success answer is composed two methods
     * below `run`, and the state was referenced there from a variable that
     * does not reach it -- which PHP answers with a warning and a null, so the
     * field was silently absent while every other test passed. Asserted here
     * so the field has somewhere to be missing from.
     */
    #[Group('wpml')]
    public function test_a_rewrite_reports_the_attestation_that_let_it_through(): void {
        $body = ['piece_id' => 'p-1', 'language' => 'en', 'post_type' => 'post',
                 'status' => 'draft', 'title' => 'T', 'content' => 'C',
                 'declared' => ['multilingual' => true, 'languages' => ['en']]];
        $published = CadenceContentRequest::run($body, static fn (string $c): bool => true,
            null, null, self::KEY,
            $this->signed('/content', CadenceAttest::fields('/content', $body)));
        $this->assertTrue($published['ok'], $published['reason'] ?? '');

        $rewrite = ['piece_id' => 'p-1', 'post_id' => $published['post_id'],
                    'revision' => $published['revision'], 'title' => 'T2', 'content' => 'C2'];
        $r = CadenceReplaceRequest::run($rewrite, null, self::KEY,
            $this->signed('/content/replace', CadenceAttest::fields('/content/replace', $rewrite)));

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame('verified', $r['attestation'] ?? null,
            'the rewrite reply dropped the attestation state');
        $this->assertSame(CadenceAttest::KID, $r['attestation_kid'] ?? null);
        $this->assertSame('verified', CadenceRestRoute::respond($r)['body']['attestation']);
    }

    /**
     * EACH VECTOR VERIFIES AGAINST ITS OWN ROUTE AND AGAINST NO OTHER. The
     * route line is the first thing in the material, so a signature for one
     * route must not verify a body presented to another, even where the two
     * sign the same names.
     */
    public function test_every_contract_vector_verifies_only_against_its_own_route(): void {
        $this->install_vector_key();
        $vectors = $this->vectors();
        $routes = array_unique(array_column($vectors, 'route'));
        $crossed = 0;
        foreach ($vectors as $v) {
            foreach ($routes as $route) {
                if ($route === $v['route']) {
                    continue;
                }
                try {
                    $r = CadenceAttestation::verify($v['header'], $route, $v['fields'], self::KEY);
                } catch (InvalidArgumentException $e) {
                    // The other route signs a name this body does not carry:
                    // no material, so no verification either.
                    $crossed++;
                    continue;
                }
                $this->assertFalse($r['ok'], $v['label'] . ' verified against ' . $route);
                $this->assertSame('mismatch', $r['branch'], $v['label'] . ' against ' . $route);
                $crossed++;
            }
        }
        $this->assertSame(count($vectors) * (count($routes) - 1), $crossed);
        $this->assertGreaterThan(0, $crossed);
    }

    /**
     * THE FIVE-FIELD AND THE EIGHT-FIELD REWRITE ARE TWO SIGNATURES. Each
     * verifies against its own body and not the other's: the confirmation
     * cannot be stripped from a signed body, nor added to one.
     */
    public function test_a_five_field_and_an_eight_field_rewrite_do_not_verify_each_other(): void {
        $this->install_vector_key();
        $by = array_column($this->vectors(), null, 'label');
        $five = $by['replace'];
        $eight = $by['replace-with-rewrite-confirmation'];
        $this->assertTrue(CadenceAttestation::verify($five['header'], '/content/replace', $five['fields'], self::KEY)['ok']);
        $this->assertTrue(CadenceAttestation::verify($eight['header'], '/content/replace', $eight['fields'], self::KEY)['ok']);
        foreach ([[$five['header'], $eight['fields']], [$eight['header'], $five['fields']]] as [$header, $fields]) {
            $r = CadenceAttestation::verify($header, '/content/replace', $fields, self::KEY);
            $this->assertFalse($r['ok']);
            $this->assertSame('mismatch', $r['branch']);
        }
        // AND THE FIVE-FIELD BODY'S ORDER NAMES NONE OF THE THREE.
        $this->assertSame(['piece_id', 'post_id', 'revision', 'title', 'content'],
            CadenceAttestation::signed_field_order('/content/replace', $five['fields']));
        $this->assertSame(['piece_id', 'post_id', 'revision', 'title', 'content',
                           'overwrite_adopted', 'site', 'issued_at'],
            CadenceAttestation::signed_field_order('/content/replace', $eight['fields']));
    }

    /**
     * THE SAME LIST, IN THE SAME ORDER, AS THE SIGNER'S LIST'S `SITE_PARITY`. Each
     * side pins the other's output: a row changed on one side only is a site
     * that refuses every adopt, release and rewrite confirmation as signed
     * for another site.
     */
    public static function site_parity(): array {
        $rows = [
            ['https://[::1]/blog', '[::1]/blog'],
            ['https://[::1]:8443/blog', '[::1]:8443/blog'],
            ['https://[2001:DB8::1]:8443/', '[2001:db8::1]:8443'],
            ["https://\u{00C9}XAMPLE.TEST/", "\u{00E9}xample.test"],
            ['https://example.test:443/', 'example.test'],
            ['http://example.test:80/', 'example.test'],
            ['https://example.test:80/', 'example.test:80'],
            ['http://example.test:443/', 'example.test:443'],
            ['https://Example.TEST/Blog/', 'example.test/Blog'],
            ['https://example.test/index.php', 'example.test/index.php'],
            ['https://example.test/blog/', 'example.test/blog'],
            ['https://example.test', 'example.test'],
        ];
        return array_combine(array_column($rows, 0), $rows);
    }

    #[DataProvider('site_parity')]
    public function test_site_matches_the_signers_normalisation(string $home, string $site): void {
        WpStub::reset();
        WpStub::$options['home'] = $home;
        $this->assertSame($site, CadenceAttestation::site());
    }
}
