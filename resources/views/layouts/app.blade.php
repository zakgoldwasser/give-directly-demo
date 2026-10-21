<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'Partner Donation Reconciliation'))</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 text-stone-900 antialiased">
    <header class="border-b border-stone-200 bg-white">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <a href="{{ route('landing') }}" class="text-lg font-semibold tracking-tight text-emerald-800">
                {{ config('app.name', 'Partner Donation Reconciliation') }}
            </a>
            <nav class="flex flex-wrap items-center gap-4 text-sm text-stone-600">
                <a href="{{ route('landing') }}#how-it-works" class="hover:text-emerald-800">How it works</a>
                <a href="{{ route('landing') }}#sample-scenarios" class="hover:text-emerald-800">Try the tool</a>
                @if(config('reconciliation.github_url') !== '#')
                    <a href="{{ config('reconciliation.github_url') }}" class="hover:text-emerald-800" target="_blank" rel="noopener noreferrer">GitHub</a>
                @else
                    <span class="text-stone-400" title="Repository link coming soon">GitHub</span>
                @endif
                <a href="{{ route('about') }}" class="hover:text-emerald-800">About</a>
                <a href="{{ route('privacy') }}" class="hover:text-emerald-800">Privacy</a>
            </nav>
        </div>
    </header>

    <main>
        <p class="sr-only" role="status" data-action-status></p>
        @yield('content')
    </main>

    <footer class="mt-16 border-t border-stone-200 bg-white">
        <div class="mx-auto max-w-5xl px-4 py-8 text-sm text-stone-500 sm:px-6">
            <p>{{ config('app.name') }} is an independent reconciliation demo with synthetic samples and CSV uploads.</p>
            <p class="mt-2">Temporary guest workspaces expire automatically. No account is required.</p>
        </div>
    </footer>
</body>
</html>
