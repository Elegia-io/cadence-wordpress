<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `/content/delete`: trash one draft this connector created, for the key that
 * created it. Every refusal has a twin, the answering test, which differs from
 * it in the one fact the refusal is about; and every refusal trashes nothing.
 */
final class DeleteRequestTest extends TestCase {

    private const SIGN = "\0sign";
    private const ID = 31;

    protected function setUp(): void {
        WpStub::reset();
        WpStub::$posts[self::ID] = ['post_type' => 'post', 'post_status' => 'draft', 'language' => 'en',
                                    'trid' => null, 'post_title' => 'T', 'post_content' => 'C'];
        WpStub::$meta[self::ID] = [CadenceContentRequest::META => 'piece-1',
                                   CadenceContentRequest::KEY_META => CadenceAttest::KEY_ID,
                                   CadenceContentRequest::CREATED_META => '1'];
    }

    private function body(array $over = []): array {
        return array_merge(['piece_id' => 'piece-1', 'post_id' => self::ID], $over);
    }

    private function delete(array $body, ?string $key_id = CadenceAttest::KEY_ID,
                            $attestation = self::SIGN, ?array $post_types = null): array {
        return CadenceDeleteRequest::run($body, $post_types, $key_id,
            $attestation === self::SIGN
                ? CadenceAttest::header('/content/delete',
                    CadenceAttest::fields('/content/delete', $body), $key_id)
                : $attestation);
    }

    private function assertRefused(string $code, array $r): void {
        $this->assertFalse($r['ok']);
        $this->assertSame($code, $r['code'], $r['reason'] ?? '');
        $this->assertSame([], WpStub::$trashed, 'a refusal trashed a post');
        $this->assertArrayHasKey($code, CadenceRestRoute::STATUS);
        $this->assertContains($code, CadenceDeleteRequest::REFUSAL_CODES);
    }

    public function test_a_draft_this_connector_created_is_trashed(): void {
        $r = $this->delete($this->body());
        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertSame(['post_id' => self::ID, 'post_status' => 'trash'], $r['report']);
        $this->assertSame([self::ID], WpStub::$trashed);
        $answer = CadenceRestRoute::respond($r);
        $this->assertSame(200, $answer['status']);
        foreach (CadenceDeleteRequest::REPLY_FIELDS as $name) {
            $this->assertArrayHasKey($name, $answer['body'], $name . ' is missing from the reply');
        }
    }

    /** @return array<string, array{0: array}> */
    public static function missing_ids(): array {
        return ['no post_id' => [['piece_id' => 'piece-1']],
                'post_id a string' => [['piece_id' => 'piece-1', 'post_id' => (string) self::ID]],
                'post_id zero' => [['piece_id' => 'piece-1', 'post_id' => 0]],
                'no piece_id' => [['post_id' => self::ID]],
                'blank piece_id' => [['piece_id' => ' ', 'post_id' => self::ID]],
                'empty body' => [[]]];
    }

    /** Twin: the answering test, which names both. */
    #[DataProvider('missing_ids')]
    public function test_a_missing_id_is_refused(array $body): void {
        $this->assertRefused('bad_delete', $this->delete($body, CadenceAttest::KEY_ID, null));
    }

    /** @return array<string, array{0: string}> */
    public static function not_drafts(): array {
        return ['published' => ['publish'], 'scheduled' => ['future'],
                'pending' => ['pending'], 'private' => ['private']];
    }

    /** Twin: the answering test, a draft. */
    #[DataProvider('not_drafts')]
    public function test_a_post_that_is_not_a_draft_is_refused(string $status): void {
        WpStub::$posts[self::ID]['post_status'] = $status;
        $r = $this->delete($this->body());
        $this->assertRefused('delete_not_draft', $r);
        $this->assertStringContainsString('`' . $status . '`', $r['reason']);
        $this->assertSame($status, WpStub::$posts[self::ID]['post_status']);
    }

    /** THE STATUS IS READ AGAIN UNDER THE LOCK: published by hand in between. */
    public function test_a_draft_published_while_the_lock_was_awaited_is_refused(): void {
        WpStub::$on_lock = static function (): void {
            WpStub::$posts[self::ID]['post_status'] = 'publish';
        };
        $this->assertRefused('delete_not_draft', $this->delete($this->body()));
    }

