// A saved multiple-link limit must not prevent single-link replacement.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium, firefox, webkit} from 'playwright';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const craftPath = process.env.HYPER_CRAFT_PATH || [path.join(root, 'vendor/craftcms/cms'), path.join(root, '.cache/verbb-tests/app/vendor/craftcms/cms')].find(p => fs.existsSync(path.join(p, 'src/web/assets/jquery/dist/jquery.js')));
assert(craftPath, 'Run ddev test first or set HYPER_CRAFT_PATH.');
const bundle = await build({stdin: {contents: "export {HyperInput} from './src/web/assets/field/src/js/input/HyperInput';", resolveDir: root}, bundle: true, format: 'iife', globalName: 'CapacityInput', write: false});
const browser = await ({chromium, firefox, webkit}[process.env.HYPER_BROWSER || 'chromium']).launch({headless: true});
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://hyper.test/**', route => route.fulfill({contentType:'text/html',body:'<form id="owner"></form>'}));
    await page.goto('http://hyper.test/');
    await page.addScriptTag({path: path.join(craftPath, 'src/web/assets/jquery/dist/jquery.js')});
    await page.evaluate(() => {
        window.requests = 0; window.notices = [];
        window.Craft = {
            initUiElements() {}, randomString: () => Math.random().toString(36).slice(2), t: (category, text) => text,
            cp: {displayNotice: text => notices.push(text), displayError: text => notices.push(text)},
            sendActionRequest: async () => {
                requests++;
                return {data:{blocks:[{handle:'url',html:'<input name="fields[hyperData][__LINK_ID__][linkValue]" value="/replacement">',serialized:{linkTypeHandle:'url',uid:'replacement',linkValue:'/replacement'},tabLabels:[]}]}};
            },
            expandPostArray(flat) {
                const tree = {};
                for (const [name, value] of Object.entries(flat)) {
                    const keys = name.match(/[^\[\]]+/g); let node = tree;
                    keys.forEach((key, i) => {if (i === keys.length - 1) node[key] = value; else node = node[key] ||= {};});
                }
                return tree;
            },
        };
        window.Garnish = {getPostData: node => Object.fromEntries($(node).find(':input').serializeArray().map(({name,value})=>[name,value]))};
        localStorage.setItem('hyper:linkClipboard', JSON.stringify({version:1,type:'Url',linkTypeHandle:'url',operation:'copy',copiedAt:Date.now(),link:{linkValue:'/replacement'}}));
        for (const multipleLinks of [false,true]) {
            const id = multipleLinks ? 'multiple' : 'single';
            const section = document.createElement('section'); section.id=id;section.className='hyper-input-component';
            section.innerHTML=`<input type="hidden" data-hyper-store name="fields[${id}]"><div data-hyper-input><div data-hyper-links><div data-hyper-link data-link-id="${id}" data-link-handle="url"><div class="hyper-wrapper"><div class="hyper-header"><button type="button" data-hyper-action="paste">Paste ${id}</button></div><div data-hyper-portal><input name="fields[hyperData][${id}][linkValue]" value="/original"></div></div></div></div><div data-hyper-add-link><button type="button" data-hyper-add-type="url">Add ${id}</button></div><div data-hyper-link-templates><template data-link-type="url"><input name="fields[hyperData][__LINK_ID__][linkValue]" value=""></template></div></div>`;
            const value=[{id,handle:'url',linkTypeHandle:'url',uid:id,linkValue:'/original'}];
            section.querySelector('[data-hyper-store]').value=JSON.stringify(value);
            section.querySelector('[data-hyper-input]').dataset.hyperInputConfig=JSON.stringify({initialValue:value,settings:{fieldId:id,handle:id,multipleLinks,maxLinks:1,defaultLinkType:'url',linkTypes:[{handle:'url',label:'URL',type:'Url'}]}});
            document.querySelector('#owner').append(section);
        }
    });
    await page.addScriptTag({content:bundle.outputFiles[0].text});
    await page.evaluate(()=>{window.instances=[...document.querySelectorAll('[data-hyper-input]')].map(el=>new CapacityInput.HyperInput(el));instances.forEach(i=>i.init());});
    await page.locator('#single [data-hyper-input].hyper-input--interactive').waitFor();
    await page.getByRole('button',{name:'Paste single',exact:true}).click();
    await page.waitForFunction(()=>notices.length>0);
    assert.equal(await page.locator('#single [data-hyper-portal] input').inputValue(),'/replacement');
    assert.equal(await page.locator('#single [data-hyper-link]').count(),1);
    assert.equal(JSON.parse(await page.locator('#single [data-hyper-store]').inputValue())[0].linkValue,'/replacement');
    await page.getByRole('button',{name:'Paste multiple',exact:true}).click();
    assert.equal(await page.evaluate(()=>requests),1,'A full multiple-link field cannot request another pasted link');
    assert.equal(await page.locator('#multiple [data-hyper-portal] input').inputValue(),'/original');
    await page.evaluate(()=>instances.forEach(i=>i.destroy()));
    assert.deepEqual(errors,[]);
    console.log(JSON.stringify({ok:true,scenarios:['single paste after multiple-limit settings change','single replacement store','multiple maximum remains enforced']}));
} finally {await browser.close();}
