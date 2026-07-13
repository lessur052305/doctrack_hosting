<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    protected $primaryKey = 'log_id';
    public $timestamps = false; // uses its own `timestamp` column instead

    protected $fillable = [
        'user_id', 'document_id', 'action_type', 'description', 'ip_address', 'timestamp',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function document()
    {
        return $this->belongsTo(DocumentRepository::class, 'document_id', 'document_id');
    }

    /**
     * Convenience factory used everywhere a transition happens (Section 6).
     * Deliberately insert-only: audit rows are never updated or deleted
     * by application code.
     */
    public static function record(?int $userId, ?int $documentId, string $action, ?string $description = null, ?string $ip = null): self
    {
        return static::create([
            'user_id' => $userId,
            'document_id' => $documentId,
            'action_type' => $action,
            'description' => $description,
            'ip_address' => $ip ?? request()->ip(),
            'timestamp' => now(),
        ]);
    }
}
