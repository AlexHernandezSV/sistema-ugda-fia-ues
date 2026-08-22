<?php

namespace Tests\Feature;

use App\Models\Unit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class UnitImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_import_units_with_dependencies_from_the_same_file(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = \App\Models\User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();
        $file = UploadedFile::fake()->createWithContent(
            'unidades-productoras.xlsx',
            $this->workbookContent([
                ['Nombre de la Unidad/Escuela/Departamento', 'Dependencia 1', 'Dependencia 2', 'Dependencia 3', 'Dependencia 4'],
                ['Universidad Central', '', '', '', ''],
                ['Facultad de Ingeniería', 'Universidad Central', '', '', ''],
                ['Departamento de Sistemas', 'Facultad de Ingeniería', 'Universidad Central', '', ''],
            ])
        );

        $response = $this->actingAs($admin)->postJson('/api/units/import', [
            'file' => $file,
        ]);

        $response->assertOk()->assertJsonPath('created_count', 3);

        $department = Unit::where('name', 'Departamento de Sistemas')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['Facultad de Ingeniería', 'Universidad Central'],
            $department->parents()->pluck('name')->all()
        );
    }

    public function test_import_rejects_existing_unit_without_partial_import(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = \App\Models\User::where('email', 'admin-ugda@yopmail.com')->firstOrFail();
        Unit::create(['name' => 'Unidad Existente', 'is_active' => true]);

        $file = UploadedFile::fake()->createWithContent(
            'unidades-productoras.xlsx',
            $this->workbookContent([
                ['Nombre de la Unidad/Escuela/Departamento', 'Dependencia 1', 'Dependencia 2', 'Dependencia 3', 'Dependencia 4'],
                ['Unidad Existente', '', '', '', ''],
                ['Unidad Nueva', '', '', '', ''],
            ])
        );

        $this->actingAs($admin)
            ->postJson('/api/units/import', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonPath('message', "La unidad 'Unidad Existente' ya existe en la base de datos.");

        $this->assertDatabaseMissing('units', ['name' => 'Unidad Nueva']);
    }

    private function workbookContent(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ugda-units-test-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hoja1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');

        $sheetRows = collect($rows)->map(function (array $row, int $rowIndex): string {
            $cells = collect($row)->map(function (string $value, int $columnIndex): string {
                $column = chr(65 + $columnIndex);
                $escaped = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');

                return '<c r="' . $column . '1" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
            })->implode('');

            return '<row r="' . ($rowIndex + 1) . '">' . str_replace('1"', ($rowIndex + 1) . '"', $cells) . '</row>';
        })->implode('');

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>');
        $zip->close();

        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }
}
