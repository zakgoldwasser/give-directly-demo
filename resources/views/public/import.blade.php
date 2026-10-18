@extends('layouts.app')

@section('title', 'Imported reconciliation report')

@section('content')
<section class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
    <a class="text-sm font-medium text-emerald-700 hover:text-emerald-800" href="{{ route('try.show') }}">← Back to report set</a>

    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">{{ str($batch->report_type)->replace('_', ' ')->title() }}</p>
            <h1 class="mt-1 text-3xl font-semibold tracking-tight text-stone-900">{{ $batch->original_filename }}</h1>
            <p class="mt-2 text-stone-600">{{ number_format($batch->total_rows) }} staged source rows · {{ str($batch->status)->replace('_', ' ') }}</p>
        </div>
        <p class="text-sm text-stone-500">Workspace expires in {{ $minutesRemaining }} minutes</p>
    </div>

    @if ($duplicateWarning)
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" role="status">{{ $duplicateWarning }}</div>
    @endif

    @if ($batch->status === \App\Models\ImportBatch::STATUS_FAILED)
        <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-red-900" role="alert">
            This report could not be staged. Error code: {{ $batch->processing_error_code }}.
        </div>
    @elseif (count($previewRows) > 0)
        <div class="mt-8 overflow-hidden rounded-xl border border-stone-200 bg-white">
            <div class="border-b border-stone-200 px-5 py-4">
                <h2 class="font-semibold text-stone-900">Source preview</h2>
                <p class="mt-1 text-sm text-stone-600">Original values remain immutable. Mapping and validation will create versioned interpretations.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-stone-600" scope="col">Row</th>
                            @foreach ($headers as $header)
                                <th class="px-4 py-3 text-left font-medium text-stone-600" scope="col">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($previewRows as $row)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-stone-700">{{ $row->row_number }}</td>
                                @foreach ($headers as $header)
                                    <td class="max-w-xs truncate px-4 py-3 text-stone-700">{{ $row->raw_data[$header] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</section>
@endsection
