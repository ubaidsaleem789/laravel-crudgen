<?php

namespace UbaidCrudGen\LaravelCrudGen;

use Illuminate\Support\Str;

/**
 * Turns a model's api() definition into a plain Laravel API controller and JSON resource.
 *
 * Everything is a whitelist baked into the generated code: fields that are returned,
 * fields that can be written, columns that can be filtered / sorted / searched,
 * and relations that can be included.
 */
class ApiGenerator
{
    use BuildsCode;

    private string $model;

    private string $uri;

    /**
     * @param  array<string, string|array>  $fields  Model::fields()
     * @param  array{methods: list<string>, show: list<string>, writable: list<string>, filterable: list<string>, sortable: list<string>, searchable: list<string>, include: array<string, array{fields: list<string>, many: bool}>, per_page: int, max_per_page: int}  $api
     * @param  array{authorize: bool, appPath: string, appNamespace: string}  $o
     */
    public function __construct(
        private string $modelClass,
        private array $fields,
        private array $api,
        private array $o,
    ) {
        $this->model = class_basename($modelClass);
        $this->uri = Str::plural(Str::kebab($this->model));
    }

    /** @return array<string, string> path => contents */
    public function files(): array
    {
        return [
            "{$this->o['appPath']}/Http/Controllers/Api/{$this->model}Controller.php" => $this->controller(),
            "{$this->o['appPath']}/Http/Resources/{$this->model}Resource.php" => $this->resource(),
        ];
    }

    public function routeName(): string
    {
        return "api.{$this->uri}";
    }

    /** @param  list<string>  $middleware */
    public function route(array $middleware): string
    {
        return "Route::apiResource('{$this->uri}', \\{$this->o['appNamespace']}\\Http\\Controllers\\Api\\{$this->model}Controller::class)"
            ."->only({$this->list($this->api['methods'])})"
            ."->parameters(['{$this->uri}' => 'record'])"
            .($middleware ? "->middleware({$this->list($middleware)})" : '')
            ."->names('{$this->routeName()}');";
    }

    // --------------------------------------------------------- controller

    private function controller(): string
    {
        $has = fn (string $method) => in_array($method, $this->api['methods'], true);
        $writes = $has('store') || $has('update');
        $includes = (bool) $this->api['include'];

        $class = $this->blocks(<<<'PHP'
            class {{ model }}Controller extends Controller
            {
                {{ index }}

                {{ show }}

                {{ store }}

                {{ update }}

                {{ destroy }}

                {{ includes }}

                {{ validated }}
            }
            PHP, [
            'index' => $has('index') ? $this->index() : '',
            'show' => $has('show') ? <<<'PHP'
                public function show(Request $request, {{ model }} $record)
                {
                    {{ authorizeView }}
                    return new {{ model }}Resource($record{{ loadIncludes }});
                }
                PHP : '',
            'store' => $has('store') ? <<<'PHP'
                public function store(Request $request)
                {
                    {{ authorizeCreate }}
                    $record = new {{ model }};
                    $record->forceFill($this->validated($request, $record))->save();

                    return new {{ model }}Resource($record);
                }
                PHP : '',
            'update' => $has('update') ? <<<'PHP'
                public function update(Request $request, {{ model }} $record)
                {
                    {{ authorizeUpdate }}
                    $record->forceFill($this->validated($request, $record))->save();

                    return new {{ model }}Resource($record);
                }
                PHP : '',
            'destroy' => $has('destroy') ? $this->blocks(<<<'PHP'
                public function destroy({{ model }} $record)
                {
                    {{ authorizeDelete }}
                    {{ cleanup }}

                    $record->delete();

                    return response()->noContent();
                }
                PHP, ['cleanup' => $this->cleanup()]) : '',
            'includes' => $includes ? <<<'PHP'
                /** ?include=a,b. Only these relations can be loaded. */
                private function includes(Request $request): array
                {
                    $include = $request->query('include');

                    return is_string($include) ? array_values(array_intersect(explode(',', $include), {{ includable }})) : [];
                }
                PHP : '',
            'validated' => $writes ? $this->validated() : '',
        ]);

        $can = fn (string $ability, string $on) => $this->o['authorize'] ? "Gate::authorize('{$ability}', {$on});" : '';

        $class = $this->tidy($this->blocks($class, [
            'authorizeViewAny' => $can('viewAny', "{$this->model}::class"),
            'authorizeView' => $can('view', '$record'),
            'authorizeCreate' => $can('create', "{$this->model}::class"),
            'authorizeUpdate' => $can('update', '$record'),
            'authorizeDelete' => $can('delete', '$record'),
        ]));

        $class = strtr($class, [
            '{{ model }}' => $this->model,
            '{{ withIncludes }}' => $includes ? '->with($this->includes($request))' : '',
            '{{ loadIncludes }}' => $includes ? '->load($this->includes($request))' : '',
            '{{ includable }}' => $this->list(array_keys($this->api['include'])),
            '{{ writable }}' => $this->list($this->api['writable']),
        ]);

        $imports = $this->imports($class, [
            'Controller' => "{$this->o['appNamespace']}\\Http\\Controllers\\Controller",
            "{$this->model}Resource" => "{$this->o['appNamespace']}\\Http\\Resources\\{$this->model}Resource",
            'class ' => $this->modelClass,
            'Request ' => 'Illuminate\Http\Request',
            'UploadedFile' => 'Illuminate\Http\UploadedFile',
            'Arr::' => 'Illuminate\Support\Arr',
            'Gate::' => 'Illuminate\Support\Facades\Gate',
            'Storage::' => 'Illuminate\Support\Facades\Storage',
            'HtmlSanitizer(' => 'Symfony\Component\HtmlSanitizer\HtmlSanitizer',
            'HtmlSanitizerConfig' => 'Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig',
        ]);

        return "<?php\n\nnamespace {$this->o['appNamespace']}\\Http\\Controllers\\Api;\n\n{$imports}\n\n{$class}\n";
    }

