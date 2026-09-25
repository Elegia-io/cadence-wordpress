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
    /** The site's home URL, as `home_url()` answers it. */
    public static string $home = 'https://example.test/';
    /** @var array<int, array{post_type: string, language: string, trid: int|null}> */
    public static array $posts = [];
    /** @var list<array> every wpml_set_element_language_details call */
    public static array $writes = [];

    /**
     * @var list<int> element ids the write LEAVES IN NO GROUP.
     *
     * WPML's own documented outcome, not an invented failure: the action drops
     * an element's translation relations rather than reporting anything, and
     * it returns nothing whatever it does. A caller cannot tell from the write
     * that the relation it asked for is gone -- it can only read the site back,
     * which is why the report does.
     */
    public static array $wpml_write_detaches = [];

    /**
     * @var list<int> element ids whose write leaves WPML WITH NO USABLE ANSWER.
     *
     * Distinct from `$wpml_write_detaches`, which leaves the element in no
     * group -- a reading. This leaves the element one the language-details
     * filter says nothing usable about, which is not a reading and must never
     * be treated as "no group". The create path reads the source back between
     * its own two writes, so this is the only way to reach the branch where
     * that read is not an answer.
     */
    public static array $wpml_write_unreadable = [];

    /**
     * @var array<int, string> element id => the language the write ACTUALLY stores.
     *
     * WPML filing a post under something other than the code it was handed is
     * not invented: it mirrors a symptom seen from the site's side, where every
     * post landed in the default language. Without a way to make the write
     * disagree with its argument, a `placed` that echoes the request passes
     * every assertion, because the stub's write sets the language to whatever
     * was asked and the two strings can never differ.
     */
    public static array $wpml_write_lands_as = [];

    /**
     * @var list<int> trids WPML will not enumerate the members of.
     *
     * The create path has to know what a regroup would DETACH, and a filter
     * nobody answers hands back the default. Without a way to reach that,
     * a mutant reading silence as "the group is empty" -- the destructive
     * reading -- passes the suite.
     */
    public static array $wpml_group_unreadable = [];

    /** The next group id WPML invents for a write that names none. */
    public static int $next_trid = 900;

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

    /**
     * WHAT THE ROW ITSELF HOLDS, when that is not what `get_post` answers.
     *
     * Not a contrivance: `get_post` reads WordPress's object cache, and a
     * concurrent process editing the post in wp-admin invalidates that cache
     * in ITS process, not in this one. So a request can hold a WP_Post whose
     * text the database no longer has -- which is precisely the window a
     * check made before a write sits in. Keyed by post id; the fields given
     * override what `$wpdb` reports for that row, and nothing else.
     *
     * @var array<int, array<string, string>>
     */
    public static array $row_override = [];

    /**
     * Ids whose row is gone by the time it is locked, though `get_post`
     * answered for them. A post deleted between the two reads, or a row the
     * locking read could not get. Not the same as `$post_read_fails`, which
     * is about the first read.
     *
     * @var list<int>
     */
    public static array $rows_gone = [];

    /** When set, `wp_update_post` throws it -- as a hook on `save_post` can. */
    public static ?string $update_throws = null;

    public static int $next_post_id = 100;

    /** @var array<string, array> WPML's active-language map, code => details */
    public static array $active_languages = ['en' => ['code' => 'en'], 'de' => ['code' => 'de']];

    /** @var array<string, mixed> the site's options table, in the two calls anything here makes */
    public static array $options = [];

    /**
     * @var array<int, string> the users this site has, id => display name.
     *
     * A list rather than "any id is a user": a key's byline is checked against
     * the site, and a stub that said yes to every id would make that check
     * unreachable from here.
     */
    public static array $users = [7 => 'A Real Person'];

    /** @var list<string> the post types this site has registered */
    public static array $post_types = ['post', 'page', 'revision', 'nav_menu_item'];

    /**
     * TYPES REGISTERED BUT NOT PUBLIC-FACING, mirroring core's own
     * `is_post_type_viewable()` for the two built-ins this suite needs:
     * `revision` and `nav_menu_item`. Deliberately does NOT include
     * `attachment` -- core really does answer true for it, and
     * `CadenceKey::is_content_type` excludes it on its own grounds, which is
     * the fact a test against this stub has to prove rather than assume.
     *
     * @var list<string>
     */
    public static array $non_viewable_types = ['revision', 'nav_menu_item'];

    /**
     * IDS WHOSE CACHE HAS BEEN INVALIDATED, standing in for
     * `clean_post_cache()`. `get_post` answers from `$posts` -- the possibly
     * stale cache -- for every id not in here; once an id is cleared, it
     * answers from `$row_override` merged over `$posts` instead, which is
     * the same overlay the locked `SELECT ... FOR UPDATE` read already uses
     * to model a row a concurrent edit changed underneath the cache.
     *
     * @var array<int, bool>
     */
    public static array $cache_cleared = [];

    /**
     * STANDS IN FOR `wp_suspend_cache_invalidation()` BEING ON, as it is
     * during a WordPress import and around a bulk operation a client's own
     * plugin runs. While true, `clean_post_cache()` is the documented no-op
     * -- WordPress skips the invalidation entirely -- and only a direct
     * `wp_cache_delete()` still clears the id.
     */
    public static bool $cache_invalidation_suspended = false;

    /**
     * WHETHER THE NONCE `check_admin_referer` IS ASKED TO VERIFY IS GOOD.
     *
     * The one thing a test controls about it. Real WordPress reads a value
     * that travelled in the form; the stub answers this instead, so a test
     * can make the check fail without knowing anything about how a nonce is
     * built.
     */
    public static bool $referer_valid = true;

    /** Every action `check_admin_referer` was asked to verify, in order. */
    public static array $referers_checked = [];

    /** Every action `wp_nonce_url` minted a nonce for, in order. */
    public static array $nonces_made = [];

    /**
     * THE TRANSIENT STORE, which is where a just-issued key waits for the
     * redirect to land instead of travelling in the URL. Kept separate from
     * `$options` so a test can see the difference: a secret in the options
     * table outlives the render, and one here is deleted by the read.
     *
     * @var array<string, mixed>
     */
    public static array $transients = [];

    /**
     * EVERY READ OF THE SITE, counted per function, so a refusal that must
     * come before the site is asked anything can be measured as zero reads of
     * every kind rather than zero calls to `get_post` alone. `get_option` is
     * counted per option name, because the key store is itself an option and
     * the attestation has to read it to have a key to verify with.
     */
    public static array $reads = [];

    /** Meta keys whose `add_post_meta` answers false, as a failed write would. */
    public static array $meta_add_fails = [];

    /** Every `add_post_meta` call that stored something, in order, as [id, key, raw value]. */
    public static array $meta_added = [];

    /**
     * EVERY ROW OF A KEY, for a key written more than once without `$unique`.
     * `$meta` keeps the first row, which is what a single read answers.
     */
    public static array $meta_rows = [];

    /**
     * Called once, just before a `SELECT ... FOR UPDATE` returns: a second
     * request that took the row lock first and committed while this one
     * waited on it.
     */
    public static $on_lock = null;

    /** Meta keys whose `delete_post_meta` answers false and removes nothing, as a failed delete would. */
    public static array $meta_delete_fails = [];

    /** Every `delete_post_meta` call that removed something, in order, as [id, key]. */
    public static array $meta_deleted = [];

    /** Public path => post id, the permalinks `url_to_postid` resolves. */
    public static array $permalinks = [];

    /**
     * Run once, inside the next `INSERT IGNORE` into the options table, before
     * that insert looks for a row: the moment a second request would have to
     * land in to race the first.
     */
    public static $on_claim = null;

    /**
     * AN OPT-IN META CACHE, as WordPress keeps one per request: the first
     * `get_post_meta` for a post primes every row it has, and later reads
     * answer from that copy until this process writes the post's meta or
     * calls `wp_cache_delete($id, 'post_meta')`. A write made straight into
     * `$meta` is another request's, and a primed copy does not see it.
     */
    public static bool $meta_cache_on = false;
    /** @var array<int, array{0: array, 1: array}> */
    public static array $meta_cache = [];

    /**
     * AN OPT-IN QUERY CACHE for `get_posts`, keyed on its arguments, unless the
     * caller passes `'cache_results' => false`. Stands in for the result a
     * request already holds for the same query.
     */
    public static bool $query_cache_on = false;
    /** @var array<string, array> */
    public static array $query_cache = [];

    /**
     * @var array<string, callable> option name => a hook `get_option` runs
     * once, after it has read the value and before it answers with it: what
     * another request does between this one's read and its next statement.
     */
    public static array $on_option_read = [];

    public static function read(string $what): void {
        self::$reads[$what] = (self::$reads[$what] ?? 0) + 1;
    }

    public static function reset(): void {
        self::$home = 'https://example.test/';
        self::$posts = [];
        self::$writes = [];
        self::$capabilities = [];
        self::$wpml_reads = true;
        self::$wpml_writes = true;
        self::$wpml_write_detaches = [];
        self::$wpml_write_unreadable = [];
        self::$wpml_group_unreadable = [];
        self::$wpml_write_lands_as = [];
        self::$next_trid = 900;
        self::$wpml_declines = false;
        self::$inserted = [];
        self::$updated = [];
        self::$meta = [];
        self::$insert_fails = null;
        self::$insert_returns_zero = false;
        self::$update_fails = null;
        self::$update_returns_zero = false;
        self::$post_read_fails = false;
        self::$row_override = [];
        self::$rows_gone = [];
        self::$cache_cleared = [];
        self::$cache_invalidation_suspended = false;
        self::$non_viewable_types = ['revision', 'nav_menu_item'];
        self::$update_throws = null;
        self::$next_post_id = 100;
        $GLOBALS['wpdb'] = new WpdbStub();
        self::$post_types = ['post', 'page', 'revision', 'nav_menu_item'];
        self::$active_languages = ['en' => ['code' => 'en'], 'de' => ['code' => 'de']];
        self::$options = [];
        self::$users = [7 => 'A Real Person'];
        self::$referer_valid = true;
        self::$referers_checked = [];
        self::$nonces_made = [];
        self::$transients = [];
        self::$reads = [];
        self::$meta_add_fails = [];
        self::$meta_added = [];
        self::$meta_rows = [];
        self::$on_lock = null;
        self::$meta_delete_fails = [];
        self::$meta_deleted = [];
        self::$permalinks = [];
        self::$on_claim = null;
        self::$meta_cache_on = false;
        self::$meta_cache = [];
        self::$query_cache_on = false;
        self::$query_cache = [];
        self::$on_option_read = [];
    }

    public static function add_post(int $id, string $post_type = 'page',
                                    ?string $language = null, ?int $trid = null,
                                    bool $wpml_knows = true,
                                    string $status = 'publish'): void {
        self::$posts[$id] = ['post_type' => $post_type, 'language' => $language,
                             'trid' => $trid, 'wpml_knows' => $wpml_knows,
                             'status' => $status];
    }

    /**
     * MARK A POST AS ONE THIS CONNECTOR PUBLISHED, i.e. inside the scope
     * `translation.link` reaches.
     *
     * Separate from `add_post` and never its default, deliberately: a post on
     * the site is not a post this plugin made, and a stub whose every post was
     * one would make the scope check unreachable while every test passed. The
     * value is what `/content` would have written -- the caller's identifier
     * for the piece -- and only its non-blankness is read.
     */
    /**
     * WORDPRESS REVISIONS, RESTORING AN EARLIER TEXT. Core's restore is an
     * ordinary `wp_update_post` back to the stored title and content, which
     * is all a rewrite's revision is a hash of.
     */
    public static function restore_revision(int $id, string $title, string $content): void {
        wp_update_post(['ID' => $id, 'post_title' => $title, 'post_content' => $content]);
    }

    public static function cadence_published(int $id, ?string $piece_id = null): void {
        self::$meta[$id][CadenceContentRequest::META] = $piece_id ?? 'piece-' . $id;
    }
}

