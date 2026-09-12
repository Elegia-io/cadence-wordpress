<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * THE REST BOUNDARY: what shape a body must be, and what HTTP answer a result is.
 *
 * WHO may call is no longer asked here. It was `current_user_can` against a
 * WordPress user; it is now `CadenceKey`, whose whole point is that there is no
 * user -- see KeyTest. What survives in this class is the body shape check the
 * old permission callback also performed, kept because a body nothing can read
 * must be refused before the handler reads it.
 */
final class RestRouteTest extends TestCase {

    /** A plan in the shape CadenceLinkRequest actually reads: a source and its translations. */
    private function body(array $ids): array {
        $langs = ['en', 'de', 'fr', 'it'];
        $post = fn (int $id, int $i): array => [
            'post_id' => $id, 'language_code' => $langs[$i],
            'element_type' => 'post_page', 'source_language_code' => $i === 0 ? null : 'en',
        ];
        $ids = array_values($ids);
        return [
            'trid' => null,
            'create_group' => true,
            'source' => $post($ids[0], 0),
            'translations' => array_map($post, array_slice($ids, 1), range(1, max(1, count($ids) - 1))),
        ];
    }

    public function test_names_posts_accepts_a_well_formed_plan(): void {
        $this->assertTrue(CadenceRestRoute::names_posts($this->body([1, 2])));
    }

    public function test_refuses_a_string_keyed_map_of_well_formed_posts(): void {
        // A JSON OBJECT DECODES TO A PHP ARRAY TOO. `{"a": {...}}` passes every
        // per-post check, so only the keys separate it from the documented list.
        $this->assertFalse(CadenceRestRoute::names_posts([
            'source' => ['post_id' => 1], 'translations' => ['a' => ['post_id' => 2]],
        ]));
    }

    public function test_refuses_a_request_that_names_no_posts(): void {
        // NOT "everything is permitted". An empty request authorises nothing.
        foreach ([[], ['trid' => 5], ['create_group' => true]] as $i => $body) {
            $this->assertFalse(CadenceRestRoute::names_posts($body), (string) $i);
        }
    }

    public function test_refuses_a_body_whose_shape_it_cannot_read(): void {
        foreach ([
            ['source' => ['post_id' => '1'], 'translations' => [['post_id' => 2]]],
            ['source' => ['post_id' => 1.0], 'translations' => [['post_id' => 2]]],
            ['source' => ['post_id' => true], 'translations' => [['post_id' => 2]]],
            ['source' => ['post_id' => 0], 'translations' => [['post_id' => 2]]],
            ['source' => ['post_id' => -1], 'translations' => [['post_id' => 2]]],
            ['source' => ['post_id' => 1], 'translations' => [['id' => 2]]],
            ['source' => ['post_id' => 1], 'translations' => 'two'],
            ['source' => 'one', 'translations' => [['post_id' => 2]]],
        ] as $i => $body) {
            $this->assertFalse(CadenceRestRoute::names_posts($body), (string) $i);
        }
    }

    /**
     * A WRITTEN PLAN IS A 200 SAYING HOW MUCH IT WROTE.
     */
    public function test_a_written_plan_answers_200(): void {
        $r = CadenceRestRoute::respond(['ok' => true, 'written' => 2]);
        $this->assertSame(200, $r['status']);
        $this->assertSame(2, $r['body']['written']);
    }

    /**
     * 201 FOR SOMETHING THAT CAME INTO EXISTENCE, 200 FOR A REPEAT that found
     * it already there. The body says `created` either way, so a caller never
     * has to read the status code to know which happened -- but a caller that
     * does read it gets the truth.
     */
    public function test_a_created_post_is_201_and_an_idempotent_repeat_is_200(): void {
        $made = CadenceRestRoute::respond(['ok' => true, 'created' => true, 'post_id' => 12]);
        $this->assertSame(201, $made['status']);
        $this->assertSame(12, $made['body']['post_id']);
        $this->assertTrue($made['body']['created']);

        $again = CadenceRestRoute::respond(['ok' => true, 'created' => false, 'post_id' => 12]);
        $this->assertSame(200, $again['status']);
        $this->assertSame(12, $again['body']['post_id']);
        $this->assertFalse($again['body']['created']);
    }

