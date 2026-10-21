@php
    $requiredColumns = [
        'donations' => [
            'Donation reference (transaction ID)',
            'Donation date and time',
            'Donation amount before fees',
            'Currency',
            'Donation status',
            'Campaign code',
        ],
        'payouts' => [
            'Unique reference for each payout entry',
            'Payout reference',
            'Entry type: donation, fee, refund, reversal, or adjustment',
            'Entry amount (positive for money in; negative for money out)',
            'Currency',
            'Reported payout total',
            'Expected number of entries in the payout',
            'Payout status',
        ],
        'crm' => [
            'CRM record reference',
            'Donation amount before fees',
            'Currency',
            'Donation date and time',
            'Donation status',
            'Campaign reference in the CRM',
        ],
    ];
@endphp

<div class="mt-4 text-sm text-stone-600">
    <p class="font-semibold text-stone-900">Required columns</p>
    <ul class="mt-2 list-disc space-y-1 pl-5 marker:text-emerald-700">
        @foreach ($requiredColumns[$type] as $column)
            <li>{{ $column }}</li>
        @endforeach
        @if ($type === 'payouts')
            <li>Payout date — required when the payout has been sent</li>
        @endif
    </ul>
    @if (in_array($type, ['donations', 'crm'], true))
        <p class="mt-3"><strong class="font-semibold text-stone-900">Optional:</strong> Amount refunded. Missing columns and empty cells are treated as 0.00.</p>
    @endif
</div>
