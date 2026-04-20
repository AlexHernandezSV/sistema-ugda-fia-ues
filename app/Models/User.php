<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function units()
    {
        return $this->belongsToMany(Unit::class, 'user_unit')
            ->wherePivotNull('deleted_at')
            ->withPivot(['is_active', 'created_at', 'updated_at', 'deleted_at']);
    }

    public function person()
    {
        return $this->hasOne(Person::class);
    }

    public function profiles()
    {
        return $this->belongsToMany(Profile::class, 'user_profile')
            ->wherePivotNull('deleted_at')
            ->withPivot(['is_active', 'created_at', 'updated_at', 'deleted_at']);
    }
}
