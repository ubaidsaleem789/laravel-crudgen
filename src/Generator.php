<?php

namespace UbaidCrudGen\LaravelCrudGen;

use Illuminate\Support\Str;

/**
 * Turns a model's fields() definition into the files of one Livewire CRUD page.
 *
 * The class body and the view are built once; the format (sfc / mfc / class)
 * only decides how the two are wrapped and where they are written.
 */
class Generator
{
    use BuildsCode;

    private string $model;

    private string $slug;

    /**
     * @param  array<string, string|array>  $fields  Model::fields()
     * @param  array<string, string>  $columns  table column => type
     * @param  array{mode: string, format: string, ui: string, pro: bool, stubRoots: list<string>, layout: ?string, emoji: bool, authorize: bool, pagesPath: string, classPath: string, classNamespace: string, viewPath: string}  $o
     */
    public function __construct(
        private string $modelClass,
        private array $fields,
        private array $columns,
        private array $o,
    ) {
        $this->model = class_basename($modelClass);
        $this->slug = Str::kebab($this->model);
    }

    /** @return array<string, string> path => contents */
    public function files(): array
    {
        $dir = "{$this->o['pagesPath']}/crud/".($this->o['emoji'] ? '⚡' : '').$this->slug;

        return match ($this->o['format']) {
            'sfc' => ["{$dir}.blade.php" => $this->php()."?>\n\n".$this->view()],
            'mfc' => [
                "{$dir}/{$this->slug}.php" => $this->php(),
                "{$dir}/{$this->slug}.blade.php" => $this->view(),
            ],
            'class' => [
                "{$this->o['classPath']}/Crud/{$this->model}Crud.php" => $this->php(named: true),
                "{$this->o['viewPath']}/crud/{$this->slug}.blade.php" => $this->view(),
            ],
        };
    }

    public function routeName(): string
    {
        return "crud.{$this->slug}";
    }

    /** @param  list<string>  $middleware */
    public function route(string $prefix = '', array $middleware = []): string
    {
        $uri = trim($prefix.'/'.($this->o['mode'] === 'multi' ? Str::plural($this->slug) : $this->slug), '/');
        $target = $this->o['format'] === 'class'
            ? "\\{$this->o['classNamespace']}\\Crud\\{$this->model}Crud::class"
            : "'pages::crud.{$this->slug}'";
        $middleware = $middleware ? "->middleware(['".implode("', '", $middleware)."'])" : '';

        return "Route::livewire('/{$uri}', {$target}){$middleware}->name('{$this->routeName()}');";
    }

    public function navStub(): string
    {
        return $this->stub('nav')."\n";
    }

    public function title(): string
    {
        return Str::headline($this->o['mode'] === 'multi' ? Str::plural($this->model) : $this->model);
    }

    // ---------------------------------------------------------------- PHP

    private function php(bool $named = false): string
    {
        $body = $this->body();

        if ($named) {
            // ponytail: assumes livewire.view_path is resources/views/livewire; derive the view name if that is ever customised.
            $body .= "\n    public function render()\n    {\n        return view('livewire.crud.{$this->slug}');\n    }\n";
        }

        $attributes = ["#[Title('{$this->title()}')]"];
        if ($this->o['layout']) {
            $attributes[] = "#[Layout('{$this->o['layout']}')]";
        }

        $imports = collect([
            'Component' => 'Livewire\Component',
            $this->model.'::' => $this->modelClass,
            '#[Title' => 'Livewire\Attributes\Title',
            '#[Layout' => 'Livewire\Attributes\Layout',
            '#[Locked]' => 'Livewire\Attributes\Locked',
            '#[Computed]' => 'Livewire\Attributes\Computed',
            'WithFileUploads' => 'Livewire\WithFileUploads',
            'WithPagination' => 'Livewire\WithPagination',
            'Storage::' => 'Illuminate\Support\Facades\Storage',
            'Str::' => 'Illuminate\Support\Str',
            'UploadedFile' => 'Illuminate\Http\UploadedFile',
        ])
            ->filter(fn ($class, $needle) => str_contains('Component'.implode($attributes).$body, $needle))
            ->sort()
            ->map(fn ($class) => "use {$class};")
            ->implode("\n");

        $open = $named
            ? implode("\n", $attributes)."\nclass {$this->model}Crud extends Component"
            : 'new '.implode(' ', $attributes).' class extends Component';

        return "<?php\n\n"
            .($named ? "namespace {$this->o['classNamespace']}\\Crud;\n\n" : '')
            ."{$imports}\n\n{$open}\n{\n{$body}}".($named ? '' : ';')."\n";
    }

