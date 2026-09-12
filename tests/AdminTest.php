<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * THE SCREEN THAT MINTS AND WITHDRAWS A PUBLISHING CREDENTIAL.
 *
 * `CadenceKey` is asserted in `KeyTest`, without a WordPress. What is under
 * test here is the three things only this file does, and that a model test
 * cannot reach: `check_admin_referer` runs before either write, both entry
 * points ask for `manage_options` and not only the menu registration, and the
 * issued secret is shown once and never again.
 */
final class AdminTest extends TestCase {

    protected function setUp(): void {
        WpStub::reset();
        $_POST = [];
        $_GET  = [];
    }

    private function grantManageOptions(): void {
        // `current_user_can('manage_options')` is called with no post id, so
        // the stub's `$args[0] ?? null` is `null`; this is what the existing
        // stub answers `true` to.
        WpStub::$capabilities['manage_options'] = [null];
    }

    /**
     * WITHOUT `manage_options`, `handle()` DIES BEFORE EITHER WRITE -- not
     * only before the check further down that has never run.
     */
    public function test_handle_refuses_without_manage_options_before_any_write(): void {
        $_POST = ['label' => 'tenant-a', 'author' => '7', 'caps' => ['content.publish']];
        try {
            CadenceAdmin::handle();
            $this->fail('handle() did not refuse a request with no manage_options');
        } catch (CadenceTestDied $e) {
        }
        $this->assertSame([], CadenceKey::all(), 'a refused request still wrote a key');
    }

    /**
     * THE TWIN: granted, the same request reaches the redirect that follows a
     * write, so the refusal above is the `manage_options` check firing rather
     * than `handle()` being broken for everyone.
     */
    public function test_manage_options_granted_lets_handle_reach_the_write(): void {
        $this->grantManageOptions();
        $_POST = ['label' => 'tenant-a', 'author' => '7', 'caps' => ['content.publish']];
        try {
            CadenceAdmin::handle();
            $this->fail('handle() did not reach the redirect');
        } catch (CadenceTestRedirected $e) {
        }
        $this->assertCount(1, CadenceKey::all());
    }

    /** WITHOUT `manage_options`, `screen()` renders nothing -- not a hidden form. */
    public function test_screen_renders_nothing_without_manage_options(): void {
        ob_start();
        CadenceAdmin::screen();
        $out = ob_get_clean();
        $this->assertSame('', $out, 'the screen rendered something for a user without manage_options');
    }

    /** THE TWIN. */
    public function test_screen_renders_for_manage_options(): void {
        $this->grantManageOptions();
        ob_start();
        CadenceAdmin::screen();
        $out = ob_get_clean();
        $this->assertStringContainsString('Cadence Connector', $out);
    }

    /**
     * AN INVALID NONCE REFUSES AN ISSUE BEFORE IT WRITES ANYTHING. Granting
     * `manage_options` is not enough on its own -- the form itself has to be
     * the one that posted.
     */
    public function test_handle_refuses_an_issue_with_an_invalid_nonce_before_writing(): void {
        $this->grantManageOptions();
        WpStub::$referer_valid = false;
        $_POST = ['label' => 'tenant-a', 'author' => '7', 'caps' => ['content.publish']];
        try {
            CadenceAdmin::handle();
            $this->fail('handle() did not die on an invalid nonce');
        } catch (CadenceTestDied $e) {
        }
        $this->assertSame([], CadenceKey::all(), 'a key was issued before the nonce was checked');
    }

    /** THE TWIN: a valid nonce lets the very same post issue the key. */
    public function test_a_valid_nonce_lets_the_issue_through(): void {
        $this->grantManageOptions();
        $_POST = ['label' => 'tenant-a', 'author' => '7', 'caps' => ['content.publish']];
        try {
            CadenceAdmin::handle();
            $this->fail('handle() did not reach the redirect');
        } catch (CadenceTestRedirected $e) {
        }
        $this->assertCount(1, CadenceKey::all(), 'a valid nonce did not let the key get issued');
    }

    /** THE SAME CHECK GATES THE OTHER WRITE: revoking a key. */
    public function test_handle_refuses_a_revoke_with_an_invalid_nonce_before_writing(): void {
        $this->grantManageOptions();
        $key = CadenceKey::issue('tenant-a', ['content.publish'], 7);
        WpStub::$referer_valid = false;
        $_POST = ['do' => 'revoke', 'id' => $key['id']];
        try {
            CadenceAdmin::handle();
            $this->fail('handle() did not die on an invalid nonce');
        } catch (CadenceTestDied $e) {
        }
        $this->assertNull(CadenceKey::all()[$key['id']]['revoked_at'],
            'a key was revoked before the nonce was checked');
    }

    /** THE TWIN. */
    public function test_a_valid_nonce_lets_the_revoke_through(): void {
        $this->grantManageOptions();
        $key = CadenceKey::issue('tenant-a', ['content.publish'], 7);
        $_POST = ['do' => 'revoke', 'id' => $key['id']];
        try {
            CadenceAdmin::handle();
            $this->fail('handle() did not reach the redirect');
        } catch (CadenceTestRedirected $e) {
        }
        $this->assertNotNull(CadenceKey::all()[$key['id']]['revoked_at'],
            'a valid nonce did not let the revoke through');
    }

