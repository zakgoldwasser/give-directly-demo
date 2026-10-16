@extends('layouts.app')

@section('title', 'Try GivingLedger')

@section('content')
<section class="mx-auto max-w-5xl px-4 py-12 sm:px-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-3xl font-semibold tracking-tight text-stone-900">Temporary workspace</h1>
            <p class="mt-2 text-stone-600">
                Your guest session is active. CSV processing arrives in a later milestone—this screen confirms isolation, limits, and expiry.
            </p>
        </div>
        <form method="POST" action="{{ route('try.reset') }}">
            @csrf
            <button type="submit" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-800 hover:bg-stone-50">
                Reset workspace
            </button>
        </form>
    </div>

    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500">Session</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-stone-500">Intent</dt>
                    <dd class="font-medium text-stone-900">{{ $intent === 'sample' ? 'Try with sample data' : ($intent === 'upload' ? 'Upload your CSV' : 'Not set') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-stone-500">Time remaining</dt>
                    <dd class="font-medium text-stone-900">{{ $minutesRemaining }} minute{{ $minutesRemaining === 1 ? '' : 's' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-stone-500">Expires at</dt>
                    <dd class="font-medium text-stone-900">{{ $workspace->expires_at->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-stone-500">State</dt>
                    <dd class="font-medium text-emerald-700">{{ ucfirst($workspace->state) }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500">Configured limits</h2>
            <ul class="mt-4 space-y-2 text-sm text-stone-700">
                <li>Maximum CSV size: {{ $maxCsvSizeMb }} MB</li>
                <li>Maximum data rows: {{ number_format($maxDataRows) }}</li>
                <li>Maximum columns: {{ $maxColumns }}</li>
                <li>Maximum field length: {{ number_format($maxFieldLength) }} characters</li>
                <li>Maximum active imports: {{ $maxActiveImports }}</li>
                <li>Workspace lifetime: {{ $workspaceLifetimeMinutes }} minutes</li>
            </ul>
        </div>
    </div>

    <div class="mt-8 rounded-xl border border-dashed border-stone-300 bg-stone-50 px-6 py-10 text-center">
        <p class="text-lg font-medium text-stone-800">Upload area (coming next)</p>
        <p class="mt-2 text-sm text-stone-600">
            @if($intent === 'sample')
                Sample data loading and CSV processing are not available in Milestone 1. Your isolated workspace is ready for those features.
            @else
                Drag-and-drop upload and CSV parsing are not available in Milestone 1. Your isolated workspace is ready for those features.
            @endif
        </p>
    </div>
</section>
@endsection
