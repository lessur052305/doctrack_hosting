<?php

namespace App\Http\Controllers;

use App\Models\NotificationRecord;
use Illuminate\Http\Request;

/**
 * NotificationController
 * -----------------------
 * Section 3: In-app Notification Center. NotificationRecord::send() has
 * always written rows across the app (submission alerts, decision
 * alerts, SLA escalations, etc.) but nothing previously read them back —
 * this exposes that history to whichever role the recipient is.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications()->with('document')->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    public function markRead(Request $request, NotificationRecord $notification)
    {
        abort_unless($notification->recipient_id === $request->user()->user_id, 403);

        $notification->update(['is_read' => true]);

        return back();
    }

    public function markAllRead(Request $request)
    {
        $request->user()->notifications()->where('is_read', false)->update(['is_read' => true]);

        return back();
    }
}
