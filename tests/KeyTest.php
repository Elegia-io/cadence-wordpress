<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * THE CREDENTIAL, AND WHAT IT IS NOT.
 *
 * What this replaces is a WordPress application password or an admin account,
 * and the difference is not convenience: those are scoped to a USER, so a
 * credential that may create a draft may also edit every published post, read
 * every draft and enumerate users. A key here grants named capabilities and
 * carries no WordPress identity, so the site's other routes still refuse it.
 */
final class KeyTest extends TestCase {

    protected function setUp(): void {
        WpStub::reset();
    }

    private function issue(array $caps = ['content.publish'], string $label = 'tenant-a',
                           $author = 7): array {
        $key = CadenceKey::issue($label, $caps, $author);
        $this->assertIsArray($key, is_string($key) ? $key : '');
        return $key;
    }

    public function test_a_key_authorises_the_capability_it_was_issued_for(): void {
        $key = $this->issue(['content.publish']);
        $this->assertTrue(CadenceKey::authorises($key['secret'], 'content.publish'));
    }

    /**
     * AND NOTHING ELSE. This is the whole argument for the plugin: the key is
     * scoped to a capability, so a publishing credential cannot be used for the
     * other thing the connector can do, never mind for the rest of WordPress.
     */
    public function test_a_key_does_not_authorise_a_capability_it_was_not_issued_for(): void {
        $key = $this->issue(['content.publish']);
        $this->assertFalse(CadenceKey::authorises($key['secret'], 'translation.link'));
    }

    public function test_a_key_may_carry_both_capabilities_when_issued_for_both(): void {
        $key = $this->issue(['content.publish', 'translation.link']);
        $this->assertTrue(CadenceKey::authorises($key['secret'], 'content.publish'));
        $this->assertTrue(CadenceKey::authorises($key['secret'], 'translation.link'));
    }

    /**
     * THE BYLINE THE KEY NAMES, AND WHAT IT IS NOT.
     *
     * `post_author` is the only thing this id is ever read for. It is not a
     * WordPress identity: the key still authorises exactly the capabilities it
     * was issued for, and nothing else on the site.
     */
    public function test_a_key_names_the_byline_it_was_issued_with(): void {
        $key = $this->issue(['content.publish'], 'tenant-a', 7);
        $this->assertSame(7, CadenceKey::author_for($key['secret']));
    }

    /**
     * AN ID THIS SITE HAS NO USER FOR IS REFUSED, at the moment the key is
     * issued -- which is the moment a human is on the screen to fix it. A
     * byline naming nobody is the empty byline this exists to stop, stored.
     *
     * The string forms matter: a form posts a string, and a cast would turn
     * `'editor'` into 0 and `'7 posts'` into 7.
     */
    public function test_refuses_to_issue_a_byline_this_site_has_no_user_for(): void {
        foreach ([99, 0, -1, '', 'editor', '7 posts', '7.0', 7.0, true, null, [7]] as $i => $author) {
            $this->assertIsString(CadenceKey::issue('tenant-a', ['content.publish'], $author), (string) $i);
        }
        $this->assertSame([], CadenceKey::all(), 'a refused issue still wrote a key');
        // THE TWIN: the same call with an id this site does have is issued, so
        // the refusals above are the check firing rather than issue() being
        // broken for everything.
        $this->assertIsArray(CadenceKey::issue('tenant-a', ['content.publish'], 7));
        $this->assertIsArray(CadenceKey::issue('tenant-b', ['content.publish'], '7'),
            'a form posts its fields as strings');
    }

    /**
     * A KEY ISSUED BEFORE KEYS CARRIED A BYLINE STILL WORKS, and names none.
     *
     * The record is written here in the shape the previous version stored, on
     * purpose: the option table on an install that upgrades holds exactly this,
     * and it must not start refusing to publish over a field it never had.
     * Nothing substitutes a user for it either -- a byline nobody chose is
     * somebody's name on a post they did not write.
     */
    public function test_a_key_issued_before_bylines_authorises_and_names_none(): void {
        $secret = 'deadbeef';
        WpStub::$options[CadenceKey::OPTION] = ['old1' => [
            'label' => 'tenant-a', 'hash' => hash('sha256', $secret),
            'caps' => ['content.publish'], 'created' => 1, 'revoked_at' => null,
        ]];
        $this->assertTrue(CadenceKey::authorises('old1.' . $secret, 'content.publish'),
            'an upgraded install stopped being able to publish');
        $this->assertNull(CadenceKey::author_for('old1.' . $secret));
    }

    /** A key that does not authenticate names no byline either. */
    public function test_a_revoked_or_unauthenticated_key_names_no_byline(): void {
        $key = $this->issue(['content.publish'], 'tenant-a', 7);
        $this->assertNull(CadenceKey::author_for($key['id'] . '.wrong'));
        $this->assertNull(CadenceKey::author_for('unknown.secret'));
        $this->assertNull(CadenceKey::author_for(null));
        CadenceKey::revoke($key['id']);
        $this->assertNull(CadenceKey::author_for($key['secret']));
    }

