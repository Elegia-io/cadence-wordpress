<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * WHAT THE PLUGIN FILE REGISTERS, not what it looks like it registers.
 *
 * The classes either side of this are covered by their own tests and could
 * both be perfect while the route is wired to the wrong callback, hooked to
 * nothing, or -- the one that matters -- registered with a permission callback
 * that returns true. So this loads the real entry file and interrogates the
 * registration it produced.
 */
final class PluginTest extends TestCase {

    private array $route;
    private array $routes = [];

    protected function setUp(): void {
        WpStub::reset();
        // The file cannot be loaded fresh per test -- PHP will not re-declare
        // what it already has -- so it is included once and the hook it
        // registered is re-fired here. $actions is deliberately NOT cleared:
        // clearing it leaves nothing to fire from the second test onwards, and
        // every assertion below would then be made about an empty list.
        require_once dirname(__DIR__) . '/cadence-connector.php';
        WpHooks::$routes = [];
        WpHooks::fire('rest_api_init');

        foreach (WpHooks::$routes as [$namespace, $path, $args]) {
            $this->assertSame('cadence/v1', $namespace, $path);
            $this->routes[$path] = $args;
        }
        // Named, not indexed: a test reading `$routes[0]` starts asserting
        // about a different endpoint the day one is registered above it, and
        // still passes while doing so.
        $this->assertSame(['/translation-group', '/content', '/content/replace'],
            array_keys($this->routes));
        $this->route = ['cadence/v1', '/translation-group', $this->routes['/translation-group']];
    }

    public function test_registers_its_routes_under_its_own_namespace_as_post(): void {
        $this->assertSame('POST', $this->routes['/translation-group']['methods']);
        $this->assertSame('POST', $this->routes['/content']['methods']);
        $this->assertSame('POST', $this->routes['/content/replace']['methods']);
    }

    /**
     * REPLACING IS ITS OWN ROUTE, AND ITS OWN CAPABILITY. The content route is
     * authorised on `content.publish`; that grants nothing here, because a key
     * that may create must not silently also be able to overwrite -- creating
     * writes a post nobody has seen, and replacing destroys text a human may
     * have hand-edited since. A pipeline that needs both holds both.
     */
    public function test_the_replace_route_takes_only_a_replace_capable_key(): void {
        $permit = $this->routes['/content/replace']['permission_callback'];
        $this->assertIsCallable($permit);
        $this->assertNotSame('__return_true', $permit);
        $this->assertFalse($permit(new WP_REST_Request(null)));

        $publisher = CadenceKey::issue('tenant-a', ['content.publish'], 7);
        $this->assertFalse($permit(new WP_REST_Request(['post_id' => 41], $this->key($publisher))),
            'a key that may only create was allowed to rewrite');

        $replacer = CadenceKey::issue('tenant-b', ['content.replace'], 7);
        $this->assertTrue($permit(new WP_REST_Request(['post_id' => 41], $this->key($replacer))));
    }

    /**
     * THE ROUTE REWRITES ONCE. Sent a second time -- the shape a lost response
     * produces -- it is refused with the status that tells the caller to
     * re-read the site rather than to keep sending.
     */
    #[Group('wpml')]
    public function test_the_replace_route_rewrites_once_and_refuses_the_replay(): void {
        $made = ($this->routes['/content']['callback'])(new WP_REST_Request([
            'piece_id' => 'p-1', 'post_type' => 'post', 'status' => 'draft',
            'title' => 'T', 'content' => 'C', 'language' => 'en',
            'declared' => ['multilingual' => true, 'languages' => ['en']]]));
        $body = ['piece_id' => 'p-1', 'post_id' => $made->get_data()['post_id'],
                 'revision' => $made->get_data()['revision'],
                 'title' => 'T2', 'content' => 'C2'];

        $call = $this->routes['/content/replace']['callback'];
        $done = $call(new WP_REST_Request($body));
        $this->assertSame(200, $done->get_status());
        $this->assertFalse($done->get_data()['created']);
        $this->assertNotSame($body['revision'], $done->get_data()['revision']);
        $this->assertCount(1, WpStub::$updated);

        $replay = $call(new WP_REST_Request($body));
        $this->assertSame(409, $replay->get_status());
        $this->assertSame('revision_mismatch', $replay->get_data()['code']);
        $this->assertCount(1, WpStub::$updated, 'the replay rewrote the post again');
    }

    public function test_the_replace_route_refuses_a_body_it_cannot_read(): void {
        $r = ($this->routes['/content/replace']['callback'])(new WP_REST_Request(null));
        $this->assertSame(400, $r->get_status());
        $this->assertSame('bad_replacement', $r->get_data()['code']);
        $this->assertSame([], WpStub::$updated);
    }

    /**
     * THE CONTENT ROUTE IS AUTHORISED BY A KEY SCOPED TO PUBLISHING, and the
     * linker's key does not open it.
     *
     * The two capabilities are separate for the reason the whole scheme exists:
     * a credential is worth what its widest grant is worth, and a key held by a
     * pipeline that only ever publishes should not also be able to rearrange
     * the site's translation groups.
     */
    public function test_the_content_route_takes_only_a_publishing_key(): void {
        $permit = $this->routes['/content']['permission_callback'];
        $this->assertIsCallable($permit);
        $body = ['piece_id' => 'x', 'post_type' => 'page', 'status' => 'draft'];

        $this->assertFalse($permit(new WP_REST_Request($body, WP_REST_Request::NO_KEY)),
            'no key at all was let through');

        $linker = CadenceKey::issue('tenant-a', ['translation.link'], 7);
        $this->assertFalse($permit(new WP_REST_Request($body, $this->key($linker))),
            'a key for linking translations was allowed to publish content');

        $publisher = CadenceKey::issue('tenant-b', ['content.publish'], 7);
        $this->assertTrue($permit(new WP_REST_Request($body, $this->key($publisher))));
    }

