<?php
/**
 * WHICH TEXT A POST HOLDS RIGHT NOW, as one short string.
 *
 * A caller that wants to replace a post has to be able to say which text it
 * believes it is replacing, and this site has to be able to check that claim
 * against what it actually holds. So the token is DERIVED FROM THE POST and
 * never stored beside it: a marker this plugin writes and only this plugin
 * updates agrees with the site right up until a human edits the post in
 * wp-admin, which is the one moment it had to disagree.
 *
 * It covers the title and the content, which are exactly the fields a
 * replacement overwrites. Anything else about the post is not something a
 * replacement destroys, and refusing over it would refuse work that is safe.
 *
 * @package cadence-connector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceRevision {

    /** Named in the value, so a later change of algorithm is visible rather than silent. */
    public const ALGO = 'sha256';

    public static function of(string $title, string $content): string {
        // LENGTH-PREFIXED, so where the title ends is part of what is hashed.
        // Plain concatenation makes title `ab` with content `c` and title `a`
        // with content `bc` the same revision -- and a replacement naming one
        // would then be accepted against the other, passing the check while
        // overwriting a title the caller has never seen.
        return self::ALGO . ':' . hash(self::ALGO, sprintf(
            '%d:%s%d:%s', strlen($title), $title, strlen($content), $content));
    }

    /**
     * The revision of what this site holds for a post, as a fragment of an
     * answer: `['revision' => ...]`, or nothing at all when the post cannot be
     * read.
     *
     * ABSENT, NEVER GUESSED. The tempting fallback -- deriving it from the
     * request that was just written -- is the single answer that would be
     * wrong: it hands the caller a token this site never agreed to, and the
     * caller's next replacement would name it and be accepted. Refusing
     * instead would be the other kind of lie, since by this point the write
     * has already happened.
     */
    public static function answer(int $post_id): array {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return [];
        }
        return ['revision' => self::of($post->post_title, $post->post_content)];
    }
}
