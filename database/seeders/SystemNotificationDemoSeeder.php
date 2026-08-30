<?php

namespace Database\Seeders;

use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Database\Seeder;

class SystemNotificationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $recipients = User::query()
            ->whereIn('email', [
                'admin-ugda@yopmail.com',
                'usuario.ugda@yopmail.com',
            ])
            ->get();

        $actor = User::query()
            ->where('email', 'unidad.solicitante@yopmail.com')
            ->first();

        foreach ($recipients as $recipient) {
            SystemNotification::query()
                ->where('user_id', $recipient->id)
                ->where('metadata->demo_batch', 'notifications-preview')
                ->delete();

            foreach ($this->demoNotifications() as $index => $notification) {
                SystemNotification::query()->create(array_merge($notification, [
                    'user_id' => $recipient->id,
                    'actor_user_id' => $actor?->id,
                    'metadata' => [
                        'demo_batch' => 'notifications-preview',
                        'demo_index' => $index + 1,
                    ],
                    'created_at' => now()->subMinutes(($index + 1) * 9),
                    'updated_at' => now()->subMinutes(($index + 1) * 9),
                    'read_at' => $index >= 9 ? now()->subMinutes(($index + 1) * 5) : null,
                ]));
            }
        }
    }

    private function demoNotifications(): array
    {
        return [
            $this->notification('transfer_pending_ugda_review', 'Transferencia autorizada', 'Transferencia #TRF-2025-00008 lista para revision UGDA.', 'La jefatura autorizo la transferencia documental de Decanato FIA. UGDA debe revisar el detalle y programar la entrega fisica.', 'info', '/solicitudes/transferencias/TRF-2025-00008', 'transfer', 'TRF-2025-00008'),
            $this->notification('transfer_observed', 'Transferencia observada', 'Transferencia #TRF-2025-00005 necesita correcciones.', 'UGDA registro observaciones sobre inventario y firmas requeridas. La unidad solicitante debe corregir y reenviar.', 'warning', '/solicitudes/transferencias/TRF-2025-00005', 'transfer', 'TRF-2025-00005'),
            $this->notification('loan_pending_search', 'Prestamo autorizado', 'Prestamo #PRT-2026-00004 listo para busqueda.', 'La solicitud fue autorizada. UGDA debe iniciar la busqueda fisica de los documentos solicitados.', 'info', '/solicitudes/prestamos/PRT-2026-00004', 'loan', 'PRT-2026-00004'),
            $this->notification('transfer_pending_unit_authorization', 'Pendiente de jefatura', 'Transferencia #TRF-2025-00003 requiere autorización.', 'La unidad productora envio una transferencia documental pendiente de revision por jefatura.', 'warning', '/solicitudes/transferencias/TRF-2025-00003', 'transfer', 'TRF-2025-00003'),
            $this->notification('loan_pending_delivery', 'Busqueda finalizada', 'Prestamo #PRT-2026-00006 listo para entrega.', 'Los documentos ya fueron localizados. Registre el prestamo cuando se entreguen al solicitante.', 'success', '/solicitudes/prestamos/PRT-2026-00006', 'loan', 'PRT-2026-00006'),
            $this->notification('transfer_scheduled', 'Transferencia agendada', 'Transferencia #TRF-2025-00002 fue agendada.', 'UGDA programo la entrega fisica. Revise fecha y prepare las cajas indicadas.', 'info', '/solicitudes/transferencias/TRF-2025-00002', 'transfer', 'TRF-2025-00002'),
            $this->notification('loan_observed', 'Prestamo observado', 'Prestamo #PRT-2026-00002 necesita revision.', 'UGDA solicito aclarar el alcance de los documentos requeridos antes de continuar.', 'warning', '/solicitudes/prestamos/PRT-2026-00002', 'loan', 'PRT-2026-00002'),
            $this->notification('transfer_denied_by_ugda', 'Transferencia denegada', 'Transferencia #TRF-2025-00006 fue denegada.', 'UGDA denego la transferencia porque la documentación no cumple los requisitos minimos.', 'danger', '/solicitudes/transferencias/TRF-2025-00006', 'transfer', 'TRF-2025-00006'),
            $this->notification('loan_search_started', 'Busqueda iniciada', 'Prestamo #PRT-2026-00003 esta en busqueda.', 'UGDA inicio la localizacion fisica de los expedientes solicitados.', 'info', '/solicitudes/prestamos/PRT-2026-00003', 'loan', 'PRT-2026-00003'),
            $this->notification('transfer_completed', 'Transferencia completada', 'Transferencia #TRF-2025-00001 fue completada.', 'Las ubicaciones fisicas fueron asignadas y el proceso de transferencia quedo cerrado.', 'success', '/solicitudes/transferencias/TRF-2025-00001', 'transfer', 'TRF-2025-00001'),
            $this->notification('loan_registered', 'Prestamo registrado', 'Prestamo #PRT-2026-00007 fue entregado.', 'UGDA registro la entrega de documentos con fecha de devolución programada.', 'success', '/solicitudes/prestamos/PRT-2026-00007', 'loan', 'PRT-2026-00007'),
            $this->notification('transfer_denied_by_unit', 'Transferencia denegada', 'Transferencia #TRF-2025-00004 fue denegada por jefatura.', 'La jefatura de unidad denego la solicitud por documentación incompleta.', 'danger', '/solicitudes/transferencias/TRF-2025-00004', 'transfer', 'TRF-2025-00004'),
            $this->notification('loan_returned', 'Devolución registrada', 'Prestamo #PRT-2026-00008 tiene devolución registrada.', 'Los documentos del prestamo fueron devueltos y verificados por UGDA.', 'success', '/solicitudes/prestamos/PRT-2026-00008', 'loan', 'PRT-2026-00008'),
            $this->notification('transfer_pending_ugda_review', 'Revision pendiente', 'Transferencia #TRF-2026-00001 espera gestion UGDA.', 'La solicitud ya fue autorizada por jefatura y requiere continuar el flujo interno.', 'info', '/solicitudes/transferencias/TRF-2026-00001', 'transfer', 'TRF-2026-00001'),
            $this->notification('loan_denied', 'Prestamo denegado', 'Prestamo #PRT-2026-00009 fue denegado.', 'UGDA denego la solicitud porque los documentos estan en proceso de digitalizacion.', 'danger', '/solicitudes/prestamos/PRT-2026-00009', 'loan', 'PRT-2026-00009'),
            $this->notification('transfer_pending_unit_authorization', 'Nueva transferencia', 'Transferencia #TRF-2026-00002 pendiente de autorización.', 'Una nueva solicitud de transferencia fue registrada y espera decision de jefatura.', 'warning', '/solicitudes/transferencias/TRF-2026-00002', 'transfer', 'TRF-2026-00002'),
        ];
    }

    private function notification(
        string $type,
        string $title,
        string $shortMessage,
        string $body,
        string $tone,
        string $actionUrl,
        string $referenceType,
        string|int $referenceNumber
    ): array {
        return [
            'type' => $type,
            'title' => $title,
            'short_message' => $shortMessage,
            'body' => $body,
            'tone' => $tone,
            'action_url' => $actionUrl,
            'reference_type' => $referenceType,
            'reference_id' => is_numeric($referenceNumber) ? (int) $referenceNumber : null,
            'reference_number' => (string) $referenceNumber,
        ];
    }
}
