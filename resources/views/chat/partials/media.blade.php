{{-- Photo gallery for one conversation — every attachment ever sent either way, newest first. --}}
<div class="px-4 py-3 border-b border-surface-200 flex items-center gap-2 shrink-0">
    <button type="button" onclick="openChatThread({{ $other->user_id }})" class="p-1 -ml-1 rounded-lg hover:bg-surface-100 text-surface-500" aria-label="Back to conversation">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
    </button>
    <p class="text-sm font-semibold text-surface-900 truncate">Photos with {{ $other->full_name }}</p>
</div>
<div class="flex-1 min-h-0 overflow-y-auto p-3">
    @if($images->isEmpty())
        <p class="text-center text-sm text-surface-400 py-8">No photos yet.</p>
    @else
        <div class="grid grid-cols-3 gap-2">
            @foreach($images as $m)
                <a href="{{ route('chat.image', $m) }}" target="_blank" class="block aspect-square rounded-lg overflow-hidden bg-surface-100">
                    <img src="{{ route('chat.image', $m) }}" class="w-full h-full object-cover" alt="Chat photo">
                </a>
            @endforeach
        </div>
    @endif
</div>
