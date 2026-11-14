<?php

namespace UbaidCrudGen\LaravelCrudGen\Tests;

use PHPUnit\Framework\TestCase;
use UbaidCrudGen\LaravelCrudGen\AppFiles;

class AppFilesTest extends TestCase
{
    public function test_routes_are_added_once_and_replaced_by_name(): void
    {
        $file = AppFiles::routes(null, "->name('crud.article');", "Route::livewire('/articles', 'pages::crud.article')->name('crud.article');");
        $file = AppFiles::routes($file, "->name('crud.page');", "Route::livewire('/page', 'pages::crud.page')->name('crud.page');");
        $file = AppFiles::routes($file, "->name('crud.article');", "Route::livewire('/admin/articles', 'pages::crud.article')->middleware(['auth'])->name('crud.article');");

        token_get_all($file, TOKEN_PARSE);
        $this->assertSame(1, substr_count($file, "->name('crud.article');"));
        $this->assertStringContainsString('/admin/articles', $file);
        $this->assertStringContainsString("->name('crud.page');", $file);
        $this->assertStringStartsWith("<?php\n\nuse Illuminate\\Support\\Facades\\Route;", $file);
    }

    public function test_nav_file_round_trips(): void
    {
        $entries = [
            'crud.article' => ['label' => "Editor's picks", 'icon' => 'newspaper', 'group' => 'Content'],
            'crud.setting' => ['label' => 'Settings', 'icon' => null, 'group' => null],
        ];

        $path = tempnam(sys_get_temp_dir(), 'nav');
        file_put_contents($path, AppFiles::nav($entries));

        try {
            $this->assertSame($entries, require $path);
        } finally {
            unlink($path);
        }
    }

    public function test_sidebar_include_is_inserted_once(): void
    {
        $include = "@include('partials.crud-nav')";
        $layout = "<flux:sidebar>\n    <flux:sidebar.nav>\n        <flux:sidebar.group>x</flux:sidebar.group>\n    </flux:sidebar.nav>\n    <flux:sidebar.nav>y</flux:sidebar.nav>\n</flux:sidebar>\n";

        $updated = AppFiles::sidebar($layout, $include);

        $this->assertStringContainsString("</flux:sidebar.group>\n        {$include}\n    </flux:sidebar.nav>\n    <flux:sidebar.nav>y", $updated);
        $this->assertNull(AppFiles::sidebar($updated, $include));
        $this->assertNull(AppFiles::sidebar('<nav>no flux here</nav>', $include));
    }
}
