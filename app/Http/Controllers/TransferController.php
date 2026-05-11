<?php

namespace App\Http\Controllers;

use App\Models\RequestStatusCatalog;
use App\Models\Transfer;
use App\Services\SystemNotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;
use ZipArchive;

class TransferController extends Controller
{
    public function nextCode(): JsonResponse
    {
        try {
            $last = Transfer::query()
                ->pluck('code')
                ->filter(fn ($code) => ctype_digit((string) $code))
                ->map(fn ($code) => (int) $code)
                ->max();
            $next = str_pad((string) (($last ?? 0) + 1), 6, '0', STR_PAD_LEFT);

            return response()->json(['code' => $next]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo generar el correlativo de transferencia.',
            ], 500);
        }
    }

    public function documentImportTemplate()
    {
        $headers = [
            'Serie del documento',
            'Nombre del documento',
            'Código',
            'Soporte',
            'Año',
            'Cantidad de páginas',
        ];

        $path = $this->createDocumentImportWorkbook([$headers]);

        return response()
            ->download($path, 'plantilla-importacion-documentos.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    public function importDocuments(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
            'current_box_series' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe seleccionar un archivo Excel valido en formato .xlsx.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $rows = $this->readXlsxRows($request->file('file')->getRealPath());

            if (count($rows) < 2) {
                return response()->json([
                    'message' => 'El archivo no contiene documentos para importar.',
                ], 422);
            }

            $headers = array_map(fn ($header) => $this->normalizeImportKey($header), $rows[0]);
            $documents = [];
            $currentBoxSeries = trim((string) $request->input('current_box_series', ''));

            foreach (array_slice($rows, 1) as $index => $row) {
                if (!collect($row)->contains(fn ($cell) => trim((string) $cell) !== '')) {
                    continue;
                }

                $series = $this->getImportValue($row, $headers, ['seriedeldocumento']);
                $name = $this->getImportValue($row, $headers, ['nombredeldocumento']);
                $code = $this->getImportValue($row, $headers, ['codigo', 'cdigo']);
                $support = $this->resolveSupportLabel($this->getImportValue($row, $headers, ['soporte']));
                $year = $this->getImportValue($row, $headers, ['ano', 'ao']);
                $pages = $this->getImportValue($row, $headers, ['cantidaddepaginas', 'cantidaddepginas']);

                if ($name === '' || $code === '' || $support === '') {
                    return response()->json([
                        'message' => 'La fila ' . ($index + 2) . ' debe tener Nombre del documento, Codigo y Soporte valido.',
                    ], 422);
                }

                $documents[] = [
                    'series' => $series !== '' ? $series : $currentBoxSeries,
                    'name' => $name,
                    'code' => $code,
                    'date' => $year,
                    'pages' => $pages,
                    'support' => $support,
                ];
            }

            if (count($documents) === 0) {
                return response()->json([
                    'message' => 'El archivo no contiene filas con documentos.',
                ], 422);
            }

            return response()->json([
                'documents' => $documents,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo procesar el archivo Excel. Revise que use la plantilla vigente.',
            ], 500);
        }
    }

    public function authorizeByUnit(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los comentarios no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transfer = Transfer::query()
                ->with(['authorizationStatus', 'workflowStatus'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            if (!$this->canUnitDirectorAct($request, $transfer)) {
                return response()->json([
                    'message' => 'Solo la jefatura de la unidad productora puede autorizar esta solicitud.',
                ], 403);
            }

            if ($transfer->authorizationStatus?->code !== 'transfer_auth_pending' || $transfer->workflowStatus?->code !== 'transfer_status_pending') {
                return response()->json([
                    'message' => 'Esta transferencia ya no esta pendiente de autorizacion por jefatura.',
                ], 422);
            }

            $comments = trim($request->string('comments')->toString());
            $authAuthorized = RequestStatusCatalog::query()->where('code', 'transfer_auth_authorized')->value('id');

            DB::transaction(function () use ($request, $transfer, $authAuthorized, $comments) {
                $transfer->update([
                    'authorization_status_id' => $authAuthorized,
                    'authorized_by_user_id' => $request->user()->id,
                    'authorized_at' => now(),
                    'view_mode' => 'review',
                    'box_display_state' => 'collapsed',
                ]);

                $transfer->events()->create([
                    'status_catalog_id' => $authAuthorized,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Autorizado',
                    'description' => $comments !== ''
                        ? 'Solicitud autorizada por jefatura de unidad. Comentario: ' . $comments
                        : 'Solicitud autorizada por jefatura de unidad.',
                    'context' => [
                        'decision_scope' => 'unit',
                        'comments' => $comments !== '' ? $comments : null,
                    ],
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->transferAuthorizedByUnit($transfer->fresh(['unit']), $request->user());

            return response()->json([
                'message' => 'Transferencia autorizada por jefatura de unidad.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo autorizar la transferencia.',
            ], 500);
        }
    }

    public function denyByUnit(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe proporcionar un motivo de denegacion.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transfer = Transfer::query()
                ->with(['authorizationStatus', 'workflowStatus'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            if (!$this->canUnitDirectorAct($request, $transfer)) {
                return response()->json([
                    'message' => 'Solo la jefatura de la unidad productora puede denegar esta solicitud.',
                ], 403);
            }

            if ($transfer->authorizationStatus?->code !== 'transfer_auth_pending' || $transfer->workflowStatus?->code !== 'transfer_status_pending') {
                return response()->json([
                    'message' => 'Esta transferencia ya no esta pendiente de autorizacion por jefatura.',
                ], 422);
            }

            $reason = trim($request->string('reason')->toString());
            $authDenied = RequestStatusCatalog::query()->where('code', 'transfer_auth_denied')->value('id');
            $statusDenied = RequestStatusCatalog::query()->where('code', 'transfer_status_denied')->value('id');

            DB::transaction(function () use ($request, $transfer, $authDenied, $statusDenied, $reason) {
                $transfer->update([
                    'authorization_status_id' => $authDenied,
                    'workflow_status_id' => $statusDenied,
                    'authorized_by_user_id' => $request->user()->id,
                    'authorized_at' => now(),
                    'status' => 'Rechazada',
                    'view_mode' => 'detail',
                    'show_print_card' => false,
                    'box_display_state' => 'collapsed',
                ]);

                $transfer->events()->create([
                    'status_catalog_id' => $authDenied,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'decision',
                    'title' => 'Denegado por jefatura',
                    'description' => $reason,
                    'context' => [
                        'reason_label' => 'Motivo de denegacion:',
                        'reason' => $reason,
                        'decision_scope' => 'unit',
                    ],
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->transferDeniedByUnit($transfer->fresh(), $request->user(), $reason);

            return response()->json([
                'message' => 'Transferencia denegada por jefatura de unidad.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo denegar la transferencia.',
            ], 500);
        }
    }

    public function editData(Request $request, string $number): JsonResponse
    {
        try {
            $transfer = Transfer::query()
                ->with(['authorizationStatus', 'workflowStatus', 'requester.person', 'events.actor.person', 'boxes.documents'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            if (!$this->canRequesterCorrectObserved($request, $transfer)) {
                return response()->json([
                    'message' => 'Solo la unidad solicitante puede editar una transferencia observada de su unidad.',
                ], 403);
            }

            if ($transfer->workflowStatus?->code !== 'transfer_status_observed') {
                return response()->json([
                    'message' => 'Esta transferencia no esta en estado observado.',
                ], 422);
            }

            return response()->json([
                'transfer' => [
                    'code' => $transfer->code,
                    'request_date' => optional($transfer->request_date)->format('Y-m-d'),
                    'unit_id' => $transfer->unit_id,
                    'responsible' => trim(collect([
                        $transfer->requester?->person?->first_name,
                        $transfer->requester?->person?->second_name,
                        $transfer->requester?->person?->first_last_name,
                        $transfer->requester?->person?->second_last_name,
                    ])->filter()->implode(' ')),
                    'description' => $transfer->description,
                ],
                'boxes' => $transfer->boxes->map(fn ($box) => [
                    'id_temp' => 'box-' . $box->id,
                    'series' => $box->series_name,
                    'year_start' => (string) $box->start_year,
                    'year_end' => (string) $box->end_year,
                    'box_number' => (int) $box->box_number,
                    'documents_count' => $box->documents->count(),
                    'documents_list' => $box->documents->map(fn ($document) => [
                        'id_doc' => 'doc-' . $document->id,
                        'series' => $document->series_label,
                        'name' => $document->name,
                        'code' => $document->code,
                        'date' => $document->year_label,
                        'pages' => $document->pages_label,
                        'support' => $document->support_type,
                        'file_name' => $document->digital_file_name,
                        'file_url' => $document->digital_file_name
                            ? '/api/transfers/' . $transfer->code . '/documents/' . $document->code . '/download'
                            : null,
                    ])->values()->all(),
                ])->values()->all(),
                'observations' => $transfer->events
                    ->where('event_type', 'observation')
                    ->sortBy('occurred_at')
                    ->map(fn ($event) => [
                        'actor' => trim(collect([
                            $event->actor?->person?->first_name,
                            $event->actor?->person?->second_name,
                            $event->actor?->person?->first_last_name,
                            $event->actor?->person?->second_last_name,
                        ])->filter()->implode(' ')) ?: 'UGDA',
                        'dateTime' => optional($event->occurred_at)->format('d/m/Y H:i'),
                        'message' => $event->description,
                    ])->values()->all(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo cargar la transferencia para edicion.',
            ], 500);
        }
    }

    public function resubmitCorrection(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:60'],
            'request_date' => ['required', 'date'],
            'unit_id' => ['required', 'exists:units,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'boxes' => ['required', 'array', 'min:1'],
            'boxes.*.series' => ['required', 'string', 'max:255'],
            'boxes.*.year_start' => ['required', 'integer', 'min:1900', 'max:2100'],
            'boxes.*.year_end' => ['required', 'integer', 'min:1900', 'max:2100'],
            'boxes.*.box_number' => ['required', 'integer', 'min:1'],
            'boxes.*.documents_list' => ['required', 'array', 'min:1'],
            'boxes.*.documents_list.*.series' => ['nullable', 'string', 'max:255'],
            'boxes.*.documents_list.*.name' => ['required', 'string', 'max:255'],
            'boxes.*.documents_list.*.code' => ['nullable', 'string', 'max:120'],
            'boxes.*.documents_list.*.date' => ['nullable', 'string', 'max:50'],
            'boxes.*.documents_list.*.pages' => ['nullable', 'string', 'max:50'],
            'boxes.*.documents_list.*.support' => ['required', 'string', 'max:30'],
            'boxes.*.documents_list.*.file_name' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la transferencia no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transfer = Transfer::query()
                ->with(['authorizationStatus', 'workflowStatus', 'boxes.documents'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            if (!$this->canRequesterCorrectObserved($request, $transfer)) {
                return response()->json([
                    'message' => 'Solo la unidad solicitante puede corregir esta transferencia.',
                ], 403);
            }

            if (!$request->user()->units()->where('units.id', (int) $request->input('unit_id'))->exists()) {
                return response()->json([
                    'message' => 'No puede reenviar la transferencia para una unidad que no tiene asignada.',
                ], 403);
            }

            if ($transfer->workflowStatus?->code !== 'transfer_status_observed') {
                return response()->json([
                    'message' => 'Esta transferencia no esta en estado observado.',
                ], 422);
            }

            DB::transaction(function () use ($request, $transfer) {
                $requestedAt = Carbon::parse($request->input('request_date'));
                $authPending = RequestStatusCatalog::query()->where('code', 'transfer_auth_pending')->value('id');
                $statusPending = RequestStatusCatalog::query()->where('code', 'transfer_status_pending')->value('id');

                $transfer->update([
                    'unit_id' => (int) $request->input('unit_id'),
                    'request_date' => $requestedAt->toDateString(),
                    'requested_at' => $requestedAt,
                    'status' => 'Enviada',
                    'authorization_status_id' => $authPending,
                    'workflow_status_id' => $statusPending,
                    'authorized_by_user_id' => null,
                    'authorized_at' => null,
                    'completed_by_user_id' => null,
                    'completed_at' => null,
                    'scheduled_for' => null,
                    'view_mode' => 'detail',
                    'box_display_state' => 'collapsed',
                    'show_print_card' => false,
                    'description' => $request->input('description'),
                ]);

                $transfer->boxes->each(function ($box) {
                    $box->documents()->delete();
                });
                $transfer->boxes()->delete();
                $this->syncTransferBoxes($transfer, $request->input('boxes', []));

                $transfer->events()->create([
                    'status_catalog_id' => $statusPending,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Corregido y reenviado',
                    'description' => 'Solicitud corregida por la unidad solicitante y reenviada a jefatura para autorizacion.',
                    'context' => [
                        'previous_status' => 'transfer_status_observed',
                    ],
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->transferResubmitted($transfer->fresh(['unit']), $request->user());

            return response()->json([
                'message' => 'Solicitud corregida y reenviada a jefatura.',
                'number' => $transfer->code,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo guardar la correccion de la transferencia.',
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:60', 'unique:transfers,code'],
            'request_date' => ['required', 'date'],
            'unit_id' => ['required', 'exists:units,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'boxes' => ['required', 'array', 'min:1'],
            'boxes.*.series' => ['required', 'string', 'max:255'],
            'boxes.*.year_start' => ['required', 'integer', 'min:1900', 'max:2100'],
            'boxes.*.year_end' => ['required', 'integer', 'min:1900', 'max:2100'],
            'boxes.*.box_number' => ['required', 'integer', 'min:1'],
            'boxes.*.documents_list' => ['required', 'array', 'min:1'],
            'boxes.*.documents_list.*.series' => ['nullable', 'string', 'max:255'],
            'boxes.*.documents_list.*.name' => ['required', 'string', 'max:255'],
            'boxes.*.documents_list.*.code' => ['nullable', 'string', 'max:120'],
            'boxes.*.documents_list.*.date' => ['nullable', 'string', 'max:50'],
            'boxes.*.documents_list.*.pages' => ['nullable', 'string', 'max:50'],
            'boxes.*.documents_list.*.support' => ['required', 'string', 'max:30'],
            'boxes.*.documents_list.*.file_name' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la transferencia no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$request->user()->units()->where('units.id', (int) $request->input('unit_id'))->exists()) {
            return response()->json([
                'message' => 'No puede crear una transferencia para una unidad que no tiene asignada.',
            ], 403);
        }

        try {
            $transfer = DB::transaction(function () use ($request) {
                $requestedAt = Carbon::parse($request->input('request_date'));
                $authPending = RequestStatusCatalog::query()->where('code', 'transfer_auth_pending')->value('id');
                $statusPending = RequestStatusCatalog::query()->where('code', 'transfer_status_pending')->value('id');

                $transfer = Transfer::query()->create([
                    'code' => $request->string('code')->toString(),
                    'user_id' => $request->user()->id,
                    'unit_id' => (int) $request->input('unit_id'),
                    'request_date' => $requestedAt->toDateString(),
                    'requested_at' => $requestedAt,
                    'status' => 'Enviada',
                    'authorization_status_id' => $authPending,
                    'workflow_status_id' => $statusPending,
                    'view_mode' => 'detail',
                    'box_display_state' => 'collapsed',
                    'show_print_card' => false,
                    'description' => $request->input('description'),
                ]);

                $this->syncTransferBoxes($transfer, $request->input('boxes', []));

                $transfer->events()->create([
                    'status_catalog_id' => null,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Creado',
                    'description' => 'Solicitud de transferencia creada por la unidad productora.',
                    'context' => null,
                    'occurred_at' => $requestedAt,
                ]);

                return $transfer;
            });

            app(SystemNotificationService::class)->transferCreated($transfer->fresh(['unit']), $request->user());

            return response()->json([
                'message' => 'Solicitud de transferencia registrada correctamente.',
                'number' => $transfer->code,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Ocurrio un problema al guardar la transferencia. Intente nuevamente.',
            ], 500);
        }
    }

    private function syncTransferBoxes(Transfer $transfer, array $boxesPayload): void
    {
        foreach ($boxesPayload as $boxPayload) {
            $box = $transfer->boxes()->create([
                'series_name' => $boxPayload['series'],
                'start_year' => (int) $boxPayload['year_start'],
                'end_year' => (int) $boxPayload['year_end'],
                'box_number' => (int) $boxPayload['box_number'],
                'title' => 'Caja #' . str_pad((string) $boxPayload['box_number'], 3, '0', STR_PAD_LEFT),
                'period_label' => '01/01/' . $boxPayload['year_start'] . ' - 31/12/' . $boxPayload['year_end'],
                'content_description' => $boxPayload['series'],
            ]);

            foreach ($boxPayload['documents_list'] as $documentIndex => $documentPayload) {
                $fileName = $documentPayload['file_name'] ?? null;

                $box->documents()->create([
                    'code' => $documentPayload['code'] ?? 'DOC-' . $transfer->code . '-' . str_pad((string) ($documentIndex + 1), 3, '0', STR_PAD_LEFT),
                    'name' => $documentPayload['name'],
                    'series_label' => $documentPayload['series'] ?? $boxPayload['series'],
                    'support_type' => $documentPayload['support'],
                    'year_label' => $documentPayload['date'] ?? null,
                    'pages_label' => $documentPayload['pages'] ?? null,
                    'digital_file_name' => $fileName,
                    'digital_file_path' => $fileName ? 'uploads/' . $fileName : null,
                    'sort_order' => $documentIndex + 1,
                ]);
            }
        }
    }

    private function createDocumentImportWorkbook(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ugda-document-import-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Documentos" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');

        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach ($row as $columnIndex => $value) {
                $cellRef = $this->columnName($columnIndex + 1) . ($rowIndex + 1);
                $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $cells .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
            }
            $sheetRows .= '<row r="' . ($rowIndex + 1) . '">' . $cells . '</row>';
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <cols>
    <col min="1" max="2" width="32" customWidth="1"/>
    <col min="3" max="4" width="18" customWidth="1"/>
    <col min="5" max="6" width="20" customWidth="1"/>
  </cols>
  <sheetData>' . $sheetRows . '</sheetData>
</worksheet>');
        $zip->close();

        return $path;
    }

    private function readXlsxRows(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new \RuntimeException('No se pudo abrir el archivo Excel.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException('El archivo no contiene la hoja esperada.');
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw new \RuntimeException('No se pudo leer la hoja del archivo.');
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rows = [];

        foreach ($sheet->children($namespace)->sheetData->children($namespace)->row as $rowNode) {
            $row = [];

            foreach ($rowNode->children($namespace)->c as $cellNode) {
                $attributes = $cellNode->attributes();
                $reference = (string) ($attributes['r'] ?? '');
                preg_match('/([A-Z]+)/', $reference, $matches);
                $columnIndex = isset($matches[1]) ? $this->columnIndex($matches[1]) - 1 : count($row);
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

            if (count($row) > 0) {
                ksort($row);
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $sharedStrings = simplexml_load_string($xml);
        if ($sharedStrings === false) {
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

    private function getImportValue(array $row, array $headers, array $acceptedKeys): string
    {
        foreach ($acceptedKeys as $key) {
            $index = array_search($key, $headers, true);

            if ($index !== false) {
                return trim((string) ($row[$index] ?? ''));
            }
        }

        return '';
    }

    private function resolveSupportLabel(string $value): string
    {
        return match ($this->normalizeImportKey($value)) {
            'fisico', 'fsico' => 'Físico',
            'digital' => 'Digital',
            'mixto' => 'Mixto',
            default => '',
        };
    }

    private function normalizeImportKey(string $value): string
    {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value));
        $normalized = strtolower($normalized !== false ? $normalized : trim($value));

        return preg_replace('/[^a-z0-9]/', '', $normalized) ?? '';
    }

    private function columnName(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function columnIndex(string $name): int
    {
        $index = 0;

        foreach (str_split($name) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }

    private function canUnitDirectorAct(Request $request, Transfer $transfer): bool
    {
        $user = $request->user();
        $profileName = $user->activeProfile()?->name;

        if ($profileName !== 'Director/Jefe de Unidad') {
            return false;
        }

        return $user->units()
            ->where('units.id', $transfer->unit_id)
            ->exists();
    }

    private function canRequesterCorrectObserved(Request $request, Transfer $transfer): bool
    {
        $user = $request->user();

        if ($user->activeProfile()?->name !== 'Unidad Solicitante') {
            return false;
        }

        return $user->units()
            ->where('units.id', $transfer->unit_id)
            ->exists();
    }

    private function canUgdaManage(Request $request): bool
    {
        $user = $request->user();
        $profileName = $user->activeProfile()?->name;

        if (in_array($profileName, ['Administrador', 'Usuario UGDA'], true)) {
            return true;
        }

        return $user->units()
            ->where('units.code', 'UGDA')
            ->exists();
    }

    public function schedule(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'scheduled_for' => ['required', 'date'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe indicar la fecha de entrega.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $comments = trim($request->string('comments')->toString());

        return $this->changeWorkflow($request, $number, 'transfer_status_scheduled', 'Agendado', $comments !== ''
            ? 'Transferencia autorizada para entrega fisica. Comentario: ' . $comments
            : 'Transferencia autorizada para entrega fisica', [
            'scheduled_for' => Carbon::parse($request->input('scheduled_for'))->format('d/m/Y H:i'),
            'comments' => $comments !== '' ? $comments : null,
        ], [
            'scheduled_for' => Carbon::parse($request->input('scheduled_for')),
            'view_mode' => 'detail',
            'show_print_card' => true,
            'box_display_state' => 'first',
        ]);
    }

    public function completeLocations(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'locations' => ['required', 'array', 'min:1'],
            'locations.*.box_number' => ['required', 'integer', 'min:1'],
            'locations.*.aisle' => ['required', 'string', 'max:80'],
            'locations.*.shelf' => ['required', 'string', 'max:80'],
            'locations.*.level' => ['required', 'string', 'max:80'],
            'locations.*.position' => ['required', 'string', 'max:80'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe asignar ubicacion fisica a todas las cajas.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transfer = Transfer::query()
                ->with(['workflowStatus', 'boxes'])
                ->where('code', $number)
                ->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            if (!$this->canUgdaManage($request)) {
                return response()->json([
                    'message' => 'Solo UGDA puede completar el proceso de transferencia.',
                ], 403);
            }

            if ($transfer->workflowStatus?->code !== 'transfer_status_scheduled') {
                return response()->json([
                    'message' => 'La transferencia debe estar agendada para asignar ubicaciones fisicas.',
                ], 422);
            }

            $locations = collect($request->input('locations', []))->keyBy('box_number');
            $missingBoxes = $transfer->boxes
                ->filter(fn ($box) => !$locations->has($box->box_number))
                ->pluck('box_number')
                ->values();

            if ($missingBoxes->isNotEmpty()) {
                return response()->json([
                    'message' => 'Debe asignar ubicacion fisica a todas las cajas.',
                    'missing_boxes' => $missingBoxes,
                ], 422);
            }

            DB::transaction(function () use ($request, $transfer, $locations) {
                foreach ($transfer->boxes as $box) {
                    $location = $locations->get($box->box_number);
                    $locationCode = collect([
                        $location['aisle'],
                        $location['shelf'],
                        $location['level'],
                        $location['position'],
                    ])->filter()->implode(' / ');

                    $box->update([
                        'location_code' => $locationCode,
                        'assigned_by_user_id' => $request->user()->id,
                        'assigned_at' => now(),
                    ]);
                }

                $statusTransferred = RequestStatusCatalog::query()->where('code', 'transfer_status_transferred')->value('id');

                $transfer->update([
                    'workflow_status_id' => $statusTransferred,
                    'completed_by_user_id' => $request->user()->id,
                    'completed_at' => now(),
                    'status' => 'Aprobada',
                    'view_mode' => 'detail',
                    'box_display_state' => 'all',
                    'show_print_card' => true,
                ]);

                $transfer->events()->create([
                    'status_catalog_id' => $statusTransferred,
                    'actor_user_id' => $request->user()->id,
                    'event_type' => 'status',
                    'title' => 'Transferido',
                    'description' => 'Ubicaciones fisicas asignadas y transferencia completada por UGDA.',
                    'context' => [
                        'locations' => $locations->values()->all(),
                    ],
                    'occurred_at' => now(),
                ]);
            });

            app(SystemNotificationService::class)->transferCompleted($transfer->fresh(), $request->user());

            return response()->json([
                'message' => 'Transferencia completada correctamente.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo completar el proceso de transferencia.',
            ], 500);
        }
    }

    public function observe(Request $request, string $number): JsonResponse
    {
        return $this->changeWorkflow($request, $number, 'transfer_status_observed', 'Observacion UGDA', $request->input('message', ''), null, [], 'observation', true);
    }

    public function deny(Request $request, string $number): JsonResponse
    {
        return $this->changeWorkflow($request, $number, 'transfer_status_denied', 'Denegado', $request->input('reason', ''), [
            'reason_label' => 'Motivo de rechazo:',
            'reason' => $request->input('reason', ''),
            'decision_scope' => 'ugda',
        ], [
            'view_mode' => 'detail',
            'show_print_card' => false,
            'box_display_state' => 'collapsed',
        ], 'decision', true);
    }

    private function changeWorkflow(
        Request $request,
        string $number,
        string $statusCode,
        string $eventTitle,
        string $description,
        ?array $context = null,
        array $attributes = [],
        string $eventType = 'status',
        bool $requireMessage = false
    ): JsonResponse {
        if (!$this->canUgdaManage($request)) {
            return response()->json([
                'message' => 'Solo UGDA puede realizar esta accion.',
            ], 403);
        }

        $rules = $requireMessage ? ['message' => ['sometimes', 'nullable', 'string', 'max:1000'], 'reason' => ['sometimes', 'nullable', 'string', 'max:1000']] : [];
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos enviados no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($requireMessage && blank($description)) {
            return response()->json([
                'message' => 'Debe proporcionar un comentario para esta accion.',
            ], 422);
        }

        try {
            $transfer = Transfer::query()->where('code', $number)->first();

            if ($transfer === null) {
                return response()->json(['message' => 'Transferencia no encontrada.'], 404);
            }

            DB::transaction(function () use ($request, $transfer, $statusCode, $eventTitle, $description, $context, $attributes, $eventType) {
                $transfer->update(array_merge([
                    'workflow_status_id' => RequestStatusCatalog::query()->where('code', $statusCode)->value('id'),
                ], $attributes));

                $transfer->events()->create([
                    'status_catalog_id' => RequestStatusCatalog::query()->where('code', $statusCode)->value('id'),
                    'actor_user_id' => $request->user()->id,
                    'event_type' => $eventType,
                    'title' => $eventTitle,
                    'description' => $description,
                    'context' => $context,
                    'occurred_at' => now(),
                ]);
            });

            $notificationService = app(SystemNotificationService::class);
            $freshTransfer = $transfer->fresh();

            match ($statusCode) {
                'transfer_status_observed' => $notificationService->transferObserved($freshTransfer, $request->user(), $description),
                'transfer_status_denied' => $notificationService->transferDeniedByUgda($freshTransfer, $request->user(), $description),
                'transfer_status_scheduled' => $notificationService->transferScheduled($freshTransfer, $request->user()),
                default => null,
            };

            return response()->json([
                'message' => 'Estado de la transferencia actualizado correctamente.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo actualizar la transferencia.',
            ], 500);
        }
    }
}
