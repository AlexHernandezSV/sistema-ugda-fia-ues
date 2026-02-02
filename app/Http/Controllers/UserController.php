<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\UserCredentialsMail;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::with('unit')->orderBy('id', 'desc')->get());
    }

    public function store(Request $request)
    {
        // --- 1. VALIDACIÓN ESTRICTA (BACKEND) ---
        $request->validate([
            // Nombres: Requeridos, Texto, Máximo 30, Regex (Solo letras y espacios)
            'first_name' => ['required', 'string', 'max:30', 'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'],
            'second_name' => ['nullable', 'string', 'max:30', 'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'],
            
            // Apellidos: Requeridos, Texto, Máximo 30, Regex (Solo letras y espacios)
            'first_last_name' => ['required', 'string', 'max:30', 'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'],
            'second_last_name' => ['nullable', 'string', 'max:30', 'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'],
            
            // Carnet: Nullable, Max 20, Regex (Solo Mayúsculas A-Z y Números 0-9)
            // Nota: El regex no permite espacios ni símbolos.
            'carnet' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/'],
            
            'email' => 'required|email|unique:users,email',
            'unit_id' => 'required|exists:units,id',
            'role' => 'required|string'
        ], [
            // Mensajes personalizados (Opcional, Laravel los da en inglés por defecto)
            'first_name.regex' => 'El nombre solo puede contener letras.',
            'carnet.regex' => 'El carnet solo puede contener letras mayúsculas y números.',
            'max' => 'El campo no debe exceder los :max caracteres.'
        ]);

        // --- 2. LOGICA DE NEGOCIO ---
        
        $randomPassword = Str::random(8);

        // Forzamos el carnet a mayúsculas por seguridad antes de crear
        $carnetUpper = $request->carnet ? strtoupper($request->carnet) : null;

        $user = User::create([
            'first_name' => $request->first_name,
            'second_name' => $request->second_name,
            'first_last_name' => $request->first_last_name,
            'second_last_name' => $request->second_last_name,
            'carnet' => $carnetUpper,
            'email' => $request->email,
            'role' => $request->role,
            'unit_id' => $request->unit_id,
            'password' => Hash::make($randomPassword),
            'is_active' => true
        ]);

        try {
            $data = [
                'name' => $user->first_name . ' ' . $user->first_last_name,
                'email' => $user->email,
                'password' => $randomPassword
            ];
            Mail::to($user->email)->send(new UserCredentialsMail($data));
        } catch (\Exception $e) {
            // Log error
        }

        return response()->json(['message' => 'Usuario creado y credenciales enviadas']);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // --- VALIDACIÓN ESTRICTA EN EDICIÓN ---
        $request->validate([
            'first_name' => ['required', 'string', 'max:30', 'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'],
            'second_name' => ['nullable', 'string', 'max:30', 'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'],
            'first_last_name' => ['required', 'string', 'max:30', 'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'],
            'second_last_name' => ['nullable', 'string', 'max:30', 'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'],
            
            // Validamos Carnet también al editar
            'carnet' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/'],
            
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'unit_id' => 'required|exists:units,id',
            'role' => 'required|string',
            'is_active' => 'required|boolean'
        ]);

        // Forzamos mayúsculas
        $carnetUpper = $request->carnet ? strtoupper($request->carnet) : null;

        // Actualizamos usando fill para poder manipular el carnet manualmente
        $user->fill($request->except(['carnet'])); // Llenamos todo menos carnet
        $user->carnet = $carnetUpper; // Asignamos carnet procesado
        $user->save();

        return response()->json(['message' => 'Usuario actualizado correctamente']);
    }
}