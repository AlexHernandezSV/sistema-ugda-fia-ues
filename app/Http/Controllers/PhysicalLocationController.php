<?php

namespace App\Http\Controllers;

use App\Models\PhysicalLocationAisle;
use App\Models\PhysicalLocationOffice;
use App\Models\PhysicalLocationShelf;
use App\Models\TransferBox;
use App\Services\UserActivityLogger;
use App\Support\XlsxRowsReader;
use App\Support\XlsxWorkbookWriter;
use App\Support\TextSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class PhysicalLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return response()->json([
                'message' => 'Solo el administrador UGDA puede gestionar ubicaciones fisicas.',
            ], 403);
        }

        return response()->json($this->cachedPaginatedCatalogPayload($request));
    }

    public function options(): JsonResponse
    {
        return response()->json($this->cachedCatalogPayload(true));
    }

    public function downloadImportTemplate(Request $request)
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $offices = PhysicalLocationOffice::query()
            ->with(['aisles.shelves'])
            ->get()
            ->sortBy(fn (PhysicalLocationOffice $office) => $this->catalogSortKey($office->code, $office->name))
            ->values();

        $officeRows = [];
        $aisleRows = [];
        $shelfRows = [];

        foreach ($offices as $office) {
            $officeRows[] = [$office->code, $office->name];

            $aisles = $office->aisles
                ->sortBy(fn (PhysicalLocationAisle $aisle) => $this->catalogSortKey($aisle->code, $aisle->name))
                ->values();

            foreach ($aisles as $aisle) {
                $aisleRows[] = [$office->code, $aisle->code, $aisle->name];

                $shelves = $aisle->shelves
                    ->sortBy(fn (PhysicalLocationShelf $shelf) => $this->catalogSortKey($shelf->code, $shelf->name))
                    ->values();

                foreach ($shelves as $shelf) {
                    $shelfRows[] = [$office->code, $aisle->code, $shelf->code, $shelf->name];
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'physical-locations-template-');

        if ($path === false) {
            abort(500, 'No se pudo preparar la plantilla de ubicaciones físicas.');
        }

        XlsxWorkbookWriter::write($path, [
            [
                'name' => 'Oficinas',
                'headers' => ['Código de Oficina', 'Nombre de Oficina'],
                'rows' => $officeRows,
            ],
            [
                'name' => 'Pasillos',
                'headers' => ['Código de Oficina', 'Código de Pasillo', 'Nombre de Pasillo'],
                'rows' => $aisleRows,
            ],
            [
                'name' => 'Estantes',
                'headers' => ['Código de Oficina', 'Código de Pasillo', 'Código de Estante', 'Nombre de Estante'],
                'rows' => $shelfRows,
            ],
        ]);

        return response()
            ->download(
                $path,
                'Ubicaciones.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            )
            ->deleteFileAfterSend(true);
    }

    public function import(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ], [
            'file.required' => 'Seleccione un archivo Excel.',
            'file.file' => 'El archivo seleccionado no es válido.',
            'file.mimes' => 'El archivo debe tener formato .xlsx.',
            'file.max' => 'El archivo no puede superar 5 MB.',
        ]);

        try {
            $workbook = XlsxRowsReader::readWorkbook($request->file('file')->getRealPath());
            $records = $this->parseImportWorkbook($workbook);
            $this->validateImportRecords($records);
            $result = DB::transaction(fn (): array => $this->syncImportRecords($records));

            return response()->json([
                'message' => sprintf(
                    'Carga completada correctamente. Se procesaron %d oficinas, %d pasillos y %d estantes.',
                    $result['offices_count'],
                    $result['aisles_count'],
                    $result['shelves_count']
                ),
                ...$result,
            ]);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo procesar el archivo de ubicaciones físicas.',
            ], 422);
        }
    }

    public function storeOffice(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:100', 'unique:physical_location_offices,code'],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $office = PhysicalLocationOffice::query()->create([
                'name' => trim((string) $request->input('name')),
                'code' => $this->normalizeCode((string) $request->input('code')),
                'is_active' => $request->boolean('is_active', true),
            ]);

            $this->refreshCatalogCacheVersion();

            return response()->json([
                'message' => 'Oficina registrada correctamente.',
                'office' => $this->mapOffice($office),
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'No se pudo registrar la oficina.'], 500);
        }
    }

    public function updateOffice(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $office = PhysicalLocationOffice::query()->findOrFail($id);
        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:100', Rule::unique('physical_location_offices', 'code')->ignore($office->id)],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $office->update([
            'name' => trim((string) $request->input('name')),
            'code' => $this->normalizeCode((string) $request->input('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Oficina actualizada correctamente.',
            'office' => $this->mapOffice($office->fresh()),
        ]);
    }

    public function destroyOffice(Request $request, int $id, UserActivityLogger $activityLogger): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $office = PhysicalLocationOffice::query()
            ->withCount('aisles')
            ->findOrFail($id);
        $blockMessage = $this->officeDeletionBlockMessage($office);

        if ($blockMessage !== null) {
            return response()->json([
                'message' => $blockMessage,
            ], 422);
        }

        $deletionResult = DB::transaction(function () use ($activityLogger, $request, $id) {
            $office = PhysicalLocationOffice::query()
                ->withCount('aisles')
                ->with('aisles.shelves')
                ->lockForUpdate()
                ->findOrFail($id);
            $blockMessage = $this->officeDeletionBlockMessage($office);

            if ($blockMessage !== null) {
                return ['blocked' => $blockMessage];
            }

            $log = $activityLogger->log(
                $request->user(),
                $request->user(),
                'physical_location_office_deleted',
                'Eliminacion de oficina',
                'Oficina: ' . $office->code . ' - ' . $office->name . '.',
                $request,
            );

            $office->delete();

            return ['log' => $log];
        });

        if (isset($deletionResult['blocked'])) {
            return response()->json([
                'message' => $deletionResult['blocked'],
            ], 422);
        }

        $activityLog = $deletionResult['log'];

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Oficina eliminada correctamente.',
            'event_id' => $activityLog->id,
        ]);
    }

    public function checkOfficeDeletion(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $office = PhysicalLocationOffice::query()->findOrFail($id);
        $blockMessage = $this->officeDeletionBlockMessage($office);

        if ($blockMessage !== null) {
            return response()->json([
                'message' => $blockMessage,
            ], 422);
        }

        return response()->json([
            'message' => 'La oficina puede eliminarse.',
        ]);
    }

    public function storeAisle(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);

        $validator = Validator::make($request->all(), [
            'office_id' => ['required', 'exists:physical_location_offices,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('physical_location_aisles', 'code')
                    ->where(fn ($query) => $query->where('physical_location_office_id', (int) $request->input('office_id'))),
            ],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $aisle = PhysicalLocationAisle::query()->create([
            'physical_location_office_id' => (int) $request->input('office_id'),
            'name' => trim((string) $request->input('name')),
            'code' => $this->normalizeCode((string) $request->input('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Pasillo registrado correctamente.',
            'aisle' => $this->mapAisle($aisle->load('office')),
        ], 201);
    }

    public function updateAisle(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $aisle = PhysicalLocationAisle::query()->findOrFail($id);
        $officeId = (int) $request->input('office_id', $aisle->physical_location_office_id);
        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);
        $validator = Validator::make($request->all(), [
            'office_id' => ['required', 'exists:physical_location_offices,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('physical_location_aisles', 'code')
                    ->ignore($aisle->id)
                    ->where(fn ($query) => $query->where('physical_location_office_id', $officeId)),
            ],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $aisle->update([
            'physical_location_office_id' => $officeId,
            'name' => trim((string) $request->input('name')),
            'code' => $this->normalizeCode((string) $request->input('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Pasillo actualizado correctamente.',
            'aisle' => $this->mapAisle($aisle->fresh('office')),
        ]);
    }

    public function destroyAisle(Request $request, int $id, UserActivityLogger $activityLogger): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $aisle = PhysicalLocationAisle::query()
            ->withCount('shelves')
            ->findOrFail($id);
        $blockMessage = $this->aisleDeletionBlockMessage($aisle);

        if ($blockMessage !== null) {
            return response()->json([
                'message' => $blockMessage,
            ], 422);
        }

        $deletionResult = DB::transaction(function () use ($activityLogger, $request, $id) {
            $aisle = PhysicalLocationAisle::query()
                ->withCount('shelves')
                ->with('shelves')
                ->lockForUpdate()
                ->findOrFail($id);
            $blockMessage = $this->aisleDeletionBlockMessage($aisle);

            if ($blockMessage !== null) {
                return ['blocked' => $blockMessage];
            }

            $log = $activityLogger->log(
                $request->user(),
                $request->user(),
                'physical_location_aisle_deleted',
                'Eliminacion de pasillo',
                'Pasillo: ' . $aisle->code . ' - ' . $aisle->name . '.',
                $request,
            );

            $aisle->delete();

            return ['log' => $log];
        });

        if (isset($deletionResult['blocked'])) {
            return response()->json([
                'message' => $deletionResult['blocked'],
            ], 422);
        }

        $activityLog = $deletionResult['log'];

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Pasillo eliminado correctamente.',
            'event_id' => $activityLog->id,
        ]);
    }

    public function checkAisleDeletion(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $aisle = PhysicalLocationAisle::query()->findOrFail($id);
        $blockMessage = $this->aisleDeletionBlockMessage($aisle);

        if ($blockMessage !== null) {
            return response()->json([
                'message' => $blockMessage,
            ], 422);
        }

        return response()->json([
            'message' => 'El pasillo puede eliminarse.',
        ]);
    }

    public function storeShelf(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);

        $validator = Validator::make($request->all(), [
            'aisle_id' => ['required', 'exists:physical_location_aisles,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('physical_location_shelves', 'code')
                    ->where(fn ($query) => $query->where('physical_location_aisle_id', (int) $request->input('aisle_id'))),
            ],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $shelf = PhysicalLocationShelf::query()->create([
            'physical_location_aisle_id' => (int) $request->input('aisle_id'),
            'name' => trim((string) $request->input('name')),
            'code' => $this->normalizeCode((string) $request->input('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Estante registrado correctamente.',
            'shelf' => $this->mapShelf($shelf->load('aisle.office')),
        ], 201);
    }

    public function updateShelf(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $shelf = PhysicalLocationShelf::query()->findOrFail($id);
        $aisleId = (int) $request->input('aisle_id', $shelf->physical_location_aisle_id);
        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);
        $validator = Validator::make($request->all(), [
            'aisle_id' => ['required', 'exists:physical_location_aisles,id'],
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('physical_location_shelves', 'code')
                    ->ignore($shelf->id)
                    ->where(fn ($query) => $query->where('physical_location_aisle_id', $aisleId)),
            ],
            'is_active' => ['boolean'],
        ], $this->messages());

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $shelf->update([
            'physical_location_aisle_id' => $aisleId,
            'name' => trim((string) $request->input('name')),
            'code' => $this->normalizeCode((string) $request->input('code')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Estante actualizado correctamente.',
            'shelf' => $this->mapShelf($shelf->fresh('aisle.office')),
        ]);
    }

    public function destroyShelf(Request $request, int $id, UserActivityLogger $activityLogger): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $shelf = PhysicalLocationShelf::query()
            ->with('aisle.office')
            ->findOrFail($id);
        $blockMessage = $this->shelfDeletionBlockMessage($shelf);

        if ($blockMessage !== null) {
            return response()->json([
                'message' => $blockMessage,
            ], 422);
        }

        $deletionResult = DB::transaction(function () use ($activityLogger, $request, $id) {
            $shelf = PhysicalLocationShelf::query()
                ->with('aisle.office')
                ->lockForUpdate()
                ->findOrFail($id);
            $blockMessage = $this->shelfDeletionBlockMessage($shelf);

            if ($blockMessage !== null) {
                return ['blocked' => $blockMessage];
            }

            $log = $activityLogger->log(
                $request->user(),
                $request->user(),
                'physical_location_shelf_deleted',
                'Eliminacion de estante',
                'Estante: ' . $shelf->code . ' - ' . $shelf->name . '.',
                $request,
            );

            $shelf->delete();

            return ['log' => $log];
        });

        if (isset($deletionResult['blocked'])) {
            return response()->json([
                'message' => $deletionResult['blocked'],
            ], 422);
        }

        $activityLog = $deletionResult['log'];

        $this->refreshCatalogCacheVersion();

        return response()->json([
            'message' => 'Estante eliminado correctamente.',
            'event_id' => $activityLog->id,
        ]);
    }

    public function checkShelfDeletion(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $shelf = PhysicalLocationShelf::query()
            ->with('aisle.office')
            ->findOrFail($id);
        $blockMessage = $this->shelfDeletionBlockMessage($shelf);

        if ($blockMessage !== null) {
            return response()->json([
                'message' => $blockMessage,
            ], 422);
        }

        return response()->json([
            'message' => 'El estante no tiene cajas asociadas.',
        ]);
    }

    /**
     * @param array<string, array<int, array<int, string>>> $workbook
     * @return array<string, array<int, array<string, string|int>>>
     */
    private function parseImportWorkbook(array $workbook): array
    {
        $requiredSheets = ['Oficinas', 'Pasillos', 'Estantes'];

        foreach ($requiredSheets as $sheetName) {
            if (!array_key_exists($sheetName, $workbook)) {
                throw new \InvalidArgumentException("El archivo debe contener la hoja '{$sheetName}'.");
            }
        }

        return [
            'offices' => $this->parseImportSheet(
                $workbook['Oficinas'],
                [
                    'code' => ['codigodeoficina', 'codigooficina'],
                    'name' => ['nombredeoficina', 'nombreoficina'],
                ],
                'Oficinas'
            ),
            'aisles' => $this->parseImportSheet(
                $workbook['Pasillos'],
                [
                    'office_code' => ['codigodeoficina', 'codigooficina'],
                    'code' => ['codigodepasillo', 'codigopasillo'],
                    'name' => ['nombredepasillo', 'nombrepasillo'],
                ],
                'Pasillos'
            ),
            'shelves' => $this->parseImportSheet(
                $workbook['Estantes'],
                [
                    'office_code' => ['codigodeoficina', 'codigooficina'],
                    'aisle_code' => ['codigodepasillo', 'codigopasillo'],
                    'code' => ['codigodeestante', 'codigoestante'],
                    'name' => ['nombredeestante', 'nombreestante'],
                ],
                'Estantes'
            ),
        ];
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @param array<string, array<int, string>> $columns
     * @return array<int, array<string, string|int>>
     */
    private function parseImportSheet(array $rows, array $columns, string $sheetName): array
    {
        if ($rows === []) {
            throw new \InvalidArgumentException("La hoja {$sheetName} está vacía.");
        }

        $headers = array_values(array_map(
            fn ($header) => $this->normalizeImportHeader((string) $header),
            $rows[0]
        ));
        $headerIndexes = [];

        foreach ($headers as $index => $header) {
            if ($header !== '' && !array_key_exists($header, $headerIndexes)) {
                $headerIndexes[$header] = $index;
            }
        }

        $columnIndexes = [];

        foreach ($columns as $field => $aliases) {
            $normalizedAliases = array_map(
                fn ($alias) => $this->normalizeImportHeader((string) $alias),
                $aliases
            );
            $matchedIndex = null;

            foreach ($normalizedAliases as $alias) {
                if (array_key_exists($alias, $headerIndexes)) {
                    $matchedIndex = $headerIndexes[$alias];
                    break;
                }
            }

            if ($matchedIndex === null) {
                $foundHeaders = implode(', ', array_filter($headers, fn (string $header) => $header !== ''));
                $expectedHeaders = implode(' / ', $normalizedAliases);

                throw new \InvalidArgumentException(
                    "La hoja {$sheetName} no contiene la columna requerida '{$expectedHeaders}'. Encabezados encontrados: {$foundHeaders}."
                );
            }

            $columnIndexes[$field] = $matchedIndex;
        }

        $records = [];

        foreach (array_slice($rows, 1) as $index => $row) {
            $excelRow = $index + 2;
            $values = array_values(array_map(fn ($value) => trim((string) $value), $row));

            if (collect($values)->every(fn (string $value) => $value === '')) {
                continue;
            }

            $record = ['row' => $excelRow];

            foreach (array_keys($columns) as $field) {
                $value = trim((string) ($values[$columnIndexes[$field]] ?? ''));

                if ($value === '') {
                    throw new \InvalidArgumentException(
                        "La fila {$excelRow} de la hoja {$sheetName} tiene campos obligatorios vacíos."
                    );
                }

                $record[$field] = $value;
            }

            $records[] = $record;
        }

        if ($records === []) {
            throw new \InvalidArgumentException("La hoja {$sheetName} no contiene registros.");
        }

        return $records;
    }

    /**
     * @param array<string, array<int, array<string, string|int>>> $records
     */
    private function validateImportRecords(array &$records): void
    {
        $officeCodes = [];

        foreach (PhysicalLocationOffice::query()->get(['code']) as $office) {
            $officeCodes[$this->normalizeCode((string) $office->code)] = true;
        }

        $incomingOfficeCodes = [];

        foreach ($records['offices'] as &$record) {
            $row = (int) $record['row'];
            $record['code'] = $this->normalizeCode((string) $record['code']);
            $record['name'] = trim((string) $record['name']);
            $this->validateImportValue($record['code'], "El código de oficina de la fila {$row}");
            $this->validateImportValue($record['name'], "El nombre de oficina de la fila {$row}");

            if (isset($incomingOfficeCodes[$record['code']])) {
                throw new \InvalidArgumentException(
                    "El código de oficina '{$record['code']}' está repetido en las filas {$incomingOfficeCodes[$record['code']]} y {$row}."
                );
            }

            $incomingOfficeCodes[$record['code']] = $row;
            $officeCodes[$record['code']] = true;
        }
        unset($record);

        $aisleKeys = [];

        foreach (PhysicalLocationAisle::query()->with('office:id,code')->get() as $aisle) {
            $aisleKeys[$this->locationAisleKey(
                (string) $aisle->office?->code,
                (string) $aisle->code
            )] = true;
        }

        $incomingAisleKeys = [];

        foreach ($records['aisles'] as &$record) {
            $row = (int) $record['row'];
            $record['office_code'] = $this->normalizeCode((string) $record['office_code']);
            $record['code'] = $this->normalizeCode((string) $record['code']);
            $record['name'] = trim((string) $record['name']);
            $this->validateImportValue($record['office_code'], "El código de oficina del pasillo en la fila {$row}");
            $this->validateImportValue($record['code'], "El código de pasillo de la fila {$row}");
            $this->validateImportValue($record['name'], "El nombre de pasillo de la fila {$row}");

            if (!isset($officeCodes[$record['office_code']])) {
                throw new \InvalidArgumentException(
                    "La oficina '{$record['office_code']}' del pasillo en la fila {$row} no existe en la base de datos ni en la hoja Oficinas."
                );
            }

            $key = $this->locationAisleKey($record['office_code'], $record['code']);

            if (isset($incomingAisleKeys[$key])) {
                throw new \InvalidArgumentException(
                    "El pasillo '{$record['code']}' de la oficina '{$record['office_code']}' está repetido en las filas {$incomingAisleKeys[$key]} y {$row}."
                );
            }

            $incomingAisleKeys[$key] = $row;
            $aisleKeys[$key] = true;
        }
        unset($record);

        $incomingShelfKeys = [];

        foreach ($records['shelves'] as &$record) {
            $row = (int) $record['row'];
            $record['office_code'] = $this->normalizeCode((string) $record['office_code']);
            $record['aisle_code'] = $this->normalizeCode((string) $record['aisle_code']);
            $record['code'] = $this->normalizeCode((string) $record['code']);
            $record['name'] = trim((string) $record['name']);
            $this->validateImportValue($record['office_code'], "El código de oficina del estante en la fila {$row}");
            $this->validateImportValue($record['aisle_code'], "El código de pasillo del estante en la fila {$row}");
            $this->validateImportValue($record['code'], "El código de estante de la fila {$row}");
            $this->validateImportValue($record['name'], "El nombre de estante de la fila {$row}");

            if (!isset($officeCodes[$record['office_code']])) {
                throw new \InvalidArgumentException(
                    "La oficina '{$record['office_code']}' del estante en la fila {$row} no existe en la base de datos ni en la hoja Oficinas."
                );
            }

            $aisleKey = $this->locationAisleKey($record['office_code'], $record['aisle_code']);

            if (!isset($aisleKeys[$aisleKey])) {
                throw new \InvalidArgumentException(
                    "El pasillo '{$record['aisle_code']}' de la oficina '{$record['office_code']}' del estante en la fila {$row} no existe en la base de datos ni en la hoja Pasillos."
                );
            }

            $key = $this->locationShelfKey(
                $record['office_code'],
                $record['aisle_code'],
                $record['code']
            );

            if (isset($incomingShelfKeys[$key])) {
                throw new \InvalidArgumentException(
                    "El estante '{$record['code']}' del pasillo '{$record['aisle_code']}' está repetido en las filas {$incomingShelfKeys[$key]} y {$row}."
                );
            }

            $incomingShelfKeys[$key] = $row;
        }
        unset($record);
    }

    private function validateImportValue(string $value, string $label): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException("{$label} es obligatorio.");
        }

        if (mb_strlen($value) > 100) {
            throw new \InvalidArgumentException("{$label} no puede superar 100 caracteres.");
        }
    }

    /**
     * @param array<string, array<int, array<string, string|int>>> $records
     * @return array<string, int>
     */
    private function syncImportRecords(array $records): array
    {
        $officeModels = PhysicalLocationOffice::query()->get()->keyBy(
            fn (PhysicalLocationOffice $office) => $this->normalizeCode((string) $office->code)
        );
        $officeIds = $officeModels->mapWithKeys(
            fn (PhysicalLocationOffice $office, string $code) => [$code => $office->id]
        )->all();
        $officesCreated = 0;
        $officesUpdated = 0;

        foreach ($records['offices'] as $record) {
            $code = (string) $record['code'];
            $office = $officeModels->get($code);

            if ($office) {
                $office->update(['name' => $record['name']]);
                $officesUpdated++;
            } else {
                $office = PhysicalLocationOffice::query()->create([
                    'code' => $code,
                    'name' => $record['name'],
                    'is_active' => true,
                ]);
                $officeModels->put($code, $office);
                $officesCreated++;
            }

            $officeIds[$code] = $office->id;
        }

        $aisleModels = PhysicalLocationAisle::query()
            ->with('office:id,code')
            ->get()
            ->keyBy(fn (PhysicalLocationAisle $aisle) => $this->locationAisleKey(
                (string) $aisle->office?->code,
                (string) $aisle->code
            ));
        $aisleIds = $aisleModels->mapWithKeys(
            fn (PhysicalLocationAisle $aisle, string $key) => [$key => $aisle->id]
        )->all();
        $aislesCreated = 0;
        $aislesUpdated = 0;

        foreach ($records['aisles'] as $record) {
            $key = $this->locationAisleKey($record['office_code'], $record['code']);
            $aisle = $aisleModels->get($key);

            if ($aisle) {
                $aisle->update(['name' => $record['name']]);
                $aislesUpdated++;
            } else {
                $aisle = PhysicalLocationAisle::query()->create([
                    'physical_location_office_id' => $officeIds[$record['office_code']],
                    'code' => $record['code'],
                    'name' => $record['name'],
                    'is_active' => true,
                ]);
                $aisleModels->put($key, $aisle);
                $aislesCreated++;
            }

            $aisleIds[$key] = $aisle->id;
        }

        $shelfModels = PhysicalLocationShelf::query()
            ->with('aisle.office')
            ->get()
            ->keyBy(fn (PhysicalLocationShelf $shelf) => $this->locationShelfKey(
                (string) $shelf->aisle?->office?->code,
                (string) $shelf->aisle?->code,
                (string) $shelf->code
            ));
        $shelvesCreated = 0;
        $shelvesUpdated = 0;

        foreach ($records['shelves'] as $record) {
            $key = $this->locationShelfKey(
                $record['office_code'],
                $record['aisle_code'],
                $record['code']
            );
            $shelf = $shelfModels->get($key);

            if ($shelf) {
                $shelf->update(['name' => $record['name']]);
                $shelvesUpdated++;
            } else {
                $shelf = PhysicalLocationShelf::query()->create([
                    'physical_location_aisle_id' => $aisleIds[
                        $this->locationAisleKey($record['office_code'], $record['aisle_code'])
                    ],
                    'code' => $record['code'],
                    'name' => $record['name'],
                    'is_active' => true,
                ]);
                $shelfModels->put($key, $shelf);
                $shelvesCreated++;
            }
        }

        $this->refreshCatalogCacheVersion();

        return [
            'offices_count' => count($records['offices']),
            'aisles_count' => count($records['aisles']),
            'shelves_count' => count($records['shelves']),
            'offices_created' => $officesCreated,
            'offices_updated' => $officesUpdated,
            'aisles_created' => $aislesCreated,
            'aisles_updated' => $aislesUpdated,
            'shelves_created' => $shelvesCreated,
            'shelves_updated' => $shelvesUpdated,
        ];
    }

    private function normalizeImportHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? $value;

        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii(trim($value)))) ?? '';
    }

    private function catalogSortKey(string $code, string $name): string
    {
        $normalizedCode = strtoupper(trim($code));
        $normalizedCode = preg_replace_callback(
            '/\d+/',
            fn (array $matches) => str_pad($matches[0], 12, '0', STR_PAD_LEFT),
            $normalizedCode
        ) ?? $normalizedCode;

        return $normalizedCode . '|' . mb_strtolower(trim($name));
    }

    private function locationAisleKey(string $officeCode, string $aisleCode): string
    {
        return $this->normalizeCode($officeCode) . '|' . $this->normalizeCode($aisleCode);
    }

    private function locationShelfKey(string $officeCode, string $aisleCode, string $shelfCode): string
    {
        return $this->locationAisleKey($officeCode, $aisleCode)
            . '|' . $this->normalizeCode($shelfCode);
    }

    private function cachedCatalogPayload(bool $onlyActive): array
    {
        $version = $this->catalogCacheVersion();
        $key = "physical_locations:catalog:v{$version}:active:" . (int) $onlyActive;

        return Cache::remember($key, now()->addMinutes(5), fn () => $this->catalogPayload($onlyActive));
    }

    private function cachedPaginatedCatalogPayload(Request $request): array
    {
        $version = $this->catalogCacheVersion();
        $params = [
            'office_page' => $this->pageValue($request, 'office'),
            'office_per_page' => $this->perPageValue($request, 'office'),
            'office_search' => $this->searchValue($request, 'office'),
            'aisle_page' => $this->pageValue($request, 'aisle'),
            'aisle_per_page' => $this->perPageValue($request, 'aisle'),
            'aisle_search' => $this->searchValue($request, 'aisle'),
            'shelf_page' => $this->pageValue($request, 'shelf'),
            'shelf_per_page' => $this->perPageValue($request, 'shelf'),
            'shelf_search' => $this->searchValue($request, 'shelf'),
        ];
        $key = 'physical_locations:paginated:v' . $version . ':' . md5(json_encode($params));

        return Cache::remember($key, now()->addMinutes(5), fn () => $this->paginatedCatalogPayload($request));
    }

    private function catalogCacheVersion(): int
    {
        return (int) Cache::get('physical_locations:version', 1);
    }

    private function refreshCatalogCacheVersion(): void
    {
        Cache::add('physical_locations:version', 1);
        Cache::increment('physical_locations:version');
    }

    private function catalogPayload(bool $onlyActive): array
    {
        $offices = PhysicalLocationOffice::query()
            ->when($onlyActive, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();

        $aisles = PhysicalLocationAisle::query()
            ->with('office')
            ->when($onlyActive, fn ($query) => $query->where('is_active', true)->whereHas('office', fn ($office) => $office->where('is_active', true)))
            ->orderBy('name')
            ->get();

        $shelves = PhysicalLocationShelf::query()
            ->with('aisle.office')
            ->when($onlyActive, fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('aisle', fn ($aisle) => $aisle->where('is_active', true)->whereHas('office', fn ($office) => $office->where('is_active', true))))
            ->orderBy('name')
            ->get();

        return [
            'offices' => $offices->map(fn (PhysicalLocationOffice $office) => $this->mapOffice($office))->values(),
            'aisles' => $aisles->map(fn (PhysicalLocationAisle $aisle) => $this->mapAisle($aisle))->values(),
            'shelves' => $shelves->map(fn (PhysicalLocationShelf $shelf) => $this->mapShelf($shelf))->values(),
        ];
    }

    private function paginatedCatalogPayload(Request $request): array
    {
        $offices = PhysicalLocationOffice::query()
            ->withCount('aisles')
            ->when(
                $this->searchValue($request, 'office'),
                fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search) {
                    $this->whereTextMatches($query, ['name', 'code'], $search);
                })
            )
            ->orderBy('name')
            ->paginate(
                $this->perPageValue($request, 'office'),
                ['*'],
                'office_page',
                $this->pageValue($request, 'office')
            );

        $aisles = PhysicalLocationAisle::query()
            ->with('office')
            ->withCount('shelves')
            ->when(
                $this->searchValue($request, 'aisle'),
                fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search) {
                    $this->whereTextMatches($query, ['name', 'code'], $search);
                    $query->orWhereHas('office', fn (Builder $office) => $this->whereTextMatches($office, ['name'], $search));
                })
            )
            ->orderBy('name')
            ->paginate(
                $this->perPageValue($request, 'aisle'),
                ['*'],
                'aisle_page',
                $this->pageValue($request, 'aisle')
            );

        $shelves = PhysicalLocationShelf::query()
            ->with('aisle.office')
            ->when(
                $this->searchValue($request, 'shelf'),
                fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search) {
                    $this->whereTextMatches($query, ['name', 'code'], $search);
                    $query->orWhereHas('aisle', fn (Builder $aisle) => $aisle
                        ->where(fn (Builder $aisle) => $this->whereTextMatches($aisle, ['name'], $search))
                        ->orWhereHas('office', fn (Builder $office) => $this->whereTextMatches($office, ['name'], $search)));
                })
            )
            ->orderBy('name')
            ->paginate(
                $this->perPageValue($request, 'shelf'),
                ['*'],
                'shelf_page',
                $this->pageValue($request, 'shelf')
            );

        return [
            'offices' => $this->paginatedResponse($offices, fn (PhysicalLocationOffice $office) => $this->mapOffice($office)),
            'aisles' => $this->paginatedResponse($aisles, fn (PhysicalLocationAisle $aisle) => $this->mapAisle($aisle)),
            'shelves' => $this->paginatedResponse($shelves, fn (PhysicalLocationShelf $shelf) => $this->mapShelf($shelf)),
        ];
    }

    private function paginatedResponse(LengthAwarePaginator $paginator, callable $mapper): array
    {
        return [
            'data' => $paginator->getCollection()->map($mapper)->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function searchValue(Request $request, string $type): ?string
    {
        $search = trim((string) $request->query("{$type}_search", ''));

        return $search === '' ? null : $search;
    }

    private function pageValue(Request $request, string $type): int
    {
        return max(1, (int) $request->query("{$type}_page", 1));
    }

    private function perPageValue(Request $request, string $type): int
    {
        $perPage = (int) $request->query("{$type}_per_page", 10);

        return min(max($perPage, 5), 50);
    }

    private function whereTextMatches(Builder $query, array $columns, string $search): void
    {
        $pattern = TextSearch::containsPattern($search);

        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
            $query->{$method}(TextSearch::postgresTextExpression($column) . ' LIKE ?', [$pattern]);
        }
    }

    private function mapOffice(PhysicalLocationOffice $office): array
    {
        $mapped = [
            'id' => $office->id,
            'name' => $office->name,
            'code' => $office->code,
            'is_active' => (bool) $office->is_active,
        ];

        if (array_key_exists('aisles_count', $office->getAttributes())) {
            $mapped['aisles_count'] = (int) $office->aisles_count;
        }

        return $mapped;
    }

    private function mapAisle(PhysicalLocationAisle $aisle): array
    {
        $mapped = [
            'id' => $aisle->id,
            'office_id' => $aisle->physical_location_office_id,
            'office_name' => $aisle->office?->name,
            'name' => $aisle->name,
            'code' => $aisle->code,
            'is_active' => (bool) $aisle->is_active,
        ];

        if (array_key_exists('shelves_count', $aisle->getAttributes())) {
            $mapped['shelves_count'] = (int) $aisle->shelves_count;
        }

        return $mapped;
    }

    private function mapShelf(PhysicalLocationShelf $shelf): array
    {
        $mapped = [
            'id' => $shelf->id,
            'aisle_id' => $shelf->physical_location_aisle_id,
            'aisle_name' => $shelf->aisle?->name,
            'office_id' => $shelf->aisle?->physical_location_office_id,
            'office_name' => $shelf->aisle?->office?->name,
            'name' => $shelf->name,
            'code' => $shelf->code,
            'is_active' => (bool) $shelf->is_active,
        ];

        return $mapped;
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function officeDeletionBlockMessage(PhysicalLocationOffice $office): ?string
    {
        if ((int) ($office->aisles_count ?? $office->aisles()->count()) > 0) {
            return 'No se puede eliminar la oficina porque tiene pasillos registrados. Elimine primero los pasillos.';
        }

        $office->loadMissing('aisles.shelves');

        if ($this->boxesAreAssignedToShelves($office->aisles->flatMap->shelves)) {
            return 'No se puede eliminar la oficina porque tiene cajas asociadas a sus estantes. Retire primero las cajas de esas ubicaciones.';
        }

        return null;
    }

    private function aisleDeletionBlockMessage(PhysicalLocationAisle $aisle): ?string
    {
        if ((int) ($aisle->shelves_count ?? $aisle->shelves()->count()) > 0) {
            return 'No se puede eliminar el pasillo porque tiene estantes registrados. Elimine primero los estantes.';
        }

        $aisle->loadMissing('office');

        if ($this->boxesAreAssignedToShelves([$aisle->shelves()->first()])) {
            return 'No se puede eliminar el pasillo porque tiene cajas asociadas a sus estantes. Retire primero las cajas de esas ubicaciones.';
        }

        return null;
    }

    private function shelfDeletionBlockMessage(PhysicalLocationShelf $shelf): ?string
    {
        if ($this->boxesAreAssignedToShelves([$shelf])) {
            return 'No se puede eliminar el estante porque tiene cajas asociadas. Retire primero las cajas de esta ubicación.';
        }

        return null;
    }

    /**
     * @param iterable<int, PhysicalLocationShelf|null> $shelves
     */
    private function boxesAreAssignedToShelves(iterable $shelves): bool
    {
        $locationCodes = collect($shelves)
            ->filter()
            ->map(function (PhysicalLocationShelf $shelf): string {
                $shelf->loadMissing('aisle.office');

                return $this->locationCodeForShelf($shelf);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($locationCodes === []) {
            return false;
        }

        return TransferBox::query()
            ->whereRaw('UPPER(TRIM(location_code)) IN (' . implode(', ', array_fill(0, count($locationCodes), '?')) . ')', $locationCodes)
            ->exists();
    }

    private function locationCodeForShelf(PhysicalLocationShelf $shelf): string
    {
        $shelf->loadMissing('aisle.office');

        return collect([
            $shelf->aisle?->office?->code,
            $shelf->aisle?->code,
            $shelf->code,
        ])
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->implode('-');
    }

    private function validationResponse($validator): JsonResponse
    {
        return response()->json([
            'message' => 'Los datos de la ubicación física no son válidos.',
            'errors' => $validator->errors(),
        ], 422);
    }

    private function adminOnlyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Solo el administrador UGDA puede gestionar ubicaciones físicas.',
        ], 403);
    }

    private function canManagePhysicalLocations(Request $request): bool
    {
        return $request->user()?->activeProfile()?->name === 'Administrador';
    }

    private function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
            'code.max' => 'El código no puede superar los 100 caracteres.',
            'code.required' => 'El código es obligatorio.',
            'code.unique' => 'Ya existe un registro con este código en el mismo nivel.',
            'office_id.required' => 'Debe seleccionar la oficina.',
            'office_id.exists' => 'La oficina seleccionada no existe.',
            'aisle_id.required' => 'Debe seleccionar el pasillo.',
            'aisle_id.exists' => 'El pasillo seleccionado no existe.',
        ];
    }
}
