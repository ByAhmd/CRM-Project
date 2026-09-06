<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Replaces the merge tags of a subject and a body with the recipient's real
 * values (decision D-10).
 *
 * `{{ tag }}` is matched whitespace-tolerantly. Values are substituted RAW:
 * the renderer neither escapes nor formats, because the same text is stored
 * on the activity, shown in the modal preview and printed by the mail view,
 * and each of those escapes for its own medium — the mail view prints the
 * body with `nl2br(e(...))`. Unknown tags become an empty string and are
 * reported, so a typo in a template never reaches a recipient as literal
 * braces. The subject is a single line: line breaks are collapsed to spaces.
 */
final class EmailTemplateRenderer
{
    private const string TAG_PATTERN = '/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/';

    public function __construct(
        private readonly MergeTags $tags,
    ) {}

    public function render(string $subject, string $body, Model $recipient, User $sender): RenderedEmail
    {
        $values = $this->tags->resolve($recipient, $sender);
        $unknown = [];

        // Collapse the subject after substitution so a blanked tag never leaves a stray space behind.
        $subject = self::singleLine($this->substitute($subject, $values, $unknown));
        $body = $this->substitute($body, $values, $unknown);

        return new RenderedEmail($subject, $body, array_values(array_unique($unknown)));
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $unknown
     */
    private function substitute(string $text, array $values, array &$unknown): string
    {
        $result = preg_replace_callback(self::TAG_PATTERN, static function (array $match) use ($values, &$unknown): string {
            $tag = $match[1];

            if (array_key_exists($tag, $values)) {
                return $values[$tag];
            }

            $unknown[] = $tag;

            return '';
        }, $text);

        return $result ?? $text;
    }

    /** A subject must be one line: line breaks become spaces and runs of whitespace collapse. */
    public static function singleLine(string $subject): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\r", "\n"], ' ', $subject)));
    }
}
