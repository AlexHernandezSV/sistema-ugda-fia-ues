<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\SystemNotification;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Collection;

class SystemNotificationService
{
    public function notifyUsers(iterable $users, ?User $actor, array $payload): Collection
    {
        $actorId = $actor?->id;
        $recipientIds = collect($users)
            ->map(fn ($user) => $user instanceof User ? $user->id : (int) $user)
            ->filter(fn ($id) => $id > 0 && $id !== $actorId)
            ->unique()
            ->values();

        return $recipientIds->map(fn ($userId) => SystemNotification::query()->create([
            'user_id' => $userId,
            'actor_user_id' => $actorId,
            'type' => $payload['type'],
            'title' => $payload['title'],
            'short_message' => $payload['short_message'],
            'body' => $payload['body'] ?? null,
            'tone' => $payload['tone'] ?? 'info',
            'action_url' => $payload['action_url'] ?? null,
            'reference_type' => $payload['reference_type'] ?? null,
            'reference_id' => $payload['reference_id'] ?? null,
            'reference_number' => $payload['reference_number'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ]));
    }

    public function transferCreated(Transfer $transfer, User $actor): void
    {
        $transfer->loadMissing('unit');

        $this->notifyUsers($this->unitDirectors($transfer->unit_id), $actor, [
            'type' => 'transfer_pending_unit_authorization',
            'title' => 'Transferencia pendiente de jefatura',
            'short_message' => 'Transferencia #' . $transfer->code . ' requiere autorizacion.',
            'body' => 'La unidad ' . ($transfer->unit?->name ?? 'solicitante') . ' envio una transferencia documental. Revise la solicitud para autorizarla o denegarla.',
            'tone' => 'warning',
            'action_url' => '/solicitudes/transferencias/' . $transfer->code,
            'reference_type' => 'transfer',
            'reference_id' => $transfer->id,
            'reference_number' => $transfer->code,
        ]);
    }

    public function transferAuthorizedByUnit(Transfer $transfer, User $actor): void
    {
        $transfer->loadMissing('unit');

        $this->notifyUsers($this->ugdaUsers(), $actor, [
            'type' => 'transfer_pending_ugda_review',
            'title' => 'Transferencia autorizada',
            'short_message' => 'Transferencia #' . $transfer->code . ' lista para revision UGDA.',
            'body' => 'La jefatura autorizo la transferencia de ' . ($transfer->unit?->name ?? 'la unidad productora') . '. UGDA debe continuar con la revision y programacion.',
            'tone' => 'info',
            'action_url' => '/solicitudes/transferencias/' . $transfer->code,
            'reference_type' => 'transfer',
            'reference_id' => $transfer->id,
            'reference_number' => $transfer->code,
        ]);
    }

    public function transferDeniedByUnit(Transfer $transfer, User $actor, string $reason): void
    {
        $this->notifyUsers([$transfer->user_id], $actor, [
            'type' => 'transfer_denied_by_unit',
            'title' => 'Transferencia denegada',
            'short_message' => 'Transferencia #' . $transfer->code . ' fue denegada por jefatura.',
            'body' => 'La jefatura de unidad denego la transferencia. Motivo: ' . $reason,
            'tone' => 'danger',
            'action_url' => '/solicitudes/transferencias/' . $transfer->code,
            'reference_type' => 'transfer',
            'reference_id' => $transfer->id,
            'reference_number' => $transfer->code,
        ]);
    }

    public function transferObserved(Transfer $transfer, User $actor, string $message): void
    {
        $this->notifyUsers([$transfer->user_id], $actor, [
            'type' => 'transfer_observed',
            'title' => 'Transferencia observada',
            'short_message' => 'Transferencia #' . $transfer->code . ' necesita correcciones.',
            'body' => 'UGDA registro una observación para la transferencia. Revise el detalle y reenvie la corrección cuando este lista. Observación: ' . $message,
            'tone' => 'warning',
            'action_url' => '/solicitudes/transferencias/' . $transfer->code,
            'reference_type' => 'transfer',
            'reference_id' => $transfer->id,
            'reference_number' => $transfer->code,
        ]);
    }

    public function transferResubmitted(Transfer $transfer, User $actor): void
    {
        $this->transferCreated($transfer, $actor);
    }

    public function transferCorrectionSubmitted(Transfer $transfer, User $actor): void
    {
        $transfer->loadMissing('unit');

        $this->notifyUsers($this->ugdaUsers(), $actor, [
            'type' => 'transfer_correction_submitted',
            'title' => 'Transferencia subsanada',
            'short_message' => 'Transferencia #' . $transfer->code . ' fue reenviada con correcciones.',
            'body' => 'La unidad ' . ($transfer->unit?->name ?? 'solicitante') . ' corrigio observaciones de revision fisica. UGDA debe iniciar una nueva revision para validar los cambios.',
            'tone' => 'info',
            'action_url' => '/solicitudes/transferencias/' . $transfer->code,
            'reference_type' => 'transfer',
            'reference_id' => $transfer->id,
            'reference_number' => $transfer->code,
        ]);
    }

    public function transferScheduled(Transfer $transfer, User $actor): void
    {
        $this->notifyUsers([$transfer->user_id], $actor, [
            'type' => 'transfer_scheduled',
            'title' => 'Transferencia agendada',
            'short_message' => 'Transferencia #' . $transfer->code . ' fue agendada.',
            'body' => 'UGDA agendo la entrega fisica de la transferencia. Revise el detalle para preparar la documentación correspondiente.',
            'tone' => 'info',
            'action_url' => '/solicitudes/transferencias/' . $transfer->code,
            'reference_type' => 'transfer',
            'reference_id' => $transfer->id,
            'reference_number' => $transfer->code,
        ]);
    }

    public function transferCompleted(Transfer $transfer, User $actor): void
    {
        $this->notifyUsers([$transfer->user_id], $actor, [
            'type' => 'transfer_completed',
            'title' => 'Transferencia completada',
            'short_message' => 'Transferencia #' . $transfer->code . ' fue completada.',
            'body' => 'UGDA asigno ubicaciones fisicas y completo el proceso de transferencia documental.',
            'tone' => 'success',
            'action_url' => '/solicitudes/transferencias/' . $transfer->code,
            'reference_type' => 'transfer',
            'reference_id' => $transfer->id,
            'reference_number' => $transfer->code,
        ]);
    }

    public function transferDeniedByUgda(Transfer $transfer, User $actor, string $reason): void
    {
        $this->notifyUsers([$transfer->user_id], $actor, [
            'type' => 'transfer_denied_by_ugda',
            'title' => 'Transferencia denegada por UGDA',
            'short_message' => 'Transferencia #' . $transfer->code . ' fue denegada.',
            'body' => 'UGDA denegó la transferencia documental. Motivo: ' . $reason,
            'tone' => 'danger',
            'action_url' => '/solicitudes/transferencias/' . $transfer->code,
            'reference_type' => 'transfer',
            'reference_id' => $transfer->id,
            'reference_number' => $transfer->code,
        ]);
    }

    public function loanStatusChanged(Loan $loan, User $actor, string $workflowCode, string $description): void
    {
        match ($workflowCode) {
            'loan_status_authorized' => $this->notifyUsers($this->ugdaUsers(), $actor, [
                'type' => 'loan_pending_search',
                'title' => 'Prestamo autorizado',
                'short_message' => 'Prestamo #' . $loan->number . ' listo para busqueda.',
                'body' => 'La solicitud de prestamo fue autorizada. UGDA debe iniciar o continuar la busqueda de documentos.',
                'tone' => 'info',
                'action_url' => '/solicitudes/prestamos/' . $loan->number,
                'reference_type' => 'loan',
                'reference_id' => $loan->id,
                'reference_number' => $loan->number,
            ]),
            'loan_status_observed' => $this->notifyUsers([$loan->user_id], $actor, [
                'type' => 'loan_observed',
                'title' => 'Prestamo observado',
                'short_message' => 'Prestamo #' . $loan->number . ' necesita revision.',
                'body' => 'UGDA registro una observación en la solicitud de prestamo: ' . $description,
                'tone' => 'warning',
                'action_url' => '/solicitudes/prestamos/' . $loan->number,
                'reference_type' => 'loan',
                'reference_id' => $loan->id,
                'reference_number' => $loan->number,
            ]),
            'loan_status_denied' => $this->notifyUsers([$loan->user_id], $actor, [
                'type' => 'loan_denied',
                'title' => 'Prestamo denegado',
                'short_message' => 'Prestamo #' . $loan->number . ' fue denegado.',
                'body' => $description,
                'tone' => 'danger',
                'action_url' => '/solicitudes/prestamos/' . $loan->number,
                'reference_type' => 'loan',
                'reference_id' => $loan->id,
                'reference_number' => $loan->number,
            ]),
            default => null,
        };
    }

    public function loanCreated(Loan $loan, User $actor): void
    {
        $loan->loadMissing('unit');

        $this->notifyUsers($this->unitDirectors($loan->unit_id), $actor, [
            'type' => 'loan_pending_unit_authorization',
            'title' => 'Prestamo pendiente de jefatura',
            'short_message' => 'Prestamo #' . $loan->number . ' requiere autorizacion.',
            'body' => 'La unidad ' . ($loan->unit?->name ?? 'solicitante') . ' envio una solicitud de prestamo documental. Revise la solicitud para autorizarla o denegarla.',
            'tone' => 'warning',
            'action_url' => '/solicitudes/prestamos/' . $loan->number,
            'reference_type' => 'loan',
            'reference_id' => $loan->id,
            'reference_number' => $loan->number,
        ]);
    }

    public function loanAuthorizedByUnit(Loan $loan, User $actor): void
    {
        $loan->loadMissing('unit');

        $this->notifyUsers($this->ugdaUsers(), $actor, [
            'type' => 'loan_pending_search',
            'title' => 'Prestamo autorizado',
            'short_message' => 'Prestamo #' . $loan->number . ' listo para busqueda.',
            'body' => 'La jefatura autorizo el prestamo de ' . ($loan->unit?->name ?? 'la unidad solicitante') . '. UGDA debe iniciar la busqueda de documentos.',
            'tone' => 'info',
            'action_url' => '/solicitudes/prestamos/' . $loan->number,
            'reference_type' => 'loan',
            'reference_id' => $loan->id,
            'reference_number' => $loan->number,
        ]);
    }

    public function loanSearchStarted(Loan $loan, User $actor): void
    {
        $this->notifyUsers([$loan->user_id], $actor, [
            'type' => 'loan_search_started',
            'title' => 'Busqueda iniciada',
            'short_message' => 'Prestamo #' . $loan->number . ' esta en busqueda.',
            'body' => 'UGDA inicio la busqueda y localizacion fisica de los documentos solicitados.',
            'tone' => 'info',
            'action_url' => '/solicitudes/prestamos/' . $loan->number,
            'reference_type' => 'loan',
            'reference_id' => $loan->id,
            'reference_number' => $loan->number,
        ]);
    }

    public function loanSearchFinished(Loan $loan, User $actor): void
    {
        $loan->loadMissing('searchStatus');

        if ($loan->searchStatus?->code === 'loan_search_not_found') {
            $this->notifyUsers([$loan->user_id], $actor, [
                'type' => 'loan_search_not_found',
                'title' => 'Busqueda sin resultados',
                'short_message' => 'Prestamo #' . $loan->number . ' no tuvo documentos encontrados.',
                'body' => 'UGDA finalizo la busqueda, pero los documentos solicitados no fueron encontrados en el archivo. El flujo de esta solicitud ha finalizado.',
                'tone' => 'danger',
                'action_url' => '/solicitudes/prestamos/' . $loan->number,
                'reference_type' => 'loan',
                'reference_id' => $loan->id,
                'reference_number' => $loan->number,
            ]);

            return;
        }

        $this->notifyUsers([$loan->user_id], $actor, [
            'type' => 'loan_search_completed',
            'title' => 'Busqueda finalizada',
            'short_message' => 'Prestamo #' . $loan->number . ' tiene documentos encontrados.',
            'body' => 'UGDA finalizo la busqueda de los documentos solicitados. Revise el detalle de la solicitud para consultar los documentos encontrados.',
            'tone' => 'success',
            'action_url' => '/solicitudes/prestamos/' . $loan->number,
            'reference_type' => 'loan',
            'reference_id' => $loan->id,
            'reference_number' => $loan->number,
        ]);

        $this->notifyUsers($this->ugdaUsers(), $actor, [
            'type' => 'loan_pending_delivery',
            'title' => 'Busqueda finalizada',
            'short_message' => 'Prestamo #' . $loan->number . ' listo para entrega.',
            'body' => 'La busqueda de documentos finalizo. Registre el prestamo cuando los documentos sean entregados.',
            'tone' => 'success',
            'action_url' => '/solicitudes/prestamos/' . $loan->number,
            'reference_type' => 'loan',
            'reference_id' => $loan->id,
            'reference_number' => $loan->number,
        ]);
    }

    public function loanRegistered(Loan $loan, User $actor): void
    {
        $this->notifyUsers([$loan->user_id], $actor, [
            'type' => 'loan_registered',
            'title' => 'Prestamo registrado',
            'short_message' => 'Prestamo #' . $loan->number . ' fue entregado.',
            'body' => 'UGDA registro la entrega de documentos. Revise el detalle para consultar fechas y condiciones del prestamo.',
            'tone' => 'success',
            'action_url' => '/solicitudes/prestamos/' . $loan->number,
            'reference_type' => 'loan',
            'reference_id' => $loan->id,
            'reference_number' => $loan->number,
        ]);
    }

    public function loanReturned(Loan $loan, User $actor): void
    {
        $this->notifyUsers([$loan->user_id], $actor, [
            'type' => 'loan_returned',
            'title' => 'Devolución registrada',
            'short_message' => 'Prestamo #' . $loan->number . ' tiene devolución registrada.',
            'body' => 'UGDA registro la devolución de documentos del prestamo.',
            'tone' => 'success',
            'action_url' => '/solicitudes/prestamos/' . $loan->number,
            'reference_type' => 'loan',
            'reference_id' => $loan->id,
            'reference_number' => $loan->number,
        ]);
    }

    private function unitDirectors(int $unitId): Collection
    {
        return $this->usersByProfiles(['Director/Jefe de Unidad'], $unitId);
    }

    private function ugdaUsers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereHas('profiles', function ($profileQuery) {
                    $profileQuery->whereIn('profiles.name', ['Administrador', 'Usuario UGDA'])
                        ->where('user_profile.is_active', true)
                        ->whereNull('user_profile.deleted_at');
                })->orWhereHas('units', function ($unitQuery) {
                    $unitQuery->where('units.code', 'UGDA')
                        ->whereNull('user_unit.deleted_at');
                });
            })
            ->get();
    }

    private function usersByProfiles(array $profileNames, ?int $unitId = null): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('profiles', function ($profileQuery) use ($profileNames) {
                $profileQuery->whereIn('profiles.name', $profileNames)
                    ->where('user_profile.is_active', true)
                    ->whereNull('user_profile.deleted_at');
            })
            ->when($unitId !== null, function ($query) use ($unitId) {
                $query->whereHas('units', function ($unitQuery) use ($unitId) {
                    $unitQuery->where('units.id', $unitId)
                        ->whereNull('user_unit.deleted_at');
                });
            })
            ->get();
    }
}
