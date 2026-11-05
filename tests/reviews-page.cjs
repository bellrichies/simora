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
        await send('Emulation.setDeviceMetricsOverride', {width:1440, height:900, deviceScaleFactor:1, mobile:false});
        await send('Page.navigate', {url:'http://127.0.0.1:8772/reviews'});
        await ready("document.querySelectorAll('.rv-review').length === 6 && document.querySelector('.rv-enhanced')");
        assert.deepEqual(await evaluate("[...document.querySelectorAll('.rv-number')].map(e => e.textContent)"), ['4.0/ 5', '8', '75%']);
        assert.equal(await evaluate("[...document.querySelectorAll('progress')].reduce((sum,el)=>sum+el.value,0)"),8);
        assert(await evaluate("new Set([...document.querySelectorAll('.rv-summary>.pl-card')].map(e => e.getBoundingClientRect().top)).size === 1"), 'Summary cards align');
        assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Desktop fits');
        writeFileSync(join(tmpdir(), 'smart-betai-reviews-desktop.png'), Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
        await evaluate("[...document.querySelectorAll('[aria-label=\"Review pages\"] a')].find(e=>e.textContent.includes('Next')).click()");
        await ready("location.search.includes('page=2') && document.querySelectorAll('.rv-review').length === 2");
        await evaluate("document.querySelector('[data-review-filters] select[name=rating]').value='1'; document.querySelector('[data-review-filters] select[name=rating]').dispatchEvent(new Event('change',{bubbles:true}))");
        await ready("location.search.includes('rating=1') && document.querySelector('.rv-empty')");
        assert(await evaluate("document.querySelector('.rv-number').textContent.startsWith('4.0')"), 'Filters preserve overall aggregates');
        await send('Page.navigate',{url:'http://127.0.0.1:8772/reviews?sort=lowest#review-draft'});
        await ready("document.querySelector('#review-draft').open && document.querySelectorAll('.rv-review').length === 6");
        assert(await evaluate("document.querySelector('.rv-review').textContent.includes('Needs more guidance')"), 'Lowest sort retains constructive criticism');
        await evaluate("const f=document.querySelector('[data-review-draft]'); f.elements.rating.value='3'; f.elements.title.value='A useful demo'; f.elements.body.value='The navigation is clear, and a glossary would be useful.'; f.requestSubmit()");
        await ready("document.querySelector('[data-draft-status]').textContent.includes('Draft saved')");
        await send('Page.reload');
        await ready("document.querySelector('[data-review-draft] input[name=title]')?.value === 'A useful demo'");
        assert(await evaluate("document.querySelector('[data-review-draft] select').value === '3'"), 'Draft survives reload in same tab');
        await evaluate("document.querySelector('[data-draft-clear]').click()");
        assert(await evaluate("document.querySelector('[data-review-draft] input').value === '' && sessionStorage.getItem('simora-review-draft-v1') === null"), 'Clear removes saved draft');
        await send('Page.navigate',{url:'http://127.0.0.1:8772/reviews'});
        await ready("document.querySelectorAll('.rv-review').length === 6");
        for (const width of [900,390,320]) {
            await send('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<700});
            if(width<700) await ready("document.querySelector('.sidebar').getBoundingClientRect().right <= 0");
            assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), `No overflow at ${width}px`);
        }
        writeFileSync(join(tmpdir(), 'smart-betai-reviews-mobile.png'), Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
        await send('Emulation.setScriptExecutionDisabled',{value:true});
        await send('Page.navigate',{url:'http://127.0.0.1:8772/reviews?rating=2'});
        await ready("document.querySelectorAll('.rv-review').length === 1");
        assert(await evaluate("getComputedStyle(document.querySelector('.rv-apply')).display !== 'none' && document.querySelector('[data-draft-fields]').disabled"), 'No-JavaScript browse fallback avoids accidental submissions');
        console.log('Reviews browser checks passed: aggregates, pagination, filters, empty state, sorting, local draft lifecycle, responsive layouts and no-JavaScript fallback.');
    } finally {
        socket?.close();
        browser.kill();
        server.kill();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
