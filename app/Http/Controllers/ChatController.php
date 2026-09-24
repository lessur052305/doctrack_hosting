<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\NotificationRecord;
use App\Models\User;
use App\Rules\ReliableMimeType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * ChatController
 * ---------------
 * In-system chat between any Originator/Approver and the single Admin
 * account (Feature: floating chat icon). No thread table — a
 * "conversation" is just every ChatMessage row between two users (see
 * ChatMessage::scopeThreadBetween()). For Originator/Approver the other
 * participant is always the Admin (there is exactly one); for Admin it's
 * whichever user the `with` query param names.
 */
class ChatController extends Controller
{
    /**
     * The other participant in "my" conversation. For Admin this is
     * whoever `with` names (validated active, non-admin); for anyone else
     * it's always User::adminAccount() — nothing they send can be
     * redirected to a different recipient by tampering with `with`.
     */
    private function otherUser(Request $request): User
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return User::where('is_active', true)->where('role', '!=', 'admin')
                ->whereKey($request->integer('with'))->firstOrFail();
        }

        return User::adminAccount() ?? abort(404, 'No Admin account is configured.');
    }

    /** Unread badge count for the floating icon — every role polls this the same way. */
    public function poll(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'unread_count' => ChatMessage::where('recipient_id', $user->user_id)->where('is_read', false)->count(),
        ]);
    }

    /**
     * Main panel fragment. Admin with no `with` param gets the full user
     * roster (Feature: every user appears, whether they've chatted or
     * not — see chat/partials/user-list.blade.php); everyone else, and
     * Admin WITH a `with` param, gets a thread. `view=media` swaps to the
     * photo gallery for whichever thread is open; `q` filters the open
     * thread by keyword.
     */
    public function refresh(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin() && !$request->filled('with')) {
            return $this->userList($user);
        }

        $other = $this->otherUser($request);

        return $request->get('view') === 'media'
            ? $this->mediaGallery($user, $other)
            : $this->thread($request, $user, $other);
    }

    private function userList(User $admin)
    {
        $users = User::where('role', '!=', 'admin')->where('is_active', true)->orderBy('full_name')->get();

        // Grouped by "the other participant" — there's no thread_id, so
        // this is the same (sender, recipient) => other-user-id logic
        // ChatMessage::scopeThreadBetween() encodes for a single pair,
        // done in bulk here for every user at once.
        $allMessages = ChatMessage::where('sender_id', $admin->user_id)->orWhere('recipient_id', $admin->user_id)
            ->orderByDesc('created_at')->get();
        $lastMessageByUser = $allMessages->groupBy(
            fn (ChatMessage $m) => $m->sender_id === $admin->user_id ? $m->recipient_id : $m->sender_id
        )->map->first();

        $unreadCounts = ChatMessage::where('recipient_id', $admin->user_id)->where('is_read', false)
            ->selectRaw('sender_id, count(*) as c')->groupBy('sender_id')->pluck('c', 'sender_id');

        // Messenger-style stacking: anyone with an actual conversation
        // floats to the top, most-recently-active first; everyone who's
        // never chatted stays below. $users was already alphabetical
        // (see the query above), and PHP's sort is stable since 8.0, so
        // that order survives as the tiebreaker among the never-chatted
        // ones (all sharing the same "no timestamp" key below).
        $users = $users->sortByDesc(
            fn (User $u) => $lastMessageByUser->get($u->user_id)?->created_at
        )->values();

        return view('chat.partials.user-list', [
            'users' => $users,
            'lastMessageByUser' => $lastMessageByUser,
            'unreadCounts' => $unreadCounts,
        ]);
    }

    private function thread(Request $request, User $user, User $other)
    {
        $query = ChatMessage::threadBetween($user->user_id, $other->user_id)->with(['sender'])->orderBy('created_at');

        $keyword = $request->string('q')->trim()->value();
        if ($keyword !== '') {
            $query->where('body', 'like', '%' . $keyword . '%');
        }

        return view('chat.partials.thread', [
            'messages' => $query->get(),
            'other' => $other,
            'keyword' => $keyword,
        ]);
    }

    private function mediaGallery(User $user, User $other)
    {
        $images = ChatMessage::threadBetween($user->user_id, $other->user_id)
            ->whereNotNull('attachment_path')->orderByDesc('created_at')->get();

        return view('chat.partials.media', ['images' => $images, 'other' => $other]);
    }

    public function send(Request $request)
    {
        $user = $request->user();
        $recipient = $this->otherUser($request);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg', new ReliableMimeType(), 'max:5120'],
        ]);

        abort_if(blank($validated['body'] ?? null) && !$request->hasFile('image'), 422, 'A message needs text or an image.');

        $attachmentPath = null;
        $attachmentMime = null;
        if ($request->hasFile('image')) {
            // Same disk-agnostic store() convention as document uploads
            // (WorkflowService::ingest()) — no malware-scan call here: it
            // only inspects Office-document macros, a genuine no-op for
            // images (see the feature's own plan discussion), so calling
            // it would just be dead weight.
            $attachmentPath = $request->file('image')->store('chat-images');
            $attachmentMime = $request->file('image')->getMimeType();
        }

        ChatMessage::create([
            'sender_id' => $user->user_id,
            'recipient_id' => $recipient->user_id,
            'body' => $validated['body'] ?? null,
            'attachment_path' => $attachmentPath,
            'attachment_mime' => $attachmentMime,
        ]);

        event(new ChatMessageSent($recipient->user_id));
        NotificationRecord::send($recipient->user_id, null, "New message from {$user->full_name}.");

        return response()->json(['status' => 'sent']);
    }

    /**
     * Marks a whole thread read when it's opened. $otherUser is route-
     * model-bound but not automatically trustworthy as "my conversation
     * partner" — a non-admin can only ever mark their own Admin thread
     * read (the other-user-is-Admin check), Admin can mark any thread.
     */
    public function markThreadRead(Request $request, User $otherUser)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $otherUser->isAdmin(), 403);

        ChatMessage::where('sender_id', $otherUser->user_id)->where('recipient_id', $user->user_id)
            ->where('is_read', false)->update(['is_read' => true]);

        return response()->noContent();
    }

    /** Serves a chat image attachment — only the two participants of that message may view it. */
    public function image(Request $request, ChatMessage $message)
    {
        $user = $request->user();
        abort_unless(in_array($user->user_id, [$message->sender_id, $message->recipient_id], true), 403);
        abort_unless($message->attachment_path && Storage::exists($message->attachment_path), 404);

        return Storage::response($message->attachment_path, null, ['Content-Type' => $message->attachment_mime]);
    }

    /** Presence heartbeat (Feature: online-based "Available" status) — see User::isOnline(). */
    public function heartbeat(Request $request)
    {
        $request->user()->update(['last_seen_at' => now()]);

        return response()->noContent();
    }
}
