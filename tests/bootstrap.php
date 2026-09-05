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

    /** @var list<array> every postarr handed to wp_insert_post */
    public static array $inserted = [];
    /** @var list<array> every postarr handed to wp_update_post */
    public static array $updated = [];
    /** @var array<int, array<string, mixed>> post_id => meta */
    public static array $meta = [];
    /** When set, wp_insert_post returns a WP_Error carrying this message. */
    public static ?string $insert_fails = null;

    /**
     * THE SAME TWO FAILURE SWITCHES FOR `wp_update_post`, which fails in the
     * same two ways and is read for its return value in the same way. Without
     * them the refusals on that path are unreachable through these stubs and a
     * mutant deleting them passes the suite -- which is exactly how the `false`
     * branch of `current_trid` survived once already.
     */
    public static ?string $update_fails = null;
    public static bool $update_returns_zero = false;

    /**
     * When true, `get_post` answers null for every id. Not "the post is gone":
     * the post is there and this process could not read it. It exists so that
     * the branch where a revision cannot be computed is reachable, because the
     * tempting answer there -- one derived from the caller's own request -- is
     * the single answer that would be wrong.
     */
    public static bool $post_read_fails = false;

    /**
     * When true, wp_insert_post returns `0` even though it was asked for
     * errors. `0` is what the non-error form returns on failure, and a filter
     * on `wp_insert_post_empty_content` or `wp_insert_post_data` can put a
     * caller on that path from a plugin it does not control. It matters because
     * `0` is falsy and is also what "no post" looks like everywhere else, so an
     * unchecked one propagates as a plausible absence rather than an error.
     */
    public static bool $insert_returns_zero = false;

    public static int $next_post_id = 100;

    /** @var list<string> the post types this site has registered */
    public static array $post_types = ['post', 'page'];

    public static function reset(): void {
        self::$posts = [];
        self::$writes = [];
        self::$capabilities = [];
        self::$wpml_reads = true;
        self::$wpml_writes = true;
        self::$wpml_declines = false;
        self::$inserted = [];
        self::$updated = [];
        self::$meta = [];
        self::$insert_fails = null;
        self::$insert_returns_zero = false;
        self::$update_fails = null;
        self::$update_returns_zero = false;
        self::$post_read_fails = false;
        self::$next_post_id = 100;
        self::$post_types = ['post', 'page'];
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
 * The post row itself. WordPress hands back a WP_Post or, for an id this site
 * does not have, null -- and null is the whole reason anything asks: a caller
 * naming a post that is not here is talking about a different site.
 */
function get_post($post_id = null): ?WP_Post {
    if (WpStub::$post_read_fails) {
        return null;
    }
    $id = is_int($post_id) ? $post_id : 0;
    if (!isset(WpStub::$posts[$id])) {
        return null;
    }
    $p = WpStub::$posts[$id];
    return new WP_Post($id, $p['post_title'] ?? '', $p['post_content'] ?? '',
                       $p['post_status'] ?? 'draft', $p['post_type'] ?? 'post');
}

/** Single-value meta, including WordPress's own answer for meta that is not there. */
function get_post_meta(int $post_id, string $key = '', bool $single = false) {
    $value = WpStub::$meta[$post_id][$key] ?? null;
    if ($single) {
        // `''`, not null and not false. A plugin reading this as "no value" by
        // truthiness cannot tell it from a meta value that is an empty string.
        return $value ?? '';
    }
    return $value === null ? [] : [$value];
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

/**
 * The post object, in the five fields anything here reads. Real WordPress
 * declares far more, and declares them without types; typed here so that a
 * stub handing back something a real WP_Post could never hold fails loudly in
 * the test rather than quietly in the code under test.
 */
final class WP_Post {
    public function __construct(
        public int $ID,
        public string $post_title = '',
        public string $post_content = '',
        public string $post_status = 'draft',
        public string $post_type = 'post'
    ) {}
}

/** WordPress's own error type, in the two respects anything here uses it. */
final class WP_Error {
    public function __construct(private string $code = '', private string $message = '') {}
    public function get_error_message(): string { return $this->message; }
    public function get_error_code(): string { return $this->code; }
}

function is_wp_error($thing): bool { return $thing instanceof WP_Error; }

/**
 * The capability names for a post type. Real WordPress derives these from the
 * type's registration, and they are NOT the same for every type -- which is the
 * whole reason the plugin asks for them rather than hard-coding `edit_posts`.
 */
function get_post_type_object(string $type): ?object {
    if (!post_type_exists($type)) {
        return null;   // exactly what WordPress returns for an unregistered type
    }
    $plural = $type === 'post' ? 'posts' : $type . 's';
    return (object) ['name' => $type, 'cap' => (object) [
        'create_posts'  => 'create_' . $plural,
        'publish_posts' => 'publish_' . $plural,
    ]];
}

function post_type_exists(string $type): bool {
    return in_array($type, WpStub::$post_types, true);
}

/**
 * Returns a new post's id, or -- and this is the half that gets forgotten -- a
 * WP_Error, or 0. It does not throw.
 */
function wp_insert_post(array $postarr, bool $wp_error = false) {
    if (WpStub::$insert_returns_zero) {
        return 0;
    }
    if (WpStub::$insert_fails !== null) {
        return $wp_error
            ? new WP_Error('db_insert_error', WpStub::$insert_fails)
            : 0;
    }
    $id = WpStub::$next_post_id++;
    WpStub::$inserted[] = $postarr;
    WpStub::$posts[$id] = [
        'post_type' => $postarr['post_type'] ?? 'post',
        'post_status' => $postarr['post_status'] ?? 'draft',
        'post_title' => $postarr['post_title'] ?? '',
        'post_content' => $postarr['post_content'] ?? '',
        'language' => null, 'trid' => null, 'wpml_knows' => true,
    ];
    // `meta_input` is written by wp_insert_post itself, in the same call that
    // creates the post -- which is why the plugin uses it.
    foreach ($postarr['meta_input'] ?? [] as $k => $v) {
        WpStub::$meta[$id][$k] = $v;
    }
    return $id;
}

/**
 * Returns the post's id, or -- the half that gets forgotten, exactly as with
 * `wp_insert_post` -- a WP_Error, or 0. It does not throw, and a failure
 * changes nothing on the site, which is why neither branch records anything.
 */
function wp_update_post(array $postarr, bool $wp_error = false) {
    if (WpStub::$update_returns_zero) {
        return 0;
    }
    if (WpStub::$update_fails !== null) {
        return $wp_error
            ? new WP_Error('db_update_error', WpStub::$update_fails)
            : 0;
    }
    WpStub::$updated[] = $postarr;
    $id = $postarr['ID'] ?? 0;
    // The site now holds what was written, so the next read of this post sees
    // it -- without which nothing here could tell a check made against the
    // post from one made against the request that changed it.
    foreach (['post_title', 'post_content', 'post_status'] as $field) {
        if (isset($postarr[$field], WpStub::$posts[$id])) {
            WpStub::$posts[$id][$field] = $postarr[$field];
        }
    }
    return $id;
}

function update_post_meta(int $post_id, string $key, $value): bool {
    WpStub::$meta[$post_id][$key] = $value;
    return true;
}

/** Only the meta_key/meta_value/fields=ids shape the plugin asks for. */
function get_posts(array $args = []): array {
    $key = $args['meta_key'] ?? null;
    $value = $args['meta_value'] ?? null;
    $want_status = $args['post_status'] ?? 'publish';
    $found = [];
    foreach (WpStub::$meta as $id => $meta) {
        // Exact, never a prefix: `piece-1` must not answer for `piece-10`.
        if (!array_key_exists($key, $meta) || $meta[$key] !== $value) {
            continue;
        }
        // WordPress's default is publish-only; honoured here so that asking
        // for the default rather than `any` is a difference a test can see.
        $status = WpStub::$posts[$id]['post_status'] ?? 'draft';
        if ($want_status !== 'any' && $status !== $want_status) {
            continue;
        }
        $found[] = $id;
    }
    return $found;
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
require_once __DIR__ . '/../includes/class-cadence-revision.php';
require_once __DIR__ . '/../includes/class-cadence-content-request.php';
require_once __DIR__ . '/../includes/class-cadence-replace-request.php';
