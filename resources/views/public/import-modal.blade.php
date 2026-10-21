<dialog data-import-modal data-workspace-url="{{ route('try.show') }}" aria-labelledby="import-modal-title" class="m-0 h-dvh max-h-dvh w-screen max-w-none bg-white p-0 text-stone-900 backdrop:bg-stone-950/50 sm:m-auto sm:h-[90dvh] sm:max-h-[90dvh] sm:w-[92vw] sm:max-w-6xl sm:rounded-2xl">
    <div class="flex h-full flex-col">
        <div class="flex shrink-0 items-center justify-between gap-4 border-b border-stone-200 px-5 py-4">
            <h2 id="import-modal-title" class="text-lg font-semibold">Review report</h2>
            <button type="button" data-import-close class="rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium">Close</button>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto px-5 pb-6 sm:px-8" data-import-scroll>
            <p class="mt-4 text-sm text-stone-600" role="status" data-import-progress></p>
            <div class="mt-4 rounded-lg bg-red-50 p-4 text-sm text-red-900" role="alert" data-import-error hidden></div>
            <div data-import-content></div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-stone-200 bg-stone-50 px-5 py-4">
            <p class="text-sm text-stone-600" data-import-guidance>Your uploaded file is saved. You can close this window and review it later.</p>
            <button type="button" data-import-next class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white" hidden></button>
            <a data-import-fallback class="text-sm text-emerald-800 underline">Open full page</a>
        </div>
    </div>
</dialog>
