<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * THE ADOPT ROUTE AND ITS PREVIEW: one test per refusal, each with a twin that
 * differs in one fact and passes.
 *
 * The fixture is post 41: a draft `post`, in English, alone in its own
 * translation group, carrying no Cadence meta, on a key scoped to `post`.
 * Every case below changes one thing about it and names the answer; the
 * `ok` cases are the twins.
 */
final class AdoptRequestTest extends TestCase {

    private const SIGN = "\0sign";
    private const KEY = CadenceAttest::KEY_ID;

    protected function setUp(): void {
        WpStub::reset();
        WpStub::$post_types[] = 'product';
        self::post(41);
    }

    private static function now(int $offset = 0): string {
        return gmdate('Y-m-d\TH:i:s\Z', time() + $offset);
    }

    private static function body(array $over = []): array {
        return array_merge(['piece_id' => 'piece-new', 'post_id' => 41, 'language' => 'en',
                            'site' => 'example.test', 'issued_at' => self::now()], $over);
    }

    private static function preview_body(array $over = []): array {
        return array_merge(['piece_id' => 'piece-new', 'link' => 'https://example.test/?p=41',
                            'site' => 'example.test', 'issued_at' => self::now()], $over);
    }

    /** A post as the site holds it. `status` fills both spellings the stubs read. */
    private static function post(int $id, array $over = []): void {
        $status = $over['status'] ?? 'draft';
        WpStub::add_post($id, $over['type'] ?? 'post', $over['language'] ?? 'en',
                         array_key_exists('trid', $over) ? $over['trid'] : 500 + $id,
                         $over['wpml_knows'] ?? true, $status);
        WpStub::$posts[$id]['post_status']   = $status;
        WpStub::$posts[$id]['post_title']    = $over['title'] ?? 'A title';
        WpStub::$posts[$id]['post_content']  = $over['content'] ?? 'Some text';
        WpStub::$posts[$id]['post_password'] = $over['password'] ?? '';
    }

    private static function adopt(array $body, ?array $types = ['post'], ?string $key = self::KEY,
                                  $attestation = self::SIGN): array {
        return CadenceAdoptRequest::run($body, $types, $key, $attestation === self::SIGN
            ? CadenceAttest::header('/adopt', CadenceAttest::fields('/adopt', $body), $key)
            : $attestation);
    }

    private static function preview(array $body, ?array $types = ['post'], ?string $key = self::KEY,
                                    $attestation = self::SIGN): array {
        return CadenceAdoptRequest::preview($body, $types, $key, $attestation === self::SIGN
            ? CadenceAttest::header('/adopt/preview', CadenceAttest::fields('/adopt/preview', $body), $key)
            : $attestation);
    }

    /** Every read of the site, leaving out the key store the attestation must read. */
    private static function site_reads(): array {
        $reads = WpStub::$reads;
        unset($reads['get_option:' . CadenceKey::OPTION]);
        return $reads;
    }

    // ------------------------------------------------------------------
    // Rows 1 to 4, and the attestation: nothing about the site is read.
    // ------------------------------------------------------------------

    public static function malformed(): array {
        return [
            'post_id as a string'  => [['post_id' => '41']],
            'post_id zero'         => [['post_id' => 0]],
            'no language'          => [['language' => null]],
            'language not a code'  => [['language' => 'English']],
            'blank piece_id'       => [['piece_id' => '  ']],
            'site not a string'    => [['site' => 7]],
            'issued_at not UTC'    => [['issued_at' => '2026-09-24 10:00:00']],
            'issued_at no such day' => [['issued_at' => '2026-02-30T10:00:00Z']],
        ];
    }

    #[DataProvider('malformed')]
    public function test_row_1_a_body_not_its_shape_is_bad_adoption_and_reads_nothing(array $over): void {
        $body = array_filter(self::body($over), static fn ($v) => $v !== null);
        WpStub::$reads = [];
        $r = CadenceAdoptRequest::run($body, ['post'], self::KEY, 'v1 x y');
        $this->assertSame('bad_adoption', $r['code'] ?? null);
        $this->assertSame([], self::site_reads());
    }

    public function test_row_1_a_preview_without_a_link_is_bad_adoption(): void {
        $body = self::preview_body();
        unset($body['link']);
        $this->assertSame('bad_adoption', self::preview($body, ['post'], self::KEY, 'v1 x y')['code']);
    }

    public function test_row_2_an_exempt_key_with_no_header_is_refused_here_and_not_on_content(): void {
        CadenceAttest::exempt_key();
        foreach (['/adopt' => self::adopt(self::body(), ['post'], self::KEY, null),
                  '/adopt/preview' => self::preview(self::preview_body(), ['post'], self::KEY, null)]
                 as $route => $r) {
            $this->assertSame('attestation_unverified', $r['code'], $route);
            $this->assertSame('exempt_refused', $r['attestation_branch'], $route);
        }
        // THE TWIN: the same key, the same absent header, on `/content`.
        $this->assertSame('exempt', CadenceAttestation::verify(
            null, '/content', CadenceAttest::fields('/content', []), self::KEY)['attestation']);
    }

    /** Refused by the verifier itself, not only by the route's own check on its answer. */
    public function test_row_2_the_verifier_refuses_the_exemption_on_every_adopt_route(): void {
        CadenceAttest::exempt_key();
        foreach (['/adopt', '/adopt/preview', '/adopt/release', '/adopt/release/preview'] as $route) {
            $r = CadenceAttestation::verify(null, $route, [], self::KEY);
            $this->assertSame('exempt_refused', $r['branch'] ?? null, $route);
            $this->assertStringContainsString('this route takes no exemption', $r['reason'], $route);
        }
    }

    public function test_row_2_a_key_without_the_exemption_and_no_header_is_absent(): void {
        CadenceAttest::install(self::KEY);
        $r = self::adopt(self::body(), ['post'], self::KEY, null);
        $this->assertSame('absent', $r['attestation_branch']);
    }

    public function test_a_tampered_body_reaches_no_site_read(): void {
        $signed = self::body();
        $header = CadenceAttest::header('/adopt', CadenceAttest::fields('/adopt', $signed), self::KEY);
        WpStub::$reads = [];
        $r = self::adopt(self::body(['post_id' => 42]), ['post'], self::KEY, $header);
        $this->assertSame('mismatch', $r['attestation_branch']);
        $this->assertSame([], self::site_reads());

        $signed = self::preview_body();
        $header = CadenceAttest::header('/adopt/preview',
            CadenceAttest::fields('/adopt/preview', $signed), self::KEY);
        WpStub::$reads = [];
        $r = self::preview(self::preview_body(['link' => 'https://example.test/?p=42']),
                           ['post'], self::KEY, $header);
        $this->assertSame('mismatch', $r['attestation_branch']);
        $this->assertSame([], self::site_reads());
    }

