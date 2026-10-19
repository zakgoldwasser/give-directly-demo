@extends('layouts.app')

@section('title', 'Operation could not complete')

@section('content')
<section class="mx-auto max-w-xl px-4 py-16 sm:px-6">
    <div class="rounded-xl border border-red-200 bg-white p-8 shadow-sm">
        <p class="text-sm font-medium uppercase tracking-wide text-red-700">{{ $error['code'] }}</p>
        <h1 class="mt-2 text-2xl font-semibold text-stone-900">Operation could not complete</h1>
        <p class="mt-4 text-stone-700">{{ $error['message'] }}</p>
        <p class="mt-3 break-all text-xs text-stone-500">Trace ID: {{ $error['trace_id'] }}</p>
        <a class="mt-6 inline-flex rounded-lg bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800" href="{{ route('try.show') }}">Return to workspace</a>
    </div>
</section>
@endsection
