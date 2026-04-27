<?php

namespace App\Http\Controllers;

use App\Models\RequestStatusCatalog;
use App\Models\Transfer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

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
            'boxes.*.documents_list.*.name' => ['required', 'string', 'max:255'],
            'boxes.*.documents_list.*.date' => ['required', 'string', 'max:50'],
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

                foreach ($request->input('boxes', []) as $boxIndex => $boxPayload) {
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
                        $box->documents()->create([
                            'code' => 'DOC-' . $transfer->code . '-' . str_pad((string) ($documentIndex + 1), 3, '0', STR_PAD_LEFT),
                            'name' => $documentPayload['name'],
                            'series_label' => $boxPayload['series'],
                            'support_type' => $documentPayload['support'],
                            'year_label' => $documentPayload['date'],
                            'pages_label' => null,
                            'digital_file_name' => $documentPayload['file_name'] ?? null,
                            'digital_file_path' => $documentPayload['file_name'] ? 'uploads/' . $documentPayload['file_name'] : null,
                            'sort_order' => $documentIndex + 1,
                        ]);
                    }
                }

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
