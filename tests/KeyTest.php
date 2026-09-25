<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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
     * A BYLINE CHECKED ONLY AT ISSUE TIME SURVIVES THE USER'S DELETION -- the
     * failure this asserts against. `issue()` refused nobody at the moment the
     * key was created; the user is then removed from the site, as
     * `wp_delete_user` allows for any id, and the next publish must not carry
     * an author that resolves to nothing.
     */
    public function test_a_byline_whose_user_has_since_been_deleted_names_no_byline(): void {
        $key = $this->issue(['content.publish'], 'tenant-a', 7);
        unset(WpStub::$users[7]);
        $this->assertNull(CadenceKey::author_for($key['secret']));
        // THE TWIN: the capability the key was issued for is untouched by the
        // byline dying. Deleting a user must not also revoke a key -- the two
        // are unrelated grants, and a key that stops authorising because a
        // WordPress account vanished would be the identity coupling this
        // credential exists to avoid.
        $this->assertTrue(CadenceKey::authorises($key['secret'], 'content.publish'));
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

    /**
     * WHAT A CAPABILITY MAY ACT ON, asked of the predicate itself.
     *
     * `translation.link` used to reach every post on the site: the per-post
     * `current_user_can('edit_post', $id)` it replaced had no WordPress user to
     * ask about, so nothing was left that could say no. The scope is now this
     * plugin's own posts -- the set it created, and the one set that needs no
     * configuration on the site to stay current.
     */
    public function test_a_post_this_connector_published_is_in_scope_and_nothing_else_is(): void {
        WpStub::reset();
        WpStub::add_post(1, 'page');
        WpStub::add_post(2, 'page');
        WpStub::cadence_published(1, 'piece-2026-en');

        $this->assertTrue(CadenceKey::scope_admits(1));
        // A page a human wrote. The site has it, WordPress would happily let
        // anything write to it, and it is not this connector's.
        $this->assertFalse(CadenceKey::scope_admits(2));
        // And a post id the site does not have at all is not in scope either --
        // fail closed, rather than "no meta says nothing, so allow it".
        $this->assertFalse(CadenceKey::scope_admits(999));
    }

    /**
     * THE SCOPE IS THE SET `/content` CREATES, checked by creating one rather
     * than by writing the meta a test believes it writes.
     *
     * This is the join between the two halves, and the reason the meta is the
     * right instrument: `/content` writes the identifier in the same
     * `wp_insert_post` call that makes the row, so there is no window in which
     * a post this caller just published is outside the scope of the key that
     * will link it a moment later.
     */
    #[Group('wpml')]
    public function test_a_post_the_content_route_just_created_is_in_scope(): void {
        WpStub::reset();
        $r = CadenceContentRequest::run([
            'piece_id' => 'piece-1', 'language' => 'en', 'post_type' => 'post',
            'status' => 'draft', 'title' => 'T', 'content' => 'C',
            'declared' => ['multilingual' => true, 'languages' => ['en']],
        ], static fn (string $c): bool => false, null, null, CadenceAttest::KEY_ID,
            CadenceAttest::header('/content', CadenceAttest::fields('/content', [
                'piece_id' => 'piece-1', 'language' => 'en', 'post_type' => 'post',
                'status' => 'draft', 'title' => 'T', 'content' => 'C',
            ]), CadenceAttest::KEY_ID));

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertTrue(CadenceKey::scope_admits($r['post_id']),
            'a post this connector had just created was outside the scope of a key that links it');
    }

    /**
     * A POST A DIFFERENT KEY CREATED IS NOT THIS KEY'S TO ACT ON.
     *
     * `scope_admits` answers "did Cadence make this", which on a site holding
     * two connector keys -- two brands on one WordPress, an agency serving two
     * tenants -- admits the other tenant's pieces. A translation group written
     * over them destroys the relations they had, so the second question has to
     * be asked separately and this is it.
     */
    public function test_a_post_a_different_key_created_is_not_this_keys_own(): void {
        WpStub::add_post(1, 'post');
        WpStub::cadence_published(1, 'piece-a');
        WpStub::$meta[1][CadenceContentRequest::KEY_META] = 'aaaa1111';

        $this->assertTrue(CadenceKey::created_by(1, 'aaaa1111'));
        $this->assertFalse(CadenceKey::created_by(1, 'bbbb2222'));
        // AND NO IDENTITY IS NOT A WILDCARD. A caller this route cannot name
        // reaches a stamped post never -- the direction matters, because the
        // null case is the one every unauthenticated caller arrives in.
        $this->assertFalse(CadenceKey::created_by(1, null));
    }

    /**
     * THE NULL-IDENTITY PATH, WHICH IS WHY THIS CAN SHIP AT ALL.
     *
     * Every post Cadence has already created on any site carries no
     * key stamp: the stamp did not exist when it was made. Refusing those would
     * refuse every link over every piece already published -- an upgrade that
     * breaks the working case, which is worse than the widening it closes. So
     * an unstamped post stays reachable, and it is a documented branch rather
     * than a falsy check: an absent row, a blank string and an array something
     * else wrote all take it.
     */
    #[DataProvider('absentStamps')]
    public function test_a_post_carrying_no_key_stamp_is_reachable_by_any_key($stamp): void {
        WpStub::add_post(1, 'post');
        WpStub::cadence_published(1, 'piece-a');
        if ($stamp !== 'absent') {
            WpStub::$meta[1][CadenceContentRequest::KEY_META] = $stamp;
        }

        $this->assertTrue(CadenceKey::created_by(1, 'aaaa1111'));
        $this->assertTrue(CadenceKey::created_by(1, 'bbbb2222'));
    }

    public static function absentStamps(): array {
        return [
            'no meta row' => ['absent'],
            'blank'       => [''],
            'whitespace'  => ["  \t "],
            'an array'    => [['aaaa1111']],
            'null'        => [null],
        ];
    }

    /** The id a presented key authenticates as -- and null for one that does not. */
    public function test_the_key_id_is_readable_only_from_a_key_that_authenticates(): void {
        $key = $this->issue(['content.publish']);
        $this->assertSame($key['id'], CadenceKey::key_id_for($key['secret']));
        // THE ID ALONE IS NOT AN IDENTITY. Spelling a real key's id in front
        // of a dot is what a second tenant would try, and the hash comparison
        // in `grant` is what refuses it -- without which the stamp comparison
        // would be a boundary anyone could walk through by guessing an id that
        // is printed on the admin screen.
        $this->assertNull(CadenceKey::key_id_for($key['id'] . '.wrong-secret'));
        $this->assertNull(CadenceKey::key_id_for(null));
        CadenceKey::revoke($key['id']);
        $this->assertNull(CadenceKey::key_id_for($key['secret']));
    }

    /**
     * A KEY NAMES THE POST TYPES ITS `content.publish` MAY CREATE IN.
     *
     * The one scope that cannot be derived from the site: a post being created
     * has no meta to ask about yet, so the types are declared on the key.
     */
    public function test_a_key_can_be_issued_scoped_to_post_types(): void {
        $key = CadenceKey::issue('tenant-a', ['content.publish'], 7, ['post']);
        $this->assertIsArray($key, is_string($key) ? $key : '');
        $this->assertSame(['post'], CadenceKey::publish_types_for($key['secret']));
        $this->assertSame(['post'], CadenceKey::all()[$key['id']]['post_types']);
    }

    /**
     * A TYPO IS REFUSED WHEN THE KEY IS MADE, NOT WHEN A PUBLISH FAILS.
     *
     * `post_type_exists` is the site's own answer and this is the one moment a
     * human is on the screen to read it. A key scoped to `artcle` would
     * otherwise be stored, read on the admin screen as a key that works, and
     * refuse every publish it is ever presented for -- with a 403 whose holder
     * can see neither the field nor the typo in it.
     */
    public function test_a_key_naming_a_post_type_this_site_does_not_register_is_refused(): void {
        $result = CadenceKey::issue('tenant-a', ['content.publish'], 7, ['artcle']);
        $this->assertIsString($result);
        $this->assertStringContainsString('artcle', $result);
        $this->assertSame([], CadenceKey::all(), 'a key with an impossible scope was stored anyway');
    }

    /**
     * `revision` AND `attachment` ARE REFUSED THE SAME WAY, AT THE SAME
     * MOMENT, for the same reason a typo is: `CadenceKey::is_content_type`
     * refuses both everywhere Cadence acts on a post, whatever a key's scope
     * names, so a key scoped to either would read on the admin screen as one
     * that works and fail every publish, replace and link it is ever
     * presented for. Both are registered post types on a real site -- unlike
     * a typo -- so `post_type_exists` alone would not have caught them.
     */
    public function test_a_key_scoped_to_revision_or_attachment_is_refused(): void {
        WpStub::$post_types[] = 'attachment';
        foreach (['revision', 'attachment'] as $type) {
            $result = CadenceKey::issue('tenant-a', ['content.publish'], 7, [$type]);
            $this->assertIsString($result, "a key scoped to $type was issued");
            $this->assertStringContainsString($type, $result);
        }
        $this->assertSame([], CadenceKey::all(), 'a key scoped to revision or attachment was stored anyway');
    }

    /**
     * AN EMPTY LIST IS REFUSED, and is not read as "any".
     *
     * The two are a keystroke apart on the screen and opposite in effect: a key
     * scoped to nothing refuses every publish, and one scoped to any publishes
     * into every type the site registers. Leaving the field blank is how an
     * operator says "any", and it arrives here as null.
     */
    public function test_a_publish_scope_naming_no_type_is_refused(): void {
        $this->assertIsString(CadenceKey::issue('tenant-a', ['content.publish'], 7, []));
        $this->assertIsString(CadenceKey::issue('tenant-a', ['content.publish'], 7, ['  ']));
        $this->assertSame([], CadenceKey::all());
    }

    /**
     * A KEY ISSUED BEFORE THE FIELD EXISTED PUBLISHES INTO ANY TYPE.
     *
     * THE COMPATIBILITY PATH, and the reason the field is optional. Keys are
     * live on sites this repository does not control; a new required field
     * would turn a working publish into a 403 the moment the plugin updated,
     * for a key whose holder can neither see the field nor fill it in. The
     * record carries no `post_types` at all, which is what a key issued by 0.3.0
     * looks like, and `publish_types_for` reads that absence as "any".
     */
    public function test_a_key_that_names_no_post_type_publishes_into_any(): void {
        $key = $this->issue(['content.publish']);
        $this->assertArrayNotHasKey('post_types', CadenceKey::all()[$key['id']]);
        $this->assertNull(CadenceKey::publish_types_for($key['secret']));
    }

    /**
     * A STORED EMPTY LIST READS AS "NONE", NEVER AS "ANY", AND REFUSES EVERY
     * PUBLISH.
     *
     * `issue` refuses `[]`, which the test above it asserts -- but that is the
     * WRITING side, and the reading side was a property only the docblock
     * claimed. `is_array($types) && $types !== []` there would turn a stored
     * `[]` into "any registered type", widening a key on corrupt option data,
     * and every test in this repository stayed green. This is the test that
     * fails on it.
     *
     * Reachable only through option data nothing here wrote -- a partial
     * restore, another plugin, a hand-edited row -- which is why it is written
     * by hand below rather than issued: `issue` cannot produce it, and a
     * property enforced by nothing is the shape this repository refuses.
     */
    public function test_a_stored_empty_publish_scope_refuses_every_publish(): void {
        $key = CadenceKey::issue('tenant-a', ['content.publish'], 7, ['post']);
        $this->assertIsArray($key, is_string($key) ? $key : '');
        $records = get_option(CadenceKey::OPTION);
        $records[$key['id']]['post_types'] = [];
        update_option(CadenceKey::OPTION, $records);

        // READ AS "NONE": the empty list itself, not null.
        $this->assertSame([], CadenceKey::publish_types_for($key['secret']));

        // AND THE READING IS WHAT THE PUBLISH DOES WITH IT. The scope is worth
        // whatever the route makes of it, so the refusal is asserted here too
        // rather than left as an inference from the return value.
        $r = CadenceContentRequest::run([
            'piece_id' => 'piece-1', 'language' => 'en', 'post_type' => 'post',
            'status' => 'draft', 'title' => 'T', 'content' => 'C',
            'declared' => ['multilingual' => true, 'languages' => ['en']],
        ], static fn (string $c): bool => false,
            CadenceKey::publish_types_for($key['secret']), null, $key['id'],
            CadenceAttest::header('/content', CadenceAttest::fields('/content', [
                'piece_id' => 'piece-1', 'language' => 'en', 'post_type' => 'post',
                'status' => 'draft', 'title' => 'T', 'content' => 'C',
            ]), $key['id']));

        $this->assertFalse($r['ok'], 'a key scoped to nothing published anyway');
        $this->assertSame('post_type_out_of_scope', $r['code']);
        $this->assertSame([], WpStub::$inserted);
    }
}
