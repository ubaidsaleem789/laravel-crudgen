<?php

namespace UbaidCrudGen\LaravelCrudGen\Commands;

use Illuminate\Console\Command;

class InstallAssets extends Command
{
    protected $signature = 'crudgen:install-assets';

    protected $description = 'Add Toast UI Editor to resources/js/app.js (only needed for rich text fields without Flux Pro)';

    public function handle(): int
    {
        $appJs = resource_path('js/app.js');

        if (! file_exists($appJs)) {
            $this->components->error('resources/js/app.js not found.');

            return self::FAILURE;
        }

        if (str_contains(file_get_contents($appJs), '// === CRUDGEN ASSETS START ===')) {
            $this->components->info('The editor import is already in app.js.');
        } else {
            file_put_contents($appJs, <<<'JS'


            // === CRUDGEN ASSETS START ===
            import { Editor } from '@toast-ui/editor';
            import '@toast-ui/editor/dist/toastui-editor.css';

            window.Editor = Editor;
            // === CRUDGEN ASSETS END ===

            JS, FILE_APPEND);
            $this->components->info('Toast UI Editor added to app.js.');
        }

        $this->components->warn('Now run: npm install @toast-ui/editor && npm run build');

        return self::SUCCESS;
    }
}
