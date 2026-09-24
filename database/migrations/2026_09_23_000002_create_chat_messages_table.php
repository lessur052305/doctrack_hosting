<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-system chat (Feature: floating chat icon so any Originator/Approver
 * can message the single Admin, and vice versa). No separate "threads"
 * table — with exactly one Admin account, a conversation is fully defined
 * by (sender, recipient): every non-admin user's messages to/from the
 * Admin form their one thread. Grouped at query time by "the other
 * participant", not a thread_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id('message_id');
            $table->foreignId('sender_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->text('body')->nullable(); // nullable — an attachment-only message has no text
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sender_id', 'recipient_id']);
            $table->index(['recipient_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
