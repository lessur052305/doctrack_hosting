<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowStage extends Model
{
    protected $table = 'workflow_stages';
    protected $primaryKey = 'stage_id';

    protected $fillable = [
        'document_category', 'stage_name', 'sequence_order', 'description',
    ];

    public function assignments()
    {
        return $this->hasMany(DocumentAssignment::class, 'stage_id', 'stage_id');
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('document_category', $category)->orderBy('sequence_order');
    }
}