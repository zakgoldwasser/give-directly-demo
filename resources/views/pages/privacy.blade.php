@extends('layouts.app')

@section('title', 'Privacy — '.config('app.name'))

@section('content')
<article class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
    <h1 class="text-3xl font-semibold tracking-tight text-stone-900">Privacy notice</h1>
    <p class="mt-4 text-stone-600">
        GivingLedger’s public Phase 1 demo processes files in temporary guest workspaces. This notice summarizes how that works before upload features are enabled.
    </p>

    <div class="prose prose-stone mt-8 max-w-none space-y-6 text-stone-700">
        <section>
            <h2 class="text-xl font-semibold text-stone-900">What information is uploaded</h2>
            <p class="mt-2">When CSV upload is available, visitors may upload donation spreadsheet data they choose to process. Prefer synthetic or non-sensitive data on the public deployment.</p>
        </section>
        <section>
            <h2 class="text-xl font-semibold text-stone-900">How processing occurs</h2>
            <p class="mt-2">Uploaded files are parsed and validated on the server using GivingLedger’s processing engine. Results stay inside the visitor’s temporary workspace.</p>
        </section>
        <section>
            <h2 class="text-xl font-semibold text-stone-900">Whether data reaches the server</h2>
            <p class="mt-2">Yes. Files and review decisions are stored temporarily on the server for the duration of the guest workspace.</p>
        </section>
        <section>
            <h2 class="text-xl font-semibold text-stone-900">How long data is stored</h2>
            <p class="mt-2">Guest workspaces expire after {{ $workspaceLifetimeMinutes }} minutes by default. Expired workspaces and their associated temporary data are deleted.</p>
        </section>
        <section>
            <h2 class="text-xl font-semibold text-stone-900">Security limits</h2>
            <p class="mt-2">Default limits include a {{ $maxCsvSizeMb }} MB maximum file size and {{ number_format($maxDataRows) }} maximum data rows. Workspaces are bound to secure browser sessions and are not publicly enumerable.</p>
        </section>
        <section>
            <h2 class="text-xl font-semibold text-stone-900">How deletion works</h2>
            <p class="mt-2">Expiration is enforced on access. Scheduled cleanup removes expired data even if visitors never return.</p>
        </section>
        <section>
            <h2 class="text-xl font-semibold text-stone-900">Payment verification</h2>
            <p class="mt-2">GivingLedger does not verify whether money was received. It identifies inconsistencies and duplicate candidates within uploaded data.</p>
        </section>
        <section>
            <h2 class="text-xl font-semibold text-stone-900">Sensitive production data</h2>
            <p class="mt-2">The public deployment is not yet approved for sensitive production donor data. A deletion policy is not a substitute for privacy, security, and legal review.</p>
        </section>
    </div>
</article>
@endsection
