<?php

declare(strict_types=1);

namespace Tests\Feature\Qa;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A component whose label is hidden still announces it to screen readers
 * (Filament renders it sr-only). Without an explicit ->label(__(...)) Filament
 * falls back to the English headline of the component's name — «Page thread»,
 * «Stage logs» — inside an Arabic page, against the constitution's "no
 * user-facing string in code". So every ->hiddenLabel() in app/Filament sits
 * in a chain that also sets ->label(), or on a component built by a helper
 * that sets it (listed below, each checked by hand).
 */
final class HiddenLabelsAreTranslatedTest extends TestCase
{
    /**
     * Calls whose component comes from a helper that labels it itself.
     *
     * @var list<string>
     */
    private const array LABELLED_BY_HELPER = [
        // RoleForm::permissionList() labels its CheckboxList with permissions.groups.<group>.
        'self::permissionList(',
    ];

    #[Test]
    public function every_hidden_label_in_the_panels_is_a_translated_one(): void
    {
        $offenders = [];
        $checked = 0;

        foreach (File::allFiles(app_path('Filament')) as $file) {
            $source = $file->getContents();
            $offset = 0;

            while (($at = strpos($source, '->hiddenLabel()', $offset)) !== false) {
                $offset = $at + 1;
                $checked++;

                // The chain this call belongs to: back to the component's make(…).
                $before = substr($source, 0, $at);
                $start = strrpos($before, '::make(');
                $chain = $start === false ? $before : substr($before, $start);

                if (str_contains($chain, '->label(')) {
                    continue;
                }

                $line = substr_count($before, "\n") + 1;
                $statement = substr($before, (int) strrpos($before, "\n", -1));

                foreach (self::LABELLED_BY_HELPER as $helper) {
                    if (str_contains($statement, $helper)) {
                        continue 2;
                    }
                }

                $offenders[] = $file->getRelativePathname().':'.$line;
            }
        }

        $this->assertGreaterThan(10, $checked, 'sanity: the scan must actually find the hidden labels in app/Filament');
        $this->assertSame([], $offenders, "These hidden labels fall back to Filament's English headline of the name; add ->label(__('…')):\n".implode("\n", $offenders));
    }
}
