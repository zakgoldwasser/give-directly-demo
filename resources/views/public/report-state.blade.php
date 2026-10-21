<div class="mt-5 rounded-lg border border-stone-200 bg-stone-50 p-4" data-report-status="{{ $batch->status }}">
    <p class="text-sm font-medium">{{ $batch->original_filename }}</p>
    <p class="mt-1 text-sm">Loaded {{ number_format($batch->total_rows) }} rows · {{ str_replace('_', ' ', $batch->status) }}</p>
    @if ($batch->mappingProfile)
        <p class="mt-1 text-xs">{{ $batch->mappingProfile->adapter_profile }} · mapping v{{ $batch->mappingProfile->version }}</p>
    @endif
    @if ($batch->validated_at)
        <p class="mt-1 text-xs">{{ number_format($batch->valid_rows) }} valid · {{ number_format($batch->rejected_rows) }} rejected</p>
    @endif
    @if ($batch->materialized_at)
        <p class="mt-1 text-xs">{{ number_format($batch->materialized_rows) }} source rows materialized · {{ number_format($batch->duplicate_rows) }} duplicate occurrences retained</p>
    @endif
    <a class="mt-3 inline-flex text-sm font-medium text-emerald-800 underline" data-import-open href="{{ route('try.imports.show', $batch) }}">Review mapping</a>
</div>
