## Laravel CrudGen

This app uses `ubaidsaleem/laravel-crudgen` to generate Livewire CRUD pages and JSON APIs from static methods on a model. Prefer it over hand-writing a CRUD page or API controller.

### Workflow

1. Add the static methods below to the model (and `casts()` for JSON / date / boolean columns).
2. Run `php artisan crud:generate {Model} --multiRecord` (table with add/edit/delete) or `--singleRecord` (one settings-style form).
3. For an API, add `api()` and run `php artisan crud:api {Model}`.
4. After changing `fields()`, `crud()` or `api()`, run the command again with `--force`. The generated files are plain code in the app; `--force` overwrites manual edits to them.

Do not add the route or sidebar link by hand: the commands write `routes/crud.php`, `routes/crud-api.php` and `config/crud-nav.php`.

### Model methods

@verbatim
<code-snippet name="Model definition for CrudGen" lang="php">
public static function fields(): array   // column => type
{
    return [
        'title' => 'string',            // string | text | richtext | date | boolean | image
        'region_id' => 'foreign:Region,id,name',   // select: Model, saved key, shown label
        'team' => [                     // repeater stored in one JSON column (cast it to array)
            'type' => 'json',
            'repeatable' => true,
            'fields' => ['image' => 'image', 'title' => 'string'],
        ],
    ];
}

public static function tableColumnShow(): array   // columns of the list table
{
    return ['title', 'cover' => 'image', 'region_id' => 'foreign:Region,id,name'];
}

// $id is null on create. $component is the Livewire component (or the Request for the API).
public static function rules($id = null, $component = null): array
{
    $rules = ['title' => 'required|string|max:255', 'team' => 'array'];

    foreach ($component?->team ?? [] as $index => $item) {
        $rules["team.$index.title"] = ['required', 'string'];
        $rules["team.$index.image"] = ($item['image'] ?? null) instanceof \Illuminate\Http\UploadedFile
            ? ['required', 'image', 'max:2048']
            : [is_null($id) ? 'required' : 'nullable', 'string'];
    }

    return $rules;
}

public static function crud(): array   // optional: route and sidebar
{
    return ['middleware' => ['auth', 'verified'], 'prefix' => 'admin', 'nav' => ['group' => 'Content', 'icon' => 'newspaper']];
}

public static function api(): array    // every key is a whitelist; 'show' is required
{
    return [
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
        'middleware' => ['auth:sanctum', 'throttle:60,1'],
        'show' => ['id', 'title'],
        'writable' => ['title'],
        'filterable' => ['region_id'],
        'sortable' => ['id', 'title'],
        'searchable' => ['title'],
        'include' => ['region' => ['id', 'name']],
    ];
}
</code-snippet>
@endverbatim

### Rules to follow

- Every writable or form field needs a rule in `rules()`; the API never writes a field that has no rule.
- Never expose a field by default: add it to `api()['show']` deliberately. Never list secrets there.
- A foreign column shown in the table needs a relation named after the related model (`Region` → `region()`).
- Create a policy for the model before generating so authorization checks are included; without one only route middleware protects the page and API.
- Useful flags: `--sfc|--mfc|--class`, `--ui=flux|tailwind`, `--layout=`, `--middleware=`, `--prefix=`, `--nav-group=`, `--no-route`, `--no-nav`, `--force`.
- To change how pages look, publish and edit the stubs (`php artisan vendor:publish --tag=crudgen-stubs`, files in `stubs/crudgen/`) instead of editing every generated page.
