<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRecord extends Model
{
    protected $table = 'notifications';
    protected $primaryKey = 'notification_id';
    public $timestamps = false;

    protected $fillable = [
        'recipient_id', 'document_id', 'message_body', 'priority', 'is_read', 'created_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id', 'user_id');
    }

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    public static function send(int $recipientId, ?int $documentId, string $message, string $priority = 'normal'): self
    {
        return static::create([
            'recipient_id' => $recipientId,
            'document_id' => $documentId,
            'message_body' => $message,
            'priority' => $priority,
            'is_read' => false,
            'created_at' => now(),
        ]);
    }
}
