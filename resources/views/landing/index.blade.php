@extends('layouts.app')

@section('title', config('app.name').' — Partner donation reconciliation')

@section('content')
<section class="mx-auto max-w-5xl px-4 py-12 sm:px-6 sm:py-16">
    <div class="max-w-3xl">
        <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Independent demo · Synthetic data</p>
        <h1 class="mt-3 text-4xl font-semibold tracking-tight text-stone-900 sm:text-5xl">
            Reconcile partner donations, payouts, and CRM records with inspectable evidence.
        </h1>
    </div>

    <ul id="how-it-works" class="mt-8 grid scroll-mt-6 list-disc grid-cols-1 gap-x-8 gap-y-5 pl-5 sm:grid-cols-2 lg:grid-cols-3 text-sm leading-6 text-stone-600 marker:text-emerald-700">
        <li><strong class="font-semibold text-stone-900">Bring three CSVs together:</strong> donation reports, payout reports, and existing CRM records.</li>
        <li><strong class="font-semibold text-stone-900">Organize the data:</strong> map each file’s columns into a consistent format while preserving the original rows.</li>
        <li><strong class="font-semibold text-stone-900">Check for problems:</strong> flag missing information, invalid values, duplicate rows, and conflicting records.</li>
        <li><strong class="font-semibold text-stone-900">Match donations to CRM records:</strong> use identifiers to find existing donations and flag uncertain matches for review.</li>
        <li><strong class="font-semibold text-stone-900">Check payout totals:</strong> compare reported totals with donation, fee, refund, and reversal components to identify differences.</li>
        <li><strong class="font-semibold text-stone-900">Sort the results:</strong> show which donations need to be added, updated, left unchanged, or held for review.</li>
        <li><strong class="font-semibold text-stone-900">Guide your review:</strong> resolve campaign mappings and confirm supported matches, with evidence behind each decision.</li>
    </ul>

    <section id="sample-scenarios" class="mt-12 scroll-mt-6" aria-labelledby="sample-scenarios-heading">
        <h2 id="sample-scenarios-heading" class="text-2xl font-semibold text-stone-900">Choose a sample dataset</h2>
        <p class="mt-2 mb-6 text-stone-600">Preview the source CSVs or load all three reports. You can keep exploring the previews while your sample loads.</p>
        @if ($errors->any())
            <p class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-900" role="alert">{{ $errors->first() }}</p>
        @endif
        @include('public.scenario-cards', ['sampleScenarios' => $sampleScenarios, 'selectedScenarioId' => null])
    </section>

    <div class="mt-12 rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-950">
        <h2 class="font-semibold">Demonstration boundary</h2>
        <p class="mt-2">This independent demo uses synthetic data. It does not write to Salesforce, confirm bank receipt, or represent an actual CAF export contract.</p>
    </div>
</section>
@endsection
