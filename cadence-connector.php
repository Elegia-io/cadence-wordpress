<?php
/**
 * Plugin Name:       Cadence Connector
 * Plugin URI:        https://github.com/Elegia-io/cadence-wordpress
 * Description:       Lets an external content pipeline link WordPress posts into WPML translation groups, refusing any request that disagrees with the site's own state.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Elegia
 * Author URI:        https://elegia.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cadence-connector
 *
 * @package cadence-connector
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-cadence-link-request.php';
require_once __DIR__ . '/includes/class-cadence-rest-route.php';

add_action('rest_api_init', static function (): void {
    register_rest_route('cadence/v1', '/translation-group', [
        'methods'  => 'POST',
        'callback' => static function ($request) {
            $result = CadenceLinkRequest::run((array) $request->get_json_params());
            $answer = CadenceRestRoute::respond($result);
            return new WP_REST_Response($answer['body'], $answer['status']);
        },
        // NEVER `__return_true`. WordPress accepts it, logs a notice nobody
        // reads, and serves the route to the entire internet. The callback
        // below asks about each post the request names, so a route registered
        // without it is not a weaker version of this -- it is no check at all.
        'permission_callback' => static function ($request): bool {
            return CadenceRestRoute::permitted(
                (array) $request->get_json_params(),
                'current_user_can'
            );
        },
    ]);
});
