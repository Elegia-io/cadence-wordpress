<?php
/**
 * WPML's `WPML\Setup\Option`, in the one method this plugin calls.
 *
 * It answers from `WpStub::$wpml_translate_everything`, and reports the call to
 * `WpmlBoundary` first, so a test that reaches it is in the wpml lane.
 */
declare(strict_types=1);

namespace WPML\Setup;

final class Option {
    public static function shouldTranslateEverything() {
        \WpmlBoundary::reached(self::class . '::shouldTranslateEverything');
        if (\WpStub::$wpml_translate_everything_throws) {
            throw new \RuntimeException('WPML setup option unreadable');
        }
        return \WpStub::$wpml_translate_everything;
    }
}
