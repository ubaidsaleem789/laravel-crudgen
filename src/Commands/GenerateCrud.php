<?php

namespace UbaidCrudGen\LaravelCrudGen\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use UbaidCrudGen\LaravelCrudGen\Generator;

class GenerateCrud extends Command
{
    protected $signature = 'crud:generate {model : Model name or full class name}
        {--singleRecord : One record only (settings / page section)}
        {--multiRecord : Table with create, edit and delete}
        {--sfc : Single-file component}
        {--mfc : Multi-file component}
        {--class : Class-based component}
        {--ui= : UI preset: flux, tailwind, or your own folder in stubs/crudgen (default: flux when installed)}
        {--layout= : Layout for the page, e.g. layouts::admin (default: livewire.component_layout)}
        {--force : Overwrite files that already exist}';

    protected $description = 'Generate a Livewire CRUD page from a model\'s fields() definition';

    public function handle(): int
    {
        if ($this->option('singleRecord') === $this->option('multiRecord')) {
            $this->components->error('Pass exactly one of --singleRecord or --multiRecord.');

            return self::FAILURE;
        }

        $formats = array_keys(array_filter($this->options(), fn ($on, $name) => $on && in_array($name, ['sfc', 'mfc', 'class']), ARRAY_FILTER_USE_BOTH));

        if (count($formats) > 1) {
            $this->components->error('Pass only one of --sfc, --mfc or --class.');

            return self::FAILURE;
        }

        $format = $formats[0] ?? config('livewire.make_command.type', 'sfc');

        $model = $this->argument('model');
        $modelClass = class_exists($model) ? $model : "App\\Models\\{$model}";

        foreach (['fields', 'rules'] as $method) {
            if (! method_exists($modelClass, $method)) {
                $this->components->error(class_exists($modelClass)
                    ? "{$modelClass} needs a static {$method}() method."
                    : "Model {$modelClass} not found.");

                return self::FAILURE;
            }
        }

        $fields = $modelClass::fields();

        // tableColumnShow() may mix 'name' and 'name' => 'type'; without it, show the first plain fields.
        $columns = method_exists($modelClass, 'tableColumnShow')
            ? $modelClass::tableColumnShow()
            : array_slice(array_keys(array_filter($fields, fn ($def) => is_string($def) && ! in_array($def, ['image', 'richtext', 'text']))), 0, 4);

        $columns = collect($columns)->mapWithKeys(fn ($value, $key) => is_int($key)
            ? [$value => 'string']
            : [$key => is_array($value) ? ($value['type'] ?? 'string') : $value])->all();

        $authorize = Gate::getPolicyFor($modelClass) !== null;

        // Stubs published to stubs/crudgen (vendor:publish --tag=crudgen-stubs) win over the package's own.
        $stubRoots = [base_path('stubs/crudgen'), dirname(__DIR__, 2).'/stubs'];
        $ui = $this->option('ui') ?: (class_exists(\Flux\Flux::class) ? 'flux' : 'tailwind');

        if (! array_filter($stubRoots, fn ($root) => is_dir("{$root}/{$ui}"))) {
            $this->components->error("Unknown UI [{$ui}]: no {$ui} folder in stubs/crudgen or in the package stubs.");

            return self::FAILURE;
        }

        $generator = new Generator($modelClass, $fields, $columns, [
            'ui' => $ui,
            'pro' => InstalledVersions::isInstalled('livewire/flux-pro'),
            'stubRoots' => $stubRoots,
            'mode' => $this->option('singleRecord') ? 'single' : 'multi',
            'format' => $format,
            'layout' => $this->option('layout'),
            'emoji' => (bool) config('livewire.make_command.emoji', true),
            'authorize' => $authorize,
            'pagesPath' => config('livewire.component_namespaces.pages', resource_path('views/pages')),
            'classPath' => config('livewire.class_path', app_path('Livewire')),
            'classNamespace' => config('livewire.class_namespace', 'App\\Livewire'),
            'viewPath' => config('livewire.view_path', resource_path('views/livewire')),
        ]);

        $files = $generator->files();

        if (! $this->option('force') && $existing = array_filter(array_keys($files), 'file_exists')) {
            $this->components->error('Already exists (use --force to overwrite): '.implode(', ', $existing));

            return self::FAILURE;
        }

        foreach ($files as $path => $contents) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $contents);
            $this->components->info("Created {$path}");
        }

        if (! $authorize) {
            $this->components->warn("No policy found for {$modelClass}: the page is protected only by its route middleware. Create a policy and re-run with --force to add authorization checks.");
        }

        if (str_contains(implode($files), 'window.Editor')) {
            $this->components->warn('The rich text field uses Toast UI Editor: run "php artisan crudgen:install-assets", then "npm install @toast-ui/editor" and rebuild.');
        }

        $this->line('  Add this route (inside your auth middleware group):');
        $this->line('  '.$generator->route());

        return self::SUCCESS;
    }
}
