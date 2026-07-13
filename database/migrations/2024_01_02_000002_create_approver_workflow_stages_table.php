<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPROVER_WORKFLOW_STAGES — pivot table
 * Optionally scopes an Approver to specific workflow stages within their
 * (locked, category-wide) assigned_category. This is a finer-grained,
 * editable layer on top of the category lock: an Admin can, at creation or
 * later, restrict an approver to only certain stages (e.g. "Technical
 * Review" but not "Final Approval").
 *
 * An approver with ZERO rows here is eligible for every stage in their
 * category — this is the backward-compatible default for existing/simple
 * accounts that never had specific stages picked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approver_workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('workflow_stages', 'stage_id')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approver_workflow_stages');
    }
};