    /**
     * THE ISSUED SECRET NEVER TRAVELS IN THE URL, appears exactly once on the
     * render that follows issuing it, and is gone from every render after.
     *
     * Driven through `handle()` and not by planting `$_GET`, because the URL is
     * now half of what is under test: the secret used to be redirected as
     * `?issued=<the key>`, which puts it in the access log, in the browser's
     * history and in the `Referer` of the next request -- written down by
     * parties this plugin does not control, and not undone by the screen
     * showing it once.
     *
     * And it is never read back from `CadenceKey::all()` or the option row,
     * which carry a hash.
     */
    public function test_the_issued_secret_is_never_in_the_url_and_is_shown_once(): void {
        $this->grantManageOptions();
        $_POST = ['label' => 'tenant-a', 'author' => '7', 'caps' => ['content.publish']];
        $location = null;
        try {
            CadenceAdmin::handle();
            $this->fail('handle() did not reach the redirect');
        } catch (CadenceTestRedirected $e) {
            $location = $e->location;
        }

        // The value is read out of the stash rather than invented, so this
        // asserts about the secret the screen actually minted.
        $this->assertCount(1, WpStub::$transients, 'the issued secret was not stashed for the render');
        $secret = array_values(WpStub::$transients)[0];
        $this->assertIsString($secret);

        $this->assertStringNotContainsString($secret, $location,
            'the issued key travelled in the redirect URL');
        $this->assertStringNotContainsString('issued', $location,
            'the URL still names the issue, which is a handle a log can carry');
        $this->assertStringNotContainsString($secret, (string) json_encode(get_option(CadenceKey::OPTION)),
            'the option row carries the secret and not only its hash');

        ob_start();
        CadenceAdmin::screen();
        $shown = ob_get_clean();
        $this->assertSame(1, substr_count($shown, $secret),
            'the secret did not appear exactly once on the render that issued it');
        $this->assertSame([], WpStub::$transients, 'the render left the secret in the stash');

        ob_start();
        CadenceAdmin::screen();
        $again = ob_get_clean();
        $this->assertStringNotContainsString($secret, $again,
            'the secret survived into a later render of the key list');
    }

    /**
     * THE PUBLISH SCOPE TYPED ON THE SCREEN REACHES THE KEY.
     *
     * The form field is worth nothing if `handle()` drops it: the key would be
     * issued unscoped, the list would say so in a column nobody re-reads, and
     * the operator would believe a boundary exists that does not.
     */
    public function test_the_typed_publish_scope_reaches_the_key(): void {
        $this->grantManageOptions();
        $_POST = ['label' => 'tenant-a', 'author' => '7', 'caps' => ['content.publish'],
                  'post_types' => 'post, page'];
        try {
            CadenceAdmin::handle();
            $this->fail('handle() did not reach the redirect');
        } catch (CadenceTestRedirected $e) {
        }

        $record = array_values(CadenceKey::all())[0];
        $this->assertSame(['post', 'page'], $record['post_types']);
    }

    /**
     * A BLANK FIELD IS "ANY", AND IS NOT AN EMPTY SCOPE.
     *
     * The two are a keystroke apart and opposite in effect: a key scoped to
     * nothing refuses every publish. Blank has to arrive at `CadenceKey` as
     * null -- the key issued before the field existed -- and it is the default
     * every operator who ignores the field will get.
     */
    public function test_a_blank_publish_scope_issues_a_key_that_publishes_into_any_type(): void {
        $this->grantManageOptions();
        $_POST = ['label' => 'tenant-a', 'author' => '7', 'caps' => ['content.publish'],
                  'post_types' => '   '];
        try {
            CadenceAdmin::handle();
            $this->fail('handle() did not reach the redirect');
        } catch (CadenceTestRedirected $e) {
        }

        $record = array_values(CadenceKey::all())[0];
        $this->assertArrayNotHasKey('post_types', $record);
    }

    /**
     * A TYPO IS REFUSED AT ISSUE TIME AND THE SCREEN SAYS SO.
     *
     * The whole reason the validation is `CadenceKey`'s and happens when the
     * key is made: the alternative is a stored key that reads as working and
     * refuses every publish, with a 403 its holder cannot trace to a field it
     * cannot see.
     */
    public function test_a_publish_scope_naming_an_unregistered_type_writes_no_key(): void {
        $this->grantManageOptions();
        $_POST = ['label' => 'tenant-a', 'author' => '7', 'caps' => ['content.publish'],
                  'post_types' => 'artcle'];
        try {
            CadenceAdmin::handle();
            $this->fail('handle() did not reach the redirect');
        } catch (CadenceTestRedirected $e) {
        }

        $this->assertSame([], CadenceKey::all(), 'a key with an impossible scope was stored');
    }

    /** The screen offers the field, and marks the keys that have no scope. */
    public function test_the_screen_offers_the_publish_scope_and_marks_unscoped_keys(): void {
        $this->grantManageOptions();
        CadenceKey::issue('tenant-a', ['content.publish'], 7);
        CadenceKey::issue('tenant-b', ['content.publish'], 7, ['page']);

        ob_start();
        CadenceAdmin::screen();
        $shown = ob_get_clean();

        $this->assertStringContainsString('name="post_types"', $shown);
        $this->assertStringContainsString('any type', $shown);
        $this->assertStringContainsString('page', $shown);
    }
}
