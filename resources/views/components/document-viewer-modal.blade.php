{{--
    Embedded original-file viewer (Feature: view the exact uploaded file
    in-system rather than downloading it). One shared modal, invoked from
    anywhere via JS: openDocumentViewer(url, mimeType, filename).

    Rendering strategy per mime type:
      - application/pdf                                            -> <iframe> (native browser PDF viewer)
      - image/*                                                    -> <img>
      - text/plain                                                 -> fetched & shown as preformatted text
      - .docx (wordprocessingml.document)                          -> fetched & converted to HTML client-side via mammoth.js
      - anything else (legacy .doc, etc.)                          -> graceful fallback with an "open in new tab" link
--}}
<div id="doc-viewer-overlay" class="hidden fixed inset-0 z-50 bg-surface-900/60 backdrop-blur-sm flex items-center justify-center p-4" onclick="if(event.target === this) closeDocumentViewer()">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl h-[85vh] flex flex-col overflow-hidden" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between px-5 py-3 border-b border-surface-200 flex-shrink-0">
            <h3 id="doc-viewer-title" class="text-sm font-semibold text-surface-900 truncate pr-4">Document Preview</h3>
            <div class="flex items-center gap-4 flex-shrink-0">
                <a id="doc-viewer-newtab" href="#" target="_blank" rel="noopener" class="text-xs text-primary-700 hover:underline font-medium">Open in new tab</a>
                <button type="button" onclick="closeDocumentViewer()" class="text-surface-400 hover:text-surface-700" aria-label="Close preview">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>
        <div id="doc-viewer-body" class="flex-1 overflow-auto bg-surface-50 relative"></div>
    </div>
</div>

<script>
    let __mammothLoadPromise = null;

    function __loadMammoth() {
        if (window.mammoth) return Promise.resolve();
        if (__mammothLoadPromise) return __mammothLoadPromise;

        __mammothLoadPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.7.2/mammoth.browser.min.js';
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Failed to load docx preview library.'));
            document.head.appendChild(script);
        });

        return __mammothLoadPromise;
    }

    function __escapeHtml(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function __showFallback(body, url, message) {
        body.innerHTML = `<div class="absolute inset-0 flex flex-col items-center justify-center text-center p-6 gap-2">
            <p class="text-sm text-surface-500">${message}</p>
            <a href="${url}" target="_blank" rel="noopener" class="text-primary-700 hover:underline text-sm font-medium">Open in new tab instead &rarr;</a>
        </div>`;
    }

    function closeDocumentViewer() {
        document.getElementById('doc-viewer-overlay').classList.add('hidden');
        document.getElementById('doc-viewer-body').innerHTML = '';
    }

    async function openDocumentViewer(url, mimeType, filename) {
        const overlay = document.getElementById('doc-viewer-overlay');
        const title = document.getElementById('doc-viewer-title');
        const newTabLink = document.getElementById('doc-viewer-newtab');
        const body = document.getElementById('doc-viewer-body');

        title.textContent = filename || 'Document Preview';
        newTabLink.href = url;
        overlay.classList.remove('hidden');
        body.innerHTML = '<div class="absolute inset-0 flex items-center justify-center text-sm text-surface-400">Loading preview…</div>';

        mimeType = mimeType || '';

        try {
            if (mimeType === 'application/pdf') {
                body.innerHTML = `<iframe src="${url}" class="w-full h-full border-0" title="${filename || 'Document preview'}"></iframe>`;
            } else if (mimeType.startsWith('image/')) {
                body.innerHTML = `<div class="w-full h-full flex items-center justify-center p-4">
                    <img src="${url}" alt="${filename || 'Document preview'}" class="max-w-full max-h-full object-contain rounded-lg shadow-card">
                </div>`;
            } else if (mimeType === 'text/plain') {
                const res = await fetch(url);
                if (!res.ok) throw new Error('Fetch failed');
                const text = await res.text();
                body.innerHTML = `<pre class="p-6 text-xs text-surface-700 whitespace-pre-wrap font-mono">${__escapeHtml(text)}</pre>`;
            } else if (mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                await __loadMammoth();
                const res = await fetch(url);
                if (!res.ok) throw new Error('Fetch failed');
                const arrayBuffer = await res.arrayBuffer();
                const result = await window.mammoth.convertToHtml({ arrayBuffer });
                body.innerHTML = `<div class="prose prose-sm max-w-none bg-white mx-auto my-4 p-8 rounded-lg shadow-card" style="max-width: 800px;">${result.value}</div>`;
            } else {
                __showFallback(body, url, "Preview isn't available for this file type in-browser.");
            }
        } catch (e) {
            __showFallback(body, url, "Couldn't load the preview.");
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeDocumentViewer();
    });
</script>