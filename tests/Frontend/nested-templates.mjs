// New outer rows must leave inner templates available for their own row IDs.
import assert from 'node:assert/strict';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium, firefox, webkit} from 'playwright';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const bundle = await build({stdin: {contents: "export {HyperLinkBlock} from './src/web/assets/field/src/js/input/HyperLinkBlock';export {parseLinkIdHtml} from './src/web/assets/field/src/js/utils/string';", resolveDir: root}, bundle: true, format: 'iife', globalName: 'NestedTemplates', write: false});
const browser = await ({chromium, firefox, webkit}[process.env.HYPER_BROWSER || 'chromium']).launch({headless: true});
try {
    const page = await browser.newPage();
    await page.setContent('<main></main>');
    await page.addScriptTag({content: bundle.outputFiles[0].text});
    const result = await page.evaluate(() => {
        const root = document.createElement('div');
        root.innerHTML = `<template data-link-type="url" data-link-placeholder="__HYPER_BLOCK_outer__" data-link-js="outer('__HYPER_BLOCK_outer__'); inner('__HYPER_BLOCK_inner__');">
            <input name="fields[hyperData][__HYPER_BLOCK_outer__][linkValue]">
            <div data-hyper-link-templates><template data-link-type="url" data-link-placeholder="__HYPER_BLOCK_inner__"><input name="fields[hyperData][__HYPER_BLOCK_outer__][fields][hyperData][__HYPER_BLOCK_inner__][linkValue]"></template></div>
        </template>`;
        const outer = NestedTemplates.HyperLinkBlock.getTemplateHtml(root, 'url', 'parent1');
        const live = document.createElement('div'); live.innerHTML = outer.html;
        const innerRoot = live.querySelector('[data-hyper-link-templates]');
        const first = NestedTemplates.HyperLinkBlock.getTemplateHtml(innerRoot, 'url', 'child1');
        const second = NestedTemplates.HyperLinkBlock.getTemplateHtml(innerRoot, 'url', 'child2');
        return {outer: outer.html, js: outer.js, first: first.html, second: second.html,
            hydrated: NestedTemplates.parseLinkIdHtml(root.innerHTML, 'pasted', '__HYPER_BLOCK_outer__')};
    });
    assert(result.outer.includes('[hyperData][parent1][linkValue]'));
    assert(result.outer.includes('[hyperData][__HYPER_BLOCK_inner__][linkValue]'));
    assert(result.first.includes('[hyperData][parent1][fields][hyperData][child1][linkValue]'));
    assert(result.second.includes('[hyperData][parent1][fields][hyperData][child2][linkValue]'));
    assert.equal(result.js, "outer('parent1'); inner('__HYPER_BLOCK_inner__');");
    assert(result.hydrated.includes('[hyperData][pasted][linkValue]'));
    assert(result.hydrated.includes('[hyperData][__HYPER_BLOCK_inner__][linkValue]'));
    console.log(JSON.stringify({ok:true,scenarios:['nested blank templates retain independent row IDs','nested sibling templates','nested deferred scripts','hydrated nested templates']}));
} finally {await browser.close();}
