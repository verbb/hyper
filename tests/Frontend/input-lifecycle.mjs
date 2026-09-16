// Exercise the production DOM observer with lightweight widget constructors.
import assert from 'node:assert/strict';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium, firefox, webkit} from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const substitutes = {
    './css/hyper.css': '',
    '@verbb/plugin-kit-web/plugin-kit': 'export const allDefined = async () => {};',
    './hyperPkComponents.js': 'export const HYPER_PK_COMPONENTS = [];',
    './globals': 'export function registerHyperGlobals() {} export function registerHyperFormHooks() {}',
    './input/HyperInput': `export class HyperInput {
        constructor(root) { this.root = root; root.dataset.instance = String(++window.mounts); }
        init() {}
        destroy() { window.destroys++; delete this.root.dataset.instance; }
    }`,
    './settings/HyperSettings': 'export class HyperSettings { init() {} }',
};
const bundle = await build({
    entryPoints: [path.join(root, 'src/web/assets/field/src/js/hyper.ts')],
    bundle: true, format: 'iife', write: false,
    plugins: [{name: 'widget-lifecycle-fixtures', setup(builder) {
        builder.onResolve({filter: /.*/}, args => Object.hasOwn(substitutes, args.path)
            ? {path: args.path, namespace: 'fixture'} : undefined);
        builder.onLoad({filter: /.*/, namespace: 'fixture'}, args => ({contents: substitutes[args.path], loader: 'js'}));
    }}],
});
const browserType = {chromium, firefox, webkit}[process.env.HYPER_BROWSER || 'chromium'];
if (!browserType) throw new Error('Unsupported HYPER_BROWSER');
const browser = await browserType.launch({headless: true});
try {
    const page = await browser.newPage();
    await page.setContent('<section id="first"><div id="field" data-hyper-input><div id="child" data-hyper-input></div></div></section><section id="second"></section>');
    await page.evaluate(() => { window.Craft = {Hyper: {}}; window.mounts = 0; window.destroys = 0; });
    await page.addScriptTag({content: bundle.outputFiles[0].text});
    await page.waitForFunction(() => window.mounts === 2);
    const initial = await page.locator('[data-hyper-input]').evaluateAll(nodes => nodes.map(node => node.dataset.instance));
    await page.evaluate(() => document.querySelector('#second').append(document.querySelector('#field')));
    // MutationObserver delivery happens before the next animation frame.
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(resolve)));
    assert.deepEqual(await page.locator('[data-hyper-input]').evaluateAll(nodes => nodes.map(node => node.dataset.instance)), initial);
    assert.equal(await page.evaluate(() => window.destroys), 0);
    await page.evaluate(() => { window.detached = document.querySelector('#field'); window.detached.remove(); });
    await page.waitForFunction(() => window.destroys === 2);
    await page.evaluate(() => document.querySelector('#first').append(window.detached));
    await page.waitForFunction(() => window.mounts === 4);
    assert.equal(await page.evaluate(() => window.destroys), 2);
    console.log(JSON.stringify({ok: true, scenarios: ['connected parent and child moves', 'detached disposal', 'fresh remount']}));
} finally {
    await browser.close();
}
