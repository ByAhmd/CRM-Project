<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Localisation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Arabic wording probes (quality pass, plan step 12 "translation audit").
 *
 * Each test pins one defect class found by reading lang/ar as a native
 * speaker; each fails while the defect is present and passes once fixed.
 */
final class ArabicWordingTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_pipeline_is_called_by_one_arabic_term_everywhere(): void
    {
        $offenders = $this->valuesMatching('ar', '/خط المبيعات|خطوط المبيعات|خط مبيعات|مسارات البيع|مسار بيع/u');

        $this->assertSame([], $offenders, "pipeline must read «مسار المبيعات» everywhere:\n".$this->describe($offenders));
    }

    #[Test]
    public function the_deal_is_called_by_one_arabic_term_everywhere(): void
    {
        $offenders = $this->valuesMatching('ar', '/فرصة بيعية|الفرص البيعية|الفرصة البيعية|فرص البيعية|لوحة الفرص|الفرص الحالية/u');

        $this->assertSame([], $offenders, "deal must read «صفقة» everywhere:\n".$this->describe($offenders));
    }

    #[Test]
    public function the_record_owner_field_has_one_arabic_label(): void
    {
        $labels = [];

        foreach ($this->flat('ar') as $key => $value) {
            if (preg_match('/\.(fields|filters|columns\.[a-z_]+|tables\.columns)\.owner$/', $key) === 1) {
                // A trailing qualifier is not a different name: the import
                // column «المسؤول (البريد الإلكتروني)» tells the reader the
                // cell holds the owner's email, and must still say «المسؤول».
                $labels[(string) preg_replace('/\s*\([^)]*\)$/u', '', $value)][] = $key;
            }
        }

        $this->assertCount(1, $labels, "owner is labelled differently across modules:\n".print_r($labels, true));
    }

    #[Test]
    public function an_account_type_does_not_reuse_the_lead_entity_name(): void
    {
        app()->setLocale('ar');

        $this->assertNotSame(__('leads.navigation.model'), __('enums.account_type.prospect'), 'the "prospect" account type reads exactly like the Lead entity');
    }

    #[Test]
    public function arabic_status_change_arrows_do_not_point_backwards_in_rtl(): void
    {
        $offenders = $this->valuesMatching('ar', '/→/u');

        $this->assertSame([], $offenders, "U+2192 is not mirrored: in RTL \":from → :to\" reads as to-from:\n".$this->describe($offenders));
    }

    #[Test]
    public function arabic_strings_quote_with_guillemets_not_ascii_double_quotes(): void
    {
        $offenders = [];

        foreach ($this->valuesMatching('ar', '/"/') as $key => $value) {
            if (! str_starts_with($key, 'validation.')) {
                $offenders[$key] = $value;
            }
        }

        $this->assertSame([], $offenders, "Arabic strings using \"…\" instead of «…»:\n".$this->describe($offenders));
    }

    #[Test]
    public function arabic_strings_use_one_digit_system_and_no_english_abbreviations(): void
    {
        $offenders = [
            ...$this->valuesMatching('ar', '/[٠-٩]/u'),
            ...$this->valuesMatching('ar', '/\be\.g\./'),
        ];

        $this->assertSame([], $offenders, "Arabic-Indic digits (the rest of the UI uses 0-9) or English abbreviations:\n".$this->describe($offenders));
    }

    #[Test]
    public function counted_nouns_are_pluralised_with_trans_choice_in_both_locales(): void
    {
        // Counts that vary at runtime need plural forms in both languages; the
        // Arabic list adds fixed or configured counts (200 rows, 7 days, 60
        // minutes, 100+ timeline entries) whose noun form the sentence gets wrong.
        $dynamic = [
            'assignment.notifications.bulk_done',
            'tasks.notifications.bulk_completed',
            'imports.notifications.completed',
            'exports.notifications.completed',
            'custom_fields.validation.delete_has_values',
            'lead_scoring_rules.fields.within_days_value',
        ];
        $keysByLocale = [
            'ar' => [
                ...$dynamic,
                'imports.helpers.failed_rows',
                'dashboard.tables.upcoming_follow_ups_description',
                'users.invitation.expiry',
                'timeline.hints.capped',
            ],
            'en' => $dynamic,
        ];
        $offenders = [];

        foreach ($keysByLocale as $locale => $keys) {
            $flat = $this->flat($locale);

            foreach ($keys as $key) {
                if (! str_contains($flat[$key] ?? '', '|')) {
                    $offenders[] = "{$locale}: {$key} => ".($flat[$key] ?? '(missing)');
                }
            }
        }

        $this->assertSame([], $offenders, "a count is interpolated next to a fixed noun (\"استُورد 3 صفًا\", \"1 tasks completed\"):\n".implode("\n", $offenders));
    }

    #[Test]
    public function the_activity_export_does_not_swap_kind_and_type_labels(): void
    {
        $flat = $this->flat('ar');

        $this->assertSame($flat['activities.fields.kind'], $flat['exports.columns.activity.kind'], 'activity "kind" is الصنف on screen but a different word in the export');
        $this->assertSame($flat['activities.fields.type'], $flat['exports.columns.activity.type'], 'activity "type" is النوع on screen but a different word in the export');
    }

    #[Test]
    public function the_paper_airplane_icon_is_not_labelled_as_a_kite(): void
    {
        $this->assertNotSame('طائرة ورقية', $this->flat('ar')['activity_types.options.icons.OutlinedPaperAirplane'], 'طائرة ورقية means "kite" in Arabic');
    }

    #[Test]
    public function lang_files_do_not_declare_the_same_key_twice_in_one_array(): void
    {
        $duplicates = [];

        foreach (['ar', 'en'] as $locale) {
            foreach (glob(lang_path($locale.'/*.php')) ?: [] as $file) {
                foreach ($this->duplicateKeys((string) file_get_contents($file)) as $key) {
                    $duplicates[] = "{$locale}/".basename($file).": {$key}";
                }
            }
        }

        $this->assertSame([], $duplicates, "a later duplicate silently overrides the earlier label:\n".implode("\n", $duplicates));
    }

    /**
     * @return list<string>
     */
    private function duplicateKeys(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $stack = [];
        $duplicates = [];

        foreach ($tokens as $index => $token) {
            if ($token === '[') {
                $stack[] = [];

                continue;
            }

            if ($token === ']') {
                array_pop($stack);

                continue;
            }

            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER], true)
                && is_array($tokens[$index + 1] ?? null) && $tokens[$index + 1][0] === T_DOUBLE_ARROW && $stack !== []) {
                $depth = count($stack) - 1;

                if (in_array($token[1], $stack[$depth], true)) {
                    $duplicates[] = $token[1];
                }

                $stack[$depth][] = $token[1];
            }
        }

        return $duplicates;
    }

    /**
     * @return array<string, string>
     */
    private function valuesMatching(string $locale, string $pattern): array
    {
        return array_filter($this->flat($locale), static fn (string $value): bool => preg_match($pattern, $value) === 1);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function describe(array $values): string
    {
        return implode("\n", array_map(static fn (string $key, string $value): string => "{$key} => {$value}", array_keys($values), $values));
    }

    /**
     * @return array<string, string>
     */
    private function flat(string $locale): array
    {
        $flat = [];

        foreach (glob(lang_path($locale.'/*.php')) ?: [] as $file) {
            $flat += $this->flatten(require $file, basename($file, '.php'));
        }

        return $flat;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = (string) $value;
        }

        return $flat;
    }
}
