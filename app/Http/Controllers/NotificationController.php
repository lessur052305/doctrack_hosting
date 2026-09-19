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
        // Real page route, not the implicit current-request path — this
        // list is also built from within listRefresh() (the live-poll
        // fragment route); see AdminController::paginateContainers()'s
        // docblock for the full reasoning.
        $notifications = $request->user()->notifications()->with('document')->paginate(10)
            ->withPath(route('notifications.index'));

        return view('notifications.index', compact('notifications'));
    }

    /**
     * The full Notifications page's list fragment (notifications/partials/
     * list.blade.php) — fetched live whenever a new notification arrives
     * for this user (see index.blade.php's script) so the page updates
     * without a manual reload, distinct from refresh() below which only
     * ever serves the bell dropdown's smaller unread-only preview.
     */
    public function listRefresh(Request $request)
    {
        $notifications = $request->user()->notifications()->with('document')->paginate(10)
            ->withPath(route('notifications.index'));

        return view('notifications.partials.list', compact('notifications'));
    }

    /**
     * Lightweight JSON endpoint the notification bell polls every ~5-10s
     * (see startLivePoll() in resources/js/app.js, wired up in the bell's
     * own markup since it appears on every page, not just one dashboard).
     */
    public function poll(Request $request)
    {
        return response()->json([
            'unread_count' => $request->user()->notifications()->where('is_read', false)->count(),
        ]);
    }

    /**
     * Renders just the bell's inner content (notifications/partials/bell.blade.php)
     * for the live-poll JS to swap in place — see components/notification-bell.blade.php's
     * docblock for why only the <details> tag's children are swapped, never
     * the tag itself (preserves open/closed state across the swap).
     */
    public function refresh(Request $request)
    {
        // Recent, regardless of read status — read notifications used to
        // be filtered out here entirely, so marking them read (via the
        // bell opening, or clicking one) made them vanish from the
        // dropdown instead of just losing their unread styling.
        $recent = $request->user()->notifications()->limit(6)->get();
        $unreadCount = $request->user()->notifications()->where('is_read', false)->count();

        return view('notifications.partials.bell', compact('recent', 'unreadCount'));
    }

    /**
     * Marks just this one notification read and sends the user to
     * whatever it's about — the bell dropdown and the full notifications
     * list both submit here as a plain form post, so clicking a
     * notification IS how it gets marked read, not a separate "Mark read"
     * control next to it.
     *
     * An approver has no per-document tracking page — they work a shared,
     * paginated queue (see ApprovalController::buildQueue()) rather than a
     * document detail route — so a plain route('approver.dashboard')
     * (NotificationRecord::targetUrl()'s fallback) just dumps them at the
     * top of page 1 with no indication of where the actual document is,
     * possibly several pages deep. This instead finds which page the
     * document is CURRENTLY on (ApprovalController::pageForDocument(),
     * same ordering the queue itself renders with) and appends a
     * #document-{id} anchor to it — the fragment id already rendered on
     * every document card in queue.blade.php — so the browser lands on
     * the right page AND scrolls straight to that document, same "click,
     * land exactly there" pattern the ML Training page's jump-nav uses,
     * with a computed page number standing in for that page's fixed
     * single-page anchor since this list is paginated. Falls back to the
     * plain queue link if the document is no longer in this approver's
     * queue at all (already resolved and no longer in-flight).
     */
    public function markRead(Request $request, NotificationRecord $notification)
    {
        $this->authorize('markRead', $notification);

        $notification->update(['is_read' => true]);

        $user = $request->user();

        if ($user->isApprover() && $notification->document_id) {
            $page = app(ApprovalController::class)->pageForDocument($user->user_id, $notification->document_id);

            if ($page !== null) {
                $url = route('approver.dashboard', $page > 1 ? ['page' => $page] : []);

                return redirect($url . '#document-' . $notification->document_id);
            }
        }

        return redirect($notification->targetUrl($user) ?? route('notifications.index'));
    }

    /**
     * Fired automatically when the bell dropdown is opened (see the
     * 'toggle' listener in app.js), not from a visible button — reading
     * the dropdown IS the read receipt. Deliberately does not hide
     * anything; refresh()/index() already show recent notifications
     * regardless of read status, so this only clears their unread
     * styling/badge count.
     */
    public function markAllRead(Request $request)
    {
        $request->user()->notifications()->where('is_read', false)->update(['is_read' => true]);

        return response()->noContent();
    }
}
