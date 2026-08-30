<?php

namespace Database\Seeders\Concerns;

use App\Models\Person;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait SeedsUserAccounts
{
    protected function seedUserWithProfileAndUnits(
        string $email,
        string $password,
        array $personData,
        string $profileName,
        array $unitIds,
        bool $mustChangePassword = false
    ): User {
        $now = now();

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'password' => $password,
                'is_active' => true,
                'two_factor_method' => 'email',
                'must_change_password' => $mustChangePassword,
                'temporary_password_expires_at' => $mustChangePassword
                    ? $now->copy()->addDays(7)
                    : null,
            ]
        );

        Person::updateOrCreate(
            ['user_id' => $user->id],
            $personData
        );

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
            $profile->update(['is_active' => true]);
        }

        $this->deactivateUserAssignments('user_profile', $user->id, $now);
        $this->syncUserAssignment(
            'user_profile',
            [
                'user_id' => $user->id,
                'profile_id' => $profile->id,
            ],
            $now
        );

        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));

        $this->deactivateUserAssignments('user_unit', $user->id, $now);

        foreach ($unitIds as $index => $unitId) {
            $this->syncUserAssignment(
                'user_unit',
                [
                    'user_id' => $user->id,
                    'unit_id' => $unitId,
                ],
                $now,
                $index === 0
            );
        }

        return $user->fresh(['person', 'profiles', 'units']);
    }

    private function deactivateUserAssignments(string $table, int $userId, mixed $now): void
    {
        DB::table($table)
            ->where('user_id', $userId)
            ->update([
                'is_active' => false,
                'updated_at' => $now,
                'deleted_at' => $now,
            ]);
    }

    private function syncUserAssignment(
        string $table,
        array $keys,
        mixed $now,
        bool $isActive = true
    ): void {
        $query = DB::table($table);

        foreach ($keys as $column => $value) {
            $query->where($column, $value);
        }

        $attributes = [
            'is_active' => $isActive,
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        if ($query->exists()) {
            $query->update($attributes);

            return;
        }

        DB::table($table)->insert($keys + $attributes + ['created_at' => $now]);
    }
}
