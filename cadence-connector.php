<?php
/**
 * Plugin Name:       Cadence Connector
 * Plugin URI:        https://github.com/Elegia-io/cadence-wordpress
 * Description:       Lets an external content pipeline publish posts into WordPress, replace the ones it published, and link them into WPML translation groups, refusing any request that disagrees with the site's own state.
 * Version:           0.3.0
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

require_once __DIR__ . '/includes/class-cadence-key.php';
require_once __DIR__ . '/includes/class-cadence-language-declaration.php';
require_once __DIR__ . '/includes/class-cadence-link-request.php';
require_once __DIR__ . '/includes/class-cadence-revision.php';
require_once __DIR__ . '/includes/class-cadence-content-request.php';
require_once __DIR__ . '/includes/class-cadence-replace-request.php';
require_once __DIR__ . '/includes/class-cadence-rest-route.php';
require_once __DIR__ . '/includes/class-cadence-admin.php';

CadenceAdmin::boot();

add_action('rest_api_init', static function (): void {
    register_rest_route('cadence/v1', '/translation-group', [
        'methods'  => 'POST',
        'callback' => static function ($request) {
            $result = CadenceLinkRequest::run((array) $request->get_json_params());
            $answer = CadenceRestRoute::respond($result);
            return new WP_REST_Response($answer['body'], $answer['status']);
        },
        // NEVER `__return_true`. WordPress accepts it, logs a notice nobody
        // reads, and serves the route to the entire internet.
        //
        // AND NEVER `current_user_can` EITHER, which is the change here: a
        // WordPress credential is scoped to a USER, so one that may create a
        // draft may also edit every published post, read every draft and
        // enumerate users. The key below is scoped to a CAPABILITY and confers
        // no WordPress identity at all.
        'permission_callback' => static function ($request): bool {
            return CadenceKey::authorises($request->get_header(CadenceKey::HEADER), 'translation.link')
                && CadenceRestRoute::names_posts((array) $request->get_json_params());
        },
    ]);

    register_rest_route('cadence/v1', '/content', [
        'methods'  => 'POST',
        'callback' => static function ($request) {
            $result = CadenceContentRequest::run(
                (array) $request->get_json_params(),
                // WHETHER THE SAME KEY MAY ALSO REPLACE. `/content` is
                // authorised on `content.publish` alone; whether the
                // idempotent-repeat answer discloses the post's revision --
                // the proof `/content/replace` demands -- is a second
                // question, asked of the same presented key.
                static fn (string $capability): bool =>
                    CadenceKey::authorises($request->get_header(CadenceKey::HEADER), $capability),
                // The byline this key names, and nothing else: it fills
                // `post_author` on the insert. The request remains
                // unauthenticated as far as WordPress is concerned -- two
                // things the same header decides, and neither is a login.
                CadenceKey::author_for($request->get_header(CadenceKey::HEADER))
            );
            $answer = CadenceRestRoute::respond($result);
            return new WP_REST_Response($answer['body'], $answer['status']);
        },
        'permission_callback' => static function ($request): bool {
            return CadenceKey::authorises($request->get_header(CadenceKey::HEADER), 'content.publish');
        },
    ]);

    // ITS OWN ROUTE, not a second method on `/content`. `/content` documents
    // one promise -- this piece exists on the site, however many times you ask
    // -- and a rewrite arriving at that same address makes the promise depend
    // on a verb. The two also authorise different things: creating is asked of
    // a post type, rewriting is asked of the one post being rewritten. A
    // caller that means to replace says so in the address it calls.
    //
    // AND ITS OWN CAPABILITY. `content.publish` is never enough on its own: a
    // key that may create must not silently also be able to overwrite. A
    // pipeline that both publishes and revises holds both capabilities on one
    // key; a pipeline that only ever publishes cannot rewrite anything, even
    // its own posts, without this being granted separately.
    register_rest_route('cadence/v1', '/content/replace', [
        'methods'  => 'POST',
        'callback' => static function ($request) {
            $result = CadenceReplaceRequest::run((array) $request->get_json_params());
            $answer = CadenceRestRoute::respond($result);
            return new WP_REST_Response($answer['body'], $answer['status']);
        },
        'permission_callback' => static function ($request): bool {
            return CadenceKey::authorises($request->get_header(CadenceKey::HEADER), 'content.replace');
        },
    ]);
});