    /**
     * THE TWO KINDS OF REFUSAL ARE DIFFERENT HTTP ANSWERS, because they call
     * for opposite things from the caller: 409 means the site disagreed with
     * the plan, so re-read and try again; 400 means the plan is wrong however
     * many times it is sent.
     */
    public function test_a_stale_plan_is_409_and_a_wrong_one_is_400(): void {
        foreach (['group_unknown', 'already_grouped', 'group_disagreement'] as $code) {
            $r = CadenceRestRoute::respond(['ok' => false, 'code' => $code, 'reason' => 'x']);
            $this->assertSame(409, $r['status'], $code);
            $this->assertSame($code, $r['body']['code']);
            $this->assertSame('x', $r['body']['reason']);
        }
        foreach (['bad_plan', 'contradictory_instructions', 'no_group_named'] as $code) {
            $this->assertSame(400, CadenceRestRoute::respond(
                ['ok' => false, 'code' => $code, 'reason' => 'x'])['status'], $code);
        }
    }

    /**
     * EVERY REFUSAL THE WRITER CAN PRODUCE IS CLASSIFIED. Without this, adding
     * a seventh refusal over in CadenceLinkRequest and not adding it here is
     * caught by nothing -- the mapping keeps passing its own tests, which only
     * ever ask about the codes it already knows.
     */
    public function test_every_published_refusal_code_is_mapped(): void {
        $published = array_merge(
            CadenceLinkRequest::REFUSAL_CODES,
            CadenceContentRequest::REFUSAL_CODES,
            CadenceReplaceRequest::REFUSAL_CODES
        );
        $this->assertNotEmpty($published);
        foreach ($published as $code) {
            // Asked of the TABLE, not of the status the table produces. 500 is
            // both "nobody classified this" and the right answer for
            // `insert_failed`, so a test keying on the number cannot tell a
            // deliberate 500 from an unclassified one.
            $this->assertArrayHasKey($code, CadenceRestRoute::STATUS, $code . ' is not classified');
        }
        // And nothing is classified that no writer emits, which is how a code
        // renamed on one side and not the other shows up.
        $this->assertSame([], array_diff(array_keys(CadenceRestRoute::STATUS), $published));
    }

    /**
     * A POST THE CREDENTIAL DOES NOT REACH IS A 403, and is neither of the two
     * answers it would otherwise collapse into.
     *
     * Not 400: the body is well formed, and a caller told its request is wrong
     * re-reads its own JSON forever over a request that will never be the
     * problem. Not 409 either, which means "re-read this site and try again" --
     * a post does not become one this connector published by being asked about
     * twice, so that retry is one the status invited and cannot succeed.
     */
    public function test_a_post_outside_the_scope_is_403_and_not_400_or_409(): void {
        $r = CadenceRestRoute::respond(
            ['ok' => false, 'code' => 'post_out_of_scope', 'reason' => 'x']);
        $this->assertSame(403, $r['status']);
        $this->assertSame('post_out_of_scope', $r['body']['code']);
        $this->assertFalse($r['body']['ok']);
        $this->assertNotSame(400, $r['status']);
        $this->assertNotSame(409, $r['status']);
    }

    /**
     * A SITE THAT CANNOT DO THIS AT ALL IS A 503, not a 400 blaming the request
     * and not a 409 inviting a retry that cannot succeed until someone installs
     * WPML.
     */
    public function test_a_site_without_wpml_is_503(): void {
        $r = CadenceRestRoute::respond(['ok' => false, 'code' => 'wpml_unavailable', 'reason' => 'x']);
        $this->assertSame(503, $r['status']);
    }

