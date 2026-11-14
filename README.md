# Laravel CrudGen

Building an admin page for a table is the same work every time: a list, a form, validation, file uploads, a route, a sidebar link. This package writes that code for you.

You describe your fields in the model, run one command, and you get a working Livewire page where you can add, edit and delete records. With one more command you also get a JSON API for the same model.

The code it writes is normal Laravel and Livewire code. It is saved in your own app, so you can open it, read it and change it like any file you wrote yourself.

## Contents

- [What you need](#what-you-need)
- [Setup, step by step](#setup-step-by-step)
- [Your first page](#your-first-page)
- [Field types](#field-types)
- [Lists of items inside one record (repeater)](#lists-of-items-inside-one-record-repeater)
- [Choosing the table columns](#choosing-the-table-columns)
- [Validation rules](#validation-rules)
- [All options of crud:generate](#all-options-of-crudgenerate)
- [Route, middleware and sidebar link](#route-middleware-and-sidebar-link)
- [Changing how the pages look](#changing-how-the-pages-look)
- [Making an API](#making-an-api)
- [How your data is kept safe](#how-your-data-is-kept-safe)
- [What it cannot do yet](#what-it-cannot-do-yet)
- [Common problems](#common-problems)
- [Working on the package itself](#working-on-the-package-itself)

## What you need

- PHP 8.2 or newer
- Laravel 12 or 13
- Livewire 4

Flux is optional. If your app has Flux (the Laravel Livewire starter kit comes with it), the pages are built with Flux components. If it does not, they are built with plain Tailwind CSS. The free version of Flux is enough.

## Setup, step by step

### 1. Have a Laravel app with Livewire

If you are starting fresh, the easiest way is the Livewire starter kit, because it already has login, a layout and a sidebar:

```bash
laravel new my-app
```

Pick **Livewire** when the installer asks which starter kit you want.

If you already have an app with Livewire 4, skip this step.

### 2. Install the package

Run this inside your Laravel app:

```bash
composer require ubaidsaleem/laravel-crudgen
```

### 3. Link the storage folder

Uploaded images are saved in `storage/app/public`. This command makes them reachable from the browser. You only run it once per app:

```bash
php artisan storage:link
```

### 4. Check that it is installed

```bash
php artisan list crud
```

You should see `crud:generate` and `crud:api` in the list. That is the whole setup.

## Your first page

Let's build a page to manage articles. It takes four small steps.

### Step 1: Make the model and the table

```bash
php artisan make:model Article -m
```

Open the new migration in `database/migrations` and add your columns:

```php
Schema::create('articles', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body');
    $table->date('published_on')->nullable();
    $table->boolean('active')->default(false);
    $table->string('cover')->nullable();   // the image path is saved here
    $table->timestamps();
});
```

```bash
php artisan migrate
```

### Step 2: Describe the fields in the model

Open `app/Models/Article.php` and add three methods. They tell the package what the form looks like, which columns the table shows, and how the input is validated.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected function casts(): array
    {
        return ['published_on' => 'date', 'active' => 'boolean'];
    }

    // The form: column name => field type
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

    // The columns of the list table
    public static function tableColumnShow(): array
    {
        return ['title', 'cover' => 'image', 'published_on'];
    }

    // Normal Laravel validation rules
    public static function rules($id = null, $component = null): array
    {
        return [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'published_on' => 'nullable|date',
            'active' => 'boolean',
            // $id is null while creating, so the image is only required for a new article
            'cover' => [is_null($id) ? 'required' : 'nullable', 'image', 'max:2048'],
        ];
    }
}
```

`fields()` and `rules()` are required. `tableColumnShow()` is optional.

### Step 3: Run the command

```bash
php artisan crud:generate Article --multiRecord
```

The command prints what it created: the page file, the route and the sidebar link.

### Step 4: Open the page

Start your app (`composer run dev` or `php artisan serve`), log in, and go to `/articles`. You will also find an "Articles" link in the sidebar.

You now have a table of articles with buttons to add, edit and delete, a form with validation, and image upload with a preview.

### Changed your mind about a field?

Change `fields()` or `rules()` in the model and run the command again with `--force`:

```bash
php artisan crud:generate Article --multiRecord --force
```

`--force` replaces the page file. If you edited that file by hand, those edits are lost, so keep that in mind. Without `--force` the command never touches a file that already exists.

## Field types

`fields()` returns a list of `column name => type`.

| Type | What the user sees | Good to know |
| --- | --- | --- |
| `string` | A one-line text box | Used for any type the package does not know. |
| `text` | A bigger text box (textarea) | |
| `richtext` | An editor with bold, lists, links and so on | Needs a small extra setup, see below. |
| `date` | A date picker | The browser's own date picker. |
| `boolean` | An on/off switch | Off by default. |
| `image` | A file upload with a preview | Saved in `storage/app/public/uploads`. The old file is deleted when you replace it or delete the record. |
| `foreign:Model,key,label` | A dropdown filled from another table | See below. |

### Dropdown from another table

```php
'region_id' => 'foreign:Region,id,name',
```

This reads as: show a dropdown of `App\Models\Region` rows, display each one by its `name`, and save its `id` in the `region_id` column. If your model is somewhere else, write the full class name instead of `Region`.

### Rich text setup

If you have Flux Pro, there is nothing to do: the page uses the Flux editor.

Without Flux Pro, the page uses Toast UI Editor, which has to be added to your JavaScript once:

```bash
php artisan crudgen:install-assets
```

```bash
npm install @toast-ui/editor
```

```bash
npm run build
```

When you generate a page that needs this, the command reminds you.

## Lists of items inside one record (repeater)

Sometimes one record holds a list, for example a page with several team members, each with a photo and a name. You can keep that list in a single JSON column, and the form lets the user add and remove items.

In the migration:

```php
$table->json('team')->nullable();
```

In the model, cast the column to an array:

```php
protected function casts(): array
{
    return ['team' => 'array'];
}
```

In `fields()`:

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

The fields inside can be any type from the table above. A repeater cannot contain another repeater.

Each item is saved with a hidden `uuid`. The package uses it to know which photo belongs to which item when the user edits or removes one.

## Choosing the table columns

`tableColumnShow()` decides which columns appear in the list. Write a plain name for text. For an image or a dropdown column, add the type so it is shown as a picture or as the related name:

```php
public static function tableColumnShow(): array
{
    return ['title', 'cover' => 'image', 'region_id' => 'foreign:Region,id,name'];
}
```

For a `foreign` column, the model needs a relation named after the related model, starting with a small letter. For `Region` that is `region()`, and for `BlogPost` it would be `blogPost()`:

```php
public function region()
{
    return $this->belongsTo(Region::class);
}
```

If you leave `tableColumnShow()` out, the first four simple fields are shown.

## Validation rules

`rules()` returns ordinary Laravel validation rules, so everything you know about them works here. It receives two things:

- `$id` is `null` when a record is being created, and the record's id when it is being updated. Use it for rules that differ between the two, such as "image required only when creating".
- `$component` is the Livewire page, so you can look at what the user has typed so far. When the API calls `rules()`, it passes the request instead, and reading values works the same way.

### Rules for a repeater

A repeater has a different number of items every time, so you build its rules in a loop. One thing needs care: an image inside an item is either a **new upload** (a file) or an **already saved image** (the path, as text). They need different rules.

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
            : [is_null($id) ? 'required' : 'nullable', 'string'];   // an image that is already saved
    }

    return $rules;
}
```

You do not have to worry about someone typing a fake path into an image field. The generated code only keeps a path that is already saved on the record, and ignores any text sent in its place.

## All options of crud:generate

```bash
php artisan crud:generate Article --multiRecord
```

You must pick one of the first two options. All the others are optional.

| Option | What it does |
| --- | --- |
| `--multiRecord` | A table of records with add, edit and delete. Use it for things like articles, products or users. |
| `--singleRecord` | Just one form that edits the first row of the table. Use it for things that exist only once, like site settings or the "About us" section. |
| `--sfc` | Put the whole page in one file. |
| `--mfc` | Split the page into a PHP file and a Blade file, in one folder. |
| `--class` | The classic way: a class in `app/Livewire` and a view. |
| `--ui=flux` or `--ui=tailwind` | Which look to use. If you leave it out, Flux is used when it is installed, otherwise Tailwind. |
| `--layout=layouts::admin` | Which layout wraps the page. If you leave it out, your Livewire default layout is used. |
| `--middleware=auth,verified` | Middleware for the route. |
| `--prefix=admin` | Puts the page under a URL prefix, so `/articles` becomes `/admin/articles`. |
| `--nav-group=Content` | Puts the sidebar link under a group heading. |
| `--nav-icon=newspaper` | The icon of the sidebar link. |
| `--no-nav` | Do not add a sidebar link. |
| `--no-route` | Do not add a route or a sidebar link. You wire the page up yourself. |
| `--force` | Replace files that already exist. |

If you do not pick `--sfc`, `--mfc` or `--class`, the package follows the setting in `config/livewire.php` (`make_command.type`), the same one `php artisan make:livewire` uses.

### Where the files are saved

| Format | Files |
| --- | --- |
| `--sfc` | `resources/views/pages/crud/⚡article.blade.php` |
| `--mfc` | `resources/views/pages/crud/⚡article/article.php` and `article.blade.php` in the same folder |
| `--class` | `app/Livewire/Crud/ArticleCrud.php` and `resources/views/livewire/crud/article.blade.php` |

The ⚡ in the file name is Livewire 4's own convention. If you turned it off in `config/livewire.php`, the package leaves it out too.

## Route, middleware and sidebar link

You can pass these as options every time, but it is easier to write them once in the model. Add a `crud()` method:

```php
public static function crud(): array
{
    return [
        'middleware' => ['auth', 'verified'],
        'prefix' => 'admin',
        'nav' => ['group' => 'Content', 'icon' => 'newspaper', 'label' => 'Articles'],
    ];
}
```

Every key is optional. Write `'nav' => false` if you do not want a sidebar link. An option on the command line wins over what is written here.

**The route.** Routes are written to their own file, `routes/crud.php`, one line per page. The first time, one line is added to the end of `routes/web.php` to load that file. Generating the same model again replaces its line, it does not add a second one.

**Middleware.** If you give no middleware at all, the package uses `auth` when your app has a login page. If the route ends up with no middleware, anyone can open the page, and the command warns you about it.

**The sidebar link.** The links are stored in `config/crud-nav.php`. You can edit that file by hand, for example to reorder links or rename a group. A link with `'group' => null` is shown on its own, outside any group.

The links are drawn by `resources/views/partials/crud-nav.blade.php`. The package creates this file once and never overwrites it, so you can restyle it. With Flux, the package also adds `@include('partials.crud-nav')` to your sidebar layout for you. With the Tailwind preset it cannot know where your menu is, so it tells you to add that line yourself.

## Changing how the pages look

Every page is built from small template files called stubs. If you want all your pages to look different, change the stubs once instead of editing each generated page.

Copy the stubs into your app:

```bash
php artisan vendor:publish --tag=crudgen-stubs
```

They land in `stubs/crudgen/`:

```
stubs/crudgen/
├── component/     multi.stub, single.stub      the PHP part of the page
├── flux/
│   ├── multi.stub, single.stub, nav.stub       the page and the sidebar link
│   ├── fields/    one file per field type
│   └── table/     table heading and table cells
└── tailwind/      the same files for the Tailwind look
```

A stub in your app is used instead of the one in the package. You can delete the ones you did not change, and the package will fall back to its own.

**Making your own look.** Copy the `tailwind` folder to, say, `stubs/crudgen/bootstrap/`, change the HTML and classes, and generate with `--ui=bootstrap`.

**Flux Pro.** If a file named `fields/<type>_pro.stub` exists and Flux Pro is installed, it is used instead of `fields/<type>.stub`. This is how the rich text field switches to the Flux editor.

**Placeholders.** Inside a field stub you will see words in double curly braces. The package fills them in:

| Placeholder | Becomes |
| --- | --- |
| `{{ field }}` | The name used in `wire:model`, for example `title` |
| `{{ label }}` | The readable label, for example `Published On` |
| `{{ errorMsg }}` | The name used to show the validation error |
| `{{ subfieldName }}` | Inside a repeater: the name of the inner field |
| `{{ relatedModel }}`, `{{ relatedKey }}`, `{{ relatedLabel }}` | For dropdowns: the other model, the saved column and the shown column |

## Making an API

The same model can also get a JSON API, for a mobile app or a separate frontend.

### Step 1: Turn on API routes

If your app has no `routes/api.php` yet, run:

```bash
php artisan install:api
```

This also installs Laravel Sanctum, which handles API login tokens.

### Step 2: Say what the API is allowed to do

Add an `api()` method to the model. The rule is simple: **nothing is allowed unless you list it here.** A column you do not list is never shown and can never be changed through the API.

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

Only `show` is required. Here is what each key means:

| Key | Meaning |
| --- | --- |
| `show` | The columns that appear in the answer. Image columns come back as full URLs. Never list passwords or other secrets here. |
| `methods` | Which actions exist: `index` (list), `show` (one record), `store` (create), `update`, `destroy` (delete). All five if you leave it out. |
| `middleware` | Who may call the API. If you leave it out and Sanctum is installed, callers must be logged in with a token and are limited to 60 calls a minute. For an API that is open to everyone, write `'middleware' => []` yourself, so it is a clear decision and not an accident. |
| `writable` | The columns that can be set when creating or updating. Each one must also have a rule in `rules()`. A column without a rule is never saved. If the list is empty, the API is read-only. |
| `filterable` | The columns that can be used to filter the list. |
| `sortable` | The columns the list can be sorted by. |
| `searchable` | The columns that are searched when the caller sends a search word. |
| `include` | Related data the caller may ask for, as `relation => [columns]`. The relation must exist on the model. Only the listed columns of it are returned. |
| `per_page`, `max_per_page` | How many records a page has, and the most a caller is allowed to ask for. |

### Step 3: Run the command

```bash
php artisan crud:api Article
```

It creates:

- `app/Http/Controllers/Api/ArticleController.php`
- `app/Http/Resources/ArticleResource.php`
- one line in `routes/crud-api.php`, which is loaded from `routes/api.php`

If you change `api()` later, run the command again with `--force`. The lists are written into the generated controller, so a change in the model has no effect until you generate again.

### Step 4: Get a token

To call a protected API you need a token. Add the `HasApiTokens` trait to your `User` model:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    // ...
}
```

For a quick test, make a token in Tinker:

```bash
php artisan tinker
```

```php
User::first()->createToken('test')->plainTextToken
```

Copy the text it prints. Send it with every request in the `Authorization` header.

### Step 5: Call it

List the articles:

```bash
curl -H "Accept: application/json" -H "Authorization: Bearer YOUR_TOKEN" http://localhost:8000/api/articles
```

Create one:

```bash
curl -X POST -H "Accept: application/json" -H "Authorization: Bearer YOUR_TOKEN" -F "title=Hello" -F "body=My first article" -F "cover=@photo.jpg" http://localhost:8000/api/articles
```

Everything you can ask for:

| Request | What you get |
| --- | --- |
| `GET /api/articles` | A list, split into pages. The answer has `data`, `links` and `meta`. |
| `GET /api/articles?filter[active]=1` | Only the records where `active` is 1. |
| `GET /api/articles?filter[user_id][]=1&filter[user_id][]=2` | Records where `user_id` is 1 or 2. |
| `GET /api/articles?search=apple` | Records where a searchable column contains "apple". |
| `GET /api/articles?sort=title` | Sorted by title, A to Z. |
| `GET /api/articles?sort=-title` | Sorted by title, Z to A. The minus sign reverses the order. |
| `GET /api/articles?include=author` | Each record also carries its author. |
| `GET /api/articles?per_page=20&page=2` | 20 records per page, second page. |
| `GET /api/articles/5` | The record with id 5. `?include=` works here too. |
| `POST /api/articles` | Creates a record. Answers with `201` and the new record. |
| `PUT /api/articles/5` | Updates a record. You send all fields. |
| `PATCH /api/articles/5` | Updates only the fields you send. The rest stay as they are. |
| `DELETE /api/articles/5` | Deletes the record and its uploaded files. Answers with `204`. |

You can combine them, for example `?filter[active]=1&sort=-id&include=author`.

A few things to know:

- If a caller asks to filter or sort by a column that is not on your list, that part is quietly ignored. The request still works.
- When validation fails, the answer is Laravel's usual `422` with the error messages.
- **Sending a file while updating.** PHP cannot read uploaded files on `PUT` or `PATCH` requests. Send a `POST` request and add a field `_method` with the value `PATCH`:

```bash
curl -X POST -H "Accept: application/json" -H "Authorization: Bearer YOUR_TOKEN" -F "_method=PATCH" -F "cover=@new-photo.jpg" http://localhost:8000/api/articles/5
```

## How your data is kept safe

You do not have to do anything for most of these. They are listed so you know what the generated code does and what is still up to you.

- **Who may do what.** If the model has a policy at the moment you generate, the page and the API ask it before every action (`viewAny`, `view`, `create`, `update`, `delete`). If there is no policy, only the route middleware stands in front of the page, and the command tells you so. To add the checks later, create the policy and generate again with `--force`.
- **The record being edited.** The id of the record in the edit form is locked. A user cannot change it in the browser to overwrite someone else's record.
- **Uploaded files.** A saved file path always comes from the database, never from what the browser sends.
- **Error messages.** When something breaks while saving, the real error goes to your log file. The user only sees a short general message, so no internal details leak out.
- **Rich text.** Before rich text is saved, it is cleaned: scripts, things like `onclick`, and `javascript:` links are removed. Normal formatting and images stay. This happens in the page and in the API. Two things are up to you:
  - Put a `max:` rule on rich text fields, because the cleaner itself sets no length limit.
  - Text that was saved before you had this version is only cleaned the next time that record is saved.
- **The API.** What can be read, written, filtered, sorted, searched and included is fixed in the generated code. A caller cannot widen it with clever query parameters.

### One note about installing as a dev dependency

Because the generated code stands on its own, you may install the package with `composer require --dev` so it is not on your live server. If you do that **and** you use `richtext` fields, also run:

```bash
composer require symfony/html-sanitizer
```

The generated code uses that library to clean rich text, and it normally arrives together with this package.

## What it cannot do yet

- A repeater that contains images can be read through the API but not changed through it.
- A repeater cannot sit inside another repeater.
- Images inside a repeater come back from the API as saved paths, not full URLs.
- The `--class` format expects Livewire views in the default place, `resources/views/livewire`.
- Uploads always go to the `public` disk, into the `uploads` folder. To use another place, change it in the generated code.

## Common problems

**Images are uploaded but do not show.**
Run `php artisan storage:link`. Also check that `APP_URL` in your `.env` file matches the address you open in the browser.

**The rich text field is an empty box.**
The editor is not in your JavaScript yet. Follow [Rich text setup](#rich-text-setup) and run `npm run build` again.

**The command says a file already exists.**
Add `--force` to replace it.

**I changed `fields()` but the page looks the same.**
The page is only written when you run the command. Run it again with `--force`.

**The page gives a 404.**
Check that `routes/web.php` ends with the line that loads `routes/crud.php`. If you used `--prefix`, the address has that prefix in front. If you cache routes, run `php artisan route:clear`.

**The sidebar link is missing.**
Make sure your layout contains `@include('partials.crud-nav')`. With the Tailwind preset you add this line yourself.

**The API answers 401.**
The token is missing or wrong. Send the header `Authorization: Bearer YOUR_TOKEN`, and also `Accept: application/json`.

**The API ignores a field I send.**
The field has to be in `writable` and it has to have a rule in `rules()`. Then generate the API again with `--force`.

**`crud:api` says there is no `routes/api.php`.**
Run `php artisan install:api` first.

## Working on the package itself

```bash
composer install
```

```bash
vendor/bin/phpunit
```

The tests generate every kind of page and API and check that the result is valid PHP. They also compare the output with saved copies in `tests/snapshots/`. If you change the output on purpose, delete those snapshot files and run the tests once; they are recorded again.
