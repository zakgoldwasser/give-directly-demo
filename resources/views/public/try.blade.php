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

    @if ($errors->any())
        <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert">
            {{ $errors->first() }}
        </div>
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
