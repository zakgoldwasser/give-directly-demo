@extends('layouts.app')

@section('title', 'Import · GivingLedger')

@section('content')
<section
    class="mx-auto max-w-5xl px-4 py-12 sm:px-6"
    data-processing-root
    data-status-url="{{ route('try.imports.status', $batch) }}"
    data-batch-status="{{ $batch->status }}"
>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm text-stone-500">
                <a href="{{ route('try.show') }}" class="font-medium text-emerald-800 hover:underline">Back to workspace</a>
            </p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-stone-900">{{ $batch->original_filename }}</h1>
            <p class="mt-2 text-sm text-stone-600">
                Workspace time remaining: {{ $minutesRemaining }} minute{{ $minutesRemaining === 1 ? '' : 's' }}
            </p>
        </div>
        <div class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm">
            <span class="text-stone-500">Status</span>
            <span class="ml-2 font-semibold text-stone-900" data-status-label>{{ str_replace('_', ' ', $batch->status) }}</span>
        </div>
    </div>

    @if ($duplicateWarning)
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            {{ $duplicateWarning }} Showing the existing import instead of creating a new one.
        </div>
    @endif

    @if ($batch->isProcessing())
        <div class="mt-8 rounded-xl border border-stone-200 bg-white p-6" data-processing-panel>
            <h2 class="text-lg font-semibold text-stone-900">Processing your file</h2>
            <p class="mt-2 text-sm text-stone-600">
                Parsing rows into temporary staging records. Column mapping comes next.
            </p>
            <div class="mt-6">
                <div class="h-2 overflow-hidden rounded-full bg-stone-100">
                    <div class="h-full w-1/4 animate-pulse rounded-full bg-emerald-600" data-progress-bar></div>
                </div>
                <p class="mt-3 text-sm text-stone-700" data-progress-text>
                    Processed <span data-processed-rows>{{ $batch->processed_rows }}</span>
                    of <span data-total-rows>{{ $batch->total_rows ?: '…' }}</span> rows
                </p>
            </div>
        </div>
    @elseif ($batch->status === \App\Models\ImportBatch::STATUS_FAILED)
        <div class="mt-8 rounded-xl border border-red-200 bg-red-50 p-6" role="alert">
            <h2 class="text-lg font-semibold text-red-900">Processing failed</h2>
            <p class="mt-2 text-sm text-red-800">
                {{ $batch->processing_error_code ?? 'PROCESSING_FAILED' }}: The file could not be parsed into staging rows.
            </p>
            <a href="{{ route('try.show') }}" class="mt-4 inline-flex rounded-lg bg-red-800 px-4 py-2 text-sm font-semibold text-white hover:bg-red-900">
                Try another file
            </a>
        </div>
    @elseif ($batch->status === \App\Models\ImportBatch::STATUS_MAPPING_REQUIRED)
        <div class="mt-8 rounded-xl border border-emerald-200 bg-emerald-50 p-6">
            <h2 class="text-lg font-semibold text-emerald-900">Parse complete</h2>
            <p class="mt-2 text-sm text-emerald-800">
                {{ number_format($batch->total_rows) }} rows are staged in this temporary workspace.
                Column mapping and validation arrive in the next milestone.
            </p>
        </div>

        <div class="mt-8 overflow-hidden rounded-xl border border-stone-200 bg-white">
            <div class="border-b border-stone-200 px-4 py-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500">Raw preview (first 10 rows)</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-stone-50 text-stone-600">
                        <tr>
                            <th class="px-3 py-2 font-medium">#</th>
                            @foreach ($headers as $header)
                                <th class="px-3 py-2 font-medium">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($previewRows as $row)
                            <tr class="border-t border-stone-100 align-top">
                                <td class="px-3 py-2 text-stone-500">{{ $row->row_number }}</td>
                                @foreach ($headers as $header)
                                    <td class="px-3 py-2 text-stone-800">{{ $row->raw_data[$header] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td class="px-3 py-4 text-stone-500" colspan="{{ count($headers) + 1 }}">No staged rows.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</section>
@endsection
