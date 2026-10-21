@extends('layouts.app')

@section('title', 'Privacy and retention')

@section('content')
<section class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
    <h1 class="text-3xl font-semibold tracking-tight text-stone-900">Privacy and retention</h1>
    <div class="mt-6 space-y-5 text-stone-700">
        <p>The demonstration offers synthetic sample datasets and accepts your own donation, payout, and CRM CSV uploads.</p>
        <p>Each visitor receives an isolated, session-bound workspace. Reports, staged rows, decisions, and exports expire 24 hours after creation and are removed by retryable cleanup within the following 24 hours.</p>
        <p>Uploaded files are stored privately and processed within your temporary workspace. File-size, row-count, and processing limits apply.</p>
        <p>The application reconciles the supplied evidence. It does not confirm bank receipt, write to a CRM, issue tax receipts, or send donor communications.</p>
    </div>
</section>
@endsection
