<?php

namespace App\Http\Controllers;

use App\Mail\UserCredentialsMail;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with([
            'unit',
            'person',
            'profiles' => function ($query) {
                $query->whereNull('user_profile.deleted_at')
                    ->where('profile.is_active', true);
            },
        ])->orderBy('id', 'desc')->get();

        return response()->json(
            $users->map(fn (User $user) => $this->mapUserForResponse($user))
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'first_last_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_last_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'carnet' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/'],
            'email' => ['required', 'email', 'unique:users,email'],
            'unit_id' => ['required', 'exists:units,id'],
            'role' => ['required', 'string', 'max:100'],
        ], [
            'first_name.regex' => 'El nombre solo puede contener letras.',
            'carnet.regex' => 'El carnet solo puede contener letras mayusculas y numeros.',
            'max' => 'El campo no debe exceder los :max caracteres.',
        ]);

        $randomPassword = Str::random(8);
        $carnetUpper = $request->carnet ? strtoupper($request->carnet) : null;
        $profileName = trim((string) $request->role);

        $user = DB::transaction(function () use ($request, $randomPassword, $carnetUpper, $profileName) {
            $user = User::create([
                'email' => $request->email,
                'password' => Hash::make($randomPassword),
                'unit_id' => $request->unit_id,
                'is_active' => true,
            ]);

            $user->person()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'first_name' => $request->first_name,
                    'second_name' => $request->second_name,
                    'first_last_name' => $request->first_last_name,
                    'second_last_name' => $request->second_last_name,
                    'carnet' => $carnetUpper,
                ]
            );

            $this->assignProfileToUser($user, $profileName);

            return $user->load(['unit', 'person', 'profiles']);
        });

        try {
            $data = [
                'name' => trim(($request->first_name ?? '') . ' ' . ($request->first_last_name ?? '')),
                'email' => $user->email,
                'password' => $randomPassword,
            ];
            Mail::to($user->email)->send(new UserCredentialsMail($data));
        } catch (\Throwable $e) {
            // Keep API success: user creation should not fail if email provider fails.
        }

        return response()->json([
            'message' => 'Usuario creado y credenciales enviadas',
            'user' => $this->mapUserForResponse($user),
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'first_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'first_last_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_last_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'carnet' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'unit_id' => ['required', 'exists:units,id'],
            'role' => ['required', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ]);

        $carnetUpper = $request->carnet ? strtoupper($request->carnet) : null;
        $profileName = trim((string) $request->role);

        DB::transaction(function () use ($request, $user, $carnetUpper, $profileName) {
            $user->update([
                'email' => $request->email,
                'unit_id' => $request->unit_id,
                'is_active' => $request->is_active,
            ]);

            $user->person()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'first_name' => $request->first_name,
                    'second_name' => $request->second_name,
                    'first_last_name' => $request->first_last_name,
                    'second_last_name' => $request->second_last_name,
                    'carnet' => $carnetUpper,
                ]
            );

            $this->assignProfileToUser($user, $profileName);
        });

        return response()->json(['message' => 'Usuario actualizado correctamente']);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);

        DB::transaction(function () use ($user) {
            DB::table('person')->where('user_id', $user->id)->delete();
            DB::table('user_profile')->where('user_id', $user->id)->delete();
            DB::table('user_role')->where('user_id', $user->id)->delete();
            DB::table('user_unit')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        return response()->json(['message' => 'Usuario eliminado correctamente']);
    }

    private function assignProfileToUser(User $user, string $profileName): void
    {
        $profile = Profile::withTrashed()->firstOrCreate(
            ['name' => $profileName],
            [
                'description' => null,
                'is_active' => true,
            ]
        );

        if ($profile->trashed()) {
            $profile->restore();
        }

        if (!$profile->is_active) {
            $profile->is_active = true;
            $profile->save();
        }

        $now = now();

        DB::table('user_profile')
            ->where('user_id', $user->id)
            ->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);

        DB::table('user_profile')->updateOrInsert(
            [
                'user_id' => $user->id,
                'profile_id' => $profile->id,
            ],
            [
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
                'deleted_at' => null,
            ]
        );
    }

    private function mapUserForResponse(User $user): array
    {
        $person = $user->person;
        $activeProfile = $user->profiles
            ->first(fn ($profile) => (bool) ($profile->pivot->is_active ?? false))
            ?? $user->profiles->first();

        return [
            'id' => $user->id,
            'first_name' => $person?->first_name,
            'second_name' => $person?->second_name,
            'first_last_name' => $person?->first_last_name,
            'second_last_name' => $person?->second_last_name,
            'carnet' => $person?->carnet,
            'email' => $user->email,
            'unit_id' => $user->unit_id,
            'role' => $activeProfile?->name,
            'is_active' => (bool) $user->is_active,
            'unit' => $user->unit,
        ];
    }
}
