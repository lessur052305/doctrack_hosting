<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'username', 'password_hash', 'full_name', 'email', 'role', 'assigned_category', 'is_busy', 'created_by', 'is_active',
    ];

    protected $hidden = ['password_hash', 'remember_token'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_busy' => 'boolean',
    ];

    /**
     * Laravel's auth guard expects a `password` attribute/column by default.
     * We map it onto our documented `password_hash` column instead of
     * renaming the column, to stay faithful to the Data Dictionary (3.5.1).
     */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password_hash'] = $value;
    }

    // --- Role helpers ---
    public function isAdmin(): bool { return $this->role === 'admin'; }
    public function isOriginator(): bool { return $this->role === 'originator'; }
    public function isApprover(): bool { return $this->role === 'approver'; }

    // --- Relationships ---
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function documentsOriginated()
    {
        return $this->hasMany(DocumentRepository::class, 'originator_id', 'user_id');
    }

    public function assignmentsAsApprover()
    {
        return $this->hasMany(DocumentAssignment::class, 'user_id', 'user_id');
    }

    /**
     * Optional restriction to specific workflow stages within this
     * approver's assigned_category (Admin dynamic workflow assignment).
     * Empty by default, meaning "eligible for every stage in my category."
     */
    public function workflowStages()
    {
        return $this->belongsToMany(WorkflowStage::class, 'approver_workflow_stages', 'user_id', 'stage_id')
            ->withTimestamps();
    }

    public function notifications()
    {
        return $this->hasMany(NotificationRecord::class, 'recipient_id', 'user_id')->orderByDesc('created_at');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'user_id', 'user_id');
    }
}