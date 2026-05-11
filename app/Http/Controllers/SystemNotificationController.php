<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 8), 5), 30);
        $query = SystemNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest();

        if ($request->input('status') === 'unread') {
            $query->whereNull('read_at');
        } elseif ($request->input('status') === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        if ($request->filled('search')) {
            $search = '%' . trim($request->string('search')->toString()) . '%';
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('title', 'like', $search)
                    ->orWhere('short_message', 'like', $search)
                    ->orWhere('body', 'like', $search)
                    ->orWhere('reference_number', 'like', $search);
            });
        }

        $notifications = $query->paginate($perPage);
        $userId = $request->user()->id;

        return response()->json([
            'data' => $notifications->getCollection()->map(fn ($notification) => $this->serialize($notification))->values(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'from' => $notifications->firstItem(),
                'to' => $notifications->lastItem(),
            ],
            'summary' => [
                'unread_count' => SystemNotification::query()->where('user_id', $userId)->whereNull('read_at')->count(),
                'read_count' => SystemNotification::query()->where('user_id', $userId)->whereNotNull('read_at')->count(),
                'types' => SystemNotification::query()
                    ->where('user_id', $userId)
                    ->select('type')
                    ->distinct()
                    ->orderBy('type')
                    ->pluck('type')
                    ->map(fn ($type) => [
                        'value' => $type,
                        'label' => $this->typeLabel($type),
                    ])
                    ->values(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $query = SystemNotification::query()
            ->where('user_id', $request->user()->id);

        $latest = (clone $query)
            ->orderByRaw('read_at is null desc')
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn ($notification) => $this->serialize($notification));

        return response()->json([
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
            'latest' => $latest,
        ]);
    }

    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = SystemNotification::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json([
            'message' => 'Notificacion marcada como leida.',
            'notification' => $this->serialize($notification->fresh()),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        SystemNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Todas las notificaciones fueron marcadas como leidas.',
        ]);
    }

    private function serialize(SystemNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'type_label' => $this->typeLabel($notification->type),
            'title' => $notification->title,
            'short_message' => $notification->short_message,
            'body' => $notification->body,
            'tone' => $notification->tone,
            'action_url' => $notification->action_url,
            'reference_type' => $notification->reference_type,
            'reference_number' => $notification->reference_number,
            'is_read' => $notification->read_at !== null,
            'read_at' => optional($notification->read_at)->toISOString(),
            'read_at_label' => optional($notification->read_at)->format('d/m/Y H:i'),
            'created_at' => optional($notification->created_at)->toISOString(),
            'created_at_label' => optional($notification->created_at)->format('d/m/Y H:i'),
            'metadata' => $notification->metadata,
        ];
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'transfer_pending_unit_authorization' => 'Transferencia por autorizar',
            'transfer_pending_ugda_review' => 'Transferencia para UGDA',
            'transfer_denied_by_unit' => 'Transferencia denegada',
            'transfer_observed' => 'Transferencia observada',
            'transfer_scheduled' => 'Transferencia agendada',
            'transfer_completed' => 'Transferencia completada',
            'transfer_denied_by_ugda' => 'Transferencia denegada por UGDA',
            'loan_pending_search' => 'Prestamo para busqueda',
            'loan_pending_unit_authorization' => 'Prestamo por autorizar',
            'loan_observed' => 'Prestamo observado',
            'loan_denied' => 'Prestamo denegado',
            'loan_search_started' => 'Busqueda iniciada',
            'loan_pending_delivery' => 'Prestamo para entrega',
            'loan_registered' => 'Prestamo registrado',
            'loan_returned' => 'Devolucion registrada',
            default => 'Notificacion del sistema',
        };
    }
}
