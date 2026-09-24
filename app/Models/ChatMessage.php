<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ChatMessage
 * -----------
 * In-system chat between any Originator/Approver and the single Admin
 * account. No thread/conversation table — with exactly one Admin, "the
 * conversation" is fully defined by which two users a row is between.
 * scopeThreadBetween() is how every read query groups messages into one.
 */
class ChatMessage extends Model
{
    protected $primaryKey = 'message_id';

    public $timestamps = false;

    protected $fillable = [
        'sender_id', 'recipient_id', 'body', 'attachment_path', 'attachment_mime', 'is_read', 'created_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id', 'user_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id', 'user_id');
    }

    /**
     * Every message either way between these two users — a "thread" is
     * just this query, not a stored row. Each branch is an explicit
     * closure, not orWhere(array) — orWhere() doesn't AND an array's
     * entries the way where() does, so orWhere(['sender_id' => ...,
     * 'recipient_id' => ...]) silently ORs them together instead
     * (confirmed via a real bug this produced: it matched ANY message
     * naming either user, not just ones strictly between the two).
     */
    public function scopeThreadBetween(Builder $query, int $userA, int $userB): Builder
    {
        return $query->where(function (Builder $q) use ($userA, $userB) {
            $q->where(fn (Builder $q2) => $q2->where('sender_id', $userA)->where('recipient_id', $userB))
                ->orWhere(fn (Builder $q2) => $q2->where('sender_id', $userB)->where('recipient_id', $userA));
        });
    }

    public function isImage(): bool
    {
        return $this->attachment_mime !== null && str_starts_with($this->attachment_mime, 'image/');
    }
}
