<div class="grid gap-4 md:grid-cols-2">
    @foreach ($sampleScenarios as $scenarioId => $sample)
        @php($isSelected = ($selectedScenarioId ?? null) === $scenarioId)
        <article @class([
            'flex flex-col gap-4 rounded-xl border p-6',
            'border-emerald-600 bg-emerald-50' => $isSelected,
            'border-stone-200 bg-white' => ! $isSelected,
        ])>
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ $sample['badge'] }}</p>
            <h3 class="text-lg font-semibold text-stone-900">{{ $sample['title'] }}</h3>
            <div class="text-sm text-stone-600">
                <p class="mb-1 font-semibold text-stone-800">What the CSVs contain</p>
                <p>{{ $sample['contains'] }}</p>
            </div>
            <div class="text-sm text-stone-600">
                <p class="mb-1 font-semibold text-stone-800">What it demonstrates</p>
                <p>{{ $sample['demonstrates'] }}</p>
            </div>
            <div class="mt-auto flex flex-wrap items-center gap-3 pt-2">
                <button type="button" data-preview-open="{{ $scenarioId }}" aria-haspopup="dialog" aria-controls="preview-{{ $scenarioId }}"
                    aria-label="View sample CSVs: {{ $sample['title'] }}"
                    class="rounded-lg border border-emerald-700 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700">
                    View sample CSVs
                </button>
                <form method="POST" action="{{ route('try.start') }}" data-scenario-form data-scenario-title="{{ $sample['title'] }}">
                    @csrf
                    <input type="hidden" name="intent" value="guided">
                    <input type="hidden" name="scenario_id" value="{{ $scenarioId }}">
                    <button type="submit" aria-label="{{ $isSelected ? 'Loaded: ' : 'Load sample: ' }}{{ $sample['title'] }}" @disabled($isSelected)
                        class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:cursor-default disabled:opacity-60">
                        <span data-scenario-label>{{ $isSelected ? 'Currently loaded' : 'Load this sample' }}</span>
                    </button>
                </form>
            </div>
        </article>
    @endforeach
</div>
<div hidden class="mt-4 rounded-lg border border-stone-200 bg-white p-4" data-scenario-feedback>
    <p class="text-sm text-stone-700" role="status" data-scenario-status></p>
    <p class="text-sm text-red-800" role="alert" data-scenario-error></p>
    <a hidden data-scenario-results class="mt-3 inline-flex rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">View reconciliation</a>
</div>
@include('public.scenario-previews', ['sampleScenarios' => $sampleScenarios, 'samplePreviews' => $samplePreviews])
