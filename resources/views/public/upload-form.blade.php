<form class="mt-5" method="POST" enctype="multipart/form-data" action="{{ $uploadUrl }}" data-upload-form data-upload-url="{{ $uploadUrl }}" data-max-bytes="{{ (int) config('reconciliation.max_csv_size_bytes') }}">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ (string) Str::uuid() }}">
    <input type="hidden" name="report_type" value="{{ $type }}">
    <label class="block cursor-pointer rounded-lg border border-dashed border-stone-300 p-4 text-center focus-within:ring-2 focus-within:ring-emerald-600" data-drop-zone>
        <span class="block text-sm font-medium text-stone-800">Choose CSV</span>
        <span class="mt-1 block text-xs text-stone-500" data-file-label>No file selected</span>
        <input class="sr-only" type="file" name="file" accept=".csv,text/csv" aria-label="Choose {{ $type }} CSV" data-file-input required>
    </label>
    <p class="mt-3 hidden text-sm text-red-700" data-upload-error role="alert"></p>
    <p class="mt-3 hidden text-sm text-stone-600" role="status" data-upload-progress></p>
    <button class="mt-4 w-full rounded-lg bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60" type="submit" data-upload-submit>Upload {{ $type === 'crm' ? 'CRM snapshot' : $type }}</button>
</form>
