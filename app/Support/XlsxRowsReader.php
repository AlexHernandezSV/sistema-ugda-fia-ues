<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class XlsxRowsReader
{
    /**
     * @return array<int, array<int, string>>
     */
    public static function read(string $path): array
    {
        $workbook = self::readWorkbook($path);

        if ($workbook === []) {
            throw new RuntimeException('El archivo no contiene hojas legibles.');
        }

        return $workbook['Hoja1'] ?? reset($workbook);
    }

    /**
     * @return array<string, array<int, array<int, string>>>
     */
    public static function readWorkbook(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo Excel.');
        }

        $sharedStrings = self::readSharedStrings($zip);
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relationshipsXml === false) {
            $zip->close();
            throw new RuntimeException('El archivo no contiene la estructura de hojas esperada.');
        }

        $workbook = simplexml_load_string($workbookXml);
        $relationships = simplexml_load_string($relationshipsXml);

        if (!$workbook instanceof SimpleXMLElement || !$relationships instanceof SimpleXMLElement) {
            $zip->close();
            throw new RuntimeException('No se pudo leer la estructura del archivo Excel.');
        }

        $mainNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $relationshipMap = [];

        foreach ($relationships->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            $relationshipId = (string) ($relationship['Id'] ?? '');

            if ($relationshipId !== '') {
                $relationshipMap[$relationshipId] = (string) ($relationship['Target'] ?? '');
            }
        }

        $sheets = [];

        $sheetNodes = $workbook->xpath('//*[local-name()="sheet"]') ?: [];

        foreach ($sheetNodes as $sheetIndex => $sheetNode) {
            $attributes = $sheetNode->attributes();
            $relationshipAttributes = $sheetNode->attributes('r', true);
            $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
            $target = $relationshipMap[$relationshipId]
                ?? 'worksheets/sheet' . ((int) $sheetIndex + 1) . '.xml';

            $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . ltrim($target, '/');
            $sheetXml = $zip->getFromName($sheetPath);

            if ($sheetXml === false) {
                continue;
            }

            $sheets[(string) $attributes['name']] = self::parseSheet($sheetXml, $sharedStrings);
        }

        $zip->close();

        return $sheets;
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, array<int, string>>
     */
    private static function parseSheet(string $sheetXml, array $sharedStrings): array
    {
        $sheet = simplexml_load_string($sheetXml);

        if (!$sheet instanceof SimpleXMLElement) {
            throw new RuntimeException('No se pudo leer una hoja del archivo.');
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rows = [];

        foreach ($sheet->children($namespace)->sheetData->children($namespace)->row as $rowNode) {
            $row = [];

            foreach ($rowNode->children($namespace)->c as $cellNode) {
                $attributes = $cellNode->attributes();
                $reference = (string) ($attributes['r'] ?? '');
                preg_match('/([A-Z]+)/', $reference, $matches);
                $columnIndex = isset($matches[1])
                    ? self::columnIndex($matches[1]) - 1
                    : count($row);
                $type = (string) ($attributes['t'] ?? '');
                $value = '';

                if ($type === 's') {
                    $value = $sharedStrings[(int) $cellNode->children($namespace)->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $texts = $cellNode->xpath('.//*[local-name()="t"]');
                    $value = collect($texts)
                        ->map(fn ($text) => (string) $text)
                        ->implode('');
                } else {
                    $value = (string) ($cellNode->children($namespace)->v ?? '');
                }

                $row[$columnIndex] = trim($value);
            }

            if ($row !== []) {
                ksort($row);
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $sharedStrings = simplexml_load_string($xml);

        if (!$sharedStrings instanceof SimpleXMLElement) {
            return [];
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $values = [];

        foreach ($sharedStrings->children($namespace)->si as $item) {
            $texts = $item->xpath('.//*[local-name()="t"]');
            $values[] = collect($texts)->map(fn ($text) => (string) $text)->implode('');
        }

        return $values;
    }

    private static function columnIndex(string $name): int
    {
        $index = 0;

        foreach (str_split($name) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }
}
