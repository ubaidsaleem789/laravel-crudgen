<?php

namespace UbaidCrudGen\LaravelCrudGen\Commands;

use Illuminate\Console\Command;

class InstallAssets extends Command
{
    protected $signature = 'crudgen:install-assets';
    protected $description = 'Install Toast UI Editor and Flatpickr in app.js';

    public function handle()
    {

        $appJsPath = base_path('resources/js/app.js');

        if (!file_exists($appJsPath)) {
            $this->error('resources/js/app.js not found.');
            return;
        }

        $content = file_get_contents($appJsPath);

        $startTag = "// === CRUDGEN ASSETS START ===";
        $endTag = "// === CRUDGEN ASSETS END ===";

        $newBlock = <<<EOT
           {$startTag}
           import { Editor } from '@toast-ui/editor';
           import '@toast-ui/editor/dist/toastui-editor.css';

           import 'flatpickr/dist/flatpickr.css';
           import flatpickr from 'flatpickr';

           window.Editor = Editor;
           window.flatpickr = flatpickr;
           {$endTag}
        EOT;

        // Check if block already exists
        if (str_contains($content, $startTag)) {

            $this->info('CRUDGEN asset block already exists in app.js');

        } else {

            // Append neatly
            $content .= "\n\n" . $newBlock . "\n";
            file_put_contents($appJsPath, $content);
            $this->info('✅ Toast UI Editor and Flatpickr added to app.js');
            
        }

        $this->warn('⚠️ Don’t forget to run: npm install @toast-ui/editor flatpickr && npm run dev');

    }
}
