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
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo Excel.');
        }

        $sharedStrings = self::readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('El archivo no contiene la hoja esperada.');
        }

        $sheet = simplexml_load_string($sheetXml);

        if (!$sheet instanceof SimpleXMLElement) {
            throw new RuntimeException('No se pudo leer la hoja del archivo.');
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
                    $value = (string) ($cellNode->children($namespace)->is->children($namespace)->t ?? '');
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
