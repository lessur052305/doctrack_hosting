<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WORKFLOW_STAGES — Data Dictionary Table 3.5.3
 * Admin-configurable sequential stages (e.g. Budget Check, Technical Review,
 * Final Approval) referenced by Process 4.0 (Workflow Routing & Priority
 * Assignment) in the DFD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id('stage_id');
            $table->string('document_category', 50); // Job Order | Purchase Requisition | Service Report
            $table->string('stage_name', 255);
            $table->unsignedInteger('sequence_order');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_stages');
    }
};