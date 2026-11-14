<?php

namespace UbaidCrudGen\LaravelCrudGen\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UbaidCrudGen\LaravelCrudGen\Generator;

class GeneratorTest extends TestCase
{
    private const FIELDS = [
        'region_id' => 'foreign:Region,id,name',
        'title' => 'string',
        'para' => 'text',
        'body' => 'richtext',
        'published_on' => 'date',
        'active' => 'boolean',
        'cover' => 'image',
        'team' => ['type' => 'json', 'repeatable' => true, 'fields' => ['image' => 'image', 'title' => 'string', 'para' => 'richtext']],
        'logos' => ['type' => 'json', 'repeatable' => true, 'fields' => ['logo' => 'image']], // image-only repeater used to generate a syntax error
    ];

    private function generate(string $mode, string $format, bool $authorize = true, ?string $layout = 'layouts::admin'): array
    {
        return (new Generator('App\Models\TeamPage', self::FIELDS, ['title' => 'string', 'cover' => 'image', 'region_id' => 'foreign:Region,id,name'], [
            'mode' => $mode, 'format' => $format, 'layout' => $layout, 'emoji' => true, 'authorize' => $authorize,
            'pagesPath' => 'views/pages', 'classPath' => 'app/Livewire', 'classNamespace' => 'App\Livewire', 'viewPath' => 'views/livewire',
        ]))->files();
    }

    public static function combinations(): array
    {
        $all = [];
        foreach (['single', 'multi'] as $mode) {
            foreach (['sfc', 'mfc', 'class'] as $format) {
                foreach ([true, false] as $authorize) {
                    $all["{$mode} {$format} ".($authorize ? 'policy' : 'no-policy')] = [$mode, $format, $authorize];
                }
            }
        }

        return $all;
    }

    #[DataProvider('combinations')]
    public function test_generated_php_parses_and_has_no_leftover_placeholders(string $mode, string $format, bool $authorize): void
    {
        $files = $this->generate($mode, $format, $authorize);

        $this->assertCount($format === 'sfc' ? 1 : 2, $files);

        foreach ($files as $path => $contents) {
            if (str_starts_with($contents, '<?php')) {
                token_get_all($contents, TOKEN_PARSE); // throws ParseError on invalid PHP
            }

            $this->assertDoesNotMatchRegularExpression('/\{\{ [a-zA-Z]+ \}\}/', $contents, $path);
            $this->assertSame($authorize, str_contains(implode($files), '$this->authorize('));
        }
    }

    public function test_paths_per_format(): void
    {
        $this->assertSame(['views/pages/crud/⚡team-page.blade.php'], array_keys($this->generate('multi', 'sfc')));
        $this->assertSame(['views/pages/crud/⚡team-page/team-page.php', 'views/pages/crud/⚡team-page/team-page.blade.php'], array_keys($this->generate('multi', 'mfc')));
        $this->assertSame(['app/Livewire/Crud/TeamPageCrud.php', 'views/livewire/crud/team-page.blade.php'], array_keys($this->generate('multi', 'class')));
    }

    public function test_security_properties_of_the_output(): void
    {
        $code = implode($this->generate('multi', 'sfc'));

        $this->assertStringContainsString("#[Locked]\n    public \$editingId", $code);
        $this->assertStringNotContainsString('{!!', $code);
        $this->assertStringNotContainsString('getMessage()', $code);
        $this->assertStringNotContainsString("#[Layout", implode($this->generate('multi', 'sfc', layout: null)));
    }

    public function test_multi_sfc_matches_snapshot(): void
    {
        $snapshot = __DIR__.'/snapshots/multi-sfc.blade.php';
        $actual = current($this->generate('multi', 'sfc'));

        if (! file_exists($snapshot)) { // first run (or after deleting it on purpose) records the snapshot
            @mkdir(dirname($snapshot));
            file_put_contents($snapshot, $actual);
        }

        $this->assertSame(str_replace("\r\n", "\n", file_get_contents($snapshot)), $actual);
    }
}
