<?php

namespace App\Http\Controllers\Concerns;

trait InlinesPrintAssets
{
    /**
     * Replace src/href URLs that point at the current app server with
     * inline data: URIs (base64 from public/) so Browsershot/Chromium
     * tidak harus balik nge-hit dev server yang single-thread (deadlock)
     * dan render PDF tetap jalan tanpa internet.
     */
    protected function inlineLocalAssets(string $html): string
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl === '') return $html;

        $publicRoot = public_path();

        return preg_replace_callback(
            '/(src|href)\s*=\s*"([^"]+)"/i',
            function ($m) use ($appUrl, $publicRoot) {
                $attr = $m[1];
                $url  = $m[2];
                if (!str_starts_with($url, $appUrl . '/')) {
                    return $m[0];
                }
                $path = parse_url($url, PHP_URL_PATH);
                if (!$path) return $m[0];
                $absolute = $publicRoot . str_replace('/', DIRECTORY_SEPARATOR, $path);
                if (!is_file($absolute)) return $m[0];
                $mime = @mime_content_type($absolute) ?: 'application/octet-stream';
                $data = base64_encode((string) file_get_contents($absolute));
                return $attr . '="data:' . $mime . ';base64,' . $data . '"';
            },
            $html
        ) ?? $html;
    }
}
