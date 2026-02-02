<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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
        // Campos de identificación personal
        'first_name',
        'second_name',
        'first_last_name',
        'second_last_name',
        'carnet',
        
        // Campos de sistema y organización
        'email',
        'password',
        'role',
        'unit_id',   // Llave foránea para la unidad
        'is_active', // Estado del usuario
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
            'is_active' => 'boolean', // Convierte 1/0 a true/false automáticamente
        ];
    }

    /**
     * RELACIONES
     */

    // Un usuario pertenece a una Unidad
    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}