import assert from 'node:assert/strict';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium, firefox, webkit} from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const bundle = await build({stdin: {contents: "export * from './src/web/assets/field/src/js/input/conditionalLayout';", resolveDir: root}, bundle: true, format: 'iife', globalName: 'Audit', write: false});
const browser = await ({chromium, firefox, webkit}[process.env.HYPER_BROWSER || 'chromium']).launch();
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent('<div id="portal"><div data-layout-tab="first"><div data-layout-element="url"><input id="url" value="initial"></div><div data-layout-element="conditional" data-layout-element-placeholder class="hidden"></div></div><div data-layout-tab="last" class="hidden"><div data-layout-element="stable">Unchanged widget</div></div></div>');
    await page.evaluate(() => {
        window.pending = []; window.labels = []; window.messages = []; window.initialized = [];
        window.Craft = {
            sendActionRequest: (method, action, options) => new Promise(resolve => pending.push({method, action, options, resolve})),
            initUiElements: root => initialized.push(root.dataset.layoutElement),
            t: (_, text) => text, cp: {displayError: text => messages.push(text)},
        };
        window.value = {handle: 'url', linkValue: 'initial', fields: {retained: 'saved'}};
        window.input = document.querySelector('#url'); window.stable = document.querySelector('[data-layout-element="stable"]');
    });
    await page.addScriptTag({content: bundle.outputFiles[0].text});
    await page.evaluate(() => {
        window.layout = new Audit.ConditionalLayout(document.querySelector('#portal'), {fieldId: 1, siteId: 1, elementId: 2, inputContext: 'signed', inputNamePrefix: 'fields[nested][fields]'}, 'row', () => value, next => { labels = next; });
        input.addEventListener('input', () => { value.linkValue = input.value; layout.schedule(); });
    });
    await page.locator('#url').fill('first request');
    await page.waitForFunction(() => pending.length === 1);
    await page.locator('#url').fill('latest request');
    await page.waitForFunction(() => pending.length === 2);
    // A late response for older content must never replace the newer layout.
    await page.evaluate(() => pending[0].resolve({data: {tabs: []}}));
    assert.equal(await page.locator('#url').count(), 1);
    await page.evaluate(() => pending[1].resolve({data: {tabs: [
        {uid: 'first', label: 'Content', elements: [{uid: 'url', html: true}, {uid: 'conditional', html: '<div data-layout-element="conditional"><input id="revealed" value="saved"></div>'}]},
        {uid: 'middle', label: 'Nested', elements: [{uid: 'child', html: '<div data-layout-element="child">Nested field</div>'}]},
        {uid: 'last', label: 'Advanced', elements: [{uid: 'stable', html: true}]},
    ]}}));
    await page.waitForSelector('#revealed');
    assert.deepEqual(await page.locator('[data-layout-tab]').evaluateAll(es => es.map(e => e.dataset.layoutTab)), ['first', 'middle', 'last']);
    assert.equal(await page.evaluate(() => input === document.querySelector('#url') && stable === document.querySelector('[data-layout-element="stable"]')), true);
    assert.equal(await page.locator('#url').inputValue(), 'latest request');
    assert.equal(await page.locator('#url').evaluate(input => document.activeElement === input), true, 'Refresh preserves input focus');
    assert.equal(await page.locator('#revealed').inputValue(), 'saved');
    assert.deepEqual(await page.evaluate(() => initialized), ['conditional', 'child']);
    assert.equal(await page.evaluate(() => pending[1].options.data.namespace), 'fields[nested][fields][hyperData][row]');
    assert.equal(await page.evaluate(() => pending[1].options.data.inputContext), 'signed');
    await page.locator('#url').fill('hide');
    await page.waitForFunction(() => pending.length === 3);
    await page.evaluate(() => pending[2].resolve({data: {tabs: [
        {uid: 'first', label: 'Content', elements: [{uid: 'url', html: true}, {uid: 'conditional', html: false}]},
        {uid: 'last', label: 'Advanced', elements: [{uid: 'stable', html: true}]},
    ]}}));
    await page.waitForFunction(() => !document.querySelector('#revealed'));
    assert.deepEqual(await page.locator('[data-layout-tab]').evaluateAll(es => es.map(e => e.dataset.layoutTab)), ['first', 'last']);
    assert.equal(await page.evaluate(() => value.fields.retained), 'saved');
    await page.locator('#url').fill('destroy during request');
    await page.waitForFunction(() => pending.length === 4);
    await page.evaluate(() => { layout.destroy(); pending[3].resolve({data: {tabs: []}}); });
    assert.equal(await page.locator('#url').count(), 1);
    assert.deepEqual(errors, []);
    assert.deepEqual(await page.evaluate(() => messages), []);
    console.log('PASS conditional layouts: stale responses, retained inputs, middle tabs, hiding, nested namespace, teardown');
} finally { await browser.close(); }
