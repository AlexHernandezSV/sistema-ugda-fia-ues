<?php

namespace App\Http\Controllers;

use App\Support\RequestCatalog;
use Illuminate\Http\Request;
use Throwable;

class RequestCatalogController extends Controller
{
    public function index()
    {
        try {
            return response()->json(RequestCatalog::listings());
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

    public function showLoan(string $number)
    {
        try {
            $detail = RequestCatalog::loanDetail($number);

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

    public function downloadTransferDocument(string $number, string $code)
    {
        try {
            $document = RequestCatalog::transferDocumentDownload($number, $code);

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
                'Codigo: ' . ($document['code'] ?? 'N/D'),
                'Nombre: ' . ($document['name'] ?? 'N/D'),
                'Serie: ' . ($document['series'] ?? 'N/D'),
                'Soporte: ' . ($document['support'] ?? 'N/D'),
                'Ano: ' . ($document['year'] ?? 'N/D'),
                'Paginas: ' . ($document['pages'] ?? 'N/D'),
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
