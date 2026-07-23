<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data-only backfill, deliberately a separate migration from the one
     * that added confirmed_at_model_id — schema changes and data backfills
     * shouldn't be bundled, and this needs to run on any environment where
     * that column migration already executed before this fix existed
     * (this dev database included), not just fresh installs.
     *
     * confirmed_at_model_id is null on every document confirmed before
     * that column existed. AdminController::recheckFlaggedDocument()'s
     * gate reads null as "definitely different from whatever's active now"
     * — i.e. "go ahead, a retrain happened" — which is backwards for these
     * rows: we don't actually know, so treating them as already stale
     * would offer a "Re-check" that produces a meaningless no-op result.
     * Best available truth for historical rows: assume they were
     * confirmed under whatever model is active right now (correct in the
     * overwhelmingly common case of "no retrain happened between then and
     * this migration running"; the alternative — leaving them null — is
     * definitely wrong).
     */
    public function up(): void
    {
        $activeModelId = DB::table('ml_model_repository')->where('is_active', true)->value('model_id');

        if ($activeModelId !== null) {
            DB::table('document_repository')
                ->where('ml_review_status', 'confirmed')
                ->whereNull('confirmed_at_model_id')
                ->update(['confirmed_at_model_id' => $activeModelId]);
        }
    }

    public function down(): void
    {
        // Data-only backfill — no safe rollback (can't distinguish
        // backfilled rows from ones that were already null for a genuine
        // reason, e.g. confirmed before any model ever existed).
    }
};
