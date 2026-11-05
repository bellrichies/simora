const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { existsSync, readdirSync, writeFileSync } = require('node:fs');
const { join } = require('node:path');
const { tmpdir } = require('node:os');

// Use an installed headless Chromium; no browser download or npm dependency required.
const cache = join(process.env.LOCALAPPDATA || '', 'ms-playwright');
const candidates = existsSync(cache) ? readdirSync(cache).filter(name => name.startsWith('chromium_headless_shell-')).sort().reverse() : [];
const chromePath = process.env.CHROME_BIN || candidates.map(name => join(cache, name, 'chrome-headless-shell-win64', 'chrome-headless-shell.exe')).find(existsSync);
assert(chromePath, 'Set CHROME_BIN to an installed headless Chromium executable');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
    const server = spawn('php', ['-S', '127.0.0.1:8772', '-t', 'public', 'public/index.php'], { stdio: 'ignore' });
    const browser = spawn(chromePath, ['--no-sandbox', '--headless', '--remote-debugging-port=9331', 'about:blank'], { stdio: 'ignore' });
    let socket;
    try {
        let targets;
        for (let attempt = 0; attempt < 50; attempt++) {
            try {
                await fetch('http://127.0.0.1:8772/platforms');
                targets = await (await fetch('http://127.0.0.1:9331/json')).json();
                break;
            } catch { await delay(100); }
        }
        assert(targets, 'Local PHP server and browser started');
        socket = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
        await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
        let sequence = 0;
        const pending = new Map();
        socket.addEventListener('message', event => {
            const message = JSON.parse(event.data);
            if (pending.has(message.id)) {
                pending.get(message.id)(message);
                pending.delete(message.id);
            }
        });
        const send = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Timed out: ${method}`)); }, 10000);
            pending.set(id, message => { clearTimeout(timer); message.error ? reject(new Error(message.error.message)) : resolve(message.result); });
            socket.send(JSON.stringify({ id, method, params }));
        });
        const evaluate = async expression => {
            const response = await send('Runtime.evaluate', { expression, returnByValue: true });
            assert(!response.exceptionDetails, JSON.stringify(response.exceptionDetails));
            return response.result.value;
        };
        const ready = async predicate => {
            for (let attempt = 0; attempt < 60; attempt++) {
                if (await evaluate(`document.readyState === 'complete' && (${predicate})`)) return;
                await delay(100);
            }
            throw new Error(`Page did not become ready: ${predicate}`);
        };
        await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
        await send('Page.navigate', { url: 'http://127.0.0.1:8772/platforms' });
        await ready("document.querySelectorAll('.pl-flag').length === 18 && [...document.images].every(img => img.complete)");
        assert(await evaluate("[...document.querySelectorAll('.pl-flag')].every(img => img.naturalWidth > 0 && img.getBoundingClientRect().width === 14)"), 'All local league flags render');
        assert(await evaluate("document.querySelector('.pl-card-actions button[formaction]').textContent.includes('England')"), 'Prediction button names the selected league');
        assert(await evaluate("document.querySelector('.pl-history').getBoundingClientRect().bottom <= 900"), 'Desktop overview and six settled rows fit in 900px');
        const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(tmpdir(), 'smart-betai-platforms-desktop.png'), Buffer.from(shot.data, 'base64'));

        await evaluate("document.querySelector('[data-pl-league-form] input[type=radio][value=sportybet-italy-league]').click()");
        await ready("location.search.includes('league_sportybet=sportybet-italy-league') && document.querySelector('.pl-stat-head>span')?.textContent === 'Italy League'");
        assert(await evaluate("document.querySelector('.pl-card-actions button[formaction]').textContent.includes('Italy')"), 'League changes update performance and prediction destination');
        await evaluate("document.querySelector('.pl-filters input[type=radio]').click()");
        await ready("location.search.includes('window=7') && document.querySelector('.pl-stat-caption').textContent.includes('7-day')");

        await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
        await ready("document.querySelector('.sidebar').getBoundingClientRect().right <= 0");
        assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Mobile page has no horizontal overflow');
        assert(await evaluate("[...document.querySelectorAll('.pl-flag')].every(img => img.complete && img.naturalWidth > 0)"), 'Flags remain loaded on mobile');
        const mobile = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(tmpdir(), 'smart-betai-platforms-mobile.png'), Buffer.from(mobile.data, 'base64'));

        // All HTML routes share one accessible hierarchy in the main content.
        for (const route of ['dashboard', 'platforms', 'plans', 'wallet', 'referrals', 'reviews', 'support', 'settings', 'notifications', 'results', 'sportybet', 'betpawa', 'betking', 'predictions']) {
            const response = await fetch(`http://127.0.0.1:8772/${route}`);
            const html = await response.text();
            assert.equal(response.status, 200, `${route} responds successfully`);
            assert.equal((html.match(/aria-label="Breadcrumb"/g) || []).length, 1, `${route} has one breadcrumb`);
            const crumb = html.match(/<nav class="page-container workspace-breadcrumb"[\s\S]*?<\/nav>/)?.[0];
            assert(crumb?.includes('aria-current="page"'), `${route} identifies its current breadcrumb`);
            assert(!html.includes('Warning:') && !html.includes('Fatal error:'), `${route} renders without PHP warnings`);
        }
        await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
        await send('Page.navigate', { url: 'http://127.0.0.1:8772/platforms?league_sportybet=sportybet-italy-league' });
        await ready("document.querySelector('.pl-card-actions button[formaction]')?.textContent.includes('Italy')");
        await evaluate("document.querySelector('.pl-card-actions button[formaction]').click()");
        await ready("document.querySelector('[data-simulation-status]')?.textContent.includes('Replay')");
        assert.deepEqual(await evaluate("[...document.querySelectorAll('.workspace-breadcrumb li')].map(e => e.textContent.trim())"), ['Overview', 'Platforms', 'SportyBet', 'Italy League']);
        assert(await evaluate("document.querySelectorAll('.ph-workspace-grid>.ph-panel').length === 3 && new Set([...document.querySelectorAll('.ph-workspace-grid>.ph-panel')].map(e => e.getBoundingClientRect().top)).size === 1"), 'Prediction, strategy and report align as three desktop cards');
        assert(await evaluate("document.querySelector('[data-net]').textContent !== '\u2014' && document.querySelector('[data-chart-line]').getAttribute('points').length > 0"), 'Simulation and chart render');
        assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Predictions desktop has no overflow');
        const predictionShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(tmpdir(), 'smart-betai-predictions-desktop.png'), Buffer.from(predictionShot.data, 'base64'));
        await evaluate("document.querySelector('.ph-focus-form select[name=focus]').value='betking'; document.querySelector('.ph-focus-form select[name=focus]').dispatchEvent(new Event('change', {bubbles:true}))");
        await ready("location.search.includes('focus=betking') && document.querySelector('.workspace-breadcrumb').textContent.includes('BetKing')");
        assert(await evaluate("document.title.includes('BetKing') && document.querySelector('.ph-brand h3').textContent === 'BetKing'"), 'Platform changes update title, breadcrumb and prediction');
        await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
        await ready("document.querySelector('.sidebar').getBoundingClientRect().right <= 0");
        assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Predictions mobile has no overflow');
        const predictionMobile = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(tmpdir(), 'smart-betai-predictions-mobile.png'), Buffer.from(predictionMobile.data, 'base64'));

        await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
        await send('Page.navigate', { url: 'http://127.0.0.1:8772/plans' });
        await ready("document.querySelector('.pb-enhanced') && document.querySelectorAll('.pb-plan').length === 4");
        assert(await evaluate("document.querySelectorAll('.pb-summary>.pb-panel').length === 3 && new Set([...document.querySelectorAll('.pb-summary>.pb-panel')].map(e => e.getBoundingClientRect().top)).size === 1"), 'Billing summary uses three aligned desktop cards');
        assert(await evaluate("document.querySelector('.pb-history').getBoundingClientRect().width === document.querySelector('.pb-page').getBoundingClientRect().width"), 'Billing ledger spans the workspace');
        const billingShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(tmpdir(), 'smart-betai-billing-desktop.png'), Buffer.from(billingShot.data, 'base64'));
        await evaluate("document.querySelector('[data-plan=Max]').click()");
        assert(await evaluate("document.querySelector('#billing-plan-dialog').open && document.querySelector('[data-selected-plan]').textContent === 'Max'"), 'Plan preview dialog retains the selected plan');
        await evaluate("document.querySelector('#billing-plan-dialog [data-close-dialog]').click(); document.querySelector('[data-dialog=billing-email-dialog]').click(); document.querySelector('#billing-email').value='billing-preview@example.com'; document.querySelector('[data-billing-email-form]').requestSubmit()");
        assert(await evaluate("document.querySelector('[data-billing-email]').textContent === 'billing-preview@example.com' && !document.querySelector('#billing-email-dialog').open"), 'Invoice email preview still saves');
        await evaluate("document.querySelector('.pb-filters select[name=status]').value='failed'; document.querySelector('.pb-filters select[name=status]').dispatchEvent(new Event('change',{bubbles:true}))");
        await ready("location.search.includes('status=failed') && document.querySelector('.pb-status-failed')");
        assert.equal(await evaluate("document.querySelectorAll('.pb-table tbody tr').length"), 1, 'Billing filter retains only the matching transaction');
        await evaluate("document.querySelector('.pb-detail-link').click()");
        await ready("document.querySelector('#invoice-detail')");
        assert(await evaluate("document.querySelector('#invoice-detail').textContent.toLowerCase().includes('unpaid')"), 'Invoice detail links still resolve');
        await send('Page.navigate', { url: 'http://127.0.0.1:8772/plans' });
        await ready("document.querySelectorAll('.pb-plan').length === 4");
        await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
        await ready("document.querySelector('.sidebar').getBoundingClientRect().right <= 0");
        assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Billing mobile has no page overflow');
        assert(await evaluate("document.querySelector('.pb-table-wrap').scrollWidth > document.querySelector('.pb-table-wrap').clientWidth"), 'Mobile billing table scrolls inside its panel');
        const billingMobile = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(tmpdir(), 'smart-betai-billing-mobile.png'), Buffer.from(billingMobile.data, 'base64'));

        await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
        await send('Page.navigate', { url: 'http://127.0.0.1:8772/dashboard' });
        await ready("document.querySelector('.ov-enhanced') && document.querySelectorAll('[data-countdown]').length === 3");
        assert.equal(await evaluate("document.querySelectorAll('.ov-stats article').length"), 4, 'Overview retains four account metrics');
        assert(await evaluate("new Set([...document.querySelectorAll('.ov-platform-card')].map(e => e.getBoundingClientRect().top)).size === 1 && document.querySelectorAll('.ov-lower>.ov-panel').length === 3"), 'Overview aligns platform and analytics cards in desktop rows');
        assert(await evaluate("document.querySelector('.ov-analytics').getBoundingClientRect().bottom <= 900"), 'Overview panels fit at desktop size');
        assert(await evaluate("[...document.querySelectorAll('.ov-league-flag')].every(img => img.complete && img.naturalWidth > 0)"), 'Overview local league flags load');
        const overviewShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(tmpdir(), 'smart-betai-overview-desktop.png'), Buffer.from(overviewShot.data, 'base64'));
        await evaluate("document.querySelector('.ov-platform-filter select').value='betking'; document.querySelector('.ov-platform-filter select').dispatchEvent(new Event('change',{bubbles:true}))");
        await ready("location.search.includes('platform=betking') && document.querySelectorAll('.ov-platform-card').length === 1");
        assert(await evaluate("document.querySelector('.ov-platform-link').getAttribute('href') === '/betking'"), 'Platform filtering preserves board destinations');
        await evaluate("document.querySelector('.ov-model select').value='betpawa'; document.querySelector('.ov-model select').dispatchEvent(new Event('change',{bubbles:true}))");
        await ready("location.search.includes('model_platform=betpawa') && document.querySelector('.ov-model .ov-note').textContent.includes('BetPawa')");
        assert.equal(await evaluate("document.querySelectorAll('.ov-platform-card').length"), 1, 'Model switching retains the platform filter');
        await send('Page.navigate', { url: 'http://127.0.0.1:8772/dashboard' });
        await ready("document.querySelectorAll('.ov-platform-card').length === 3");
        await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
        await ready("document.querySelector('.sidebar').getBoundingClientRect().right <= 0");
        assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Overview mobile has no page overflow');
        const overviewMobile = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(tmpdir(), 'smart-betai-overview-mobile.png'), Buffer.from(overviewMobile.data, 'base64'));
        console.log('Workspace browser checks passed: Overview, Platforms, Predictions, billing, responsive layouts, navigation, and shared breadcrumbs.');
    } finally {
        socket?.close();
        browser.kill();
        server.kill();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
