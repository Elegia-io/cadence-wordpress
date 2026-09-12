<?php
/**
 * How a human issues and withdraws a key.
 *
 * A THIN VIEW OVER `CadenceKey`, on purpose. Every decision -- what a key may
 * grant, what is stored, what revocation does -- lives in that class, where the
 * test suite can reach it without a WordPress. What is here is the screen: read
 * the form, call the model, print the answer. Anything this file decided by
 * itself would be a decision nothing runs a test against.
 *
 * Settings -> Cadence Connector. `manage_options` and a nonce, because a screen
 * that mints a publishing credential is worth exactly as much as the weakest
 * way of reaching it.
 *
 * @package cadence-connector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceAdmin {

    public const PAGE   = 'cadence-connector';
    public const ACTION = 'cadence_connector_keys';

    /**
     * WHERE A JUST-ISSUED SECRET WAITS FOR THE REDIRECT TO LAND, per user.
     *
     * IT USED TO TRAVEL AS `?issued=<the key>`. A secret in a query string is
     * in the web server's access log, in the browser's history, and in the
     * `Referer` of whatever the next click asks for -- all of it written down
     * by parties this plugin does not control, and none of it undone by the
     * screen showing the key only once. Brevity is not confidentiality.
     *
     * KEYED BY THE USER AND NOT BY A HANDLE IN THE URL. A handle would be a
     * bearer token for the secret, which is the same leak one indirection out;
     * whoever issued the key is the one who reads it back, and reading takes
     * the `manage_options` session that issued it. Deleted on the first read
     * and expiring on its own, so a secret nobody collected does not sit in
     * the options table until someone does.
     */
    private const STASH  = 'cadence_connector_issued_';

    /** How long an uncollected secret waits. One redirect, not one session. */
    private const STASH_TTL = 60;

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    public static function menu(): void {
        add_options_page('Cadence Connector', 'Cadence Connector', 'manage_options',
                         self::PAGE, [self::class, 'screen']);
    }

    /** The form post: issue one, or revoke one. Nothing else. */
    public static function handle(): void {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to manage Cadence connector keys.', '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        $error  = '';
        $post   = wp_unslash($_POST);
        if (($post['do'] ?? '') === 'revoke') {
            CadenceKey::revoke(sanitize_text_field((string) ($post['id'] ?? '')));
        } else {
            $capabilities = array_values(array_intersect(
                CadenceKey::CAPABILITIES,
                array_map('sanitize_text_field', (array) ($post['caps'] ?? []))
            ));
            // THE PUBLISH SCOPE, SPLIT AND HANDED OVER UNJUDGED. Blank is
            // null -- "any registered type", the key this plugin issued before
            // the field existed -- and anything else is the list as typed.
            // Whether a name in it is a post type this site registers is
            // `CadenceKey`'s question, asked of the site with
            // `post_type_exists`; deciding it here would be a decision nothing
            // runs a test against, and dropping an unknown name silently would
            // issue a key narrower than the operator asked for without saying
            // so. A typo comes back as the error this screen prints.
            $typed = sanitize_text_field((string) ($post['post_types'] ?? ''));
            $post_types = trim($typed) === ''
                ? null
                : array_values(array_filter(array_map('trim', explode(',', $typed)),
                                            static fn (string $t): bool => $t !== ''));
            // The author goes through as it was posted. Whether it names a
            // user this site has is `CadenceKey`'s question, not this file's.
            $result = CadenceKey::issue(sanitize_text_field((string) ($post['label'] ?? '')), $capabilities,
                                        sanitize_text_field((string) ($post['author'] ?? '')),
                                        $post_types);
            if (is_string($result)) {
                $error = $result;
            } else {
                // SHOWN ONCE, ON THE NEXT RENDER, AND NOWHERE AFTER -- and
                // NOT in the URL that gets there. Not mailed, not logged:
                // what the site keeps is a hash, and a key nobody wrote down
                // is a key that gets revoked and reissued, which is the
                // outcome this screen wants anyway.
                set_transient(self::STASH . get_current_user_id(), $result['secret'],
                              self::STASH_TTL);
            }
        }
        // NO `issued` ARGUMENT AT ALL, not even a flag: the screen asks the
        // stash whether there is a secret to show, so there is nothing here for
        // a log or a `Referer` to carry. `error` is not a secret and stays.
        wp_safe_redirect(add_query_arg(array_filter([
            'page'   => self::PAGE,
            'error'  => $error,
        ]), admin_url('options-general.php')));
        exit;
    }

    public static function screen(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>Cadence Connector</h1>';
        if (!empty($_GET['error'])) {
            echo '<div class="notice notice-error"><p>'
                . esc_html(sanitize_text_field(wp_unslash((string) $_GET['error']))) . '</p></div>';
        }
        // TAKEN, NOT READ: the stash is deleted in the same call that answers
        // it, so a reload of this screen shows the key once and never again --
        // which is what the old `?issued=` could not promise, since the URL a
        // reload re-sends still had the key in it.
        $issued = self::take_issued();
        if ($issued !== '') {
            echo '<div class="notice notice-warning"><p>Copy this key now; it is not shown again.</p><p><code>'
                . esc_html($issued) . '</code></p></div>';
        }
        echo '<table class="widefat"><thead><tr><th>Label</th><th>Id</th><th>Grants</th><th>Publishes in</th><th>Byline</th><th>State</th><th></th></tr></thead><tbody>';
        foreach (CadenceKey::all() as $id => $record) {
            echo '<tr><td>' . esc_html((string) $record['label']) . '</td>'
                . '<td><code>' . esc_html($id) . '</code></td>'
                . '<td>' . esc_html(implode(', ', $record['caps'])) . '</td>'
                // A KEY THAT NAMES NO POST TYPE says so here, and the row reads
                // as what it is: a key that may create in any type this site
                // registers. Keys issued before the field existed are all of
                // them, and re-issuing is the fix -- the same shape as the
                // byline below.
                . '<td>' . (isset($record['post_types'])
                    ? esc_html(implode(', ', (array) $record['post_types']))
                    : 'any type — re-issue to scope') . '</td>'
                // A KEY ISSUED BEFORE KEYS CARRIED A BYLINE says so here.
                // Posts it creates have no author, as they always have; this
                // is the only place that fact is visible, and re-issuing the
                // key is the fix.
                . '<td>' . (isset($record['author'])
                    // Empty for a user deleted since: the id is then what
                    // there is to show, and blank would read as no byline.
                    ? esc_html(get_the_author_meta('display_name', (int) $record['author'])
                               ?: '#' . (int) $record['author'])
                    : 'none — re-issue to set one') . '</td>'
                . '<td>' . ($record['revoked_at'] === null ? 'active' : 'revoked') . '</td><td>';
            if ($record['revoked_at'] === null) {
                self::form(['do' => 'revoke', 'id' => $id], 'Revoke');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><h2>Issue a key</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<p><label>Tenant label <input name="label" required></label></p>';
        // The byline posts made with this key will carry. Defaulted to whoever
        // is on this screen, who holds `manage_options` -- that is what reached
        // it -- and so is a real user of this site by construction. It grants
        // the key nothing; it is a name on a post.
        echo '<p>Byline author ';
        wp_dropdown_users(['name' => 'author', 'selected' => get_current_user_id()]);
        echo '</p>';
        // THE POST TYPES `content.publish` MAY CREATE IN. Free text and not a
        // list of checkboxes: a site's post types are registered by its own
        // plugins and a type this screen did not know to offer is exactly the
        // custom type a tenant's pipeline publishes into. What a checkbox list
        // buys -- no typos -- `CadenceKey::issue` buys instead by asking the
        // site, at the one moment a human is here to read the answer.
        echo '<p><label>Publishes in <input name="post_types" placeholder="post, page">'
            . '</label><br><em>Comma-separated post types. Leave blank for any type '
            . 'this site registers.</em></p>';
        foreach (CadenceKey::CAPABILITIES as $capability) {
            echo '<p><label><input type="checkbox" name="caps[]" value="' . esc_attr($capability) . '"> '
                . esc_html($capability) . '</label></p>';
        }
        echo '<p><button class="button button-primary">Issue</button></p></form></div>';
    }

    /**
     * THE JUST-ISSUED SECRET, ONCE. Empty when there is none waiting, which is
     * every render but the one after an issue.
     *
     * The delete is unconditional and comes before the return: a secret this
     * read cannot show -- something else stored a non-string under the key, a
     * second tab got here first -- must not be left behind for a later render
     * either.
     */
    private static function take_issued(): string {
        $slot   = self::STASH . get_current_user_id();
        $issued = get_transient($slot);
        delete_transient($slot);
        return is_string($issued) ? $issued : '';
    }

    /** @param array<string, string> $fields */
    private static function form(array $fields, string $label): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        foreach ($fields as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        echo '<button class="button">' . esc_html($label) . '</button></form>';
    }
}
