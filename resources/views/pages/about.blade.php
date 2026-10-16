@extends('layouts.app')

@section('title', 'About — '.config('app.name'))

@section('content')
<article class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
    <h1 class="text-3xl font-semibold tracking-tight text-stone-900">About GivingLedger</h1>
    <p class="mt-4 text-lg text-stone-600">
        GivingLedger is an open-source utility that helps nonprofits prepare messy donation spreadsheets for CRM import.
    </p>
    <div class="mt-8 space-y-4 text-stone-700">
        <p>
            Phase 1 is a public tool: no accounts, temporary workspaces, and a complete cleanup workflow ending in a clean CSV export.
        </p>
        <p>
            The processing engine is designed to stay conservative with financial data—ambiguous amounts and dates are surfaced for review instead of silently guessed.
        </p>
        @if($githubUrl !== '#')
            <p>
                <a href="{{ $githubUrl }}" class="font-medium text-emerald-800 underline" target="_blank" rel="noopener noreferrer">View the project on GitHub</a>
            </p>
        @endif
        <p>
            <a href="{{ route('privacy') }}" class="font-medium text-emerald-800 underline">Read the privacy notice</a>
        </p>
    </div>
</article>
@endsection
