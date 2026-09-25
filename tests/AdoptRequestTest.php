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

        $this->assertSame(['_cadence_external_id', '_cadence_key', '_cadence_adopted'],
            array_map(static fn (array $w): string => $w[1], WpStub::$meta_added));
        $this->assertSame(wp_slash($piece), WpStub::$meta_added[0][2], 'written unslashed');
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
    public function test_the_meta_is_written_all_or_nothing(): void {
        WpStub::$meta_add_fails = ['_cadence_adopted'];
        $r = self::adopt(self::body());
        $this->assertSame('adopt_failed', $r['code']);
        $this->assertSame(500, CadenceRestRoute::respond($r)['status']);
        $this->assertSame([], WpStub::$meta[41] ?? [], 'a part-written adoption was left behind');
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
     * on the same site, after the post was let go (the three rows removed, as a
     * release removes them), adopts it again. The window is 300 s either side
     * of the site's clock; past it the same bytes are `adopt_expired`.
     */
    #[Group('wpml')]
    public function test_a_replay_inside_the_window_after_a_release_re_adopts(): void {
        $body = self::body();
        $header = CadenceAttest::header('/adopt', CadenceAttest::fields('/adopt', $body), self::KEY);
        $this->assertTrue(self::adopt($body, ['post'], self::KEY, $header)['ok']);
        unset(WpStub::$meta[41]);
        $this->assertTrue(self::adopt($body, ['post'], self::KEY, $header)['ok'],
            'the residual closed: update this test and the design together');
    }
}
