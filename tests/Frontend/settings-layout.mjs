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
    stdin: {contents: "export {FieldLayoutDesigner} from './src/web/assets/field/src/js/settings/FieldLayoutDesigner';", resolveDir: root},
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
    console.log(JSON.stringify({ok: true, scenarios: ['pristine layout chrome', 'immediate layout save', 'type-switch flush without duplicate writes']}));
} finally {
    await browser.close();
}