    private function index(): string
    {
        $wheres = collect($this->api['searchable'])
            ->map(fn ($column, $i) => ($i ? '->orWhereLike' : '->whereLike')."('{$column}', \$like)")
            ->implode('');

        return strtr($this->blocks(<<<'PHP'
            public function index(Request $request)
            {
                {{ authorizeViewAny }}
                $query = {{ model }}::query(){{ withIncludes }};

                {{ filter }}

                {{ search }}

                {{ sort }}

                $perPage = min(max((int) $request->query('per_page', {{ perPage }}), 1), {{ maxPerPage }});

                return {{ model }}Resource::collection($query->paginate($perPage)->withQueryString());
            }
            PHP, [
            'filter' => $this->api['filterable'] ? <<<'PHP'
                // ?filter[column]=value, or ?filter[column][]=a&filter[column][]=b. Only these columns can be filtered.
                $filters = array_intersect_key((array) $request->query('filter'), array_flip({{ filterable }}));

                foreach ($filters as $column => $value) {
                    is_array($value)
                        ? $query->whereIn($column, array_filter($value, 'is_scalar'))
                        : $query->where($column, $value);
                }
                PHP : '',
            'search' => $wheres ? <<<'PHP'
                // ?search=text
                $search = $request->query('search');

                if (is_string($search) && $search !== '') {
                    $like = "%{$search}%";
                    $query->where(fn ($q) => $q{{ searchWheres }});
                }
                PHP : '',
            'sort' => $this->api['sortable'] ? <<<'PHP'
                // ?sort=column (ascending) or ?sort=-column (descending). Only these columns can be sorted.
                $sort = $request->query('sort');

                if (is_string($sort) && in_array(ltrim($sort, '-'), {{ sortable }}, true)) {
                    $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');
                }
                PHP : '',
        ]), [
            '{{ filterable }}' => $this->list($this->api['filterable']),
            '{{ sortable }}' => $this->list($this->api['sortable']),
            '{{ searchWheres }}' => $wheres,
            '{{ perPage }}' => (int) $this->api['per_page'],
            '{{ maxPerPage }}' => (int) $this->api['max_per_page'],
        ]);
    }

