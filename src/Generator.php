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
    private string $model;

    private string $slug;

    /**
     * @param  array<string, string|array>  $fields  Model::fields()
     * @param  array<string, string>  $columns  table column => type
     * @param  array{mode: string, format: string, layout: ?string, emoji: bool, authorize: bool, pagesPath: string, classPath: string, classNamespace: string, viewPath: string}  $o
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

    /** The route line the user has to add (written automatically in a later phase). */
    public function route(): string
    {
        $uri = $this->o['mode'] === 'multi' ? Str::plural($this->slug) : $this->slug;
        $target = $this->o['format'] === 'class'
            ? "\\{$this->o['classNamespace']}\\Crud\\{$this->model}Crud::class"
            : "'pages::crud.{$this->slug}'";

        return "Route::livewire('/{$uri}', {$target})->name('crud.{$this->slug}');";
    }

    private function title(): string
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

        // A placeholder alone on its line is a block: every line of it takes the placeholder's indentation.
        $body = preg_replace_callback(
            '/^([ \t]*)\{\{ (\w+) \}\}$/m',
            fn ($m) => isset($blocks[$m[2]]) ? $this->indent($blocks[$m[2]], $m[1]) : $m[0],
            $this->stub("component/{$this->o['mode']}"),
        );

        $relations = collect($this->columns)
            ->filter(fn ($type) => str_starts_with($type, 'foreign:'))
            ->map(fn ($type) => "'".$this->relation($type)."'")
            ->implode(', ');

        $body = strtr($body, [
            '{{ model }}' => $this->model,
            '{{ with }}' => $relations ? "->with([{$relations}])" : '',
            '{{ resettable }}' => "['showForm', 'editingId', '".implode("', '", $this->propertyNames())."']",
        ]);

        // Empty blocks leave blank lines behind; tidy them.
        return preg_replace(
            ['/[ \t]+$/m', '/\n{3,}/', '/\{\n\n/', '/\n\n([ \t]*\})/'],
            ['', "\n\n", "{\n", "\n$1"],
            rtrim($body)."\n",
        );
    }

    private function properties(): string
    {
        return $this->each(fn ($def, $f) => match (true) {
            $this->type($def) === 'image' => "public \${$f};\n\npublic \${$f}Show;",
            $this->isRepeater($def) => "public array \${$f} = [];",
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

    /** Deletes every stored image of a record that is about to be deleted. */
    private function cleanup(): string
    {
        $top = collect($this->fields)
            ->filter(fn ($def) => $this->type($def) === 'image')
            ->keys()
            ->map(fn ($f) => "\$record->{$f}")
            ->implode(', ');

        $nested = collect($this->fields)
            ->filter(fn ($def) => $this->isRepeater($def) && $this->imagePaths($def, '$item'))
            ->map(fn ($def, $f) => "foreach (\$record->{$f} ?? [] as \$item) {\n    Storage::disk('public')->delete(array_filter([{$this->imagePaths($def, '$item')}]));\n}");

        return $nested
            ->prepend($top ? "Storage::disk('public')->delete(array_filter([{$top}]));" : null)
            ->filter()
            ->implode("\n\n");
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
        return strtr($this->stub("{$this->o['mode']}RecordView"), [
            '{{ fields }}' => collect($this->fields)->map(fn ($def, $name) => $this->field($name, $def))->implode("\n\n"),
            '{{ modelName }}' => Str::headline($this->model),
            '{{ title }}' => $this->title(),
            '{{ tableHeaders }}' => collect($this->columns)
                ->map(fn ($type, $name) => strtr($this->stub('table/table_header'), ['{{ field }}' => $name]))
                ->implode("\n"),
            '{{ tableCells }}' => collect($this->columns)->map(fn ($type, $name) => match (true) {
                str_starts_with($type, 'foreign:') => strtr($this->stub('table/table_cell_foreign'), [
                    '{{ relation }}' => $this->relation($type),
                    '{{ column }}' => explode(',', $type)[2] ?? 'name',
                ]),
                $type === 'image' => strtr($this->stub('table/table_cell_image'), ['{{ field }}' => $name]),
                default => strtr($this->stub('table/table_cell'), ['{{ field }}' => $name]),
            })->implode("\n"),
        ]);
    }

    private function field(string $name, array|string $def, ?string $parent = null): string
    {
        $type = $this->type($def);

        $stub = $this->stub('fields/'.match (true) {
            str_starts_with($type, 'foreign') => 'foreign',
            $type === 'image' && $parent => 'image_repeatable',
            in_array($type, ['text', 'boolean', 'image', 'richtext', 'date', 'json']) => $type,
            default => 'string',
        });

        if ($type === 'json' && is_array($def['fields'] ?? null)) {
            return strtr($stub, [
                '{{ field }}' => $name,
                '{{ errorMsg }}' => $name,
                '{{ subfields }}' => collect($def['fields'])->map(fn ($sub, $n) => $this->field($n, $sub, $name))->implode("\n\n"),
            ]);
        }

        // foreign:Model,key,label
        [$related, $key, $label] = explode(',', Str::after($type, ':')) + [null, 'id', 'name'];

        return strtr($stub, [
            '{{ field }}' => $parent ? "{$parent}.{{ \$index }}.{$name}" : $name,
            '{{ errorMsg }}' => $parent ? "{$parent}.\$index.{$name}" : $name,
            '{{ label }}' => Str::headline($name),
            '{{ currentItemVariable }}' => '$item',
            '{{ subfieldName }}' => $name,
            '{{ relatedModel }}' => $related,
            '{{ relatedKey }}' => $key,
            '{{ relatedLabel }}' => $label,
        ]);
    }

    // ------------------------------------------------------------ helpers

    private function type(array|string $def): string
    {
        return is_array($def) ? ($def['type'] ?? 'string') : $def;
    }

    private function isRepeater(array|string $def): bool
    {
        return is_array($def) && $this->type($def) === 'json' && ($def['repeatable'] ?? false) && is_array($def['fields'] ?? null);
    }

    /** "$var['a'] ?? null, $var['b'] ?? null" for the image sub-fields of a repeater. */
    private function imagePaths(array $def, string $var): string
    {
        return collect($def['fields'])
            ->filter(fn ($sub) => $this->type($sub) === 'image')
            ->keys()
            ->map(fn ($n) => "{$var}['{$n}'] ?? null")
            ->implode(', ');
    }

    /** foreign:Region,id,name => region */
    private function relation(string $type): string
    {
        return Str::camel(class_basename(explode(',', Str::after($type, ':'))[0]));
    }

    private function each(callable $callback, string $glue = "\n"): string
    {
        return collect($this->fields)->map($callback)->implode($glue);
    }

    private function indent(string $code, string $indent): string
    {
        return preg_replace('/^(?=.)/m', $indent, $code);
    }

    private function stub(string $name): string
    {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__."/../stubs/{$name}.stub"));
    }
}
