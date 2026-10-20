// Deferred widgets must bind namespaces added by parent fields and Preview after PHP rendering.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium, firefox, webkit} from 'playwright';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const craftPath = process.env.HYPER_CRAFT_PATH || [path.join(root, 'vendor/craftcms/cms'), path.join(root, '.cache/verbb-tests/app/vendor/craftcms/cms')].find(p => fs.existsSync(path.join(p, 'src/web/assets/jquery/dist/jquery.js')));
assert(craftPath, 'Run ddev test first or set HYPER_CRAFT_PATH.');
const bundle = await build({stdin: {contents: "export {HyperInput} from './src/web/assets/field/src/js/input/HyperInput';", resolveDir: root}, bundle: true, format: 'iife', globalName: 'Widgets', write: false});
const browser = await ({chromium, firefox, webkit}[process.env.HYPER_BROWSER || 'chromium']).launch({headless: true});
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent('<form id="owner"></form>');
    await page.addScriptTag({path: path.join(craftPath, 'src/web/assets/jquery/dist/jquery.js')});
    await page.evaluate(() => {
        window.Craft = {
            initUiElements() {}, randomString: () => Math.random().toString(36).slice(2),
            formatInputId: name => name.replace(/^[^A-Za-z]+/, '').replace(/[^A-Za-z0-9_.]+/g, '-').replace(/-+$/, ''),
            appendBodyHtml: html => $(document.body).append(html),
            expandPostArray(flat) {
                const tree = {};
                for (const [name, value] of Object.entries(flat)) {
                    const keys = name.match(/[^\[\]]+/g); let node = tree;
                    keys.forEach((key, i) => {if (i === keys.length - 1) node[key] = value; else node = node[key] ||= {};});
                }
                return tree;
            },
        };
        window.Garnish = {getPostData: node => Object.fromEntries($(node).find(':input').serializeArray().map(({name, value}) => [name, value]))};
        // Missing targets leave native chooser buttons inert, matching the live failure.
        window.bindChooser = id => document.getElementById(id)?.addEventListener('click', event => { event.currentTarget.dataset.opened = 'true'; });
        window.instances = [];
    });
    await page.addScriptTag({content: bundle.outputFiles[0].text});
    for (const prefix of ['fields', 'fields[hyperData][parent][fields]', 'preview[fields][blocks][entries][uid:row][fields][hyperData][parent][fields]']) {
        await page.evaluate(prefix => {
            document.querySelector('#owner').innerHTML = '';
            const idPrefix = Craft.formatInputId(prefix);
            const field = document.createElement('section');field.className = 'hyper-input-component';
            const portal = id => `<div data-hyper-portal><input name="${prefix}[hyperData][${id}][linkValue]" value="/retained"><button type="button" id="${idPrefix}-hyperData-${id}-choose">Choose ${id}</button></div>`;
            const js = id => `<script id="__HYPER_INPUT_NAMESPACE__-widget-${id}">bindChooser('__HYPER_INPUT_NAMESPACE__-hyperData-${id}-choose');</script>`;
            field.innerHTML = `<input type="hidden" data-hyper-store name="${prefix}[child]" value='[{"linkTypeHandle":"url","linkValue":"/retained"}]'><div data-hyper-input><div data-hyper-links><div data-hyper-link data-link-id="saved" data-link-handle="url">${portal('saved')}</div></div><div data-hyper-add-link><button type="button" data-hyper-add-type="url">Add child</button></div><div data-hyper-link-templates><template data-link-type="url">${portal('__LINK_ID__')}</template></div></div>`;
            field.querySelector('[data-hyper-link]').dataset.linkJs = js('saved');
            field.querySelector('template').dataset.linkJs = js('__LINK_ID__');
            const host = field.querySelector('[data-hyper-input]');
            host.dataset.hyperInputConfig = JSON.stringify({initialValue:[{id:'saved',handle:'url',linkTypeHandle:'url',linkValue:'/retained'}],settings:{fieldId:1,handle:'child',inputNamePrefix:'hyperData[parent][fields]',inputIdPrefix:'hyperData-parent-fields',multipleLinks:true,defaultLinkType:'url',linkTypes:[{handle:'url',label:'URL',type:'url'}]}});
            document.querySelector('#owner').append(field);
            const instance = new Widgets.HyperInput(host);instances.push(instance);instance.init();
        }, prefix);
        await page.locator('[data-hyper-input].hyper-input--interactive').waitFor();
        await page.getByRole('button', {name:'Choose saved',exact:true}).click();
        assert.equal(await page.getByRole('button', {name:'Choose saved',exact:true}).getAttribute('data-opened'), 'true', `Saved selector binds its actual namespace: ${prefix}`);
        await page.getByRole('button', {name:'Add child',exact:true}).click();
        const added = page.locator('[data-hyper-links] > [data-hyper-link]').last();
        await added.getByRole('button', {name:/Choose /}).click();
        assert.equal(await added.getByRole('button', {name:/Choose /}).getAttribute('data-opened'), 'true', `New selector binds its actual namespace: ${prefix}`);
        assert.equal(await added.locator('input').inputValue(), '/retained');
        await page.evaluate(() => instances.splice(0).forEach(instance => instance.destroy()));
    }
    assert.deepEqual(errors, []);
    console.log('PASS nested widget namespaces: saved and new rows, root, nested and preview/Matrix wrappers');
} finally {await browser.close();}
