@extends('layouts.app')

@section('title', 'Workspace expired')

@section('content')
<section class="mx-auto max-w-2xl px-4 py-16 sm:px-6">
    <div class="rounded-xl border border-amber-200 bg-amber-50 px-6 py-8 text-center">
        <h1 class="text-3xl font-semibold tracking-tight text-stone-900">Workspace expired</h1>
        <p class="mt-4 text-stone-700">
            This temporary guest workspace has expired. Uploaded files, staged records, and exports from the session are no longer available.
        </p>
        <p class="mt-2 text-sm text-stone-600">
            You can start again with a fresh workspace. No account is required.
        </p>
        <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <form method="POST" action="{{ route('try.start') }}">
                @csrf
                <input type="hidden" name="intent" value="guided">
                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-emerald-700 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-800">
                    Start guided demo
                </button>
            </form>
            <a href="{{ route('landing') }}" class="inline-flex items-center justify-center rounded-lg border border-stone-300 bg-white px-5 py-3 text-sm font-semibold text-stone-800 hover:bg-stone-50">
                Back to home
            </a>
        </div>
    </div>
</section>
@endsection
