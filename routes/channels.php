<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
| Authorization callbacks for the private channels app/Events/*.php push
| to. Each checks the authenticated user is genuinely the intended
| recipient (and holds the right role) before Reverb allows the WebSocket
| subscription — the same per-request ownership checks used everywhere
| else in this app, just applied to channel subscriptions instead of
| HTTP routes. (The stock 'App.Models.User.{id}' stub Laravel generates
| here by default is removed — it assumes a User model keyed on `id`,
| but this app's User is keyed on `user_id`, and nothing broadcasts to
| that channel name anyway.)
*/

Broadcast::channel('originator.{userId}', function ($user, $userId) {
    return (int) $user->user_id === (int) $userId && $user->isOriginator();
});

Broadcast::channel('approver.{userId}', function ($user, $userId) {
    return (int) $user->user_id === (int) $userId && $user->isApprover();
});

Broadcast::channel('admin-dashboard', function ($user) {
    return $user->isAdmin();
});

// Notification bell — every role has one, so this only checks identity,
// not a specific role.
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->user_id === (int) $userId;
});