    /**
     * THE REPORT REACHES THE WIRE FLAT, from the linking route as from the
     * other one. The caller's verifier reads `linked` from the body; a report
     * left nested under `report` is a field it cannot see, and the merge is the
     * only thing that puts it there. `written` survives beside it -- it is how
     * many writes were ISSUED, and the gap between it and `linked` is the
     * signal, so neither may swallow the other.
     */
    public function test_a_linking_reports_fields_are_merged_into_the_body(): void {
        $r = CadenceRestRoute::respond(['ok' => true, 'written' => 2, 'report' => [
            'piece_id' => 'piece-1', 'post_id' => 12,
            'placed' => [], 'linked' => ['de'], 'refused' => [],
        ]]);
        $this->assertSame(200, $r['status']);
        $this->assertArrayNotHasKey('report', $r['body']);
        $this->assertSame(['connector_version' => CadenceRestRoute::VERSION,
                           'reply_schema' => CadenceRestRoute::REPLY_SCHEMA,
                           'ok' => true, 'written' => 2, 'piece_id' => 'piece-1',
                           'post_id' => 12, 'placed' => [], 'linked' => ['de'],
                           'refused' => []], $r['body']);
    }

    /**
     * EVERY REPLY CARRIES WHICH VERSION ANSWERED, AND WHETHER ITS SHAPE IS ONE
     * THE SPINE CAN READ -- a success as much as a refusal, since a
     * half-upgraded fleet needs both told apart no matter which kind of
     * connector answers. Asked of the constants, not of a literal: a bump to
     * either constant should not need this test rewritten, only reread as
     * true.
     */
    public function test_every_reply_carries_the_connector_version_and_reply_schema(): void {
        foreach ([
            ['ok' => true, 'written' => 2],
            ['ok' => true, 'created' => true, 'post_id' => 1],
            ['ok' => false, 'code' => 'bad_plan', 'reason' => 'x'],
            ['ok' => false, 'code' => 'invented_later'],
        ] as $i => $result) {
            $body = CadenceRestRoute::respond($result)['body'];
            $this->assertSame(CadenceRestRoute::VERSION, $body['connector_version'] ?? null, (string) $i);
            $this->assertSame(CadenceRestRoute::REPLY_SCHEMA, $body['reply_schema'] ?? null, (string) $i);
        }
    }

    /**
     * A HALF-APPLIED CREATE TAKES ITS COUNT AND ITS REPORT TO THE WIRE. The
     * linking route's create path can refuse with the source already written,
     * and this is the one refusal body that is not just `{ok, code, reason}`: a
     * caller whose ledger saw only the code would file nothing for a run that
     * changed the site, which is the failure the report exists to prevent one
     * boundary over.
     *
     * A 500 AND NOT A 409, though a re-read is the next step for both codes. The
     * likeliest cause is that WPML here does not answer a read with the trid a
     * write in the same request just invented -- in which case every create
     * fails identically and a caller retrying a 409 loops.
     */
    public function test_a_half_applied_create_carries_its_count_and_report(): void {
        foreach (['source_group_unset', 'source_group_unreadable'] as $code) {
            $r = CadenceRestRoute::respond(['ok' => false, 'code' => $code,
                'reason' => 'x', 'written' => 1, 'report' => [
                    'piece_id' => 'piece-1', 'post_id' => 12,
                    'placed' => [], 'linked' => [], 'refused' => [],
                ]]);
            $this->assertSame(500, $r['status'], $code);
            $this->assertArrayNotHasKey('report', $r['body'], $code);
            // The meta pair leads, because `respond` builds this body as
            // `$meta + ...` and `assertSame` over arrays compares order too.
            // Asked of the constants so a bump rereads as true (#1273).
            $this->assertSame(['connector_version' => CadenceRestRoute::VERSION,
                               'reply_schema' => CadenceRestRoute::REPLY_SCHEMA,
                               'ok' => false, 'code' => $code, 'reason' => 'x',
                               'written' => 1, 'piece_id' => 'piece-1',
                               'post_id' => 12, 'placed' => [], 'linked' => [],
                               'refused' => []], $r['body'], $code);
        }
    }

