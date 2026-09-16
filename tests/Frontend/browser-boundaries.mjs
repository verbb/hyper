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
const bundle=await build({stdin:{contents:`export * from '${source}embed';export * from '${source}registry';export * from '${source}elementEditor';`,resolveDir:root},bundle:true,format:'iife',globalName:'Audit',write:false});
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
    // Exercise Craft's actual serializer: it reads the form before emitting its event.
    // Direct save/autosave calls bypass the jQuery data('serializer') callback.
    const directSave = await page.evaluate((nativeSource) => {
        const form = document.createElement('form');
        form.innerHTML = '<input name="title" value="Untouched"><input name="action" value="entries/save-entry"><input name="redirect" value="destination"><input data-hyper-store name="fields[links]" value="old"><div data-hyper-input><input name="fields[hyperData][row][linkValue]" value="latest"></div>';
        document.body.append(form);
        const host = form.querySelector('[data-hyper-input]');
        const store = form.querySelector('[data-hyper-store]');
        const portal = host.querySelector('input');
        const listeners = new Map();
        let flushes = 0;
        const editor = {
            $container: $(form),
            settings: { isUnpublishedDraft: false },
            namespaceInputName: name => name,
            on(name, handler) {
                listeners.set(name, [...(listeners.get(name) || []), handler]);
            },
            trigger(name, event) {
                for (const handler of listeners.get(name) || []) handler(event);
            },
            serializeForm: (0, eval)(`(${nativeSource})`),
        };
        const oldEscapeRegex = Craft.escapeRegex;
        Craft.escapeRegex = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        $(form).data('elementEditor', editor);
        $(form).data('initialSerializedValue', 'baseline');
        editor.lastSerializedValue = 'last-saved';
        const unregister = Audit.registerHyperInputSync(() => {
            flushes++;
            store.value = portal.value;
        });
        try {
            Audit.ensureElementEditorSerializeHook(host);
            Audit.ensureElementEditorSerializeHook(host);
            editor.on('serializeForm', event => { event.data.serialized += '&extension=retained'; });
            const save = editor.serializeForm(false);
            portal.value = 'latest-autosave';
            const autosave = editor.serializeForm(true);
            return { save, autosave, flushes, baseline: $(form).data('initialSerializedValue'), lastSaved: editor.lastSerializedValue };
        } finally {
            unregister();
            form.remove();
            Craft.escapeRegex = oldEscapeRegex;
        }
    }, nativeSerializeForm);
    for (const [name, expected] of [['save', 'latest'], ['autosave', 'latest-autosave']]) {
        const params = new URLSearchParams(directSave[name]);
        assert.equal(params.get('fields[links]'), expected);
        assert.equal(params.get('title'), 'Untouched');
        assert.equal(params.get('extension'), 'retained');
        assert(!params.has('fields[hyperData][row][linkValue]'));
        assert.equal(params.has('action'), name === 'save');
        assert.equal(params.has('redirect'), name === 'save');
    }
    assert.equal(directSave.flushes, 2);
    assert.equal(directSave.baseline, 'baseline');
    assert.equal(directSave.lastSaved, 'last-saved');
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
    const initialization = await page.evaluate(async () => {
        const form=document.createElement('form');const host=document.createElement('div');form.append(host);document.body.append(form);
        const store = document.createElement('input'); store.value = 'old'; form.append(store);
        const editor={serializeForm(){return store.value;},on(){},pauseLevel:0,pause:async function(){this.pauseLevel++;},resume:function(){this.pauseLevel--;},formObserver:{_serialize(){}}};
        // Field scripts can run before Craft installs the editor on the form.
        Audit.ensureElementEditorSerializeHook(host);
        setTimeout(() => $(form).data('elementEditor',editor), 20);
        const unregister = Audit.registerHyperInputSync(() => { store.value = 'fresh'; });
        const calls=[];
        await Audit.enqueueHyperFieldInit(host,async()=>{calls.push('parent');setTimeout(()=>Audit.enqueueHyperFieldInit(host,()=>calls.push('late child')),20);});
        const serialized = editor.serializeForm();
        unregister();form.remove();return {calls,pauseLevel:editor.pauseLevel,serialized};
    });
    assert.deepEqual(initialization,{calls:['parent','late child'],pauseLevel:0,serialized:'fresh'});
    // Removal cancels the pending debounce; remount creates one request, no stale handler.
    const before=await page.evaluate(()=>{window.removed=document.querySelector('#embed');removed.remove();return pending.length;});
    await page.waitForTimeout(600);
    assert.equal(await page.evaluate(()=>pending.length),before);
    await page.evaluate(()=>{document.body.append(removed);Audit.mountEmbed(removed);});
    await page.locator('.visible').fill('https://example.test/remount');
    await page.waitForFunction(n=>pending.length===n+1,before);
    assert.equal(await page.evaluate(()=>pending.length),before+1);
    console.log(JSON.stringify({ok:true,scenarios:["direct", "embed", "init", "removal"]}));
} finally {await browser.close();}
