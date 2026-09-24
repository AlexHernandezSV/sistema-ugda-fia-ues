<?php

namespace App\Support;

use Illuminate\Support\Str;

final class TextSearch
{
    private const POSTGRES_ACCENTED = 'áéíóúüñàèìòùäëïöýÿç';

    private const POSTGRES_PLAIN = 'aeiouunaeiouaeioyyc';

    public static function normalize(?string $value): string
    {
        $normalized = Str::ascii(str_replace(['–', '—', '−'], '-', trim((string) $value)));

        return mb_strtolower(preg_replace('/\s+/u', ' ', $normalized) ?? '');
    }

    public static function containsPattern(?string $value): string
    {
        return '%' . self::normalize($value) . '%';
    }

    public static function postgresTextExpression(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(TRANSLATE(LOWER(COALESCE(CAST({$column} AS TEXT), '')), '" . self::POSTGRES_ACCENTED . "', '" . self::POSTGRES_PLAIN . "'), '–', '-'), '—', '-'), '−', '-')";
    }

    public static function postgresDelimitedExpression(string $column): string
    {
        $expression = self::postgresTextExpression($column);

        return "REPLACE(REPLACE({$expression}, ' ', ''), ';', '|')";
    }
}
