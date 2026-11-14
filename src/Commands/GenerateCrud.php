<?php

namespace UbaidCrudGen\LaravelCrudGen\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateCrud extends Command
{
    protected $signature = 'crud:generate {model} {--singleRecord} {--multiRecord}';
    protected $description = 'Generate CRUD Livewire components from model and migration';

    public function handle(): int
    {

        $modelName = $this->argument('model');
        $isSingle = $this->option('singleRecord');
        $isMulti = $this->option('multiRecord');
        $modelClass = "App\\Models\\$modelName";

        if ($isSingle && $isMulti) {
            $this->error("❌ You can't use both --singleRecord and --multiRecord at the same time.");
            return Command::FAILURE;
        }

        if (!$isSingle && !$isMulti) {
            $this->error("❌ Please specify one of the options: --singleRecord or --multiRecord.");
            return Command::FAILURE;
        }

        // Check if model exists
        if (!class_exists($modelClass)) {
            $this->error("❌ Model $modelClass not found.");
            return Command::FAILURE;
        }

        $table = (new $modelClass)->getTable();

        // Check if table exists
        if (!Schema::hasTable($table)) {
            $this->error("❌ Table '$table' does not exist. Did you run migrate?");
            return Command::FAILURE;
        }

        // Get columns with types
        $columns = $this->getTableColumns($table);

        $this->info("✅ Model: $modelName");
        $this->info("✅ Table: $table");
        $this->info("✅ Columns:");

        foreach ($columns as $col) {
            $this->line(" - {$col['name']} ({$col['type']})");
        }

        // Placeholder for actual file generation
        $this->info("🎉 Ready to generate " . ($isSingle ? "Single Record" : "Multi Record") . " CRUD for $modelName!");

        if ($isSingle) {
            $this->generateSingleRecordCrud($modelName);
        } else {
            $this->generateMultiRecordCrud($modelName);
        }

        // $this->generateLivewireComponents($modelName, $columns);

        return Command::SUCCESS;
    }

    protected function getTableColumns(string $table): array
    {
        $connection = DB::connection(); // Laravel connection
        $pdo = $connection->getPdo();   // Raw PDO connection

        $laravelDriver = config('database.default'); // 'mysql', 'pgsql', etc.
        $driverMap = [
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'sqlsrv' => 'pdo_sqlsrv',
        ];

        $doctrineDriver = $driverMap[$laravelDriver] ?? 'pdo_mysql'; // Fallback to MySQL

        $params = [
            'dbname'   => $connection->getDatabaseName(),
            'user'     => $connection->getConfig('username'),
            'password' => $connection->getConfig('password'),
            'host'     => $connection->getConfig('host'),
            'driver'   => $doctrineDriver,
            'pdo'      => $pdo,
        ];

        $doctrineConfig = new \Doctrine\DBAL\Configuration();
        $doctrineConn = \Doctrine\DBAL\DriverManager::getConnection($params, $doctrineConfig);
        $schemaManager = $doctrineConn->createSchemaManager();

        $columns = [];
        foreach ($schemaManager->listTableColumns($table) as $column) {
            $columns[] = [
                'name' => $column->getName(),
                'type' => $column->getType()->getName(),
                'nullable' => !$column->getNotnull(),
                'default' => $column->getDefault(),
            ];
        }

        return $columns;
    }





