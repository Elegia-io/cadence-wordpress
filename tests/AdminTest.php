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

    /** A key on the site, and the screen's HTML. */
    private function render(): string {
        $this->grantManageOptions();
        ob_start();
        try {
            CadenceAdmin::screen();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    private function post(array $fields): string {
        $this->grantManageOptions();
        $_POST = $fields;
        try {
            CadenceAdmin::handle();
            $this->fail('handle() did not reach the redirect');
        } catch (CadenceTestRedirected $e) {
            // The `error` this screen prints, read off the redirect it is
            // carried on -- the only channel a refused write has.
            parse_str((string) parse_url($e->getMessage(), PHP_URL_QUERY), $query);
            return (string) ($query['error'] ?? '');
        }
        return '';
    }

    /**
     * THE VERIFYING KEY IS PASTED BY HAND, AND THERE IS NO OTHER WAY IN.
     *
     * No upload route and no API write channel, deliberately: whoever can set
     * the verifying key can sign anything as this tenant, so a grant that
     * could set it would be the boundary this feature draws. Asserted over the
     * ROUTE TABLE and the key class rather than over the screen -- "the screen
     * has a form" is satisfied by a screen that also has a REST endpoint.
     */
    public function test_no_route_and_no_rest_handler_can_write_a_verifying_key(): void {
        $sources = '';
        foreach (['cadence-connector.php', 'includes/class-cadence-content-request.php',
                  'includes/class-cadence-replace-request.php',
                  'includes/class-cadence-link-request.php',
                  'includes/class-cadence-rest-route.php'] as $file) {
            $sources .= file_get_contents(__DIR__ . '/../' . $file);
        }
        $this->assertStringNotContainsString('add_verify_key', $sources,
            'something outside the admin screen can write a verifying key');
        $this->assertStringNotContainsString('set_unsigned_ok', $sources,
            'something outside the admin screen can set the unsigned exemption');
        // And the admin file DOES call both, so the assertions above are not
        // passing because the methods were renamed out from under them.
        $admin = file_get_contents(__DIR__ . '/../includes/class-cadence-admin.php');
        $this->assertStringContainsString('CadenceKey::add_verify_key', $admin);
        $this->assertStringContainsString('CadenceKey::set_unsigned_ok', $admin);
    }

    /** THE SCREEN OFFERS THE PASTE, beside the key it belongs to. */
    public function test_the_screen_offers_a_field_for_a_public_key(): void {
        $key = CadenceKey::issue('tenant-a', ['content.publish'], 7);
        $html = $this->render();

        $this->assertStringContainsString('name="public_key"', $html);
        $this->assertStringContainsString('name="kid"', $html);
        $this->assertStringContainsString('value="attest"', $html);
        $this->assertStringContainsString($key['id'], $html);
        // A key with none says so, rather than showing a blank cell that reads
        // as "fine".
        $this->assertStringContainsString('every signed publish is refused', $html);
    }

    /** A PASTED KEY LANDS ON THAT KEY, AND ONLY THAT KEY. */
    public function test_pasting_a_public_key_stores_it_on_the_named_key(): void {
        $a = CadenceKey::issue('tenant-a', ['content.publish'], 7);
        $b = CadenceKey::issue('tenant-b', ['content.publish'], 7);
        $pk = base64_encode(random_bytes(32));

        $this->post(['do' => 'attest', 'id' => $a['id'], 'kid' => 'abcdef0123456789',
                     'public_key' => $pk]);

        $this->assertSame([['kid' => 'abcdef0123456789', 'pk' => $pk,
                            'added' => CadenceKey::verify_keys($a['id'])[0]['added']]],
                          CadenceKey::verify_keys($a['id']));
        $this->assertSame([], CadenceKey::verify_keys($b['id']),
            'a paste reached a key it did not name');
    }

    /** A REFUSED PASTE COMES BACK AS THE ERROR THIS SCREEN PRINTS. */
    public function test_a_bad_public_key_is_refused_and_stores_nothing(): void {
        $key = CadenceKey::issue('tenant-a', ['content.publish'], 7);
        $error = $this->post(['do' => 'attest', 'id' => $key['id'], 'kid' => 'abcdef0123456789',
                              'public_key' => base64_encode(random_bytes(31))]);

        $this->assertSame([], CadenceKey::verify_keys($key['id']));
        $this->assertStringContainsString('decodes to 31', $error);
    }

    /** AND A PASTED KEY CAN BE TAKEN OFF, or a rotation happens once. */
    public function test_a_public_key_can_be_removed(): void {
        $key = CadenceKey::issue('tenant-a', ['content.publish'], 7);
        $this->post(['do' => 'attest', 'id' => $key['id'], 'kid' => 'abcdef0123456789',
                     'public_key' => base64_encode(random_bytes(32))]);
        $this->assertCount(1, CadenceKey::verify_keys($key['id']));

        $this->post(['do' => 'attest_remove', 'id' => $key['id'], 'kid' => 'abcdef0123456789']);
        $this->assertSame([], CadenceKey::verify_keys($key['id']));
    }

    /**
     * THE WARNING IS THERE WHILE THE EXEMPTION IS, NAMES THE KEY, AND IS GONE
     * WHEN IT IS OFF.
     *
     * The absence is half the assertion: a notice that is always printed is
     * furniture, and an operator stops reading it before the day it matters.
     */
    public function test_the_unsigned_warning_names_the_key_and_only_while_it_is_set(): void {
        $key = CadenceKey::issue('tenant-a', ['content.publish'], 7);

        $this->assertStringNotContainsString('notice-warning', $this->render(),
            'the screen warned about an exemption nobody set');

        $this->post(['do' => 'unsigned_on', 'id' => $key['id']]);
        $html = $this->render();
        $this->assertStringContainsString('notice-warning', $html);
        // NAMED, and by the two things that identify it on this screen.
        $this->assertStringContainsString('tenant-a', $html);
        $this->assertStringContainsString($key['id'], $html);
        $this->assertStringContainsString('publishes WITHOUT a signature', $html);
        // AND IT SAYS WHAT IS STILL REFUSED, so nobody reads the exemption as
        // "verification is off".
        $this->assertStringContainsString('BAD signature is still refused', $html);

        $this->post(['do' => 'unsigned_off', 'id' => $key['id']]);
        $this->assertStringNotContainsString('notice-warning', $this->render(),
            'the warning outlived the exemption it warns about');
    }

    /** SETTING IT RECORDS WHEN, AND WHICH USER DID IT. */
    public function test_setting_the_exemption_records_its_provenance(): void {
        $key = CadenceKey::issue('tenant-a', ['content.publish'], 7);

        // The form posts a DIFFERENT user id, and it is ignored.
        $this->post(['do' => 'unsigned_on', 'id' => $key['id'], 'by_user' => '1']);

        $flag = CadenceKey::unsigned_ok($key['id']);
        $this->assertIsArray($flag);
        $this->assertGreaterThan(0, $flag['set_at']);
        // THE SESSION'S USER AND NEVER THE FORM'S. A provenance field a form
        // can choose is a provenance field an operator can disown.
        $this->assertSame(get_current_user_id(), $flag['by_user']);
        $this->assertNotSame(1, $flag['by_user']);
    }
}
