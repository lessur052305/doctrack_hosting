@extends('layouts.app')
@section('title', 'Archive')
@section('page-title', 'Document Archive & Repository')

@section('content')
<div class="space-y-6">

    @if($noCategoryAssigned)
        <div class="bg-white rounded-xl shadow-card border border-surface-200 p-12 text-center">
            <p class="text-sm text-surface-600 font-medium">No document category has been assigned to your account yet.</p>
            <p class="text-xs text-surface-400 mt-1">Ask an Admin to assign you a category from User Accounts to unlock the archive.</p>
        </div>
    @else

    <div class="grid grid-cols-1 {{ auth()->user()->isAdmin() ? 'lg:grid-cols-3' : '' }} gap-6">

        <div class="{{ auth()->user()->isAdmin() ? 'lg:col-span-2' : '' }} space-y-6">

            {{-- Search / filter bar --}}
            <div class="bg-white rounded-xl shadow-card border border-surface-200 p-5">
                <form method="GET" class="flex flex-wrap gap-3 items-end">
                    <div class="flex-1 min-w-[180px]">
                        <label class="block text-xs font-medium text-surface-700 mb-1">Keyword</label>
                        <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="Title or content…"
                            class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>

                    @if($restrictedCategory)
                        <div>
                            <label class="block text-xs font-medium text-surface-700 mb-1">Category</label>
                            <span class="inline-flex items-center px-3 py-2 rounded-lg bg-surface-100 text-sm font-medium text-surface-700">{{ $restrictedCategory }}</span>
                        </div>
                    @else
                        <div>
                            <label class="block text-xs font-medium text-surface-700 mb-1">Category</label>
                            <select name="category" class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                                <option value="">All Categories</option>
                                @foreach($categories as $c)
                                    <option value="{{ $c }}" @selected(request('category') === $c)>{{ $c }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">From</label>
                        <input type="date" name="date_from" value="{{ request('date_from') }}"
                            class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">To</label>
                        <input type="date" name="date_to" value="{{ request('date_to') }}"
                            class="rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>

                    <button class="bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Search</button>
                    <a href="{{ url()->current() }}" class="text-xs text-surface-500 hover:underline self-center">Clear</a>
                </form>
            </div>

            {{-- Results --}}
            <div class="bg-white rounded-xl shadow-card border border-surface-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-surface-200 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-surface-900">Approved Documents</h2>
                        @if($isOwnSubmissionsView)
                            <p class="text-xs text-surface-400 mt-0.5">Showing only documents you submitted, across all categories.</p>
                        @endif
                    </div>
                    <span class="text-xs text-surface-400">{{ $documents->total() }} total</span>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-surface-50 text-surface-500 text-xs uppercase tracking-wide">
                        <tr>
                            <th class="text-left px-6 py-3 font-medium">Document</th>
                            <th class="text-left px-6 py-3 font-medium">Category</th>
                            <th class="text-left px-6 py-3 font-medium">Originator</th>
                            <th class="text-left px-6 py-3 font-medium">Approved</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-surface-100">
                        @forelse($documents as $doc)
                            <tr class="hover:bg-surface-50 transition-colors">
                                <td class="px-6 py-3 font-medium text-surface-800 max-w-xs truncate">{{ $doc->title }}</td>
                                <td class="px-6 py-3 text-surface-600">{{ $doc->ml_category }}</td>
                                <td class="px-6 py-3 text-surface-500">{{ $doc->originator->full_name ?? '—' }}</td>
                                <td class="px-6 py-3 text-surface-500">{{ $doc->updated_at->format('M j, Y') }}</td>
                                <td class="px-6 py-3 text-right">
                                    <a href="{{ route('archive.download', $doc) }}" class="text-primary-700 hover:text-primary-900 font-medium text-xs">Download &darr;</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-surface-400 text-sm">No archived documents match your search.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                @if($documents->hasPages())
                    <div class="px-6 py-4 border-t border-surface-200">{{ $documents->links() }}</div>
                @endif
            </div>
        </div>

        @if(auth()->user()->isAdmin())
        <div>
            <div class="bg-white rounded-xl shadow-card border border-surface-200 p-6">
                <h2 class="text-sm font-semibold text-surface-900 mb-1">Import Legacy Document</h2>
                <p class="text-xs text-surface-500 mb-4">Directly archive a pre-existing, already-approved document — bypasses classification, validation, and the approval workflow.</p>

                <form method="POST" action="{{ route('admin.archive.legacy') }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">File</label>
                        <input type="file" name="file" required accept=".pdf,.docx,.doc,.txt,.png,.jpg,.jpeg"
                            class="w-full text-xs text-surface-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">Category</label>
                        <select name="category" required class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                            @foreach(\App\Services\ValidationService::knownCategories() as $c)
                                <option value="{{ $c }}">{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-surface-700 mb-1">Title (optional)</label>
                        <input type="text" name="title" placeholder="Defaults to the file name"
                            class="w-full rounded-lg border-surface-300 text-sm px-3 py-2 focus:border-primary-500 focus:ring-primary-500">
                    </div>
                    <button class="w-full bg-primary-700 hover:bg-primary-800 text-white text-sm font-medium py-2.5 rounded-lg transition-colors">
                        Add to Archive
                    </button>
                </form>
            </div>
        </div>
        @endif
    </div>
    @endif
</div>
@endsection