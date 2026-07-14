<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUBMISSION_BATCHES
 * ------------------
 * Groups documents that an Originator uploaded together in a single
 * submission (Feature: nested/grouped approval requests). One batch is
 * created per "Submit Document(s)" form post, whether it contains one file
 * or several. All documents in a batch share the same due_date, since the
 * Originator sets a single deadline for the whole submission.
 *
 * A document's `batch_id` is nullable (see the following migration) so
 * documents created before this feature existed remain valid with no
 * batch — the UI treats those as a single-document container.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_batches', function (Blueprint $table) {
            $table->id('batch_id');
            $table->foreignId('originator_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->dateTime('due_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_batches');
    }
};