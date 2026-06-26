<?php

namespace App\Http\Controllers;

use App\Support\RequestCatalog;
use Illuminate\Http\Request;
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

            $content = $box['title'] ?? ('Caja #' . $normalizedBoxNumber);

            if (str_contains($content, ' - ')) {
                $content = trim(collect(explode(' - ', $content))->slice(1)->implode(' - '));
            }

            if ($content === '') {
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
                'boxLabel' => '#' . $box['number'],
                'labelCode' => $detail['number'] . '-' . $box['number'],
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

            $fileName = $document['digitalFile'] ?? ($document['code'] . '.txt');
            $content = implode(PHP_EOL, [
                'Sistema UGDA FIA UES',
                'Documento de transferencia documental',
                'Transferencia: ' . $number,
                'Caja: ' . ($document['boxNumber'] ?? 'N/D'),
                'Código: ' . ($document['code'] ?? 'N/D'),
                'Nombre: ' . ($document['name'] ?? 'N/D'),
                'Serie: ' . ($document['series'] ?? 'N/D'),
                'Soporte: ' . ($document['support'] ?? 'N/D'),
                'Año: ' . ($document['year'] ?? 'N/D'),
                'Páginas: ' . ($document['pages'] ?? 'N/D'),
            ]);

            return response()->streamDownload(
                static function () use ($content) {
                    echo $content;
                },
                $fileName,
                [
                    'Content-Type' => 'application/octet-stream',
                ]
            );
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
}
