<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

class XlsxWorkbookWriter
{
    /**
     * @param array<int, array{
     *     name:string,
     *     headers:array<int, string>,
     *     rows:array<int, array<int, string|int|null>>
     * }> $sheets
     */
    public static function write(string $path, array $sheets): void
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear la plantilla Excel.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', self::rootRelationships());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelationships(count($sheets)));
        $zip->addFromString('xl/styles.xml', self::styles());

        foreach ($sheets as $index => $sheet) {
            $zip->addFromString(
                'xl/worksheets/sheet' . ($index + 1) . '.xml',
                self::worksheet($sheet['headers'], $sheet['rows'])
            );
        }

        if (!$zip->close()) {
            throw new RuntimeException('No se pudo finalizar la plantilla Excel.');
        }
    }

    private static function contentTypes(int $sheetCount): string
    {
        $overrides = [
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>',
        ];

        for ($index = 1; $index <= $sheetCount; $index++) {
            $overrides[] = sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $index
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . implode('', $overrides)
            . '</Types>';
    }

    private static function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    /**
     * @param array<int, array{name:string}> $sheets
     */
    private static function workbook(array $sheets): string
    {
        $sheetNodes = [];

        foreach ($sheets as $index => $sheet) {
            $sheetNodes[] = sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                self::escape((string) $sheet['name']),
                $index + 1,
                $index + 1
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView x15ac:windowWidth="19200" x15ac:windowHeight="10800" workbookViewId="0" '
            . 'xmlns:x15ac="http://schemas.microsoft.com/office/spreadsheetml/2010/11/ac"/></bookViews>'
            . '<sheets>' . implode('', $sheetNodes) . '</sheets>'
            . '<calcPr calcId="191029" fullCalcOnLoad="1"/>'
            . '</workbook>';
    }

    private static function workbookRelationships(int $sheetCount): string
    {
        $relationships = [];

        for ($index = 1; $index <= $sheetCount; $index++) {
            $relationships[] = sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $index,
                $index
            );
        }

        $relationships[] = sprintf(
            '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
            $sheetCount + 1
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', $relationships)
            . '</Relationships>';
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string|int|null>> $rows
     */
    private static function worksheet(array $headers, array $rows): string
    {
        $allRows = [$headers, ...$rows];
        $columnCount = max(1, count($headers));
        $lastCell = self::columnName($columnCount) . max(1, count($allRows));
        $columnDefinitions = [];

        for ($column = 0; $column < $columnCount; $column++) {
            $maxLength = mb_strlen((string) ($headers[$column] ?? ''));

            foreach ($rows as $row) {
                $maxLength = max($maxLength, mb_strlen((string) ($row[$column] ?? '')));
            }

            $columnDefinitions[] = sprintf(
                '<col min="%d" max="%d" width="%.2f" customWidth="1"/>',
                $column + 1,
                $column + 1,
                min(42, max(14, $maxLength + 2))
            );
        }

        $rowNodes = [];

        foreach ($allRows as $rowIndex => $row) {
            $cells = [];

            for ($column = 0; $column < $columnCount; $column++) {
                $value = (string) ($row[$column] ?? '');
                $cellReference = self::columnName($column + 1) . ($rowIndex + 1);
                $style = $rowIndex === 0 ? ' s="1"' : '';
                $cells[] = sprintf(
                    '<c r="%s" t="inlineStr"%s><is><t xml:space="preserve">%s</t></is></c>',
                    $cellReference,
                    $style,
                    self::escape($value)
                );
            }

            $height = $rowIndex === 0 ? ' ht="30" customHeight="1"' : '';
            $rowNodes[] = sprintf(
                '<row r="%d"%s>%s</row>',
                $rowIndex + 1,
                $height,
                implode('', $cells)
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>' . implode('', $columnDefinitions) . '</cols>'
            . '<sheetData>' . implode('', $rowNodes) . '</sheetData>'
            . '<autoFilter ref="A1:' . $lastCell . '"/>'
            . '</worksheet>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="0"/>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1768C4"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/>'
            . '</xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function columnName(int $column): string
    {
        $name = '';

        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $name = chr(65 + $remainder) . $name;
            $column = intdiv($column - 1, 26);
        }

        return $name;
    }

    private static function escape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
