<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RequestNumberGenerator
{
    public static function transfer(CarbonInterface $requestDate): string
    {
        return self::build(
            modelClass: \App\Models\Transfer::class,
            column: 'code',
            prefix: 'TRF',
            requestDate: $requestDate,
        );
    }

    public static function loan(CarbonInterface $requestDate): string
    {
        return self::build(
            modelClass: \App\Models\Loan::class,
            column: 'number',
            prefix: 'PRT',
            requestDate: $requestDate,
        );
    }

    public static function box(CarbonInterface $createdAt): string
    {
        return self::build(
            modelClass: \App\Models\TransferBox::class,
            column: 'box_code',
            prefix: 'C',
            requestDate: $createdAt,
        );
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private static function build(string $modelClass, string $column, string $prefix, CarbonInterface $requestDate): string
    {
        $year = $requestDate->format('Y');
        $numberPrefix = "{$prefix}-{$year}-";

        return DB::transaction(function () use ($modelClass, $column, $prefix, $year, $numberPrefix): string {
            self::lockSequence($prefix, $year);

            $last = $modelClass::query()
                ->where($column, 'like', $numberPrefix . '%')
                ->pluck($column)
                ->map(function ($value) use ($numberPrefix): ?int {
                    $matches = [];

                    if (!preg_match('/^' . preg_quote($numberPrefix, '/') . '(\d{5})$/', (string) $value, $matches)) {
                        return null;
                    }

                    return (int) $matches[1];
                })
                ->filter(fn (?int $value): bool => $value !== null)
                ->max();

            $next = ($last ?? 0) + 1;

            if ($next > 99999) {
                throw new RuntimeException("Se agotó el correlativo {$prefix}-{$year}.");
            }

            return $numberPrefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        });
    }

    private static function lockSequence(string $prefix, string $year): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('SELECT pg_advisory_xact_lock(?)', [
            sprintf('%u', crc32("request-number:{$prefix}:{$year}")),
        ]);
    }
}
