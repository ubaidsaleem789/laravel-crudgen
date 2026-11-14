<?php

namespace UbaidSaleem\LaravelCrudGen\Tests;

use PHPUnit\Framework\TestCase;
use UbaidSaleem\LaravelCrudGen\ApiGenerator;

class ApiGeneratorTest extends TestCase
{
    private const FIELDS = ['title' => 'string', 'cover' => 'image', 'team' => ['type' => 'json', 'repeatable' => true, 'fields' => ['image' => 'image']]];

    private const FULL = [
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
        'show' => ['id', 'title', 'cover'],
        'writable' => ['title', 'cover'],
        'filterable' => ['active'],
        'sortable' => ['id', 'created_at'],
        'searchable' => ['title', 'body'],
        'include' => ['author' => ['fields' => ['id', 'name'], 'many' => false], 'comments' => ['fields' => ['id'], 'many' => true]],
        'per_page' => 15,
        'max_per_page' => 50,
    ];

    private function generator(array $api = [], bool $authorize = true): ApiGenerator
    {
        return new ApiGenerator('App\Models\Article', self::FIELDS, $api + self::FULL, [
            'authorize' => $authorize, 'appPath' => 'app', 'appNamespace' => 'App',
        ]);
    }

    private function assertValidPhp(array $files): void
    {
        foreach ($files as $path => $code) {
            token_get_all($code, TOKEN_PARSE); // throws ParseError on invalid PHP
            $this->assertDoesNotMatchRegularExpression('/\{\{ [a-zA-Z]+ \}\}/', $code, $path);
        }
    }

    public function test_full_api_parses_and_bakes_in_the_whitelists(): void
    {
        $files = $this->generator()->files();
        $this->assertValidPhp($files);
        $this->assertSame(['app/Http/Controllers/Api/ArticleController.php', 'app/Http/Resources/ArticleResource.php'], array_keys($files));

        [$controller, $resource] = array_values($files);

        $this->assertStringContainsString("array_flip(['active'])", $controller);
        $this->assertStringContainsString("['id', 'created_at'], true)", $controller);
        $this->assertStringContainsString("->whereLike('title', \$like)->orWhereLike('body', \$like)", $controller);
        $this->assertStringContainsString("['author', 'comments']", $controller);
        $this->assertStringContainsString("1), 50);", $controller);
        $this->assertStringContainsString("Gate::authorize('delete', \$record);", $controller);
        $this->assertStringContainsString("unset(\$data['cover']);", $controller);
        $this->assertStringContainsString("\$item['image'] ?? null", $controller); // repeater images deleted with the record

        $this->assertStringContainsString("'cover' => \$this->cover ? Storage::disk('public')->url(\$this->cover) : null,", $resource);
        $this->assertStringContainsString("\$this->author?->only(['id', 'name'])", $resource);
        $this->assertStringContainsString("\$this->comments->map->only(['id'])", $resource);
    }

    public function test_writable_richtext_is_sanitized(): void
    {
        $fields = ['body' => 'richtext', 'notes' => ['type' => 'json', 'repeatable' => true, 'fields' => ['para' => 'richtext']]];
        $files = (new ApiGenerator('App\Models\Article', $fields, ['show' => ['id'], 'writable' => ['body', 'notes']] + self::FULL, [
            'authorize' => false, 'appPath' => 'app', 'appNamespace' => 'App',
        ]))->files();
        $this->assertValidPhp($files);

        $controller = current($files);

        $this->assertStringContainsString('use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;', $controller);
        $this->assertStringContainsString("\$data['body'] = is_string(\$data['body']) ? (new HtmlSanitizer(", $controller);
        $this->assertStringContainsString("\$data['notes'][\$i]['para'] = is_string(\$item['para']) ? (new HtmlSanitizer(", $controller);
        $this->assertStringNotContainsString('HtmlSanitizer', current($this->generator()->files())); // no rich text, no sanitizer
    }

    public function test_read_only_api_has_no_write_code(): void
    {
        $files = $this->generator(['methods' => ['index', 'show'], 'writable' => [], 'filterable' => [], 'sortable' => [], 'searchable' => [], 'include' => []], authorize: false)->files();
        $this->assertValidPhp($files);

        $controller = current($files);

        foreach (['function store', 'function update', 'function destroy', 'function validated', 'function includes', 'Gate::', 'filter', 'search', 'sort', 'Storage', 'UploadedFile'] as $absent) {
            $this->assertStringNotContainsString($absent, $controller);
        }
    }

    public function test_route_line(): void
    {
        $this->assertSame(
            "Route::apiResource('articles', \\App\\Http\\Controllers\\Api\\ArticleController::class)->only(['index', 'show'])->parameters(['articles' => 'record'])->middleware(['auth:sanctum'])->names('api.articles');",
            $this->generator(['methods' => ['index', 'show']])->route(['auth:sanctum']),
        );
    }

    public function test_controller_matches_snapshot(): void
    {
        $snapshot = __DIR__.'/snapshots/api-controller.php';
        $actual = current($this->generator()->files());

        if (! file_exists($snapshot)) { // first run (or after deleting it on purpose) records the snapshot
            file_put_contents($snapshot, $actual);
        }

        $this->assertSame(str_replace("\r\n", "\n", file_get_contents($snapshot)), $actual);
    }
}
