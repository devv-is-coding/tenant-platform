/**
 * Fail when a built Vite entry (TA-35), or all of them together, is larger
 * gzipped than its budget in budgets.json. Run after `npm run build`.
 *
 * An entry counts its own file, its CSS and the chunks it imports, once each:
 * what a browser downloads to load it. An entry with no budget fails too, so a
 * new one is budgeted on purpose. With GITHUB_STEP_SUMMARY set, the table is
 * written to the job summary as well.
 *
 * Usage: node tests/Performance/bundle-size.mjs
 */
import { appendFileSync, readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const build = 'public/build';
const budgets = JSON.parse(readFileSync(new URL('./budgets.json', import.meta.url), 'utf8')).bundle;
const manifest = JSON.parse(readFileSync(`${build}/manifest.json`, 'utf8'));

const gzipped = (file) => gzipSync(readFileSync(`${build}/${file}`)).length;

/** Every JS and CSS file an entry loads, following its imports. */
function filesOf(key, seen = new Set()) {
    const chunk = manifest[key];

    if (!chunk || seen.has(chunk.file)) {
        return seen;
    }

    seen.add(chunk.file);
    (chunk.css ?? []).forEach((css) => seen.add(css));
    (chunk.imports ?? []).forEach((imported) => filesOf(imported, seen));

    return seen;
}

const entries = Object.keys(manifest).filter((key) => manifest[key].isEntry);
const rows = [];
const counted = new Set();
let failed = false;

for (const entry of entries) {
    const files = [...filesOf(entry)].filter((file) => /\.(js|css)$/.test(file));
    files.forEach((file) => counted.add(file));

    const size = files.reduce((sum, file) => sum + gzipped(file), 0);
    const budget = budgets.entries[entry];
    const ok = budget !== undefined && size <= budget;
    failed ||= !ok;
    rows.push([entry, size, budget, ok]);
}

const total = [...counted].reduce((sum, file) => sum + gzipped(file), 0);
const totalOk = total <= budgets.total;
failed ||= !totalOk;
rows.push(['**all entries**', total, budgets.total, totalOk]);

const kb = (bytes) => (bytes === undefined ? 'no budget' : `${(bytes / 1000).toFixed(1)} kB`);
const table = [
    '### Bundle size (gzipped)',
    '',
    '| Entry | Size | Budget | |',
    '| --- | --- | --- | --- |',
    ...rows.map(([entry, size, budget, ok]) => `| ${entry} | ${kb(size)} | ${kb(budget)} | ${ok ? 'ok' : '**over**'} |`),
    '',
].join('\n');

console.log(table);

if (process.env.GITHUB_STEP_SUMMARY) {
    appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${table}\n`);
}

if (failed) {
    console.error('A bundle is over its budget (tests/Performance/budgets.json), or an entry has none.');
    process.exit(1);
}
