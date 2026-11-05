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
        await send('Page.navigate', { url: 'http://127.0.0.1:8772/referrals' });
        await ready("document.querySelector('[data-referrals-page]') && document.querySelectorAll('.rf-table tbody tr').length === 6");
        assert(await evaluate("new Set([...document.querySelectorAll('.rf-summary>.pl-card')].map(e => e.getBoundingClientRect().top)).size === 1"), 'Three summary cards align');
        assert.deepEqual(await evaluate("[...document.querySelectorAll('.rf-value')].map(e => e.textContent)"), ['8', '\u20a62,000.00', '\u20a6500.00'], 'Summary uses all records');
        assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'No desktop overflow');
        await evaluate("Object.defineProperty(navigator, 'clipboard', {configurable:true, value:{writeText:async value => {window.copiedLink=value}}}); document.querySelector('[data-referral-copy]').click()");
        await ready("document.querySelector('[data-referral-feedback]').textContent === 'Demo link copied.'");
        assert.equal(await evaluate('window.copiedLink'), 'https://simora.example/join?ref=SIM-DEMO26');
        await evaluate("Object.defineProperty(navigator, 'clipboard', {configurable:true, value:{writeText:async () => {throw Error('Denied')}}}); document.querySelector('[data-referral-copy]').click()");
        await ready("document.querySelector('[data-referral-feedback]').textContent.includes('Select and copy')");
        await evaluate("document.activeElement.blur()");
        writeFileSync(join(tmpdir(), 'smart-betai-referrals-desktop.png'), Buffer.from((await send('Page.captureScreenshot', {format:'png', captureBeyondViewport:true})).data, 'base64'));
        await evaluate("[...document.querySelectorAll('[aria-label=\"Referral activity pages\"] a')].find(e => e.textContent.includes('Next')).click()");
        await ready("location.search.includes('page=2') && document.querySelectorAll('.rf-table tbody tr').length === 2");
        await evaluate("document.querySelector('select[name=status]').value='Credited'; document.querySelector('select[name=status]').dispatchEvent(new Event('change',{bubbles:true}))");
        await ready("location.search.includes('status=Credited') && document.querySelectorAll('.rf-table tbody tr').length === 2 && !location.search.includes('page=2')");
        assert(await evaluate("[...document.querySelectorAll('.rf-status')].every(e => e.textContent === 'Credited')"), 'Status filter works');
        await evaluate("document.querySelector('select[name=sort]').value='oldest'; document.querySelector('select[name=sort]').dispatchEvent(new Event('change',{bubbles:true}))");
        await ready("location.search.includes('sort=oldest') && document.querySelector('.rf-table tbody').textContent.includes('Ada E.')");
        assert(await evaluate("document.querySelector('.rf-table tbody tr').textContent.includes('Ada E.') && document.querySelector('select[name=status]').value === 'Credited'"), 'Sorting preserves status');
        await send('Page.navigate', {url:'http://127.0.0.1:8772/referrals'});
        await ready("document.querySelectorAll('.rf-table tbody tr').length === 6");
        for (const width of [900, 390, 320]) {
            await send('Emulation.setDeviceMetricsOverride', {width, height:844, deviceScaleFactor:1, mobile:width < 700});
            if (width < 700) await ready("document.querySelector('.sidebar').getBoundingClientRect().right <= 0");
            assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), `No overflow at ${width}px`);
        }
        writeFileSync(join(tmpdir(), 'smart-betai-referrals-mobile.png'), Buffer.from((await send('Page.captureScreenshot', {format:'png', captureBeyondViewport:true})).data, 'base64'));
        await send('Emulation.setScriptExecutionDisabled', {value:true});
        await send('Page.navigate', {url:'http://127.0.0.1:8772/referrals?status=Pending'});
        await ready("document.querySelectorAll('.rf-table tbody tr').length === 1 && document.querySelector('.rf-table tbody').textContent.includes('Chika O.')");
        assert(await evaluate("getComputedStyle(document.querySelector('.rf-apply')).display !== 'none' && document.querySelector('[data-referral-copy]').hidden"), 'No-JavaScript filter and selectable link fallback');
        console.log('Referrals browser checks passed: totals, filters, sorting, pagination, copy/fallback, responsive layout and no-JavaScript rendering.');
    } finally {
        socket?.close();
        browser.kill();
        server.kill();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