    public function test_an_exempt_body_reaches_no_site_read(): void {
        CadenceAttest::exempt_key();
        WpStub::$reads = [];
        self::adopt(self::body(), ['post'], self::KEY, null);
        self::preview(self::preview_body(), ['post'], self::KEY, null);
        $this->assertSame([], self::site_reads());
    }

    public function test_row_3_another_site_is_refused(): void {
        $this->assertSame('adopt_wrong_site', self::adopt(self::body(['site' => 'other.test']))['code']);
        $this->assertSame('adopt_wrong_site',
            self::preview(self::preview_body(['site' => 'other.test']))['code']);
    }

    public function test_row_4_a_body_outside_the_window_is_expired(): void {
        $this->assertSame('adopt_expired', self::adopt(self::body(['issued_at' => self::now(-301)]))['code']);
        $this->assertSame('adopt_expired', self::adopt(self::body(['issued_at' => self::now(301)]))['code']);
        $this->assertSame('adopt_expired',
            self::preview(self::preview_body(['issued_at' => self::now(-301)]))['code']);
    }

    // ------------------------------------------------------------------
    // Rows 5 to 16, and their twins.
    // ------------------------------------------------------------------

    /**
     * [what to change, the key's types, the body's changes, the answer].
     * `ok` is a twin: the same fixture, one fact different, adopted.
     */
    public static function rows(): array {
        $none = static function (): void {};
        return [
            'the fixture'                 => [$none, ['post'], [], 'ok'],
            'row 3 twin: this site'       => [$none, ['post'], ['site' => 'example.test'], 'ok'],
            'row 4 twin: 299 s old'       => [$none, ['post'], ['issued_at' => self::now(-299)], 'ok'],
            'row 5: no WPML'              => [static function (): void { WpStub::$wpml_reads = false; },
                                              ['post'], [], 'wpml_unavailable'],
            'row 6: a blank key'          => [$none, null, [], 'adopt_types_unscoped'],
            'row 6: an empty scope'       => [$none, [], [], 'adopt_types_unscoped'],
            'row 7: no such post'         => [$none, ['post'], ['post_id' => 99], 'post_missing'],
            'row 8: a type not named'     => [static fn () => self::post(41, ['type' => 'page']),
                                              ['post'], [], 'adopt_post_type_out_of_scope'],
            'row 8 twin: the type named'  => [static fn () => self::post(41, ['type' => 'page']),
                                              ['post', 'page'], [], 'ok'],
            'row 8: not viewable, named'  => [static fn () => self::post(41, ['type' => 'nav_menu_item']),
                                              ['post', 'nav_menu_item'], [], 'adopt_post_type_out_of_scope'],
            'row 8: a revision, named'    => [static fn () => self::post(41, ['type' => 'revision']),
                                              ['revision'], [], 'adopt_post_type_out_of_scope'],
            'row 8 twin: viewable, named' => [static fn () => self::post(41, ['type' => 'product']),
                                              ['product'], [], 'ok'],
            'row 9: trash'                => [static fn () => self::post(41, ['status' => 'trash']),
                                              ['post'], [], 'adopt_post_unavailable'],
            'row 9: auto-draft'           => [static fn () => self::post(41, ['status' => 'auto-draft']),
                                              ['post'], [], 'adopt_post_unavailable'],
            'row 9: inherit'              => [static fn () => self::post(41, ['status' => 'inherit']),
                                              ['post'], [], 'adopt_post_unavailable'],
            'row 9: a plugin status'      => [static fn () => self::post(41, ['status' => 'wc-on-hold']),
                                              ['post'], [], 'adopt_post_unavailable'],
            'row 9: a password'           => [static fn () => self::post(41, ['password' => 'hunter2']),
                                              ['post'], [], 'adopt_post_unavailable'],
            'row 9: private, a password'  => [static fn () => self::post(41, ['status' => 'private',
                                              'password' => 'hunter2']), ['post'], [], 'adopt_post_unavailable'],
            'row 9 twin: private'         => [static fn () => self::post(41, ['status' => 'private']),
                                              ['post'], [], 'ok'],
            'row 9 twin: publish'         => [static fn () => self::post(41, ['status' => 'publish']),
                                              ['post'], [], 'ok'],
            'row 9 twin: pending'         => [static fn () => self::post(41, ['status' => 'pending']),
                                              ['post'], [], 'ok'],
            'row 9 twin: future'          => [static fn () => self::post(41, ['status' => 'future']),
                                              ['post'], [], 'ok'],
            'row 10: the front page'      => [static function (): void { WpStub::$options['page_on_front'] = '41'; },
                                              ['post'], [], 'adopt_site_page'],
            'row 10: the posts page'      => [static function (): void { WpStub::$options['page_for_posts'] = 41; },
                                              ['post'], [], 'adopt_site_page'],
            'row 10: the privacy page'    => [static function (): void {
                                                  WpStub::$options['wp_page_for_privacy_policy'] = '41'; },
                                              ['post'], [], 'adopt_site_page'],
            'row 10 twin: another page'   => [static function (): void {
                                                  WpStub::$options['page_on_front'] = '42';
                                                  WpStub::$options['page_for_posts'] = '0';
                                                  WpStub::$options['wp_page_for_privacy_policy'] = '3'; },
                                              ['post'], [], 'ok'],
            'row 11: all three rows'      => [static function (): void {
                                                  WpStub::$meta[41] = ['_cadence_external_id' => 'piece-old',
                                                      '_cadence_key' => self::KEY, '_cadence_adopted' => '{}']; },
                                              ['post'], [], 'post_already_identified'],
            'row 11: the piece id alone'  => [static function (): void {
                                                  WpStub::$meta[41]['_cadence_external_id'] = 'piece-new'; },
                                              ['post'], [], 'post_already_identified'],
            'row 11: the key alone'       => [static function (): void {
                                                  WpStub::$meta[41]['_cadence_key'] = self::KEY; },
                                              ['post'], [], 'post_already_identified'],
            'row 11: the record alone'    => [static function (): void {
                                                  WpStub::$meta[41]['_cadence_adopted'] = '{}'; },
                                              ['post'], [], 'post_already_identified'],
            'row 11: another key made it' => [static function (): void {
                                                  WpStub::$meta[41] = ['_cadence_external_id' => 'piece-new',
                                                      '_cadence_key' => 'someoneelse00key']; },
                                              ['post'], [], 'post_already_identified'],
            'row 12: this key made it, no record' => [static function (): void {
                                                  WpStub::$meta[41] = ['_cadence_external_id' => 'piece-new',
                                                      '_cadence_key' => self::KEY]; },
                                              ['post'], [], 'post_already_identified'],
            'row 12: a record of another key' => [static function (): void {
                                                  WpStub::$meta[41] = ['_cadence_external_id' => 'piece-new',
                                                      '_cadence_key' => self::KEY,
                                                      '_cadence_adopted' => '{"key":"someoneelse00key"}']; },
                                              ['post'], [], 'post_already_identified'],
            'row 13: the piece elsewhere' => [static function (): void {
                                                  self::post(77, ['status' => 'publish']);
                                                  WpStub::$meta[77]['_cadence_external_id'] = 'piece-new'; },
                                              ['post'], [], 'adopt_piece_taken'],
            'row 13: ... in the trash'    => [static function (): void {
                                                  self::post(77, ['status' => 'trash']);
                                                  WpStub::$meta[77]['_cadence_external_id'] = 'piece-new'; },
                                              ['post'], [], 'adopt_piece_taken'],
            'row 13: ... in another type' => [static function (): void {
                                                  self::post(77, ['type' => 'nav_menu_item']);
                                                  WpStub::$meta[77]['_cadence_external_id'] = 'piece-new'; },
                                              ['post'], [], 'adopt_piece_taken'],
            'row 13 twin: another piece'  => [static function (): void {
                                                  self::post(77, ['status' => 'trash']);
                                                  WpStub::$meta[77]['_cadence_external_id'] = 'piece-other'; },
                                              ['post'], [], 'ok'],
            'row 14: WPML knows nothing'  => [static fn () => self::post(41, ['wpml_knows' => false]),
                                              ['post'], [], 'group_unknown'],
            'row 14: no language'         => [static fn () => self::post(41, ['trid' => null]),
                                              ['post'], [], 'group_unknown'],
            'row 15: a draft member'      => [static fn () => self::post(42, ['trid' => 541, 'language' => 'de']),
                                              ['post'], [], 'already_grouped'],
            'row 15: a published member'  => [static fn () => self::post(42, ['trid' => 541, 'language' => 'de',
                                              'status' => 'publish']), ['post'], [], 'already_grouped'],
            'row 15: an unreadable group' => [static function (): void { WpStub::$wpml_group_unreadable = [541]; },
                                              ['post'], [], 'group_unknown'],
            'row 15 twin: another group'  => [static fn () => self::post(42, ['trid' => 542, 'language' => 'de']),
                                              ['post'], [], 'ok'],
            'row 16: another language'    => [$none, ['post'], ['language' => 'de'], 'language_disagreement'],
        ];
    }

