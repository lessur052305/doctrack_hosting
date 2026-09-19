{{--
    One audit-trail row (Timestamp / Document Title / Actor / Action / Track
    / Description) — shared by admin/partials/audit-results.blade.php (the
    full Audit Trail table) AND the Control Center's Recent Activity panel,
    so a document/system row always looks and behaves identically wherever
    it's shown, not just similarly. Expects $row (kind: 'document' or
    'system', shaped by AdminController::buildAuditRows()/recentActivityRows())
    and the same $actionCategories/$categoryClasses/$actionLabels maps the
    including view already defined.
--}}
@if($row->kind === 'document')
    @php
        $doc = $row->document;
        $rowActionLabel = $doc->is_legacy_import ? 'Imported' : 'Uploaded';
        $rowBadgeClass = $categoryClasses['lifecycle'];
    @endphp
    {{-- data-row-group gives initFittedPagination() a distinct fitting
         group per row. --}}
    <tr class="audit-row audit-row-document hover:bg-surface-50 cursor-pointer" data-row-group="doc-{{ $doc->document_id }}"
        data-document-title="{{ strtolower($doc->title) }}" data-document-id="{{ $doc->document_id }}"
        onclick="openKpiDrilldown('document-tracker', '{{ addslashes($doc->title) }}', '{{ route('documents.trackerModal', $doc) }}')">
        <td class="px-6 py-3 text-surface-500 whitespace-nowrap align-top border-r border-surface-200">{{ $doc->upload_date?->format('M j, Y g:i:s A') }}</td>
        <td class="px-6 py-3 text-surface-800 font-medium align-top border-r border-surface-200">{{ $doc->title }}</td>
        <td class="px-6 py-3 text-surface-700 align-top border-r border-surface-200">{{ $doc->originator->full_name ?? 'System' }}</td>
        <td class="px-6 py-3 align-top border-r border-surface-200">
            <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $rowBadgeClass }}">{{ $rowActionLabel }}</span>
        </td>
        <td class="px-6 py-3 text-surface-500 align-top whitespace-nowrap border-r border-surface-200">
            {{-- Stashes the page we're currently on before navigating away
                 — see admin/audit_logs.blade.php's matching "return page"
                 comment for why (this page's pagination has no ?page= URL
                 to restore from otherwise). Reads the page-number nav
                 directly rather than reaching into the JS closure that
                 built it — simpler than exposing that instance globally
                 just for this one read. --}}
            <a href="{{ route('documents.track', ['document' => $doc, 'from' => 'audit']) }}"
                onclick="event.stopPropagation(); sessionStorage.setItem('auditLogsReturnPage', document.querySelector('#audit-results-pagination [aria-current=page]')?.textContent?.trim() || '1')"
                class="inline-flex items-center px-2 py-1 rounded-lg bg-primary-50 hover:bg-primary-100 text-primary-700 font-medium transition-colors">View &rarr;</a>
        </td>
        <td class="px-6 py-3 text-surface-600 align-top">
            {{-- bg + padding, not just an icon + plain text — reads as a
                 clickable button at a glance, same treatment as the
                 Resubmit/Mark off button fixes elsewhere in the app.
                 Opens the shared Document Tracker popup (Feature: a
                 guaranteed-size view regardless of where in a long,
                 paginated list this row happens to sit — an inline
                 expand here had no reliable room to show it in). --}}
            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-primary-50 text-primary-700 text-xs font-medium">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 4.5a3 3 0 013-3h9a3 3 0 013 3v15a3 3 0 01-3 3h-9a3 3 0 01-3-3v-15z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 8.25h7.5M8.25 12h7.5M8.25 15.75h4.5"/></svg>
                Click to view Document Tracker.
            </span>
        </td>
    </tr>
@else
    @php
        $log = $row->log;
        $rowCategory = $actionCategories[$log->action_type] ?? 'config';
        $rowBadgeClass = $categoryClasses[$rowCategory];
    @endphp
    <tr class="audit-row" data-row-group="log-{{ $log->log_id }}" data-document-title="" data-document-id="">
        <td class="px-6 py-3 text-surface-500 whitespace-nowrap align-top border-r border-surface-200">{{ $log->timestamp->format('M j, Y g:i:s A') }}</td>
        <td class="px-6 py-3 text-surface-400 align-top border-r border-surface-200">—</td>
        <td class="px-6 py-3 text-surface-700 align-top border-r border-surface-200">{{ $log->user->full_name ?? 'System' }}</td>
        <td class="px-6 py-3 align-top border-r border-surface-200">
            <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $rowBadgeClass }}">{{ $actionLabels[$log->action_type] ?? ucfirst(str_replace('_', ' ', $log->action_type)) }}</span>
        </td>
        <td class="px-6 py-3 text-surface-400 align-top whitespace-nowrap border-r border-surface-200">—</td>
        <td class="px-6 py-3 text-surface-600 align-top">{{ $log->description }}</td>
    </tr>
@endif