    private function body(): string
    {
        $can = fn (string $line) => $this->o['authorize'] ? $line : '';

        $blocks = [
            'properties' => $this->properties(),
            'mount' => $can("public function mount(): void\n{\n    \$this->authorize('viewAny', {$this->model}::class);\n}"),
            'fill' => $this->fill(),
            'persist' => $this->persist(),
            'cleanup' => $this->cleanup(),
            'jsonMethods' => $this->jsonMethods(),
            'authorizeCreate' => $can("\$this->authorize('create', {$this->model}::class);"),
            'authorizeUpdate' => $can("\$this->authorize('update', \$record);"),
            'authorizeSave' => $can("\$this->authorize(\$record->exists ? 'update' : 'create', \$record);"),
            'authorizeDelete' => $can("\$this->authorize('delete', \$record);"),
        ];

        $body = $this->blocks($this->stub("component/{$this->o['mode']}"), $blocks);

        $relations = collect($this->columns)
            ->filter(fn ($type) => str_starts_with($type, 'foreign:'))
            ->map(fn ($type) => "'".$this->relation($type)."'")
            ->implode(', ');

        $body = strtr($body, [
            '{{ model }}' => $this->model,
            '{{ with }}' => $relations ? "->with([{$relations}])" : '',
            '{{ resettable }}' => "['showForm', 'editingId', '".implode("', '", $this->propertyNames())."']",
        ]);

        return $this->tidy($body);
    }

    private function properties(): string
    {
        return $this->each(fn ($def, $f) => match (true) {
            $this->type($def) === 'image' => "public \${$f};\n\npublic \${$f}Show;",
            $this->isRepeater($def) => "public array \${$f} = [];",
            $this->type($def) === 'boolean' => "public \${$f} = false;",
            default => "public \${$f};",
        }, "\n\n");
    }

    /** @return list<string> */
    private function propertyNames(): array
    {
        return collect($this->fields)
            ->flatMap(fn ($def, $f) => $this->type($def) === 'image' ? [$f, "{$f}Show"] : [$f])
            ->all();
    }

    private function fill(): string
    {
        return $this->each(fn ($def, $f) => match (true) {
            $this->type($def) === 'image' => "\$this->{$f} = null;\n\$this->{$f}Show = \$record->{$f};",
            $this->isRepeater($def) => "\$this->{$f} = \$record->{$f} ?? [];",
            $this->type($def) === 'boolean' => "\$this->{$f} = (bool) \$record->{$f};",
            $this->type($def) === 'date' => "\$this->{$f} = \$record->{$f} instanceof \\DateTimeInterface ? \$record->{$f}->format('Y-m-d') : \$record->{$f};",
            default => "\$this->{$f} = \$record->{$f};",
        });
    }

    private function persist(): string
    {
        return $this->each(function ($def, $f) {
            if ($this->type($def) === 'image') {
                return <<<PHP
                if (\$this->{$f} instanceof UploadedFile) {
                    if (\$record->{$f}) {
                        Storage::disk('public')->delete(\$record->{$f});
                    }

                    \$record->{$f} = \$this->{$f}->store('uploads', 'public');
                }
                PHP;
            }

            if (! $this->isRepeater($def)) {
                return "\$record->{$f} = \$this->{$f};";
            }

            // Stored image paths only ever come from the saved record ($old), never from the request.
            $assign = $this->indent(collect($def['fields'])->map(fn ($sub, $n) => $this->type($sub) === 'image'
                ? <<<PHP
                if ((\$item['{$n}'] ?? null) instanceof UploadedFile) {
                    if (! empty(\$old['{$n}'])) {
                        Storage::disk('public')->delete(\$old['{$n}']);
                    }

                    \$new['{$n}'] = \$item['{$n}']->store('uploads', 'public');
                } else {
                    \$new['{$n}'] = \$old['{$n}'] ?? null;
                }
                PHP
                : "\$new['{$n}'] = \$item['{$n}'] ?? '';")->implode("\n"), '    ');

            $removed = ($paths = $this->imagePaths($def, '$old'))
                ? "\nforeach (\$existing as \$old) {\n    Storage::disk('public')->delete(array_filter([{$paths}]));\n}\n"
                : '';

            return <<<PHP

            // {$f}
            \$existing = collect(\$record->{$f} ?? [])->keyBy('uuid');
            \$items = [];

            foreach (\$this->{$f} as \$item) {
                \$old = \$existing->pull(\$item['uuid'] ?? '', []);
                \$new = ['uuid' => \$old['uuid'] ?? (string) Str::uuid()];
            {$assign}

                \$items[] = \$new;
            }
            {$removed}
            \$record->{$f} = \$items;
            PHP;
        });
    }