    #[DataProvider('rows')]
    #[Group('wpml')]
    public function test_each_row_refuses_its_shape_and_its_twin_passes(callable $arrange, ?array $types,
                                                                          array $over, string $want): void {
        $arrange();
        $r = self::adopt(self::body($over), $types);
        $this->assertSame($want, ($r['ok'] ?? false) === true ? 'ok' : ($r['code'] ?? '?'),
            $r['reason'] ?? '');
        if ($want !== 'ok') {
            $this->assertSame([], WpStub::$meta_added, 'a refusal wrote');
        }
        $this->assertSame([], array_filter(array_keys(WpStub::$options),
            static fn ($k) => str_starts_with($k, 'cadence_adopt_')), 'a claim was left behind');
    }

    /**
     * THE PREVIEW RUNS THE SAME ROWS, except 16, which needs the language the
     * preview is the one to report. So its answer is the adopt's, row for row.
     */
    #[DataProvider('rows')]
    #[Group('wpml')]
    public function test_the_preview_runs_rows_5_to_15_the_same(callable $arrange, ?array $types,
                                                                 array $over, string $want): void {
        if (isset($over['post_id']) || isset($over['language'])) {
            $this->assertTrue(true);   // the preview has no post_id or language of its own
            return;
        }
        $arrange();
        $r = self::preview(self::preview_body(array_intersect_key($over, ['site' => 1, 'issued_at' => 1])),
                           $types);
        $this->assertSame($want, ($r['ok'] ?? false) === true ? 'ok' : ($r['code'] ?? '?'),
            $r['reason'] ?? '');
        $this->assertSame([], WpStub::$meta_added, 'the preview wrote');
    }

    // ------------------------------------------------------------------
    // What is written, and what is answered.
    // ------------------------------------------------------------------

    #[Group('wpml')]
    public function test_the_happy_path_writes_exactly_three_slashed_rows_and_answers_every_name(): void {
        self::post(41, ['status' => 'private', 'title' => 'Ein "Titel"', 'content' => '<p>a\\b</p>']);
        $piece = 'piece\\with"quote';
        $r = self::adopt(self::body(['piece_id' => $piece]));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');

        // THE RECORD FIRST AND THE IDENTIFIER LAST, inside one transaction.
        $this->assertSame(['_cadence_adopted', '_cadence_key', '_cadence_external_id'],
            array_map(static fn (array $w): string => $w[1], WpStub::$meta_added));
        $this->assertSame(wp_slash($piece), WpStub::$meta_added[2][2], 'written unslashed');
        $this->assertSame(['START TRANSACTION', 'COMMIT'],
            $GLOBALS['wpdb']->statements('START TRANSACTION', 'COMMIT', 'ROLLBACK'));
        $this->assertSame($piece, WpStub::$meta[41]['_cadence_external_id']);
        $this->assertSame(self::KEY, WpStub::$meta[41]['_cadence_key']);
        $record = json_decode(WpStub::$meta[41]['_cadence_adopted'], true);
        $this->assertSame(['at', 'key', 'kid', 'prior_status', 'prior_trid'], array_keys($record));
        $this->assertSame(self::KEY, $record['key']);
        $this->assertSame(CadenceAttest::KID, $record['kid']);
        $this->assertSame('private', $record['prior_status']);
        $this->assertSame(541, $record['prior_trid']);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $record['at']);

