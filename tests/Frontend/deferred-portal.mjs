// Deferred Craft widget hydration must not convert untouched server JSON into a draft.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium, firefox, webkit} from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const craftPath = process.env.HYPER_CRAFT_PATH || path.join(root, '.cache/verbb-tests/app/vendor/craftcms/cms');
const map = JSON.parse(fs.readFileSync(path.join(craftPath, 'src/web/assets/cp/dist/cp.js.map'), 'utf8'));
const source = map.sourcesContent[map.sources.findIndex(name => name.endsWith('/ElementEditor.js'))];
const start = source.indexOf('serializeForm: function (');
assert(start >= 0);
const serializer = source.slice(start, source.indexOf('\n    /**', start)).trim().replace(/^serializeForm: /, '').replace(/,$/, '');
const bundle = await build({stdin:{contents:"export {HyperInput} from './src/web/assets/field/src/js/input/HyperInput';",resolveDir:root},bundle:true,format:'iife',globalName:'DeferredPortal',write:false});
const browser = await ({chromium,firefox,webkit}[process.env.HYPER_BROWSER || 'chromium']).launch({headless:true});
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent('<form id="owner"><input name="title" value="Published"><button type="button" id="tab">Links tab</button><section id="links" style="display:none"></section></form>');
    await page.addScriptTag({path:path.join(craftPath,'src/web/assets/jquery/dist/jquery.js')});
    await page.addScriptTag({path:path.join(craftPath,'src/web/assets/garnish/dist/garnish.js')});
    await page.addScriptTag({content:bundle.outputFiles[0].text});
    await page.evaluate(nativeSource => {
        window.Craft = {
            formatInputId:name => name.replace(/[^A-Za-z0-9]/g,'-'), initUiElements() {},
            expandPostArray(flat) {
                const tree = {};
                for (const [name,value] of Object.entries(flat)) {
                    const keys = name.match(/[^\[\]]+/g); let node = tree;
                    keys.forEach((key,i) => { if(i === keys.length-1) node[key]=value; else node=node[key] ||= /^\d+$/.test(keys[i+1]) ? [] : {}; });
                }
                return tree;
            },
        };
        const saved = [{linkTypeHandle:'entry',uid:'d8634dd0-3722-41d5-93fb-3dea91fb40d6',newWindow:false,linkValue:[10264],linkText:'All Events',fields:{'f24e9bcd-b350-4717-a2c9-5d375f102abf':'base64:Z2hvc3Q=','a056618e-f1a8-4186-a9c3-055396ca0699':'base64:YXJyb3ctcmlnaHQ='},linkSiteId:4}];
        const field = document.querySelector('#links'); field.className='hyper-input-component';
        field.innerHTML = `<input type="hidden" data-hyper-store name="fields[links]"><div data-hyper-input><div data-hyper-links><div data-hyper-link data-link-id="row" data-link-handle="entry"><div data-hyper-portal>
            <div class="elementselect"><input type="hidden" name="fields[hyperData][row][linkValue][]" value="10264"></div>
            <input type="hidden" name="fields[hyperData][row][linkSiteId]" value="4">
            <input class="text" style="height:0;padding:0;border:0" aria-label="Link text" name="fields[hyperData][row][linkText]" value="All Events" maxlength="100" data-show-chars-left>
            <input type="hidden" name="fields[hyperData][row][fields][styles_btnVariant]" value="base64:Z2hvc3Q=">
            <input type="hidden" name="fields[hyperData][row][fields][styles_linkIcon]" value="base64:YXJyb3ctcmlnaHQ=">
            </div></div></div><div data-hyper-link-templates></div></div>`;
        window.original = JSON.stringify(saved); field.querySelector('[data-hyper-store]').value=original;
        const host = field.querySelector('[data-hyper-input]');
        host.dataset.hyperInputConfig=JSON.stringify({initialValue:[{...saved[0],id:'row',handle:'entry',fields:{styles_btnVariant:'base64:Z2hvc3Q=',styles_linkIcon:'base64:YXJyb3ctcmlnaHQ='}}],settings:{fieldId:1,handle:'links',linkTypes:[]}});
        const listeners = new Map(); window.checks=[];
        window.editor = {
            $container:$('#owner'),settings:{},pauseLevel:0,serializeForm:(0,eval)(`(${nativeSource})`),
            on(name,handler) { listeners.set(name,[...(listeners.get(name)||[]),handler]); },
            trigger(name,event) { for(const handler of listeners.get(name)||[]) handler(event); },
            async pause() { this.pauseLevel++; }, resume() { this.pauseLevel--; },
            async checkForm() { checks.push(this.serializeForm() !== $('#owner').data('initialSerializedValue')); },
            formObserver:{_serialize() {}},
        };
        $('#owner').data('elementEditor',editor).data('initialSerializedValue',editor.serializeForm());
        window.widget = new Garnish.NiceText(field.querySelector('.text'),{showCharsLeft:true});
        document.querySelector('#tab').addEventListener('click',() => {
            field.style.display=''; field.querySelector('.text').style.height='20px'; $(window).trigger('resize');
        });
        window.instance = new DeferredPortal.HyperInput(host); instance.init();
    }, serializer);
    await page.locator('[data-hyper-input].hyper-input--interactive').waitFor({state:'attached'});
    assert.equal(await page.evaluate(() => widget.initialized),false);
    const baseline = await page.evaluate(() => $('#owner').data('initialSerializedValue'));
    await page.getByRole('button',{name:'Links tab'}).click();
    await page.waitForFunction(() => widget.initialized);
    await page.waitForTimeout(200);
    assert.equal(await page.locator('[data-hyper-store]').inputValue(),await page.evaluate(() => original),'Showing the tab must preserve the exact server store, including UID keys and numeric IDs');
    assert.equal(await page.evaluate(() => editor.serializeForm()),baseline,'Craft must see no change after deferred hydration');
    await page.evaluate(() => $('.text').trigger('change'));
    assert.equal(await page.locator('[data-hyper-store]').inputValue(), await page.evaluate(() => original), 'An unchanged synthetic event must also stay pristine');
    await page.getByRole('textbox',{name:'Link text'}).fill('Edited events');
    assert.equal(JSON.parse(await page.locator('[data-hyper-store]').inputValue())[0].linkText,'Edited events');
    assert.notEqual(await page.evaluate(() => editor.serializeForm()),baseline);
    await page.getByRole('textbox',{name:'Link text'}).fill('');
    assert.equal(JSON.parse(await page.locator('[data-hyper-store]').inputValue())[0].linkText ?? '', '', 'Clearing a real value must reach the store');
    // Selection widgets can publish genuine edits through synthetic events or DOM mutations.
    await page.evaluate(() => { document.querySelector('.elementselect input').value='10265'; $('.elementselect').trigger('selectElements'); });
    assert.deepEqual(JSON.parse(await page.locator('[data-hyper-store]').inputValue())[0].linkValue,['10265']);
    await page.evaluate(() => { const input=document.querySelector('.elementselect input'); input.value='10266'; input.setAttribute('value','10266'); });
    await page.waitForTimeout(150);
    assert.deepEqual(JSON.parse(await page.locator('[data-hyper-store]').inputValue())[0].linkValue,['10266']);
    assert.equal(await page.evaluate(() => $('#owner').data('initialSerializedValue')),baseline,'Real edits must never replace the saved baseline');
    assert.deepEqual(errors,[]);
    console.log(JSON.stringify({ok:true,scenarios:['deferred Craft NiceText hydration','server bytes preserved','unchanged synthetic event','native text edit and clear','synthetic element selection','DOM-only selection change','persisted baseline unchanged']}));
} finally { await browser.close(); }