/**
 * `$wpdb`, in the four things the plugin asks of it.
 *
 * ENOUGH TO SEE THE ORDER, which is the whole point: what is under test is
 * that the text a replacement is checked against is read under a row lock the
 * write then happens inside, rather than read once beforehand and trusted. A
 * stub cannot run two requests at once, so it does the next best thing --
 * `get_row` can be made to answer something `get_post` does not, which is
 * exactly the disagreement a concurrent edit produces.
 */
final class WpdbStub {

    /** The posts table's name, as WordPress exposes it. */
    public string $posts = 'wp_posts';

    /** The options table's name. */
    public string $options = 'wp_options';

    /** The post meta table's name. */
    public string $postmeta = 'wp_postmeta';

    /** What the last statement's error was, or blank. */
    public string $last_error = '';

    /** @var list<string> every statement, in the order it was issued */
    public array $log = [];

    /** A statement prefix `query` answers false to, as a failing one does. */
    public ?string $fails_on = null;

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $sql = preg_replace_callback('/%[ds]/', static fn (array $m): string => $m[0] === '%d'
                ? (string) (int) $arg : "'" . addslashes((string) $arg) . "'", $sql, 1);
        }
        return $sql;
    }

    /** False on error, and anything else on success -- which is all the plugin reads. */
    public function query(string $sql) {
        $this->log[] = $sql;
        if ($this->fails_on !== null && stripos($sql, $this->fails_on) === 0) {
            return false;
        }
        // THE OPTIONS TABLE'S `INSERT IGNORE`, modelled on what MySQL does
        // with the unique `option_name`: one row inserted and 1 answered, or
        // the row already there and 0 answered. Never an update.
        if (preg_match("/\\AINSERT IGNORE INTO `wp_options` .*VALUES \\('([^']*)', '([^']*)'/s", $sql, $m) === 1) {
            if (WpStub::$on_claim !== null) {
                $hook = WpStub::$on_claim;
                WpStub::$on_claim = null;
                $hook($m[1]);
            }
            if (array_key_exists($m[1], WpStub::$options)) {
                return 0;
            }
            WpStub::$options[$m[1]] = $m[2];
            return 1;
        }
        // A COMPARE-AND-DELETE on one option: 1 when the row held that value
        // and is gone, 0 when it held another or was not there.
        if (preg_match("/\\ADELETE FROM `wp_options` WHERE `option_name` = '([^']*)' AND `option_value` = '([^']*)'\\z/s",
                       $sql, $m) === 1) {
            $name = stripslashes($m[1]);
            if (array_key_exists($name, WpStub::$options) && (string) WpStub::$options[$name] === stripslashes($m[2])) {
                unset(WpStub::$options[$name]);
                return 1;
            }
            return 0;
        }
        return true;
    }

    /**
     * One column. Only the post meta lookup by exact key and value, over
     * every row the stub holds and never through the meta cache.
     */
    public function get_col(string $sql): array {
        $this->log[] = $sql;
        WpStub::read('wpdb:get_col');
        if (preg_match("/\\ASELECT `post_id` FROM `wp_postmeta` WHERE `meta_key` = '((?:[^'\\\\]|\\\\.)*)' AND `meta_value` = '((?:[^'\\\\]|\\\\.)*)'\\z/s",
                       $sql, $m) !== 1) {
            return [];
        }
        [$key, $value] = [stripslashes($m[1]), stripslashes($m[2])];
        $ids = [];
        foreach (WpStub::$meta as $id => $meta) {
            $rows = WpStub::$meta_rows[$id][$key] ?? (array_key_exists($key, $meta) ? [$meta[$key]] : []);
            foreach ($rows as $row) {
                if ($row === $value) {
                    $ids[] = (string) $id;
                }
            }
        }
        return $ids;
    }

    /** The row, or null -- for a row that is not there and for a failed read alike. */
    public function get_row(string $sql): ?object {
        $this->log[] = $sql;
        if (!preg_match('/WHERE ID = (\d+)/', $sql, $m)) {
            return null;
        }
        $id = (int) $m[1];
        if (WpStub::$on_lock !== null && stripos($sql, 'FOR UPDATE') !== false) {
            $hook = WpStub::$on_lock;
            WpStub::$on_lock = null;
            $hook();
        }
        if (!isset(WpStub::$posts[$id]) || in_array($id, WpStub::$rows_gone, true)) {
            return null;
        }
        $post = WpStub::$posts[$id];
        $over = WpStub::$row_override[$id] ?? [];
        return (object) [
            'post_title'   => $over['post_title']   ?? ($post['post_title']   ?? ''),
            'post_content' => $over['post_content'] ?? ($post['post_content'] ?? ''),
        ];
    }

    /** Every statement of a kind, so a test can assert the order of two of them. */
    public function statements(string ...$needles): array {
        return array_values(array_filter($this->log, static function (string $s) use ($needles): bool {
            foreach ($needles as $n) {
                if (stripos($s, $n) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }
}

function get_post_type(int $id) {
    return WpStub::$posts[$id]['post_type'] ?? false;
}

function get_post_status(int $id) {
    return isset(WpStub::$posts[$id]) ? (WpStub::$posts[$id]['status'] ?? 'publish') : false;
}

/**
 * The post row itself. WordPress hands back a WP_Post or, for an id this site
 * does not have, null -- and null is the whole reason anything asks: a caller
 * naming a post that is not here is talking about a different site.
 */
function get_post($post_id = null): ?WP_Post {
    WpStub::read('get_post');
    if (WpStub::$post_read_fails) {
        return null;
    }
    $id = is_int($post_id) ? $post_id : 0;
    if (!isset(WpStub::$posts[$id])) {
        return null;
    }
    $p = WpStub::$posts[$id];
    // ONLY ONCE THE CACHE HAS BEEN INVALIDATED does this read the same
    // overlay the locked row read already uses -- see `clean_post_cache`.
    // Before that, `get_post` answers from `$posts` alone, exactly as it
    // always has, which is what keeps every other test in this file honest.
    if (!empty(WpStub::$cache_cleared[$id])) {
        $p = array_merge($p, WpStub::$row_override[$id] ?? []);
    }
    return new WP_Post($id, $p['post_title'] ?? '', $p['post_content'] ?? '',
                       $p['post_status'] ?? 'draft', $p['post_type'] ?? 'post',
                       $p['post_password'] ?? '');
}

/**
 * STANDS IN FOR WORDPRESS'S OWN `clean_post_cache()`: invalidates this
 * process's cached copy of one post, so the next `get_post` for it answers
 * from the row rather than from what was read before. See
 * `WpStub::$cache_cleared`.
 */
function clean_post_cache(int $post_id): void {
    // THE DOCUMENTED NO-OP: real WordPress skips the invalidation entirely
    // while `wp_suspend_cache_invalidation()` is on, which is why a caller
    // that cares cannot rely on this call alone. See
    // `WpStub::$cache_invalidation_suspended`.
    if (WpStub::$cache_invalidation_suspended) {
        return;
    }
    WpStub::$cache_cleared[$post_id] = true;
}

/**
 * STANDS IN FOR WORDPRESS'S OWN `wp_cache_delete()`: unlike
 * `clean_post_cache`, it clears the id even while cache invalidation is
 * suspended -- it is a direct object-cache call, not one routed through the
 * invalidation machinery that flag turns off.
 */
function wp_cache_delete(int $id, string $group = ''): bool {
    if ($group === 'posts') {
        WpStub::$cache_cleared[$id] = true;
    }
    if ($group === 'post_meta') {
        unset(WpStub::$meta_cache[$id]);
    }
    return true;
}

/**
 * Mirrors core's `is_post_type_viewable()`: true for a registered type that
 * is public-facing. Modelled here as "registered, and not one of the
 * built-ins WordPress itself never gives a front end" -- see
 * `WpStub::$non_viewable_types`.
 */
function is_post_type_viewable(string $type): bool {
    return post_type_exists($type) && !in_array($type, WpStub::$non_viewable_types, true);
}

/** Single-value meta, including WordPress's own answer for meta that is not there. */
function get_post_meta(int $post_id, string $key = '', bool $single = false) {
    WpStub::read('get_post_meta');
    [$meta, $rows] = [WpStub::$meta, WpStub::$meta_rows];
    if (WpStub::$meta_cache_on) {
        WpStub::$meta_cache[$post_id] ??= [WpStub::$meta[$post_id] ?? [], WpStub::$meta_rows[$post_id] ?? []];
        [$meta, $rows] = [[$post_id => WpStub::$meta_cache[$post_id][0]], [$post_id => WpStub::$meta_cache[$post_id][1]]];
    }
    $value = $meta[$post_id][$key] ?? null;
    if ($single) {
        // `''`, not null and not false. A plugin reading this as "no value" by
        // truthiness cannot tell it from a meta value that is an empty string.
        return $value ?? '';
    }
    if (isset($rows[$post_id][$key])) {
        return $rows[$post_id][$key];
    }
    return $value === null ? [] : [$value];
}

/**
 * WHICH TESTS REACH WPML, recorded while they run.
 *
 * THE LINE THIS SUITE IS SPLIT ON. The stubs above answer the way the code
 * expects, so a test whose verdict rests on WPML's behaviour is checking this
 * file's beliefs about WPML and not WPML -- three of them are documented and
 * never observed (see the header of includes/class-cadence-link-request.php).
 * Those tests carry `#[Group('wpml')]` and run in their own lane; everything
 * else is the plugin's own refusals, which reach no WPML at all and are
 * therefore as true here as on a live site.
 *
 * THE BOUNDARY IS THE CAPABILITY, NOT A LIST OF TEST NAMES: recorded when a
 * WPML hook is actually reached, so a test that gets there through a private
 * helper, a shared setUp or three frames of production code is recorded the
 * same as one that names the hook itself. Any hook in WPML's `wpml_`
 * namespace counts, present or future -- the prefix is the namespace WPML
 * publishes under, not a denylist of the four spellings this file happens to
 * implement.
 *
 * Armed only when `CADENCE_WPML_BOUNDARY_LOG` names a file, which
 * tests/wpml-boundary.php sets; an ordinary run records nothing and pays for
 * nothing. The log is written at shutdown so a fatal error mid-run still
 * leaves whatever was measured behind.
 */
final class WpmlBoundary {
    /** The namespace WPML's hooks live in. */
    public const PREFIX = 'wpml_';

    /**
     * A reach nothing could attribute to a test -- a hook fired from a data
     * provider, a shutdown function, or the bootstrap itself. Recorded under
     * its own key rather than dropped: an unattributable crossing is still a
     * crossing, and a recorder that silently discarded it would report a clean
     * lane it had not measured.
     */
    public const UNATTRIBUTED = '(no test frame)';

    /** @var array<string, list<string>> `Class::method` => the wpml_ hooks it reached */
    public static array $crossings = [];

    private static ?string $log = null;

    public static function arm(): void {
        $path = getenv('CADENCE_WPML_BOUNDARY_LOG');
        if ($path === false || $path === '') {
            return;
        }
        self::$log = $path;
        register_shutdown_function(static function (): void {
            // THE PREFIX TRAVELS WITH THE MEASUREMENT. The reader reports
            // what was keyed on rather than repeating the constant, so a
            // second copy of it cannot drift away from the one that ran.
            file_put_contents(
                (string) self::$log,
                json_encode(['prefix' => self::PREFIX, 'crossings' => self::$crossings],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );
        });
    }

    /** Called by every stub below that answers for WPML, before it answers. */
    public static function reached(string $hook): void {
        if (self::$log === null || !str_starts_with($hook, self::PREFIX)) {
            return;
        }
        $test = self::current_test();
        if (!in_array($hook, self::$crossings[$test] ?? [], true)) {
            self::$crossings[$test][] = $hook;
        }
    }

    /**
     * The test method on the stack. PHPUnit's own frames sit between it and
     * here, and production code sits above it, so the first frame that is a
     * `test*` method on a `*Test` class is the one that asked -- the same
     * identity `--list-tests` prints, so the two sets can be compared.
     */
    private static function current_test(): string {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';
            $method = $frame['function'] ?? '';
            if (str_ends_with($class, 'Test') && str_starts_with($method, 'test')) {
                return $class . '::' . $method;
            }
        }
        return self::UNATTRIBUTED;
    }
}

WpmlBoundary::arm();

/**
 * WPML's read filter. Returns null when the post is in no group, which is a
 * READING; a post this stub does not know returns false, which is not.
 */
function apply_filters(string $hook, $value, ...$args) {
    // BEFORE the listener question, not after: "WPML is not installed here" is
    // an answer about WPML, and a test that leans on it has crossed the line
    // just as surely as one that reads a trid back.
    WpmlBoundary::reached($hook);
    if (str_starts_with($hook, 'wpml_')) {
        WpStub::read('wpml');
    }
    // EXACTLY WHAT WORDPRESS DOES WITH NO LISTENER: return the default,
    // unchanged and without complaint. Not an error, not null -- the value the
    // caller itself supplied, which is why an absent WPML is invisible to any
    // code that reads the answer without asking whether anyone answered.
    if (!WpStub::$wpml_reads || WpStub::$wpml_declines) {
        return $value;
    }
    if ($hook === 'wpml_active_languages') {
        // WPML's own map: language code => details. A site whose hooks are
        // present but which has configured no languages answers with an empty
        // map, which is NOT the same as "every language is fine".
        return WpStub::$active_languages;
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
    if ($hook === 'wpml_get_element_translations') {
        $trid = $args[0] ?? null;
        $type = (string) ($args[1] ?? '');
        // NEITHER OF THESE IS AN EMPTY GROUP. Both mean "WPML did not answer",
        // and the create path must not read either as "nothing would be
        // detached" -- so they return the caller's default untouched.
        if (!is_int($trid) || in_array($trid, WpStub::$wpml_group_unreadable, true)) {
            return $value;
        }
        // THE STATUS FILTER, WHICH IS NOT A DETAIL. Asked with three arguments,
        // WPML answers about PUBLISHED posts only outside an admin request, and
        // a REST request is outside one. Cadence places DRAFTS, so a caller
        // that omits `all_statuses` is told a group of drafts is empty and goes
        // on to detach them. Measured live on WPML 5.0.1 (see
        // `members_outside_plan`); modelled here so the three-argument call
        // cannot pass this suite.
        $all_statuses = (bool) ($args[3] ?? false);
        $out = [];
        foreach (WpStub::$posts as $id => $p) {
            if (($p['trid'] ?? null) !== $trid || 'post_' . $p['post_type'] !== $type) {
                continue;
            }
            if (!($p['wpml_knows'] ?? true)) {
                continue;
            }
            if (!$all_statuses && ($p['status'] ?? 'publish') !== 'publish') {
                continue;
            }
            // KEYED BY LANGUAGE, AND EVERY SCALAR A STRING, because that is
            // what WPML hands back: measured on WPML 5.0.1, where `element_id`
            // comes out as `string(1) "5"`. A stub answering with integers
            // would let a strict `in_array` in the caller pass here and fail
            // on a real site.
            $out[(string) $p['language']] = (object) [
                'trid'                 => (string) $trid,
                'translation_id'       => (string) $id,
                'language_code'        => (string) $p['language'],
                'element_id'           => (string) $id,
                'source_language_code' => null,
                'element_type'         => $type,
                'original'             => '1',
            ];
        }
        return $out;
    }
    return $value;
}

function do_action(string $hook, ...$args): void {
    WpmlBoundary::reached($hook);
    if (!WpStub::$wpml_writes) {
        return;   // nothing listening; the call is a no-op, as on a real site
    }
    if ($hook === 'wpml_set_element_language_details') {
        $d = $args[0] ?? [];
        WpStub::$writes[] = $d;

        // AND THE WRITE CHANGES WHAT A LATER READ RETURNS. Recording the call
        // and leaving the site's answers alone modelled a writer nothing can
        // observe, which is the one shape a report read back from the site can
        // never be tested against: every read-back would answer with the state
        // from before the write.
        $id = (int) ($d['element_id'] ?? 0);
        if (!isset(WpStub::$posts[$id])) {
            return;
        }
        WpStub::$posts[$id]['language'] = WpStub::$wpml_write_lands_as[$id]
            ?? ($d['language_code'] ?? null);
        WpStub::$posts[$id]['wpml_knows'] = !in_array($id, WpStub::$wpml_write_unreadable, true);
        if (in_array($id, WpStub::$wpml_write_detaches, true)) {
            WpStub::$posts[$id]['trid'] = null;   // the relation is gone
            return;
        }
        // WPML's own documentation for this action: *"If set to FALSE it will
        // create a new trid for the element causing any potential translation
        // relations to/from it to disappear."* A new one PER ELEMENT -- the
        // action is told about one element and has no way to know the call is
        // one of a set.
        WpStub::$posts[$id]['trid'] = $d['trid'] ?? null;
        if (WpStub::$posts[$id]['trid'] === null) {
            WpStub::$posts[$id]['trid'] = WpStub::$next_trid++;
        }
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
    WpmlBoundary::reached($hook);
    WpStub::read('wpml');
    return $hook === 'wpml_element_language_details' ? WpStub::$wpml_reads : false;
}

function has_action(string $hook, $callback = false) {
    WpmlBoundary::reached($hook);
    WpStub::read('wpml');
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
        public string $post_type = 'post',
        public string $post_password = ''
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
    if (WpStub::$update_throws !== null) {
        // A `save_post` hook in a plugin this one does not control, throwing.
        // It matters because it leaves this code mid-transaction.
        throw new RuntimeException(WpStub::$update_throws);
    }
    if (WpStub::$update_returns_zero) {
        return 0;
    }
    if (WpStub::$update_fails !== null) {
        return $wp_error
            ? new WP_Error('db_update_error', WpStub::$update_fails)
            : 0;
    }
    WpStub::$updated[] = $postarr;
    // Recorded on the SAME timeline as the plugin's own statements. The real
    // one issues an UPDATE on this connection, so where it falls relative to
    // a transaction the plugin opened is a fact a test can read -- and the
    // whole question here is whether the write happens inside the lock.
    $GLOBALS['wpdb']->log[] = 'UPDATE (wp_update_post)';
    $id = $postarr['ID'] ?? 0;
    // REAL `wp_update_post()` FILLS IN ANY FIELD THE CALLER DID NOT GIVE FROM
    // ITS OWN CACHED READ OF THE POST (`get_post($ID, ARRAY_A)`), then writes
    // the whole merged row. Modelled here because that merge is the exact
    // mechanism `clean_post_cache` exists to close: a stale `get_post` hands
    // back a field the caller never touched, and it lands back in the row
    // over whatever a concurrent edit put there.
    $cached = isset(WpStub::$posts[$id]) ? get_post($id) : null;
    foreach (['post_title', 'post_content', 'post_status', 'post_password'] as $field) {
        if (!isset(WpStub::$posts[$id])) {
            break;
        }
        if (array_key_exists($field, $postarr)) {
            WpStub::$posts[$id][$field] = $postarr[$field];
        } elseif ($cached !== null) {
            WpStub::$posts[$id][$field] = $cached->$field;
        }
    }
    return $id;
}

/**
 * `add_post_meta` AS WORDPRESS BEHAVES: with `$unique` it refuses a key the
 * post already carries, and it UNSLASHES what it is given (`add_metadata`
 * calls `wp_unslash`), which is why a caller passes its value through
 * `wp_slash` first. Modelled with `stripslashes` here rather than by changing
 * the `wp_unslash` stub, which the settings screen's tests read as identity.
 */
function add_post_meta(int $post_id, string $key, $value, bool $unique = false) {
    unset(WpStub::$meta_cache[$post_id]);
    if (in_array($key, WpStub::$meta_add_fails, true)) {
        return false;
    }
    if ($unique && array_key_exists($key, WpStub::$meta[$post_id] ?? [])) {
        return false;
    }
    WpStub::$meta_added[] = [$post_id, $key, $value];
    $value = is_string($value) ? stripslashes($value) : $value;
    // A SECOND ROW UNDER THE SAME KEY, never an overwrite: a single read
    // still answers the first row, and a list read answers them all.
    if (!$unique && array_key_exists($key, WpStub::$meta[$post_id] ?? [])) {
        WpStub::$meta_rows[$post_id][$key] ??= [WpStub::$meta[$post_id][$key]];
        WpStub::$meta_rows[$post_id][$key][] = $value;
        return 1;
    }
    WpStub::$meta[$post_id][$key] = $value;
    return 1;
}

function delete_post_meta(int $post_id, string $key, $value = ''): bool {
    unset(WpStub::$meta_cache[$post_id]);
    if (in_array($key, WpStub::$meta_delete_fails, true)) {
        return false;
    }
    $had = array_key_exists($key, WpStub::$meta[$post_id] ?? []);
    unset(WpStub::$meta[$post_id][$key], WpStub::$meta_rows[$post_id][$key]);
    if ($had) {
        WpStub::$meta_deleted[] = [$post_id, $key];
    }
    return $had;
}

function wp_json_encode($data, int $options = 0, int $depth = 512) {
    return json_encode($data, $options, $depth);
}

function wp_slash($value) {
    return is_string($value) ? addslashes($value) : $value;
}

function home_url(string $path = ''): string {
    return rtrim(WpStub::$home, '/') . '/' . ltrim($path, '/');
}

function wp_parse_url(string $url, int $component = -1) {
    return parse_url($url, $component);
}

/**
 * `url_to_postid` AS CORE WRITES IT, in the parts this plugin relies on: a
 * host other than the site's answers 0; `?p=`, `?page_id=` and
 * `?attachment_id=` answer their id whether or not a post has it; a
 * permalink answers the post it is the path of, and anything else 0.
 */
function url_to_postid(string $url): int {
    WpStub::read('url_to_postid');
    $host = parse_url($url, PHP_URL_HOST);
    if (is_string($host) && $host !== parse_url(home_url(), PHP_URL_HOST)) {
        return 0;
    }
    if (preg_match('#[?&](p|page_id|attachment_id)=(\d+)#', $url, $m) === 1 && (int) $m[2] > 0) {
        return (int) $m[2];
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    return WpStub::$permalinks[$path] ?? 0;
}

function delete_option(string $name): bool {
    $had = array_key_exists($name, WpStub::$options);
    unset(WpStub::$options[$name]);
    return $had;
}

function update_post_meta(int $post_id, string $key, $value): bool {
    unset(WpStub::$meta_cache[$post_id]);
    unset(WpStub::$meta_rows[$post_id][$key]);
    WpStub::$meta[$post_id][$key] = $value;
    return true;
}

/**
 * The post types this site has registered, name => name -- WordPress's own
 * shape for `get_post_types()` called with no arguments.
 */
function get_post_types(): array {
    return array_combine(WpStub::$post_types, WpStub::$post_types);
}

/**
 * Every status name this stub knows, standing in for `get_post_stati()`:
 * WordPress's built-ins, `trash` and `auto-draft` included -- the two
 * `'any'` leaves out.
 */
function get_post_stati(): array {
    $names = ['publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit'];
    return array_combine($names, $names);
}

/** Only the meta_key/meta_value/fields=ids shape the plugin asks for. */
function get_posts(array $args = []): array {
    WpStub::read('get_posts');
    $cache_key = serialize($args);
    $cached = WpStub::$query_cache_on && ($args['cache_results'] ?? true) !== false;
    if ($cached && array_key_exists($cache_key, WpStub::$query_cache)) {
        return WpStub::$query_cache[$cache_key];
    }
    $key = $args['meta_key'] ?? null;
    $value = $args['meta_value'] ?? null;
    $want_status = $args['post_status'] ?? 'publish';
    $want_type = $args['post_type'] ?? null;
    // `'any'` is WORDPRESS'S OWN EXCLUSION, not "every status": a status
    // registered `exclude_from_search` is left out of it, and `trash` and
    // `auto-draft` are the built-ins that are. A caller that means every
    // status, trash included, has to name the explicit list -- `'any'` will
    // not get it there, here or on a real site.
    $any_excludes = ['trash', 'auto-draft'];
    $found = [];
    foreach (WpStub::$meta as $id => $meta) {
        // Exact, never a prefix: `piece-1` must not answer for `piece-10`.
        if (!array_key_exists($key, $meta) || $meta[$key] !== $value) {
            continue;
        }
        $type = WpStub::$posts[$id]['post_type'] ?? 'post';
        if (is_array($want_type)) {
            if (!in_array($type, $want_type, true)) {
                continue;
            }
        } elseif ($want_type !== null && $want_type !== 'any' && $type !== $want_type) {
            continue;
        }
        $status = WpStub::$posts[$id]['post_status'] ?? 'draft';
        if ($want_status === 'any') {
            if (in_array($status, $any_excludes, true)) {
                continue;
            }
        } elseif (is_array($want_status)) {
            if (!in_array($status, $want_status, true)) {
                continue;
            }
        } elseif ($status !== $want_status) {
            continue;
        }
        $found[] = $id;
    }
    if ($cached) {
        WpStub::$query_cache[$cache_key] = $found;
    }
    return $found;
}

/**
 * A WP_User, or FALSE for an id this site has no user for -- which is the half
 * that matters: `get_userdata(0)` is false on every WordPress, and 0 is what
 * `post_author` takes when nothing sets it.
 */
function get_userdata(int $id) {
    return isset(WpStub::$users[$id])
        ? (object) ['ID' => $id, 'display_name' => WpStub::$users[$id]]
        : false;
}

/**
 * The three transient calls, in what the admin screen asks of them. No clock:
 * the expiry is WordPress's business and a stub that implemented it would let a
 * test pass by waiting rather than by reading, while the property under test is
 * that the READ deletes.
 */
function set_transient(string $key, $value, int $expiry = 0): bool {
    WpStub::$transients[$key] = $value;
    return true;
}

function get_transient(string $key) {
    return array_key_exists($key, WpStub::$transients) ? WpStub::$transients[$key] : false;
}

function delete_transient(string $key): bool {
    $had = array_key_exists($key, WpStub::$transients);
    unset(WpStub::$transients[$key]);
    return $had;
}

function get_option(string $name, $default = false) {
    WpStub::read('get_option:' . $name);
    $value = array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
    if (isset(WpStub::$on_option_read[$name])) {
        $hook = WpStub::$on_option_read[$name];
        unset(WpStub::$on_option_read[$name]);
        $hook();
    }
    return $value;
}

function update_option(string $name, $value, $autoload = null): bool {
    WpStub::$options[$name] = $value;
    return true;
}

function esc_html(string $s): string { return $s; }
function __(string $s, string $d = ''): string { return $s; }
function esc_url(string $s): string { return $s; }
function esc_attr(string $s): string { return $s; }
// Not the identity the escapers above are: it keeps only the allowed element
// names, so an allow-list that drops markup the caller emits shows in a test.
function wp_kses(string $s, array $allowed): string {
    return strip_tags($s, array_keys($allowed));
}

/**
 * TWO EXCEPTIONS THAT STAND IN FOR CONTROL FLOW THAT WOULD OTHERWISE END THE
 * PROCESS. `wp_die` never returns on a real site, and `wp_safe_redirect` is
 * always followed by a bare `exit` at its call site -- a stub that recorded
 * either call and returned would let a test see code that runs AFTER a point
 * nothing ever reaches outside a test. Throwing instead stops execution in
 * exactly the same place a real death or a real redirect would, and hands the
 * test the one fact it needs to tell the two apart.
 */
final class CadenceTestDied extends RuntimeException {}
final class CadenceTestRedirected extends RuntimeException {
    public function __construct(public readonly string $location) {
        parent::__construct($location);
    }
}

function wp_die($message = '', $title = '', $args = []): void {
    throw new CadenceTestDied(is_string($message) ? $message : 'died');
}

/**
 * Fails exactly as the real one does: on an invalid nonce it calls `wp_die`
 * rather than returning a falsy value, so a caller that never checks a return
 * value is not thereby skipping the check. `WpStub::$referer_valid` is the
 * one thing a test controls about it.
 */
function check_admin_referer($action = -1, $query_arg = '_wpnonce') {
    WpStub::$referers_checked[] = $action;
    if (!WpStub::$referer_valid) {
        wp_die('The link you followed has expired.');
    }
    return 1;
}

function wp_safe_redirect(string $location, int $status = 302): void {
    throw new CadenceTestRedirected($location);
}

function wp_unslash($value) {
    return is_array($value) ? array_map('wp_unslash', $value) : $value;
}

function sanitize_text_field($str): string {
    return trim((string) $str);
}

function add_query_arg(array $args, string $url): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function admin_url(string $path = ''): string {
    return 'https://example.test/wp-admin/' . $path;
}

function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true): string {
    $field = '<input type="hidden" name="' . $name . '" value="stub-nonce">';
    if ($echo) {
        echo $field;
    }
    return $field;
}

function wp_nonce_url(string $url, $action = -1, string $name = '_wpnonce'): string {
    WpStub::$nonces_made[] = $action;
    return add_query_arg([$name => 'stub-nonce'], $url);
}

function wp_dropdown_users(array $args = []): ?string {
    $html = '<select name="' . ($args['name'] ?? 'user') . '"></select>';
    if ($args['echo'] ?? true) {
        echo $html;
        return null;
    }
    return $html;
}

/** Reuses `WpStub::$users` -- the same map `get_userdata` answers from. */
function get_the_author_meta(string $field, int $user_id) {
    return $field === 'display_name' ? (WpStub::$users[$user_id] ?? '') : '';
}

/**
 * The screen's own default byline. Whoever is looking at it holds
 * `manage_options` -- that is what reached it -- and is a real user of this
 * site by construction, so this stub answers with one: the id `CadenceKey`'s
 * own stub map already has a name for.
 */
/** WordPress's localised date formatter; the format is all a test needs. */
function date_i18n(string $format, ?int $timestamp = null): string {
    return date($format, $timestamp ?? time());
}

function get_current_user_id(): int {
    return 7;
}

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
    /** @var array<string, list<callable>> */
    public static array $filters = [];
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

function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool {
    WpHooks::$filters[$hook][] = $cb;
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

/**
 * `get_json_params` and `get_header`, which is all the routes ask of a request.
 * The header matters now: it is where the connector's own key is presented, and
 * WordPress returns null rather than '' for a header that was not sent.
 */
final class WP_REST_Request {

    /**
     * "THIS CALLER PRESENTED NOTHING", said explicitly.
     *
     * The constructor hands a headerless request with a body the suite's own
     * exempt key, so that route tests written before the attestation existed
     * go on reaching the handler they are about. A test probing a
     * `permission_callback` means the opposite -- nobody presented anything --
     * and has to be able to say so, because for that one the credential IS the
     * thing under test. A header name no code reads: present, so nothing is
     * injected; meaningless, so nothing is authorised.
     */
    public const NO_KEY = ['x-cadence-test-no-credentials' => '1'];
    /** @param array<string, string> $headers */
    public function __construct(private $json, private array $headers = []) {
        // A REQUEST WITH NO HEADERS AT ALL IS A LEGACY CALLER, and this stub
        // gives it the one credential those tests always assumed they had: a
        // connector key carrying the UNSIGNED-PUBLISH EXEMPTION. Not a signed
        // header -- a test that supplied itself a valid signature by default
        // would make every route test pass the attestation without ever
        // composing one, which is the guard testing itself. The exemption is
        // what an un-upgraded site actually has, these tests predate the
        // header, and the ones that are ABOUT attestation pass headers.
        // AND ONLY FOR A REQUEST THAT CARRIES A BODY. `new WP_REST_Request(null)`
        // and `new WP_REST_Request([])` are this file's "nobody presented
        // anything" probes against the permission callbacks, and handing those
        // a credential would make a test that asserts an empty request is
        // refused assert nothing at all.
        if ($this->headers === [] && is_array($this->json) && $this->json !== []) {
            CadenceAttest::exempt_key();
            $this->headers = [strtolower(CadenceKey::HEADER) => CadenceAttest::secret()];
        }
    }
    public function get_json_params() { return $this->json; }
    public function get_header(string $name) {
        // WordPress normalises header names; so does this, so a test naming the
        // header as it travels on the wire asks the same question the route does.
        return $this->headers[strtolower($name)] ?? null;
    }
}

require_once __DIR__ . '/../includes/class-cadence-key.php';
require_once __DIR__ . '/../includes/class-cadence-attestation.php';
require_once __DIR__ . '/../includes/class-cadence-language-declaration.php';

$GLOBALS['wpdb'] = new WpdbStub();

require_once __DIR__ . '/../includes/class-cadence-link-request.php';
require_once __DIR__ . '/../includes/class-cadence-rest-route.php';
require_once __DIR__ . '/../includes/class-cadence-revision.php';
require_once __DIR__ . '/../includes/class-cadence-content-request.php';
require_once __DIR__ . '/../includes/class-cadence-admin.php';
require_once __DIR__ . '/../includes/class-cadence-replace-request.php';
require_once __DIR__ . '/../includes/class-cadence-adopt-request.php';

/**
 * THE SIGNING SIDE, IN THE TEST SUITE ONLY.
 *
 * The connector never signs anything -- it verifies -- so no production code
 * here composes a valid header, and a suite that could build only INVALID ones
 * would test the five refusals and never the acceptance. This is the sending
 * service's half reduced to what a test needs: a keypair, and the header the
 * sending service sends.
 *
 * IT DOES NOT COMPOSE THE MATERIAL ITSELF. It calls
 * `CadenceAttestation::material`, so every helper-signed header agrees with the
 * verifier BY CONSTRUCTION -- which means this file proves nothing about the
 * canonical form, and `AttestationTest`'s three contract vectors, signed
 * elsewhere by a key this file never sees, are the only thing standing between
 * this suite and a self-consistent wrong layout. Deliberate: the vectors are
 * the oracle and this is a convenience, and a convenience that re-derived the
 * bytes would be a second implementation nobody checks.
 */
final class CadenceAttest {

    /** The connector key the suite's signed requests authenticate as. */
    public const KEY_ID = 'ca11ab1e0000key1';

    /** The attestation key id those requests name. 16 lowercase hex. */
    public const KID = 'a1b2c3d4e5f60718';

    /** @var array<string, array{pk: string, sk: string}> */
    private static array $pairs = [];

    /**
     * One keypair per connector key per suite run, so no test can pass
     * against a pinned signature, and no public key is on two connector keys,
     * which the key store refuses.
     */
    public static function pair(?string $key_id = null): array {
        $key_id ??= self::KEY_ID;
        if (!isset(self::$pairs[$key_id])) {
            $keypair = sodium_crypto_sign_keypair();
            self::$pairs[$key_id] = ['pk' => sodium_crypto_sign_publickey($keypair),
                                     'sk' => sodium_crypto_sign_secretkey($keypair)];
        }
        return self::$pairs[$key_id];
    }

    public static function public_key_base64(?string $key_id = null): string {
        return base64_encode(self::pair($key_id)['pk']);
    }

    /** The signed field set a body reduces to, with the alias resolved as the routes do. */
    public static function fields(string $route, array $body): array {
        if (!isset($body['piece_id']) && isset($body['external_id'])) {
            $body['piece_id'] = $body['external_id'];
        }
        $out = [];
        foreach (CadenceAttestation::FIELDS[$route] as $name) {
            // AN OPTIONAL FIELD IS SIGNED WHEN THE BODY CARRIES IT, and its
            // boolean is rendered the one way the route renders it.
            if (strncmp($name, CadenceAttestation::OPTIONAL, 1) === 0) {
                $name = substr($name, 1);
                if (!array_key_exists($name, $body)) {
                    continue;
                }
                $value = $body[$name];
                $out[$name] = is_bool($value) ? ($value ? 'true' : 'false')
                    : (is_string($value) ? $value : '');
                continue;
            }
            $value = $body[$name] ?? '';
            // A BODY THE ROUTE WILL REFUSE AS `bad_request` STILL GETS A
            // HEADER. The helper is called before `run`, so it sees bodies
            // whose fields are arrays or missing; it signs the empty string
            // for those rather than throwing, because what those tests assert
            // is that validation refuses FIRST and the header is never read.
            if ($name === CadenceAttestation::INTEGER_FIELD) {
                // The composer refuses a string here, deliberately. A body
                // carrying one is `bad_replacement` before the header is ever
                // read, so the helper signs 0 rather than throwing on its way
                // to a refusal that is not about the signature.
                $out[$name] = is_int($value) ? $value : 0;
                continue;
            }
            $out[$name] = (is_string($value) || is_int($value)) ? $value : '';
        }
        return $out;
    }

    /** Unpadded base64url of an Ed25519 signature over the 32 RAW digest bytes. */
    public static function sign(string $material, ?string $sk = null): string {
        $signature = sodium_crypto_sign_detached(
            CadenceAttestation::digest($material), $sk ?? self::pair()['sk']);
        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * The header the sending service would send for this body, with the
     * public key already pasted onto the connector key it names. Installing
     * on the way past is what keeps every publish test from having to know a
     * verifying key exists.
     */
    public static function header(string $route, array $fields, ?string $key_id,
                                  ?string $kid = null): string {
        $kid = $kid ?? self::KID;
        if ($key_id !== null) {
            self::install($key_id, $kid);
        }
        return 'v1 ' . $kid . ' ' . self::sign(CadenceAttestation::material($route, $fields),
                                               self::pair($key_id)['sk']);
    }

    /**
     * The header the sending service would send for one LINK PLAN.
     *
     * `link_fields` and not a second reduction here: the sort, the boolean
     * rendering and the member framing have ONE implementation and this helper
     * is a convenience over it, exactly as `fields` is for the flat routes.
     * `AttestationTest`'s four link vectors, signed by the sending service,
     * are what prove that implementation right.
     */
    public static function link_header(array $plan, ?string $key_id,
                                       ?string $kid = null): string {
        $fields = CadenceAttestation::link_fields($plan);
        if (!is_array($fields)) {
            // A PLAN THE ROUTE WILL REFUSE AS `bad_plan` STILL GETS A HEADER.
            // The helper is called before `run`, so it sees plans with no
            // rendering at all; it signs the empty group rather than throwing,
            // because what those tests assert is that the plan is refused FIRST
            // and this header is never read.
            $fields = ['trid' => '', 'create_group' => 'false', 'piece_id' => ''];
        }
        return self::header('/translation-group', $fields, $key_id, $kid);
    }

    /** The presented secret for the suite's own connector key. */
    public static function secret(): string {
        self::install(self::KEY_ID);
        return self::KEY_ID . '.x';
    }

    /**
     * The suite's key, carrying the unsigned-publish exemption.
     *
     * What an un-upgraded site has, and the ONLY thing the exemption covers:
     * an absent header. A test that sends a BAD header to this key is refused
     * exactly as it would be on a key without it, which is what
     * `AttestationTest` pins.
     */
    public static function exempt_key(string $key_id = self::KEY_ID): string {
        self::install($key_id);
        CadenceKey::set_unsigned_ok($key_id, true, 1);
        return $key_id;
    }

    /** Put a connector key record on the site, carrying this public key. */
    public static function install(string $key_id, ?string $kid = null): void {
        $keys = get_option(CadenceKey::OPTION, []);
        if (!is_array($keys)) {
            $keys = [];
        }
        if (!isset($keys[$key_id])) {
            $keys[$key_id] = ['label' => 'test', 'hash' => hash('sha256', 'x'),
                              'caps' => CadenceKey::CAPABILITIES, 'author' => 1,
                              'created' => 0, 'revoked_at' => null];
            update_option(CadenceKey::OPTION, $keys);
        }
        $kid = $kid ?? self::KID;
        foreach (CadenceKey::verify_keys($key_id) as $record) {
            if (($record['kid'] ?? null) === $kid) {
                return;
            }
        }
        CadenceKey::add_verify_key($key_id, $kid, self::public_key_base64($key_id));
    }
}
