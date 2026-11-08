<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Step I-16.5 / M-0.3 — the module index in `.junie/modules/README.md` must match the files on
 * disk, so the docs can't list a module that was never written (or miss one that was).
 */
class ModuleDocsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // `.junie/` is gitignored, so a fresh CI checkout has no docs to check.
        if (! is_file(base_path('.junie/modules/README.md'))) {
            $this->markTestSkipped('.junie/modules is not present in this checkout.');
        }
    }

    public function test_every_module_listed_in_the_readme_exists(): void
    {
        $missing = array_filter(
            $this->listedModules(),
            fn (string $file) => ! is_file(base_path('.junie/modules/'.$file)),
        );

        $this->assertSame([], array_values($missing), 'README lists module files that don\'t exist.');
    }

    /**
     * One row per module. `plans.md` had two for a year — an "In progress" row left behind when the
     * "Done" one was added — which meant the index said a finished module was unfinished. Both the
     * checks above pass happily with a duplicate, so this is what catches it.
     */
    public function test_no_module_is_listed_twice(): void
    {
        $listed = $this->listedModules();
        $duplicates = array_keys(array_filter(array_count_values($listed), fn (int $count) => $count > 1));

        $this->assertSame([], $duplicates, 'These modules have more than one row in the README table.');
    }

    public function test_every_module_file_is_listed_in_the_readme(): void
    {
        $files = array_map('basename', glob(base_path('.junie/modules/*.md')) ?: []);
        $unlisted = array_diff($files, ['README.md'], $this->listedModules());

        $this->assertSame([], array_values($unlisted), 'Module files missing from the README table.');
    }

    /**
     * @return list<string>
     */
    private function listedModules(): array
    {
        $readme = (string) file_get_contents(base_path('.junie/modules/README.md'));

        // Whitespace-tolerant: the markdown formatter pads table cells so the columns line up in
        // the source, and the original pattern required exactly one space after the backtick. It
        // then matched nothing and reported every module as unlisted — a parser failure dressed up
        // as 33 missing files.
        preg_match_all('/^\|\s*`([a-z0-9-]+\.md)`\s*\|/m', $readme, $matches);

        return $matches[1];
    }
}
