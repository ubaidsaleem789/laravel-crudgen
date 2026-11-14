<?php

namespace UbaidSaleem\LaravelCrudGen\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UbaidSaleem\LaravelCrudGen\Generator;

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

    private function generate(string $mode, string $format, array $options = []): array
    {
        return (new Generator('App\Models\TeamPage', self::FIELDS, ['title' => 'string', 'cover' => 'image', 'region_id' => 'foreign:Region,id,name'], $options + [
            'mode' => $mode, 'format' => $format, 'ui' => 'flux', 'pro' => false, 'stubRoots' => [dirname(__DIR__).'/stubs'],
            'layout' => 'layouts::admin', 'emoji' => true, 'authorize' => true,
            'pagesPath' => 'views/pages', 'classPath' => 'app/Livewire', 'classNamespace' => 'App\Livewire', 'viewPath' => 'views/livewire',
        ]))->files();
    }

    public static function combinations(): array
    {
        $all = [];
        foreach (['single', 'multi'] as $mode) {
            foreach (['sfc', 'mfc', 'class'] as $format) {
                foreach (['flux', 'tailwind'] as $ui) {
                    foreach ([true, false] as $authorize) {
                        $all["{$mode} {$format} {$ui} ".($authorize ? 'policy' : 'no-policy')] = [$mode, $format, $ui, $authorize];
                    }
                }
            }
        }

        return $all;
    }

    #[DataProvider('combinations')]
    public function test_generated_php_parses_and_has_no_leftover_placeholders(string $mode, string $format, string $ui, bool $authorize): void
    {
        $files = $this->generate($mode, $format, ['ui' => $ui, 'authorize' => $authorize]);

        $this->assertCount($format === 'sfc' ? 1 : 2, $files);
        $this->assertSame($authorize, str_contains(implode($files), '$this->authorize('));
        $this->assertSame($ui === 'flux', str_contains(implode($files), '<flux:'));

        foreach ($files as $path => $contents) {
            if (str_starts_with($contents, '<?php')) {
                token_get_all($contents, TOKEN_PARSE); // throws ParseError on invalid PHP
            }

            $this->assertDoesNotMatchRegularExpression('/\{\{ [a-zA-Z]+ \}\}/', $contents, $path);
        }
    }

    public function test_paths_per_format(): void
    {
        $this->assertSame(['views/pages/crud/⚡team-page.blade.php'], array_keys($this->generate('multi', 'sfc')));
        $this->assertSame(['views/pages/crud/⚡team-page/team-page.php', 'views/pages/crud/⚡team-page/team-page.blade.php'], array_keys($this->generate('multi', 'mfc')));
        $this->assertSame(['app/Livewire/Crud/TeamPageCrud.php', 'views/livewire/crud/team-page.blade.php'], array_keys($this->generate('multi', 'class')));
    }

    #[DataProvider('uis')]
    public function test_security_properties_of_the_output(string $ui): void
    {
        $code = implode($this->generate('multi', 'sfc', ['ui' => $ui]));

        $this->assertStringContainsString("#[Locked]\n    public \$editingId", $code);
        $this->assertStringNotContainsString('{!!', $code);
        $this->assertStringNotContainsString('getMessage()', $code);
        $this->assertStringNotContainsString('#[Layout', implode($this->generate('multi', 'sfc', ['ui' => $ui, 'layout' => null])));
    }

    public function test_richtext_is_sanitized_before_it_is_stored(): void
    {
        $code = implode($this->generate('multi', 'sfc'));

        $this->assertStringContainsString('use Symfony\Component\HtmlSanitizer\HtmlSanitizer;', $code);
        $this->assertStringContainsString("\$new['para'] = is_string(\$item['para'] ?? null) ? (new HtmlSanitizer(", $code); // inside a repeater too
        $this->assertSame(1, preg_match('/\$record->body = (.+);/', $code, $m));

        // Run the generated expression itself.
        $clean = fn ($body) => eval('return '.strtr($m[1], [
            '$this->body' => '$body',
            'new HtmlSanitizer(' => 'new \Symfony\Component\HtmlSanitizer\HtmlSanitizer(',
            'new HtmlSanitizerConfig' => 'new \Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig',
        ]).';');

        $html = $clean('<p onclick="x()">Hi <strong>there</strong></p><script>alert(1)</script><a href="javascript:alert(1)">l</a><img src="https://a.test/i.png">');

        $this->assertStringContainsString('<p>Hi <strong>there</strong></p>', $html);
        $this->assertStringContainsString('<img src="https://a.test/i.png"', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertNull($clean(null));
        $this->assertSame(30_000, strlen(strip_tags($clean('<p>'.str_repeat('a', 30_000).'</p>')))); // not cut at the sanitizer's 20 000 default
    }

    public function test_flux_pro_swaps_in_the_pro_stub(): void
    {
        $this->assertStringContainsString('window.Editor', implode($this->generate('single', 'sfc')));

        $pro = implode($this->generate('single', 'sfc', ['pro' => true]));
        $this->assertStringContainsString('<flux:editor wire:model="body"', $pro);
        $this->assertStringContainsString('<flux:editor wire:model="team.{{ $index }}.para"', $pro);
        $this->assertStringNotContainsString('window.Editor', $pro);
    }

    public function test_app_stubs_override_package_stubs(): void
    {
        $root = sys_get_temp_dir().'/crudgen-'.uniqid();
        mkdir("{$root}/flux/fields", recursive: true);
        file_put_contents("{$root}/flux/fields/string.stub", '<my-input name="{{ field }}" />');

        try {
            $code = implode($this->generate('single', 'sfc', ['stubRoots' => [$root, dirname(__DIR__).'/stubs']]));
        } finally {
            unlink("{$root}/flux/fields/string.stub");
            rmdir("{$root}/flux/fields");
            rmdir("{$root}/flux");
            rmdir($root);
        }

        $this->assertStringContainsString('<my-input name="title" />', $code);
        $this->assertStringContainsString('<flux:textarea', $code); // everything else still comes from the package
    }

    #[DataProvider('uis')]
    public function test_multi_sfc_matches_snapshot(string $ui): void
    {
        $snapshot = __DIR__."/snapshots/multi-sfc-{$ui}.blade.php";
        $actual = current($this->generate('multi', 'sfc', ['ui' => $ui]));

        if (! file_exists($snapshot)) { // first run (or after deleting it on purpose) records the snapshot
            @mkdir(dirname($snapshot));
            file_put_contents($snapshot, $actual);
        }

        $this->assertSame(str_replace("\r\n", "\n", file_get_contents($snapshot)), $actual);
    }

    public static function uis(): array
    {
        return ['flux' => ['flux'], 'tailwind' => ['tailwind']];
    }
}
