<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * THE CONFIRMED-REWRITE HISTORY ON THE POST EDIT SCREEN.
 *
 * What the record says is `CadenceReplaceRequest::confirmations`, asserted in
 * `ReplaceRequestTest`. What is under test here is the box: who sees it, on
 * which posts, that it prints each kind's wording escaped and newest first,
 * and that it carries nothing a browser could submit.
 */
final class RewriteHistoryBoxTest extends TestCase {

    private const ID = 41;

    protected function setUp(): void {
        WpStub::reset();
        WpHooks::$actions = [];
    }

    private function spend(string $digest, ?string $kind): void {
        add_post_meta(self::ID, CadenceReplaceRequest::SPENT_META, $digest);
        if ($kind !== null) {
            add_post_meta(self::ID, CadenceReplaceRequest::KIND_META,
                wp_json_encode(['spent' => $digest, 'kind' => $kind]));
        }
    }

    private function canEdit(int $id = self::ID): void {
        WpStub::$capabilities['edit_post'] = [$id];
    }

    private function register(string $post_type = 'post', int $id = self::ID): void {
        CadenceAdmin::history_box($post_type, new WP_Post($id, post_type: $post_type));
    }

    private function render(int $id = self::ID): string {
        ob_start();
        CadenceAdmin::history_render(new WP_Post($id));
        return (string) ob_get_clean();
    }

    public function test_boot_hooks_the_box_onto_every_edit_screen(): void {
        CadenceAdmin::boot();
        $this->assertSame([[CadenceAdmin::class, 'history_box']], WpHooks::$actions['add_meta_boxes'] ?? []);
    }

    public static function kinds(): array {
        return [
            'text'        => [null, 'client confirmed this text'],
            'retranslate' => ['retranslate', 'client asked for a new translation'],
            'slug'        => ['slug', 'client confirmed this slug change'],
            'unreadable'  => ['machine', 'the kind of confirmation could not be read'],
        ];
    }

    /** Each kind registers the box and prints its own wording. */
    #[DataProvider('kinds')]
    public function test_each_kind_is_shown_in_its_own_words(?string $kind, string $note): void {
        $this->spend('d1', $kind);
        $this->canEdit();
        $this->register();
        $this->assertCount(1, WpStub::$meta_boxes);
        $this->assertSame('cadence_rewrite_history', WpStub::$meta_boxes[0][0]);
        $this->assertSame('Confirmed rewrites', WpStub::$meta_boxes[0][1]);
        $this->assertStringContainsString('<li>' . $note . '</li>', $this->render());
    }

    /** The admin wording is the record's wording, kind for kind, on an untranslated site. */
    public function test_every_recorded_kind_has_its_label(): void {
        foreach (CadenceReplaceRequest::KIND_NOTES as $kind => $note) {
            $this->assertSame($note, CadenceAdmin::history_label($kind), $kind);
        }
    }

    public function test_rows_are_newest_first(): void {
        $this->spend('d1', 'text');
        $this->spend('d2', 'retranslate');
        $this->spend('d3', 'slug');
        $this->canEdit();
        $out = $this->render();
        $this->assertSame(3, substr_count($out, '<li>'));
        $slug  = strpos($out, 'client confirmed this slug change');
        $again = strpos($out, 'client asked for a new translation');
        $text  = strpos($out, 'client confirmed this text');
        $this->assertTrue($slug < $again && $again < $text, $out);
    }

    /** A translation carrying markup is printed as text, not as markup. */
    public function test_every_string_is_escaped(): void {
        WpStub::$translations = [
            'client asked for a new translation' => '<script>x()</script>',
            'Newest first.' => '<b>first</b>',
        ];
        $this->spend('d1', 'retranslate');
        $this->canEdit();
        $out = $this->render();
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringNotContainsString('<b>', $out);
        $this->assertStringContainsString('&lt;script&gt;x()&lt;/script&gt;', $out);
        $this->assertStringContainsString('&lt;b&gt;first&lt;/b&gt;', $out);
    }

    /** Read-only: nothing in the box can be submitted with the post. */
    public function test_the_box_has_no_form_fields(): void {
        $this->spend('d1', 'text');
        $this->spend('d2', 'machine');
        $this->canEdit();
        $out = $this->render();
        $this->assertNotSame('', $out);
        foreach (['<input', '<form', '<textarea', '<select', '<button'] as $field) {
            $this->assertStringNotContainsString($field, $out);
        }
    }

    /** No confirmation, no box: it is not furniture on every post. */
    public function test_a_post_with_no_confirmation_gets_no_box(): void {
        $this->canEdit();
        $this->register();
        $this->assertSame([], WpStub::$meta_boxes);
        $this->assertSame('', $this->render());
    }

    /** Without `edit_post` on THIS post, neither the box nor its contents. */
    public function test_a_user_who_cannot_edit_the_post_sees_nothing(): void {
        $this->spend('d1', 'text');
        $this->canEdit(self::ID + 1);
        $this->register();
        $this->assertSame([], WpStub::$meta_boxes);
        $this->assertSame('', $this->render());
    }

    /** THE TWIN of the gate: the same post, with `edit_post` on it, gets the box. */
    public function test_the_gate_asks_about_this_post(): void {
        $this->spend('d1', 'text');
        $this->canEdit();
        $this->register();
        $this->assertCount(1, WpStub::$meta_boxes);
        $this->assertNotSame('', $this->render());
    }

    /** No screen named, so WordPress places it on whatever type is being edited. */
    #[DataProvider('post_types')]
    public function test_any_post_type_gets_the_box(string $post_type): void {
        $this->spend('d1', 'text');
        $this->canEdit();
        $this->register($post_type);
        $this->assertCount(1, WpStub::$meta_boxes);
        $this->assertNull(WpStub::$meta_boxes[0][3]);
    }

    public static function post_types(): array {
        return ['post' => ['post'], 'page' => ['page'], 'custom' => ['event']];
    }

    public function test_something_that_is_not_a_post_gets_no_box(): void {
        $this->canEdit();
        CadenceAdmin::history_box('comment', null);
        $this->assertSame([], WpStub::$meta_boxes);
    }
}
