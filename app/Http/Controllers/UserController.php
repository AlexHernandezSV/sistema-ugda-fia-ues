<?php

namespace App\Http\Controllers;

use App\Mail\UserCredentialsMail;
use App\Mail\TemporaryPasswordMail;
use App\Models\Profile;
use App\Models\Unit;
use App\Models\User;
use App\Services\UserActivityLogger;
use App\Support\XlsxRowsReader;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly UserActivityLogger $activityLogger)
    {
    }

    public function index()
    {
        $users = User::with([
            'units' => function ($query) {
                $query->whereNull('user_unit.deleted_at');
            },
            'person',
            'profiles' => function ($query) {
                $query->whereNull('user_profile.deleted_at')
                    ->where('profiles.is_active', true);
            },
        ])->orderBy('id', 'desc')->get();

        return response()->json(
            $users->map(fn (User $user) => $this->mapUserForResponse($user))
        );
    }

    public function downloadImportTemplate()
    {
        $path = resource_path('templates/Usuarios UGDA.xlsx');

        abort_unless(is_file($path), 404, 'La plantilla de usuarios no está disponible.');

        return response()->download(
            $path,
            'Usuarios UGDA.xlsx',
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
            $credentials = DB::transaction(fn (): array => $this->createUsersFromRecords($records));
            $credentialsSent = 0;

            foreach ($credentials as $credential) {
                try {
                    Mail::to($credential['email'])->send(new UserCredentialsMail([
                        'name' => $credential['name'],
                        'email' => $credential['email'],
                        'password' => $credential['password'],
                    ]));
                    $credentialsSent++;
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }

            return response()->json([
                'message' => sprintf(
                    'Carga completada correctamente. Se crearon %d usuarios.',
                    count($credentials)
                ),
                'created_count' => count($credentials),
                'credentials_sent' => $credentialsSent,
            ]);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo procesar el archivo de usuarios.',
            ], 422);
        }
    }

    public function store(Request $request)
    {
        $this->normalizeCarnetInput($request);
        $this->normalizeUserUnitInput($request);

        $request->validate([
            'first_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'first_last_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_last_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'carnet' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/', Rule::unique('person', 'carnet')],
            'email' => ['required', 'email', 'unique:users,email'],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['required', 'integer', 'distinct', 'exists:units,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'role' => $this->roleValidationRules(),
        ], [
            'first_name.regex' => 'El nombre solo puede contener letras.',
            'carnet.regex' => 'El carnet solo puede contener letras mayúsculas y números.',
            'carnet.unique' => 'El carnet ya esta registrado.',
            'unit_ids.required' => 'Seleccione al menos una unidad para el usuario.',
            'unit_ids.min' => 'Seleccione al menos una unidad para el usuario.',
            'unit_ids.*.exists' => 'Seleccione una unidad valida.',
            'role.required' => 'Seleccione un rol para el usuario.',
            'role.exists' => 'Seleccione un rol válido.',
            'max' => 'El campo no debe exceder los :max caracteres.',
        ], [
            'role.required' => 'Seleccione un rol para el usuario.',
            'role.exists' => 'Seleccione un rol válido.',
        ]);

        $randomPassword = Str::random(8);
        $carnetUpper = $request->carnet ? strtoupper($request->carnet) : null;
        $profileName = trim((string) $request->role);
        $unitIds = $this->normalizeUnitIds($request);

        $user = DB::transaction(function () use ($request, $randomPassword, $carnetUpper, $profileName, $unitIds) {
            $user = User::create([
                'email' => $request->email,
                'password' => Hash::make($randomPassword),
                'is_active' => true,
                'two_factor_method' => 'email',
                'must_change_password' => true,
                'temporary_password_expires_at' => Carbon::now()->addDays(7),
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
            $this->assignUnitsToUser($user, $unitIds);

            return $user->load(['units', 'person', 'profiles']);
        });

        try {
            $data = [
                'name' => trim(($request->first_name ?? '') . ' ' . ($request->first_last_name ?? '')),
                'email' => $user->email,
                'password' => $randomPassword,
            ];
            Mail::to($user->email)->send(new UserCredentialsMail($data));
        } catch (\Throwable $e) {
            //
        }

        return response()->json([
            'message' => 'Usuario creado y credenciales enviadas',
            'user' => $this->mapUserForResponse($user),
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $this->normalizeCarnetInput($request);
        $this->normalizeUserUnitInput($request);

        $request->validate([
            'first_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'first_last_name' => ['required', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'second_last_name' => ['nullable', 'string', 'max:30', 'regex:/^[\pL\s]+$/u'],
            'carnet' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/', Rule::unique('person', 'carnet')->ignore($user->id, 'user_id')],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'unit_ids' => ['required', 'array', 'min:1'],
            'unit_ids.*' => ['required', 'integer', 'distinct', 'exists:units,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'role' => $this->roleValidationRules(),
            'is_active' => ['required', 'boolean'],
        ], [
            'carnet.unique' => 'El carnet ya esta registrado.',
            'unit_ids.required' => 'Seleccione al menos una unidad para el usuario.',
            'unit_ids.min' => 'Seleccione al menos una unidad para el usuario.',
            'unit_ids.*.exists' => 'Seleccione una unidad valida.',
        ]);

        $carnetUpper = $request->carnet ? strtoupper($request->carnet) : null;
        $profileName = trim((string) $request->role);
        $unitIds = $this->normalizeUnitIds($request);

        DB::transaction(function () use ($request, $user, $carnetUpper, $profileName, $unitIds) {
            $user->update([
                'email' => $request->email,
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
            $this->assignUnitsToUser($user, $unitIds);
        });

        return response()->json(['message' => 'Usuario actualizado correctamente']);
    }

    public function resetPassword(Request $request, $id)
    {
        $request->validate([
            'admin_password' => ['required'],
        ], [
            'admin_password.required' => 'Ingrese su contrasena para confirmar el restablecimiento.',
        ]);

        $user = User::with('person')->findOrFail($id);

        if (!$user->is_active) {
            return response()->json(['message' => 'El usuario no se encuentra activo'], 422);
        }

        if (!Hash::check($request->admin_password, $request->user()->password)) {
            return response()->json(['message' => 'La contrasena del administrador no es correcta.'], 422);
        }

        $temporaryPassword = Str::password(12, true, true, false, false);
        $expiresAt = Carbon::now()->addDays(2);

        $user->update([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
            'temporary_password_expires_at' => $expiresAt,
            'password_changed_at' => null,
            'two_factor_method' => 'email',
        ]);

        $data = [
            'name' => $this->userDisplayName($user),
            'email' => $user->email,
            'password' => $temporaryPassword,
            'expires_at' => $expiresAt->format('d/m/Y H:i'),
        ];

        try {
            Mail::to($user->email)->send(new TemporaryPasswordMail($data));
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'La contrasena fue restablecida, pero no se pudo enviar el correo: ' . $e->getMessage(),
            ], 500);
        }

        $this->activityLogger->log(
            $user,
            $request->user(),
            'temporary_password_reset',
            'Contraseña temporal generada',
            'Un administrador restablecio la contrasena y envio una temporal por correo.',
            $request
        );

        return response()->json(['message' => 'Contraseña temporal enviada al correo del usuario.']);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);

        DB::transaction(function () use ($user) {
            DB::table('person')->where('user_id', $user->id)->delete();
            DB::table('user_profile')->where('user_id', $user->id)->delete();
            DB::table('user_unit')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        return response()->json(['message' => 'Usuario eliminado correctamente']);
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @return array<int, array{
     *     row:int,
     *     first_name:string,
     *     second_name:?string,
     *     first_last_name:string,
     *     second_last_name:?string,
     *     carnet:?string,
     *     carnet_key:?string,
     *     email:string,
     *     email_key:string,
     *     unit_name:string,
     *     unit_key:string,
     *     role_name:string,
     *     role_key:string
     * }>
     */
    private function parseImportRows(array $rows): array
    {
        if ($rows === []) {
            throw new \InvalidArgumentException('El archivo Excel está vacío.');
        }

        $headerAliases = [
            'first_name' => ['primernombre'],
            'second_name' => ['segundonombre'],
            'first_last_name' => ['primerapellido'],
            'carnet' => ['carnet'],
            'email' => ['correoelectronico', 'correoelectrnico'],
            'unit' => ['unidad'],
            'role' => ['roldeusuario'],
        ];
        $headerLabels = [
            'first_name' => 'Primer Nombre',
            'second_name' => 'Segundo Nombre',
            'first_last_name' => 'Primer Apellido',
            'carnet' => 'Carnet',
            'email' => 'Correo Electrónico',
            'unit' => 'Unidad',
            'role' => 'Rol de Usuario',
        ];
        $headers = array_map(
            fn ($header) => $this->normalizeImportHeader((string) $header),
            $rows[0]
        );
        $columnIndexes = [];

        foreach ($headerAliases as $field => $aliases) {
            $columnIndexes[$field] = false;

            foreach ($aliases as $alias) {
                $index = array_search($alias, $headers, true);

                if ($index !== false) {
                    $columnIndexes[$field] = $index;
                    break;
                }
            }

            if ($columnIndexes[$field] === false) {
                throw new \InvalidArgumentException(
                    'La plantilla debe contener las columnas: ' . implode(', ', array_values($headerLabels)) . '.'
                );
            }
        }

        $records = [];

        foreach (array_slice($rows, 1) as $index => $row) {
            $excelRow = $index + 2;
            $values = array_map(fn ($value) => trim((string) $value), $row);

            if (collect($values)->every(fn (string $value) => $value === '')) {
                continue;
            }

            $firstName = $this->nullableImportValue($values[$columnIndexes['first_name']] ?? null);
            $secondName = $this->nullableImportValue($values[$columnIndexes['second_name']] ?? null);
            $firstLastName = $this->nullableImportValue($values[$columnIndexes['first_last_name']] ?? null);
            $carnet = $this->nullableImportValue($values[$columnIndexes['carnet']] ?? null);
            $email = $this->nullableImportValue($values[$columnIndexes['email']] ?? null);
            $unit = $this->nullableImportValue($values[$columnIndexes['unit']] ?? null);
            $role = $this->nullableImportValue($values[$columnIndexes['role']] ?? null);

            if ($firstName === null) {
                throw new \InvalidArgumentException("La fila {$excelRow} no tiene Primer Nombre.");
            }

            if ($firstLastName === null) {
                throw new \InvalidArgumentException("La fila {$excelRow} no tiene Primer Apellido.");
            }

            if ($email === null) {
                throw new \InvalidArgumentException("La fila {$excelRow} no tiene Correo Electrónico.");
            }

            if ($unit === null) {
                throw new \InvalidArgumentException("La fila {$excelRow} no tiene Unidad.");
            }

            if ($role === null) {
                throw new \InvalidArgumentException("La fila {$excelRow} no tiene Rol de Usuario.");
            }

            foreach ([
                'Primer Nombre' => $firstName,
                'Segundo Nombre' => $secondName,
                'Primer Apellido' => $firstLastName,
            ] as $label => $value) {
                if ($value !== null && (mb_strlen($value) > 30 || preg_match('/^[\pL\s]+$/u', $value) !== 1)) {
                    throw new \InvalidArgumentException(
                        "El {$label} de la fila {$excelRow} solo puede contener letras, espacios y hasta 30 caracteres."
                    );
                }
            }

            if ($carnet !== null && (mb_strlen($carnet) > 20 || preg_match('/^[A-Z0-9]+$/', strtoupper($carnet)) !== 1)) {
                throw new \InvalidArgumentException(
                    "El Carnet de la fila {$excelRow} solo puede contener letras mayúsculas y números, hasta 20 caracteres."
                );
            }

            if (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException("El Correo Electrónico de la fila {$excelRow} no es válido.");
            }

            $records[] = [
                'row' => $excelRow,
                'first_name' => $firstName,
                'second_name' => $secondName,
                'first_last_name' => $firstLastName,
                'second_last_name' => null,
                'carnet' => $carnet === null ? null : strtoupper($carnet),
                'carnet_key' => $carnet === null ? null : $this->normalizeImportCarnet($carnet),
                'email' => $this->normalizeImportEmail($email),
                'email_key' => $this->normalizeImportEmail($email),
                'unit_name' => $unit,
                'unit_key' => $this->normalizeImportReference($unit),
                'role_name' => $role,
                'role_key' => $this->normalizeImportReference($role),
            ];
        }

        if ($records === []) {
            throw new \InvalidArgumentException('El archivo no contiene registros de usuarios.');
        }

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function validateImportRecords(array &$records): void
    {
        $existingEmailKeys = User::query()
            ->pluck('email')
            ->map(fn (string $email) => $this->normalizeImportEmail($email))
            ->filter()
            ->unique()
            ->flip()
            ->all();
        $existingCarnetKeys = DB::table('person')
            ->whereNotNull('carnet')
            ->pluck('carnet')
            ->map(fn (string $carnet) => $this->normalizeImportCarnet($carnet))
            ->filter()
            ->unique()
            ->flip()
            ->all();
        $unitsByKey = Unit::query()
            ->get(['id', 'name'])
            ->keyBy(fn (Unit $unit) => $this->normalizeImportReference($unit->name));
        $profilesByKey = Profile::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->keyBy(fn (Profile $profile) => $this->normalizeImportReference($profile->name));
        $seenEmails = [];
        $seenCarnets = [];

        foreach ($records as $index => &$record) {
            $row = $record['row'];

            if (isset($seenEmails[$record['email_key']])) {
                throw new \InvalidArgumentException(
                    "El correo '{$record['email']}' aparece más de una vez en el archivo (filas {$seenEmails[$record['email_key']]} y {$row})."
                );
            }

            if (isset($existingEmailKeys[$record['email_key']])) {
                throw new \InvalidArgumentException(
                    "El correo '{$record['email']}' de la fila {$row} ya existe en la base de datos."
                );
            }

            $seenEmails[$record['email_key']] = $row;

            if ($record['carnet_key'] !== null) {
                if (isset($seenCarnets[$record['carnet_key']])) {
                    throw new \InvalidArgumentException(
                        "El carnet '{$record['carnet']}' aparece más de una vez en el archivo (filas {$seenCarnets[$record['carnet_key']]} y {$row})."
                    );
                }

                if (isset($existingCarnetKeys[$record['carnet_key']])) {
                    throw new \InvalidArgumentException(
                        "El carnet '{$record['carnet']}' de la fila {$row} ya existe en la base de datos."
                    );
                }

                $seenCarnets[$record['carnet_key']] = $row;
            }

            if (!isset($unitsByKey[$record['unit_key']])) {
                throw new \InvalidArgumentException(
                    "La unidad '{$record['unit_name']}' de la fila {$row} no existe en el sistema."
                );
            }

            if (!isset($profilesByKey[$record['role_key']])) {
                throw new \InvalidArgumentException(
                    "El rol '{$record['role_name']}' de la fila {$row} no existe o está inactivo en el sistema."
                );
            }

            $record['unit_id'] = $unitsByKey[$record['unit_key']]->id;
            $record['profile_name'] = $profilesByKey[$record['role_key']]->name;
        }
        unset($record);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array{name:string, email:string, password:string}>
     */
    private function createUsersFromRecords(array $records): array
    {
        $credentials = [];

        foreach ($records as $record) {
            $temporaryPassword = Str::random(8);
            $user = User::create([
                'email' => $record['email'],
                'password' => Hash::make($temporaryPassword),
                'is_active' => true,
                'two_factor_method' => 'email',
                'must_change_password' => true,
                'temporary_password_expires_at' => Carbon::now()->addDays(7),
            ]);

            $user->person()->create([
                'first_name' => $record['first_name'],
                'second_name' => $record['second_name'],
                'first_last_name' => $record['first_last_name'],
                'second_last_name' => $record['second_last_name'],
                'carnet' => $record['carnet'],
            ]);

            $this->assignProfileToUser($user, $record['profile_name']);
            $this->assignUnitsToUser($user, [$record['unit_id']]);

            $credentials[] = [
                'name' => trim($record['first_name'] . ' ' . $record['first_last_name']),
                'email' => $record['email'],
                'password' => $temporaryPassword,
            ];
        }

        return $credentials;
    }

    private function nullableImportValue(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function normalizeImportHeader(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii(trim($value)))) ?? '';
    }

    private function normalizeImportReference(string $value): string
    {
        $normalized = Str::ascii(trim($value));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return mb_strtolower(trim($normalized));
    }

    private function normalizeImportEmail(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function normalizeImportCarnet(string $value): string
    {
        return strtoupper(trim($value));
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

    private function roleValidationRules(): array
    {
        return [
            'required',
            'string',
            'max:100',
            Rule::exists('profiles', 'name')
                ->where('is_active', true)
                ->whereNull('deleted_at'),
        ];
    }

    private function userDisplayName(User $user): string
    {
        $person = $user->person;

        return trim(($person?->first_name ?? '') . ' ' . ($person?->first_last_name ?? '')) ?: $user->email;
    }

    private function mapUserForResponse(User $user): array
    {
        $person = $user->person;
        $activeProfile = $user->profiles
            ->first(fn ($profile) => (bool) ($profile->pivot->is_active ?? false))
            ?? $user->profiles->first();
        $activeUnit = $user->units
            ->first(fn ($unit) => (bool) ($unit->pivot->is_active ?? false))
            ?? $user->units->first();

        return [
            'id' => $user->id,
            'first_name' => $person?->first_name,
            'second_name' => $person?->second_name,
            'first_last_name' => $person?->first_last_name,
            'second_last_name' => $person?->second_last_name,
            'carnet' => $person?->carnet,
            'email' => $user->email,
            'unit_id' => $activeUnit?->id,
            'unit_ids' => $user->units->pluck('id')->values()->all(),
            'role' => $activeProfile?->name,
            'is_active' => (bool) $user->is_active,
            'unit' => $activeUnit,
            'units' => $user->units->values(),
        ];
    }

    private function normalizeUnitIds(Request $request): array
    {
        $unitIds = $request->input('unit_ids', []);

        if ((!is_array($unitIds) || $unitIds === []) && $request->filled('unit_id')) {
            $unitIds = [$request->integer('unit_id')];
        }

        return collect($unitIds)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeUserUnitInput(Request $request): void
    {
        $request->merge([
            'unit_ids' => $this->normalizeUnitIds($request),
        ]);
    }

    private function normalizeCarnetInput(Request $request): void
    {
        if (!$request->has('carnet')) {
            return;
        }

        $carnet = trim((string) $request->input('carnet'));

        $request->merge([
            'carnet' => $carnet === '' ? null : strtoupper($carnet),
        ]);
    }

    private function assignUnitsToUser(User $user, array $unitIds): void
    {
        $now = now();

        DB::table('user_unit')
            ->where('user_id', $user->id)
            ->update([
                'is_active' => false,
                'updated_at' => $now,
                'deleted_at' => $now,
            ]);

        foreach ($unitIds as $index => $unitId) {
            DB::table('user_unit')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'unit_id' => $unitId,
                ],
                [
                    'is_active' => $index === 0,
                    'updated_at' => $now,
                    'created_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
