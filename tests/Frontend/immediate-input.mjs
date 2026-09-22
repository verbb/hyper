// Exercise the real HyperInput while Craft holds widget initialization open.
// A parent reads the hidden store in the same bubbling event, without a save hook.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium, firefox, webkit} from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const craftPath = process.env.HYPER_CRAFT_PATH || [
    path.join(root, 'vendor/craftcms/cms'),
    path.join(root, '.cache/verbb-tests/app/vendor/craftcms/cms'),
].find(candidate => fs.existsSync(path.join(candidate, 'src/web/assets/jquery/dist/jquery.js')));
assert(craftPath, 'Run ddev test first or set HYPER_CRAFT_PATH.');
const bundle = await build({
    stdin: {contents: "export {HyperInput} from './src/web/assets/field/src/js/input/HyperInput';", resolveDir: root},
    bundle: true, format: 'iife', globalName: 'ImmediateInput', write: false,
});
const browser = await ({chromium, firefox, webkit}[process.env.HYPER_BROWSER || 'chromium']).launch({headless: true});
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent('<form id="owner"></form>');
    await page.addScriptTag({path: path.join(craftPath, 'src/web/assets/jquery/dist/jquery.js')});
    await page.evaluate(() => {
        window.samples = [];
        window.Craft = {
            initUiElements() {},
            expandPostArray(flat) {
                const tree = {};
                for (const [name, value] of Object.entries(flat)) {
                    const keys = name.match(/[^\[\]]+/g);
                    let node = tree;
                    keys.forEach((key, i) => { if (i === keys.length - 1) node[key] = value; else node = node[key] ||= {}; });
                }
                return tree;
            },
        };
        window.Garnish = {getPostData: node => Object.fromEntries($(node).find(':input').serializeArray().map(({name, value}) => [name, value]))};
        const pause = new Promise(resolve => { window.finishPause = resolve; });
        $('#owner').data('elementEditor', {
            pauseLevel: 0,
            async pause() { this.pauseLevel++; await pause; },
            resume() { this.pauseLevel--; window.resumed = true; },
            serializeForm() { return $('#owner').serialize(); },
            formObserver: {_serialize() {}},
        });
        for (const id of ['edited', 'untouched']) {
            const value = [{linkTypeHandle: 'url', linkValue: 'https://example.test/original', fields: {unrendered: 'retain'}}];
            const field = document.createElement('section');
            field.className = 'hyper-input-component';
            field.id = id;
            field.innerHTML = `<input type="hidden" data-hyper-store name="fields[${id}]"><div data-hyper-input><div data-hyper-links><div data-hyper-link data-link-id="${id}" data-link-handle="url"><div data-hyper-portal><input class="url" name="fields[hyperData][${id}][linkValue]" value="https://example.test/original"></div></div></div><div data-hyper-link-templates></div></div>`;
            field.querySelector('[data-hyper-store]').value = JSON.stringify(value);
            field.querySelector('[data-hyper-input]').dataset.hyperInputConfig = JSON.stringify({
                initialValue: [{id, handle: 'url', ...value[0]}],
                settings: {fieldId: id, handle: id, defaultLinkType: 'url', linkTypes: []},
            });
            document.querySelector('#owner').append(field);
        }
        for (const type of ['input', 'change']) document.querySelector('#owner').addEventListener(type, event => {
            if (!event.isTrusted) return;
            const field = event.target.closest('.hyper-input-component');
            samples.push({expected: event.target.value, stored: JSON.parse(field.querySelector('[data-hyper-store]').value)[0]});
        });
    });
    await page.addScriptTag({content: bundle.outputFiles[0].text});
    await page.evaluate(() => {
        window.instances = [...document.querySelectorAll('[data-hyper-input]')].map(host => new ImmediateInput.HyperInput(host));
        instances.forEach(instance => instance.init());
    });
    const untouched = await page.locator('#untouched [data-hyper-store]').inputValue();
    // Widget initialization may emit synthetic changes. They must leave SSR bytes intact.
    await page.locator('#untouched .url').evaluate(input => {
        input.value = 'initialization noise';
        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.dispatchEvent(new Event('change', {bubbles: true}));
    });
    assert.equal(await page.locator('#untouched [data-hyper-store]').inputValue(), untouched);
    for (const value of ['https://example.test/early', '', 'https://example.test/original']) {
        await page.locator('#edited .url').fill(value);
        await page.locator('#edited .url').blur();
    }
    // Finish real block initialization, then repeat after portal watches are attached.
    await page.evaluate(() => finishPause());
    await page.waitForFunction(() => window.resumed);
    await page.locator('#edited .url').fill('https://example.test/ready');
    await page.locator('#edited .url').blur();
    const samples = await page.evaluate(() => window.samples);
    assert(samples.length >= 4);
    for (const {expected, stored} of samples) {
        assert.equal(stored.linkValue ?? '', expected, 'Parent reads the current edit before the event returns');
        assert.equal(stored.fields.unrendered, 'retain');
    }
    assert.equal(await page.locator('#untouched [data-hyper-store]').inputValue(), untouched);
    await page.evaluate(() => instances.forEach(instance => instance.destroy()));
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ok: true, scenarios: ['early edit', 'early clear', 'early revert', 'ready edit', 'same-event parent read', 'passive startup', 'unrendered fields retained']}));
} finally {
    await browser.close();
}