    /**
     * NOT CREATED BY THE CONNECTOR: an adopted draft carries the piece and key
     * stamps but never the creation record. This is the test that catches the
     * creation check being dropped.
     */
    public function test_an_adopted_draft_is_refused(): void {
        unset(WpStub::$meta[self::ID][CadenceContentRequest::CREATED_META]);
        WpStub::$meta[self::ID][CadenceAdoptRequest::ADOPTED_META] = '{"adopted":true}';
        $r = $this->delete($this->body());
        $this->assertRefused('delete_not_created', $r);
        $this->assertSame('draft', WpStub::$posts[self::ID]['post_status']);
    }

    public function test_a_creation_record_with_another_value_is_refused(): void {
        WpStub::$meta[self::ID][CadenceContentRequest::CREATED_META] = 'yes';
        $this->assertRefused('delete_not_created', $this->delete($this->body()));
    }

    /** A post with no connector stamp at all: out of scope, before anything finer. */
    public function test_a_post_the_connector_never_touched_is_refused(): void {
        WpStub::$meta[self::ID] = [];
        $this->assertRefused('post_out_of_scope', $this->delete($this->body()));
    }

    public function test_a_post_that_is_not_there_is_refused(): void {
        $this->assertRefused('post_out_of_scope', $this->delete($this->body(['post_id' => 999])));
    }

    public function test_another_keys_draft_is_refused(): void {
        WpStub::$meta[self::ID][CadenceContentRequest::KEY_META] = 'othertenant00key';
        $this->assertRefused('post_out_of_scope', $this->delete($this->body()));
    }

    public function test_a_trashed_post_is_refused(): void {
        WpStub::$posts[self::ID]['post_status'] = 'trash';
        $this->assertRefused('post_out_of_scope', $this->delete($this->body()));
    }

    /** Twin: the answering test, naming the piece the post holds. */
    public function test_a_stale_piece_id_is_refused(): void {
        $this->assertRefused('identifier_mismatch', $this->delete($this->body(['piece_id' => 'piece-0'])));
    }

    /** Twin: the answering test, whose key reaches every type. */
    public function test_a_post_of_a_type_outside_the_keys_scope_is_refused(): void {
        $this->assertRefused('existing_post_type_out_of_scope',
            $this->delete($this->body(), CadenceAttest::KEY_ID, self::SIGN, ['page']));
    }

    /** Twin: the answering test, signed over the post it sends. */
    public function test_a_post_id_changed_after_signing_is_refused(): void {
        $r = $this->delete($this->body(['post_id' => 32]), CadenceAttest::KEY_ID,
            CadenceAttest::header('/content/delete',
                CadenceAttest::fields('/content/delete', $this->body()), CadenceAttest::KEY_ID));
        $this->assertRefused(CadenceAttestation::CODE, $r);
        $this->assertSame('mismatch', $r['attestation_branch']);
    }

    public function test_an_unsigned_delete_is_refused(): void {
        CadenceAttest::header('/content/delete', CadenceAttest::fields('/content/delete', $this->body()),
                              CadenceAttest::KEY_ID);
        $r = $this->delete($this->body(), CadenceAttest::KEY_ID, null);
        $this->assertRefused(CadenceAttestation::CODE, $r);
        $this->assertSame('absent', $r['attestation_branch']);
    }

    /** THE ROUTE TAKES NO EXEMPTION. */
    public function test_an_exempt_key_deleting_unsigned_is_refused(): void {
        CadenceAttest::header('/content/delete', CadenceAttest::fields('/content/delete', $this->body()),
                              CadenceAttest::KEY_ID);
        CadenceKey::set_unsigned_ok(CadenceAttest::KEY_ID, true, 3);
        $r = $this->delete($this->body(), CadenceAttest::KEY_ID, null);
        $this->assertRefused(CadenceAttestation::CODE, $r);
        $this->assertSame('exempt_refused', $r['attestation_branch']);
    }

    public function test_no_transaction_is_refused(): void {
        $GLOBALS['wpdb']->fails_on = 'START TRANSACTION';
        $this->assertRefused('no_row_lock', $this->delete($this->body()));
    }

    public function test_a_trash_wordpress_refuses_rolls_back(): void {
        WpStub::$trash_fails = true;
        $r = $this->delete($this->body());
        $this->assertFalse($r['ok']);
        $this->assertSame('delete_failed', $r['code']);
        $this->assertSame('draft', WpStub::$posts[self::ID]['post_status']);
        $this->assertNotSame([], $GLOBALS['wpdb']->statements('ROLLBACK'));
    }
}
