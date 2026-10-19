@extends('layouts.app')

@section('title', 'Reconciliation workspace')

@section('content')
<section class="mx-auto max-w-5xl px-4 py-10 sm:px-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Independent demo · Synthetic data</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-stone-900">Reconciliation workspace</h1>
            <p class="mt-3 max-w-2xl text-stone-600">Load the donation, payout, and CRM reports that make up one frozen reconciliation run.</p>
        </div>
        <form method="POST" action="{{ route('try.reset') }}">
            @csrf
            <button class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-800 hover:bg-stone-50">Reset workspace</button>
        </form>
    </div>

    <dl class="mt-8 grid gap-4 rounded-xl border border-stone-200 bg-white p-5 text-sm sm:grid-cols-3">
        <div>
            <dt class="text-stone-500">Scenario</dt>
            <dd class="font-medium text-stone-900">{{ $intent === 'guided' ? 'Guided reference scenario' : 'Scenario explorer' }}</dd>
        </div>
        <div>
            <dt class="text-stone-500">Time remaining</dt>
            <dd class="font-medium text-stone-900">{{ $minutesRemaining }} minutes</dd>
        </div>
        <div>
            <dt class="text-stone-500">Report limits</dt>
            <dd class="font-medium text-stone-900">{{ $maxCsvSizeMb }} MiB · {{ number_format($maxDataRows) }} rows each</dd>
        </div>
    </dl>

    @if ($canonicalCounts->isNotEmpty())
        <dl class="mt-4 grid gap-4 rounded-xl border border-stone-200 bg-stone-50 p-5 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-stone-500">Logical gifts</dt>
                <dd class="mt-1 text-2xl font-semibold text-stone-900">{{ number_format((int) ($canonicalCounts['gift'] ?? 0)) }}</dd>
            </div>
            <div>
                <dt class="text-stone-500">Payout components</dt>
                <dd class="mt-1 text-2xl font-semibold text-stone-900">{{ number_format((int) ($canonicalCounts['payout_component'] ?? 0)) }}</dd>
            </div>
            <div>
                <dt class="text-stone-500">CRM records</dt>
                <dd class="mt-1 text-2xl font-semibold text-stone-900">{{ number_format((int) ($canonicalCounts['crm_record'] ?? 0)) }}</dd>
            </div>
        </dl>
    @endif

    @if (session('review_saved'))
        <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">
            {{ session('review_saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    @if ($reconciliationRun)
        <section class="mt-8 rounded-xl border border-stone-200 bg-white p-6" aria-labelledby="reconciliation-summary-heading">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Current automated assessment</p>
                    <h2 id="reconciliation-summary-heading" class="mt-1 text-xl font-semibold text-stone-900">Reconciliation outcomes</h2>
                </div>
                <p class="text-xs text-stone-500">Rule {{ $reconciliationRun->rule_version }} · {{ $reconciliationRun->completed_at?->format('j M Y, H:i T') }}</p>
            </div>
            <p class="mt-3 text-sm text-stone-600">These results use exact identifiers and conservative payout rules. Candidate matches and unmapped campaigns stay held until reviewed.</p>

            @php
                $outcomeLabels = [
                    'create' => 'Proposed creates',
                    'update' => 'Proposed updates',
                    'unchanged' => 'Unchanged gifts',
                    'held' => 'Held gifts',
                ];
            @endphp
            <dl class="mt-5 grid gap-3 sm:grid-cols-4">
                @foreach ($outcomeLabels as $outcome => $label)
                    <div class="rounded-lg bg-stone-50 p-4">
                        <dt class="text-xs text-stone-500">{{ $label }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-stone-900">{{ number_format((int) ($reconciliationRun->outcome_counts[$outcome] ?? 0)) }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-6">
                <h3 class="text-sm font-semibold text-stone-900">Payout checks</h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    @foreach ($reconciliationRun->payouts as $payout)
                        <article class="rounded-lg border border-stone-200 p-4 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <h4 class="font-medium text-stone-900">{{ $payout->payout_id }}</h4>
                                <span class="rounded-full bg-stone-100 px-2 py-1 text-xs text-stone-700">{{ str_replace('_', ' ', $payout->state) }}</span>
                            </div>
                            <p class="mt-2 text-stone-600">{{ $payout->currency }} {{ number_format($payout->component_total_minor / 100, 2) }} from {{ number_format($payout->component_count) }} components</p>
                            @if ($payout->difference_minor !== null)
                                <p class="mt-1 text-xs text-stone-500">Difference: {{ $payout->currency }} {{ number_format($payout->difference_minor / 100, 2) }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($reviewOptions['campaigns'] !== [] || $reviewOptions['matches'] !== [])
        <section class="mt-8 rounded-xl border border-amber-200 bg-amber-50 p-6" aria-labelledby="review-heading">
            <p class="text-sm font-medium uppercase tracking-wide text-amber-800">Human review required</p>
            <h2 id="review-heading" class="mt-1 text-xl font-semibold text-stone-900">Review held records</h2>
            <p class="mt-3 max-w-3xl text-sm text-stone-700">Each saved decision is retained as evidence and creates a new immutable reconciliation run. Confirm only values supported by the source reports.</p>

            @if ($reviewOptions['campaigns'] !== [])
                <div class="mt-6 space-y-4">
                    <h3 class="text-sm font-semibold text-stone-900">Map campaign codes</h3>
                    @foreach ($reviewOptions['campaigns'] as $campaign)
                        <form class="rounded-lg border border-amber-200 bg-white p-4" method="POST" action="{{ route('try.review.campaigns.store') }}">
                            @csrf
                            <input type="hidden" name="campaign_code" value="{{ $campaign['campaign_code'] }}">
                            <div class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                <div>
                                    <p class="text-xs text-stone-500">Partner campaign code</p>
                                    <p class="mt-1 font-medium text-stone-900">{{ $campaign['campaign_code'] }}</p>
                                    <p class="mt-1 text-xs text-stone-500">Gift {{ implode(', ', $campaign['transaction_ids']) }}</p>
                                </div>
                                <label class="block text-sm font-medium text-stone-800">
                                    Destination campaign ID
                                    <input class="mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600" name="campaign_id" type="text" maxlength="255" required value="{{ old('campaign_id', $campaign['suggested_campaign_id'] ?? '') }}">
                                </label>
                                <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800" type="submit">Save mapping</button>
                            </div>
                        </form>
                    @endforeach
                </div>
            @endif

            @if ($reviewOptions['matches'] !== [])
                <div class="mt-6 space-y-4">
                    <h3 class="text-sm font-semibold text-stone-900">Confirm CRM candidates</h3>
                    @foreach ($reviewOptions['matches'] as $matchIndex => $match)
                        <form class="rounded-lg border border-amber-200 bg-white p-4" method="POST" action="{{ route('try.review.matches.store') }}">
                            @csrf
                            <input type="hidden" name="transaction_id" value="{{ $match['transaction_id'] }}">
                            <fieldset>
                                <legend class="font-medium text-stone-900">Gift {{ $match['transaction_id'] }}</legend>
                                <p class="mt-1 text-xs text-stone-500">
                                    {{ $match['currency'] }} {{ number_format($match['gross_amount_minor'] / 100, 2) }} · {{ $match['gifted_at'] }} · {{ $match['donor_ref'] ?? $match['donor_email'] ?? 'No donor reference' }}
                                </p>
                                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                    @foreach ($match['candidates'] as $candidateIndex => $candidate)
                                        <label class="flex cursor-pointer gap-3 rounded-lg border border-stone-200 p-3" for="match-{{ $matchIndex }}-candidate-{{ $candidateIndex }}">
                                            <input id="match-{{ $matchIndex }}-candidate-{{ $candidateIndex }}" name="crm_record_id" type="radio" required value="{{ $candidate['crm_record_id'] }}">
                                            <span class="text-sm">
                                                <span class="block font-medium text-stone-900">{{ $candidate['crm_record_id'] }}</span>
                                                <span class="mt-1 block text-stone-600">{{ $candidate['currency'] }} {{ number_format($candidate['gross_amount_minor'] / 100, 2) }} · {{ $candidate['gifted_at'] }}</span>
                                                <span class="mt-1 block text-xs text-stone-500">{{ $candidate['donor_ref'] ?? $candidate['donor_email'] ?? 'No donor reference' }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <button class="mt-4 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800" type="submit">Confirm CRM match</button>
                        </form>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    @php
        $reportCards = [
            'donations' => ['Partner donations', 'Gift identity, amount, status, campaign, and revision evidence.'],
            'payouts' => ['Partner payouts', 'Settlement components, fees, refunds, reversals, and reported totals.'],
            'crm' => ['Existing CRM snapshot', 'Current destination records and declared snapshot coverage.'],
        ];
    @endphp

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        @foreach ($reportCards as $type => [$label, $description])
            @php
                $batch = $imports->get($type);
                $fixture = $fixtureReports[$type];
                $statusClasses = match ($batch?->status) {
                    \App\Models\ImportBatch::STATUS_VALIDATED => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                    \App\Models\ImportBatch::STATUS_VALIDATION_FAILED => 'border-red-200 bg-red-50 text-red-900',
                    default => 'border-amber-200 bg-amber-50 text-amber-950',
                };
            @endphp
            <article class="rounded-xl border border-stone-200 bg-white p-6">
                <h2 class="text-lg font-semibold text-stone-900">{{ $label }}</h2>
                <p class="mt-2 text-sm text-stone-600">{{ $description }}</p>

                <a class="mt-4 inline-flex text-sm font-medium text-emerald-700 hover:text-emerald-800" href="{{ route('reference-fixtures.download', $type) }}">
                    Download sample · {{ number_format($fixture['expected_rows']) }} rows
                </a>

                @if ($batch)
                    <div class="mt-5 rounded-lg border p-4 {{ $statusClasses }}">
                        <p class="text-sm font-medium">Loaded {{ number_format($batch->total_rows) }} rows</p>
                        <p class="mt-1 text-xs">{{ $batch->original_filename }} · {{ str_replace('_', ' ', $batch->status) }}</p>
                        @if ($batch->mappingProfile)
                            <p class="mt-1 text-xs">{{ $batch->mappingProfile->adapter_profile }} · mapping v{{ $batch->mappingProfile->version }}</p>
                        @endif
                        @if ($batch->validated_at)
                            <p class="mt-1 text-xs">{{ number_format($batch->valid_rows) }} valid · {{ number_format($batch->rejected_rows) }} rejected</p>
                        @endif
                        @if ($batch->materialized_at)
                            <p class="mt-1 text-xs">{{ number_format($batch->materialized_rows) }} source rows materialized@if ($batch->duplicate_rows > 0) · {{ number_format($batch->duplicate_rows) }} duplicate occurrence@endif</p>
                        @endif
                        <a class="mt-3 inline-flex text-sm font-medium underline" href="{{ route('try.imports.show', $batch) }}">Review loaded rows</a>
                    </div>
                @else
                    <form class="mt-5" method="POST" enctype="multipart/form-data" action="{{ route('try.imports.store') }}" data-upload-form data-upload-url="{{ route('try.imports.store') }}" data-max-bytes="{{ (int) config('reconciliation.max_csv_size_bytes') }}">
                        @csrf
                        <input type="hidden" name="report_type" value="{{ $type }}">
                        <label class="block rounded-lg border border-dashed border-stone-300 p-4 text-center" data-drop-zone>
                            <span class="block text-sm font-medium text-stone-800">Choose CSV</span>
                            <span class="mt-1 block text-xs text-stone-500" data-file-label>No file selected</span>
                            <input class="sr-only" type="file" name="file" accept=".csv,text/csv" data-file-input>
                        </label>
                        <p class="mt-3 hidden text-sm text-red-700" data-upload-error role="alert"></p>
                        <p class="mt-3 hidden text-sm text-stone-600" data-upload-progress></p>
                        <button class="mt-4 w-full rounded-lg bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800" type="submit" data-upload-submit>Load report</button>
                    </form>
                    @unless ($arbitraryUploadsAllowed)
                        <p class="mt-3 text-xs text-stone-500">Public demo uploads must match this report's supplied sample.</p>
                    @endunless
                @endif
            </article>
        @endforeach
    </div>
</section>
@endsection
