<?php

namespace UbaidCrudGen\LaravelCrudGen\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use UbaidCrudGen\LaravelCrudGen\ApiGenerator;
use UbaidCrudGen\LaravelCrudGen\AppFiles;

class GenerateApi extends Command
{
    private const METHODS = ['index', 'show', 'store', 'update', 'destroy'];

    protected $signature = 'crud:api {model : Model name or full class name}
        {--no-route : Do not write a route}
        {--force : Overwrite files that already exist}';

    protected $description = 'Generate an API controller and resource from a model\'s api() definition';

    public function handle(): int
    {
        $model = $this->argument('model');
        $modelClass = class_exists($model) ? $model : "App\\Models\\{$model}";

        if (! method_exists($modelClass, 'api')) {
            return $this->stop(class_exists($modelClass) ? "{$modelClass} needs a static api() method." : "Model {$modelClass} not found.");
        }

        $api = $modelClass::api() + [
            'methods' => self::METHODS, 'show' => [], 'writable' => [], 'filterable' => [], 'sortable' => [],
            'searchable' => [], 'include' => [], 'per_page' => 15, 'max_per_page' => 100,
        ];
        $fields = method_exists($modelClass, 'fields') ? $modelClass::fields() : [];

        if (! $api['show']) {
            return $this->stop("api()['show'] must list the fields the API returns. Nothing is exposed by default.");
        }

        if ($unknown = array_diff($api['methods'], self::METHODS)) {
            return $this->stop('Unknown methods in api(): '.implode(', ', $unknown).'. Use: '.implode(', ', self::METHODS).'.');
        }

        // ponytail: a repeater with images needs per-item upload handling; over the API it stays read-only until someone needs it.
        foreach ($api['writable'] as $i => $field) {
            $def = $fields[$field] ?? null;

            if (is_array($def) && in_array('image', $def['fields'] ?? [], true)) {
                unset($api['writable'][$i]);
                $this->components->warn("[{$field}] has images inside a repeater: it is read-only over the API.");
            }
        }
        $api['writable'] = array_values($api['writable']);

        if (! $api['writable']) {
            $api['methods'] = array_diff($api['methods'], ['store', 'update']);
        } elseif (! method_exists($modelClass, 'rules')) {
            return $this->stop("{$modelClass} needs a static rules() method for writable fields.");
        }
        $api['methods'] = array_values(array_intersect(self::METHODS, $api['methods']));

        // include: 'relation' => ['field', ...]. A relation without a field list is refused.
        foreach ($api['include'] as $relation => $only) {
            $instance = is_string($relation) && method_exists($modelClass, $relation) ? (new $modelClass)->{$relation}() : null;

            if (! $instance instanceof Relation || ! is_array($only) || ! $only) {
                return $this->stop("api()['include'] must be 'relation' => ['fields', ...] for a relation on {$modelClass}; check [".(is_string($relation) ? $relation : $only).'].');
            }

            $api['include'][$relation] = [
                'fields' => $only,
                'many' => ! ($instance instanceof BelongsTo || $instance instanceof HasOne || $instance instanceof MorphOne || $instance instanceof HasOneThrough),
            ];
        }

        // No 'middleware' key in api() means "protect it"; a public API has to say 'middleware' => [] on purpose.
        $middleware = $modelClass::api()['middleware']
            ?? (class_exists(\Laravel\Sanctum\Sanctum::class) ? ['auth:sanctum', 'throttle:60,1'] : null);

        if ($middleware === null) {
            return $this->stop("No middleware for this API. Run \"php artisan install:api\" (Sanctum), or set 'middleware' in api() ([] for a public API).");
        }

        $routesFile = base_path('routes/api.php');

        if (! $this->option('no-route') && ! is_file($routesFile)) {
            return $this->stop('routes/api.php not found. Run "php artisan install:api" first, or pass --no-route.');
        }

        $authorize = Gate::getPolicyFor($modelClass) !== null;

        $generator = new ApiGenerator($modelClass, $fields, $api, [
            'authorize' => $authorize,
            'appPath' => app_path(),
            'appNamespace' => rtrim(app()->getNamespace(), '\\'),
        ]);

        $files = $generator->files();

        if (! $this->option('force') && $existing = array_filter(array_keys($files), 'file_exists')) {
            return $this->stop('Already exists (use --force to overwrite): '.implode(', ', $existing));
        }

        foreach ($files as $path => $contents) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $contents);
            $this->components->info("Created {$path}");
        }

        if (! $authorize) {
            $this->components->warn("No policy found for {$modelClass}: every caller that passes the route middleware can use every generated method. Create a policy and re-run with --force.");
        }

        if (! $middleware) {
            $this->components->warn('This API has no middleware: it is public.');
        }

        if (! $this->option('no-route')) {
            $line = $generator->route($middleware);
            $path = base_path('routes/crud-api.php');
            file_put_contents($path, AppFiles::routes(is_file($path) ? file_get_contents($path) : null, "->names('{$generator->routeName()}');", $line));
            $this->components->info("Route written to routes/crud-api.php: {$line}");

            if (! str_contains(file_get_contents($routesFile), 'crud-api.php')) {
                file_put_contents($routesFile, "\nrequire __DIR__.'/crud-api.php';\n", FILE_APPEND);
                $this->components->info('routes/api.php now loads routes/crud-api.php.');
            }
        }

        return self::SUCCESS;
    }

    private function stop(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