    private function jsonMethods(): string
    {
        return collect($this->fields)->filter(fn ($def) => $this->isRepeater($def))->map(function ($def, $f) {
            $defaults = collect($def['fields'])
                ->map(fn ($sub, $n) => "'{$n}' => ".($this->type($sub) === 'image' ? 'null' : "''"))
                ->prepend("'uuid' => (string) Str::uuid()")
                ->implode(', ');

            return <<<PHP
            public function add{$f}(): void
            {
                \$this->{$f}[] = [{$defaults}];
            }

            public function remove{$f}(\$uuid): void
            {
                \$this->{$f} = array_values(array_filter(\$this->{$f}, fn (\$item) => (\$item['uuid'] ?? null) !== \$uuid));
            }
            PHP;
        })->implode("\n\n");
    }

    // --------------------------------------------------------------- view

    private function view(): string
    {
        $table = fn (string $stub, string $name, array $extra = []) => strtr($this->stub("table/{$stub}"), [
            '{{ field }}' => $name,
            '{{ label }}' => Str::headline($name),
        ] + $extra);

        $blocks = [
            'fields' => collect($this->fields)->map(fn ($def, $name) => $this->field($name, $def))->implode("\n\n"),
            'tableHeaders' => collect($this->columns)->map(fn ($type, $name) => $table('table_header', $name))->implode("\n"),
            'tableCells' => collect($this->columns)->map(fn ($type, $name) => match (true) {
                str_starts_with($type, 'foreign:') => $table('table_cell_foreign', $name, [
                    '{{ relation }}' => $this->relation($type),
                    '{{ column }}' => explode(',', $type)[2] ?? 'name',
                ]),
                $type === 'image' => $table('table_cell_image', $name),
                default => $table('table_cell', $name),
            })->implode("\n"),
        ];

        return strtr($this->blocks($this->stub($this->o['mode']), $blocks), [
            '{{ modelName }}' => Str::headline($this->model),
            '{{ title }}' => $this->title(),
        ])."\n";
    }

    private function field(string $name, array|string $def, ?string $parent = null): string
    {
        $type = $this->type($def);

        $stub = 'fields/'.match (true) {
            str_starts_with($type, 'foreign') => 'foreign',
            $type === 'image' && $parent => 'image_repeatable',
            in_array($type, ['text', 'boolean', 'image', 'richtext', 'date', 'json']) => $type,
            default => 'string',
        };

        // e.g. flux/fields/richtext_pro.stub replaces richtext.stub when Flux Pro is installed.
        $stub = $this->stub($this->o['pro'] && $this->stubPath("{$stub}_pro") ? "{$stub}_pro" : $stub);

        if ($type === 'json' && is_array($def['fields'] ?? null)) {
            $subfields = collect($def['fields'])->map(fn ($sub, $n) => $this->field($n, $sub, $name))->implode("\n\n");

            return strtr($this->blocks($stub, ['subfields' => $subfields]), [
                '{{ field }}' => $name,
                '{{ errorMsg }}' => $name,
                '{{ label }}' => Str::headline($name),
            ]);
        }

        // foreign:Model,key,label
        [$related, $key, $label] = explode(',', Str::after($type, ':')) + [null, 'id', 'name'];

        return strtr($stub, [
            '{{ field }}' => $parent ? "{$parent}.{{ \$index }}.{$name}" : $name,
            '{{ errorMsg }}' => $parent ? "{$parent}.\$index.{$name}" : $name,
            '{{ label }}' => Str::headline(str_starts_with($type, 'foreign') ? Str::beforeLast($name, '_id') : $name),
            '{{ currentItemVariable }}' => '$item',
            '{{ subfieldName }}' => $name,
            '{{ relatedModel }}' => str_contains((string) $related, '\\') ? ltrim($related, '\\') : "App\\Models\\{$related}",
            '{{ relatedKey }}' => $key,
            '{{ relatedLabel }}' => $label,
        ]);
    }

    // ------------------------------------------------------------ helpers

    /** foreign:Region,id,name => region */
    private function relation(string $type): string
    {
        return Str::camel(class_basename(explode(',', Str::after($type, ':'))[0]));
    }

    private function each(callable $callback, string $glue = "\n"): string
    {
        return collect($this->fields)->map($callback)->implode($glue);
    }

    /** First match wins: the app's stubs before the package's, the UI preset before shared stubs. */
    private function stubPath(string $name): ?string
    {
        foreach ($this->o['stubRoots'] as $root) {
            foreach (["{$root}/{$this->o['ui']}/{$name}.stub", "{$root}/{$name}.stub"] as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function stub(string $name): string
    {
        $path = $this->stubPath($name) ?? throw new \RuntimeException("Stub [{$name}] not found for the [{$this->o['ui']}] UI.");

        return str_replace("\r\n", "\n", rtrim(file_get_contents($path)));
    }
}
