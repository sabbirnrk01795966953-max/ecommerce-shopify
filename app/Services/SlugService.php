<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SlugService
{
    public static function normalizeOrGenerate(?string $input, string $fallback, string $prefix = 'item'): string
    {
        $input = trim((string) $input);
        if ($input !== '') {
            $path = parse_url($input, PHP_URL_PATH) ?: $input;
            $path = trim($path, '/');
            $segments = array_values(array_filter(explode('/', $path)));
            $input = end($segments) ?: $input;
        } else {
            $input = $fallback;
        }

        $slug = Str::slug($input);
        return $slug !== '' ? $slug : $prefix.'-'.Str::lower(Str::random(8));
    }

    public static function normalizeUnicodeOrGenerate(?string $input, string $fallback, string $prefix = 'item'): string
    {
        $input = trim((string) $input);

        if ($input !== '') {
            $path = parse_url($input, PHP_URL_PATH) ?: $input;
            $path = trim(rawurldecode((string) $path), '/');
            $segments = array_values(array_filter(explode('/', $path)));
            $input = end($segments) ?: $input;
        } else {
            $input = $fallback;
        }

        $input = rawurldecode(trim((string) $input));
        $input = preg_replace('/\s+/u', '-', $input) ?? $input;
        $input = preg_replace('/[^\pL\pN\-_]+/u', '-', $input) ?? $input;
        $input = preg_replace('/-+/u', '-', $input) ?? $input;
        $input = trim($input, '-_');

        return $input !== '' ? $input : $prefix.'-'.Str::lower(Str::random(8));
    }

    /** @param class-string<Model> $modelClass */
    public static function unique(string $slug, string $modelClass, ?int $ignoreId = null): string
    {
        $base = $slug;
        $candidate = $base;
        $counter = 2;

        while ($modelClass::query()
            ->where('slug', $candidate)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $candidate = $base.'-'.$counter++;
        }

        return $candidate;
    }
}
