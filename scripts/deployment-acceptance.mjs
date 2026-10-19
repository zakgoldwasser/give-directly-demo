import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile, chmod } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

const mode = process.argv[2];
const baseUrl = (process.env.DEPLOYMENT_URL ?? '').replace(/\/$/, '');
const readinessSecret = process.env.RECONCILIATION_READINESS_SECRET ?? '';
const checkpointPath = resolve(process.env.DEPLOYMENT_CHECKPOINT ?? '.deployment-acceptance/checkpoint.json');
const warmSamples = Number.parseInt(process.env.DEPLOYMENT_WARM_SAMPLES ?? '30', 10);

if (!['prepare', 'verify'].includes(mode)) {
    fail('Usage: node scripts/deployment-acceptance.mjs <prepare|verify>');
}

if (mode === 'prepare' && (!baseUrl || !readinessSecret)) {
    fail('DEPLOYMENT_URL and RECONCILIATION_READINESS_SECRET are required for prepare.');
}

const checkpoint = mode === 'verify'
    ? JSON.parse(await readFile(checkpointPath, 'utf8'))
    : null;
const targetUrl = mode === 'verify' ? checkpoint.baseUrl : baseUrl;
const cookies = new Map(Object.entries(checkpoint?.cookies ?? {}));

await assertOperational(targetUrl);

if (mode === 'prepare') {
    await prepare();
} else {
    await verify();
}

async function prepare() {
    if (!Number.isInteger(warmSamples) || warmSamples < 1 || warmSamples > 100) {
        fail('DEPLOYMENT_WARM_SAMPLES must be between 1 and 100.');
    }

    const landingDurations = [];
    let landingHtml = '';

    for (let sample = 0; sample < warmSamples; sample += 1) {
        const started = performance.now();
        const response = await request('/');
        landingDurations.push(performance.now() - started);
        landingHtml = await response.text();
        assert(response.ok && landingHtml.includes('Start guided demo'), 'Landing page is not usable.');
    }

    const csrf = extractCsrf(landingHtml);
    const started = performance.now();
    let html = await submit('/try/start', { _token: csrf, intent: 'guided' });
    const guidedStartMs = performance.now() - started;
    assert(html.includes('Reconciliation outcomes'), 'Guided reconciliation did not complete.');

    for (const path of ['/try/review/campaigns', '/try/review/matches']) {
        const form = extractForm(html, path);

        if (form !== null) {
            html = await submit(path, form);
        }
    }

    const approval = extractForm(html, '/try/approvals');
    assert(approval !== null, 'The guided run did not reach approval.');
    html = await submit('/try/approvals', approval);

    const exportForm = extractForm(html, '/try/exports');
    assert(exportForm !== null, 'The approved run did not reach export.');
    html = await submit('/try/exports', exportForm);
    assert(html.includes('Reviewed package ready'), 'The reviewed export was not created.');

    const exportPath = decodeHtml(match(html, /href="([^"]*\/try\/exports\/[^"]+\/download)"/, 'Export download link'));
    const exportResponse = await request(exportPath);
    assert(exportResponse.ok, `Export download returned HTTP ${exportResponse.status}.`);
    const exportBytes = Buffer.from(await exportResponse.arrayBuffer());
    assert(exportBytes.length > 0, 'Export download was empty.');
    const exportSha256 = createHash('sha256').update(exportBytes).digest('hex');
    const sorted = [...landingDurations].sort((left, right) => left - right);
    const p95 = sorted[Math.max(0, Math.ceil(sorted.length * 0.95) - 1)];

    assert(p95 < 3_000, `Warm landing p95 ${p95.toFixed(2)} ms exceeded 3,000 ms.`);
    assert(guidedStartMs < 20_000, `Guided start ${guidedStartMs.toFixed(2)} ms exceeded 20,000 ms.`);

    const state = {
        version: 1,
        baseUrl: targetUrl,
        cookies: Object.fromEntries(cookies),
        exportPath: new URL(exportPath, targetUrl).pathname,
        exportSha256,
        preparedAt: new Date().toISOString(),
        measurements: {
            warmLandingSamples: warmSamples,
            warmLandingP95Ms: round(p95),
            guidedStartMs: round(guidedStartMs),
            exportBytes: exportBytes.length,
        },
    };

    await mkdir(dirname(checkpointPath), { recursive: true });
    await writeFile(checkpointPath, `${JSON.stringify(state, null, 2)}\n`, { mode: 0o600 });

    try {
        await chmod(checkpointPath, 0o600);
    } catch {
        // Windows applies ACLs rather than POSIX file modes.
    }

    console.log(JSON.stringify({ passed: true, phase: 'prepare', ...state.measurements }, null, 2));
    console.log(`Checkpoint written to ${checkpointPath}. Redeploy, then run the verify phase.`);
}

