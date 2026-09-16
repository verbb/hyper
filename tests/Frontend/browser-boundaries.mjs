// Real Chromium and production Hyper functions. Synthetic DOM, queued Craft requests,
// and a minimal form parser keep these tests independent of a running CP/database.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium, firefox, webkit} from 'playwright';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const craftPath = process.env.HYPER_CRAFT_PATH || [
    path.join(root, 'vendor/craftcms/cms'),
    path.join(root, '.cache/verbb-tests/app/vendor/craftcms/cms'),
].find(candidate => fs.existsSync(path.join(candidate, 'src/web/assets/cp/dist/cp.js.map')));
if (!craftPath) throw new Error('Run ddev test first or set HYPER_CRAFT_PATH to an installed Craft package.');
const cpMap = JSON.parse(fs.readFileSync(path.join(craftPath, 'src/web/assets/cp/dist/cp.js.map'), 'utf8'));
const editorSource = cpMap.sourcesContent[cpMap.sources.findIndex(name => name.endsWith('/ElementEditor.js'))];
const serializeStart = editorSource.indexOf('serializeForm: function (');
assert(serializeStart >= 0, 'Locate the installed Craft serializer rather than substituting a test implementation.');
const nativeSerializeForm = editorSource.slice(serializeStart, editorSource.indexOf('\n    /**', serializeStart))
    .trim().replace(/^serializeForm: /, '').replace(/,$/, '');
const source='./src/web/assets/field/src/js/input/';
const bundle=await build({stdin:{contents:`export * from '${source}embed';`,resolveDir:root},bundle:true,format:'iife',globalName:'Audit',write:false});
const browserType = {chromium, firefox, webkit}[process.env.HYPER_BROWSER || 'chromium'];
if (!browserType) throw new Error('Unsupported HYPER_BROWSER');
const browser=await browserType.launch({headless:true});
try {
    const page=await browser.newPage();
    await page.route('http://localhost/**',route=>route.fulfill({contentType:'text/html',body:'<div id="embed" class="hyper-embed-field"><input class="visible" value="https://example.test/old"><input type="hidden" class="link-embed-data" value=\'{"url":"https://example.test/old"}\'><span class="spinner hidden"></span><div class="hyper-embed-response"></div></div><div id="block"><div data-hyper-portal></div></div>'}));
    await page.goto('http://localhost/synthetic');
    await page.addScriptTag({path:path.join(craftPath,'src/web/assets/jquery/dist/jquery.js')});
    await page.evaluate(()=>{
        window.pending=[];window.posted={fields:{main:'edited'}};
        window.Craft={randomString:()=>crypto.randomUUID(),getActionUrl:(a,p)=>p,sendActionRequest:(m,p)=>new Promise(r=>pending.push({p,r})),expandPostArray:()=>({hyperData:{row:posted}})};
        window.Garnish={getPostData:()=>({})};
    });
    await page.addScriptTag({content:bundle.outputFiles[0].text});
    // Simulate an edit before deferred widget initialization, then a submit flush.
    await page.locator('.visible').fill('https://example.test/before-mount');
    await page.evaluate(()=>Audit.syncEmbedWidgets());
    assert.equal(JSON.parse(await page.locator('.link-embed-data').inputValue()).url,'https://example.test/before-mount');
    await page.locator('.visible').fill('https://example.test/first');
    assert.equal(JSON.parse(await page.locator('.link-embed-data').inputValue()).url,'https://example.test/first');
    await page.waitForFunction(()=>pending.length===1);
    await page.locator('.visible').fill('https://example.test/second');
    await page.evaluate(()=>pending[0].r({data:{data:{url:'https://example.test/first',title:'stale'}}}));
    assert.equal(JSON.parse(await page.locator('.link-embed-data').inputValue()).url,'https://example.test/second');
    await page.waitForFunction(()=>pending.length===2);
    await page.locator('.visible').fill('');
    await page.evaluate(()=>pending[1].r({data:{data:{url:'https://example.test/second'}}}));
    assert.deepEqual(JSON.parse(await page.locator('.link-embed-data').inputValue()),{});
    // Submit synchronization sees programmatic/widget changes even without input events.
    await page.evaluate(()=>{document.querySelector('.visible').value='https://example.test/submit';Audit.syncEmbedWidgets();});
    assert.equal(JSON.parse(await page.locator('.link-embed-data').inputValue()).url,'https://example.test/submit');
    // Removal cancels the pending debounce; remount creates one request, no stale handler.
    const before=await page.evaluate(()=>{window.removed=document.querySelector('#embed');removed.remove();return pending.length;});
    await page.waitForTimeout(600);
    assert.equal(await page.evaluate(()=>pending.length),before);
    await page.evaluate(()=>{document.body.append(removed);Audit.mountEmbed(removed);});
    await page.locator('.visible').fill('https://example.test/remount');
    await page.waitForFunction(n=>pending.length===n+1,before);
    assert.equal(await page.evaluate(()=>pending.length),before+1);
    console.log(JSON.stringify({ok:true,scenarios:['immediate URL','response race','clear race','submit sync','removed debounce','remount']}));
} finally {await browser.close();}
