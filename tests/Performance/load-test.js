/**
 * Load test for the full Docker stack (TA-35): the proxy and both app nodes,
 * seeded by `migrate:fresh --seed`. Run with the grafana/k6 image:
 *
 *   docker run --rm --network host -v "$PWD/tests/Performance:/scripts" \
 *     -e RESULTS=/scripts/results grafana/k6 run /scripts/load-test.js
 *
 * Each scenario runs on its own, one after another, so its numbers are its
 * own: the central homepage, an agency's homepage, and a signed-in agency
 * admin's dashboard. The thresholds come from budgets.json; crossing one
 * fails the run. The admin signs in once, in setup(), and every virtual user
 * reuses that session, so the login throttle never trips.
 *
 * Writes RESULTS/summary.json (compare-with-main.mjs reads it) and prints
 * the usual summary.
 */
import http from 'k6/http';
import { check, fail } from 'k6';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.1.0/index.js';

const budgets = JSON.parse(open('./budgets.json')).load;

const port = __ENV.APP_PORT || '8000';
const central = `http://localhost:${port}`;
const agency = `http://${__ENV.AGENCY || 'dumaguete'}.localhost:${port}`;
const adminEmail = __ENV.ADMIN_EMAIL || 'dumaguete.admin@example.com';
const adminPassword = __ENV.ADMIN_PASSWORD || 'password';

export const SCENARIOS = Object.keys(budgets.scenarios);

const seconds = parseInt(budgets.duration, 10);

export const options = {
    // Agency domains are subdomains of localhost, which the container's
    // resolver may not know.
    hosts: { [`${__ENV.AGENCY || 'dumaguete'}.localhost`]: '127.0.0.1' },
    scenarios: Object.fromEntries(SCENARIOS.map((name, index) => [name, {
        executor: 'constant-vus',
        exec: name.replace(/_(\w)/g, (_, letter) => letter.toUpperCase()),
        vus: budgets.virtual_users,
        duration: budgets.duration,
        // One after another, with a short gap so they never overlap.
        startTime: `${index * (seconds + 5)}s`,
        gracefulStop: '5s',
    }])),
    thresholds: {
        http_req_failed: ['rate==0'],
        checks: ['rate==1'],
        ...Object.fromEntries(SCENARIOS.flatMap((name) => [
            [`http_req_duration{scenario:${name}}`, [`p(95)<${budgets.scenarios[name].p95_ms}`]],
            // k6 averages a scenario's rate over the whole run, not its own
            // window, so the floor is a count over that window instead.
            [`http_reqs{scenario:${name}}`, [`count>=${budgets.scenarios[name].rps * seconds}`]],
        ])),
    },
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'max'],
};

/**
 * Sign the agency admin in once, and hand every virtual user the session.
 */
export function setup() {
    const form = http.get(`${agency}/login`);
    const token = form.html().find('input[name=_token]').attr('value');

    if (!token) {
        fail(`No CSRF token on ${agency}/login (status ${form.status}).`);
    }

    const login = http.post(`${agency}/login`, { _token: token, email: adminEmail, password: adminPassword }, { redirects: 0 });

    if (login.status !== 302 || !String(login.headers.Location).endsWith('/dashboard')) {
        fail(`Signing ${adminEmail} in failed: status ${login.status}, sent to ${login.headers.Location}.`);
    }

    const cookies = http.cookieJar().cookiesForURL(agency);

    return { cookies: Object.fromEntries(Object.entries(cookies).map(([name, values]) => [name, values[0]])) };
}

export function centralHomepage() {
    const response = http.get(`${central}/`);
    check(response, { 'central homepage answers 200': (r) => r.status === 200 });
}

export function agencyHomepage() {
    const response = http.get(`${agency}/`);
    check(response, { 'agency homepage answers 200': (r) => r.status === 200 });
}

export function agencyDashboard(data) {
    const response = http.get(`${agency}/dashboard`, { cookies: data.cookies, redirects: 0 });
    check(response, { 'signed-in dashboard answers 200': (r) => r.status === 200 });
}

export function handleSummary(data) {
    const results = __ENV.RESULTS || '/scripts/results';

    return {
        [`${results}/summary.json`]: JSON.stringify(data, null, 2),
        stdout: textSummary(data, { indent: ' ', enableColors: false }),
    };
}
