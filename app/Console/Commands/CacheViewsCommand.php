<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ViewCacheCommand;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\SplFileInfo;

/**
 * `view:cache` that the Blade engine can actually read on Windows.
 *
 * A compiled template is named after a hash of the *string* the compiler was
 * handed, so the warm-up and the request have to spell the source path the
 * same way. They do not:
 *
 *   - Illuminate\View\FileViewFinder::findInPaths() builds "$path.'/'.$file",
 *     so a request resolves
 *     C:\...\vendor\filament\filament\resources\views/components/sidebar/item.blade.php
 *   - Illuminate\Foundation\Console\ViewCacheCommand compiles
 *     SplFileInfo::getRealPath(), which Windows returns fully back-slashed:
 *     C:\...\vendor\filament\filament\resources\views\components\sidebar\item.blade.php
 *
 * The two strings hash differently, so on Windows every warmed template is
 * stored under a name no request ever looks up: `view:cache` appears to
 * succeed while the cache stays cold, Blade recompiles the whole Filament view
 * tree on every request, and concurrent requests (the panel page plus its
 * Livewire widget and notification polls) race to rename() the same
 * destination — which Windows refuses with "Access is denied (code: 5)" while
 * another process holds the file open.
 *
 * On Linux and macOS the two spellings are byte-identical, so this command
 * compiles exactly what the framework's does and production is unaffected.
 * Where they differ, both spellings are compiled: the framework's, so nothing
 * that reads the cache the old way regresses, and the finder's, so requests
 * hit a warm cache.
 */
#[AsCommand(name: 'view:cache')]
final class CacheViewsCommand extends ViewCacheCommand
{
    public function handle(): void
    {
        $this->callSilent('view:clear');

        $this->paths()->each(function (string $path): void {
            $prefix = $this->output->isVeryVerbose() ? '<fg=yellow;options=bold>DIR</> ' : '';

            $this->components->task($prefix.$path, null, OutputInterface::VERBOSITY_VERBOSE);

            $this->compileViewsForPath($path, $this->bladeFilesIn([$path]));
        });

        $this->newLine();

        $this->components->info('Blade templates cached successfully.');
    }

    /**
     * @param  Collection<int, SplFileInfo>  $views
     */
    private function compileViewsForPath(string $path, Collection $views): void
    {
        $compiler = $this->laravel['view']->getEngineResolver()->resolve('blade')->getCompiler();

        foreach ($views as $file) {
            $this->components->task('    '.$file->getRelativePathname(), null, OutputInterface::VERBOSITY_VERY_VERBOSE);

            $spellings = $this->spellings($path, $file);

            foreach ($spellings as $spelling) {
                $compiler->compile($spelling);
            }
        }

        if ($this->output->isVeryVerbose()) {
            $this->newLine();
        }
    }

    /**
     * Every path string a consumer may hand the compiler for this file: the
     * framework's realpath and, where it differs, the one FileViewFinder
     * produces. Identical on POSIX, so exactly one compile happens there.
     *
     * @return array<int, string>
     */
    private function spellings(string $path, SplFileInfo $file): array
    {
        $realPath = $file->getRealPath();

        $finderPath = rtrim($path, '/\\').'/'.str_replace('\\', '/', $file->getRelativePathname());

        $spellings = [];

        if (is_string($realPath) && $realPath !== '') {
            $spellings[] = $realPath;
        }

        if (! in_array($finderPath, $spellings, true)) {
            $spellings[] = $finderPath;
        }

        return $spellings;
    }
}
