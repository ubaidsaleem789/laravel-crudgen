<?php

namespace UbaidSaleem\LaravelCrudGen;

/**
 * Pure string builders for the three app files the generator maintains:
 * routes/crud.php, config/crud-nav.php and the sidebar layout.
 */
class AppFiles
{
    /**
     * Adds the route line, replacing the earlier line for the same page or API.
     * $marker is text only that line contains, e.g. "->name('crud.article');".
     */
    public static function routes(?string $existing, string $marker, string $line): string
    {
        $existing ??= "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n"
            ."// Managed by the crud generator: one route per line, replaced when the same model is generated again.\n";

        $lines = array_filter(
            explode("\n", rtrim(str_replace("\r\n", "\n", $existing))),
            fn ($existingLine) => ! str_contains($existingLine, $marker),
        );

        return implode("\n", $lines)."\n{$line}\n";
    }

    /**
     * @param  array<string, array{label: string, icon: ?string, group: ?string}>  $entries  route name => sidebar entry
     */
    public static function nav(array $entries): string
    {
        $rows = '';
        foreach ($entries as $route => $entry) {
            $rows .= sprintf(
                "    %s => ['label' => %s, 'icon' => %s, 'group' => %s],\n",
                var_export($route, true),
                var_export($entry['label'], true),
                var_export($entry['icon'] ?? null, true),
                var_export($entry['group'] ?? null, true),
            );
        }

        return "<?php\n\n// Managed by crud:generate (safe to edit): route name => sidebar entry.\n"
            ."// 'group' => null shows the link on its own, outside any group.\nreturn [\n{$rows}];\n";
    }

    /** Puts the include before the first closing sidebar nav tag; null when there is nothing to do or nowhere to put it. */
    public static function sidebar(string $layout, string $include): ?string
    {
        if (str_contains($layout, $include) || ! preg_match('/^([ \t]*)<\/flux:sidebar\.nav>/m', $layout, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return substr_replace($layout, "{$m[1][0]}    {$include}\n", $m[0][1], 0);
    }
}
