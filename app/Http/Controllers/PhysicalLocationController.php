<?php

namespace App\Http\Controllers;

use App\Models\PhysicalLocationAisle;
use App\Models\PhysicalLocationOffice;
use App\Models\PhysicalLocationShelf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json($this->catalogPayload(false));
    }

    public function options(): JsonResponse
    {
        return response()->json($this->catalogPayload(true));
    }

    public function storeOffice(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'unique:physical_location_offices,code'],
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
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', Rule::unique('physical_location_offices', 'code')->ignore($office->id)],
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

        return response()->json([
            'message' => 'Oficina actualizada correctamente.',
            'office' => $this->mapOffice($office->fresh()),
        ]);
    }

    public function destroyOffice(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $office = PhysicalLocationOffice::query()->withCount('aisles')->findOrFail($id);

        if ($office->aisles_count > 0) {
            return response()->json([
                'message' => 'No se puede eliminar una oficina con pasillos registrados.',
            ], 422);
        }

        $office->delete();

        return response()->json(['message' => 'Oficina eliminada correctamente.']);
    }

    public function storeAisle(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);

        $validator = Validator::make($request->all(), [
            'office_id' => ['required', 'exists:physical_location_offices,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'max:20',
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
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'max:20',
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

        return response()->json([
            'message' => 'Pasillo actualizado correctamente.',
            'aisle' => $this->mapAisle($aisle->fresh('office')),
        ]);
    }

    public function destroyAisle(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $aisle = PhysicalLocationAisle::query()->withCount('shelves')->findOrFail($id);

        if ($aisle->shelves_count > 0) {
            return response()->json([
                'message' => 'No se puede eliminar un pasillo con estantes registrados.',
            ], 422);
        }

        $aisle->delete();

        return response()->json(['message' => 'Pasillo eliminado correctamente.']);
    }

    public function storeShelf(Request $request): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);

        $validator = Validator::make($request->all(), [
            'aisle_id' => ['required', 'exists:physical_location_aisles,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'max:20',
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
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'max:20',
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

        return response()->json([
            'message' => 'Estante actualizado correctamente.',
            'shelf' => $this->mapShelf($shelf->fresh('aisle.office')),
        ]);
    }

    public function destroyShelf(Request $request, int $id): JsonResponse
    {
        if (!$this->canManagePhysicalLocations($request)) {
            return $this->adminOnlyResponse();
        }

        PhysicalLocationShelf::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'Estante eliminado correctamente.']);
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

    private function mapOffice(PhysicalLocationOffice $office): array
    {
        return [
            'id' => $office->id,
            'name' => $office->name,
            'code' => $office->code,
            'is_active' => (bool) $office->is_active,
        ];
    }

    private function mapAisle(PhysicalLocationAisle $aisle): array
    {
        return [
            'id' => $aisle->id,
            'office_id' => $aisle->physical_location_office_id,
            'office_name' => $aisle->office?->name,
            'name' => $aisle->name,
            'code' => $aisle->code,
            'is_active' => (bool) $aisle->is_active,
        ];
    }

    private function mapShelf(PhysicalLocationShelf $shelf): array
    {
        return [
            'id' => $shelf->id,
            'aisle_id' => $shelf->physical_location_aisle_id,
            'aisle_name' => $shelf->aisle?->name,
            'office_id' => $shelf->aisle?->physical_location_office_id,
            'office_name' => $shelf->aisle?->office?->name,
            'name' => $shelf->name,
            'code' => $shelf->code,
            'is_active' => (bool) $shelf->is_active,
        ];
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function validationResponse($validator): JsonResponse
    {
        return response()->json([
            'message' => 'Los datos de la ubicacion fisica no son validos.',
            'errors' => $validator->errors(),
        ], 422);
    }

    private function adminOnlyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Solo el administrador UGDA puede gestionar ubicaciones fisicas.',
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
            'code.required' => 'El codigo es obligatorio.',
            'code.unique' => 'Ya existe un registro con este codigo en el mismo nivel.',
            'office_id.required' => 'Debe seleccionar la oficina.',
            'office_id.exists' => 'La oficina seleccionada no existe.',
            'aisle_id.required' => 'Debe seleccionar el pasillo.',
            'aisle_id.exists' => 'El pasillo seleccionado no existe.',
        ];
    }
}