    /**
     * AND A REFUSAL THAT WROTE NOTHING SENDS NO COUNT. `written: 0` beside a
     * refusal reads as a measurement, and every other refusal in this plugin
     * never reached a write to count -- the absence is the honest shape.
     */
    public function test_a_refusal_that_wrote_nothing_carries_no_count(): void {
        $r = CadenceRestRoute::respond(['ok' => false, 'code' => 'already_grouped', 'reason' => 'x']);
        $this->assertSame(['connector_version' => CadenceRestRoute::VERSION,
                           'reply_schema' => CadenceRestRoute::REPLY_SCHEMA,
                           'ok' => false, 'code' => 'already_grouped',
                           'reason' => 'x'], $r['body']);
        $this->assertArrayNotHasKey('written', $r['body']);
    }

    /**
     * AND AN UNCLASSIFIED ONE IS NOT A SUCCESS. The test above turns red when
     * a code is added unmapped; this one says what happens in the meantime on a
     * live site. Falling back to 400 would tell the caller its plan is wrong
     * when nothing here knows that; falling back to 200 would report a write
     * that did not happen. 500 is the honest answer: this server refused and
     * cannot say why.
     */
    public function test_an_unmapped_refusal_is_a_server_error_not_a_success(): void {
        foreach ([['ok' => false, 'code' => 'invented_later', 'reason' => 'x'],
                  ['ok' => false, 'reason' => 'x'],
                  ['ok' => false]] as $i => $refusal) {
            $r = CadenceRestRoute::respond($refusal);
            $this->assertSame(500, $r['status'], (string) $i);
            $this->assertArrayNotHasKey('written', $r['body']);
        }
    }

    /**
     * A REWRITE IS A DIFFERENT ANSWER FROM A BAD REQUEST. The three below say
     * the site disagrees with what the caller believed, and a caller that
     * re-reads may find the disagreement gone; `bad_replacement` is wrong
     * however many times it is sent, and `update_failed` is this server's own.
     */
    public function test_a_stale_replacement_is_409_and_a_malformed_one_is_400(): void {
        foreach (['post_missing', 'identifier_mismatch', 'revision_mismatch'] as $code) {
            $r = CadenceRestRoute::respond(['ok' => false, 'code' => $code, 'reason' => 'x']);
            $this->assertSame(409, $r['status'], $code);
            $this->assertSame($code, $r['body']['code']);
        }
        $this->assertSame(400, CadenceRestRoute::respond(
            ['ok' => false, 'code' => 'bad_replacement', 'reason' => 'x'])['status']);
        $this->assertSame(500, CadenceRestRoute::respond(
            ['ok' => false, 'code' => 'update_failed', 'reason' => 'x'])['status']);
    }

    /**
     * THE ANSWER CARRIES THE REVISION. `respond` copies a named set of keys out
     * of the result and drops everything else -- silently, and with a 200 --
     * so a revision the writer computed and this table has not been told about
     * never reaches the caller, and the caller cannot make its next
     * replacement at all.
     */
    public function test_a_rewrite_answers_200_and_carries_the_new_revision(): void {
        $r = CadenceRestRoute::respond(['ok' => true, 'post_id' => 12,
                                        'created' => false, 'revision' => 'sha256:abc']);
        $this->assertSame(200, $r['status']);
        $this->assertSame('sha256:abc', $r['body']['revision'] ?? null,
            'the answer dropped the key the caller needs to replace this post next time');
        $this->assertSame(12, $r['body']['post_id']);
    }

