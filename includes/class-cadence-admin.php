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
        $issued = '';
        $error  = '';
        $post   = wp_unslash($_POST);
        if (($post['do'] ?? '') === 'revoke') {
            CadenceKey::revoke(sanitize_text_field((string) ($post['id'] ?? '')));
        } else {
            $capabilities = array_values(array_intersect(
                CadenceKey::CAPABILITIES,
                array_map('sanitize_text_field', (array) ($post['caps'] ?? []))
            ));
            $result = CadenceKey::issue(sanitize_text_field((string) ($post['label'] ?? '')), $capabilities);
            if (is_string($result)) {
                $error = $result;
            } else {
                // SHOWN ONCE, IN THE REDIRECT AND NOWHERE AFTER. Not stored,
                // not mailed, not logged: what the site keeps is a hash, and
                // a key nobody wrote down is a key that gets revoked and
                // reissued, which is the outcome this screen wants anyway.
                $issued = $result['secret'];
            }
        }
        wp_safe_redirect(add_query_arg(array_filter([
            'page'   => self::PAGE,
            'issued' => $issued,
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
        if (!empty($_GET['issued'])) {
            echo '<div class="notice notice-warning"><p>Copy this key now; it is not shown again.</p><p><code>'
                . esc_html(sanitize_text_field(wp_unslash((string) $_GET['issued']))) . '</code></p></div>';
        }
        echo '<table class="widefat"><thead><tr><th>Label</th><th>Id</th><th>Grants</th><th>State</th><th></th></tr></thead><tbody>';
        foreach (CadenceKey::all() as $id => $record) {
            echo '<tr><td>' . esc_html((string) $record['label']) . '</td>'
                . '<td><code>' . esc_html($id) . '</code></td>'
                . '<td>' . esc_html(implode(', ', $record['caps'])) . '</td>'
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
        foreach (CadenceKey::CAPABILITIES as $capability) {
            echo '<p><label><input type="checkbox" name="caps[]" value="' . esc_attr($capability) . '"> '
                . esc_html($capability) . '</label></p>';
        }
        echo '<p><button class="button button-primary">Issue</button></p></form></div>';
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