    private function validated(): string
    {
        $images = collect($this->api['writable'])
            ->filter(fn ($f) => $this->type($this->fields[$f] ?? 'string') === 'image')
            ->map(fn ($f) => strtr(<<<'PHP'
                // {{ f }}: only a real upload is stored; any other value (such as a path string) is ignored.
                if (($data['{{ f }}'] ?? null) instanceof UploadedFile) {
                    if ($record->{{ f }}) {
                        Storage::disk('public')->delete($record->{{ f }});
                    }

                    $data['{{ f }}'] = $data['{{ f }}']->store('uploads', 'public');
                } else {
                    unset($data['{{ f }}']);
                }
                PHP, ['{{ f }}' => $f]))
            ->implode("\n\n");

        // Rich text is cleaned before it is stored, at the top level and inside repeaters.
        $rich = collect($this->api['writable'])->flatMap(function ($f) {
            $def = $this->fields[$f] ?? 'string';

            if ($this->type($def) === 'richtext') {
                return ["if (array_key_exists('{$f}', \$data)) {\n    \$data['{$f}'] = {$this->sanitized("\$data['{$f}']")};\n}"];
            }

            return $this->isRepeater($def)
                ? collect($def['fields'])->filter(fn ($sub) => $this->type($sub) === 'richtext')->keys()
                    ->map(fn ($n) => "foreach ((array) (\$data['{$f}'] ?? []) as \$i => \$item) {\n    if (is_string(\$item['{$n}'] ?? null)) {\n        \$data['{$f}'][\$i]['{$n}'] = {$this->sanitized("\$item['{$n}']", "''")};\n    }\n}")
                : [];
        })->implode("\n\n");

        $images = trim("{$images}\n\n{$rich}");

        return $this->blocks(<<<'PHP'
            /** Validates with {{ model }}::rules() and returns only the fields the API may write. */
            private function validated(Request $request, {{ model }} $record): array
            {
                // A writable field without a rule is never validated, so it is never written.
                $rules = array_filter(
                    {{ model }}::rules($record->getKey(), $request),
                    fn ($field) => in_array(explode('.', $field)[0], {{ writable }}, true),
                    ARRAY_FILTER_USE_KEY,
                );

                if ($request->isMethod('PATCH')) {
                    // Partial update: a field is only validated when it was sent.
                    $rules = array_map(fn ($rule) => ['sometimes', ...(is_string($rule) ? explode('|', $rule) : Arr::wrap($rule))], $rules);
                }

                $data = $request->validate($rules);

                {{ images }}

                return $data;
            }
            PHP, ['images' => $images]);
    }

    // ----------------------------------------------------------- resource

    private function resource(): string
    {
        $attributes = collect($this->api['show'])->map(fn ($f) => $this->type($this->fields[$f] ?? 'string') === 'image'
            ? "'{$f}' => \$this->{$f} ? Storage::disk('public')->url(\$this->{$f}) : null,"
            : "'{$f}' => \$this->{$f},");

        $relations = collect($this->api['include'])->map(fn ($relation, $name) => $relation['many']
            ? "'{$name}' => \$this->whenLoaded('{$name}', fn () => \$this->{$name}->map->only({$this->list($relation['fields'])})),"
            : "'{$name}' => \$this->whenLoaded('{$name}', fn () => \$this->{$name}?->only({$this->list($relation['fields'])})),");

        $class = strtr($this->blocks(<<<'PHP'
            /** @mixin \{{ modelClass }} */
            class {{ model }}Resource extends JsonResource
            {
                /** Only what is listed here ever leaves the API. */
                public function toArray(Request $request): array
                {
                    return [
                        {{ attributes }}
                    ];
                }
            }
            PHP, ['attributes' => $attributes->merge($relations)->implode("\n")]), [
            '{{ model }}' => $this->model,
            '{{ modelClass }}' => $this->modelClass,
        ]);

        $imports = $this->imports($class, [
            'Request ' => 'Illuminate\Http\Request',
            'JsonResource' => 'Illuminate\Http\Resources\Json\JsonResource',
            'Storage::' => 'Illuminate\Support\Facades\Storage',
        ]);

        return "<?php\n\nnamespace {$this->o['appNamespace']}\\Http\\Resources;\n\n{$imports}\n\n{$class}\n";
    }

    // ------------------------------------------------------------ helpers

    /** @param  array<string, string>  $candidates  text that proves the class is used => class */
    private function imports(string $code, array $candidates): string
    {
        return collect($candidates)
            ->filter(fn ($class, $needle) => str_contains($code, $needle))
            ->sort()
            ->map(fn ($class) => "use {$class};")
            ->implode("\n");
    }

    /** ['a', 'b'] as PHP source. */
    private function list(array $values): string
    {
        return $values ? "['".implode("', '", $values)."']" : '[]';
    }
}
