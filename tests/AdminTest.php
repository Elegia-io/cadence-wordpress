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
     * THE ISSUED SECRET APPEARS EXACTLY ONCE, on the render that follows
     * issuing it, and is gone from every render after -- it is never read
     * back from `CadenceKey::all()`, which does not carry it.
     */
    public function test_the_issued_secret_is_shown_once_and_never_in_the_key_list(): void {
        $this->grantManageOptions();
        CadenceKey::issue('tenant-a', ['content.publish'], 7);
        $secret = 'deadbeef.' . str_repeat('c0ffee', 10);

        $_GET['issued'] = $secret;
        ob_start();
        CadenceAdmin::screen();
        $shown = ob_get_clean();
        $this->assertSame(1, substr_count($shown, $secret),
            'the secret did not appear exactly once on the render that issued it');

        $_GET = [];
        ob_start();
        CadenceAdmin::screen();
        $again = ob_get_clean();
        $this->assertStringNotContainsString($secret, $again,
            'the secret survived into a later render of the key list');
    }
}