    /**
     * THE `ABSPATH` GUARD ACTUALLY GUARDS. It is one line of boilerplate that
     * says `exit`, which is exactly the kind of line that gets the constant
     * name wrong and is never noticed, because in the suite ABSPATH is defined
     * and the guard never fires. Fire it: load each include in a fresh
     * interpreter with no ABSPATH and assert the class did not come into
     * existence.
     */
    #[DataProvider('includes')]
    public function test_an_include_reached_directly_defines_nothing(string $file, string $class): void {
        $script = sprintf(
            'require %s; var_dump(class_exists(%s, false));',
            var_export(dirname(__DIR__) . '/includes/' . $file, true),
            var_export($class, true)
        );
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');
        $this->assertSame('', trim((string) $out), 'the guard let execution reach the class');

        // THE TWIN, in the same interpreter shape: with ABSPATH defined the
        // very same require does define the class -- so the assertion above is
        // the guard firing, not a broken subprocess.
        $armed = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("define('ABSPATH','/wp/'); " . $script) . ' 2>&1');
        $this->assertSame('bool(true)', trim((string) $armed));
    }

    public static function includes(): array {
        return [
            ['class-cadence-rest-route.php', 'CadenceRestRoute'],
            ['class-cadence-link-request.php', 'CadenceLinkRequest'],
            ['class-cadence-content-request.php', 'CadenceContentRequest'],
            ['class-cadence-key.php', 'CadenceKey'],
            ['class-cadence-language-declaration.php', 'CadenceLanguageDeclaration'],
            ['class-cadence-admin.php', 'CadenceAdmin'],
            ['class-cadence-replace-request.php', 'CadenceReplaceRequest'],
            ['class-cadence-revision.php', 'CadenceRevision'],
        ];
    }

    /**
     * THE TWIN: the same eight bodies with the id made well-formed are read.
     * Without this the test above passes on a `names_posts` that returns false
     * unconditionally.
     */
    public function test_a_well_formed_id_in_the_same_shape_is_read(): void {
        $body = ['source' => ['post_id' => 1], 'translations' => [['post_id' => 4]]];
        $this->assertTrue(CadenceRestRoute::names_posts($body));
        $this->assertSame([1, 4], CadenceRestRoute::post_ids($body));
    }

    /**
     * THE SET AUTHORISED IS THE SET WRITTEN, checked against the writer rather
     * than against this file's idea of the writer.
     *
     * The two read the same body independently -- `post_ids` for the ids the
     * boundary sees, `CadenceLinkRequest` for the posts to link -- so
     * nothing but this test stops them drifting apart, and drift here is the
     * whole failure: a caller authorised for post 1 while post 2 is the one
     * that moves. An earlier draft of this file invented a `posts` key the
     * writer has never read, which would have authorised nothing at all while
     * every test on this page passed.
     */
    #[Group('wpml')]
    public function test_the_ids_authorised_are_exactly_the_ids_written(): void {
        WpStub::reset();
        WpStub::add_post(1, 'page', 'en', null);
        WpStub::add_post(2, 'page', 'de', null);
        WpStub::add_post(3, 'page', 'fr', null);
        // Posts this connector published: what `translation.link` reaches. The
        // comparison below is between the ids the boundary READS and the ids
        // the writer WRITES, and it is the write side that the scope gates --
        // so without this the writer refuses and there is nothing to compare.
        foreach ([1, 2, 3] as $id) {
            WpStub::cadence_published($id);
        }
        $body = $this->body([1, 2, 3]);

        $this->assertTrue(CadenceRestRoute::names_posts($body));
        $result = CadenceLinkRequest::run($body, null, CadenceAttest::KEY_ID,
            CadenceAttest::link_header($body, CadenceAttest::KEY_ID));
        $this->assertTrue($result['ok'], $result['reason'] ?? '');

        $authorised = CadenceRestRoute::post_ids($body);
        $written    = array_column(WpStub::$writes, 'element_id');
        sort($authorised);
        sort($written);
        $this->assertSame($written, $authorised);
        $this->assertNotEmpty($written);
    }
}
