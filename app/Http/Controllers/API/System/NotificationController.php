<?php

namespace App\Http\Controllers\API\System;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\System\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Lista as notificações do utilizador autenticado (da escola ativa + globais).
     * Filtros: ?type=alert|absence|expiry|info &unread=1 &per_page=20 &page=1
     * `meta.unread_counts` traz as não lidas por categoria (para os badges do sino).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Notification::class);

        $user = $request->user();
        $schoolId = $request->attributes->get('school_id');

        $paginator = $this->notifications->paginateFor($user, $schoolId, [
            'type' => $request->query('type'),
            'unread' => $request->boolean('unread'),
            'per_page' => $request->query('per_page'),
        ]);

        return NotificationResource::collection($paginator)->additional([
            'meta' => ['unread_counts' => $this->notifications->unreadCounts($user, $schoolId)],
        ]);
    }

    public function show(Notification $notification): NotificationResource
    {
        Gate::authorize('view', $notification);

        return new NotificationResource($notification);
    }

    /**
     * PATCH /notifications/{notification}/read
     */
    public function markAsRead(Notification $notification): NotificationResource
    {
        Gate::authorize('update', $notification);

        return new NotificationResource($this->notifications->markAsRead($notification));
    }

    /**
     * POST /notifications/read-all  (opcional: ?type=alert para marcar só uma categoria)
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Notification::class);

        $user = $request->user();
        $schoolId = $request->attributes->get('school_id');

        $updated = $this->notifications->markAllAsRead($user, $schoolId, $request->input('type', $request->query('type')));

        return response()->json([
            'message' => 'Notificações marcadas como lidas.',
            'updated' => $updated,
            'unread_counts' => $this->notifications->unreadCounts($user, $schoolId),
        ]);
    }

    public function destroy(Notification $notification): JsonResponse
    {
        Gate::authorize('delete', $notification);

        $notification->delete();

        return response()->json(['message' => 'Notificação eliminada.']);
    }
}
