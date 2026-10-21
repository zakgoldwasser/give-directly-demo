@extends('layouts.app')
@section('title', 'Imported reconciliation report')
@section('content')
<section class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
    <a class="text-sm font-medium text-emerald-700 hover:text-emerald-800" href="{{ route('try.show') }}">← Back to report set</a>
    @include('public.import-content', ['modal' => false])
</section>
@endsection
