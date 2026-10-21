<div class="grid gap-4 md:grid-cols-2">
    @foreach ($sampleScenarios as $scenarioId => $sample)
        @php($isSelected = ($selectedScenarioId ?? null) === $scenarioId)
        <form method="POST" action="{{ route('try.start') }}" data-scenario-form>
            @csrf
            <input type="hidden" name="intent" value="guided">
            <input type="hidden" name="scenario_id" value="{{ $scenarioId }}">
            <button type="submit" aria-label="{{ $isSelected ? 'Loaded: ' : 'Load sample: ' }}{{ $sample['title'] }}"
                @disabled($isSelected)
                @class([
                    'flex h-full w-full flex-col gap-4 rounded-xl border p-6 text-left transition focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-700',
                    'border-emerald-600 bg-emerald-50' => $isSelected,
                    'cursor-pointer border-stone-200 bg-white hover:border-emerald-600 hover:bg-emerald-50' => ! $isSelected,
                ])>
                <span class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ $sample['badge'] }}</span>
                <span class="text-lg font-semibold text-stone-900">{{ $sample['title'] }}</span>
                <span class="block text-sm text-stone-600">
                    <span class="mb-1 block font-semibold text-stone-800">What the CSVs contain</span>
                    {{ $sample['contains'] }}
                </span>
                <span class="block text-sm text-stone-600">
                    <span class="mb-1 block font-semibold text-stone-800">What it demonstrates</span>
                    {{ $sample['demonstrates'] }}
                </span>
                <span class="mt-auto pt-2 text-sm font-semibold text-emerald-700" data-scenario-label>{{ $isSelected ? 'Currently loaded' : 'Load this sample →' }}</span>
            </button>
        </form>
    @endforeach
</div>
<p class="mt-3 text-sm text-stone-500" role="status" data-scenario-status></p>