    protected function generateSingleRecordCrud(string $model): void
    {
        $this->info("🛠 Generating Single Record Livewire component...");

        $className = $model . 'Form';
        $namespace = "App\\Livewire\\Crud\\Single";
        $directory = app_path('Livewire/Crud/Single');
        $viewPath = resource_path('views/livewire/Crud/single');
        $viewFile = $viewPath . '/' . Str::kebab($model) . '-form.blade.php';

        // Create directory if not exists
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        if (!file_exists($viewPath)) {
            mkdir($viewPath, 0755, true);
        }

        $modelClass = "App\\Models\\$model";

        if (!class_exists($modelClass)) {
            $this->error("Model class [$modelClass] not found.");
            return;
        }

        if (!method_exists($modelClass, 'fields')) {
            $this->error("Method 'fields()' not defined in [$modelClass].");
            return;
        }

        // $modelInstance = new $modelClass;
        $fieldsArray = $modelClass::fields();

        $fieldsBlock = collect($fieldsArray)->map(function ($type, $field) {

            if ($type === 'image') {

                return <<<PHP
                public \$$field;
                public \${$field}Show;
                PHP;
            }

            if (is_array($type) && ($type['type'] ?? '') === 'json' && ($type['repeatable'] ?? false)) {
                return "public \${$field} = [];";
            }

            return "public \$$field;";
        })->implode("\n    ");

        $mountBlock = collect($fieldsArray)->map(function ($type, $field) use ($model) {

            if ($type === 'image') {

                return "\$this->{$field}Show = \$this->var{$model}->{$field};";
            }

            return "\$this->{$field} = \$this->var{$model}->{$field};";
        })->implode("\n            ");

        $saveBlock = collect($fieldsArray)->map(function ($type, $field) {
            // For regular image field
            if ($type === 'image') {
                return <<<PHP
            if (\$this->{$field}) {
                if (\$record->{$field} && Storage::disk('public')->exists(\$record->{$field})) {
                    Storage::disk('public')->delete(\$record->{$field});
                }
                \$record->{$field} = \$this->{$field}->store('uploads', 'public') ?? null;
            }
            PHP;
            }

            // For repeatable JSON field (array of items)
            if (is_array($type) && ($type['type'] ?? '') === 'json' && ($type['repeatable'] ?? false)) {
                $imageFields = collect($type['fields'])->filter(fn($t, $f) => $t === 'image')->keys();

                $nonImageFields = collect($type['fields'])->reject(fn($t, $f) => $t === 'image')->keys();

                // Upload and replace image logic
                $imageProcessing = $imageFields->map(function ($imageField) {
                    return <<<PHP
                if (isset(\$feature['{$imageField}']) && is_object(\$feature['{$imageField}'])) {
                    if (!empty(\$existing['{$imageField}'])) {
                        Storage::disk('public')->delete(\$existing['{$imageField}']);
                    }
                    \$existing['{$imageField}'] = \$feature['{$imageField}']->store('uploads', 'public') ?? null;
                }
            PHP;
                })->implode("\n                ");

                // Map all other fields, assigning image fields as `null` if not set
                // $fieldMapping = collect($type['fields'])->map(function ($ftype, $fname) {
                //     $default = $ftype == 'image' ? 'null' : "''";
                //     return "'{$fname}' => \$feature['{$fname}'] ?? {$default}";
                // })->implode(",\n                        ");

                $fieldMapping = $nonImageFields->map(function ($fname) {
                    return "'{$fname}' => \$feature['{$fname}'] ?? ''";
                })->implode(",\n                        ");

                // Delete removed items' image files
                $removeImageDeletes = $imageFields->map(fn($img) => <<<PHP
                                                                    if (!empty(\$removedFeature['{$img}'])) {
                                                                        Storage::disk('public')->delete(\$removedFeature['{$img}']);
                                                                    }
                                                                PHP)->implode("\n                ");

                return <<<PHP
            // Handle {$field}
            \$existing{$field} = collect(\$record->{$field} ?? []);
            \$new{$field} = collect(\$this->{$field});
            \$updated{$field} = [];

            \$existingFeature{$field}Map = \$existing{$field}->keyBy('uuid');

            foreach (\$new{$field} as \$feature) {
                \$uuid = \$feature['uuid'] ?? null;

                // New item
                if (!\$uuid || !\$existingFeature{$field}Map->has(\$uuid)) {
                    \$newItem = [
                        'uuid' => Str::uuid()->toString(),
                    ];

                    foreach ({$imageFields->toJson()} as \$imgField) {
                        \$newItem[\$imgField] = isset(\$feature[\$imgField]) && is_object(\$feature[\$imgField])
                            ? \$feature[\$imgField]->store('uploads', 'public') ?? null
                            : null;
                    }

                    \$newItem = array_merge(\$newItem, [
                        {$fieldMapping}
                    ]);

                    \$updated{$field}[] = \$newItem;
                    continue;
                }

                // Existing item
                \$existing = \$existingFeature{$field}Map[\$uuid];
                {$imageProcessing}

                \$updated{$field}[] = array_merge(\$existing, [
                    'uuid' => \$uuid,
                    {$fieldMapping}
                ]);

                \$existingFeature{$field}Map->forget(\$uuid);
            }

            // Delete removed items and their images
            foreach (\$existingFeature{$field}Map as \$removedFeature) {
                {$removeImageDeletes}
            }

            \$record->{$field} = \$updated{$field};
        PHP;
            }

            // Default non-special case
            return "\$record->{$field} = \$this->{$field};";
        })->implode("\n            ");


        $jsonMethodsBlock = collect($fieldsArray)
            ->filter(fn($type) => is_array($type) && ($type['type'] ?? '') === 'json' && ($type['repeatable'] ?? false))
            ->map(function ($type, $field) {
                // $fieldMethods = Str::studly($field);
                $fieldMethods = $field;
                $defaultData = collect($type['fields'])->map(function ($innerType, $innerField) {
                    if ($innerField === 'uuid') {
                        return "'uuid' => (string) Str::uuid()";
                    } elseif ($innerType === 'image') {
                        return "'{$innerField}' => null";
                    } else {
                        return "'{$innerField}' => ''";
                    }
                })->implode(",\n            ");

                return <<<PHP
                        public function add{$fieldMethods}()
                        {
                            \$this->{$field}[] = [
                                'uuid' => (string) Str::uuid(),
                                {$defaultData}
                            ];
                        }

                        public function remove{$fieldMethods}(\$uuid)
                        {
                            \$this->{$field} = collect(\$this->{$field})
                                ->reject(fn(\$item) => \$item['uuid'] === \$uuid)
                                ->values()
                                ->toArray();
                        }
                    PHP;
            })->implode("\n\n");


        $classStub = file_get_contents(__DIR__ . '/../../stubs/singleRecordController.stub');
        $classStub = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ model }}', '{{ fieldsBlock }}', '{{ mountBlock }}', '{{ saveBlock }}', '{{ viewName }}', '{{ jsonMethodsBlock }}'],
            [$namespace, $className, $model, $fieldsBlock, $mountBlock, $saveBlock, Str::kebab($model) . '-form', $jsonMethodsBlock],
            $classStub
        );
        file_put_contents("$directory/{$className}.php", $classStub);



        function renderField(string $name, array|string $definition, ?string $parentKey = null, bool $isRepeatableSubField = false, ?string $parentName = null): string
        {
            if (is_string($definition)) {
                $type = $definition;
                $meta = [];
            } else {
                $type = $definition['type'] ?? 'string';
                $meta = $definition;
            }

            $errorMsg = $parentKey ? "{$parentName}.\$index.{$name}" : $name;

            $stubPath = match (true) {
                $type === 'text' => __DIR__ . '/../../stubs/fields/text.stub',
                // Now, 'image' type specifically checks $isRepeatableSubField for the correct stub
                $type === 'image' && $isRepeatableSubField => __DIR__ . '/../../stubs/fields/image_repeatable.stub',
                $type === 'richtext' && $isRepeatableSubField => __DIR__ . '/../../stubs/fields/richtext_repeatable.stub',
                $type === 'image' => __DIR__ . '/../../stubs/fields/image.stub',
                $type === 'richtext' => __DIR__ . '/../../stubs/fields/richtext.stub',
                $type === 'boolean' => __DIR__ . '/../../stubs/fields/boolean.stub',
                $type === 'date' => __DIR__ . '/../../stubs/fields/date.stub',
                $type === 'json' => __DIR__ . '/../../stubs/fields/json.stub',
                is_string($type) && str_starts_with($type, 'foreign') => __DIR__ . '/../../stubs/fields/foreign.stub', // Foreign keys can just be string inputs for now
                default => __DIR__ . '/../../stubs/fields/string.stub',
            };

            if (!file_exists($stubPath)) {
                // You can log this or throw an exception
                // $thisCommand->error("Missing stub for field [$name] of type [$type] at: $stubPath");
                return "";
            }

            $stub = file_get_contents($stubPath);

            // If this is a json field with subfields (the container for repeatable items)
            if ($type === 'json' && isset($meta['fields']) && is_array($meta['fields'])) {
                $subfields = [];
                foreach ($meta['fields'] as $subField => $subType) {
                    // This is the critical line. Ensure {{ $index }} is a literal part of the string.
                    $subfields[] = renderField($subField, $subType, "{$name}.{{ \$index }}", in_array($subType, ['image', 'richtext']), $name);
                }

                $formattedFieldName = Str::title(str_replace('_', ' ', $name));
                $addMethod = "add" . Str::studly($name);
                $removeMethod = "remove" . Str::studly($name);
                $singularFieldName = Str::singular(Str::title(str_replace('_', ' ', $name)));


                return str_replace(
                    [
                        '{{ field }}',
                        '{{ formattedField }}',
                        '{{ subfields }}',
                        '{{ addButtonMethod }}',
                        '{{ removeButtonMethod }}',
                        '{{ singularFieldName }}',
                        '{{ errorMsg }}'
                    ],
                    [
                        $name, // e.g., 'locations'
                        $formattedFieldName,
                        implode("\n\n", $subfields),
                        $addMethod,
                        $removeMethod,
                        $singularFieldName,
                        $errorMsg
                    ],
                    $stub
                );
            }

            // For individual fields (top-level or subfields within repeatable JSON)
            $wireModelPath = $parentKey ? "{$parentKey}.{$name}" : $name;
            $label = Str::title(str_replace('_', ' ', $name));

            // For image_repeatable.stub, we need to pass the *actual* $item and its specific field name
            // The `image_repeatable.stub` will then directly reference these.
            if ($isRepeatableSubField && $parentKey) {
                // Here, $parentKey is like 'locations.{{ $index }}'
                // We need to replace these placeholders in the stub with Blade variables
                $stub = str_replace(
                    [
                        '{{ field }}', // e.g., locations.{{ $index }}.imgAlt2
                        '{{ label }}',
                        '{{ currentItemVariable }}', // New placeholder for the current $item variable
                        '{{ subfieldName }}', // New placeholder for the actual subfield name (e.g., imgAlt2)
                        '{{ errorMsg }}'
                    ],
                    [
                        $wireModelPath,
                        $label,
                        // This assumes the json.stub's foreach loop uses `$item` as the loop variable.
                        // We are literally injecting '$item' as a string into the generated Blade.
                        '$item',
                        $name, // The actual field name, e.g., 'imgAlt2'
                        $errorMsg
                    ],
                    $stub
                );
            } else {
                // For regular fields or top-level images
                $stub = str_replace(
                    [
                        '{{ field }}',
                        '{{ label }}',
                        '{{ errorMsg }}'
                        // These placeholders are not needed for non-repeatable images
                        // '{{ currentItemVariable }}',
                        // '{{ subfieldName }}'
                    ],
                    [
                        $wireModelPath,
                        $label,
                        $errorMsg
                    ],
                    $stub
                );
            }

            if (str_starts_with($type, 'foreign')) {

                $parts = explode(':', $type);
                $details = explode(',', $parts[1] ?? '');

                $relatedModel = $details[0] ?? null; // e.g. User
                $relatedKey = $details[1] ?? 'id';   // e.g. id
                $relatedLabel = $details[2] ?? 'name'; // e.g. name

                $stub = str_replace(
                    [
                        '{{ field }}',
                        '{{ label }}',
                        '{{ relatedModel }}',
                        '{{ relatedKey }}',
                        '{{ relatedLabel }}',
                        '{{ errorMsg }}'
                    ],
                    [
                        $wireModelPath,
                        $label,
                        $relatedModel,
                        $relatedKey,
                        $relatedLabel,
                        $errorMsg

                    ],
                    $stub
                );

                return $stub;
            }

            return $stub;
        }



        // Generate form fields based on types
        $fields = collect($fieldsArray)->map(function ($type, $name) {
            return renderField($name, $type);
        })->implode("\n\n");


        $viewStub = file_get_contents(__DIR__ . '/../../stubs/singleRecordView.stub');
        $viewStub = str_replace(['{{ fields }}', '{{ modelName }}'], [$fields, $model], $viewStub);
        file_put_contents($viewFile, $viewStub);


        $this->info("✅ Livewire class generated: {$className}");
        $this->info("✅ Blade view generated: " . basename($viewFile));
    }



    protected function generateMultiRecordCrud(string $model): void
    {
        // TODO: Add stub logic using your layout
        $this->info("🛠 Generating Multi Record Livewire component...");

        $className = $model . 'Form';
        $namespace = "App\\Livewire\\Crud\\Multi";
        $directory = app_path('Livewire/Crud/Multi');
        $viewPath = resource_path('views/livewire/Crud/multi');
        $viewFile = $viewPath . '/' . Str::kebab($model) . '-form.blade.php';

        // Create directory if not exists
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        if (!file_exists($viewPath)) {
            mkdir($viewPath, 0755, true);
        }

        $modelClass = "App\\Models\\$model";

        if (!class_exists($modelClass)) {
            $this->error("Model class [$modelClass] not found.");
            return;
        }

        if (!method_exists($modelClass, 'fields')) {
            $this->error("Method 'fields()' not defined in [$modelClass].");
            return;
        }

        // $modelInstance = new $modelClass;
        $fieldsArray = $modelClass::fields();

        $fieldsBlock = collect($fieldsArray)->map(function ($type, $field) {

            if ($type === 'image') {

                return <<<PHP
                public \$$field;
                public \${$field}Show;
                PHP;
            }

            if (is_array($type) && ($type['type'] ?? '') === 'json' && ($type['repeatable'] ?? false)) {
                return "public \${$field} = [];";
            }

            return "public \$$field;";
        })->implode("\n    ");

        $editShowBlock = collect($fieldsArray)->map(function ($type, $field) use ($model) {

            if ($type === 'image') {

                return "\$this->{$field}Show = \$cbl->{$field};";
            }

            return "\$this->{$field} = \$cbl->{$field};";
        })->implode("\n            ");

        $updateBlock = collect($fieldsArray)->map(function ($type, $field) {

            if ($type === 'image') {
                return <<<PHP
                        if (\$this->{$field}) {
                            if (\$record->{$field} && Storage::disk('public')->exists(\$record->{$field})) {
                                Storage::disk('public')->delete(\$record->{$field});
                            }
                            \$record->{$field} = \$this->{$field}->store('uploads', 'public');
                        }
                    PHP;
            }

            // Repeatable JSON handling with dynamic image fields
            if (is_array($type) && ($type['type'] ?? '') === 'json' && ($type['repeatable'] ?? false)) {

                $imageFields = collect($type['fields'])->filter(fn($t, $f) => $t === 'image')->keys();

                $nonImageFields = collect($type['fields'])
                    ->reject(fn($t, $f) => $t === 'image')
                    ->keys();

                $inner = $imageFields->map(function ($imageField) use ($field) {
                    return <<<PHP
                            if (isset(\$feature['{$imageField}']) && is_object(\$feature['{$imageField}'])) {
                                if (!empty(\$existing['{$imageField}'])) {
                                    Storage::disk('public')->delete(\$existing['{$imageField}']);
                                }
                                \$existing['{$imageField}'] = \$feature['{$imageField}']->store('uploads', 'public') ?? null;
                            }
                        PHP;
                })->implode("\n                    ");

                $otherFields = $nonImageFields->map(function ($fname) {
                    return "'{$fname}' => \$feature['{$fname}'] ?? ''";
                })->implode(",\n                        ");



                $removeImageDeletes = $imageFields->map(fn($img) => <<<PHP
                                                                    if (!empty(\$removedFeature['{$img}'])) {
                                                                        Storage::disk('public')->delete(\$removedFeature['{$img}']);
                                                                    }
                                                                PHP)->implode("\n                ");

                return <<<PHP
                            // Handle {$field}
                            \$existing{$field} = collect(\$record->{$field} ?? []);
                            \$new{$field} = collect(\$this->{$field});
                            \$updated{$field} = [];

                            \$existingFeature{$field}Map = \$existing{$field}->keyBy('uuid');

                            foreach (\$new{$field} as \$feature) {
                                \$uuid = \$feature['uuid'] ?? null;

                                if (!\$uuid || !\$existingFeature{$field}Map->has(\$uuid)) {
                                    \$newItem = [
                                        'uuid' => Str::uuid()->toString(),
                                    ];

                                    foreach ({$imageFields->toJson()} as \$imgField) {
                                        \$newItem[\$imgField] = isset(\$feature[\$imgField]) && is_object(\$feature[\$imgField])
                                            ? \$feature[\$imgField]->store('uploads', 'public') ?? null
                                            : null;
                                    }

                                    \$newItem = array_merge(\$newItem, [
                                        {$otherFields}
                                    ]);

                                    \$updated{$field}[] = \$newItem;
                                    continue;
                                }

                                // Existing item
                                \$existing = \$existingFeature{$field}Map[\$uuid];

                                {$inner}

                                \$updated{$field}[] = array_merge(\$existing, [
                                    'uuid' => \$uuid,
                                    {$otherFields}
                                ]);

                                \$existingFeature{$field}Map->forget(\$uuid);
                            }

                         // Delete removed items and their images
                            foreach (\$existingFeature{$field}Map as \$removedFeature) {
                                {$removeImageDeletes}
                            }

                                    \$record->{$field} = \$updated{$field};
                PHP;
            }

            return "\$record->{$field} = \$this->{$field};";
        })->implode("\n            ");

        $saveBlock = collect($fieldsArray)->map(function ($type, $field) {
            if ($type === 'image') {
                return <<<PHP
            if (\$this->{$field}) {
                if (\$record->{$field} && Storage::disk('public')->exists(\$record->{$field})) {
                    Storage::disk('public')->delete(\$record->{$field});
                }
                \$record->{$field} = \$this->{$field}->store('uploads', 'public');
            }
            PHP;
            }

            // Handle repeatable JSON with possible image fields
            if (is_array($type) && ($type['type'] ?? '') === 'json' && ($type['repeatable'] ?? false)) {
                $imageFields = collect($type['fields'])->filter(fn($t) => $t === 'image')->keys();
                $nonImageFields = collect($type['fields'])->reject(fn($t, $f) => $t === 'image')->keys();
                $otherFields = $nonImageFields->map(function ($fname) {
                    return "'{$fname}' => \$feature['{$fname}'] ?? ''";
                })->implode(",\n                        ");

                $imageUploads = $imageFields->map(function ($imgField) {
                    return <<<PHP
                if (isset(\$feature['{$imgField}']) && is_object(\$feature['{$imgField}'])) {
                    \$featureImage{$imgField} = \$feature['{$imgField}']->store('uploads/{$imgField}', 'public') ?? null;
                } else {
                    \$featureImage{$imgField} = \$feature['{$imgField}'] ?? null;
                }
            PHP;
                })->implode("\n                ");

                $imageAssignments = $imageFields->map(function ($imgField) {
                    return "'{$imgField}' => \$featureImage{$imgField}";
                })->implode(",\n                    ");

                return <<<PHP
            // Handle repeatable JSON field: {$field}
            \$processed{$field} = [];
            foreach (\$this->{$field} as &\$feature) {
                if (!isset(\$feature['uuid']) || empty(\$feature['uuid'])) {
                    \$feature['uuid'] = (string) Str::uuid();
                }

                {$imageUploads}

                \$processed{$field}[] = [
                    'uuid' => \$feature['uuid'],
                    {$otherFields},
                    {$imageAssignments}
                ];
            }
            \$record->{$field} = \$processed{$field};
            PHP;
            }

            return "\$record->{$field} = \$this->{$field};";
        })->implode("\n        ");

        $deleteBlock = collect($fieldsArray)->map(function ($type, $field) {
            // Case 1: Single image field
            if ($type === 'image') {
                return <<<PHP
                            if (!empty(\$record->{$field})) {
                                Storage::disk('public')->delete(\$record->{$field});
                            }
                            PHP;
            }

            // Case 2: Repeatable JSON with image fields
            if (is_array($type) && ($type['type'] ?? '') === 'json' && ($type['repeatable'] ?? false)) {
                $imageFields = collect($type['fields'])->filter(fn($t) => $t === 'image')->keys();

                if ($imageFields->isEmpty()) return null;

                $nestedDeletes = $imageFields->map(function ($imgField) {
                    return <<<PHP
                            if (!empty(\$item['{$imgField}'])) {
                                Storage::disk('public')->delete(\$item['{$imgField}']);
                            }
                            PHP;
                })->implode("\n            ");

                return <<<PHP
                        // Delete nested images in {$field}
                        foreach ((\$record->{$field} ?? []) as \$item) {
                            {$nestedDeletes}
                        }
                        PHP;
            }

            return null;
        })->filter()->implode("\n        ");



        $jsonMethodsBlock = collect($fieldsArray)
            ->filter(fn($type) => is_array($type) && ($type['type'] ?? '') === 'json' && ($type['repeatable'] ?? false))
            ->map(function ($type, $field) {
                // $fieldMethods = Str::studly($field);
                $fieldMethods = $field;
                $defaultData = collect($type['fields'])->map(function ($innerType, $innerField) {
                    if ($innerField === 'uuid') {
                        return "'uuid' => (string) Str::uuid()";
                    } elseif ($innerType === 'image') {
                        return "'{$innerField}' => null";
                    } else {
                        return "'{$innerField}' => ''";
                    }
                })->implode(",\n            ");

                return <<<PHP
                        public function add{$fieldMethods}()
                        {
                            \$this->{$field}[] = [
                                'uuid' => (string) Str::uuid(),
                                {$defaultData}
                            ];
                        }

                        public function remove{$fieldMethods}(\$uuid)
                        {
                            \$this->{$field} = collect(\$this->{$field})
                                ->reject(fn(\$item) => \$item['uuid'] === \$uuid)
                                ->values()
                                ->toArray();
                        }
                    PHP;
            })->implode("\n\n");


        $classStub = file_get_contents(__DIR__ . '/../../stubs/multiRecordController.stub');
        $classStub = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ model }}', '{{ fieldsBlock }}', '{{ editShowBlock }}', '{{ saveBlock }}', '{{ updateBlock }}', '{{ deleteBlock }}', '{{ viewName }}', '{{ jsonMethodsBlock }}'],
            [$namespace, $className, $model, $fieldsBlock, $editShowBlock, $saveBlock, $updateBlock, $deleteBlock, Str::kebab($model) . '-form', $jsonMethodsBlock],
            $classStub
        );
        file_put_contents("$directory/{$className}.php", $classStub);





        function renderTableHeader(string $name): string
        {
            $stub = file_get_contents(__DIR__ . '/../../stubs/table/table_header.stub');
            return str_replace('{{ field }}', $name, $stub);
        }

        function renderTableCell(string $name, string $type): string
        {

            if (str_starts_with($type, 'foreign:')) {


                // Example: foreign:Region,id,name
                [$modelN, $key, $display] = explode(',', str_replace('foreign:', '', $type));

                // Relation name = camelCase of model name (Region → region)
                $relation = \Illuminate\Support\Str::camel(class_basename($modelN));

                $stubPath = __DIR__ . '/../../stubs/table/table_cell_foreign.stub';
                $stub = file_get_contents($stubPath);

                return str_replace(
                    ['{{ relation }}', '{{ column }}'],
                    [$relation, $display],
                    $stub
                );
            }


            $isImage = $type === 'image';
            $stubPath = $isImage
                ? __DIR__ . '/../../stubs/table/table_cell_image.stub'
                : __DIR__ . '/../../stubs/table/table_cell.stub';

            $stub = file_get_contents($stubPath);
            return str_replace('{{ field }}', $name, $stub);
        }

        $modelInstance = new $modelClass;
        $columnsRaw = $modelInstance::tableColumnShow() ?? [];

        // $this->info(json_encode($columnsRaw, JSON_PRETTY_PRINT));

        // Normalize column definitions
        $columns = [];
        foreach ($columnsRaw as $key => $value) {
            if (is_int($key)) {
                $columns[$value] = 'string'; // default type
            } else {
                $columns[$key] = $value; // explicitly defined
            }
        }

        $tableHeaders = collect($columns)->map(fn($type, $name) => renderTableHeader($name))->implode("\n");
        $tableCells = collect($columns)->map(fn($type, $name) => renderTableCell($name, is_array($type) ? $type['type'] ?? 'string' : $type))->implode("\n");

        Log::info('Before collect...');
        // Generate form fields based on types
        $fields = collect($fieldsArray)->map(function ($type, $name) {

            Log::debug("Field debug", compact('name', 'type'));

            return $this->renderFields($name, $type);
        })->implode("\n\n");


        $viewStub = file_get_contents(__DIR__ . '/../../stubs/multiRecordView.stub');
        $viewStub = str_replace(['{{ fields }}', '{{ modelName }}', '{{ tableHeaders }}', '{{ tableCells }}'], [$fields, $model, $tableHeaders, $tableCells], $viewStub);
        file_put_contents($viewFile, $viewStub);


        $this->info("✅ Livewire class generated: {$className}");
        $this->info("✅ Blade view generated: " . basename($viewFile));
    }

    private function renderFields(string $name, array|string $definition, ?string $parentKey = null, bool $isRepeatableSubField = false, ?string $parentName = null): string
    {
        if (is_string($definition)) {
            $type = $definition;
            $meta = [];
        } else {
            $type = $definition['type'] ?? 'string';
            $meta = $definition;
        }

        $errorMsg = $parentKey ? "{$parentName}.\$index.{$name}" : $name;

        $stubPath = match (true) {
            $type === 'text' => __DIR__ . '/../../stubs/fields/text.stub',
            $type === 'boolean' => __DIR__ . '/../../stubs/fields/boolean.stub',
            // Now, 'image' type specifically checks $isRepeatableSubField for the correct stub
            $type === 'image' && $isRepeatableSubField => __DIR__ . '/../../stubs/fields/image_repeatable.stub',
            $type === 'richtext' && $isRepeatableSubField => __DIR__ . '/../../stubs/fields/richtext_repeatable.stub',
            $type === 'image' => __DIR__ . '/../../stubs/fields/image.stub',
            $type === 'richtext' => __DIR__ . '/../../stubs/fields/richtext.stub',
            $type === 'date' => __DIR__ . '/../../stubs/fields/date.stub',
            $type === 'json' => __DIR__ . '/../../stubs/fields/json.stub',
            is_string($type) && str_starts_with($type, 'foreign') => __DIR__ . '/../../stubs/fields/foreign.stub', // Foreign keys can just be string inputs for now
            default => __DIR__ . '/../../stubs/fields/string.stub',
        };


        if (!file_exists($stubPath)) {
            Log::error("Missing stub: $stubPath for field [$name] of type [$type]");
            // You can log this or throw an exception
            // $thisCommand->error("Missing stub for field [$name] of type [$type] at: $stubPath");
            return "";
        }

        $stub = file_get_contents($stubPath);

        // If this is a json field with subfields (the container for repeatable items)
        if ($type === 'json' && isset($meta['fields']) && is_array($meta['fields'])) {
            $subfields = [];
            foreach ($meta['fields'] as $subField => $subType) {
                // This is the critical line. Ensure {{ $index }} is a literal part of the string.
                $subfields[] = $this->renderFields($subField, $subType, "{$name}.{{ \$index }}", in_array($subType, ['image', 'richtext']), $name);
            }

            $formattedFieldName = Str::title(str_replace('_', ' ', $name));
            $addMethod = "add" . Str::studly($name);
            $removeMethod = "remove" . Str::studly($name);
            $singularFieldName = Str::singular(Str::title(str_replace('_', ' ', $name)));


            return str_replace(
                [
                    '{{ field }}',
                    '{{ formattedField }}',
                    '{{ subfields }}',
                    '{{ addButtonMethod }}',
                    '{{ removeButtonMethod }}',
                    '{{ singularFieldName }}',
                    '{{ errorMsg }}'
                ],
                [
                    $name, // e.g., 'locations'
                    $formattedFieldName,
                    implode("\n\n", $subfields),
                    $addMethod,
                    $removeMethod,
                    $singularFieldName,
                    $errorMsg
                ],
                $stub
            );
        }

        // For individual fields (top-level or subfields within repeatable JSON)
        $wireModelPath = $parentKey ? "{$parentKey}.{$name}" : $name;
        $label = Str::title(str_replace('_', ' ', $name));

        // For image_repeatable.stub, we need to pass the *actual* $item and its specific field name
        // The `image_repeatable.stub` will then directly reference these.
        if ($isRepeatableSubField && $parentKey) {
            // Here, $parentKey is like 'locations.{{ $index }}'
            // We need to replace these placeholders in the stub with Blade variables
            $stub = str_replace(
                [
                    '{{ field }}', // e.g., locations.{{ $index }}.imgAlt2
                    '{{ label }}',
                    '{{ currentItemVariable }}', // New placeholder for the current $item variable
                    '{{ subfieldName }}', // New placeholder for the actual subfield name (e.g., imgAlt2)
                    '{{ errorMsg }}'
                ],
                [
                    $wireModelPath,
                    $label,
                    // This assumes the json.stub's foreach loop uses `$item` as the loop variable.
                    // We are literally injecting '$item' as a string into the generated Blade.
                    '$item',
                    $name, // The actual field name, e.g., 'imgAlt2'
                    $errorMsg
                ],
                $stub
            );
        } else {
            // For regular fields or top-level images
            $stub = str_replace(
                [
                    '{{ field }}',
                    '{{ label }}',
                    '{{ errorMsg }}'
                    // These placeholders are not needed for non-repeatable images
                    // '{{ currentItemVariable }}',
                    // '{{ subfieldName }}'
                ],
                [
                    $wireModelPath,
                    $label,
                    $errorMsg
                ],
                $stub
            );
        }

        if (str_starts_with($type, 'foreign')) {

            $parts = explode(':', $type);
            $details = explode(',', $parts[1] ?? '');

            $relatedModel = $details[0] ?? null; // e.g. User
            $relatedKey = $details[1] ?? 'id';   // e.g. id
            $relatedLabel = $details[2] ?? 'name'; // e.g. name

            $stub = str_replace(
                [
                    '{{ field }}',
                    '{{ label }}',
                    '{{ relatedModel }}',
                    '{{ relatedKey }}',
                    '{{ relatedLabel }}',
                    '{{ errorMsg }}'
                ],
                [
                    $wireModelPath,
                    $label,
                    $relatedModel,
                    $relatedKey,
                    $relatedLabel,
                    $errorMsg

                ],
                $stub
            );

            return $stub;
        }

        return $stub;
    }
}
