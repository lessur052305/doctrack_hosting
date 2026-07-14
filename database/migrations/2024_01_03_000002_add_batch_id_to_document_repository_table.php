<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('originator_id')
                ->constrained('submission_batches', 'batch_id')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_repository', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
        });
    }
};