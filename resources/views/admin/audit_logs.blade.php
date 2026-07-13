@extends('layouts.app')
@section('title', 'Audit Logs')
@section('page-title', 'Audit Trail')

@section('content')
<div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
    <form method="GET" class="px-6 py-4 border-b border-surface-200 flex flex-wrap gap-3">
        <input type="text" name="action_type" value="{{ request('action_type') }}" placeholder="Filter by action type…"
            class="rounded-lg border-surface-300 text-xs px-3 py-2 w-56">
        <input type="number" name="document_id" value="{{ request('document_id') }}" placeholder="Document ID…"
            class="rounded-lg border-surface-300 text-xs px-3 py-2 w-40">
        <button class="text-xs font-medium bg-primary-700 hover:bg-primary-800 text-white px-4 py-2 rounded-lg">Filter</button>
        <a href="{{ route('admin.audit.logs') }}" class="text-xs font-medium text-surface-500 hover:underline self-center">Clear</a>
    </form>

    <table class="w-full text-sm">
        <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
            <tr>
                <th class="text-left px-6 py-3 font-medium">Timestamp</th>
                <th class="text-left px-6 py-3 font-medium">Actor</th>
                <th class="text-left px-6 py-3 font-medium">Action</th>
                <th class="text-left px-6 py-3 font-medium">Document</th>
                <th class="text-left px-6 py-3 font-medium">Description</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-surface-100">
            @foreach($logs as $log)
                <tr class="hover:bg-surface-50">
                    <td class="px-6 py-3 text-surface-500 whitespace-nowrap">{{ $log->timestamp->format('M j, Y g:i:s A') }}</td>
                    <td class="px-6 py-3 text-surface-700">{{ $log->user->full_name ?? 'System' }}</td>
                    <td class="px-6 py-3">
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-surface-100 text-surface-600">{{ $log->action_type }}</span>
                    </td>
                    <td class="px-6 py-3 text-surface-500">{{ $log->document_id ? '#'.$log->document_id : '—' }}</td>
                    <td class="px-6 py-3 text-surface-600 max-w-md truncate" title="{{ $log->description }}">{{ $log->description }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-6 py-4 border-t border-surface-200">{{ $logs->links() }}</div>
</div>
@endsection
