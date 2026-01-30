<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relación: Mis dependencias (Padres)
    public function parents()
    {
        return $this->belongsToMany(Unit::class, 'unit_dependencies', 'unit_id', 'parent_id');
    }

    // Relación: Quién depende de mí (Hijos)
    public function children()
    {
        return $this->belongsToMany(Unit::class, 'unit_dependencies', 'parent_id', 'unit_id');
    }
}