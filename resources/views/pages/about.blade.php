@extends('layouts.app')

@section('title', 'About')

@section('content')
<section class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
    <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Independent demo · Synthetic data</p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-stone-900">About this reconciliation demo</h1>
    <p class="mt-5 text-stone-700">This project demonstrates how partner donation reports, payout reports, and an existing CRM snapshot can be reconciled into reviewed creates, updates, unchanged records, and held exceptions.</p>
    <p class="mt-4 text-stone-700">It is an independent candidate project. Its schemas, matching rules, and sample data are proposed contracts rather than claims about GiveDirectly, CAF, or Salesforce systems.</p>
</section>
@endsection
