/*
 * Renders the landing page share images (Open Graph / X cards, ADR-035)
 * from og-image.html into public/images/og-image-{locale}.png, 1200 × 630.
 *
 *     npm run og:render
 *
 * Needs a local Chrome or Chromium; set CHROME_BIN when it is not found.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const output = resolve(here, '../../public/images');
const template = readFileSync(join(here, 'og-image.html'), 'utf8');

const copy = {
    en: {
        h1a: 'Know your services are down',
        h1b: 'before your users do.',
        sub: 'Open-source uptime monitoring, incidents, alerts and status pages. Host it yourself.',
        f3: 'Status pages',
        ok: 'All systems operational',
        up: 'Up',
        monitors: [
            ['Website', 'https://acme.example'],
            ['API', 'https://api.acme.example'],
            ['Mail server', 'mail.acme.example:587'],
        ],
    },
    fr: {
        h1a: 'Sachez que vos services sont en panne',
        h1b: 'avant vos utilisateurs.',
        sub: 'Surveillance de disponibilité open source : incidents, alertes et pages de statut. Hébergez-la vous-même.',
        f3: 'Pages de statut',
        ok: 'Tous les systèmes sont opérationnels',
        up: 'En ligne',
        monitors: [
            ['Site web', 'https://acme.example'],
            ['API', 'https://api.acme.example'],
            ['Serveur mail', 'mail.acme.example:587'],
        ],
    },
};

/** Deterministic, healthy 24-day history with an occasional short dip. */
function rows(monitors, up) {
    return monitors
        .map(([name, target], index) => {
            const bars = Array.from({ length: 24 }, (_, day) =>
                (day * 7 + index * 5) % 23 === 0
                    ? '<i class="w"></i>'
                    : '<i></i>',
            ).join('');

            return `<div class="row"><div>${name}<small>${target}</small></div><span class="badge">✓ ${up}</span></div><div class="bars">${bars}</div>`;
        })
        .join('');
}

function chrome() {
    const candidates = [
        process.env.CHROME_BIN,
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ].filter(Boolean);
    const found = candidates.find((path) => existsSync(path));

    if (!found) {
        throw new Error('Chrome not found: set CHROME_BIN.');
    }

    return found;
}

const browser = chrome();
const workdir = mkdtempSync(join(tmpdir(), 'veyra-og-'));

for (const [locale, text] of Object.entries(copy)) {
    const values = { ...text, rows: rows(text.monitors, text.up) };
    const html = template.replace(/{{(\w+)}}/g, (_, key) => values[key] ?? '');
    const page = join(workdir, `og-${locale}.html`);
    const image = join(output, `og-image-${locale}.png`);

    writeFileSync(page, html);
    execFileSync(
        browser,
        [
            '--headless=new',
            '--disable-gpu',
            '--hide-scrollbars',
            '--force-device-scale-factor=1',
            '--window-size=1200,630',
            '--virtual-time-budget=4000',
            `--user-data-dir=${join(workdir, 'profile')}`,
            `--screenshot=${image}`,
            `file://${page}`,
        ],
        { stdio: 'ignore' },
    );

    console.log(`Rendered ${image}`);
}
