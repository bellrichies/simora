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
        await send('Page.navigate',{url:'http://127.0.0.1:8772/settings'});
        await ready("document.querySelector('[data-settings-form=profile]') && !document.querySelector('fieldset').disabled");
        assert(await evaluate("new Set([...document.querySelectorAll('.st-summary>.pl-card')].map(e=>e.getBoundingClientRect().top)).size===1"), 'Summary cards align');
        assert.equal(await evaluate("document.querySelector('[data-notification-count]').textContent"),'3');
        await evaluate("const f=document.querySelector('form'); f.elements.name.value='Ada Demo'; f.requestSubmit()");
        assert.equal(await evaluate("document.querySelector('[data-preview-name]').textContent"),'Ada Demo');
        await evaluate("document.querySelector('form').elements.name.value='Discard me'; document.querySelector('[data-settings-cancel]').click()");
        assert.equal(await evaluate("document.querySelector('form').elements.name.value"),'Ada Demo');
        await evaluate("document.querySelector('form').elements.name.value='   '; document.querySelector('form').requestSubmit()");
        assert(await evaluate("!document.querySelector('form').elements.name.checkValidity()"), 'Whitespace-only names rejected');
        await evaluate("document.querySelector('[data-settings-cancel]').click()");
        assert(await evaluate('document.documentElement.scrollWidth<=innerWidth'), 'Desktop fits');
        writeFileSync(join(tmpdir(),'smart-betai-settings-desktop.png'),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
        await send('Page.navigate',{url:'http://127.0.0.1:8772/settings?section=preferences'});
        await ready("document.querySelector('[data-settings-form=preferences]') && document.querySelector('[data-preview-name]').textContent==='Ada Demo'");
        await evaluate("const f=document.querySelector('form'); f.elements.timezone.value='UTC'; f.elements.platform.value='betking'; f.elements.market.value='over_25'; f.requestSubmit()");
        assert(await evaluate("document.querySelector('[data-preferred-workspace]').getAttribute('href')==='/predictions?focus=betking&market=over_25' && document.querySelector('[data-preview-timezone]').textContent==='Coordinated Universal Time' && document.querySelector('[data-example-time]').textContent.includes('10:30')"), 'Preferences power the sample time and workspace shortcut');
        await send('Page.navigate',{url:'http://127.0.0.1:8772/settings?section=notifications'});
        await ready("document.querySelector('[data-settings-form=notifications]') && !document.querySelector('fieldset').disabled");
        await evaluate("const f=document.querySelector('form'); f.elements.marketing.checked=true; f.requestSubmit()");
        assert.equal(await evaluate("document.querySelector('[data-notification-count]').textContent"),'4');
        await send('Page.navigate',{url:'http://127.0.0.1:8772/settings?section=privacy'});
        await ready("document.querySelector('[data-settings-export]') && !document.querySelector('[data-settings-export]').hidden");
        await evaluate("window.exportText=''; URL.createObjectURL=blob=>{blob.text().then(text=>window.exportText=text);return 'blob:preview'}; HTMLAnchorElement.prototype.click=function(){}; document.querySelector('[data-settings-export]').click()");
        await ready("window.exportText.includes('Ada Demo')");
        assert(await evaluate("JSON.parse(window.exportText).preview === true && JSON.parse(window.exportText).preferences.notifications.marketing === true"), 'Export contains validated preview preferences');
        await evaluate("localStorage.setItem('unrelated-preview','keep'); document.querySelector('[data-settings-reset]').click()");
        assert(await evaluate("localStorage.getItem('simora-settings-preview-v1')===null && localStorage.getItem('unrelated-preview')==='keep' && document.querySelector('[data-notification-count]').textContent==='3'"), 'Reset affects only Settings preview');
        await send('Page.navigate',{url:'http://127.0.0.1:8772/settings?section=security'});
        await ready("document.querySelectorAll('.st-feature').length===3");
        assert(await evaluate("document.querySelector('.st-content').textContent.includes('no authenticated sign-in sessions')"), 'Security is accurately labeled');
        for (const section of ['profile','preferences','notifications','privacy','security']) {
            await send('Page.navigate',{url:'http://127.0.0.1:8772/settings?section='+section});
            await ready("document.querySelector('.st-navigation [aria-current=page]')?.href.includes('section="+section+"')");
            for (const width of [900,390,320]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<700});
                if(width<700) await ready("document.querySelector('.sidebar').getBoundingClientRect().right<=0");
                assert(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `${section} fits at ${width}px`);
            }
        }
        await send('Page.navigate',{url:'http://127.0.0.1:8772/settings?section=profile'});
        await ready("document.querySelector('[data-settings-form=profile]') && !document.querySelector('fieldset').disabled");
        await evaluate("Object.defineProperty(Storage.prototype,'setItem',{value(){throw Error('Unavailable')}}); document.querySelector('form').elements.name.value='Visit only'; document.querySelector('form').requestSubmit()");
        assert(await evaluate("document.querySelector('[data-settings-feedback]').textContent.includes('could not be saved') && document.querySelector('[data-preview-name]').textContent==='Visit only'"), 'Storage failure is reported honestly');
        writeFileSync(join(tmpdir(),'smart-betai-settings-mobile.png'),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
        await send('Emulation.setScriptExecutionDisabled',{value:true});
        await send('Page.navigate',{url:'http://127.0.0.1:8772/settings?section=preferences'});
        await ready("document.querySelector('[data-settings-form=preferences]')");
        assert(await evaluate("document.querySelector('fieldset').disabled && document.querySelector('.st-navigation [aria-current=page]').textContent.includes('Preferences')"), 'Native section navigation and read-only no-JavaScript fallback');
        console.log('Settings browser checks passed: local saves, validation, discard, timezone/shortcut consumers, notifications, export/reset, storage errors, five responsive sections and no-JavaScript fallback.');
    } finally {
        socket?.close();
        browser.kill();
        server.kill();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
