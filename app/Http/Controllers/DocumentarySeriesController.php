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
            'code' => ['required', 'string', 'size:3', 'regex:/^\d{3}$/', 'unique:documentary_series,code'],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => 'El código de la serie debe contener exactamente 3 dígitos.',
        ]);

        DocumentarySeries::create([
            'code' => $validated['code'],
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Serie documental creada con éxito']);
    }

    public function update(Request $request, $id)
    {
        $series = DocumentarySeries::findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'size:3',
                'regex:/^\d{3}$/',
                Rule::unique('documentary_series', 'code')->ignore($series->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
        ], [
            'code.regex' => 'El código de la serie debe contener exactamente 3 dígitos.',
        ]);

        $series->update([
            'code' => $validated['code'],
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
                'size:2',
                'regex:/^\d{2}$/',
                Rule::unique('documentary_subseries', 'code')
                    ->where(fn ($query) => $query->where('documentary_series_id', $series->id)->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => 'El código de la subserie debe contener exactamente 2 dígitos.',
        ]);

        DocumentarySubseries::create([
            'documentary_series_id' => $series->id,
            'code' => $validated['code'],
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Subserie documental creada con éxito']);
    }

    public function updateSubseries(Request $request, $id)
    {
        $subseries = DocumentarySubseries::findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'size:2',
                'regex:/^\d{2}$/',
                Rule::unique('documentary_subseries', 'code')
                    ->where(fn ($query) => $query->where('documentary_series_id', $subseries->documentary_series_id)->whereNull('deleted_at'))
                    ->ignore($subseries->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
        ], [
            'code.regex' => 'El código de la subserie debe contener exactamente 2 dígitos.',
        ]);

        $subseries->update([
            'code' => $validated['code'],
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
