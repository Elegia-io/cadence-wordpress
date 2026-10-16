<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * THE PLUGIN UPDATES FROM WORDPRESS.ORG AND CARRIES NO UPDATER OF ITS OWN.
 *
 * The code that downloads an archive and unpacks it into a live web root is
 * the highest-consequence thing this plugin could contain: a bug in it is code
 * execution on every client at once. WordPress.org hosting means never writing
 * it, and the auto-update toggle only works for a plugin hosted there. An
 * `Update URI` header would point WordPress somewhere other than .org, so its
 * absence is part of the same decision.
 *
 * This is a TRIPWIRE over spellings, not the boundary. The boundary is that no
 * such code exists. What this buys is that adding the obvious spellings fails
 * the build and lands the author here.
 *
 * The one case that reopens this: .org review refuses the plugin for a reason
 * that cannot be fixed. A private update server is then built as a
 * harm-guard, never improvised to get a fix out.
 */
final class NoUpdaterTest extends TestCase {

    /**
     * Every file `build-zip.py` ships from code: the plugin file and all of
     * `includes/`, recursively and whatever the extension, because the zip
     * takes `includes` with `rglob("*")`.
     *
     * @return array<string, string> path => source
     */
    private function sources(): array {
        $root = dirname(__DIR__);
        $paths = [$root . '/cadence-connector.php'];
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $f) {
            if ($f->isFile()) {
                $paths[] = $f->getPathname();
            }
        }
        $out = [];
        foreach ($paths as $p) {
            $src = file_get_contents($p);
            $this->assertIsString($src, "$p could not be read");
            $out[substr($p, strlen($root) + 1)] = $src;
        }
        return $out;
    }

    public function test_the_plugin_header_names_no_update_uri(): void {
        $header = file_get_contents(dirname(__DIR__) . '/cadence-connector.php');
        $this->assertIsString($header);
        $this->assertStringContainsString('Plugin Name:', $header);
        // WordPress's own get_file_data() prefix, with `\s*` before the colon
        // so this refuses more than WordPress parses, never less.
        $this->assertDoesNotMatchRegularExpression(
            '/^(?:[ \t]*<\?php)?[ \t\/*#@]*Update URI\s*:/mi', $header);
    }

    public function test_no_source_file_hooks_the_update_check_or_installs_a_download(): void {
        $sources = $this->sources();
        // A DENOMINATOR, so a moved tree cannot pass by scanning nothing.
        $this->assertGreaterThan(10, count($sources));

        $spellings = [
            'pre_set_site_transient_update_plugins',
            'site_transient_update_plugins',
            'wp_update_plugins',
            'plugins_api',
            'upgrader_pre_download',
            // Used, not named: a comment cites WP_Upgrader::create_lock's pattern.
            'new WP_Upgrader',
            'extends WP_Upgrader',
            'Plugin_Upgrader',
            'download_url',
            'unzip_file',
            'ZipArchive',
            'PclZip',
        ];
        $found = [];
        foreach ($sources as $file => $src) {
            foreach ($spellings as $s) {
                if (stripos($src, $s) !== false) {
                    $found[] = "$file: $s";
                }
            }
        }
        $this->assertSame([], $found, 'updater code found: ' . implode(', ', $found));
    }
}
