{{--
    Body fragment for the "Import Legacy Document" popup — fetched into
    components/kpi-drilldown-modal.blade.php by
    archive/partials/results.blade.php's "+ Import Legacy Document" button
    (see AdminController::legacyImportForm() / routes/web.php's
    admin.archive.legacy.form). Same form, fields, and submit target as
    always — only the container around it changed, from a permanently
    visible collapsible bar to something opened on demand.
--}}
<div class="p-6">
    <p class="text-xs text-surface-500 mb-4">Directly archive a pre-existing, already-approved document — bypasses classification, validation, and the approval workflow.</p>

    <form method="POST" action="{{ route('admin.archive.legacy') }}" enctype="multipart/form-data" class="space-y-3">
        @csrf
        <div>
            <label class="block text-xs font-medium text-surface-700 mb-1">File</label>
            <input type="file" name="file" required accept=".pdf,.docx,.doc,.txt,.png,.jpg,.jpeg"
                class="w-full text-xs text-surface-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-surface-700 mb-1">Category</label>
                <select name="category" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    @foreach(\App\Services\ValidationService::knownCategories() as $c)
                        <option value="{{ $c }}" @selected(request('category') === $c)>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-surface-700 mb-1">Title (optional)</label>
                <input type="text" name="title" placeholder="Defaults to the file name"
                    class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
            </div>
        </div>
        <div>
            <label class="block text-xs font-medium text-surface-700 mb-1">Reason for direct import <span class="text-rejected-700">*</span></label>
            <p class="text-[11px] text-surface-400 mb-1">Recorded in the audit trail — this is the only record of why this document skipped review.</p>
            <textarea name="import_reason" required minlength="10" maxlength="500" rows="2" placeholder="e.g. Digitizing 2019 paper records approved before this system existed"
                class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500"></textarea>
        </div>
        <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
            Add to Archive
        </button>
    </form>
</div>
