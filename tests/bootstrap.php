<?php
/**
 * WordPress and WPML, stubbed just enough to drive the decisions.
 *
 * NOT A WORDPRESS TEST SUITE. What is under test here is the plugin's own
 * REFUSALS -- the checks it makes before it writes -- and those are the part
 * that must not depend on a live site to be exercised. The stubs record what
 * WPML was asked to do so a test can assert that it was asked NOTHING, which is
 * the assertion almost every test here makes.
 */
declare(strict_types=1);

final class WpStub {
    /** @var array<int, array{post_type: string, language: string, trid: int|null}> */
    public static array $posts = [];
    /** @var list<array> every wpml_set_element_language_details call */
    public static array $writes = [];

    /** @var array<string, list<int>> capability => the post ids the current user holds it for */
    public static array $capabilities = [];

    /**
     * WHETHER WPML IS THERE AT ALL, as two separate facts.
     *
     * A site with no WPML still runs `apply_filters` and `do_action` happily:
     * the filter hands back whatever default it was given and the action goes
     * nowhere. So "WPML absent" is not an error the code trips over -- it is a
     * site that answers every question agreeably and performs no writes, which
     * is the shape that produced a `200 {"written": 2}` against a real
     * WordPress with nothing installed.
     *
     * Split in two because the failure is not symmetric: a reader with no
     * writer reports writes that did not happen.
     */
    public static bool $wpml_reads = true;
    public static bool $wpml_writes = true;

    /**
     * A FILTER THAT IS REGISTERED AND DECLINES TO ANSWER. Real, and different
     * from both of the above: WPML registers the hook site-wide and returns the
     * value untouched for an element it does not manage -- a post type nobody
     * enabled translation for. `has_filter` says yes, and the answer that comes
     * back is the caller's own default.
     */
    public static bool $wpml_declines = false;

    public static function reset(): void {
        self::$posts = [];
        self::$writes = [];
        self::$capabilities = [];
        self::$wpml_reads = true;
        self::$wpml_writes = true;
        self::$wpml_declines = false;
    }

    public static function add_post(int $id, string $post_type = 'page',
                                    ?string $language = null, ?int $trid = null,
                                    bool $wpml_knows = true): void {
        self::$posts[$id] = ['post_type' => $post_type, 'language' => $language,
                             'trid' => $trid, 'wpml_knows' => $wpml_knows];
    }
}

function get_post_type(int $id) {
    return WpStub::$posts[$id]['post_type'] ?? false;
}

function get_post_status(int $id) {
    return isset(WpStub::$posts[$id]) ? 'publish' : false;
}

/**
 * WPML's read filter. Returns null when the post is in no group, which is a
 * READING; a post this stub does not know returns false, which is not.
 */
function apply_filters(string $hook, $value, ...$args) {
    // EXACTLY WHAT WORDPRESS DOES WITH NO LISTENER: return the default,
    // unchanged and without complaint. Not an error, not null -- the value the
    // caller itself supplied, which is why an absent WPML is invisible to any
    // code that reads the answer without asking whether anyone answered.
    if (!WpStub::$wpml_reads || WpStub::$wpml_declines) {
        return $value;
    }
    if ($hook === 'wpml_element_language_details') {
        $arg = $args[0] ?? [];
        $id = (int) ($arg['element_id'] ?? 0);
        if (!isset(WpStub::$posts[$id])) {
            return false;
        }
        $p = WpStub::$posts[$id];
        // WORDPRESS KNOWS THIS POST AND WPML DOES NOT. Real, and not the same
        // as "in no group": a post that predates WPML's configuration, or one
        // of a type WPML is not set to translate, has no language details at
        // all. Without this the `false` branch of `current_trid` was
        // unreachable through the stubs, and a mutant collapsing it into
        // "no group" -- the destructive reading -- passed the whole suite.
        if (!($p['wpml_knows'] ?? true)) {
            return false;
        }
        if ($p['trid'] === null) {
            return null;
        }
        return (object) [
            'trid' => $p['trid'],
            'language_code' => $p['language'],
            'source_language_code' => null,
        ];
    }
    return $value;
}

function do_action(string $hook, ...$args): void {
    if (!WpStub::$wpml_writes) {
        return;   // nothing listening; the call is a no-op, as on a real site
    }
    if ($hook === 'wpml_set_element_language_details') {
        WpStub::$writes[] = $args[0] ?? [];
    }
}

/**
 * The real one consults roles, the post's author, its post type's capability
 * map and any number of plugins. The stub answers from an explicit list,
 * because what is under test is which questions get asked, not how WordPress
 * answers them.
 */
function current_user_can(string $cap, ...$args): bool {
    $id = $args[0] ?? null;
    return in_array($id, WpStub::$capabilities[$cap] ?? [], true);
}

/**
 * Whether anything is listening. The real ones answer from WordPress's hook
 * registry; these answer from the two flags above.
 */
function has_filter(string $hook, $callback = false) {
    return $hook === 'wpml_element_language_details' ? WpStub::$wpml_reads : false;
}

function has_action(string $hook, $callback = false) {
    return $hook === 'wpml_set_element_language_details' ? WpStub::$wpml_writes : false;
}

function esc_html(string $s): string { return $s; }
function __(string $s, string $d = ''): string { return $s; }

// What WordPress defines and every plugin file guards on. Defined here for
// the same reason WordPress's own test suite defines it: the guard is only
// disarmed by being genuinely loaded inside WordPress.
define('ABSPATH', '/wordpress/');

/**
 * Enough of WordPress's plugin API to load the entry file and see what it
 * registered. Recording rather than executing: the point is to inspect the
 * registration, since a route registered with the wrong permission callback
 * looks exactly like a working one until someone tries it unauthenticated.
 */
final class WpHooks {
    /** @var array<string, list<callable>> */
    public static array $actions = [];
    /** @var list<array{string, string, array}> */
    public static array $routes = [];

    public static function fire(string $hook): void {
        foreach (self::$actions[$hook] ?? [] as $cb) {
            $cb();
        }
    }
}

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool {
    WpHooks::$actions[$hook][] = $cb;
    return true;
}

function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool {
    WpHooks::$routes[] = [$namespace, $route, $args];
    return true;
}

/** The two fields anything downstream of a route actually reads. */
final class WP_REST_Response {
    public function __construct(public $data = null, public int $status = 200) {}
    public function get_data() { return $this->data; }
    public function get_status(): int { return $this->status; }
}

/** Only `get_json_params`, which is all the route asks of a request. */
final class WP_REST_Request {
    public function __construct(private $json) {}
    public function get_json_params() { return $this->json; }
}

require_once __DIR__ . '/../includes/class-cadence-link-request.php';
require_once __DIR__ . '/../includes/class-cadence-rest-route.php';
