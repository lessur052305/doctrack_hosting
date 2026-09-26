<?php

namespace App\Support;

use App\Models\DocumentAssignment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * When a past decision's clock really started, for the approval-time
 * estimates and forecast (ApprovalTimeMlService, ApprovalForecastService).
 *
 * A seat's created_at is normally that moment. The exception is Final
 * Approval on documents routed before it started opening last: every stage's
 * seats — Final Approval's included — were created together at routing, so
 * created_at overstates how long the Head had the decision in front of them
 * by however long the other stages took. For those rows the clock starts when
 * the last other stage was resolved instead, which is exactly when Final
 * Approval opens today. For a seat that already opened last, that moment is
 * (within the same request) its own created_at, so nothing changes.
 *
 * A Final Approval decided BEFORE the other stages finished (possible under
 * the old parallel routing) keeps its created_at: there was no waiting.
 */
class DecisionTiming
{
    /**
     * @param  Collection<int, object>  $rows  each with assignment_id, document_id, stage_name, created_at, acted_at
     * @return array<int, Carbon> assignment_id => when that decision's clock started
     */
    public static function startTimes(Collection $rows): array
    {
        $lastOtherStageResolvedAt = DocumentAssignment::query()
            ->join('workflow_stages', 'document_assignments.stage_id', '=', 'workflow_stages.stage_id')
            ->whereIn('document_assignments.document_id', $rows->pluck('document_id')->unique()->all())
            ->where('workflow_stages.stage_name', '!=', 'Final Approval')
            ->whereNotNull('document_assignments.acted_at')
            ->groupBy('document_assignments.document_id')
            ->selectRaw('document_assignments.document_id as document_id, max(document_assignments.acted_at) as resolved_at')
            ->pluck('resolved_at', 'document_id');

        $starts = [];
        foreach ($rows as $row) {
            $start = Carbon::parse($row->created_at);

            if ($row->stage_name === 'Final Approval' && isset($lastOtherStageResolvedAt[$row->document_id])) {
                $othersDone = Carbon::parse($lastOtherStageResolvedAt[$row->document_id]);
                if ($othersDone->greaterThan($start) && $othersDone->lessThanOrEqualTo(Carbon::parse($row->acted_at))) {
                    $start = $othersDone;
                }
            }

            $starts[$row->assignment_id] = $start;
        }

        return $starts;
    }
}
