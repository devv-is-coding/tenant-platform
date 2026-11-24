/**
 * Lighthouse CI (TA-35), against the production-like Docker stack:
 *
 *   npx @lhci/cli@0.15.1 autorun --config=tests/Performance/lighthouserc.cjs
 *
 * Run as a pinned tool, not a project dependency: its own dependency tree
 * carries advisories the project's `npm audit` gate would fail on.
 *
 * Public pages only: a signed-in page would need a scripted browser login
 * (puppeteer), another dependency. Each page is measured three times and the
 * median judged; the lowest scores come from budgets.json.
 */
const { lighthouse: scores } = require('./budgets.json');

const port = process.env.APP_PORT || '8000';
const agency = process.env.AGENCY || 'dumaguete';

module.exports = {
    ci: {
        collect: {
            url: [
                `http://localhost:${port}/`,
                `http://localhost:${port}/admin/homepage`,
                `http://${agency}.localhost:${port}/`,
                `http://${agency}.localhost:${port}/login`,
            ],
            numberOfRuns: 3,
            settings: {
                preset: 'desktop',
                chromeFlags: '--no-sandbox --headless=new',
            },
        },
        assert: {
            assertions: {
                'categories:performance': ['error', { minScore: scores.performance, aggregationMethod: 'median-run' }],
                'categories:accessibility': ['error', { minScore: scores.accessibility, aggregationMethod: 'median-run' }],
            },
        },
        upload: {
            target: 'filesystem',
            outputDir: 'tests/Performance/results/lighthouse',
        },
    },
};
