<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Console\Commands\CacheViewsCommand;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * `view:cache` has to warm the cache under the same path spelling a request
 * resolves, or the cache is cold no matter what the command reports.
 *
 * A compiled template is named after a hash of the *string* handed to the
 * compiler. FileViewFinder::findInPaths() builds "$path.'/'.$file" while
 * Laravel's ViewCacheCommand compiles SplFileInfo::getRealPath(). The two are
 * byte-identical on Linux, so these assertions are cheap there — and that is
 * the point: they pin the behaviour production depends on, and they fail on
 * any platform where the spellings diverge (Windows, or a symlinked deploy
 * root) and the warm-up silently misses every template.
 *
 * Resolution goes through the finder by view name, exactly as a request does,
 * rather than re-deriving paths, so the test cannot agree with a bug by
 * repeating the warm-up's own mistake.
 *
 * @see CacheViewsCommand
 */
final class CacheViewsCommandTest extends TestCase
{
    #[Test]
    public function the_panel_view_tree_is_warm_for_the_names_a_request_resolves(): void
    {
        Artisan::call('view:cache');

        $finder = $this->viewFinder();
        $compiler = $this->bladeCompiler();

        $cold = [];
        $warm = 0;

        foreach ($this->panelViewNames() as $name) {
            $resolved = $finder->find($name);

            if (file_exists($compiler->getCompiledPath($resolved))) {
                $warm++;

                continue;
            }

            $cold[] = $name.' ('.$resolved.')';
        }

        $this->assertGreaterThan(
            50,
            $warm + count($cold),
            'The panel view tree was not discovered, so this test would pass vacuously.',
        );

        $this->assertSame(
            [],
            array_slice($cold, 0, 5),
            sprintf(
                '%d of %d panel templates are cold after view:cache. Blade then recompiles them on every request, and '
                .'concurrent requests race to rename() the same compiled file.',
                count($cold),
                $warm + count($cold),
            ),
        );
    }

    #[Test]
    public function the_warm_up_still_covers_the_realpath_spelling_the_framework_command_compiles(): void
    {
        Artisan::call('view:cache');

        $finder = $this->viewFinder();
        $compiler = $this->bladeCompiler();

        $cold = [];

        foreach ($this->panelViewNames() as $name) {
            $realPath = realpath($finder->find($name));

            if ($realPath !== false && ! file_exists($compiler->getCompiledPath($realPath))) {
                $cold[] = $realPath;
            }
        }

        $this->assertSame(
            [],
            array_slice($cold, 0, 5),
            'The override dropped coverage the framework command provided; anything reading the cache by realpath regresses.',
        );
    }

    /**
     * Every Filament template, addressed the way the panel addresses it:
     * "<namespace>::<dot.path>".
     *
     * @return iterable<string>
     */
    private function panelViewNames(): iterable
    {
        foreach ($this->viewFinder()->getHints() as $namespace => $paths) {
            if (! str_starts_with($namespace, 'filament')) {
                continue;
            }

            foreach ($paths as $path) {
                if (! is_dir($path)) {
                    continue;
                }

                foreach ($this->bladeFilesIn($path) as $file) {
                    $relative = substr($file->getPathname(), strlen($path) + 1);

                    $name = str_replace(
                        [DIRECTORY_SEPARATOR, '/'],
                        '.',
                        substr($relative, 0, -strlen('.blade.php')),
                    );

                    yield $namespace.'::'.$name;
                }
            }
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function bladeFilesIn(string $root): iterable
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                yield $file;
            }
        }
    }

    private function viewFinder(): FileViewFinder
    {
        /** @var FileViewFinder $finder */
        $finder = $this->app->make('view')->getFinder();

        return $finder;
    }

    private function bladeCompiler(): BladeCompiler
    {
        return $this->app->make(BladeCompiler::class);
    }
}
