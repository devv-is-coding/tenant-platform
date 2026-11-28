/**
 * Compare this run's load test with main's last one (TA-35), write the table
 * to the job summary, and fail when a scenario got more than
 * budgets.load.regression_margin worse: a slower p95, or fewer requests a
 * second. With no baseline yet (main's first run), it only reports.
 *
 * Usage: node tests/Performance/compare-with-main.mjs <results dir> [<baseline dir>]
 * Each dir holds the summary.json load-test.js writes.
 */
import { appendFileSync, existsSync, readFileSync } from 'node:fs';

const [resultsDir, baselineDir] = process.argv.slice(2);
const budgets = JSON.parse(readFileSync(new URL('./budgets.json', import.meta.url), 'utf8')).load;
const seconds = parseInt(budgets.duration, 10);
const margin = budgets.regression_margin;

/** p95 and requests a second of each scenario in a k6 summary, or null. */
function readRun(dir) {
    const file = dir && `${dir}/summary.json`;

    if (!file || !existsSync(file)) {
        return null;
    }

    const { metrics } = JSON.parse(readFileSync(file, 'utf8'));

    return Object.fromEntries(Object.keys(budgets.scenarios).map((name) => [name, {
        p95: metrics[`http_req_duration{scenario:${name}}`]?.values?.['p(95)'],
        rps: (metrics[`http_reqs{scenario:${name}}`]?.values?.count ?? 0) / seconds,
    }]));
}

const current = readRun(resultsDir);

if (!current) {
    console.error(`No load test summary in ${resultsDir}.`);
    process.exit(1);
}

const baseline = readRun(baselineDir);
const regressions = [];
const change = (now, before) => (before ? `${(((now - before) / before) * 100).toFixed(0)} %` : '—');

const rows = Object.entries(current).map(([name, { p95, rps }]) => {
    const before = baseline?.[name];

    if (before?.p95 && p95 > before.p95 * (1 + margin)) {
        regressions.push(`${name}: p95 ${p95.toFixed(0)} ms, was ${before.p95.toFixed(0)} ms`);
    }

    if (before?.rps && rps < before.rps * (1 - margin)) {
        regressions.push(`${name}: ${rps.toFixed(0)} req/s, was ${before.rps.toFixed(0)} req/s`);
    }

    return `| ${name} | ${p95.toFixed(0)} ms | ${before?.p95 ? `${before.p95.toFixed(0)} ms` : '—'} | ${change(p95, before?.p95)} | ${rps.toFixed(0)} | ${before?.rps ? before.rps.toFixed(0) : '—'} | ${change(rps, before?.rps)} |`;
});

const report = [
    '### Load test',
    '',
    baseline ? `Compared with main's last run; more than ${margin * 100} % worse fails.` : 'No run on main to compare with yet: this one only reports.',
    '',
    '| Scenario | p95 | main p95 | change | req/s | main req/s | change |',
    '| --- | --- | --- | --- | --- | --- | --- |',
    ...rows,
    '',
    ...(regressions.length ? ['**Slower than main:**', '', ...regressions.map((line) => `- ${line}`), ''] : []),
].join('\n');

console.log(report);

if (process.env.GITHUB_STEP_SUMMARY) {
    appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${report}\n`);
}

process.exit(regressions.length ? 1 : 0);
