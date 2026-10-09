// Exercise the production layout bridge's timing and pristine-form contract in a browser.
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
if (!craftPath) throw new Error('Run ddev test first or set HYPER_CRAFT_PATH.');
const bundle = await build({
    stdin: {contents: "export {FieldLayoutDesigner} from './src/web/assets/field/src/js/settings/FieldLayoutDesigner';export {HyperSettings} from './src/web/assets/field/src/js/settings/HyperSettings';", resolveDir: root},
    bundle: true, format: 'iife', globalName: 'LayoutTest', write: false,
});
const browserType = {chromium, firefox, webkit}[process.env.HYPER_BROWSER || 'chromium'];
if (!browserType) throw new Error('Unsupported HYPER_BROWSER');
const browser = await browserType.launch({headless: true});
try {
    const page = await browser.newPage();
    await page.setContent('<form><input name="layoutConfig" value="saved layout"><div id="designer"></div></form>');
    await page.addScriptTag({path: path.join(craftPath, 'src/web/assets/jquery/dist/jquery.js')});
    await page.evaluate(() => {
        window.changes = [];
        window.Craft = {
            sendActionRequest: async () => ({data: {html: '<input data-config-input value="expanded working layout"><span>Link Text</span>'}}),
            initUiElements() {}, appendBodyHtml() {}, t: (_category, message) => message,
        };
    });
    await page.addScriptTag({content: bundle.outputFiles[0].text});
    await page.evaluate(() => {
        window.designer = new LayoutTest.FieldLayoutDesigner(document.querySelector('#designer'), {
            fieldId: null, type: 'url', value: 'saved layout',
            onChange(value) {
                changes.push(value);
                document.querySelector('input[name="layoutConfig"]').value = value;
            },
        });
        designer.load();
    });
    await page.locator('input[data-config-input]').waitFor({state: 'attached'});
    await page.evaluate(() => designer.flushPending());
    // Mounting and hover/focus chrome can mutate the DOM without changing the layout.
    await page.evaluate(() => document.querySelector('#designer span').classList.add('hover'));
    await page.waitForTimeout(300);
    assert.deepEqual(await page.evaluate(() => changes), []);
    assert.equal(await page.locator('input[name="layoutConfig"]').inputValue(), 'saved layout');

    const immediate = await page.evaluate(async () => {
        document.querySelector('input[data-config-input]').value = 'layout without Link Text';
        document.querySelector('#designer span').remove();
        // Real input events finish their mutation delivery before the next Save click.
        await Promise.resolve();
        return new FormData(document.querySelector('form')).get('layoutConfig');
    });
    assert.equal(immediate, 'layout without Link Text', 'Save must see the latest layout without waiting for a timer.');

    // Switching types can flush in the same task, before mutation delivery.
    const switched = await page.evaluate(() => {
        const target = document.querySelector('input[data-config-input]');
        target.value = 'changed field width';
        $(target).trigger('change');
        designer.flushPending();
        return document.querySelector('input[name="layoutConfig"]').value;
    });
    assert.equal(switched, 'changed field width');
    await page.waitForTimeout(300);
    assert.deepEqual(await page.evaluate(() => changes), ['layout without Link Text', 'changed field width']);

    await page.evaluate(() => {
        const errorRoot = document.createElement('div');
        errorRoot.id = 'error-designer';
        document.body.append(errorRoot);
        let attempts = 0;
        Craft.sendActionRequest = async () => {
            attempts++;

            if (attempts === 1) {
                throw {response: {data: {message: 'Layout service unavailable'}}};
            }

            return {data: {html: '<input data-config-input value="recovered layout">'}};
        };
        window.retryDesigner = new LayoutTest.FieldLayoutDesigner(errorRoot, {
            fieldId: null, type: 'url', value: 'saved layout', onChange() {},
        });
        retryDesigner.load();
    });
    await page.locator('#error-designer pk-state-panel').waitFor({state: 'attached'});
    assert.equal(await page.locator('#error-designer pk-state-panel').getAttribute('heading'), 'Field layout unavailable');
    assert.equal(await page.locator('#error-designer [slot="details"]').textContent(), 'Layout service unavailable');
    await page.locator('#error-designer button').click();
    await page.locator('#error-designer input[data-config-input]').waitFor({state: 'attached'});
    assert.equal(await page.locator('#error-designer input[data-config-input]').inputValue(), 'recovered layout');
    assert.equal(await page.locator('#error-designer .hyper-fld-stage').getAttribute('class'), 'hyper-fld-stage');

    const disposed = await page.evaluate(async () => {
        let resolve;
        let footers = 0;
        Craft.sendActionRequest = () => new Promise(done => { resolve = done; });
        Craft.appendBodyHtml = () => { footers++; };
        const root = document.createElement('div');
        document.body.append(root);
        const pending = new LayoutTest.FieldLayoutDesigner(root, {
            fieldId: null, type: 'url', value: 'original', onChange() { throw new Error('Disposed update'); },
        });
        pending.load();
        pending.destroy();
        pending.destroy();
        resolve({data: {html: '<input data-config-input value="late">', footHtml: 'late footer'}});
        await new Promise(done => setTimeout(done, 50));
        return {footers, children: root.childElementCount};
    });
    assert.deepEqual(disposed, {footers: 0, children: 0});

    const cleanup = await page.evaluate(async () => {
        let updates = 0;
        let destroys = 0;
        Craft.sendActionRequest = async () => ({data: {
            html: '<div><input data-config-input value="working"><div class="fld-tab"><button class="fld-add-btn"></button></div></div>',
        }});
        const root = document.createElement('div');
        document.body.append(root);
        const live = new LayoutTest.FieldLayoutDesigner(root, {
            fieldId: null, type: 'url', value: 'original', onChange() { updates++; },
        });
        live.load();
        await new Promise(done => setTimeout(done, 50));
        const input = root.querySelector('input');
        $(input.parentElement).data('hyperFld', {
            destroy() { destroys++; }, tabGrid: {destroy() { destroys++; }}, elementDrag: {destroy() { destroys++; }},
        });
        $(root.querySelector('.fld-tab')).data('fld-tab', {destroy() { destroys++; }});
        $(root.querySelector('button')).data('hud', {destroy() { destroys++; }});
        input.value = 'final edit';
        live.destroy();
        $(input).trigger('change');
        input.value = 'detached edit';
        live.flushPending();
        await new Promise(done => setTimeout(done, 50));
        return {updates, destroys, children: root.childElementCount};
    });
    assert.deepEqual(cleanup, {updates: 1, destroys: 5, children: 0});

    const queuedFooter = await page.evaluate(async () => {
        let finish;
        let updates = 0;
        Craft.sendActionRequest = async () => ({data: {html: '<input data-config-input value="expanded">', footHtml: 'queued'}});
        Craft.appendBodyHtml = () => new Promise(resolve => { finish = resolve; });
        const root = document.createElement('div');
        document.body.append(root);
        const pending = new LayoutTest.FieldLayoutDesigner(root, {fieldId: null, type: 'url', value: 'original', onChange() {updates++;}});
        pending.load();
        await new Promise(resolve => setTimeout(resolve, 10));
        pending.destroy();
        finish();
        await new Promise(resolve => setTimeout(resolve, 10));
        return {updates, children: root.childElementCount};
    });
    assert.deepEqual(queuedFooter, {updates: 0, children: 0});

    // Reuse the same DOM: listeners/timers belong to one mount and layouts survive.
    const remount = await page.evaluate(async () => {
        const root = document.createElement('div');
        root.dataset.hyperSettingsConfig = JSON.stringify({fieldId: 1, namespacedName: 'settings', registeredLinkTypes: [{value: 'url', label: 'URL'}], linkTypeTemplates: []});
        root.innerHTML = `<button data-hyper-settings-add-trigger>New type</button><div data-hyper-settings-add-list><ul data-hyper-settings-add-options></ul></div><div data-hyper-settings-item data-link-type-handle="url"><span data-hyper-settings-label>URL</span></div>
            <div data-hyper-settings-link-pane data-link-type-handle="url">
                <input data-label-field value="URL"><input data-handle-field value="url" data-generate-handle="1">
                <div data-hyper-fld data-link-type="url" data-layout-config="original"></div>
            </div>`;
        document.body.append(root);
        let requests = 0, generated = 0, disposed = 0, menuClicks = 0;
        Craft.cp = {displayError() { menuClicks++; }};
        Craft.initUiElements = container => {
            const trigger = container.querySelector('[data-hyper-settings-add-trigger]');
            const menu = container.querySelector('[data-hyper-settings-add-list]');
            if (trigger && menu) {
                document.body.append(menu);
                $(trigger).data('menubtn', {destroy() {menu.remove(); $(trigger).removeData('menubtn');}});
            }
        };
        Craft.HandleGenerator = class {constructor() { generated++; } destroy() { disposed++; }};
        Craft.sendActionRequest = async () => {
            requests++;
            return {data: {html: '<input data-config-input value="expanded">'}};
        };
        for (let i = 0; i < 10; i++) {
            const settings = new LayoutTest.HyperSettings(root);
            settings.init();
            document.querySelector('[data-hyper-settings-add-type]').click();
            await new Promise(done => setTimeout(done, 10));
            const value = root.querySelector('input[data-config-input]');
            value.value = `edited-${i}`;
            $(value).trigger('change');
            root.querySelector('[data-label-field]').dispatchEvent(new Event('input'));
            settings.destroy();
            settings.destroy();
            if (root.querySelectorAll('.hyper-fld-stage').length) throw new Error('Leaked designer stage');
        }
        await new Promise(done => setTimeout(done, 50));
        return {requests, generated, disposed, menuClicks, layout: root.querySelector('[data-hyper-fld]').dataset.layoutConfig};
    });
    assert.deepEqual(remount, {requests: 10, generated: 10, disposed: 10, menuClicks: 10, layout: 'edited-9'});

    console.log(JSON.stringify({ok: true, scenarios: ['pristine layout chrome', 'immediate layout save', 'type-switch flush without duplicate writes', 'field layout error retry', 'removal during pending request', 'native designer disposal', 'ten settings remounts', 'removal during queued footer preserves pristine layout']}));
} finally {
    await browser.close();
}
