<?php

namespace App\Http\Controllers;

use App\Support\RequestCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RequestCatalogController extends Controller
{
    public function index(Request $request)
    {
        try {
            return response()->json(RequestCatalog::listings($request->user()));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el catalogo de solicitudes.',
            ], 500);
        }
    }

    public function showTransfer(Request $request, string $number)
    {
        try {
            $detail = RequestCatalog::transferDetail($number, $request->user());

            if ($detail === null) {
                return response()->json([
                    'message' => 'Transferencia no encontrada.',
                ], 404);
            }

            return response()->json($detail);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el detalle de la transferencia.',
            ], 500);
        }
    }

    public function showTransferBoxLabel(Request $request, string $number, string $boxNumber)
    {
        try {
            $detail = RequestCatalog::transferDetail($number, $request->user());

            if ($detail === null) {
                return response()->json([
                    'message' => 'Transferencia no encontrada.',
                ], 404);
            }

            if (!($detail['canViewBoxLocationLabels'] ?? false)) {
                return response()->json([
                    'message' => 'No tiene permisos para consultar esta etiqueta.',
                ], 403);
            }

            $normalizedBoxNumber = str_pad((string) ((int) $boxNumber), 3, '0', STR_PAD_LEFT);
            $box = collect($detail['boxes'] ?? [])
                ->first(fn ($item) => ($item['number'] ?? null) === $normalizedBoxNumber);

            if (!$box) {
                return response()->json([
                    'message' => 'Caja no encontrada para esta transferencia.',
                ], 404);
            }

            $boxCode = $box['boxCode'] ?? $box['code'] ?? ('C-' . $detail['number'] . '-' . $normalizedBoxNumber);
            $content = $box['contentDescription'] ?? $box['title'] ?? '';

            if (str_contains($content, ' - ')) {
                $content = trim(collect(explode(' - ', $content))->slice(1)->implode(' - '));
            }

            if ($content === '' || preg_match('/^Caja\s+(#?\d+|C-)/i', $content) === 1) {
                $firstDocument = collect($box['documents'] ?? [])->first();
                $content = $firstDocument['series'] ?? $firstDocument['name'] ?? ('Documentacion de caja ' . $normalizedBoxNumber);
            }

            return response()->json([
                'transferNumber' => $detail['number'],
                'transferDate' => $detail['completion']['date'] ?? $detail['receivedAt'] ?? null,
                'unit' => $detail['unit'],
                'authorizationStatus' => $detail['auth'],
                'workflowStatus' => $detail['status'],
                'boxNumber' => $box['number'],
                'boxCode' => $boxCode,
                'boxLabel' => $boxCode,
                'labelCode' => $boxCode,
                'locationCode' => $box['physicalLocation'],
                'period' => $box['period'],
                'content' => $content,
                'assignedBy' => $detail['completion']['completedBy'] ?? $detail['authorizedBy'] ?? 'UGDA',
                'assignedAt' => trim(($detail['completion']['date'] ?? '') . ' ' . ($detail['completion']['time'] ?? '')) ?: ($detail['authorizedAt'] ?? null),
                'documents' => $box['documents'] ?? [],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar la informacion de la etiqueta.',
            ], 500);
        }
    }

    public function showLoan(Request $request, string $number)
    {
        try {
            $detail = RequestCatalog::loanDetail($number, $request->user());

            if ($detail === null) {
                return response()->json([
                    'message' => 'Prestamo no encontrado.',
                ], 404);
            }

            return response()->json($detail);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el detalle del prestamo.',
            ], 500);
        }
    }

    public function downloadTransferDocument(Request $request, string $number, string $code)
    {
        try {
            $document = RequestCatalog::transferDocumentDownload($number, $code, $request->user());

            if ($document === null) {
                return response()->json([
                    'message' => 'Documento digital no encontrado.',
                ], 404);
            }

            $fileName = $document['digitalFile'] ?? ($document['code'] . '.pdf');
            $filePath = $this->resolveDigitalDocumentPath($document['digitalPath'] ?? null);

            if ($filePath !== null) {
                return response()->file($filePath, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="' . addslashes($fileName) . '"',
                ]);
            }

            return response($this->buildDigitalDocumentPreviewPdf($number, $document), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . addslashes($fileName) . '"',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo descargar el documento digital.',
            ], 500);
        }
    }

    public function dashboard(Request $request)
    {
        try {
            return response()->json(RequestCatalog::dashboard($request->user()));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar el dashboard.',
            ], 500);
        }
    }

    private function resolveDigitalDocumentPath(?string $digitalPath): ?string
    {
        $normalizedPath = str_replace('\\', '/', trim((string) $digitalPath));

        if ($normalizedPath === '' || str_contains($normalizedPath, '..')) {
            return null;
        }

        $candidatePaths = array_values(array_unique(array_filter([
            $normalizedPath,
            'public/' . $normalizedPath,
            str_starts_with($normalizedPath, 'uploads/') ? $normalizedPath : 'uploads/' . basename($normalizedPath),
            str_starts_with($normalizedPath, 'uploads/') ? 'public/' . $normalizedPath : 'public/uploads/' . basename($normalizedPath),
        ])));

        foreach ($candidatePaths as $candidatePath) {
            if (Storage::disk('local')->exists($candidatePath)) {
                return Storage::disk('local')->path($candidatePath);
            }

            if (str_starts_with($candidatePath, 'public/')) {
                $publicPath = substr($candidatePath, strlen('public/'));

                if (Storage::disk('public')->exists($publicPath)) {
                    return Storage::disk('public')->path($publicPath);
                }
            }
        }

        return null;
    }

    private function buildDigitalDocumentPreviewPdf(string $number, array $document): string
    {
        $lines = [
            'Sistema UGDA FIA UES',
            'Vista previa del documento',
            'Transferencia: ' . $number,
            'Caja: ' . ($document['boxNumber'] ?? 'N/D'),
            'Codigo: ' . ($document['code'] ?? 'N/D'),
            'Nombre: ' . ($document['name'] ?? 'N/D'),
            'Serie: ' . ($document['series'] ?? 'N/D'),
            'Soporte: ' . ($document['support'] ?? 'N/D'),
            'Anio: ' . ($document['year'] ?? 'N/D'),
            'Paginas: ' . ($document['pages'] ?? 'N/D'),
            'Archivo registrado: ' . ($document['digitalFile'] ?? 'N/D'),
        ];

        $content = "BT\n/F1 16 Tf\n72 760 Td\n";

        foreach ($lines as $index => $line) {
            $fontSize = $index === 0 ? 18 : 12;
            $content .= "/F1 {$fontSize} Tf\n";
            $content .= '(' . $this->escapePdfText($line) . ") Tj\n";
            $content .= '0 -' . ($index === 0 ? 30 : 22) . " Td\n";
        }

        $content .= "ET\n";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function escapePdfText(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);

        if ($converted === false) {
            $converted = $text;
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }
}