    /**
     * THE ROUTE PASSES THE PRESENTING KEY'S BYLINE TO THE INSERT.
     *
     * Asserted at the route: the handler can fill `post_author` perfectly from
     * an argument the route never passes it, and every handler test still
     * passes while every post the connector creates has no author.
     */
    #[Group('wpml')]
    public function test_the_content_route_gives_the_post_the_keys_byline(): void {
        WpStub::$users = [7 => 'A Real Person', 9 => 'Another Person'];
        $key = CadenceKey::issue('tenant-b', ['content.publish'], 9);
        $this->assertIsArray($key, is_string($key) ? $key : '');
        $body = ['piece_id' => 'p-3', 'post_type' => 'post', 'status' => 'draft',
                 'title' => 'T', 'content' => 'C', 'language' => 'en',
                 'declared' => ['multilingual' => true, 'languages' => ['en']]];

        $made = ($this->routes['/content']['callback'])(new WP_REST_Request($body, $this->key($key)));

        $this->assertSame(201, $made->get_status());
        $this->assertSame(9, WpStub::$inserted[0]['post_author'] ?? null);
    }

    /** The header, spelled as it travels on the wire. */
    private function key(array $issued): array {
        // WITH THE UNSIGNED-PUBLISH EXEMPTION, because this file is about the
        // routing -- which callback, which capability, which status -- and a
        // key freshly issued by `CadenceKey::issue` carries no attestation
        // public key, so every request through it would be refused
        // `no_public_key` before reaching the thing under test. The tests that
        // are about the signature are in `AttestationTest`, and the ones here
        // that send a header pass it explicitly beside this.
        CadenceKey::set_unsigned_ok($issued['id'], true, 1);
        return [strtolower(CadenceKey::HEADER) => $issued['secret']];
    }

    #[Group('wpml')]
    public function test_the_content_route_creates_once_and_answers_201_then_200(): void {
        $call = $this->routes['/content']['callback'];
        $body = ['piece_id' => 'p-1', 'post_type' => 'post', 'status' => 'draft',
                 'title' => 'T', 'content' => 'C', 'language' => 'en',
                 'declared' => ['multilingual' => true, 'languages' => ['en']]];

        $made = $call(new WP_REST_Request($body));
        $this->assertSame(201, $made->get_status());
        $this->assertTrue($made->get_data()['created']);

        $again = $call(new WP_REST_Request($body));
        $this->assertSame(200, $again->get_status());
        $this->assertFalse($again->get_data()['created']);
        $this->assertSame($made->get_data()['post_id'], $again->get_data()['post_id']);
        $this->assertCount(1, WpStub::$inserted);
    }

    /**
     * THE SIX FIELDS REACH THE WIRE.
     *
     * Asserted at the ROUTE, not at the handler: the handler can report
     * perfectly into a response body that drops it, and every test of the
     * handler still passes. What the caller's verifier parses is what comes
     * back from here.
     */
    #[Group('wpml')]
    public function test_the_content_routes_answer_carries_the_report(): void {
        WpStub::$active_languages = ['en' => [], 'de' => []];
        $response = ($this->routes['/content']['callback'])(new WP_REST_Request([
            'piece_id' => 'p-2', 'post_type' => 'post', 'status' => 'draft',
            'title' => 'T', 'content' => 'C', 'language' => 'en',
            'declared' => ['multilingual' => true, 'languages' => ['en', 'it']],
        ]));
        $body = $response->get_data();
        $this->assertSame(201, $response->get_status());
        foreach (['piece_id', 'post_id', 'placed', 'linked', 'refused', 'observed_unsupported'] as $field) {
            $this->assertArrayHasKey($field, $body, $field);
        }
        $this->assertSame('p-2', $body['piece_id']);
        $this->assertIsInt($body['post_id']);
        $this->assertSame(['en'], $body['placed']);
        $this->assertSame(['it'], $body['observed_unsupported']);
        // `ok` and `created` kept: the refusal body shares `ok`, and `created`
        // is what selects 201 from 200.
        $this->assertTrue($body['ok']);
        $this->assertTrue($body['created']);
    }

    public function test_the_content_route_refuses_a_body_it_cannot_read(): void {
        $r = ($this->routes['/content']['callback'])(new WP_REST_Request(null));
        $this->assertSame(400, $r->get_status());
        $this->assertSame('bad_request', $r->get_data()['code']);
        $this->assertSame([], WpStub::$inserted);
    }

    /**
     * THE ROUTE HAS A PERMISSION CALLBACK AND IT IS NOT `__return_true`.
     * Asserted by calling it, not by reading its name: a callback that returns
     * true is what an omitted one becomes, and it is the difference between an
     * authenticated endpoint and a public one that edits the site.
     */
    public function test_the_permission_callback_refuses_an_anonymous_empty_request(): void {
        $permit = $this->route[2]['permission_callback'];
        $this->assertIsCallable($permit);
        $this->assertNotSame('__return_true', $permit);
        $this->assertFalse($permit(new WP_REST_Request(null)));
        $this->assertFalse($permit(new WP_REST_Request([])));
        $this->assertFalse($permit(new WP_REST_Request('not json at all')));
    }

