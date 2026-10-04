<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Services\Tasks\CommentDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Decision D-20: the mention-aware comment editor sends a TipTap document;
 * CommentDraft reduces it to what TaskService stores — plain text and the ids
 * of the users it mentions. Paragraphs and hard breaks become newlines, text
 * keeps its words and loses its marks, a mention becomes "@" and the user's
 * current name read from the database (never the label the browser sent),
 * and every other node gives its text only.
 */
final class CommentDraftTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_empty_or_missing_document_is_an_empty_draft(): void
    {
        foreach ([
            null,
            '',
            'plain text is not a document',
            42,
            [],
            ['type' => 'doc'],
            ['type' => 'doc', 'content' => []],
            self::doc(self::paragraph()),
            self::doc(self::paragraph(self::text('   ')), self::paragraph()),
        ] as $document) {
            $draft = CommentDraft::fromDocument($document);

            $this->assertSame('', $draft->text, var_export($document, true));
            $this->assertSame([], $draft->mentionedUserIds);
        }
    }

    #[Test]
    public function paragraphs_and_hard_breaks_become_newlines_and_the_text_is_trimmed(): void
    {
        $draft = CommentDraft::fromDocument(self::doc(
            self::paragraph(),
            self::paragraph(self::text('  First line'), ['type' => 'hardBreak'], self::text('same paragraph')),
            self::paragraph(),
            self::paragraph(self::text('Third paragraph  ')),
            self::paragraph(),
        ));

        $this->assertSame("First line\nsame paragraph\n\nThird paragraph", $draft->text);
        $this->assertSame([], $draft->mentionedUserIds);
    }

    #[Test]
    public function a_mention_is_written_with_the_users_current_name_whatever_label_was_sent(): void
    {
        $sara = User::factory()->create(['name' => 'Sara Ali']);

        $draft = CommentDraft::fromDocument(self::doc(self::paragraph(
            self::text('Please check, '),
            self::mention($sara->getKey(), 'The CEO says pay me'),
            self::text('.'),
        )));

        $this->assertSame('Please check, @Sara Ali.', $draft->text);
        $this->assertSame([$sara->getKey()], $draft->mentionedUserIds);

        // Renamed since: the next comment carries the new name.
        $sara->update(['name' => 'سارة علي']);

        $renamed = CommentDraft::fromDocument(self::doc(self::paragraph(self::mention((string) $sara->getKey(), 'Sara Ali'))));

        $this->assertSame('@سارة علي', $renamed->text);
    }

    #[Test]
    public function mentions_are_collected_once_in_the_order_they_first_appear(): void
    {
        $omar = User::factory()->create(['name' => 'Omar']);
        $huda = User::factory()->create(['name' => 'Huda']);

        $draft = CommentDraft::fromDocument(self::doc(
            self::paragraph(self::mention($huda->getKey()), self::text(' and '), self::mention($omar->getKey())),
            self::paragraph(self::text('again '), self::mention($huda->getKey())),
        ));

        $this->assertSame("@Huda and @Omar\nagain @Huda", $draft->text);
        $this->assertSame([$huda->getKey(), $omar->getKey()], $draft->mentionedUserIds);
    }

    #[Test]
    public function a_mention_of_no_user_a_deleted_user_or_another_trigger_contributes_nothing(): void
    {
        $gone = User::factory()->create(['name' => 'Gone User']);
        $gone->delete();

        $draft = CommentDraft::fromDocument(self::doc(self::paragraph(
            self::text('A'),
            self::mention(999999, 'Ghost'),
            self::text('B'),
            self::mention($gone->getKey(), 'Gone User'),
            self::text('C'),
            ['type' => 'mention', 'attrs' => ['id' => 'abc', 'label' => 'Letters']],
            ['type' => 'mention', 'attrs' => ['id' => '-4', 'label' => 'Negative']],
            ['type' => 'mention', 'attrs' => ['label' => 'No id']],
            ['type' => 'mention', 'attrs' => ['id' => (string) User::factory()->create(['name' => 'Hash'])->getKey(), 'label' => 'Hash', 'char' => '#']],
            self::text('D'),
        )));

        $this->assertSame('ABCD', $draft->text);
        $this->assertSame([], $draft->mentionedUserIds);
    }

    #[Test]
    public function unknown_nodes_give_their_text_only_and_nested_marks_are_dropped(): void
    {
        $lina = User::factory()->create(['name' => 'Lina']);

        $draft = CommentDraft::fromDocument(self::doc(
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [self::text('Heading words')]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [self::paragraph(self::text('first item'))]],
                ['type' => 'listItem', 'content' => [self::paragraph(self::text('second '), self::mention($lina->getKey()))]],
            ]],
            ['type' => 'blockquote', 'content' => [self::paragraph(
                self::text('bold and italic link', [
                    ['type' => 'bold'],
                    ['type' => 'italic'],
                    ['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']],
                ]),
            )]],
            ['type' => 'image', 'attrs' => ['src' => 'https://example.com/x.png', 'alt' => 'ignored']],
            ['type' => 'horizontalRule'],
            ['type' => 'mysteryWidget', 'attrs' => ['text' => 'not text'], 'content' => [self::text('inner words'), 'not a node', 7]],
            self::paragraph(self::text('<script>alert(1)</script> stays plain text')),
        ));

        $this->assertSame(
            "Heading words\nfirst item\nsecond @Lina\nbold and italic link\ninner words\n<script>alert(1)</script> stays plain text",
            $draft->text,
        );
        $this->assertSame([$lina->getKey()], $draft->mentionedUserIds);
    }

    #[Test]
    public function pasted_headings_and_code_blocks_keep_their_own_lines(): void
    {
        $draft = CommentDraft::fromDocument(self::doc(
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [self::text('Agenda')]],
            ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [self::text('Stock count')]],
            ['type' => 'codeBlock', 'content' => [self::text('aisle 3')]],
            self::paragraph(self::text('Thanks')),
        ));

        $this->assertSame("Agenda\nStock count\naisle 3\nThanks", $draft->text, 'two text blocks in a row never run their words together');
    }

    #[Test]
    public function a_forged_document_nested_beyond_the_limit_is_read_down_to_the_limit_only(): void
    {
        $node = self::paragraph(self::text('too deep'));

        for ($depth = 0; $depth < CommentDraft::MAX_DEPTH + 5; $depth++) {
            $node = ['type' => 'blockquote', 'content' => [$node]];
        }

        $draft = CommentDraft::fromDocument(self::doc(self::paragraph(self::text('Shallow')), $node));

        $this->assertSame('Shallow', $draft->text);
    }

    /**
     * @param  array<string, mixed>  ...$blocks
     * @return array<string, mixed>
     */
    private static function doc(array ...$blocks): array
    {
        return ['type' => 'doc', 'content' => $blocks];
    }

    /**
     * @param  array<string, mixed>  ...$inline
     * @return array<string, mixed>
     */
    private static function paragraph(array ...$inline): array
    {
        return $inline === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $inline];
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return array<string, mixed>
     */
    private static function text(string $text, array $marks = []): array
    {
        return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mention(int|string $id, string $label = ''): array
    {
        return ['type' => 'mention', 'attrs' => ['id' => (string) $id, 'label' => $label, 'char' => '@']];
    }
}