        $answer = CadenceRestRoute::respond($r);
        $this->assertSame(200, $answer['status']);
        foreach (['ok' => true, 'adopted' => true, 'piece_id' => $piece, 'post_id' => 41,
                  'language' => 'en', 'post_type' => 'post', 'title' => 'Ein "Titel"',
                  'content' => '<p>a\\b</p>', 'status' => 'private'] as $name => $value) {
            $this->assertSame($value, $answer['body'][$name] ?? null, $name);
        }
        $this->assertSame([], array_filter(array_keys(WpStub::$options),
            static fn ($k) => str_starts_with($k, 'cadence_adopt_')), 'a claim was left behind');
    }

    #[Group('wpml')]
    public function test_the_preview_writes_nothing_and_answers_every_name(): void {
        self::post(41, ['status' => 'private']);
        $r = self::preview(self::preview_body());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame([], WpStub::$meta_added);
        $this->assertArrayNotHasKey(41, WpStub::$meta);
        $answer = CadenceRestRoute::respond($r);
        $this->assertSame(200, $answer['status']);
        foreach (['ok' => true, 'adopted' => false, 'piece_id' => 'piece-new', 'post_id' => 41,
                  'language' => 'en', 'post_type' => 'post', 'title' => 'A title',
                  'status' => 'private'] as $name => $value) {
            $this->assertSame($value, $answer['body'][$name] ?? null, $name);
        }
        $this->assertArrayNotHasKey('content', $answer['body']);
    }

    /** [link, the answer]. */
    public static function links(): array {
        return [
            'the edit link'           => ['https://example.test/wp-admin/post.php?post=41&action=edit', 'ok'],
            'the ?p= link'            => ['https://example.test/?p=41', 'ok'],
            'the permalink'           => ['https://example.test/2026/09/a-title/', 'ok'],
            'an edit link elsewhere'  => ['https://other.test/wp-admin/post.php?post=41&action=edit',
                                          'adopt_link_unresolved'],
            'a ?p= link elsewhere'    => ['https://other.test/?p=41', 'adopt_link_unresolved'],
            'a path that is no post'  => ['https://example.test/no-such-post/', 'adopt_link_unresolved'],
            'an edit link with no id' => ['https://example.test/wp-admin/post.php?action=edit',
                                          'adopt_link_unresolved'],
            'a page on a post key'    => ['https://example.test/?page_id=43', 'adopt_post_type_out_of_scope'],
        ];
    }

    #[DataProvider('links')]
    #[Group('wpml')]
    public function test_the_link_resolves_to_one_post_or_none(string $link, string $want): void {
        WpStub::$permalinks['/2026/09/a-title/'] = 41;
        self::post(43, ['type' => 'page']);
        $r = self::preview(self::preview_body(['link' => $link]));
        $this->assertSame($want, ($r['ok'] ?? false) === true ? 'ok' : ($r['code'] ?? '?'));
        if ($want === 'ok') {
            $this->assertSame(41, $r['report']['post_id']);
        }
        // Every refusal still ends at a status the caller can act on.
        $this->assertNotSame(500, CadenceRestRoute::respond($r)['status']);
    }

    #[Group('wpml')]
    public static function failing_row(): array {
        return ['the record' => ['_cadence_adopted'], 'the stamp' => ['_cadence_key'],
                'the identifier' => ['_cadence_external_id']];
    }

    #[DataProvider('failing_row')]
    #[Group('wpml')]
    public function test_the_meta_is_written_all_or_nothing(string $fails): void {
        WpStub::$meta_add_fails = [$fails];
        $r = self::adopt(self::body());
        $this->assertSame('adopt_failed', $r['code']);
        $this->assertStringContainsString('nothing was left on the post', $r['reason']);
        $this->assertSame(500, CadenceRestRoute::respond($r)['status']);
        $this->assertSame([], WpStub::$meta[41] ?? [], 'a part-written adoption was left behind');
        $this->assertSame(['START TRANSACTION', 'ROLLBACK'],
            $GLOBALS['wpdb']->statements('START TRANSACTION', 'COMMIT', 'ROLLBACK'));
        $this->assertSame([], array_filter(array_keys(WpStub::$options),
            static fn ($k) => str_starts_with($k, 'cadence_adopt_')), 'a claim was left behind');
    }

    /**
     * A ROLLBACK THAT CANNOT REMOVE EVERY ROW leaves the record, never a lone
     * identifier, says so, and the post can then be released by the same key.
     */
    #[Group('wpml')]
    public function test_a_rollback_that_fails_part_way_leaves_the_record_and_says_so(): void {
        WpStub::$meta_add_fails = ['_cadence_external_id'];
        WpStub::$meta_delete_fails = ['_cadence_key'];
        $r = self::adopt(self::body());
        $this->assertSame('adopt_failed', $r['code']);
        $this->assertStringContainsString('could not be removed', $r['reason']);
        $this->assertSame(['_cadence_adopted', '_cadence_key'], array_keys(WpStub::$meta[41]));

        WpStub::$meta_add_fails = [];
        WpStub::$meta_delete_fails = [];
        $released = self::release(self::release_body());
        $this->assertTrue($released['ok'], $released['reason'] ?? '');
        $this->assertSame([], WpStub::$meta[41]);
    }

    #[Group('wpml')]
    public function test_a_site_that_will_not_open_a_transaction_writes_nothing(): void {
        $GLOBALS['wpdb']->fails_on = 'START TRANSACTION';
        $r = self::adopt(self::body());
        $this->assertSame('adopt_failed', $r['code']);
        $this->assertSame([], WpStub::$meta_added);
        $this->assertSame([], array_filter(array_keys(WpStub::$options),
            static fn ($k) => str_starts_with($k, 'cadence_adopt_')), 'a claim was left behind');
    }

    #[Group('wpml')]
    public function test_the_repeat_carries_the_source(): void {
        self::post(41, ['status' => 'pending', 'title' => 'T', 'content' => 'C']);
        $first = self::adopt(self::body());
        $this->assertTrue($first['ok'], 'the twin: a first adoption is written');
        $written = WpStub::$meta_added;

        $r = self::adopt(self::body());
        $this->assertSame('adopt_repeat', $r['code']);
        $this->assertSame($written, WpStub::$meta_added, 'the repeat wrote');
        $answer = CadenceRestRoute::respond($r);
        $this->assertSame(409, $answer['status']);
        foreach (['title' => 'T', 'content' => 'C', 'status' => 'pending', 'post_type' => 'post',
                  'language' => 'en', 'post_id' => 41, 'piece_id' => 'piece-new'] as $name => $value) {
            $this->assertSame($value, $answer['body'][$name] ?? null, $name);
        }

        // The preview says the same, and never carries the text.
        $p = self::preview(self::preview_body());
        $this->assertSame('adopt_repeat', $p['code']);
        $this->assertArrayNotHasKey('content', $p['report']);

        // Another piece id over the same post is not a repeat.
        $this->assertSame('post_already_identified',
            self::adopt(self::body(['piece_id' => 'piece-else']))['code']);
    }

    #[Group('wpml')]
    public function test_an_adopted_post_is_then_linkable_by_the_same_key(): void {
        $this->assertFalse(CadenceKey::reaches(41, self::KEY), 'the twin: not linkable before');
        $this->assertTrue(self::adopt(self::body())['ok']);
        $this->assertTrue(CadenceKey::reaches(41, self::KEY));
    }

    #[Group('wpml')]
    public function test_an_adopted_post_is_then_linkable_by_the_same_key_and_not_by_another_key(): void {
        $this->assertTrue(self::adopt(self::body())['ok']);
        $this->assertFalse(CadenceKey::reaches(41, 'someoneelse00key'));
    }

    // ------------------------------------------------------------------
    // Row 17: the race.
    // ------------------------------------------------------------------

    /** A second adopt holds the claim at the moment this one takes it. */
    public static function held(): array {
        return [
            'the post'  => ['cadence_adopt_post_41'],
            'the piece' => ['cadence_adopt_piece_' . substr(hash('sha256', 'piece-new'), 0, 32)],
        ];
    }

    #[DataProvider('held')]
    #[Group('wpml')]
    public function test_row_17_a_claim_held_by_another_adopt_is_busy(string $option): void {
        WpStub::$on_claim = static function () use ($option): void {
            WpStub::$options[$option] = (string) time();
        };
        $r = self::adopt(self::body());
        $this->assertSame('adopt_busy', $r['code']);
        $this->assertSame([], WpStub::$meta_added);
        // Only the claim this adopt took is released; the other's stays.
        $this->assertSame([$option], array_values(array_filter(array_keys(WpStub::$options),
            static fn ($k) => str_starts_with($k, 'cadence_adopt_'))));
    }

    #[DataProvider('held')]
    #[Group('wpml')]
    public function test_row_17_twin_a_claim_older_than_a_minute_is_taken_over(string $option): void {
        WpStub::$options[$option] = (string) (time() - 61);
        $this->assertTrue(self::adopt(self::body())['ok']);
    }

    /**
     * A SECOND ADOPT THAT RUNS TO COMPLETION BETWEEN THIS ONE'S CHECKS AND ITS
     * CLAIM: of the same post under another piece, and of another post under
     * the same piece. Neither holds a claim any more when this one takes its
     * own, so only the re-check under the claim can refuse.
     */
    public static function interleaved(): array {
        return [
            'the same post'  => [41, 'piece-other'],
            'the same piece' => [42, 'piece-new'],
        ];
    }

    #[DataProvider('interleaved')]
    #[Group('wpml')]
    public function test_row_17_an_adopt_interleaved_before_the_claim_is_busy(int $post, string $piece): void {
        self::post(42, ['trid' => 542]);
        WpStub::$on_claim = static function () use ($post, $piece): void {
            $other = self::adopt(self::body(['post_id' => $post, 'piece_id' => $piece]));
            if (($other['ok'] ?? false) !== true) {
                throw new RuntimeException('the interleaved adopt did not land: ' . ($other['reason'] ?? ''));
            }
        };
        $r = self::adopt(self::body());
        $this->assertSame('adopt_busy', $r['code'], $r['reason'] ?? '');
        $this->assertSame($post === 41 ? 'piece-other' : 'piece-new',
            WpStub::$meta[$post]['_cadence_external_id'], 'the interleaved adoption was disturbed');
        if ($post !== 41) {
            $this->assertArrayNotHasKey(41, WpStub::$meta, 'this adopt wrote after all');
        }
    }

    /**
     * THE PINNED RESIDUAL. A signed `/adopt` body replayed inside its window,
     * on the same site, after a release let the post go, adopts it again. The
     * window is 300 s either side of the site's clock; past it the same bytes
     * are `adopt_expired`.
     */
    #[Group('wpml')]
    public function test_a_replay_inside_the_window_after_release_re_adopts(): void {
        $body = self::body();
        $header = CadenceAttest::header('/adopt', CadenceAttest::fields('/adopt', $body), self::KEY);
        $this->assertTrue(self::adopt($body, ['post'], self::KEY, $header)['ok']);
        $this->assertTrue(self::release(self::release_body())['ok'], 'the release did not land');
        $this->assertSame(300, CadenceAdoptRequest::WINDOW);
        $this->assertTrue(self::adopt($body, ['post'], self::KEY, $header)['ok'],
            'the residual closed: update this test and the design together');
    }

    // ------------------------------------------------------------------
    // Release: the eleven rows, their twins, and what is removed.
    // ------------------------------------------------------------------

    private static function release_body(array $over = []): array {
        return array_merge(['piece_id' => 'piece-new', 'post_id' => 41,
                            'site' => 'example.test', 'issued_at' => self::now()], $over);
    }

    private static function release_preview_body(array $over = []): array {
        return array_merge(['link' => 'https://example.test/?p=41',
                            'site' => 'example.test', 'issued_at' => self::now()], $over);
    }

    private static function release(array $body, ?string $key = self::KEY, $attestation = self::SIGN): array {
        return CadenceAdoptRequest::release($body, $key, $attestation === self::SIGN
            ? CadenceAttest::header('/adopt/release', CadenceAttest::fields('/adopt/release', $body), $key)
            : $attestation);
    }

    private static function release_preview(array $body, ?string $key = self::KEY,
                                            $attestation = self::SIGN): array {
        return CadenceAdoptRequest::release_preview($body, $key, $attestation === self::SIGN
            ? CadenceAttest::header('/adopt/release/preview',
                                    CadenceAttest::fields('/adopt/release/preview', $body), $key)
            : $attestation);
    }

    private static function adopted(): void {
        $r = self::adopt(self::body());
        if (($r['ok'] ?? false) !== true) {
            throw new RuntimeException('the fixture adoption did not land: ' . ($r['reason'] ?? ''));
        }
        WpStub::$reads = [];
    }

    private static function answer(array $r): string {
        return ($r['ok'] ?? false) === true ? 'ok' : ($r['code'] ?? '?');
    }

    public function test_release_has_eleven_codes_in_the_design_order(): void {
        $this->assertSame(['bad_release', 'attestation_unverified', 'adopt_wrong_site', 'adopt_expired',
                           'wpml_unavailable', 'post_missing', 'not_adopted', 'post_already_identified',
                           'group_unknown', 'already_grouped', 'adopt_failed'],
                          CadenceAdoptRequest::RELEASE_REFUSAL_CODES);
    }

    public static function malformed_release(): array {
        return [
            'post_id as a string' => [['post_id' => '41']],
            'post_id zero'        => [['post_id' => 0]],
            'no piece_id'         => [['piece_id' => null]],
            'blank piece_id'      => [['piece_id' => ' ']],
            'site not a string'   => [['site' => 7]],
            'issued_at not UTC'   => [['issued_at' => '2026-09-24 10:00:00']],
        ];
    }

    #[DataProvider('malformed_release')]
    public function test_release_row_1_a_body_not_its_shape_is_bad_release_and_reads_nothing(array $over): void {
        $body = array_filter(self::release_body($over), static fn ($v) => $v !== null);
        WpStub::$reads = [];
        $r = CadenceAdoptRequest::release($body, self::KEY, 'v1 x y');
        $this->assertSame('bad_release', $r['code'] ?? null);
        $this->assertSame(400, CadenceRestRoute::respond($r)['status']);
        $this->assertSame([], self::site_reads());
    }

    public function test_release_preview_without_a_link_is_bad_release(): void {
        $body = self::release_preview_body();
        unset($body['link']);
        $this->assertSame('bad_release', self::release_preview($body, self::KEY, 'v1 x y')['code']);
    }

    public function test_release_row_2_an_exempt_key_is_refused_and_reads_nothing(): void {
        CadenceAttest::exempt_key();
        WpStub::$reads = [];
        foreach (['/adopt/release' => self::release(self::release_body(), self::KEY, null),
                  '/adopt/release/preview' => self::release_preview(self::release_preview_body(), self::KEY, null)]
                 as $route => $r) {
            $this->assertSame('attestation_unverified', $r['code'], $route);
            $this->assertSame('exempt_refused', $r['attestation_branch'], $route);
        }
        $this->assertSame([], self::site_reads());
    }

    public function test_release_row_2_a_tampered_body_reaches_no_site_read(): void {
        $header = CadenceAttest::header('/adopt/release',
            CadenceAttest::fields('/adopt/release', self::release_body()), self::KEY);
        WpStub::$reads = [];
        $r = self::release(self::release_body(['post_id' => 42]), self::KEY, $header);
        $this->assertSame('mismatch', $r['attestation_branch']);
        $this->assertSame([], self::site_reads());

        $header = CadenceAttest::header('/adopt/release/preview',
            CadenceAttest::fields('/adopt/release/preview', self::release_preview_body()), self::KEY);
        WpStub::$reads = [];
        $r = self::release_preview(self::release_preview_body(['link' => 'https://example.test/?p=42']),
                                   self::KEY, $header);
        $this->assertSame('mismatch', $r['attestation_branch']);
        $this->assertSame([], self::site_reads());
    }

    /**
     * [what to change after the fixture is adopted, the body's changes, the
     * answer]. `ok` is a twin: one fact different, released.
     */
    public static function release_rows(): array {
        $none = static function (): void {};
        $other = 'someoneelse00key';
        return [
            'the fixture'                   => [$none, [], 'ok'],
            'row 3: another site'           => [$none, ['site' => 'other.test'], 'adopt_wrong_site'],
            'row 3 twin: this site'         => [$none, ['site' => 'example.test'], 'ok'],
            'row 4: 301 s old'              => [$none, ['issued_at' => self::now(-301)], 'adopt_expired'],
            // A provider runs before its tests, so an instant only 1 s past the
            // window ahead drifts inside it by the time a slow lane gets here.
            // The exact 301 s edge is asserted in-method, above.
            'row 4: 360 s ahead'            => [$none, ['issued_at' => self::now(360)], 'adopt_expired'],
            'row 4 twin: 299 s old'         => [$none, ['issued_at' => self::now(-299)], 'ok'],
            'row 5: no WPML'                => [static function (): void { WpStub::$wpml_reads = false; },
                                                [], 'wpml_unavailable'],
            'row 6: no such post'           => [$none, ['post_id' => 99], 'post_missing'],
            'row 7: no record'              => [static function (): void {
                                                    unset(WpStub::$meta[41]['_cadence_adopted']); },
                                                [], 'not_adopted'],
            'row 7: no rows at all'         => [static function (): void { unset(WpStub::$meta[41]); },
                                                [], 'not_adopted'],
            'row 8: the stamp names another key' => [static function () use ($other): void {
                                                    WpStub::$meta[41]['_cadence_key'] = $other; },
                                                [], 'post_already_identified'],
            // A post another key adopted answers as one never adopted.
            'row 7: the record names another key' => [static function () use ($other): void {
                                                    WpStub::$meta[41]['_cadence_adopted'] =
                                                        json_encode(['key' => $other]); },
                                                [], 'not_adopted'],
            'row 7: a record naming no key' => [static function (): void {
                                                    WpStub::$meta[41]['_cadence_adopted'] = '{}'; },
                                                [], 'not_adopted'],
            'row 8: another piece named'    => [$none, ['piece_id' => 'piece-else'], 'post_already_identified'],
            'row 8 twin: a part-released post' => [static function (): void {
                                                    unset(WpStub::$meta[41]['_cadence_external_id']); },
                                                [], 'ok'],
            'row 9: WPML knows nothing'     => [static fn () => self::post(41, ['wpml_knows' => false]),
                                                [], 'group_unknown'],
            'row 9: no language'            => [static fn () => self::post(41, ['trid' => null]),
                                                [], 'group_unknown'],
            'row 9: an unreadable group'    => [static function (): void { WpStub::$wpml_group_unreadable = [541]; },
                                                [], 'group_unknown'],
            'row 10: a draft member'        => [static fn () => self::post(42, ['trid' => 541, 'language' => 'de']),
                                                [], 'already_grouped'],
            'row 10: a published member'    => [static fn () => self::post(42, ['trid' => 541, 'language' => 'de',
                                                'status' => 'publish']), [], 'already_grouped'],
            'row 10 twin: another group'    => [static fn () => self::post(42, ['trid' => 542, 'language' => 'de']),
                                                [], 'ok'],
            'row 11: the second delete fails' => [static function (): void {
                                                    WpStub::$meta_delete_fails = ['_cadence_key']; },
                                                [], 'adopt_failed'],
        ];
    }

    #[DataProvider('release_rows')]
    #[Group('wpml')]
    public function test_each_release_row_refuses_its_shape_and_its_twin_passes(callable $arrange, array $over,
                                                                              string $want): void {
        self::adopted();
        $arrange();
        $before = WpStub::$meta[41] ?? [];
        $r = self::release(self::release_body($over));
        $this->assertSame($want, self::answer($r), $r['reason'] ?? '');
        if ($want !== 'ok') {
            $this->assertNotSame(200, CadenceRestRoute::respond($r)['status']);
        }
        if ($want !== 'ok' && $want !== 'adopt_failed') {
            $this->assertSame($before, WpStub::$meta[41] ?? [], 'a refusal removed something');
        }
    }

    /** The preview runs the same rows but the identifier, and writes nothing. */
    #[DataProvider('release_rows')]
    #[Group('wpml')]
    public function test_the_release_preview_runs_the_same_rows(callable $arrange, array $over, string $want): void {
        if (isset($over['post_id']) || isset($over['piece_id']) || $want === 'adopt_failed') {
            $this->assertTrue(true);   // the preview names no post_id or piece_id, and deletes nothing
            return;
        }
        self::adopted();
        $arrange();
        $before = WpStub::$meta[41] ?? [];
        $r = self::release_preview(self::release_preview_body($over));
        $this->assertSame($want, self::answer($r), $r['reason'] ?? '');
        $this->assertSame($before, WpStub::$meta[41] ?? [], 'the preview removed something');
    }

    #[Group('wpml')]
    public function test_the_release_preview_answers_every_name(): void {
        self::post(41, ['status' => 'private', 'title' => 'T']);
        self::adopted();
        $answer = CadenceRestRoute::respond(self::release_preview(self::release_preview_body()));
        $this->assertSame(200, $answer['status']);
        foreach (['ok' => true, 'post_id' => 41, 'post_type' => 'post', 'title' => 'T',
                  'status' => 'private', 'language' => 'en'] as $name => $value) {
            $this->assertSame($value, $answer['body'][$name] ?? null, $name);
        }
        $this->assertArrayNotHasKey('content', $answer['body']);
    }

    #[Group('wpml')]
    public function test_the_release_preview_resolves_the_link_or_refuses(): void {
        self::adopted();
        $r = self::release_preview(self::release_preview_body(['link' => 'https://other.test/?p=41']));
        $this->assertSame('adopt_link_unresolved', $r['code']);
        $r = self::release_preview(self::release_preview_body(
            ['link' => 'https://example.test/wp-admin/post.php?post=41&action=edit']));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
    }

    /**
     * THE HAPPY PATH removes exactly the three rows, in order, and nothing
     * else: the post's other meta stays, and WPML is not written to, so the
     * language details are what the double recorded before.
     */
    #[Group('wpml')]
    public function test_the_release_removes_exactly_the_three_rows_in_order(): void {
        self::adopted();
        WpStub::$meta[41]['_edit_lock'] = '1:7';
        $posts = WpStub::$posts;
        $writes = WpStub::$writes;
        WpStub::$meta_deleted = [];
        $r = self::release(self::release_body());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame(['_cadence_external_id', '_cadence_key', '_cadence_adopted'],
            array_map(static fn (array $d): string => $d[1], WpStub::$meta_deleted));
        $this->assertSame(['_edit_lock' => '1:7'], WpStub::$meta[41]);
        $this->assertSame($posts, WpStub::$posts, 'the post or its language details changed');
        $this->assertSame($writes, WpStub::$writes, 'WPML was written to');
        $answer = CadenceRestRoute::respond($r);
        $this->assertSame(200, $answer['status']);
        foreach (['ok' => true, 'released' => true, 'piece_id' => 'piece-new', 'post_id' => 41]
                 as $name => $value) {
            $this->assertSame($value, $answer['body'][$name] ?? null, $name);
        }
        $this->assertFalse(CadenceKey::reaches(41, self::KEY), 'a released post is still linkable');
    }

    /** A DELETE FAILING ON THE SECOND ROW LEAVES THE RECORD, and a retry is still a release. */
    #[Group('wpml')]
    public function test_a_part_way_failure_leaves_the_record_and_a_retry_releases(): void {
        self::adopted();
        WpStub::$meta_delete_fails = ['_cadence_key'];
        $r = self::release(self::release_body());
        $this->assertSame('adopt_failed', $r['code']);
        $this->assertSame(500, CadenceRestRoute::respond($r)['status']);
        $this->assertArrayHasKey('_cadence_adopted', WpStub::$meta[41]);
        $this->assertArrayNotHasKey('_cadence_external_id', WpStub::$meta[41]);

        WpStub::$meta_delete_fails = [];
        $again = self::release(self::release_body());
        $this->assertTrue($again['ok'], $again['reason'] ?? '');
        $this->assertSame([], WpStub::$meta[41]);
    }

    /** The same when the record itself will not go: the other two rows are gone, and a retry releases. */
    #[Group('wpml')]
    public function test_a_failure_on_the_record_leaves_it_and_a_retry_releases(): void {
        self::adopted();
        WpStub::$meta_delete_fails = ['_cadence_adopted'];
        $this->assertSame('adopt_failed', self::release(self::release_body())['code']);
        $this->assertSame(['_cadence_adopted'], array_keys(WpStub::$meta[41]));

        WpStub::$meta_delete_fails = [];
        $again = self::release(self::release_body());
        $this->assertTrue($again['ok'], $again['reason'] ?? '');
        $this->assertSame([], WpStub::$meta[41]);
    }

    /** A post this plugin created is never un-stamped, and stays linkable after the refusal. */
    #[Group('wpml')]
    public function test_release_refuses_a_post_this_plugin_created_and_it_stays_linkable(): void {
        WpStub::$meta[41] = ['_cadence_external_id' => 'piece-new', '_cadence_key' => self::KEY];
        $this->assertTrue(CadenceKey::reaches(41, self::KEY), 'the twin: linkable before');
        $r = self::release(self::release_body());
        $this->assertSame('not_adopted', $r['code']);
        $this->assertSame(409, CadenceRestRoute::respond($r)['status']);
        $this->assertSame(['_cadence_external_id' => 'piece-new', '_cadence_key' => self::KEY], WpStub::$meta[41]);
        $this->assertTrue(CadenceKey::reaches(41, self::KEY));
        $this->assertSame('not_adopted', CadenceAdoptRequest::release_by_admin(41)['code']);
    }

    /** Release then adopt again: the record is gone, so is the row-12 repeat. */
    #[Group('wpml')]
    public function test_release_then_adopt_again_succeeds(): void {
        self::adopted();
        $this->assertTrue(self::release(self::release_body())['ok']);
        $r = self::adopt(self::body(['issued_at' => self::now(-1)]));
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertTrue($r['report']['adopted']);
    }

    /**
     * THE ADMINISTRATOR'S RELEASE takes no key and no signature, and so runs
     * no key or identifier check: it releases a post another key adopted,
     * which no key can over the wire. Every other row still refuses.
     */
    #[Group('wpml')]
    public function test_the_admin_release_runs_the_rows_but_the_key(): void {
        self::adopted();
        WpStub::$meta[41]['_cadence_key'] = 'someoneelse00key';
        $this->assertSame('post_already_identified', self::release(self::release_body())['code']);
        self::post(42, ['trid' => 541, 'language' => 'de']);
        $this->assertSame('already_grouped', CadenceAdoptRequest::release_by_admin(41)['code']);
        unset(WpStub::$posts[42]);
        $this->assertSame('post_missing', CadenceAdoptRequest::release_by_admin(99)['code']);
        WpStub::$wpml_reads = false;
        $this->assertSame('wpml_unavailable', CadenceAdoptRequest::release_by_admin(41)['code']);
        WpStub::$wpml_reads = true;
        $r = CadenceAdoptRequest::release_by_admin(41);
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame([], WpStub::$meta[41]);
    }

    // ------------------------------------------------------------------
    // Under the claims: what another request wrote is read, not remembered.
    // ------------------------------------------------------------------

    /**
     * ANOTHER REQUEST STAMPS THIS POST between the checks and the claim. The
     * meta this request read during the checks is cached, so only a re-read
     * past that cache sees the stamp.
     */
    #[Group('wpml')]
    public function test_the_re_check_reads_past_a_cached_copy_of_the_post_meta(): void {
        WpStub::$meta_cache_on = true;
        WpStub::$on_claim = static function (): void {
            WpStub::$meta[41]['_cadence_external_id'] = 'piece-other';
        };
        $r = self::adopt(self::body());
        $this->assertSame('adopt_busy', $r['code'], $r['reason'] ?? '');
        $this->assertSame([], WpStub::$meta_added);
        $this->assertSame(['_cadence_external_id' => 'piece-other'], WpStub::$meta[41]);
    }

    /**
     * ANOTHER REQUEST ADOPTS THIS PIECE ONTO ANOTHER POST between the checks
     * and the claim. A lookup answered from this request's query cache would
     * still say the piece is nowhere else.
     */
    #[Group('wpml')]
    public function test_the_re_check_reads_past_a_cached_piece_lookup(): void {
        WpStub::$meta_cache_on = true;
        WpStub::$query_cache_on = true;
        self::post(42, ['trid' => 542]);
        WpStub::$on_claim = static function (): void {
            WpStub::$meta[42]['_cadence_external_id'] = 'piece-new';
        };
        $r = self::adopt(self::body());
        $this->assertSame('adopt_busy', $r['code'], $r['reason'] ?? '');
        $this->assertArrayNotHasKey(41, WpStub::$meta, 'this adopt wrote after all');
    }

    /** The twin: with both caches on and nobody interleaved, the adopt lands. */
    #[Group('wpml')]
    public function test_twin_both_caches_on_and_nothing_interleaved_adopts(): void {
        WpStub::$meta_cache_on = true;
        WpStub::$query_cache_on = true;
        $r = self::adopt(self::body());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
    }

    /** [what changes on the post after the checks, the answer]. */
    public static function changed_under_the_claim(): array {
        return [
            'trashed'            => [['post_status' => 'trash'], 'adopt_post_unavailable'],
            'given a password'   => [['post_password' => 'secret'], 'adopt_post_unavailable'],
            'retyped'            => [['post_type' => 'product'], 'adopt_post_type_out_of_scope'],
            'twin: retitled'     => [['post_title' => 'Another title'], 'ok'],
        ];
    }

    #[DataProvider('changed_under_the_claim')]
    #[Group('wpml')]
    public function test_the_post_is_read_again_under_the_claim(array $change, string $want): void {
        WpStub::$on_claim = static function () use ($change): void {
            WpStub::$posts[41] = array_merge(WpStub::$posts[41], $change);
        };
        $r = self::adopt(self::body());
        if ($want === 'ok') {
            $this->assertTrue($r['ok'], $r['reason'] ?? '');
            $this->assertSame('Another title', $r['report']['title']);
            return;
        }
        $this->assertSame($want, $r['code'] ?? null, $r['reason'] ?? '');
        $this->assertSame([], WpStub::$meta_added);
        $this->assertSame([], array_filter(array_keys(WpStub::$options),
            static fn ($k) => str_starts_with($k, 'cadence_adopt_')), 'a claim was left behind');
    }

    /**
     * TWO REQUESTS FIND ONE STALE CLAIM. The other takes it over first, between
     * this request's read of the stale value and its delete; deleting by name
     * alone would remove the other's fresh claim and let both proceed.
     */
    #[Group('wpml')]
    public function test_a_stale_claim_taken_over_by_another_request_first_is_busy(): void {
        $option = 'cadence_adopt_post_41';
        WpStub::$options[$option] = (string) (time() - 61);
        WpStub::$on_option_read[$option] = static function () use ($option): void {
            WpStub::$options[$option] = (string) time();
        };
        $r = self::adopt(self::body());
        $this->assertSame('adopt_busy', $r['code'], $r['reason'] ?? '');
        $this->assertSame([], WpStub::$meta_added);
        $this->assertArrayHasKey($option, WpStub::$options, 'the other request\'s claim was deleted');
    }

    public function test_a_link_with_a_fragment_resolves_to_nothing(): void {
        foreach (['https://example.test/?p=41#x', 'https://example.test/wp-admin/post.php?post=41#top'] as $link) {
            $this->assertSame(0, CadenceAdoptRequest::resolve_link($link), $link);
        }
        // THE TWIN: the same link without its fragment.
        $this->assertSame(41, CadenceAdoptRequest::resolve_link('https://example.test/?p=41'));
    }

    /**
     * RELEASE IS NO ORACLE. A post another key adopted and a post nobody
     * adopted answer one code and one reason, so a key learns nothing about
     * posts it does not own.
     */
    #[Group('wpml')]
    public function test_release_answers_alike_for_another_keys_post_and_an_unadopted_one(): void {
        self::adopted();
        WpStub::$meta[41]['_cadence_adopted'] = json_encode(['key' => 'someoneelse00key']);
        $theirs = self::release(self::release_body());
        unset(WpStub::$meta[41]);
        $none = self::release(self::release_body());
        $this->assertSame('not_adopted', $theirs['code']);
        $this->assertSame($none, $theirs);
    }
}