    /**
     * AND A WORDPRESS ADMINISTRATOR IS NOT A WAY IN.
     *
     * This is the change, not a detail of it: the route no longer asks
     * `current_user_can`, so a site owner logged in with every capability
     * WordPress has still cannot reach it, and neither can anything holding a
     * stolen application password. There is one way in, and it is a key issued
     * for this capability.
     */
    public function test_a_wordpress_user_with_every_capability_is_still_refused(): void {
        WpStub::$capabilities = ['edit_post' => [1, 2], 'create_posts' => [null],
                                 'publish_posts' => [null], 'manage_options' => [null]];
        $body = ['source' => ['post_id' => 1], 'translations' => [['post_id' => 2]]];
        $this->assertFalse(($this->route[2]['permission_callback'])(
            new WP_REST_Request($body, WP_REST_Request::NO_KEY)));
        $this->assertFalse(($this->routes['/content']['permission_callback'])(new WP_REST_Request(
            ['piece_id' => 'x', 'post_type' => 'post', 'status' => 'publish'],
            WP_REST_Request::NO_KEY)));
        $this->assertFalse(($this->routes['/content/replace']['permission_callback'])(new WP_REST_Request(
            ['post_id' => 1], WP_REST_Request::NO_KEY)),
            'a WordPress user with every capability rewrote a post through a route that asks no user at all');
    }

    /**
     * AND IT PERMITS A KEY ISSUED FOR LINKING -- otherwise the assertions above
     * hold for a callback wired to nothing at all.
     *
     * The body shape is still checked here: a key authorises the capability,
     * not a body, and one that cannot be read names no posts to link.
     */
    public function test_the_permission_callback_permits_a_linking_key(): void {
        $permit = $this->route[2]['permission_callback'];
        $header = $this->key(CadenceKey::issue('tenant-a', ['translation.link'], 7));
        $this->assertTrue($permit(new WP_REST_Request([
            'source' => ['post_id' => 1], 'translations' => [['post_id' => 2]],
        ], $header)));
        $this->assertFalse($permit(new WP_REST_Request([
            'source' => ['post_id' => 1], 'translations' => [['post_id' => '2']],
        ], $header)), 'a body naming no readable post id was authorised');
        $this->assertFalse($permit(new WP_REST_Request([], $header)));
    }

    #[Group('wpml')]
    public function test_a_refused_plan_comes_back_as_the_refusals_own_status(): void {
        WpStub::add_post(1, 'page', 'en', 9);
        WpStub::add_post(2, 'page', 'de', 9);
        WpStub::cadence_published(1);
        WpStub::cadence_published(2);
        $response = ($this->route[2]['callback'])(new WP_REST_Request([
            'trid' => 5, 'create_group' => false,
            'source' => ['post_id' => 1, 'language_code' => 'en',
                         'element_type' => 'post_page', 'source_language_code' => null],
            'translations' => [['post_id' => 2, 'language_code' => 'de',
                                'element_type' => 'post_page', 'source_language_code' => 'en']],
        ]));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('group_disagreement', $response->get_data()['code']);
        $this->assertSame([], WpStub::$writes);
    }

