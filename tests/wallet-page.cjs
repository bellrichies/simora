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
        await send('Page.navigate', { url: 'http://127.0.0.1:8772/wallet' });
        await ready("document.querySelector('[data-wallet-page]') && document.querySelectorAll('.wl-table tbody tr').length === 7");
        assert(await evaluate("new Set([...document.querySelectorAll('.wl-summary>.pl-card')].map(e => e.getBoundingClientRect().top)).size === 1"), 'Three summary cards align on desktop');
        assert(await evaluate("document.querySelector('.wallet-primary-balance strong').textContent.includes('1,250.00')"), 'Available credit excludes the reserved amount');
        assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Desktop has no page overflow');
        writeFileSync(join(tmpdir(), 'smart-betai-wallet-desktop.png'), Buffer.from((await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })).data, 'base64'));
        await evaluate("document.querySelector('select[name=status]').value='Pending'; document.querySelector('select[name=status]').dispatchEvent(new Event('change',{bubbles:true}))");
        await ready("location.search.includes('status=Pending') && document.querySelectorAll('.wl-table tbody tr').length === 1");
        assert(await evaluate("document.querySelector('.wl-table tbody').textContent.includes('RWD-1028')"), 'Status filter returns the pending reward');
        await evaluate("document.querySelector('select[name=type]').value='Subscription'; document.querySelector('select[name=type]').dispatchEvent(new Event('change',{bubbles:true}))");
        await ready("location.search.includes('type=Subscription') && document.querySelector('.wl-table tbody').textContent.includes('No example activity')");
        assert(await evaluate("document.querySelector('select[name=status]').value === 'Pending'"), 'Type changes preserve the status filter');
        await send('Page.navigate', { url: 'http://127.0.0.1:8772/wallet' });
        await ready("document.querySelectorAll('.wl-table tbody tr').length === 7");
        for (const width of [390, 320]) {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 1, mobile: true });
            await ready("document.querySelector('.sidebar').getBoundingClientRect().right <= 0");
            assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), `No page overflow at ${width}px`);
            assert(await evaluate("getComputedStyle(document.querySelector('.wl-table tbody tr')).display === 'grid'"), 'Activity becomes readable mobile cards');
        }
        writeFileSync(join(tmpdir(), 'smart-betai-wallet-mobile.png'), Buffer.from((await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })).data, 'base64'));
        await send('Emulation.setScriptExecutionDisabled', { value: true });
        await send('Page.navigate', { url: 'http://127.0.0.1:8772/wallet?status=Reserved' });
        await ready("document.querySelectorAll('.wl-table tbody tr').length === 1 && document.querySelector('.wl-table tbody').textContent.includes('HLD-1103')");
        assert(await evaluate("getComputedStyle(document.querySelector('.wl-apply')).display !== 'none'"), 'Apply remains available without JavaScript');
        console.log('Wallet checks passed: reconciled balance, desktop/mobile layout, filters, empty state, and no-JavaScript fallback.');
    } finally {
        socket?.close();
        browser.kill();
        server.kill();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
