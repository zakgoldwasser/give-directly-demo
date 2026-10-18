@extends('layouts.app')

@section('title', config('app.name').' — Partner donation reconciliation')

@section('content')
<section class="mx-auto max-w-5xl px-4 py-12 sm:px-6 sm:py-16">
    <div class="max-w-3xl">
        <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Independent demo · Synthetic data</p>
        <h1 class="mt-3 text-4xl font-semibold tracking-tight text-stone-900 sm:text-5xl">
            Reconcile partner donations, payouts, and CRM records with inspectable evidence.
        </h1>
        <p class="mt-5 text-lg text-stone-600">
            Load a controlled report set, review proposed CRM creates and updates, resolve exceptions, and export a traceable import package.
        </p>
        <div id="try" class="mt-8 flex flex-col gap-3 sm:flex-row">
            <form method="POST" action="{{ route('try.start') }}">
                @csrf
                <input type="hidden" name="intent" value="guided">
                <button class="w-full rounded-lg bg-emerald-700 px-5 py-3 font-medium text-white hover:bg-emerald-800 sm:w-auto">
                    Start guided demo
                </button>
            </form>
            <form method="POST" action="{{ route('try.start') }}">
                @csrf
                <input type="hidden" name="intent" value="scenarios">
                <button class="w-full rounded-lg border border-stone-300 bg-white px-5 py-3 font-medium text-stone-800 hover:bg-stone-50 sm:w-auto">
                    Explore scenarios
                </button>
            </form>
        </div>
    </div>

    <div id="how-it-works" class="mt-16 grid gap-6 md:grid-cols-3">
        <article class="rounded-xl border border-stone-200 bg-white p-6">
            <p class="text-sm font-semibold text-emerald-700">1 · Load reports</p>
            <h2 class="mt-2 text-xl font-semibold text-stone-900">Establish source context</h2>
            <p class="mt-3 text-stone-600">Import partner donations, payout components, and one CRM snapshot with explicit coverage and versions.</p>
        </article>
        <article class="rounded-xl border border-stone-200 bg-white p-6">
            <p class="text-sm font-semibold text-emerald-700">2 · Review evidence</p>
            <h2 class="mt-2 text-xl font-semibold text-stone-900">Resolve exceptions</h2>
            <p class="mt-3 text-stone-600">Inspect identity matches, field differences, campaign mappings, payout arithmetic, and blocking evidence.</p>
        </article>
        <article class="rounded-xl border border-stone-200 bg-white p-6">
            <p class="text-sm font-semibold text-emerald-700">3 · Export and check</p>
            <h2 class="mt-2 text-xl font-semibold text-stone-900">Prepare reviewed changes</h2>
            <p class="mt-3 text-stone-600">Create a versioned import package, then compare its expectations with a refreshed CRM snapshot.</p>
        </article>
    </div>

    <div class="mt-12 rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-950">
        <h2 class="font-semibold">Demonstration boundary</h2>
        <p class="mt-2">This independent demo uses synthetic data. It does not write to Salesforce, confirm bank receipt, or represent an actual CAF export contract.</p>
    </div>
</section>
@endsection
