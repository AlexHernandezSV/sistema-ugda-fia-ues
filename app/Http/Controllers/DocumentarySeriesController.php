<?php

namespace App\Http\Controllers;

use App\Models\DocumentarySeries;
use App\Models\DocumentarySubseries;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentarySeriesController extends Controller
{
    public function index()
    {
        $series = DocumentarySeries::query()
            ->with(['subseries' => function ($query) {
                $query->orderBy('code');
            }])
            ->orderBy('code')
            ->get();

        return response()->json(
            $series->map(fn (DocumentarySeries $item) => $this->transformSeries($item))->values()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^\d+$/', 'unique:documentary_series,code'],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.required' => 'El codigo de la serie es obligatorio.',
            'code.max' => 'El codigo de la serie no puede exceder los 20 digitos.',
            'code.regex' => 'El codigo de la serie solo puede contener digitos.',
            'code.unique' => 'Ya existe una serie documental registrada con este codigo.',
            'name.required' => 'El nombre de la serie es obligatorio.',
            'name.max' => 'El nombre de la serie no puede exceder los 150 caracteres.',
        ]);

        DocumentarySeries::create([
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Serie documental creada con exito']);
    }

    public function update(Request $request, $id)
    {
        $series = DocumentarySeries::findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d+$/',
                Rule::unique('documentary_series', 'code')->ignore($series->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
        ], [
            'code.required' => 'El codigo de la serie es obligatorio.',
            'code.max' => 'El codigo de la serie no puede exceder los 20 digitos.',
            'code.regex' => 'El codigo de la serie solo puede contener digitos.',
            'code.unique' => 'Ya existe una serie documental registrada con este codigo.',
            'name.required' => 'El nombre de la serie es obligatorio.',
            'name.max' => 'El nombre de la serie no puede exceder los 150 caracteres.',
        ]);

        $series->update([
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'],
        ]);

        return response()->json(['message' => 'Serie documental actualizada']);
    }

    public function destroy($id)
    {
        $series = DocumentarySeries::with('subseries')->findOrFail($id);

        foreach ($series->subseries as $subseries) {
            $subseries->delete();
        }

        $series->delete();

        return response()->json(['message' => 'Serie documental eliminada']);
    }

    public function storeSubseries(Request $request, $seriesId)
    {
        $series = DocumentarySeries::findOrFail($seriesId);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d+$/',
                Rule::unique('documentary_subseries', 'code')
                    ->where(fn ($query) => $query->where('documentary_series_id', $series->id)->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.required' => 'El codigo de la subserie es obligatorio.',
            'code.max' => 'El codigo de la subserie no puede exceder los 20 digitos.',
            'code.regex' => 'El codigo de la subserie solo puede contener digitos.',
            'code.unique' => 'Ya existe una subserie registrada con este codigo dentro de la serie seleccionada.',
            'name.required' => 'El nombre de la subserie es obligatorio.',
            'name.max' => 'El nombre de la subserie no puede exceder los 150 caracteres.',
        ]);

        DocumentarySubseries::create([
            'documentary_series_id' => $series->id,
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Subserie documental creada con exito']);
    }

    public function updateSubseries(Request $request, $id)
    {
        $subseries = DocumentarySubseries::findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d+$/',
                Rule::unique('documentary_subseries', 'code')
                    ->where(fn ($query) => $query->where('documentary_series_id', $subseries->documentary_series_id)->whereNull('deleted_at'))
                    ->ignore($subseries->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
        ], [
            'code.required' => 'El codigo de la subserie es obligatorio.',
            'code.max' => 'El codigo de la subserie no puede exceder los 20 digitos.',
            'code.regex' => 'El codigo de la subserie solo puede contener digitos.',
            'code.unique' => 'Ya existe una subserie registrada con este codigo dentro de la serie seleccionada.',
            'name.required' => 'El nombre de la subserie es obligatorio.',
            'name.max' => 'El nombre de la subserie no puede exceder los 150 caracteres.',
        ]);

        $subseries->update([
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'],
        ]);

        return response()->json(['message' => 'Subserie documental actualizada']);
    }

    public function destroySubseries($id)
    {
        $subseries = DocumentarySubseries::findOrFail($id);
        $subseries->delete();

        return response()->json(['message' => 'Subserie documental eliminada']);
    }

    private function transformSeries(DocumentarySeries $series): array
    {
        return [
            'id' => $series->id,
            'code' => $series->code,
            'name' => $series->name,
            'is_active' => $series->is_active,
            'subseries_count' => $series->subseries->count(),
            'subseries' => $series->subseries
                ->map(fn (DocumentarySubseries $subseries) => [
                    'id' => $subseries->id,
                    'code' => $subseries->code,
                    'name' => $subseries->name,
                    'is_active' => $subseries->is_active,
                    'documentary_series_id' => $subseries->documentary_series_id,
                ])
                ->values()
                ->all(),
        ];
    }
}
