<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\User;

/**
 * A task comment as the mention-aware editor wrote it (decision D-20),
 * reduced to what TaskService::comment() stores: plain text and the ids of
 * the users it mentions.
 *
 * The editor sends a TipTap document (the RichEditor's JSON state). Nothing
 * in it is trusted: paragraphs — and the other text blocks a paste can bring
 * along although the editor offers no formatting (headings, code blocks) —
 * and hard breaks become newlines, a text node contributes its text (its
 * marks — bold, links, anything nested — are dropped), a mention node
 * becomes "@" followed by the mentioned user's name as stored NOW, looked up
 * here by id — the label the browser sent is never read, so a forged label
 * cannot put words in a colleague's mouth — and every other node contributes
 * only the text inside it. A mention of an id that is
 * no user (or a deleted one) contributes nothing and is not collected. The
 * text is trimmed; the length limit (TaskService::COMMENT_TEXT_MAX) applies
 * to it there. Whether a mentioned user may be mentioned at all is
 * TaskService's rule, not this class's.
 *
 * Documents nested deeper than MAX_DEPTH are read down to that depth only, so
 * a forged document cannot exhaust the stack; the editor never nests that
 * deep.
 */
final readonly class CommentDraft
{
    /** The deepest node read; the editor's own documents stay far below it. */
    public const int MAX_DEPTH = 32;

    /**
     * The nodes that hold one line of text each, so two of them in a row
     * never run their words together.
     *
     * @var list<string>
     */
    private const array TEXT_BLOCKS = ['paragraph', 'heading', 'codeBlock'];

    /**
     * @param  list<int>  $mentionedUserIds  in the order of their first mention
     */
    private function __construct(
        public string $text,
        public array $mentionedUserIds,
    ) {}

    public static function fromDocument(mixed $document): self
    {
        if (! is_array($document)) {
            return new self('', []);
        }

        $ids = [];
        self::collectMentionIds($document, 0, $ids);

        $names = $ids === [] ? [] : User::query()
            ->whereKey($ids)
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();

        $lines = [];
        $line = null;
        self::write($document, $names, 0, $lines, $line);

        if ($line !== null) {
            $lines[] = $line;
        }

        return new self(
            trim(implode("\n", $lines)),
            array_values(array_filter($ids, static fn (int $id): bool => isset($names[$id]))),
        );
    }

    /**
     * The mentioned ids, unique, in document order.
     *
     * @param  array<mixed>  $node
     * @param  list<int>  $ids
     */
    private static function collectMentionIds(array $node, int $depth, array &$ids): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        if (($node['type'] ?? null) === 'mention') {
            $id = self::mentionId($node);

            if ($id !== null && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }

            return;
        }

        foreach (self::children($node) as $child) {
            self::collectMentionIds($child, $depth + 1, $ids);
        }
    }

    /**
     * Appends the node's text to the line being written; a text block (a
     * paragraph, a pasted heading or code block) closes the line before it
     * and its own line after it.
     *
     * @param  array<mixed>  $node
     * @param  array<int, string>  $names
     * @param  list<string>  $lines
     */
    private static function write(array $node, array $names, int $depth, array &$lines, ?string &$line): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $type = $node['type'] ?? null;

        if (in_array($type, self::TEXT_BLOCKS, true)) {
            $type = 'paragraph';
        }

        switch ($type) {
            case 'text':
                $line = ($line ?? '').(is_string($node['text'] ?? null) ? $node['text'] : '');

                return;

            case 'mention':
                $id = self::mentionId($node);

                if ($id !== null && isset($names[$id])) {
                    $line = ($line ?? '').'@'.$names[$id];
                }

                return;

            case 'hardBreak':
                $line = ($line ?? '')."\n";

                return;

            case 'paragraph':
                if ($line !== null) {
                    $lines[] = $line;
                }

                $line = '';

                foreach (self::children($node) as $child) {
                    self::write($child, $names, $depth + 1, $lines, $line);
                }

                // A forged paragraph inside a paragraph has closed the line already.
                if ($line !== null) {
                    $lines[] = $line;
                }

                $line = null;

                return;

            default:
                foreach (self::children($node) as $child) {
                    self::write($child, $names, $depth + 1, $lines, $line);
                }
        }
    }

    /**
     * The user id of an "@" mention node, or null when it carries none.
     *
     * @param  array<mixed>  $node
     */
    private static function mentionId(array $node): ?int
    {
        $attributes = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $char = $attributes['char'] ?? '@';
        $id = $attributes['id'] ?? null;

        if ($char !== '@') {
            return null;
        }

        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }

        // Digits only, short enough never to overflow an integer.
        if (is_string($id) && preg_match('/^[1-9]\d{0,17}$/', $id) === 1) {
            return (int) $id;
        }

        return null;
    }

    /**
     * The node's child nodes; anything that is not a node is skipped.
     *
     * @param  array<mixed>  $node
     * @return list<array<mixed>>
     */
    private static function children(array $node): array
    {
        $content = $node['content'] ?? null;

        if (! is_array($content)) {
            return [];
        }

        return array_values(array_filter($content, static fn (mixed $child): bool => is_array($child)));
    }
}
