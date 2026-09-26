<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An auto-approved earlier stage no longer needs its own Admin review once a
 * Head Approver has signed off on Final Approval — that sign-off checks the
 * whole document, so the stage is closed out as 'covered' instead of
 * 'confirmed'/'disputed' (see WorkflowService::settleDeferredAutoApprovalReviews()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->enum('admin_review_outcome', ['confirmed', 'disputed', 'covered'])->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('document_assignments', function (Blueprint $table) {
            $table->enum('admin_review_outcome', ['confirmed', 'disputed'])->nullable()->change();
        });
    }
};
