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
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:900,deviceScaleFactor:1,mobile:false});
        await send('Page.navigate',{url:'http://127.0.0.1:8772/support'});
        await ready("document.querySelectorAll('.sp-table tbody tr').length === 5 && document.querySelector('.sp-enhanced')");
        assert.deepEqual(await evaluate("[...document.querySelectorAll('.sp-value')].map(e=>e.textContent)"), ['3','1','2']);
        assert.equal(await evaluate("document.querySelectorAll('.sp-faq details').length"),8);
        assert(await evaluate("new Set([...document.querySelectorAll('.sp-summary>.pl-card')].map(e=>e.getBoundingClientRect().top)).size === 1"), 'Summary cards align');
        assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Desktop fits');
        writeFileSync(join(tmpdir(),'smart-betai-support-desktop.png'),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
        await evaluate("document.querySelector('#support-query').value='simulator'; document.querySelector('[data-help-search]').requestSubmit()");
        await ready("location.search.includes('q=simulator') && document.querySelectorAll('.sp-faq details').length === 1");
        await evaluate("document.querySelector('.sp-faq summary').click()");
        assert(await evaluate("document.querySelector('.sp-faq details').open && document.querySelector('.sp-faq details').textContent.includes('no bets are placed')"), 'FAQ opens using native details');
        await evaluate("document.querySelector('[data-ticket-filters] select').value='waiting_customer'; document.querySelector('[data-ticket-filters] select').dispatchEvent(new Event('change',{bubbles:true}))");
        await ready("location.search.includes('status=waiting_customer') && document.querySelectorAll('.sp-table tbody tr').length === 1");
        assert(await evaluate("document.querySelector('#support-query').value === 'simulator'"), 'Ticket filtering preserves help search');
        await evaluate("document.querySelector('.sp-table tbody tr a').click()");
        await ready("document.querySelector('#support-conversation') && location.search.includes('ticket=SUP-1045')");
        assert(await evaluate("document.querySelectorAll('.sp-messages li').length === 2 && document.querySelector('.sp-conversation').textContent.includes('Pending rewards are excluded')"), 'Request opens the selected conversation');
        await send('Page.navigate',{url:'http://127.0.0.1:8772/support?q=zzznomatch&category=account'});
        await ready("document.querySelector('.sp-empty')");
        await send('Page.navigate',{url:'http://127.0.0.1:8772/support?category=billing'});
        await ready("document.querySelectorAll('.sp-faq details').length === 1 && document.querySelector('.sp-faq').textContent.includes('Can I change a plan')");
        await send('Page.navigate',{url:'http://127.0.0.1:8772/support'});
        await ready("document.querySelectorAll('.sp-table tbody tr').length === 5");
        for (const width of [900,390,320]) {
            await send('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<700});
            if(width<700) await ready("document.querySelector('.sidebar').getBoundingClientRect().right <= 0");
            assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), `No overflow at ${width}px`);
        }
        writeFileSync(join(tmpdir(),'smart-betai-support-mobile.png'),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
        await send('Emulation.setScriptExecutionDisabled',{value:true});
        await send('Page.navigate',{url:'http://127.0.0.1:8772/support?status=resolved&ticket=SUP-1036'});
        await ready("document.querySelectorAll('.sp-table tbody tr').length === 1 && document.querySelector('.sp-conversation')");
        assert(await evaluate("getComputedStyle(document.querySelector('.sp-apply')).display !== 'none'"), 'Native GET filtering and conversations work without JavaScript');
        console.log('Support browser checks passed: totals, search, topic/status filters, FAQ, conversation details, responsive layouts and no-JavaScript fallback.');
    } finally {
        socket?.close();
        browser.kill();
        server.kill();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
