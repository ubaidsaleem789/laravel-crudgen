<?php

namespace UbaidSaleem\LaravelCrudGen;

/**
 * Helpers shared by the page generator and the API generator.
 * The using class must have a $fields array (Model::fields()).
 */
trait BuildsCode
{
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

    /**
     * PHP source that cleans rich text HTML (scripts, event handlers, javascript: links) before it is stored.
     * The length is left to the model's rules; the sanitizer's own default would cut the text at 20 000 bytes.
     */
    private function sanitized(string $expr, string $else = 'null'): string
    {
        return "is_string({$expr}) ? (new HtmlSanitizer((new HtmlSanitizerConfig)->allowSafeElements()->withMaxInputLength(-1)))->sanitize({$expr}) : {$else}";
    }

    private function indent(string $code, string $indent): string
    {
        return preg_replace('/^(?=.)/m', $indent, $code);
    }

    /**
     * A placeholder alone on its line is a block: every line of the replacement takes the placeholder's indentation.
     * Placeholders that share a line with other text are replaced as-is.
     */
    private function blocks(string $stub, array $blocks): string
    {
        $stub = preg_replace_callback(
            '/^([ \t]*)\{\{ (\w+) \}\}$/m',
            fn ($m) => isset($blocks[$m[2]]) ? $this->indent($blocks[$m[2]], $m[1]) : $m[0],
            $stub,
        );

        return strtr($stub, collect($blocks)->mapWithKeys(fn ($code, $key) => ["{{ {$key} }}" => $code])->all());
    }

    /** Empty blocks leave blank lines behind; this removes them. */
    private function tidy(string $code): string
    {
        return preg_replace(
            ['/[ \t]+$/m', '/\n{3,}/', '/\{\n\n/', '/\n\n([ \t]*\})/'],
            ['', "\n\n", "{\n", "\n$1"],
            rtrim($code)."\n",
        );
    }
}
