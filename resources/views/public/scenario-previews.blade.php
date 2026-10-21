@foreach ($sampleScenarios as $scenarioId => $sample)
    <dialog id="preview-{{ $scenarioId }}" data-scenario-preview="{{ $scenarioId }}" aria-labelledby="preview-title-{{ $scenarioId }}"
        class="m-auto max-h-[90dvh] w-[min(96vw,80rem)] max-w-none overflow-y-auto rounded-xl border border-stone-200 bg-white p-0 text-stone-900 shadow-xl backdrop:bg-stone-900/50">
        <div class="flex items-start justify-between gap-4 border-b border-stone-200 p-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Source CSVs · Synthetic data</p>
                <h2 id="preview-title-{{ $scenarioId }}" class="mt-1 text-xl font-semibold">{{ $sample['title'] }}</h2>
                <p class="mt-2 text-sm text-stone-600">Highlighted rows explain this scenario. These notes are a guide; the original CSV values are unchanged.</p>
            </div>
            <button type="button" data-preview-close autofocus class="shrink-0 rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium hover:bg-stone-100">Close</button>
        </div>
        <div class="p-5">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div role="tablist" aria-label="Source CSV" class="flex gap-2">
                    @foreach (['donations' => 'Donations', 'payouts' => 'Payouts', 'crm' => 'CRM records'] as $type => $label)
                        <button type="button" role="tab" id="tab-{{ $scenarioId }}-{{ $type }}" aria-controls="panel-{{ $scenarioId }}-{{ $type }}"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" data-preview-tab="{{ $type }}"
                            class="rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium hover:bg-stone-100 aria-selected:border-emerald-700 aria-selected:bg-emerald-700 aria-selected:text-white">{{ $label }}</button>
                    @endforeach
                </div>
                <label class="flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" data-highlight-filter class="accent-emerald-700">
                    Only show highlighted rows
                </label>
            </div>
            @foreach ($samplePreviews[$scenarioId] as $type => $preview)
                <section role="tabpanel" id="panel-{{ $scenarioId }}-{{ $type }}" aria-labelledby="tab-{{ $scenarioId }}-{{ $type }}" data-preview-panel="{{ $type }}" @if (! $loop->first) hidden @endif>
                    <p class="my-3 text-sm text-stone-600"><span class="font-medium">{{ $preview['filename'] }}</span> · <span data-preview-count aria-live="polite">Showing {{ count($preview['rows']) }} of {{ count($preview['rows']) }} rows</span></p>
                    <div class="max-h-[45dvh] overflow-auto rounded-lg border border-stone-200 focus-visible:outline-2 focus-visible:outline-emerald-700" tabindex="0" role="region" aria-label="{{ $preview['filename'] }} table; scroll to see all columns">
                        <table class="min-w-full border-separate border-spacing-0 text-left text-sm">
                            <caption class="sr-only">Original {{ $preview['filename'] }} rows with scenario explanations. Row numbers exclude the header.</caption>
                            <thead class="sticky top-0 z-10 bg-stone-100 text-xs text-stone-700">
                                <tr>
                                    <th scope="col" class="border-b border-stone-200 px-3 py-3">Row</th>
                                    <th scope="col" class="min-w-64 border-b border-stone-200 px-3 py-3">Why it matters</th>
                                    @foreach ($preview['headers'] as $header)
                                        <th scope="col" class="whitespace-nowrap border-b border-stone-200 px-3 py-3">{{ $header }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['rows'] as $row)
                                    <tr data-preview-row data-highlighted="{{ $row['note'] !== null ? 'true' : 'false' }}" @class(['bg-amber-50' => $row['note'] !== null])>
                                        <th scope="row" class="border-b border-stone-200 px-3 py-3 align-top font-medium">{{ $row['number'] }}</th>
                                        <td class="max-w-80 border-b border-stone-200 px-3 py-3 align-top text-stone-700">
                                            @if ($row['note'])
                                                <span class="mb-1 block text-xs font-semibold text-amber-900">Highlighted</span>
                                                {{ $row['note'] }}
                                            @else
                                                <span class="text-stone-500">Context row</span>
                                            @endif
                                        </td>
                                        @foreach ($preview['headers'] as $header)
                                            <td class="whitespace-nowrap border-b border-stone-200 px-3 py-3 align-top font-mono text-xs">{{ $row['values'][$header] }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
        <div class="border-t border-stone-200 bg-stone-50 p-5">
            <p class="text-sm text-stone-700" role="status" data-scenario-status></p>
            <p class="text-sm text-red-800" role="alert" data-scenario-error></p>
            @include('public.scenario-results-link')
            <p class="mt-2 text-xs text-stone-500">You can keep browsing these CSVs while a sample is processing. When its results are ready, this preview stays open.</p>
        </div>
    </dialog>
@endforeach
