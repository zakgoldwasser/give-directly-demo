<?php

$fixtureDir = dirname(__DIR__).'/resources/fixtures';
$testDir = dirname(__DIR__).'/tests/Fixtures';

if (! is_dir($fixtureDir)) {
    mkdir($fixtureDir, 0777, true);
}

if (! is_dir($testDir)) {
    mkdir($testDir, 0777, true);
}

$headers = [
    'Gift Date',
    'Supporter',
    'Contact Email',
    'Gift Value',
    'Transaction Number',
    'Fund',
    'Currency Code',
    'Frequency',
    'Payment Method',
];

$fh = fopen($fixtureDir.'/sample-donations.csv', 'wb');
fputcsv($fh, $headers);

$names = [
    'Alex Rivera',
    'Jordan Lee',
    'Sam Patel',
    'Casey Ng',
    'Riley Brooks',
    'Morgan Diaz',
    'Taylor Kim',
    'Jamie Ortiz',
    'Avery Chen',
    'Quinn Walsh',
];

$campaigns = [
    'Spring Appeal',
    'spring appeal',
    'SPRING APPEAL',
    'Emergency Relief',
    'Monthly Giving',
    'Year-End Drive',
];

$freqs = ['once', 'monthly', 'unknown', 'recurring'];
$methods = ['card', 'bank', 'check', 'paypal'];
$currencies = ['USD', 'USD', 'EUR', 'GBP', 'KES'];

for ($i = 1; $i <= 150; $i++) {
    $name = $names[($i - 1) % count($names)];
    $email = strtolower(str_replace(' ', '.', $name)).'@example.test';
    $campaign = $campaigns[($i - 1) % count($campaigns)];
    $currency = $currencies[($i - 1) % count($currencies)];
    $freq = $freqs[($i - 1) % count($freqs)];
    $method = $methods[($i - 1) % count($methods)];
    $date = sprintf('2025-%02d-%02d', (($i % 12) + 1), (($i % 27) + 1));
    $amount = number_format(10 + ($i % 50) + ($i / 10), 2, '.', '');
    $txn = 'TXN-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);

    if ($i === 5) {
        $txn = 'TXN-0004';
    }

    if ($i === 12 || $i === 13) {
        $txn = '';
        $name = 'Dana Smith';
        $email = 'dana.smith@example.test';
        $amount = '50.00';
        $date = '2025-03-15';
    }

    if ($i === 20) {
        $email = '';
    }

    if ($i === 25) {
        $amount = '1,234.56';
    }

    if ($i === 26) {
        $amount = '$45.00';
    }

    if ($i === 27) {
        $amount = 'not-a-number';
    }

    if ($i === 30) {
        $date = '03/15/2025';
    }

    if ($i === 31) {
        $date = '15-03-2025';
    }

    if ($i === 32) {
        $date = 'March 15, 2025';
    }

    if ($i === 35) {
        $amount = '';
    }

    if ($i === 36) {
        $date = '';
    }

    if ($i === 40 || $i === 41 || $i === 42) {
        $name = 'Monthly Donor';
        $email = 'monthly.donor@example.test';
        $txn = 'TXN-M'.$i;
        $freq = 'monthly';
        $amount = '25.00';
    }

    if ($i === 50) {
        $amount = '=1+1';
    }

    if ($i === 51) {
        $name = '=HYPERLINK("http://evil.test")';
    }

    if ($i === 55) {
        $currency = 'XXX';
    }

    if ($i === 60) {
        $campaign = '  Emergency Relief  ';
    }

    fputcsv($fh, [$date, $name, $email, $amount, $txn, $campaign, $currency, $freq, $method]);
}

fclose($fh);

$sfh = fopen($testDir.'/stress-1000.csv', 'wb');
fputcsv($sfh, $headers);

for ($i = 1; $i <= 1000; $i++) {
    fputcsv($sfh, [
        sprintf('2025-%02d-%02d', (($i % 12) + 1), (($i % 27) + 1)),
        'Donor '.$i,
        'donor'.$i.'@example.test',
        number_format(25 + ($i % 100), 2, '.', ''),
        'STRESS-'.$i,
        'Stress Campaign',
        'USD',
        'once',
        'card',
    ]);
}

fclose($sfh);

echo 'sample='.filesize($fixtureDir.'/sample-donations.csv').PHP_EOL;
echo 'stress='.filesize($testDir.'/stress-1000.csv').PHP_EOL;
