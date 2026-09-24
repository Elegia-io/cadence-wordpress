<?php
/**
 * Plugin Name:       Cadence Connector
 * Plugin URI:        https://github.com/Elegia-io/cadence-wordpress
 * Description:       Lets an external content pipeline publish posts into WordPress, replace the ones it published, and link them into WPML translation groups, refusing any request that disagrees with the site's own state.
 * Version:           0.7.0
 * Requires at least: 6.5
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
require_once __DIR__ . '/includes/class-cadence-attestation.php';
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
            $result = CadenceLinkRequest::run(
                (array) $request->get_json_params(),
                // THE POST TYPES THIS KEY REACHES, or null for a key issued
                // before the field existed -- which links in any type, exactly
                // as it did before the plugin was updated under it. The same
                // list the other two routes read: an operator who narrows a
                // key's types is narrowing one scope, not three, and a key
                // that no longer publishes pages no longer links them either.
                CadenceKey::publish_types_for($request->get_header(CadenceKey::HEADER)),
                // WHICH KEY IS ASKING, so the scope can be this key's own
                // pieces rather than every piece Cadence ever published here.
                // The public id and never the secret: it is compared against
                // what `/content` stamped on the post.
                CadenceKey::key_id_for($request->get_header(CadenceKey::HEADER)),
                // THE SIGNATURE OVER THE PLAN'S OWN BYTES. The key above says
                // this caller may link here; this says the plan is the plan
                // that tenant composed. `get_header` answers null when the
                // header is absent, which is the `absent` branch and the only
                // one the migration flag reaches.
                $request->get_header(CadenceAttestation::HEADER)
            );
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
                // THE POST TYPES THIS KEY MAY CREATE IN, or null for a key
                // issued before the field existed -- which publishes into any
                // registered type, exactly as it did before this plugin was
                // updated under it. Passed positionally ahead of the byline
                // because `run` takes it as a REQUIRED argument: null is the
                // wide case, and a default would be a call site's omission
                // reading as a key that named no type.
                CadenceKey::publish_types_for($request->get_header(CadenceKey::HEADER)),
                // The byline this key names, and nothing else: it fills
                // `post_author` on the insert. The request remains
                // unauthenticated as far as WordPress is concerned -- two
                // things the same header decides, and neither is a login.
                CadenceKey::author_for($request->get_header(CadenceKey::HEADER)),
                // AND THE KEY'S OWN ID, which does two things: it is stamped on
                // a post this call creates, so the linking route can later ask
                // whether the post is this key's, and it scopes the lookup that
                // decides whether anything is created -- so one tenant's
                // `piece_id` never resolves to another tenant's post.
                CadenceKey::key_id_for($request->get_header(CadenceKey::HEADER)),
                // AND THE SIGNATURE OVER THE BODY, which is a different
                // question from the key: the key says this caller may publish
                // here, and this says the body is the one that caller composed.
                // Passed as a REQUIRED argument with no default, so a call site
                // that forgot it is a PHP error rather than a request that
                // reads as unsigned.
                $request->get_header(CadenceAttestation::HEADER)
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
            $result = CadenceReplaceRequest::run(
                (array) $request->get_json_params(),
                // THE POST TYPES THIS KEY REACHES, or null for a key issued
                // before the field existed -- which rewrites in any type,
                // exactly as it did before the plugin was updated under it.
                CadenceKey::publish_types_for($request->get_header(CadenceKey::HEADER)),
                // AND WHICH KEY IS ASKING, so the post can be checked against
                // the key that made it rather than against this connector as a
                // whole. The public id and never the secret: it is compared
                // against what `/content` stamped on the post.
                CadenceKey::key_id_for($request->get_header(CadenceKey::HEADER)),
                // The signature over the rewrite's own bytes -- a different
                // material from `/content`'s, over a field set that names WHICH
                // post and WHICH text is being overwritten.
                $request->get_header(CadenceAttestation::HEADER)
            );
            $answer = CadenceRestRoute::respond($result);
            return new WP_REST_Response($answer['body'], $answer['status']);
        },
        'permission_callback' => static function ($request): bool {
            return CadenceKey::authorises($request->get_header(CadenceKey::HEADER), 'content.replace');
        },
    ]);
});
