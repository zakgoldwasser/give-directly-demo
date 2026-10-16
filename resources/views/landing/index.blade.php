@extends('layouts.app')

@section('title', config('app.name').' — Clean donation CSV exports')

@section('content')
<section class="mx-auto max-w-5xl px-4 py-12 sm:px-6 sm:py-16">
    <div class="max-w-3xl">
        <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Public utility · No login</p>
        <h1 class="mt-3 text-4xl font-semibold tracking-tight text-stone-900 sm:text-5xl">
            Turn messy donation spreadsheets into clean, trustworthy, CRM-ready data.
        </h1>
        <p class="mt-5 text-lg text-stone-600">
            GivingLedger validates, normalizes, and helps you review donation CSV files before you import them elsewhere.
            Start with sample data or bring your own file—no registration required.
        </p>
    </div>

    <div id="try" class="mt-10 flex flex-col gap-3 sm:flex-row">
        <form method="POST" action="{{ route('try.start') }}">
            @csrf
            <input type="hidden" name="intent" value="sample">
            <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-emerald-700 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 sm:w-auto">
                Try with sample data
            </button>
        </form>
        <form method="POST" action="{{ route('try.start') }}">
            @csrf
            <input type="hidden" name="intent" value="upload">
            <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-stone-300 bg-white px-5 py-3 text-sm font-semibold text-stone-800 shadow-sm hover:bg-stone-50 sm:w-auto">
                Upload your CSV
            </button>
        </form>
    </div>

    <p class="mt-4 max-w-2xl text-sm text-stone-500">
        Guest sessions last {{ $workspaceLifetimeMinutes }} minutes. Uploads are capped at {{ $maxCsvSizeMb }} MB and {{ number_format($maxDataRows) }} rows.
        The public deployment is intended for synthetic or non-sensitive data until an appropriate privacy review has been completed.
    </p>
</section>

<section id="how-it-works" class="border-y border-stone-200 bg-white">
    <div class="mx-auto max-w-5xl px-4 py-12 sm:px-6">
        <h2 class="text-2xl font-semibold text-stone-900">How it works</h2>
        <ol class="mt-8 grid gap-6 sm:grid-cols-3">
            <li class="rounded-xl border border-stone-200 bg-stone-50 p-5">
                <p class="text-sm font-semibold text-emerald-700">1. Start</p>
                <p class="mt-2 text-stone-700">Open a temporary workspace with sample data or a CSV upload.</p>
            </li>
            <li class="rounded-xl border border-stone-200 bg-stone-50 p-5">
                <p class="text-sm font-semibold text-emerald-700">2. Map &amp; validate</p>
                <p class="mt-2 text-stone-700">Map columns, normalize amounts and dates, then review validation issues.</p>
            </li>
            <li class="rounded-xl border border-stone-200 bg-stone-50 p-5">
                <p class="text-sm font-semibold text-emerald-700">3. Export</p>
                <p class="mt-2 text-stone-700">Resolve exceptions and download a clean CSV ready for your CRM.</p>
            </li>
        </ol>
    </div>
</section>

<section class="mx-auto max-w-5xl px-4 py-12 sm:px-6">
    <h2 class="text-2xl font-semibold text-stone-900">Example validation outcomes</h2>
    <ul class="mt-6 space-y-3 text-stone-700">
        <li class="rounded-lg border border-stone-200 bg-white px-4 py-3"><span class="font-medium text-red-700">Error:</span> Amount “1.250,00” is ambiguous without a number locale.</li>
        <li class="rounded-lg border border-stone-200 bg-white px-4 py-3"><span class="font-medium text-amber-700">Warning:</span> Two rows share the same donor, date, and amount—possible duplicate.</li>
        <li class="rounded-lg border border-stone-200 bg-white px-4 py-3"><span class="font-medium text-emerald-700">Ready:</span> USD 125.50 normalized to exact minor units with original values preserved.</li>
    </ul>

    <div class="mt-10 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-950">
        <p class="font-semibold">Privacy &amp; retention</p>
        <p class="mt-2">
            Guest workspaces are isolated to your browser session and expire after {{ $workspaceLifetimeMinutes }} minutes.
            Expired uploads, staged records, and exports are deleted. GivingLedger does not verify whether payments were received.
            Prefer synthetic data on the public demo until a privacy review is complete.
        </p>
        <p class="mt-3">
            <a href="{{ route('privacy') }}" class="font-medium text-emerald-800 underline">Read the privacy notice</a>
            @if($githubUrl !== '#')
                · <a href="{{ $githubUrl }}" class="font-medium text-emerald-800 underline" target="_blank" rel="noopener noreferrer">View on GitHub</a>
            @endif
        </p>
    </div>
</section>
@endsection
