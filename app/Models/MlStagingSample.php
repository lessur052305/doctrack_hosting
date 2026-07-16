<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MlStagingSample extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['category', 'original_filename', 'extracted_text', 'staged_by'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function stagedBy()
    {
        return $this->belongsTo(User::class, 'staged_by', 'user_id');
    }
}
