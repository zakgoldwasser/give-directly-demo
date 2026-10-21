@extends('layouts.app')

@section('title', 'Run '.$run->sequence.' audit detail')

@section('content')
<section class="mx-auto max-w-5xl px-4 py-10 sm:px-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Immutable audit detail</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-stone-900">Reconciliation run {{ $run->sequence }}</h1>
            <p class="mt-3 font-mono text-xs text-stone-500">{{ $run->input_fingerprint }} · rule {{ $run->rule_version }}</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-800 hover:bg-stone-50" href="{{ route('try.history.index') }}">All runs</a>
            <a class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-800 hover:bg-stone-50" href="{{ route('try.show') }}">Workspace</a>
        </div>
    </div>

    <dl class="mt-8 grid gap-3 rounded-xl border border-stone-200 bg-white p-5 sm:grid-cols-4">
        @foreach (['create' => 'Creates', 'update' => 'Updates', 'unchanged' => 'Unchanged', 'held' => 'Held'] as $outcome => $label)
            <div>
                <dt class="text-xs text-stone-500">{{ $label }}</dt>
                <dd class="mt-1 text-2xl font-semibold text-stone-900">{{ $run->outcome_counts[$outcome] ?? 0 }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($run->verificationConfirmation)
        <section class="mt-8 rounded-xl border border-emerald-200 bg-emerald-50 p-6" aria-labelledby="verification-source-heading">
            <p class="text-sm font-medium uppercase tracking-wide text-emerald-700">Verification lineage</p>
            <h2 id="verification-source-heading" class="mt-1 text-xl font-semibold text-stone-900">Later CRM snapshot rerun</h2>
            <p class="mt-2 text-sm text-stone-700">This run verifies export {{ $run->verificationConfirmation->export_batch_id }} using {{ $run->verificationConfirmation->snapshot_filename }}.</p>
            <p class="mt-1 text-xs text-stone-600">Snapshot checksum {{ $run->verificationConfirmation->snapshot_checksum }}</p>
            @if ($run->verificationConfirmation->exportBatch?->run)
                <a class="mt-3 inline-flex text-sm font-medium text-emerald-700 hover:text-emerald-800" href="{{ route('try.history.runs.show', $run->verificationConfirmation->exportBatch->run) }}">Open source run {{ $run->verificationConfirmation->exportBatch->run->sequence }}</a>
            @endif
        </section>
    @endif

    <section class="mt-8 rounded-xl border border-stone-200 bg-white p-6" aria-labelledby="inputs-heading">
        <h2 id="inputs-heading" class="text-xl font-semibold text-stone-900">Frozen inputs</h2>
        <div class="mt-4 overflow-x-auto rounded-lg border border-stone-200">
            <table class="min-w-full divide-y divide-stone-200 text-left text-sm">
                <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-3" scope="col">Report</th>
                        <th class="px-4 py-3" scope="col">File</th>
                        <th class="px-4 py-3" scope="col">Checksum</th>
                        <th class="px-4 py-3" scope="col">Mapping</th>
                        <th class="px-4 py-3" scope="col">Current state</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200">
                    @foreach ($run->input_snapshot['imports'] ?? [] as $source)
                        @php($batch = $imports->get($source['id']))
                        <tr>
                            <th class="px-4 py-3 font-medium text-stone-900" scope="row">{{ ucfirst($source['report_type']) }}</th>
                            <td class="px-4 py-3">
                                @if ($batch)
                                    <a class="font-medium text-emerald-700 hover:text-emerald-800" href="{{ route('try.imports.show', $batch) }}">{{ $batch->original_filename }}</a>
                                @else
                                    Unavailable import
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-stone-600">{{ $source['checksum'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-stone-600">{{ $source['mapping_profile_id'] ?? 'none' }}</td>
                            <td class="px-4 py-3 text-stone-600">{{ $batch ? str_replace('_', ' ', $batch->status) : 'unavailable' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-8 rounded-xl border border-stone-200 bg-white p-6" aria-labelledby="decisions-heading">
        <h2 id="decisions-heading" class="text-xl font-semibold text-stone-900">Review decisions in this run</h2>
        @if ($reviewDecisions->isEmpty())
            <p class="mt-3 text-sm text-stone-600">No reviewer decisions were applied.</p>
        @else
            <div class="mt-4 overflow-x-auto rounded-lg border border-stone-200">
                <table class="min-w-full divide-y divide-stone-200 text-left text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                        <tr>
                            <th class="px-4 py-3" scope="col">Sequence</th>
                            <th class="px-4 py-3" scope="col">Decision</th>
                            <th class="px-4 py-3" scope="col">Subject</th>
                            <th class="px-4 py-3" scope="col">Selected value</th>
                            <th class="px-4 py-3" scope="col">Actor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        @foreach ($reviewDecisions as $decision)
                            <tr>
                                <td class="px-4 py-3 text-stone-600">{{ $decision->sequence }}</td>
                                <td class="px-4 py-3 text-stone-600">{{ str_replace('_', ' ', $decision->decision_type) }}</td>
                                <th class="px-4 py-3 font-medium text-stone-900" scope="row">{{ $decision->subject_key }}</th>
                                <td class="px-4 py-3 text-stone-600">{{ $decision->selected_value !== '' ? $decision->selected_value : ($decision->evidence['action'] ?? '') }}
                                    @if (isset($decision->evidence['reason']))<span class="block text-xs">{{ $decision->evidence['reason'] }}</span>@endif</td>
                                <td class="px-4 py-3 text-stone-600">{{ str_replace('_', ' ', $decision->actor_type) }} · {{ $decision->created_at->format('j M Y, H:i:s T') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="mt-8 rounded-xl border border-stone-200 bg-white p-6" aria-labelledby="gifts-heading">
        <h2 id="gifts-heading" class="text-xl font-semibold text-stone-900">Gift outcomes</h2>
        <div class="mt-4 overflow-x-auto rounded-lg border border-stone-200">
            <table class="min-w-full divide-y divide-stone-200 text-left text-sm">
                <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-3" scope="col">Gift</th>
                        <th class="px-4 py-3" scope="col">Outcome</th>
                        <th class="px-4 py-3" scope="col">CRM match</th>
                        <th class="px-4 py-3" scope="col">Payout</th>
                        <th class="px-4 py-3" scope="col">Evidence</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-200">
                    @foreach ($run->gifts as $gift)
                        <tr>
                            <th class="px-4 py-3 font-medium text-stone-900" scope="row">{{ $gift->transaction_id }}</th>
                            <td class="px-4 py-3 text-stone-600">{{ $gift->outcome }}</td>
                            <td class="px-4 py-3 text-stone-600">{{ str_replace('_', ' ', $gift->crm_match_state) }}</td>
                            <td class="px-4 py-3 text-stone-600">{{ str_replace('_', ' ', $gift->payout_state) }}</td>
                            <td class="px-4 py-3 text-xs text-stone-600">
                                @if ($gift->issue_codes !== [])
                                    {{ implode(', ', $gift->issue_codes) }}
                                @elseif ($gift->differences !== [])
                                    {{ implode(', ', array_map(fn ($difference) => str_replace('_', ' ', $difference['field']), $gift->differences)) }}
                                @else
                                    No exceptions
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-8 rounded-xl border border-stone-200 bg-white p-6" aria-labelledby="payouts-heading">
        <h2 id="payouts-heading" class="text-xl font-semibold text-stone-900">Payout outcomes</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            @foreach ($run->payouts as $payout)
                <article class="rounded-lg border border-stone-200 p-4 text-sm">
                    <p class="font-medium text-stone-900">{{ $payout->payout_id }}</p>
                    <p class="mt-1 text-stone-600">{{ str_replace('_', ' ', $payout->state) }} · {{ $payout->component_count }} components</p>
                    <p class="mt-1 text-xs text-stone-500">{{ $payout->currency }} {{ number_format($payout->component_total_minor / 100, 2) }}@if ($payout->difference_minor !== null) · difference {{ number_format($payout->difference_minor / 100, 2) }}@endif</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="mt-8 rounded-xl border border-stone-200 bg-white p-6" aria-labelledby="artifacts-heading">
        <h2 id="artifacts-heading" class="text-xl font-semibold text-stone-900">Approvals and downstream artifacts</h2>
        <p class="mt-3 text-sm text-stone-600">{{ $run->approvals->count() }} approved actions · {{ $run->exportBatches->count() }} export packages</p>

        @if ($run->approvals->isNotEmpty())
            <ul class="mt-4 grid gap-2 sm:grid-cols-2">
                @foreach ($run->approvals as $approval)
                    <li class="rounded-lg border border-stone-200 p-3 text-sm text-stone-700">{{ $approval->transaction_id }} · {{ $approval->action }} · revision {{ $approval->revision }} · {{ $approval->approved_at->format('j M Y, H:i:s T') }}</li>
                @endforeach
            </ul>
        @endif

        @foreach ($run->exportBatches as $export)
            <article class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="font-medium text-emerald-950">Export {{ $export->id }}</p>
                        <p class="mt-1 text-emerald-800">{{ $export->counts['creates'] }} creates · {{ $export->counts['updates'] }} updates · {{ $export->counts['held'] }} held · {{ str_replace('_', ' ', $export->status) }}</p>
                    </div>
                    <a data-download-feedback class="font-medium text-emerald-700 hover:text-emerald-800" href="{{ route('try.exports.download', $export) }}">Download ZIP</a>
                </div>

                @foreach ($export->confirmations as $confirmation)
                    <div class="mt-4 rounded-md border border-sky-200 bg-white p-3 text-sky-950">
                        <p class="font-medium">Confirmation · {{ $confirmation->snapshot_filename }} · {{ str_replace('_', ' ', $confirmation->status) }}</p>
                        <p class="mt-1 text-xs text-sky-800">{{ $confirmation->counts[\App\Models\ConfirmationResult::OUTCOME_MATCHES] ?? 0 }} matches · checksum {{ $confirmation->snapshot_checksum }}</p>
                        <div class="mt-3 overflow-x-auto rounded border border-sky-100">
                            <table class="min-w-full divide-y divide-sky-100 text-left text-xs">
                                <thead class="bg-sky-50 uppercase tracking-wide text-sky-800">
                                    <tr>
                                        <th class="px-3 py-2" scope="col">Gift</th>
                                        <th class="px-3 py-2" scope="col">Expected action</th>
                                        <th class="px-3 py-2" scope="col">Outcome</th>
                                        <th class="px-3 py-2" scope="col">CRM records</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-sky-100">
                                    @foreach ($confirmation->results as $result)
                                        <tr>
                                            <th class="px-3 py-2 font-medium" scope="row">{{ $result->transaction_id }}</th>
                                            <td class="px-3 py-2">{{ $result->expected_action }}</td>
                                            <td class="px-3 py-2">{{ str_replace('_', ' ', $result->outcome) }}</td>
                                            <td class="px-3 py-2">{{ $result->matched_crm_record_ids === [] ? 'none' : implode(', ', $result->matched_crm_record_ids) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($confirmation->verificationRun)
                            <a class="mt-2 inline-flex font-medium text-sky-700 hover:text-sky-800" href="{{ route('try.history.runs.show', $confirmation->verificationRun) }}">Open verification run {{ $confirmation->verificationRun->sequence }}</a>
                        @endif
                    </div>
                @endforeach
            </article>
        @endforeach
    </section>

    <p class="mt-6 text-xs text-stone-500">Run completed {{ $run->completed_at?->format('j M Y, H:i:s T') }} · {{ $minutesRemaining }} minutes remain in this workspace.</p>
</section>
@endsection