    #[Group('wpml')]
    public function test_a_written_plan_comes_back_200(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'page', 'de', 5);
        WpStub::cadence_published(1);
        WpStub::cadence_published(2);
        $response = ($this->route[2]['callback'])(new WP_REST_Request([
            'trid' => 5, 'create_group' => false,
            'source' => ['post_id' => 1, 'language_code' => 'en',
                         'element_type' => 'post_page', 'source_language_code' => null],
            'translations' => [['post_id' => 2, 'language_code' => 'de',
                                'element_type' => 'post_page', 'source_language_code' => 'en']],
        ]));
        $this->assertSame(200, $response->get_status());
        $this->assertSame(2, $response->get_data()['written']);
    }

    /**
     * THE HEADER IS THE PLUGIN, as far as WordPress is concerned: a file whose
     * `Plugin Name` line is malformed is not a broken plugin, it is not a
     * plugin, and it simply never appears on the plugins screen.
     *
     * The licence line is checked against the LICENSE file actually shipped
     * beside it rather than against a string typed twice. Distribution under
     * GPL is what the header promises and what the file has to deliver -- and
     * for this plugin it is also the condition of the WPML developer licence it
     * is built against, so the two disagreeing is not cosmetic.
     */
    public function test_the_header_declares_a_plugin_and_the_licence_it_ships(): void {
        $header = substr((string) file_get_contents(dirname(__DIR__) . '/cadence-connector.php'), 0, 8192);
        $field = static function (string $name) use ($header): string {
            preg_match('/^[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi', $header, $m);
            return isset($m[1]) ? trim($m[1]) : '';
        };

        $this->assertSame('Cadence Connector', $field('Plugin Name'));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $field('Version'));
        $this->assertNotSame('', $field('Description'));

        // THE VERSION ON THE REPLY IS THIS SAME VERSION. `CadenceRestRoute::VERSION`
        // is a second copy of this line rather than a parse of it, so nothing
        // enforces the two moving together except this comparison -- a release
        // that bumps the header and forgets the constant now fails here instead
        // of shipping a reply that names the version it was before the bump.
        $this->assertSame($field('Version'), CadenceRestRoute::VERSION,
            'the header was bumped without CadenceRestRoute::VERSION, so every reply now names the wrong version');

        // `array_is_list` and the typed closures below it are 8.1; a header
        // claiming less lets WordPress activate this on a host it fatals on.
        $this->assertTrue(version_compare($field('Requires PHP'), '8.1', '>='),
            'header allows a PHP older than the code needs');

        $this->assertSame('GPL-2.0-or-later', $field('License'));
        $licence = (string) file_get_contents(dirname(__DIR__) . '/LICENSE');
        $this->assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $licence);
        $this->assertStringContainsString('Version 2, June 1991', $licence);
    }

    /** A body that is not JSON at all reaches the writer as nothing, and is refused. */
    public function test_a_body_that_is_not_json_is_refused_without_writing(): void {
        $response = ($this->route[2]['callback'])(new WP_REST_Request(null));
        $this->assertSame(400, $response->get_status());
        $this->assertSame([], WpStub::$writes);
    }

    /**
     * THE ORDINARY FLOW, END TO END THROUGH THE REGISTERED ROUTES: publish each
     * language with `/content`, then link them with `/translation-group`.
     *
     * THE ACCEPT-PROOF FOR THE NARROWING. A scope is only safe if the work it
     * is supposed to permit still happens, and this is the shape the pipeline
     * actually sends -- every member of the link plan is composed from what
     * `/content` answered, so every member carries the identifier the scope
     * reads. Nothing here writes the meta by hand: the two routes are asked in
     * the order a publish run asks them, which is what makes this a proof that
     * the ordinary request passes rather than that the fixture does.
     */
    #[Group('wpml')]
    public function test_publishing_two_languages_then_linking_them_still_succeeds(): void {
        $publish = $this->routes['/content']['callback'];
        $ids = [];
        foreach (['en' => 'piece-en', 'de' => 'piece-de'] as $language => $piece) {
            $made = $publish(new WP_REST_Request([
                'piece_id' => $piece, 'post_type' => 'post', 'status' => 'publish',
                'title' => 'T-' . $language, 'content' => 'C', 'language' => $language,
                'declared' => ['multilingual' => true, 'languages' => ['en', 'de']]]));
            $this->assertSame(201, $made->get_status(), $language);
            $ids[$language] = $made->get_data()['post_id'];
        }

        $linked = ($this->route[2]['callback'])(new WP_REST_Request([
            'piece_id' => 'piece-en', 'trid' => null, 'create_group' => true,
            'source' => ['post_id' => $ids['en'], 'language_code' => 'en',
                         'element_type' => 'post_post', 'source_language_code' => null],
            'translations' => [['post_id' => $ids['de'], 'language_code' => 'de',
                                'element_type' => 'post_post', 'source_language_code' => 'en']],
        ]));

        $this->assertSame(200, $linked->get_status(), (string) ($linked->get_data()['reason'] ?? ''));
        $this->assertSame(2, $linked->get_data()['written']);
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * AND THE SAME ROUTE REFUSES A POST THIS CONNECTOR NEVER PUBLISHED, with a
     * code and a status rather than WordPress's own `rest_forbidden`.
     *
     * The key is genuine and carries `translation.link`, so the tripwire at the
     * `permission_callback` says yes -- which is the point: the boundary is at
     * the write, and it is what a caller can read an answer off. Before this,
     * `translation.link` authorised linking any post on the site, and the site
     * ITSELF is what a wrong group destroys the translation relations of.
     */
    #[Group('wpml')]
    public function test_a_linking_key_cannot_reach_a_post_this_connector_never_published(): void {
        WpStub::add_post(41, 'page', 'en', null);
        WpStub::add_post(42, 'page', 'de', null);
        WpStub::cadence_published(41);
        $header = $this->key(CadenceKey::issue('tenant-a', ['translation.link'], 7));
        $body = ['trid' => null, 'create_group' => true,
                 'source' => ['post_id' => 41, 'language_code' => 'en',
                              'element_type' => 'post_page', 'source_language_code' => null],
                 'translations' => [['post_id' => 42, 'language_code' => 'de',
                                     'element_type' => 'post_page', 'source_language_code' => 'en']]];

        // The capability tripwire permits it -- a key holding this grant, a body
        // whose shape reads. Nothing about the request is wrong.
        $this->assertTrue(($this->route[2]['permission_callback'])(new WP_REST_Request($body, $header)));

        $response = ($this->route[2]['callback'])(new WP_REST_Request($body, $header));
        $this->assertSame(403, $response->get_status());
        $this->assertSame('post_out_of_scope', $response->get_data()['code']);
        $this->assertFalse($response->get_data()['ok']);
        $this->assertSame([], WpStub::$writes, 'a post outside the scope was linked anyway');
    }

    /**
     * THE LINKING ROUTE APPLIES THE SAME SCOPE, at the route and not merely in
     * the handler.
     *
     * The key's post types reached `/content` and `/content/replace` first and
     * stopped there: `CadenceLinkRequest` refused perfectly on an argument the
     * callback never passed it, and every handler test in `LinkRequestTest`
     * would go on passing while a key scoped to `post` linked pages. So both
     * halves below go through the registered callback with a real key in a
     * header, and read the code off the REST response body rather than off the
     * handler's return.
     */
    #[Group('wpml')]
    public function test_the_linking_route_applies_the_keys_publish_scope(): void {
        $call = $this->route[2]['callback'];
        $header = $this->key(CadenceKey::issue('tenant-a', ['translation.link'], 7, ['post']));
        WpStub::add_post(41, 'page', 'en', null);
        WpStub::add_post(42, 'page', 'de', null);
        WpStub::cadence_published(41);
        WpStub::cadence_published(42);
        $body = ['trid' => null, 'create_group' => true,
                 'source' => ['post_id' => 41, 'language_code' => 'en',
                              'element_type' => 'post_page', 'source_language_code' => null],
                 'translations' => [['post_id' => 42, 'language_code' => 'de',
                                     'element_type' => 'post_page', 'source_language_code' => 'en']]];

        // The capability tripwire permits it: the key holds `translation.link`
        // and the body reads. What it does not hold is the type.
        $this->assertTrue(($this->route[2]['permission_callback'])(new WP_REST_Request($body, $header)));

        $refused = $call(new WP_REST_Request($body, $header));
        $this->assertSame(403, $refused->get_status());
        $this->assertSame('link_post_type_out_of_scope', $refused->get_data()['code']);
        $this->assertFalse($refused->get_data()['ok']);
        $this->assertSame([], WpStub::$writes, 'a key scoped to post linked two pages');
        // The key's own scope travels in the reason; the pages' type does not.
        $this->assertStringContainsString('post', $refused->get_data()['reason']);
        $this->assertStringNotContainsString('page', $refused->get_data()['reason']);

        // THE ACCEPT-PROOF, through the same callback and the same key: two
        // posts of the type this key names are still linked. Without it the
        // assertions above pass on a route that links nothing at all.
        WpStub::add_post(43, 'post', 'en', null);
        WpStub::add_post(44, 'post', 'de', null);
        WpStub::cadence_published(43);
        WpStub::cadence_published(44);
        $linked = $call(new WP_REST_Request([
            'trid' => null, 'create_group' => true,
            'source' => ['post_id' => 43, 'language_code' => 'en',
                         'element_type' => 'post_post', 'source_language_code' => null],
            'translations' => [['post_id' => 44, 'language_code' => 'de',
                                'element_type' => 'post_post', 'source_language_code' => 'en']],
        ], $header));
        $this->assertSame(200, $linked->get_status(), (string) ($linked->get_data()['reason'] ?? ''));
        $this->assertSame(2, $linked->get_data()['written']);
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * THE ROUTE APPLIES THE PRESENTING KEY'S PUBLISH SCOPE.
     *
     * Asserted at the route, not at the handler: the handler can refuse
     * perfectly on an argument the route never passes it, and every handler
     * test still passes while a key scoped to `post` goes on creating pages.
     * Both halves here -- the refusal and the ordinary publish that must still
     * work -- go through the registered callback with a real key in a header.
     */
    #[Group('wpml')]
    public function test_the_content_route_applies_the_keys_publish_scope(): void {
        $call = $this->routes['/content']['callback'];
        $header = $this->key(CadenceKey::issue('tenant-a', ['content.publish'], 7, ['post']));
        $body = ['piece_id' => 'p-1', 'status' => 'draft', 'title' => 'T', 'content' => 'C',
                 'language' => 'en', 'declared' => ['multilingual' => true, 'languages' => ['en']]];

        $refused = $call(new WP_REST_Request($body + ['post_type' => 'page'], $header));
        $this->assertSame(403, $refused->get_status());
        $this->assertSame('post_type_out_of_scope', $refused->get_data()['code']);
        $this->assertSame([], WpStub::$inserted, 'a key scoped to post created a page');

        // THE ACCEPT-PROOF. A narrowing with only a refusal test is one nobody
        // can tell from a connector that publishes nothing.
        $made = $call(new WP_REST_Request($body + ['post_type' => 'post'], $header));
        $this->assertSame(201, $made->get_status(), (string) ($made->get_data()['reason'] ?? ''));
        $this->assertCount(1, WpStub::$inserted);
    }

    /**
     * AND A KEY THAT NAMES NO POST TYPE STILL PUBLISHES INTO ANY.
     *
     * The compatibility path, at the route: keys are live on sites this
     * repository does not control, and the upgrade must not turn a working
     * publish into a 403 over a field the key's holder cannot see.
     */
    #[Group('wpml')]
    public function test_the_content_route_lets_an_unscoped_key_publish_into_any_type(): void {
        $header = $this->key(CadenceKey::issue('tenant-a', ['content.publish'], 7));
        $made = ($this->routes['/content']['callback'])(new WP_REST_Request([
            'piece_id' => 'p-1', 'post_type' => 'page', 'status' => 'draft', 'title' => 'T',
            'content' => 'C', 'language' => 'en',
            'declared' => ['multilingual' => true, 'languages' => ['en']]], $header));

        $this->assertSame(201, $made->get_status(), (string) ($made->get_data()['reason'] ?? ''));
        $this->assertSame('page', WpStub::$inserted[0]['post_type'] ?? null);
    }

    /**
     * TWO KEYS ON ONE SITE DO NOT SHARE A SCOPE, end to end through the
     * registered routes.
     *
     * The shape this is filed against is a client with two brands on one
     * WordPress, or an agency site serving two of our tenants. Nothing writes
     * the stamp by hand: tenant A publishes through `/content` and tenant B
     * asks `/translation-group` about what A created, in the order the two
     * pipelines actually run -- which is what makes this a boundary rather than
     * a field.
     */
    #[Group('wpml')]
    public function test_one_tenants_key_cannot_link_another_tenants_posts(): void {
        $a = $this->key(CadenceKey::issue('tenant-a', ['content.publish', 'translation.link'], 7));
        $b = $this->key(CadenceKey::issue('tenant-b', ['content.publish', 'translation.link'], 7));
        $publish = $this->routes['/content']['callback'];
        $ids = [];
        foreach (['en' => 'piece-en', 'de' => 'piece-de'] as $language => $piece) {
            $made = $publish(new WP_REST_Request([
                'piece_id' => $piece, 'post_type' => 'post', 'status' => 'publish',
                'title' => 'T-' . $language, 'content' => 'C', 'language' => $language,
                'declared' => ['multilingual' => true, 'languages' => ['en', 'de']]], $a));
            $this->assertSame(201, $made->get_status(), $language);
            $ids[$language] = $made->get_data()['post_id'];
        }
        $plan = ['piece_id' => 'piece-en', 'trid' => null, 'create_group' => true,
                 'source' => ['post_id' => $ids['en'], 'language_code' => 'en',
                              'element_type' => 'post_post', 'source_language_code' => null],
                 'translations' => [['post_id' => $ids['de'], 'language_code' => 'de',
                                     'element_type' => 'post_post', 'source_language_code' => 'en']]];
        $link = $this->route[2]['callback'];

        // Tenant B's key is genuine and carries `translation.link`, so the
        // capability tripwire says yes -- which is the point: the boundary is
        // at the write, and `scope_admits` alone would have admitted these
        // posts, because Cadence did publish them.
        $this->assertTrue(($this->route[2]['permission_callback'])(new WP_REST_Request($plan, $b)));
        $refused = $link(new WP_REST_Request($plan, $b));
        $this->assertSame(403, $refused->get_status());
        $this->assertSame('post_out_of_scope', $refused->get_data()['code']);
        $this->assertSame([], WpStub::$writes, "a second tenant's key linked these posts anyway");

        // THE ACCEPT-PROOF: tenant A links its own pieces, through the same
        // route, with the same plan.
        $linked = $link(new WP_REST_Request($plan, $a));
        $this->assertSame(200, $linked->get_status(), (string) ($linked->get_data()['reason'] ?? ''));
        $this->assertSame(2, $linked->get_data()['written']);
        $this->assertCount(2, WpStub::$writes);
    }

    /**
     * AND TENANT B CANNOT TELL TENANT A'S POSTS FROM POSTS THAT ARE NOT THERE,
     * asserted on the REPLY BODY through the registered route.
     *
     * `post_other_key` used to answer "this connector published that post and a
     * different key did" beside `post_out_of_scope`'s "absent, or not this
     * connector's". The pair sorted every integer on the site into another
     * tenant's Cadence pieces and everything else -- one `403` at a time, to a
     * caller holding a leaked connector key and nothing else, because this
     * route verifies no attestation. Provenance is the fact per-key scope was
     * added to protect, so the two questions are asked as one.
     *
     * OFF THE BODY AND NOT OFF `run`'s RETURN: what leaks is what reaches the
     * caller, and a field that never left the plugin proves nothing.
     */
    #[Group('wpml')]
    public function test_a_second_tenant_cannot_tell_the_first_tenants_posts_from_absent_ones(): void {
        $a = $this->key(CadenceKey::issue('tenant-a', ['content.publish', 'translation.link'], 7));
        $b = $this->key(CadenceKey::issue('tenant-b', ['content.publish', 'translation.link'], 7));
        $publish = $this->routes['/content']['callback'];
        $link    = $this->route[2]['callback'];

        $made = $publish(new WP_REST_Request([
            'piece_id' => 'a-en', 'post_type' => 'post', 'status' => 'publish',
            'title' => 'T', 'content' => 'C', 'language' => 'en',
            'declared' => ['multilingual' => true, 'languages' => ['en', 'de']]], $a));
        $this->assertSame(201, $made->get_status());
        $theirs = $made->get_data()['post_id'];

        $mine = $publish(new WP_REST_Request([
            'piece_id' => 'b-de', 'post_type' => 'post', 'status' => 'publish',
            'title' => 'T', 'content' => 'C', 'language' => 'de',
            'declared' => ['multilingual' => true, 'languages' => ['en', 'de']]], $b));
        $this->assertSame(201, $mine->get_status());

        // A post on the site this connector never published, of the type the
        // plan names -- so nothing but the Cadence stamp separates it from
        // tenant A's, and an existence or type read moved above the scope loop
        // would show up as a third answer here.
        $a_stranger = 5000;
        WpStub::add_post($a_stranger, 'post', 'en', null);
        $absent = 5001;

        $bodies = [];
        foreach (['absent' => $absent, 'not ours' => $a_stranger, "tenant A's" => $theirs] as $case => $probe) {
            WpStub::$writes = [];
            $plan = ['trid' => null, 'create_group' => true,
                     'source' => ['post_id' => $probe, 'language_code' => 'en',
                                  'element_type' => 'post_post', 'source_language_code' => null],
                     'translations' => [['post_id' => $mine->get_data()['post_id'],
                                         'language_code' => 'de', 'element_type' => 'post_post',
                                         'source_language_code' => 'en']]];
            $r = $link(new WP_REST_Request($plan, $b));
            $this->assertSame(403, $r->get_status(), $case);
            $this->assertSame([], WpStub::$writes, $case);
            $body = $r->get_data();
            $this->assertFalse($body['ok'], $case);
            // The whole body, not the code alone: a status, a branch name or a
            // stray field would separate the cases just as well as a sentence.
            $bodies[$case] = preg_replace('/\d+/', '<n>', json_encode($body));
        }
        $this->assertCount(1, array_unique($bodies),
            'a second tenant could tell the three cases apart: ' . implode(' | ', $bodies));
        $this->assertStringContainsString('post_out_of_scope', $bodies['absent']);
        // AND THE ACCEPT-PROOF is the test above: tenant A links the same
        // pieces through the same route and is written.
    }

    /**
     * A SECOND TENANT THAT GUESSES A `piece_id` LEARNS NOTHING ABOUT THE FIRST
     * TENANT'S POST, and cannot rewrite it with what it is handed.
     *
     * THE REVIEWER'S SCENARIO, driven through the registered routes because
     * that is where it was reachable: `/content`'s idempotent-repeat branch
     * used to answer with whatever post carried the identifier -- any type, any
     * creating key -- so key B, scoped to `post` and holding
     * `content.replace`, was answered `200` with key A's PAGE: its post id and
     * the revision that rewrites it, from behind a type scope that post
     * violates, and then `/content/replace` took the pair. The amplifier is
     * that B needed no prior knowledge of the post id; it guessed a slug.
     *
     * Asserted at the route and not at the handler: the handler can scope the
     * lookup perfectly while the route passes it no key id, and every handler
     * test would still pass.
     */
    #[Group('wpml')]
    public function test_a_second_tenants_guessed_piece_id_reaches_neither_the_post_id_nor_the_revision(): void {
        $a = CadenceKey::issue('tenant-a', ['content.publish'], 7, ['page']);
        $b = CadenceKey::issue('tenant-b', ['content.publish', 'content.replace'], 7, ['post']);
        $this->assertIsArray($a, is_string($a) ? $a : '');
        $this->assertIsArray($b, is_string($b) ? $b : '');
        $publish = $this->routes['/content']['callback'];
        $piece = ['piece_id' => 'shared-slug', 'language' => 'en',
                  'declared' => ['multilingual' => true, 'languages' => ['en']]];

        $made = $publish(new WP_REST_Request($piece + ['post_type' => 'page', 'status' => 'publish',
            'title' => "A's title", 'content' => "<p>A's body.</p>"], $this->key($a)));
        $this->assertSame(201, $made->get_status());
        $mine = $made->get_data()['post_id'];
        $this->assertSame($a['id'], WpStub::$meta[$mine][CadenceContentRequest::KEY_META]);

        // B names the same identifier, under the one type its own key admits.
        $repeat = $publish(new WP_REST_Request($piece + ['post_type' => 'post', 'status' => 'draft',
            'title' => "B's title", 'content' => "<p>B's body.</p>"], $this->key($b)));

        // B GETS ITS OWN PIECE. Not a refusal: a refusal would confirm the
        // identifier is taken, and would refuse a publish B may make.
        $this->assertSame(201, $repeat->get_status(), (string) ($repeat->get_data()['reason'] ?? ''));
        $theirs = $repeat->get_data()['post_id'];
        $this->assertNotSame($mine, $theirs, "B was handed A's post id");
        $this->assertNotSame($made->get_data()['revision'], $repeat->get_data()['revision'],
            "B was handed the revision of A's post");
        $this->assertSame($b['id'], WpStub::$meta[$theirs][CadenceContentRequest::KEY_META]);
        $this->assertSame('post', WpStub::$posts[$theirs]['post_type']);
        // A's post is untouched and still A's.
        $this->assertSame("A's title", WpStub::$posts[$mine]['post_title']);

        // AND THE PAIR B HOLDS DOES NOT REWRITE A'S POST. Even handed the id
        // by this test -- which the route no longer discloses -- the post is
        // not B's, and the replace route now asks that before it compares any
        // text: a 403 over the credential rather than the 409 the revision
        // would have produced. Both refuse, and they are not the same fact --
        // a revision the caller re-read would satisfy the second and can never
        // satisfy the first.
        $replace = $this->routes['/content/replace'];
        $rewrite = ['piece_id' => 'shared-slug', 'post_id' => $mine,
                    'revision' => $repeat->get_data()['revision'],
                    'title' => 'B took it', 'content' => '<p>B body.</p>'];
        $this->assertTrue(($replace['permission_callback'])(new WP_REST_Request($rewrite, $this->key($b))));
        $refused = ($replace['callback'])(new WP_REST_Request($rewrite, $this->key($b)));

        $this->assertSame(403, $refused->get_status());
        $this->assertSame('replace_other_key', $refused->get_data()['code']);
        $this->assertSame([], WpStub::$updated, "A's post was rewritten anyway");
        $this->assertSame($a['id'], WpStub::$meta[$mine][CadenceContentRequest::KEY_META]);
    }

    /**
     * ONE TENANT'S KEY DOES NOT REWRITE ANOTHER TENANT'S POST, EVEN HOLDING
     * THE IDENTIFIER AND THE REVISION.
     *
     * The identifier is not a secret: it travels in plan payloads, ledger rows
     * and the tenant's own operator surface. This test hands B both -- the
     * `piece_id` and the revision read off A's live post -- so nothing but the
     * key identity is left to refuse on, and a replace overwrites a published
     * title and body.
     *
     * DRIVEN THROUGH THE REGISTERED ROUTE, and that is the point rather than a
     * style: `CadenceReplaceRequest` can ask `created_by` perfectly while the
     * route passes it no key id, and every handler test would still pass.
     */
    #[Group('wpml')]
    public function test_one_tenants_key_cannot_replace_another_tenants_post(): void {
        $a = CadenceKey::issue('tenant-a', ['content.publish', 'content.replace'], 7);
        $b = CadenceKey::issue('tenant-b', ['content.publish', 'content.replace'], 7);
        $this->assertIsArray($a, is_string($a) ? $a : '');
        $this->assertIsArray($b, is_string($b) ? $b : '');

        $made = ($this->routes['/content']['callback'])(new WP_REST_Request([
            'piece_id' => 'piece-a', 'post_type' => 'post', 'status' => 'publish',
            'title' => "A's title", 'content' => "<p>A's body.</p>", 'language' => 'en',
            'declared' => ['multilingual' => true, 'languages' => ['en']]], $this->key($a)));
        $this->assertSame(201, $made->get_status(), (string) ($made->get_data()['reason'] ?? ''));
        $mine = $made->get_data()['post_id'];

        $replace = $this->routes['/content/replace'];
        $rewrite = ['piece_id' => 'piece-a', 'post_id' => $mine,
                    'revision' => $made->get_data()['revision'],
                    'title' => 'B took it', 'content' => '<p>B body.</p>'];

        // B's key is genuine and carries `content.replace`, so the capability
        // tripwire says yes -- which is the point: the boundary is at the
        // write, and `identifier_mismatch` agrees with B, because B named the
        // identifier the post really carries.
        $this->assertTrue(($replace['permission_callback'])(new WP_REST_Request($rewrite, $this->key($b))));
        $refused = ($replace['callback'])(new WP_REST_Request($rewrite, $this->key($b)));
        $this->assertSame(403, $refused->get_status(), (string) ($refused->get_data()['reason'] ?? ''));
        $this->assertSame('replace_other_key', $refused->get_data()['code']);
        $this->assertSame([], WpStub::$updated, "a second tenant's key rewrote the post anyway");
        $this->assertSame("A's title", WpStub::$posts[$mine]['post_title']);

        // THE ACCEPT-PROOF: tenant A sends the identical body through the same
        // route and its own post is rewritten.
        $written = ($replace['callback'])(new WP_REST_Request($rewrite, $this->key($a)));
        $this->assertSame(200, $written->get_status(), (string) ($written->get_data()['reason'] ?? ''));
        $this->assertSame('B took it', WpStub::$posts[$mine]['post_title']);
    }

    /**
     * AND THE TYPE SCOPE REACHES THE POSTS THAT PREDATE THE STAMP, which is
     * the set `created_by` admits to everybody and the only narrowing left
     * over it. A piece this connector published before it recorded which key
     * made it is reachable by any key -- deliberately, or an upgrade would
     * break every rewrite of work already done -- so a key that names `post`
     * must not reach such a `page`.
     *
     * At the route, for the same reason as above: the handler cannot apply a
     * scope the route never hands it.
     */
    public function test_a_key_does_not_rewrite_a_pre_stamp_piece_outside_its_types(): void {
        $narrow = CadenceKey::issue('tenant-narrow', ['content.replace'], 7, ['post']);
        $this->assertIsArray($narrow, is_string($narrow) ? $narrow : '');
        // A page this connector published before the stamp existed: the
        // identifier is there and no key id is.
        WpStub::add_post(41, 'page');
        WpStub::cadence_published(41, 'piece-old');
        $this->assertArrayNotHasKey(CadenceContentRequest::KEY_META, WpStub::$meta[41]);

        $replace = $this->routes['/content/replace'];
        $rewrite = ['piece_id' => 'piece-old', 'post_id' => 41,
                    'revision' => CadenceRevision::of('', ''),
                    'title' => 'Rewritten', 'content' => '<p>Rewritten.</p>'];
        $this->assertTrue(($replace['permission_callback'])(
            new WP_REST_Request($rewrite, $this->key($narrow))));

        $refused = ($replace['callback'])(new WP_REST_Request($rewrite, $this->key($narrow)));
        $this->assertSame(403, $refused->get_status(), (string) ($refused->get_data()['reason'] ?? ''));
        $this->assertSame('existing_post_type_out_of_scope', $refused->get_data()['code']);
        $this->assertSame([], WpStub::$updated);

        // THE ACCEPT-PROOF: a key that names no type is the compatibility case
        // and rewrites exactly what it always rewrote.
        $wide = CadenceKey::issue('tenant-wide', ['content.replace'], 7);
        $this->assertIsArray($wide, is_string($wide) ? $wide : '');
        $written = ($replace['callback'])(new WP_REST_Request($rewrite, $this->key($wide)));
        $this->assertSame(200, $written->get_status(), (string) ($written->get_data()['reason'] ?? ''));
        $this->assertSame('Rewritten', WpStub::$posts[41]['post_title']);
    }
}
