<?php
declare(strict_types=1);

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
        $this->assertCount(1, WpHooks::$routes, 'exactly one route');
        $this->route = WpHooks::$routes[0];
    }

    public function test_registers_one_post_route_under_its_own_namespace(): void {
        $this->assertSame('cadence/v1', $this->route[0]);
        $this->assertSame('/translation-group', $this->route[1]);
        $this->assertSame('POST', $this->route[2]['methods']);
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
     * AND IT PERMITS WHEN WORDPRESS SAYS YES -- otherwise the assertions above
     * hold for a callback wired to nothing at all.
     */
    public function test_the_permission_callback_permits_an_editor(): void {
        WpStub::$capabilities = ['edit_post' => [1, 2]];
        $permit = $this->route[2]['permission_callback'];
        $this->assertTrue($permit(new WP_REST_Request([
            'source' => ['post_id' => 1], 'translations' => [['post_id' => 2]],
        ])));
        $this->assertFalse($permit(new WP_REST_Request([
            'source' => ['post_id' => 1], 'translations' => [['post_id' => 3]],
        ])));
    }

    public function test_a_refused_plan_comes_back_as_the_refusals_own_status(): void {
        WpStub::add_post(1, 'page', 'en', 9);
        WpStub::add_post(2, 'page', 'de', 9);
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

    public function test_a_written_plan_comes_back_200(): void {
        WpStub::add_post(1, 'page', 'en', 5);
        WpStub::add_post(2, 'page', 'de', 5);
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
}
