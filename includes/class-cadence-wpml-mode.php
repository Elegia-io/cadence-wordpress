<?php
/**
 * WHETHER WPML WILL TRANSLATE A NEW POST BY ITSELF, asked before a write.
 *
 * WPML's "Translate Everything Automatically" mode sends every new or changed
 * post to machine translation and publishes the result as its translations.
 * On such a site a piece this connector places is translated a second time by
 * WPML, and those copies land beside the translations this connector links:
 * text nobody reviewed, under the same translation group. So `/content` and
 * `/content/replace` refuse to write while the mode is on, and the refusal
 * tells the site owner to switch it off.
 *
 * THE MODE IS ASKED OF WPML ITSELF, NEVER INFERRED FROM ITS VERSION. WPML
 * answers through `WPML\Setup\Option::shouldTranslateEverything()`. A site
 * that runs WPML and does not answer -- the class or the method is missing,
 * it throws, or it returns something that is not a boolean -- is refused under
 * its own code: "cannot tell" is not "off", and writing on that reading would
 * be the failure this check exists to prevent.
 *
 * A site with no WPML has no such mode and is never refused here. WPML's
 * presence is asked the way the rest of the plugin asks it: whether anything
 * implements `wpml_element_language_details`.
 *
 * @package cadence-connector
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CadenceWpmlMode {

    public const ON = 'wpml_translate_everything_on';
    public const UNKNOWN = 'wpml_translate_everything_unknown';

    /** Where WPML answers the question. */
    private const OPTION_CLASS = 'WPML\\Setup\\Option';

    /**
     * The class asked instead of `OPTION_CLASS`, or null for WPML's own.
     * Private, and set only by the tests (through reflection), so the branch
     * where WPML runs without this class is reachable there.
     */
    private static ?string $option_class = null;

    /**
     * The refusal to answer with, or null when the write may go ahead.
     *
     * @param string $verb what the refused request would have done, for the
     *                     sentence: `published` or `rewritten`.
     * @return array{ok: false, code: string, reason: string}|null
     */
    public static function refusal(string $verb): ?array {
        if (!has_filter('wpml_element_language_details')) {
            return null;
        }
        $class = self::$option_class ?? self::OPTION_CLASS;
        $answer = null;
        // THE LOOKUP IS INSIDE THE `try` TOO: `class_exists` runs the site's
        // autoloaders, and one that throws is WPML not answering, not a 500.
        try {
            if (class_exists($class) && method_exists($class, 'shouldTranslateEverything')) {
                $answer = $class::shouldTranslateEverything();
            }
        } catch (\Throwable $e) {
            $answer = null;
        }
        $on = self::parse($answer);
        if ($on === false) {
            return null;
        }
        if ($on === true) {
            return ['ok' => false, 'code' => self::ON, 'reason' => sprintf(
                'WPML on this site is set to translate everything automatically, so it would '
                . 'translate this piece by itself. Switch off "Translate Everything Automatically" '
                . 'in WPML to use this connector; nothing was %s', $verb)];
        }
        return ['ok' => false, 'code' => self::UNKNOWN, 'reason' => sprintf(
            'WPML on this site would not say whether it translates everything automatically, '
            . 'so this connector cannot tell whether WPML would translate this piece by itself. '
            . 'Check that "Translate Everything Automatically" is off in WPML; nothing was %s', $verb)];
    }

    /**
     * WPML's answer as on, off, or null for "not an answer".
     *
     * The WPML 4.7 to 4.9 source observed returns a bool. The value comes
     * from a stored option, though, and a stored option can come back in the
     * plain encodings WordPress and PHP give a boolean: `1`/`'1'` for on,
     * `0`/`'0'`/`''` for off. Reading only `true` and `false` would refuse
     * every publish on a site whose option is stored that way, so those are
     * accepted. Anything else -- null, an array, any other string -- is not
     * an answer, and stays null.
     */
    private static function parse($answer): ?bool {
        if (in_array($answer, [true, 1, '1'], true)) {
            return true;
        }
        if (in_array($answer, [false, 0, '0', ''], true)) {
            return false;
        }
        return null;
    }
}
