<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\RequestStatusCatalog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LoanController extends Controller
{
    public function authorizeLoan(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de autorizacion no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $comments = trim($request->string('comments')->toString());

        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_authorized',
            eventTitle: 'Autorizado UGDA',
            description: $comments !== ''
                ? 'Solicitud autorizada por UGDA para iniciar busqueda de documentos. Comentario: ' . $comments
                : 'Solicitud autorizada por UGDA para iniciar busqueda de documentos.',
            updater: fn (Loan $loan) => [
                'ugda_authorized_by_user_id' => $request->user()->id,
                'ugda_authorized_at' => now(),
            ],
            context: $comments !== '' ? ['comments' => $comments] : null,
        );
    }

    public function observe(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe ingresar la observacion de la solicitud.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_observed',
            eventTitle: 'Observacion UGDA',
            description: $request->string('message')->toString(),
            eventType: 'observation'
        );
    }

    public function deny(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Debe ingresar el motivo de rechazo.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_denied',
            eventTitle: 'Denegado por UGDA',
            description: 'Solicitud denegada por UGDA. Motivo: ' . $request->string('reason')->toString(),
            eventType: 'decision',
            context: [
                'reason_label' => 'Motivo de rechazo:',
                'reason' => $request->string('reason')->toString(),
                'decision_scope' => 'ugda',
            ]
        );
    }

    public function startSearch(Request $request, string $number): JsonResponse
    {
        return $this->simpleStateChange(
            request: $request,
            number: $number,
            workflowCode: 'loan_status_authorized',
            eventTitle: 'Busqueda en proceso',
            description: 'UGDA ha iniciado el proceso de busqueda y localizacion fisica de los documentos solicitados.',
            updater: fn (Loan $loan) => [
                'search_status_id' => $this->statusId('loan_search_in_progress'),
                'search_started_by_user_id' => $request->user()->id,
                'search_started_at' => now(),
            ]
        );
    }

    public function finishSearch(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'comments' => ['nullable', 'string', 'max:1000'],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la busqueda no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $loan = Loan::query()->with('documents')->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Prestamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan) {
                $selectedIds = collect($request->input('document_ids', []))->map(fn ($id) => (int) $id)->all();
                $loan->documents()->update(['found_in_search' => false]);

                if ($selectedIds !== []) {
                    $loan->documents()->whereIn('id', $selectedIds)->update(['found_in_search' => true]);
                }

                $loan->update([
                    'search_status_id' => $this->statusId('loan_search_completed'),
                    'search_completed_by_user_id' => $request->user()->id,
                    'search_completed_at' => now(),
                    'search_comments' => $request->input('comments'),
                ]);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId('loan_search_completed'),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'status',
                    'title' => 'Busqueda finalizada',
                    'description' => 'La busqueda de los documentos ha sido completada. Documentos listos para retiro.',
                    'context' => ['comments' => $request->input('comments')],
                    'occurred_at' => now(),
                ]);
            });

            return response()->json(['message' => 'Busqueda finalizada correctamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo finalizar la busqueda.',
            ], 500);
        }
    }

    public function registerLoan(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'selected_ids' => ['required', 'array', 'min:1'],
            'selected_ids.*' => ['integer'],
            'loan_date' => ['required', 'date_format:d/m/Y'],
            'due_date' => ['required', 'date_format:d/m/Y'],
            'received_by' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos del prestamo no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $loan = Loan::query()->with('documents')->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Prestamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan) {
                $selectedIds = collect($request->input('selected_ids'))->map(fn ($id) => (int) $id)->all();
                $dispatch = $loan->dispatches()->create([
                    'loan_date' => Carbon::createFromFormat('d/m/Y', $request->string('loan_date')->toString())->toDateString(),
                    'due_date' => Carbon::createFromFormat('d/m/Y', $request->string('due_date')->toString())->toDateString(),
                    'received_by_name' => $request->string('received_by')->toString(),
                    'delivered_by_user_id' => $request->user()->id,
                    'observations' => $request->input('observations'),
                ]);

                foreach ($selectedIds as $documentId) {
                    $dispatch->items()->create(['loan_document_id' => $documentId]);
                }

                $loan->documents()->whereIn('id', $selectedIds)->update([
                    'selected_for_loan' => true,
                ]);

                $loan->update([
                    'workflow_status_id' => $this->statusId('loan_status_loaned'),
                ]);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId('loan_status_loaned'),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'status',
                    'title' => 'Prestado',
                    'description' => 'Documentos entregados a ' . $request->string('received_by')->toString() . '. Fecha de devolucion: ' . $request->string('due_date')->toString(),
                    'context' => null,
                    'occurred_at' => now(),
                ]);
            });

            return response()->json(['message' => 'Prestamo registrado correctamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo registrar el prestamo.',
            ], 500);
        }
    }

    public function registerReturn(Request $request, string $number): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'selected_ids' => ['required', 'array', 'min:1'],
            'selected_ids.*' => ['integer'],
            'return_date' => ['required', 'date_format:d/m/Y'],
            'condition' => ['required', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Los datos de la devolucion no son validos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $loan = Loan::query()->with('documents')->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Prestamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan) {
                $selectedIds = collect($request->input('selected_ids'))->map(fn ($id) => (int) $id)->all();
                $return = $loan->returns()->create([
                    'return_date' => Carbon::createFromFormat('d/m/Y', $request->string('return_date')->toString())->toDateString(),
                    'received_by_user_id' => $request->user()->id,
                    'condition_label' => $request->string('condition')->toString(),
                    'observations' => $request->input('observations'),
                ]);

                foreach ($selectedIds as $documentId) {
                    $return->items()->create(['loan_document_id' => $documentId]);
                }

                $loan->documents()->whereIn('id', $selectedIds)->update([
                    'returned' => true,
                ]);

                $hasPending = $loan->documents()->where('selected_for_loan', true)->where('returned', false)->exists();
                $loan->update([
                    'workflow_status_id' => $this->statusId($hasPending ? 'loan_status_loaned' : 'loan_status_returned'),
                ]);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId($hasPending ? 'loan_status_loaned' : 'loan_status_returned'),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => 'status',
                    'title' => 'Devuelto',
                    'description' => 'Documentos devueltos por ' . $this->userName($request->user()) . '. ' . $request->string('condition')->toString(),
                    'context' => null,
                    'occurred_at' => now(),
                ]);
            });

            return response()->json(['message' => 'Devolucion registrada correctamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo registrar la devolucion.',
            ], 500);
        }
    }

    private function simpleStateChange(
        Request $request,
        string $number,
        string $workflowCode,
        string $eventTitle,
        string $description,
        ?callable $updater = null,
        string $eventType = 'status',
        ?array $context = null
    ): JsonResponse {
        try {
            $loan = Loan::query()->where('number', $number)->first();

            if ($loan === null) {
                return response()->json(['message' => 'Prestamo no encontrado.'], 404);
            }

            DB::transaction(function () use ($request, $loan, $workflowCode, $eventTitle, $description, $updater, $eventType, $context) {
                $attributes = ['workflow_status_id' => $this->statusId($workflowCode)];

                if ($updater !== null) {
                    $attributes = array_merge($attributes, $updater($loan));
                }

                $loan->update($attributes);

                $loan->events()->create([
                    'status_catalog_id' => $this->statusId($workflowCode),
                    'actor_user_id' => $request->user()->id,
                    'actor_name_snapshot' => null,
                    'event_type' => $eventType,
                    'title' => $eventTitle,
                    'description' => $description,
                    'context' => $context,
                    'occurred_at' => now(),
                ]);
            });

            return response()->json(['message' => 'Estado del prestamo actualizado correctamente.']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo actualizar el prestamo.',
            ], 500);
        }
    }

    private function statusId(string $code): ?int
    {
        return RequestStatusCatalog::query()->where('code', $code)->value('id');
    }

    private function userName($user): string
    {
        $parts = array_filter([
            $user->person?->first_name,
            $user->person?->second_name,
            $user->person?->first_last_name,
            $user->person?->second_last_name,
        ]);

        return implode(' ', $parts);
    }
}
