@extends('layouts.app')

@section('title', 'Try GivingLedger')

@section('content')
<section class="mx-auto max-w-5xl px-4 py-12 sm:px-6" data-upload-root>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-semibold tracking-tight text-stone-900">Temporary workspace</h1>
            <p class="mt-2 text-stone-600">
                Upload a CSV or load the built-in sample dataset. Files stay in this guest session and expire automatically.
            </p>
        </div>
        <form method="POST" action="{{ route('try.reset') }}">
            @csrf
            <button type="submit" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-800 hover:bg-stone-50">
                Reset workspace
            </button>
        </form>
    </div>

    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500">Session</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-stone-500">Intent</dt>
                    <dd class="font-medium text-stone-900">{{ $intent === 'sample' ? 'Try with sample data' : ($intent === 'upload' ? 'Upload your CSV' : 'Not set') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-stone-500">Time remaining</dt>
                    <dd class="font-medium text-stone-900">{{ $minutesRemaining }} minute{{ $minutesRemaining === 1 ? '' : 's' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-stone-500">Expires at</dt>
                    <dd class="font-medium text-stone-900">{{ $workspace->expires_at->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-stone-500">State</dt>
                    <dd class="font-medium text-emerald-700">{{ ucfirst($workspace->state) }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500">Configured limits</h2>
            <ul class="mt-4 space-y-2 text-sm text-stone-700">
                <li>Maximum CSV size: {{ $maxCsvSizeMb }} MB</li>
                <li>Maximum data rows: {{ number_format($maxDataRows) }}</li>
                <li>Maximum columns: {{ $maxColumns }}</li>
                <li>Maximum field length: {{ number_format($maxFieldLength) }} characters</li>
                <li>Maximum active imports: {{ $maxActiveImports }}</li>
                <li>Workspace lifetime: {{ $workspaceLifetimeMinutes }} minutes</li>
            </ul>
        </div>
    </div>

    @if ($errors->any())
        <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" data-upload-server-error>
            {{ $errors->first() }}
        </div>
    @endif

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-stone-200 bg-white p-6">
            <h2 class="text-lg font-semibold text-stone-900">Try with sample data</h2>
            <p class="mt-2 text-sm text-stone-600">
                Load about 150 synthetic donation rows with duplicates, formatting issues, and formula-like values. No real donor data.
            </p>
            <form method="POST" action="{{ route('try.sample') }}" class="mt-5" data-sample-form>
                @csrf
                <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">
                    Load sample dataset
                </button>
            </form>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-6">
            <h2 class="text-lg font-semibold text-stone-900">Upload your CSV</h2>
            <p class="mt-2 text-sm text-stone-600">
                UTF-8 CSV only. Common delimiters: comma, semicolon, or tab. Files are stored privately and deleted when this workspace expires.
            </p>

            <form
                method="POST"
                action="{{ route('try.imports.store') }}"
                enctype="multipart/form-data"
                class="mt-5"
                data-upload-form
                data-upload-url="{{ route('try.imports.store') }}"
                data-max-bytes="{{ (int) config('givingledger.max_csv_size_bytes') }}"
            >
                @csrf
                <div
                    class="rounded-xl border-2 border-dashed border-stone-300 bg-stone-50 px-4 py-10 text-center transition hover:border-emerald-500"
                    data-drop-zone
                >
                    <p class="text-sm font-medium text-stone-800">Drag and drop a CSV here</p>
                    <p class="mt-1 text-xs text-stone-500">or choose a file</p>
                    <label class="mt-4 inline-flex cursor-pointer rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-800 hover:bg-stone-50">
                        Choose file
                        <input type="file" name="file" accept=".csv,text/csv,text/plain" class="sr-only" data-file-input required>
                    </label>
                    <p class="mt-3 text-xs text-stone-500" data-file-label>No file selected</p>
                </div>

                <p class="mt-3 hidden text-sm text-red-700" data-upload-error role="alert"></p>
                <p class="mt-3 hidden text-sm text-stone-600" data-upload-progress></p>

                <button type="submit" class="mt-4 rounded-lg bg-stone-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-stone-800" data-upload-submit>
                    Upload and parse
                </button>
            </form>
        </div>
    </div>

    <p class="mt-8 text-sm text-stone-500">
        Privacy: uploaded values are processed on the server for this temporary workspace only. Do not upload sensitive production donor data until a privacy review is complete.
    </p>
</section>
@endsection
