<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Localisation;

use App\Enums\CrmRole;
use App\Filament\Exports\LeadExporter;
use App\Filament\Imports\LeadImporter;
use App\Models\Export;
use App\Models\Import;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;
use Throwable;

/**
 * RTL/LTR render walk (plan step 12): every navigation page and every
 * resource index/create/view/edit page, as a super admin, in both locales.
 *
 * Each page must answer 200, declare the locale's lang and dir on <html>, and
 * show no raw translation key ("module.group.key" or "package::group.key")
 * in its visible text.
 */
final class RenderMatrixTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function locales(): array
    {
        return [
            'arabic' => ['ar', 'rtl'],
            'english' => ['en', 'ltr'],
        ];
    }

    #[Test]
    #[DataProvider('locales')]
    public function every_panel_page_renders_in_the_locale_direction_without_raw_keys(string $locale, string $direction): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $admin = $this->makeUser(CrmRole::SuperAdmin, ['name' => 'Super Admin', 'locale' => $locale]);
        $this->actingAs($admin);

        $panel = Filament::getPanel('admin');
        $urls = [];

        foreach ($panel->getPages() as $page) {
            $urls[$page] = $page::getUrl();
        }

        foreach ($panel->getResources() as $resource) {
            $record = null;

            foreach (array_keys($resource::getPages()) as $name) {
                if (in_array($name, ['view', 'edit'], true)) {
                    $record ??= $this->recordFor($resource, $admin);

                    if ($record === null) {
                        $urls["{$resource}@{$name}"] = 'SKIPPED: no record could be made';

                        continue;
                    }

                    $urls["{$resource}@{$name}"] = $resource::getUrl($name, ['record' => $record]);

                    continue;
                }

                if ($name === 'index' || $name === 'create') {
                    $urls["{$resource}@{$name}"] = $resource::getUrl($name);
                }
            }
        }

        $failures = [];
        $skipped = [];

        foreach ($urls as $label => $url) {
            if (str_starts_with($url, 'SKIPPED')) {
                $skipped[] = "{$label}: {$url}";

                continue;
            }

            try {
                $response = $this->get($url);
            } catch (Throwable $exception) {
                $failures[] = "{$label} {$url}: threw ".$exception::class.': '.$exception->getMessage();

                continue;
            }

            if ($response->getStatusCode() !== 200) {
                $failures[] = "{$label} {$url}: HTTP ".$response->getStatusCode();

                continue;
            }

            $html = (string) $response->getContent();

            if (preg_match('/<html\b[^>]*>/i', $html, $tag) !== 1
                || ! str_contains($tag[0], "lang=\"{$locale}\"")
                || ! str_contains($tag[0], "dir=\"{$direction}\"")) {
                $failures[] = "{$label} {$url}: <html> is ".($tag[0] ?? '(none)');
            }

            $raw = $this->rawKeys($html);

            if ($raw !== []) {
                $failures[] = "{$label} {$url}: raw keys ".implode(', ', $raw);
            }
        }

        $this->assertGreaterThan(40, count($urls), 'sanity: the walk covers the panel');
        $this->assertSame(['leads.fields.name', 'filament-panels::layout.direction'], $this->rawKeys('<p>leads.fields.name <a href="https://a.b.c/x.y.z">filament-panels::layout.direction</a> mail@a.b.c</p>'), 'sanity: the detector sees raw keys');
        $this->assertSame([], $failures, "{$locale} render walk failures:\n".implode("\n", $failures)."\nskipped:\n".implode("\n", $skipped));
    }

    /**
     * @return list<string>
     */
    private function rawKeys(string $html): array
    {
        $text = (string) preg_replace(['#<script\b.*?</script>#is', '#<style\b.*?</style>#is', '#<!--.*?-->#s'], ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace(['#https?://\S+#i', '#\S+@\S+#'], ' ', $text);

        preg_match_all('/\b[a-z][a-z0-9_-]*::[a-z0-9_.-]+|\b[a-z_]+\.[a-z_]+\.[a-z_]+\b/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private function recordFor(string $resource, User $admin): ?Model
    {
        /** @var class-string<Model> $model */
        $model = $resource::getModel();

        // super_admin is a locked role (403 on edit by design): walk a record the
        // admin may update when one exists.
        $existing = $resource::getEloquentQuery()->get()->first(static fn (Model $row): bool => $admin->can('update', $row))
            ?? $resource::getEloquentQuery()->first();

        if ($existing instanceof Model) {
            return $existing;
        }

        if ($model === Import::class) {
            return Import::query()->forceCreate([
                'file_name' => 'leads.csv', 'file_path' => 'imports/leads.csv', 'importer' => LeadImporter::class,
                'total_rows' => 1, 'processed_rows' => 1, 'successful_rows' => 1, 'user_id' => $admin->getKey(),
                'completed_at' => now(),
            ]);
        }

        if ($model === Export::class) {
            return Export::query()->forceCreate([
                'file_name' => 'leads', 'file_disk' => 'local', 'exporter' => LeadExporter::class,
                'total_rows' => 1, 'processed_rows' => 1, 'successful_rows' => 1, 'user_id' => $admin->getKey(),
                'completed_at' => now(),
            ]);
        }

        if (method_exists($model, 'factory')) {
            try {
                $model::factory()->create();
            } catch (Throwable) {
                return null;
            }

            return $resource::getEloquentQuery()->first();
        }

        return null;
    }
}
