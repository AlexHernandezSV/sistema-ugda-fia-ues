<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index()
    {
        // Traemos las unidades con sus padres cargados
        return response()->json(Unit::with('parents')->orderBy('id', 'desc')->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'required|boolean',
            'parents' => 'nullable|array'
        ]);

        // 1. Crear Unidad
        $unit = Unit::create([
            'name' => $request->name,
            'is_active' => $request->is_active
        ]);

        // 2. Guardar dependencias
        if ($request->has('parents')) {
            $unit->parents()->sync($request->parents);
        }

        return response()->json(['message' => 'Unidad creada con éxito']);
    }

    public function update(Request $request, $id)
    {
        $unit = Unit::findOrFail($id);
        
        $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'required|boolean',
            'parents' => 'nullable|array'
        ]);

        // 1. Actualizar datos
        $unit->update([
            'name' => $request->name,
            'is_active' => $request->is_active
        ]);

        // 2. Sincronizar dependencias
        if ($request->has('parents')) {
            $unit->parents()->sync($request->parents);
        }

        return response()->json(['message' => 'Unidad actualizada']);
    }

    public function destroy($id)
    {
        $unit = Unit::findOrFail($id);
        $unit->delete();
        return response()->json(['message' => 'Unidad eliminada']);
    }
}