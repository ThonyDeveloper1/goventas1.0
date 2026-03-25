<?php

namespace App\Http\Controllers;

use App\Models\InternalNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /* ─────────────────────────────────────────────────────────
     |  GET /notifications
     ────────────────────────────────────────────────────────── */
    public function index(Request $request): JsonResponse
    {
        $notifications = InternalNotification::forUser($request->user()->id)
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($notifications);
    }

    /* ─────────────────────────────────────────────────────────
     |  GET /notifications/unread-count
     ────────────────────────────────────────────────────────── */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = InternalNotification::forUser($request->user()->id)
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /notifications/{id}/read
     ────────────────────────────────────────────────────────── */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = InternalNotification::forUser($request->user()->id)
            ->findOrFail($id);

        $notification->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /* ─────────────────────────────────────────────────────────
     |  POST /notifications/read-all
     ────────────────────────────────────────────────────────── */
    public function markAllRead(Request $request): JsonResponse
    {
        InternalNotification::forUser($request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
