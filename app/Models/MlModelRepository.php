<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MlModelRepository extends Model
{
    protected $table = 'ml_model_repository';
    protected $primaryKey = 'model_id';

    protected $fillable = [
        'model_name', 'version', 'accuracy_score', 'model_file_path',
        'training_sample_count', 'is_active', 'last_trained',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_trained' => 'datetime',
    ];

    public function documents()
    {
        return $this->hasMany(DocumentRepository::class, 'model_id', 'model_id');
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->latest('last_trained')->first();
    }
}
