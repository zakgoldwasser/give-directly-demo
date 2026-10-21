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

    @if ($mappingSaved)
        <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950" role="status">{{ $mappingSaved }}</div>
    @endif

    @if ($errors->any())
        <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert">
            <p class="font-medium">The mapping could not be saved.</p>
            <p class="mt-1">{{ $errors->first() }}</p>
        </div>
    @endif

    @if ($batch->context)
        <dl class="mt-8 grid gap-4 rounded-xl border border-stone-200 bg-white p-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-stone-500">Generated</dt>
                <dd class="mt-1 font-medium text-stone-900">{{ $batch->context['generated_at'] ?? 'Not declared' }}</dd>
            </div>
            <div>
                <dt class="text-stone-500">Coverage</dt>
                <dd class="mt-1 font-medium text-stone-900">{{ $batch->context['coverage_start'] ?? 'Not declared' }} to {{ $batch->context['coverage_end'] ?? 'Not declared' }}</dd>
            </div>
            <div>
                <dt class="text-stone-500">Provider account</dt>
                <dd class="mt-1 font-medium text-stone-900">{{ $batch->context['provider_id'] ?? 'Local' }} / {{ $batch->context['account_id'] ?? 'Local' }}</dd>
            </div>
            <div>
                <dt class="text-stone-500">Mapping profile</dt>
                <dd class="mt-1 font-medium text-stone-900">
                    @if ($batch->mappingProfile)
                        {{ $batch->mappingProfile->adapter_profile }} · v{{ $batch->mappingProfile->version }}
                    @else
                        Mapping required
                    @endif
                </dd>
            </div>
        </dl>
    @endif

    @if ($batch->status === \App\Models\ImportBatch::STATUS_VALIDATED)
        <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950" role="status">
            Validation passed for all {{ number_format($batch->valid_rows) }} rows. {{ number_format($batch->materialized_rows) }} source rows are linked to canonical records.
            @if ($batch->duplicate_rows > 0)
                {{ number_format($batch->duplicate_rows) }} identical source occurrence was retained without creating another record version.
            @endif
            @if ($batch->stale_rows > 0)
                {{ number_format($batch->stale_rows) }} stale revision was retained without replacing newer evidence.
            @endif
            @if ($batch->conflicting_rows > 0)
                {{ number_format($batch->conflicting_rows) }} source row participates in a blocking version conflict.
            @endif
        </div>
    @elseif ($batch->status === \App\Models\ImportBatch::STATUS_SUPERSEDED)
        <div class="mt-6 rounded-lg border border-stone-300 bg-stone-100 p-4 text-sm text-stone-800" role="status">
            This import was superseded by a later CRM snapshot. Its source rows, mapping, and materialization evidence remain available as read-only history.
        </div>
    @elseif ($batch->status === \App\Models\ImportBatch::STATUS_VALIDATION_FAILED)
        <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert">
            {{ number_format($batch->rejected_rows) }} of {{ number_format($batch->total_rows) }} rows have blocking validation errors. Invalid rows remain visible but cannot enter reconciliation.
        </div>
    @elseif ($batch->status === \App\Models\ImportBatch::STATUS_MAPPING_REQUIRED)
        <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" role="status">
            Choose source columns for every required canonical field before validation can run.
        </div>
    @endif

    @if ($batch->schema_issues)
        <div class="mt-6 rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="font-semibold text-stone-900">Schema findings</h2>
            <ul class="mt-3 space-y-2 text-sm text-stone-700">
                @foreach ($batch->schema_issues as $issue)
                    <li><span class="font-medium">{{ str_replace('_', ' ', $issue['code']) }}</span>@if ($issue['column']) · {{ $issue['column'] }}@endif — {{ $issue['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! in_array($batch->status, [\App\Models\ImportBatch::STATUS_FAILED, \App\Models\ImportBatch::STATUS_SUPERSEDED], true))
        <details class="mt-6 rounded-xl border border-stone-200 bg-white p-5" @if ($batch->status === \App\Models\ImportBatch::STATUS_MAPPING_REQUIRED) open @endif>
            <summary class="cursor-pointer font-semibold text-stone-900">Column mapping</summary>
            <p class="mt-2 text-sm text-stone-600">Each canonical field is mapped by header name. Saving creates an immutable mapping version and reruns validation.</p>
            <form class="mt-5 grid gap-4 sm:grid-cols-2" method="POST" action="{{ route('try.imports.mapping.store', $batch) }}" data-busy-form="Validating report…">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ (string) Str::uuid() }}">
                @foreach ($schemaFields as $canonicalField => $definition)
                    <label class="block text-sm">
                        <span class="font-medium text-stone-800">{{ $definition['label'] }}@if ($definition['required']) <span class="text-red-700">required</span>@endif</span>
                        <span class="mt-1 block text-xs text-stone-500">{{ $canonicalField }} · {{ str_replace('_', ' ', $definition['type']) }}</span>
                        <select class="mt-2 w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-stone-900" name="mapping[{{ $canonicalField }}]">
                            <option value="">Not mapped</option>
                            @foreach ($headers as $header)
                                <option value="{{ $header }}" @selected(old("mapping.{$canonicalField}", $currentMapping[$canonicalField] ?? '') === $header)>{{ $header }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
                <div class="sm:col-span-2">
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800" type="submit">Save mapping and validate</button>
                </div>
            </form>
        </details>
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
                            <th class="px-4 py-3 text-left font-medium text-stone-600" scope="col">Validation</th>
                            @foreach ($headers as $header)
                                <th class="px-4 py-3 text-left font-medium text-stone-600" scope="col">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($previewRows as $row)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-stone-700">{{ $row->row_number }}</td>
                                <td class="min-w-56 px-4 py-3 align-top text-stone-700">
                                    <p class="font-medium {{ $row->status === \App\Models\ImportRow::STATUS_INVALID ? 'text-red-700' : 'text-emerald-700' }}">{{ ucfirst($row->status) }}</p>
                                    @foreach ($row->validation_issues ?? [] as $issue)
                                        <p class="mt-1 text-xs text-red-700">{{ $issue['column'] }}: {{ $issue['message'] }}</p>
                                    @endforeach
                                    @if ($row->normalized_data)
                                        <details class="mt-2 text-xs">
                                            <summary class="cursor-pointer text-stone-600 underline">Normalized values</summary>
                                            <pre class="mt-2 max-w-sm overflow-x-auto whitespace-pre-wrap rounded bg-stone-100 p-2">{{ json_encode($row->normalized_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </details>
                                    @endif
                                </td>
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
