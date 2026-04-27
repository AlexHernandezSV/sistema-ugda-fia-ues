<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferDocument extends Model
{
    protected $table = 'transfer_box_documents';

    protected $fillable = [
        'transfer_box_id',
        'code',
        'name',
        'series_label',
        'support_type',
        'year_label',
        'pages_label',
        'digital_file_name',
        'digital_file_path',
        'sort_order',
    ];

    public function box()
    {
        return $this->belongsTo(TransferBox::class, 'transfer_box_id');
    }
}
