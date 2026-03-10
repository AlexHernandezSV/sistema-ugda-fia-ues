<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TypeDocumentSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('type_document')) {
            return;
        }

        $documents = [
            ['name' => 'DUI', 'description' => 'Documento Unico de Identidad', 'mask' => '########-#'],
            ['name' => 'NIT', 'description' => 'Numero de Identificacion Tributaria', 'mask' => '####-######-###-#'],
            ['name' => 'PASAPORTE', 'description' => 'Pasaporte', 'mask' => null],
        ];

        foreach ($documents as $document) {
            DB::table('type_document')->updateOrInsert(
                ['name' => $document['name']],
                [
                    'description' => $document['description'],
                    'mask' => $document['mask'],
                ]
            );
        }
    }
}
