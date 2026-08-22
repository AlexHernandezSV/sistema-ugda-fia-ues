<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Support\XlsxRowsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class UnitController extends Controller
{
    public function index()
    {
        return response()->json(Unit::with('parents')->orderBy('id', 'desc')->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            // CAMBIO: max:100
            'name' => 'required|string|max:100|unique:units,name', 
            'is_active' => 'required|boolean',
            'parents' => 'nullable|array'
        ]);

        $unit = Unit::create([
            'name' => $request->name,
            'is_active' => $request->is_active
        ]);

        if ($request->has('parents')) {
            $unit->parents()->sync($request->parents);
        }

        return response()->json(['message' => 'Unidad creada con éxito']);
    }

    public function downloadImportTemplate()
    {
        $path = resource_path('templates/Unidades productoras.xlsx');

        abort_unless(is_file($path), 404, 'La plantilla de unidades no está disponible.');

        return response()->download(
            $path,
            'Unidades productoras.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ], [
            'file.required' => 'Seleccione un archivo Excel.',
            'file.file' => 'El archivo seleccionado no es válido.',
            'file.mimes' => 'El archivo debe tener formato .xlsx.',
            'file.max' => 'El archivo no puede superar los 5 MB.',
        ]);

        try {
            $rows = XlsxRowsReader::read($request->file('file')->getRealPath());
            $records = $this->parseImportRows($rows);
            $this->validateImportRecords($records);
            $createdUnits = DB::transaction(fn (): array => $this->createUnitsFromRecords($records));

            return response()->json([
                'message' => sprintf(
                    'Carga completada correctamente. Se crearon %d unidades productoras.',
                    count($createdUnits)
                ),
                'created_count' => count($createdUnits),
                'units' => collect($createdUnits)
                    ->map(function (Unit $unit): array {
                        $unit->load('parents');

                        return [
                            'id' => $unit->id,
                            'name' => $unit->name,
                            'parents' => $unit->parents->pluck('name')->values(),
                        ];
                    })
                    ->values(),
            ]);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo procesar el archivo de unidades productoras.',
            ], 422);
        }
    }

    public function update(Request $request, $id)
    {
        $unit = Unit::findOrFail($id);
        
        $request->validate([
            'name' => [
                'required', 
                'string', 
                // CAMBIO: max:100
                'max:100', 
                Rule::unique('units', 'name')->ignore($unit->id)
            ],
            'is_active' => 'required|boolean',
            'parents' => 'nullable|array'
        ]);

        $unit->update([
            'name' => $request->name,
            'is_active' => $request->is_active
        ]);

        if ($request->has('parents')) {
            $unit->parents()->sync($request->parents);
        }

        return response()->json(['message' => 'Unidad actualizada']);
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @return array<int, array{row:int, name:string, key:string, parent_keys:array<int, string>}>
     */
    private function parseImportRows(array $rows): array
    {
        if ($rows === []) {
            throw new \InvalidArgumentException('El archivo Excel está vacío.');
        }

        $headers = array_map(
            fn ($header) => $this->normalizeImportHeader((string) $header),
            $rows[0]
        );
        $requiredHeaders = [
            'nombredelaunidadescueladepartamento',
            'dependencia1',
            'dependencia2',
            'dependencia3',
            'dependencia4',
        ];

        foreach ($requiredHeaders as $header) {
            if (!in_array($header, $headers, true)) {
                throw new \InvalidArgumentException(
                    'La plantilla debe contener las columnas de nombre y Dependencia 1 a Dependencia 4.'
                );
            }
        }

        $nameIndex = array_search($requiredHeaders[0], $headers, true);
        $dependencyIndexes = array_map(
            fn (string $header) => array_search($header, $headers, true),
            array_slice($requiredHeaders, 1)
        );
        $records = [];

        foreach (array_slice($rows, 1) as $index => $row) {
            $excelRow = $index + 2;
            $values = array_map(fn ($value) => trim((string) $value), $row);

            if (collect($values)->every(fn (string $value) => $value === '')) {
                continue;
            }

            $name = trim((string) ($values[$nameIndex] ?? ''));

            if ($name === '') {
                throw new \InvalidArgumentException("La fila {$excelRow} no tiene nombre de unidad.");
            }

            if (mb_strlen($name) > 100) {
                throw new \InvalidArgumentException("El nombre de la fila {$excelRow} no puede superar 100 caracteres.");
            }

            $key = $this->normalizeImportName($name);
            $parentKeys = [];

            foreach ($dependencyIndexes as $dependencyIndex) {
                $dependency = trim((string) ($values[$dependencyIndex] ?? ''));

                if ($dependency === '') {
                    continue;
                }

                $parentKey = $this->normalizeImportName($dependency);

                if ($parentKey === $key) {
                    throw new \InvalidArgumentException("La fila {$excelRow} no puede depender de sí misma.");
                }

                if (!in_array($parentKey, $parentKeys, true)) {
                    $parentKeys[] = $parentKey;
                }
            }

            if (isset($records[$key])) {
                throw new \InvalidArgumentException(
                    "La unidad '{$name}' aparece más de una vez en el archivo (filas {$records[$key]['row']} y {$excelRow})."
                );
            }

            $records[$key] = [
                'row' => $excelRow,
                'name' => $name,
                'key' => $key,
                'parent_keys' => $parentKeys,
            ];
        }

        if ($records === []) {
            throw new \InvalidArgumentException('El archivo no contiene registros de unidades productoras.');
        }

        return array_values($records);
    }

    /**
     * @param array<int, array{row:int, name:string, key:string, parent_keys:array<int, string>}> $records
     */
    private function validateImportRecords(array $records): void
    {
        $existingUnits = Unit::withTrashed()->get(['id', 'name', 'deleted_at']);
        $existingByKey = $existingUnits
            ->keyBy(fn (Unit $unit) => $this->normalizeImportName($unit->name));
        $recordsByKey = collect($records)->keyBy('key')->all();

        foreach ($records as $record) {
            if (isset($existingByKey[$record['key']])) {
                throw new \InvalidArgumentException("La unidad '{$record['name']}' ya existe en la base de datos.");
            }

            foreach ($record['parent_keys'] as $parentKey) {
                if (isset($recordsByKey[$parentKey])) {
                    continue;
                }

                if (!isset($existingByKey[$parentKey]) || $existingByKey[$parentKey]->trashed()) {
                    throw new \InvalidArgumentException(
                        "La unidad '{$record['name']}' tiene una dependencia jerárquica que no existe en la base de datos ni en el archivo."
                    );
                }
            }
        }

        $visiting = [];
        $visited = [];
        $visit = function (string $key) use (&$visit, &$visiting, &$visited, $recordsByKey): void {
            if (isset($visited[$key])) {
                return;
            }

            if (isset($visiting[$key])) {
                throw new \InvalidArgumentException('El archivo contiene dependencias jerárquicas circulares.');
            }

            $visiting[$key] = true;

            foreach ($recordsByKey[$key]['parent_keys'] as $parentKey) {
                if (isset($recordsByKey[$parentKey])) {
                    $visit($parentKey);
                }
            }

            unset($visiting[$key]);
            $visited[$key] = true;
        };

        foreach (array_keys($recordsByKey) as $key) {
            $visit($key);
        }
    }

    /**
     * @param array<int, array{row:int, name:string, key:string, parent_keys:array<int, string>}> $records
     * @return array<int, Unit>
     */
    private function createUnitsFromRecords(array $records): array
    {
        $existingUnits = Unit::withTrashed()->get(['id', 'name', 'deleted_at']);
        $existingByKey = $existingUnits->keyBy(fn (Unit $unit) => $this->normalizeImportName($unit->name));
        $recordsByKey = collect($records)->keyBy('key')->all();
        $resolvedUnits = [];
        $visiting = [];
        $createdUnits = [];

        $createFromRecord = function (string $key) use (
            &$createFromRecord,
            &$createdUnits,
            &$existingByKey,
            &$recordsByKey,
            &$resolvedUnits,
            &$visiting
        ): Unit {
            if (isset($resolvedUnits[$key])) {
                return $resolvedUnits[$key];
            }

            if (isset($visiting[$key])) {
                throw new \RuntimeException('El archivo contiene dependencias jerárquicas circulares.');
            }

            if (!isset($recordsByKey[$key])) {
                return $existingByKey[$key];
            }

            $visiting[$key] = true;
            $record = $recordsByKey[$key];
            $parentIds = [];

            foreach ($record['parent_keys'] as $parentKey) {
                $parentIds[] = $createFromRecord($parentKey)->id;
            }

            $unit = Unit::create([
                'name' => $record['name'],
                'is_active' => true,
            ]);

            $unit->parents()->sync($parentIds);
            $resolvedUnits[$key] = $unit;
            $createdUnits[] = $unit;
            unset($visiting[$key]);

            return $unit;
        };

        foreach ($records as $record) {
            $createFromRecord($record['key']);
        }

        return $createdUnits;
    }

    private function normalizeImportHeader(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii(trim($value)))) ?? '';
    }

    private function normalizeImportName(string $value): string
    {
        $normalized = Str::ascii(trim($value));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return mb_strtolower(trim($normalized));
    }

    public function destroy($id)
    {
        $unit = Unit::findOrFail($id);
        $unit->delete();
        return response()->json(['message' => 'Unidad eliminada']);
    }
}
