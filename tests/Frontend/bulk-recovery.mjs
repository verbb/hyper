// Production bulk-dialog and input logic, with queued Craft responses and a minimal dialog host.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium, firefox, webkit} from 'playwright';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const craftPath = process.env.HYPER_CRAFT_PATH || [path.join(root, 'vendor/craftcms/cms'), path.join(root, '.cache/verbb-tests/app/vendor/craftcms/cms')].find(p => fs.existsSync(path.join(p, 'src/web/assets/jquery/dist/jquery.js')));
assert(craftPath, 'Run ddev test first or set HYPER_CRAFT_PATH.');
const bundle = await build({stdin: {contents: "export {HyperInput} from './src/web/assets/field/src/js/input/HyperInput';", resolveDir: root}, bundle: true, format: 'iife', globalName: 'BulkInput', write: false});
const browser = await ({chromium, firefox, webkit}[process.env.HYPER_BROWSER || 'chromium']).launch({headless: true});
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://hyper.test/**', route => route.fulfill({contentType:'text/html',body:'<form id="owner"><section class="hyper-input-component"><input data-hyper-store name="fields[links]" type="hidden" value="[]"><div data-hyper-input><div data-hyper-links></div><div data-hyper-add-link><button type="button" data-hyper-bulk-add-open>Bulk Add</button></div><div data-hyper-link-templates></div></div></section></form>'}));
    await page.goto('http://hyper.test/');
    await page.addScriptTag({path: path.join(craftPath, 'src/web/assets/jquery/dist/jquery.js')});
    await page.evaluate(() => {
        customElements.define('pk-dialog',class extends HTMLElement {
            set open(value) {this.toggleAttribute('open',value);this.dispatchEvent(new CustomEvent('pk-open-change',{detail:{open:value}}));}
            get open() {return this.hasAttribute('open');}
        });
        window.pending=[];window.notices=[];
        window.Craft={
            initUiElements(){},randomString:()=>Math.random().toString(36).slice(2),t:(_category,text)=>text,
            cp:{displayError:text=>notices.push(text),displayNotice:text=>notices.push(text)},
            sendActionRequest:async (_method,action,options)=>{
                if(action.endsWith('bulk-element-select'))return {data:{html:'<div class="elementselect"><ul class="elements"><li><div class="element" data-id="42" data-site-id="1">About</div></li></ul></div>'}};
                return new Promise((resolve,reject)=>pending.push({data:options.data,resolve,reject}));
            },
            expandPostArray(flat){const tree={};for(const [name,value] of Object.entries(flat)){const keys=name.match(/[^\[\]]+/g);let node=tree;keys.forEach((key,i)=>{if(i===keys.length-1)node[key]=value;else node=node[key]||={};});}return tree;},
        };
        window.Garnish={getPostData:node=>Object.fromEntries($(node).find(':input').serializeArray().map(({name,value})=>[name,value]))};
        document.querySelector('[data-hyper-input]').dataset.hyperInputConfig=JSON.stringify({initialValue:[],settings:{fieldId:1,handle:'links',multipleLinks:true,linkTypes:[{handle:'url',label:'URL',type:'Url',bulk:{mode:'text'}},{handle:'entry',label:'Entry',type:'Entry',bulk:{mode:'elements'}}]}});
        window.succeed=index=>{
            const request=pending[index];const value=request.data.values?.[0]||String(request.data.elements[0].id);
            request.resolve({data:{blocks:[{handle:request.data.handle,html:`<input name="fields[hyperData][__LINK_ID__][linkValue]" value="${value}">`,serialized:{linkTypeHandle:request.data.handle,linkValue:value},tabLabels:[]}]}});
        };
    });
    await page.addScriptTag({content:bundle.outputFiles[0].text});
    await page.evaluate(()=>{window.instance=new BulkInput.HyperInput(document.querySelector('[data-hyper-input]'));instance.init();});
    await page.locator('[data-hyper-input].hyper-input--interactive').waitFor();
    for (const [mode,index] of [['text',0],['elements',2]]) {
        await page.getByRole('button',{name:'Bulk Add',exact:true}).click();
        await page.locator('pk-dialog[open]').waitFor();
        if(mode==='text')await page.locator('textarea').fill('/retry-resource');
        else await page.locator('[data-hyper-bulk-type]').selectOption('entry');
        await page.getByRole('button',{name:'Add',exact:true}).click();
        await page.waitForFunction(n=>pending.length===n,index+1);
        assert.equal(await page.getByRole('button',{name:'Add',exact:true}).isDisabled(),true);
        assert.equal(await page.locator('pk-dialog[open]').count(),1,'Dialog stays open during request');
        await page.evaluate(n=>pending[n].reject(new Error('Temporary test failure')),index);
        await page.waitForFunction(()=>!document.querySelector('pk-dialog').hasAttribute('aria-busy'));
        assert.equal(await page.locator('pk-dialog[open]').count(),1,'Failure preserves the dialog');
        assert.equal(await page.getByRole('alert').textContent(),'Couldn’t add links.');
        if(mode==='text')assert.equal(await page.locator('textarea').inputValue(),'/retry-resource');
        else assert.equal(await page.locator('.element[data-id="42"]').count(),1);
        assert.equal(await page.getByRole('button',{name:'Add',exact:true}).isEnabled(),true);
        await page.getByRole('button',{name:'Add',exact:true}).click();
        await page.waitForFunction(n=>pending.length===n,index+2);
        const requests=await page.evaluate(n=>[pending[n].data,pending[n+1].data],index);
        assert.deepEqual(requests[1],requests[0],'Retry preserves the original selection');
        await page.evaluate(n=>succeed(n),index+1);
        await page.waitForFunction(()=>!document.querySelector('pk-dialog'));
    }
    assert.equal(await page.locator('[data-hyper-link]').count(),2);
    assert.deepEqual(JSON.parse(await page.locator('[data-hyper-store]').inputValue()).map(link=>link.linkValue),['/retry-resource','42']);
    await page.evaluate(()=>instance.destroy());
    assert.deepEqual(errors,[]);
    console.log(JSON.stringify({ok:true,scenarios:['bulk text failure retains values','bulk element failure retains selection','pending request disables duplicate submission','retry adds once and closes on success']}));
} finally {await browser.close();}