async function verify() {
    const response = await request('/try');
    const html = await response.text();
    assert(response.ok && html.includes('Reviewed package ready'), 'Workspace state did not survive the deployment.');

    const exportResponse = await request(checkpoint.exportPath);
    assert(exportResponse.ok, `Persisted export returned HTTP ${exportResponse.status}.`);
    const exportBytes = Buffer.from(await exportResponse.arrayBuffer());
    const exportSha256 = createHash('sha256').update(exportBytes).digest('hex');
    assert(exportSha256 === checkpoint.exportSha256, 'The persisted export changed across the deployment.');

    console.log(JSON.stringify({
        passed: true,
        phase: 'verify',
        preparedAt: checkpoint.preparedAt,
        verifiedAt: new Date().toISOString(),
        exportBytes: exportBytes.length,
        exportSha256,
    }, null, 2));
}

async function assertOperational(url) {
    const health = await request('/up', {}, url);
    assert(health.ok, `/up returned HTTP ${health.status}.`);

    if (readinessSecret) {
        const ready = await request('/ready', {
            headers: { Authorization: `Bearer ${readinessSecret}` },
        }, url);
        assert(ready.ok, `/ready returned HTTP ${ready.status}.`);
    }
}

async function submit(path, fields) {
    const response = await request(path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(fields),
    });
    const html = await response.text();
    assert(response.ok, `${path} returned HTTP ${response.status}.`);

    return html;
}

async function request(path, options = {}, url = targetUrl) {
    let nextUrl = new URL(path, url);
    let requestOptions = { ...options, redirect: 'manual' };

    for (let redirects = 0; redirects < 6; redirects += 1) {
        const headers = new Headers(requestOptions.headers ?? {});

        if (cookies.size > 0) {
            headers.set('Cookie', [...cookies].map(([name, value]) => `${name}=${value}`).join('; '));
        }

        const response = await fetch(nextUrl, { ...requestOptions, headers });
        rememberCookies(response.headers);

        if (![301, 302, 303, 307, 308].includes(response.status)) {
            return response;
        }

        const location = response.headers.get('location');
        assert(location !== null, `Redirect from ${nextUrl.pathname} had no location.`);
        nextUrl = new URL(location, nextUrl);
        requestOptions = { method: 'GET', redirect: 'manual' };
    }

    fail(`Too many redirects while requesting ${path}.`);
}

function rememberCookies(headers) {
    const values = typeof headers.getSetCookie === 'function'
        ? headers.getSetCookie()
        : [headers.get('set-cookie')].filter(Boolean);

    for (const value of values) {
        const pair = value.split(';', 1)[0];
        const separator = pair.indexOf('=');

        if (separator > 0) {
            cookies.set(pair.slice(0, separator), pair.slice(separator + 1));
        }
    }
}

function extractCsrf(html) {
    return decodeHtml(match(html, /<meta name="csrf-token" content="([^"]+)"/, 'CSRF token'));
}

function extractForm(html, path) {
    const escaped = path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const formMatch = html.match(new RegExp(`<form[^>]+action="[^"]*${escaped}"[^>]*>([\\s\\S]*?)<\\/form>`));

    if (formMatch === null) {
        return null;
    }

    const fields = {};
    const inputs = formMatch[1].matchAll(/<input\b([^>]*)>/g);

    for (const input of inputs) {
        const attributes = input[1];
        const nameMatch = attributes.match(/\bname="([^"]+)"/);

        if (nameMatch === null || Object.hasOwn(fields, nameMatch[1])) {
            continue;
        }

        const type = attributes.match(/\btype="([^"]+)"/)?.[1] ?? 'text';
        const value = attributes.match(/\bvalue="([^"]*)"/)?.[1] ?? '';

        if (!['file', 'submit'].includes(type)) {
            fields[decodeHtml(nameMatch[1])] = decodeHtml(value);
        }
    }

    return fields;
}

function match(value, expression, label) {
    const result = value.match(expression);
    assert(result !== null, `${label} was not found.`);

    return result[1];
}

function decodeHtml(value) {
    return value
        .replaceAll('&amp;', '&')
        .replaceAll('&quot;', '"')
        .replaceAll('&#039;', "'")
        .replaceAll('&lt;', '<')
        .replaceAll('&gt;', '>');
}

function round(value) {
    return Math.round(value * 100) / 100;
}

function assert(condition, message) {
    if (!condition) {
        fail(message);
    }
}

function fail(message) {
    console.error(message);
    process.exit(1);
}
