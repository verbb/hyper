// A reopened outer link must not take ownership of its nested Hyper controls.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium, firefox, webkit} from 'playwright';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const craftPath = process.env.HYPER_CRAFT_PATH || [path.join(root, 'vendor/craftcms/cms'), path.join(root, '.cache/verbb-tests/app/vendor/craftcms/cms')].find(p => fs.existsSync(path.join(p, 'src/web/assets/jquery/dist/jquery.js')));
assert(craftPath, 'Run ddev test first or set HYPER_CRAFT_PATH.');
const bundle = await build({stdin: {contents: "export {HyperInput} from './src/web/assets/field/src/js/input/HyperInput';", resolveDir: root}, bundle: true, format: 'iife', globalName: 'NestedInput', write: false});
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
        const row = (id, child = '') => `<div data-hyper-link data-link-id="${id}" data-link-handle="url"><div class="hyper-wrapper"><div class="hyper-header"><button type="button" data-hyper-action="toggle-new-window" data-hyper-new-window-switch aria-pressed="false">New window</button><button type="button" data-hyper-action="move-down">Move down</button><button type="button" data-hyper-action="delete">Delete</button></div><div data-hyper-portal><input name="fields[hyperData][${id}][linkValue]" value="/${id}">${child}</div></div></div>`;
        const field = (id, rows) => {
            const section = document.createElement('section'); section.id = id; section.className = 'hyper-input-component';
            section.innerHTML = `<input data-hyper-store type="hidden" name="fields[${id}]"><div data-hyper-input><div data-hyper-links>${rows}</div><div data-hyper-add-link inert><button type="button" data-hyper-add-type="url">Add ${id}</button></div><div data-hyper-link-templates><template data-link-type="url"><input name="fields[hyperData][__LINK_ID__][linkValue]" value="/${id}-new"></template></div></div>`;
            const value = [...section.querySelector('[data-hyper-links]').children].map(el => ({id: el.dataset.linkId, handle:'url', linkTypeHandle:'url', linkValue:`/${el.dataset.linkId}`}));
            section.querySelector('[data-hyper-store]').value = JSON.stringify(value);
            section.querySelector('[data-hyper-input]').dataset.hyperInputConfig = JSON.stringify({initialValue:value,settings:{fieldId:id,handle:id,multipleLinks:true,newWindow:true,defaultLinkType:'url',linkTypes:[{handle:'url',label:'URL',type:'url'}]}});
            return section;
        };
        const child = field('child', row('child1'));
        const header = child.querySelector('.hyper-header');
        header.insertAdjacentHTML('beforeend', '<button type="button" data-hyper-action="select-tab" data-hyper-tab-index="1">Advanced child</button>');
        const portal = child.querySelector('[data-hyper-portal]');
        portal.innerHTML = `<div class="flex-fields">${portal.innerHTML}</div><div class="flex-fields hidden" data-native-pane>Native widget</div>`;
        window.visiblePaneNotifications = 0;
        window.addEventListener('resize', () => {
            if (!document.querySelector('[data-native-pane]')?.classList.contains('hidden')) window.visiblePaneNotifications++;
        });
        document.querySelector('#owner').append(field('parent', row('parent1', child.outerHTML)));
    });
    await page.addScriptTag({content: bundle.outputFiles[0].text});
    await page.evaluate(() => {
        window.instances = [...document.querySelectorAll('[data-hyper-input]')].map(el => new NestedInput.HyperInput(el));
        instances.forEach(instance => instance.init());
    });
    await page.locator('#parent > [data-hyper-input].hyper-input--interactive').waitFor();
    await page.locator('#child > [data-hyper-input].hyper-input--interactive').waitFor();
    await page.getByRole('button', {name:'Add parent',exact:true}).click();
    const parents = page.locator('#parent > [data-hyper-input] > [data-hyper-links] > [data-hyper-link]');
    assert.equal(await parents.count(), 2, 'The reopened outer Add control remains usable');
    assert.equal(await parents.nth(1).locator('[data-hyper-portal] input').inputValue(), '/parent-new', 'The outer field uses its own template');
    await page.locator('#child [data-hyper-new-window-switch]').click();
    assert.equal(await page.locator('#child [data-hyper-new-window-switch]').getAttribute('aria-pressed'), 'true');
    assert.equal(await parents.first().locator(':scope > .hyper-wrapper > .hyper-header [data-hyper-new-window-switch]').getAttribute('aria-pressed'), 'false', 'A child toggle cannot change its parent');
    await parents.first().locator(':scope > .hyper-wrapper > .hyper-header [data-hyper-action="move-down"]').click();
    assert.equal(await parents.nth(1).getAttribute('data-link-id'), 'parent1', 'A disabled child Move down cannot disable the parent move');
    await page.getByRole('button', {name:'Advanced child',exact:true}).click();
    assert.equal(await page.locator('[data-native-pane]').evaluate(el => el.classList.contains('hidden')), false);
    assert((await page.evaluate(() => visiblePaneNotifications)) > 0, 'Native hidden widgets receive their visibility notification');
    await page.locator('#child [data-hyper-action="delete"]').click();
    assert.equal(await parents.count(), 2, 'Deleting a child cannot delete its parent');
    assert.equal(await page.locator('#child [data-hyper-link]').count(), 0);
    await page.evaluate(() => instances.forEach(instance => instance.destroy()));
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ok:true,scenarios:['reopened parent add','parent template ownership','independent child toggle','parent move with disabled child action','independent child delete','native widget visibility on tab change']}));
} finally {await browser.close();}
