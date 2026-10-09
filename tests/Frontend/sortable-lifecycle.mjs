import assert from 'node:assert/strict';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium, firefox, webkit} from 'playwright';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const bundle = await build({stdin: {contents: "export {HyperSortableList} from './src/web/assets/field/src/js/utils/sortableList';", resolveDir: root}, bundle: true, format: 'iife', globalName: 'SortableAudit', write: false});
const browser = await ({chromium, firefox, webkit}[process.env.HYPER_BROWSER || 'chromium']).launch({headless: true});
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent('<style>.item {height:80px} #list {width:400px}</style><div id="list"><div class="item" data-id="a"><button>A</button></div><div class="item" data-id="b"><button>B</button></div><div class="item" data-id="c"><button aria-describedby="custom-instructions">C</button></div></div><p id="custom-instructions">Custom drag instructions</p>');
    await page.addScriptTag({content:bundle.outputFiles[0].text});
    await page.evaluate(() => {
        window.events = [];
        window.mount = () => {
            window.list = new SortableAudit.HyperSortableList({container:document.querySelector('#list'), itemSelector:':scope > .item', handleSelector:'button', getItemId:el=>el.dataset.id, onReorder:(from,to)=>events.push(['reorder',from,to]), onDragStart:()=>events.push(['start']), onDragEnd:()=>events.push(['end'])});
            list.init();
        };
        mount();
    });
    await page.waitForFunction(() => document.querySelector('[id^="dnd-kit-description"]'));
    // Keyboard dragging uses the same manager and allows a reliable cancel assertion.
    await page.getByRole('button',{name:'A',exact:true}).focus();
    await page.keyboard.press('Space');
    await page.waitForFunction(() => events.some(e=>e[0]==='start'));
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => events.some(e=>e[0]==='end'));
    assert.deepEqual(await page.evaluate(()=>events),[['start'],['end']]);
    await page.evaluate(()=>list.destroy());
    assert.equal(await page.locator('[id^="dnd-kit-description"], [id^="dnd-kit-announcement"]').count(),0,'Destroy removes the manager accessibility nodes');
    for (let i=0;i<10;i++) {
        await page.evaluate(()=>mount());
        await page.waitForFunction(()=>document.querySelector('[id^="dnd-kit-description"]'));
        assert.equal(await page.getByRole('button',{name:'A',exact:true}).evaluate(el=>!!document.getElementById(el.getAttribute('aria-describedby'))),true,'Remounted handles refer to the current drag instructions');
        await page.evaluate(()=>list.destroy());
    }
    assert.equal(await page.locator('[id^="dnd-kit-description"], [id^="dnd-kit-announcement"]').count(),0,'Repeated field mounts leave no drag manager DOM behind');
    await page.evaluate(()=>{events.length=0;mount();});
    await page.getByRole('button',{name:'A',exact:true}).focus();
    await page.keyboard.press('Space');
    await page.waitForFunction(()=>events.length===1);
    await page.evaluate(()=>list.destroy());
    assert.deepEqual(await page.evaluate(()=>events),[['start'],['end']],'Removing a field during a drag balances the host autosave pause without committing a reorder');
    assert.equal(await page.locator('#list.hyper-dragging, .is-dragging, [id^="dnd-kit-description"], [id^="dnd-kit-announcement"]').count(),0);
    assert.equal(await page.getByRole('button',{name:'C',exact:true}).getAttribute('aria-describedby'),'custom-instructions');
    await page.evaluate(()=>{for(let i=0;i<10;i++){mount();list.destroy();}});
    await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
    assert.equal(await page.locator('[id^="dnd-kit-description"], [id^="dnd-kit-announcement"]').count(),0,'Immediate removal leaves no delayed accessibility nodes');
    assert.deepEqual(errors,[]);
    console.log(JSON.stringify({ok:true,scenarios:['keyboard drag cancel balances callbacks','drag manager cleanup','ten mount/remove cycles','field removal during drag resumes autosave']}));
} finally {await browser.close();}
