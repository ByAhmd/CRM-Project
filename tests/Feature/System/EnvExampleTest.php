<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * .env.example is the environment reference (go-live checklist section 9):
 * every key a config file reads through env() has a `KEY=` line there, the
 * file parses as dotenv, and it carries no secret.
 */
final class EnvExampleTest extends TestCase
{
    #[Test]
    public function every_env_key_read_under_config_has_a_line_in_env_example(): void
    {
        $lines = $this->exampleKeys();
        $missing = array_values(array_diff($this->configKeys(), $lines));

        $this->assertNotEmpty($this->configKeys());
        $this->assertSame([], $missing, '.env.example lacks: '.implode(', ', $missing));
    }

    #[Test]
    public function every_key_in_env_example_carries_a_comment_above_it(): void
    {
        $uncommented = [];
        $previous = '';

        foreach (preg_split('/\R/', $this->example()) ?: [] as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $match) === 1 && $previous === '') {
                $uncommented[] = $match[1];
            }

            $previous = trim($line) === '' ? '' : $line;
        }

        $this->assertSame([], $uncommented, 'keys without a comment block above them: '.implode(', ', $uncommented));
    }

    #[Test]
    public function the_example_parses_and_holds_no_secret(): void
    {
        $values = Dotenv::parse($this->example());

        $this->assertSame('', $values['APP_KEY']);
        $this->assertSame('', $values['ADMIN_PASSWORD']);
        $this->assertSame('null', $values['MAIL_PASSWORD']);
        $this->assertSame('null', $values['CRM_TRUSTED_PROXIES']);
        $this->assertSame('log', $values['MAIL_MAILER']);
        $this->assertSame('120', $values['SESSION_LIFETIME']);

        foreach (['CRM_TRUSTED_PROXIES', 'CRM_LEAD_STALE_DAYS', 'CRM_AUDIT_RETENTION_DAYS', 'CRM_ATTACHMENT_MAX_KB', 'CRM_CURRENCY', 'CRM_TIMEZONE'] as $key) {
            $this->assertArrayHasKey($key, $values);
        }
    }

    /**
     * Keys of `env('KEY'…)` calls in config/*.php, read with the tokenizer so
     * a commented-out option is not counted and a call split over lines is.
     *
     * @return list<string>
     */
    private function configKeys(): array
    {
        $keys = [];

        foreach (Finder::create()->files()->in(config_path())->name('*.php') as $file) {
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($file->getRealPath())),
                static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
            ));

            foreach ($tokens as $index => $token) {
                $open = $tokens[$index + 1] ?? null;
                $argument = $tokens[$index + 2] ?? null;

                if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'env'
                    && $open === '(' && is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $keys[] = trim($argument[1], '\'"');
                }
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function exampleKeys(): array
    {
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $this->example(), $matches);

        return $matches[1];
    }

    private function example(): string
    {
        return (string) file_get_contents(base_path('.env.example'));
    }
}
