# Laravel CrudGen

Describe a model once, run one command, and get a Livewire 4 CRUD page and (optionally) a JSON API.

The generated code is plain Laravel and Livewire. It lives in your app, you can edit it freely, and it does not need this package at runtime — you can install the package as a dev dependency.

- [Requirements](#requirements)
- [Install](#install)
- [Quick start](#quick-start)
- [Describing fields](#describing-fields)
- [Validation](#validation)
- [Generating a page](#generating-a-page)
- [Routes, middleware and the sidebar](#routes-middleware-and-the-sidebar)
- [UI presets and your own stubs](#ui-presets-and-your-own-stubs)
- [Generating an API](#generating-an-api)
- [Security notes](#security-notes)
- [Limits](#limits)
- [Developing the package](#developing-the-package)

## Requirements

- PHP 8.2+
- Laravel 12 or 13
- Livewire 4
- Optional: Flux (free is enough). With Flux Pro, rich text uses `flux:editor`.

## Install

```bash
composer require --dev ubaidsaleem/laravel-crudgen
```

The package is not on Packagist yet. Until it is, point Composer at your local copy first and require the `@dev` version:

```bash
composer config repositories.crudgen path ../path/to/LaravelCrudGen
```

```bash
composer require --dev "ubaidsaleem/laravel-crudgen:@dev"
```

Files are uploaded to the `public` disk, so link it once:

```bash
php artisan storage:link
```

## Quick start

1. Add three static methods to your model:

```php
class Article extends Model
{
    protected function casts(): array
    {
        return ['team' => 'array', 'published_on' => 'date', 'active' => 'boolean'];
    }

    public static function fields(): array
    {
        return [
            'title' => 'string',
            'body' => 'text',
            'published_on' => 'date',
            'active' => 'boolean',
            'cover' => 'image',
        ];
    }

    public static function tableColumnShow(): array
    {
        return ['title', 'cover' => 'image', 'published_on'];
    }

    public static function rules($id = null, $component = null): array
    {
        return [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'published_on' => 'nullable|date',
            'active' => 'boolean',
            'cover' => [is_null($id) ? 'required' : 'nullable', 'image', 'max:2048'],
        ];
    }
}
```

2. Generate the page:

```bash
php artisan crud:generate Article --multiRecord
```

3. Open `/articles`. The route and the sidebar link were added for you.

## Describing fields

`fields()` returns `field name => type`. The field name is the database column.

| Type | Input | Notes |
| --- | --- | --- |
| `string` | text input | The default for any unknown type. |
| `text` | textarea | |
| `richtext` | rich text editor | `flux:editor` with Flux Pro, otherwise Toast UI Editor (see below). |
| `date` | native date input | |
| `boolean` | switch / checkbox | Defaults to `false`. |
| `image` | file upload with preview | Stored on the `public` disk in `uploads/`. The old file is deleted when it is replaced or the record is deleted. |
| `foreign:Model,key,label` | select | `foreign:Region,id,name` lists `App\Models\Region` rows, saving `id` and showing `name`. A full class name also works. |
| repeater (below) | a list of items the user can add and remove | Stored in one JSON column. |

### Repeater (JSON) fields

```php
'team' => [
    'type' => 'json',
    'repeatable' => true,
    'fields' => [
        'image' => 'image',
        'title' => 'string',
        'para' => 'richtext',
    ],
],
```

- The column must be cast to `array` on the model.
- Every item gets a `uuid` so files can be matched when an item is edited or removed.
- Sub-fields can be any type above except another repeater.

### Table columns

`tableColumnShow()` lists the columns of the table on a `--multiRecord` page. A plain name is shown as text; give a type for images and foreign keys:

```php
return ['title', 'cover' => 'image', 'region_id' => 'foreign:Region,id,name'];
```

A foreign column shows the related label through a relation named after the model (`Region` → `region()`), so that relation must exist. Without `tableColumnShow()` the first four plain fields are shown.

### Rich text without Flux Pro

```bash
php artisan crudgen:install-assets
```

```bash
npm install @toast-ui/editor
```

```bash
npm run build
```

The generator reminds you when a page needs this.

## Validation

`rules($id, $component)` returns normal Laravel rules.

- `$id` is `null` when creating and the record's key when updating.
- `$component` is the Livewire component, so you can read what the user has typed. For the API it is the request, and `$component?->team` works the same way.

Rules for a repeater are built per item. An existing item keeps its stored path (a string), a new upload is a file:

```php
use Illuminate\Http\UploadedFile;

public static function rules($id = null, $component = null): array
{
    $image = ['image', 'mimes:jpeg,png,jpg,webp', 'max:2048'];

    $rules = [
        'heading' => 'required|string|max:255',
        'team' => 'required|array',
    ];

    foreach ($component?->team ?? [] as $index => $item) {
        $rules["team.$index.title"] = ['required', 'string', 'max:255'];
        $rules["team.$index.para"] = ['required', 'string'];

        $rules["team.$index.image"] = ($item['image'] ?? null) instanceof UploadedFile
            ? ['required', ...$image]                               // a new upload
            : [is_null($id) ? 'required' : 'nullable', 'string'];   // the stored path of an existing item
    }

    return $rules;
}
```

A string in an image slot is never trusted: the generated code only keeps the path that is already saved on the record.

## Generating a page

```bash
php artisan crud:generate Article --multiRecord
```

| Option | Meaning |
| --- | --- |
| `--multiRecord` | A table with add, edit and delete. |
| `--singleRecord` | One form for the first row of the table — settings or a page section. |
| `--sfc` / `--mfc` / `--class` | Single-file, multi-file or class-based component. Default: `livewire.make_command.type` in `config/livewire.php`. |
| `--ui=flux` | UI preset. Default: `flux` when Flux is installed, otherwise `tailwind`. |
| `--layout=layouts::admin` | Layout for the page. Default: `livewire.component_layout`. |
| `--middleware=auth,verified` | Route middleware. |
| `--prefix=admin` | URL prefix. |
| `--nav-group=Content` | Sidebar group heading. |
| `--nav-icon=newspaper` | Sidebar icon. |
| `--no-route` | Do not write a route or a sidebar link. |
| `--no-nav` | Do not add a sidebar link. |
| `--force` | Overwrite files that already exist. |

Where the files go:

| Format | Files | Component |
| --- | --- | --- |
| `--sfc` | `resources/views/pages/crud/⚡article.blade.php` | `pages::crud.article` |
| `--mfc` | `resources/views/pages/crud/⚡article/article.php` and `article.blade.php` | `pages::crud.article` |
| `--class` | `app/Livewire/Crud/ArticleCrud.php` and `resources/views/livewire/crud/article.blade.php` | `App\Livewire\Crud\ArticleCrud` |

The ⚡ prefix follows `livewire.make_command.emoji`.

Without `--force` an existing file is never overwritten. Re-running with `--force` replaces the page, so changes you made to it by hand are lost — move custom code into your own stubs if you expect to regenerate.

## Routes, middleware and the sidebar

Put the defaults on the model. Flags on the command override them.

```php
public static function crud(): array
{
    return [
        'middleware' => ['auth', 'verified'],
        'prefix' => 'admin',
        'nav' => ['group' => 'Content', 'icon' => 'newspaper', 'label' => 'Articles'], // or false for no link
    ];
}
```

- **Route.** Written to `routes/crud.php`, one line per page, replaced when you generate the same model again. `routes/web.php` gets one `require` line the first time.
- **Middleware.** With no `crud()` and no flag, `auth` is used when the app has a login route. A route without middleware is public, and the command says so.
- **Sidebar.** Entries are stored in `config/crud-nav.php`, which you can edit. `'group' => null` shows a link on its own. `resources/views/partials/crud-nav.blade.php` renders them; it is created once and is yours after that. With Flux, `@include('partials.crud-nav')` is added to every layout that has a `flux:sidebar.nav`. Otherwise the command tells you to add the include yourself.

## UI presets and your own stubs

Two presets ship with the package: `flux` and `tailwind`.

```bash
php artisan vendor:publish --tag=crudgen-stubs
```

This copies the stubs to `stubs/crudgen/`. A stub there wins over the package's copy, and you only need to keep the files you changed.

```
stubs/crudgen/
├── component/        multi.stub, single.stub   — the PHP of the component (shared by all presets)
├── flux/
│   ├── multi.stub, single.stub, nav.stub        — page and sidebar templates
│   ├── fields/       one stub per field type
│   └── table/        table header and cell stubs
└── tailwind/         same layout
```

- **A new preset:** copy a preset folder to `stubs/crudgen/bootstrap/`, edit it, and pass `--ui=bootstrap`.
- **Pro variants:** when Flux Pro is installed, `fields/<type>_pro.stub` is used instead of `fields/<type>.stub` if it exists.
- **Placeholders** in field stubs: `{{ field }}` (the `wire:model` path), `{{ label }}`, `{{ errorMsg }}`; `{{ subfieldName }}` inside repeaters; `{{ relatedModel }}`, `{{ relatedKey }}`, `{{ relatedLabel }}` for foreign fields. A placeholder alone on a line is replaced as a block that keeps the line's indentation.

## Generating an API

Add `api()` to the model. Every key is a whitelist; nothing is exposed unless it is listed.

```php
public static function api(): array
{
    return [
        'methods'      => ['index', 'show', 'store', 'update', 'destroy'],
        'middleware'   => ['auth:sanctum', 'throttle:60,1'],
        'show'         => ['id', 'title', 'body', 'cover', 'created_at'],
        'writable'     => ['title', 'body', 'cover'],
        'filterable'   => ['active', 'user_id'],
        'sortable'     => ['id', 'title'],
        'searchable'   => ['title', 'body'],
        'include'      => ['author' => ['id', 'name']],
        'per_page'     => 15,
        'max_per_page' => 100,
    ];
}
```

```bash
php artisan crud:api Article
```

This needs `routes/api.php`; create it with `php artisan install:api` if the app does not have one.

Generated files:

- `app/Http/Controllers/Api/ArticleController.php`
- `app/Http/Resources/ArticleResource.php`
- one line in `routes/crud-api.php`, loaded from `routes/api.php`

### The keys

| Key | Required | Meaning |
| --- | --- | --- |
| `show` | yes | Fields in the response. Image fields are returned as full URLs. |
| `methods` | no | Any of `index`, `show`, `store`, `update`, `destroy`. Default: all. |
| `middleware` | no | Route middleware. Default: `auth:sanctum` and `throttle:60,1` when Sanctum is installed. For a public API write `'middleware' => []` on purpose. |
| `writable` | no | Fields `store` and `update` accept. Empty means no `store` and `update`. Each field needs a rule in `rules()`; a field without a rule is never written. |
| `filterable` | no | Columns allowed in `?filter[...]`. |
| `sortable` | no | Columns allowed in `?sort=`. |
| `searchable` | no | Columns searched by `?search=`. |
| `include` | no | `relation => [fields]`. The relation is only loaded when the client asks for it, and only the listed fields are returned. |
| `per_page`, `max_per_page` | no | Default page size and the largest `?per_page=` a client may ask for. |

### Calling the API

| Request | Result |
| --- | --- |
| `GET /api/articles` | Paginated list (`data`, `links`, `meta`). |
| `GET /api/articles?filter[active]=1` | Rows where `active` is 1. |
| `GET /api/articles?filter[user_id][]=1&filter[user_id][]=2` | Rows where `user_id` is 1 or 2. |
| `GET /api/articles?search=apple` | Rows where a searchable column contains "apple". |
| `GET /api/articles?sort=title` / `?sort=-title` | Ascending / descending. |
| `GET /api/articles?include=author` | Adds the whitelisted fields of `author` to each row. |
| `GET /api/articles?per_page=20&page=2` | Page size and page. |
| `GET /api/articles/5` | One record. `?include=` works here too. |
| `POST /api/articles` | Create. Returns `201` with the record. |
| `PUT /api/articles/5` | Full update: all rules apply. |
| `PATCH /api/articles/5` | Partial update: only the fields that were sent are validated and changed. |
| `DELETE /api/articles/5` | Delete the record and its uploaded files. Returns `204`. |

Parameters that are not whitelisted are ignored, not rejected. Validation errors come back as Laravel's normal `422` JSON.

**Uploading a file on update:** PHP does not read multipart bodies on `PUT` and `PATCH`. Send `POST` with a `_method=PATCH` field.

**Tokens:** to issue Sanctum tokens, add the `Laravel\Sanctum\HasApiTokens` trait to your `User` model.

## Security notes

- **Authorization.** If the model has a policy when you generate, the page and the API call it (`viewAny`, `view`, `create`, `update`, `delete`). If it has none, only the route middleware protects them and the command warns you. Add a policy and re-run with `--force`.
- **Record id.** The id of the record being edited is a `#[Locked]` Livewire property; the browser cannot change it.
- **Uploads.** Stored file paths only come from the saved record, never from the request. A string sent in place of a file is ignored.
- **Errors.** Exceptions are reported to your log; the user sees a generic message.
- **Rich text.** The editor receives saved HTML through Livewire, not printed raw into the page. Before it is stored, the HTML is cleaned with `symfony/html-sanitizer` (`allowSafeElements()`): scripts, event handlers such as `onclick`, and `javascript:` links are removed, in the page and in the API, at the top level and inside repeaters. Length is left to your `rules()` (add a `max:`). The package requires the sanitizer, so it is installed with it; if you later remove the package, keep `symfony/html-sanitizer` in your app. Records saved before this version are not cleaned until they are saved again.
- **API.** Responses, writes, filters, sorting, search and includes are all whitelists baked into the generated controller and resource, so changing `api()` has no effect until you generate again.

## Limits

- A repeater that contains images is read-only over the API.
- Repeaters cannot be nested.
- Image paths inside a repeater are returned by the API as stored paths, not URLs.
- The class-based format assumes Livewire's default `view_path` (`resources/views/livewire`).
- Uploads always go to the `public` disk, folder `uploads/`. To use another disk or folder, change it in the generated code.

## Developing the package

```bash
composer install
```

```bash
vendor/bin/phpunit
```

The tests generate every mode, format and UI preset, check that the PHP parses, and compare against snapshots in `tests/snapshots/`. After an intended change to the output, delete the snapshot files and run the tests once to record new ones.