    /**
     * THE BYLINE NEVER BECOMES A LOGGED-IN USER.
     *
     * Asserted over the shipped source rather than over a behaviour, because
     * the failure is a line somebody adds, not a branch anything here reaches:
     * one `wp_set_current_user($author)` would turn a credential scoped to two
     * capabilities into one scoped to whatever that user's role can do, and
     * every other test in this file would go on passing.
     */
    public function test_nothing_shipped_turns_the_byline_into_a_current_user(): void {
        $files = glob(dirname(__DIR__) . '/includes/*.php');
        $files[] = dirname(__DIR__) . '/cadence-connector.php';
        $this->assertGreaterThan(5, count($files), 'the scan found no source to read');
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (['wp_set_current_user(', 'wp_set_auth_cookie(', 'wp_signon('] as $call) {
                $this->assertStringNotContainsString($call, $source, basename($file));
            }
        }
    }

    /** A grant is a name from a published set, never free text. */
    public function test_refuses_to_issue_a_capability_this_connector_does_not_have(): void {
        $this->assertIsString(CadenceKey::issue('tenant-a', ['content.publsh'], 7));
        $this->assertIsString(CadenceKey::issue('tenant-a', ['manage_options'], 7));
        $this->assertIsString(CadenceKey::issue('tenant-a', [['content.publish']], 7));
        $this->assertIsString(CadenceKey::issue('tenant-a', [], 7));
        $this->assertIsString(CadenceKey::issue('   ', ['content.publish'], 7));
        $this->assertSame([], CadenceKey::all(), 'a refused issue still wrote a key');
    }

    /** ONE KEY PER TENANT, and one tenant's key is not another's. */
    public function test_one_tenants_key_does_not_authorise_under_anothers_id(): void {
        $a = $this->issue(['content.publish'], 'tenant-a');
        $b = $this->issue(['translation.link'], 'tenant-b');
        $this->assertNotSame($a['id'], $b['id']);
        [, $a_secret] = explode('.', $a['secret'], 2);
        $this->assertFalse(CadenceKey::authorises($b['id'] . '.' . $a_secret, 'translation.link'),
            'one tenant\'s secret authorised under another tenant\'s id');
    }

    /** REVOCABLE PER CLIENT, and revocation takes effect on the next call. */
    public function test_a_revoked_key_authorises_nothing(): void {
        $key = $this->issue(['content.publish']);
        $this->assertTrue(CadenceKey::revoke($key['id']));
        $this->assertFalse(CadenceKey::authorises($key['secret'], 'content.publish'));
        $this->assertFalse(CadenceKey::revoke($key['id']), 'revoking twice reported a second withdrawal');
        $this->assertSame('tenant-a', CadenceKey::all()[$key['id']]['label'],
            'the record vanished, so nothing records that this tenant ever had a key');
    }

    /**
     * THE STORED HALF CANNOT BE PRESENTED. The option table is readable by a
     * database dump, a backup, and any plugin that calls `get_option`, so what
     * is in it must not be a credential.
     */
    public function test_the_secret_is_not_stored_and_the_stored_hash_is_not_a_key(): void {
        $key = $this->issue(['content.publish']);
        [, $secret] = explode('.', $key['secret'], 2);
        $stored = WpStub::$options[CadenceKey::OPTION][$key['id']];

        $this->assertStringNotContainsString($secret, json_encode(WpStub::$options));
        $this->assertFalse(CadenceKey::authorises($key['id'] . '.' . $stored['hash'], 'content.publish'),
            'the stored value was accepted as the secret');
        $this->assertArrayNotHasKey('hash', CadenceKey::all()[$key['id']],
            'the hash reached a screen that has no use for it');
    }

    /** Every way of being wrong is the same answer, so none of them is an oracle. */
    public function test_refuses_a_presented_key_that_is_not_one(): void {
        $key = $this->issue(['content.publish']);
        [$id, $secret] = explode('.', $key['secret'], 2);
        foreach ([
            null, '', 'x', $id, $secret, $id . '.', '.' . $secret,
            $id . '.' . strrev($secret),
            $id . '.' . substr($secret, 0, -1),
            strrev($id) . '.' . $secret,
            ['id' => $id, 'secret' => $secret],
            $id . '.' . $secret . '.extra',
        ] as $i => $presented) {
            $this->assertFalse(CadenceKey::authorises($presented, 'content.publish'), (string) $i);
        }
        // THE TWIN: the genuine one, in the same call, so the run above is the
        // refusals firing rather than a method that refuses everything.
        $this->assertTrue(CadenceKey::authorises($id . '.' . $secret, 'content.publish'));
    }

    /** Two keys issued a moment apart are different keys. */
    public function test_two_issued_keys_share_neither_id_nor_secret(): void {
        $a = $this->issue();
        $b = $this->issue();
        $this->assertNotSame($a['id'], $b['id']);
        $this->assertNotSame($a['secret'], $b['secret']);
        $this->assertFalse(CadenceKey::authorises($a['secret'], 'translation.link'));
    }

    /** An option holding something other than keys is not a key that works. */
    public function test_a_junk_option_authorises_nothing(): void {
        WpStub::$options[CadenceKey::OPTION] = 'not an array';
        $this->assertFalse(CadenceKey::authorises('a.b', 'content.publish'));
        $this->assertSame([], CadenceKey::all());
    }
}
