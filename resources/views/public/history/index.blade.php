@extends('layouts.app')

@section('title', 'Run history')

@section('content')
<section class="mx-auto max-w-5xl px-4 py-10 sm:px-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Immutable workspace evidence</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-stone-900">Run history</h1>
            <p class="mt-3 max-w-3xl text-stone-600">Each entry preserves the exact source imports, decisions, outcomes, and downstream artifacts used at that point in the reconciliation.</p>
        </div>
        <a class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-800 hover:bg-stone-50" href="{{ route('try.show') }}">Back to workspace</a>
    </div>

    <div class="mt-6 rounded-lg border border-stone-200 bg-white px-4 py-3 text-sm text-stone-600">
        {{ $runs->count() }} immutable {{ Str::plural('run', $runs->count()) }} · {{ $minutesRemaining }} minutes remaining
    </div>

    <ol class="mt-8 space-y-5">
        @foreach ($runs as $run)
            @php
                $decisionCount = collect($run->decisions)->sum(fn ($values) => is_array($values) ? count($values) : 0);
                $confirmationCount = $run->exportBatches->sum(fn ($export) => $export->confirmations->count());
                $kind = match (true) {
                    $run->verificationConfirmation !== null => 'Post-import verification',
                    $run->exportBatches->isNotEmpty() => 'Reviewed export source',
                    $decisionCount > 0 => 'Reviewed reconciliation',
                    default => 'Automated baseline',
                };
            @endphp
            <li>
                <article class="rounded-xl border border-stone-200 bg-white p-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Run {{ $run->sequence }} · {{ $kind }}</p>
                            <h2 class="mt-1 text-xl font-semibold text-stone-900">{{ $run->completed_at?->format('j M Y, H:i:s T') }}</h2>
                            <p class="mt-1 font-mono text-xs text-stone-500">{{ substr($run->input_fingerprint, 0, 16) }}… · rule {{ $run->rule_version }}</p>
                        </div>
                        <a class="text-sm font-medium text-emerald-700 hover:text-emerald-800" href="{{ route('try.history.runs.show', $run) }}">Open audit detail</a>
                    </div>

                    <dl class="mt-5 grid gap-3 sm:grid-cols-4">
                        @foreach (['create' => 'Creates', 'update' => 'Updates', 'unchanged' => 'Unchanged', 'held' => 'Held'] as $outcome => $label)
                            <div class="rounded-lg bg-stone-50 p-3">
                                <dt class="text-xs text-stone-500">{{ $label }}</dt>
                                <dd class="mt-1 text-xl font-semibold text-stone-900">{{ $run->outcome_counts[$outcome] ?? 0 }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <p class="font-medium text-stone-900">Source snapshot</p>
                            <ul class="mt-2 space-y-1 text-stone-600">
                                @foreach ($run->input_snapshot['imports'] ?? [] as $source)
                                    @php($batch = $imports->get($source['id']))
                                    <li>{{ ucfirst($source['report_type']) }} · {{ $batch?->original_filename ?? 'Unavailable import' }} · {{ substr($source['checksum'], 0, 10) }}…</li>
                                @endforeach
                            </ul>
                        </div>
                        <div>
                            <p class="font-medium text-stone-900">Recorded artifacts</p>
                            <p class="mt-2 text-stone-600">{{ $decisionCount }} decisions · {{ $run->approvals_count }} approvals · {{ $run->exportBatches->count() }} exports · {{ $confirmationCount }} confirmations</p>
                            @if ($run->verificationConfirmation)
                                <p class="mt-1 text-emerald-700">Verifies {{ $run->verificationConfirmation->snapshot_filename }}</p>
                            @endif
                        </div>
                    </div>
                </article>
            </li>
        @endforeach
    </ol>
</section>
@endsection
