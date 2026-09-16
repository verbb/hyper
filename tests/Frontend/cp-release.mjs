// Run only against an explicitly supplied isolated acceptance app and fixture.
// HYPER_CP_BASE, HYPER_CP_FIXTURE, HYPER_BROWSER and HYPER_ROLE configure the run.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {chromium, firefox, webkit} from 'playwright';
const base=process.env.HYPER_CP_BASE;
const fixturePath=process.env.HYPER_CP_FIXTURE;
if(!base || !fixturePath) throw new Error('Supply the isolated CP URL and fixture path.');
const f=JSON.parse(fs.readFileSync(fixturePath,'utf8'));
const browserName=process.env.HYPER_BROWSER || 'chromium';
const role=process.env.HYPER_ROLE || 'admin';
const browser=await ({chromium,firefox,webkit}[browserName]).launch({headless:true});
const page=await browser.newPage();
const errors=[];const failedRequests=[];
page.on('pageerror',e=>errors.push({message:e.message,stack:e.stack}));
page.on('response',r=>{if(r.status()>=400)failedRequests.push({status:r.status(),route:new URL(r.url()).searchParams.get('p')});});
try {
    assert.equal(await(await page.request.get(base+'/__audit_health')).text(),'isolated-hyper-release');
    await page.route('**/*',r=>{const url=decodeURIComponent(r.request().url());if(url.includes('actions/queue/get-job-info'))return r.fulfill({json:{total:0,jobs:[]}});return /actions\/(queue\/run|app\/check-for-updates)/.test(url)?r.fulfill({json:{}}):r.continue();});
    const identity=role==='admin'?f:f.restricted;
    await page.goto(base+'/index.php?p=admin/login');
    await page.locator('input[name="username"]:visible').fill(identity.username);
    await page.locator('input[name="password"]').fill(identity.password);
    await page.locator('button[type="submit"],input[type="submit"]').first().click();
    await page.waitForURL(u=>!u.search.includes('admin/login'),{timeout:60000});
    const editorUrl=`${base}/index.php?p=admin/entries/${f.matrix.section}/${f.matrix.ownerId}`;
    await page.goto(editorUrl);
    await page.waitForFunction(()=>window.jQuery && $('.matrix').first().data('matrix')?.elementEditor);
    await page.evaluate(()=>{const m=$('.matrix').first().data('matrix');m.$entriesContainer.children().each((i,n)=>$(n).data('entry').selfDestruct());});
    await page.waitForFunction(()=>$('.matrix').first().data('matrix').$entriesContainer.children().length===0);
    await page.getByRole('button',{name:'New entry',exact:false}).first().click();
    const inputs=page.locator('.matrixblock [data-hyper-input] input[name$="[linkValue]"]');
    const nested=page.locator('.matrixblock [data-hyper-input].hyper-input--ready').first();
    await nested.locator('[data-hyper-add-trigger]').click();
    await nested.locator('[data-hyper-add-type="url"]').click();
    const urls=['alpha','beta','gamma'].map(s=>`https://example.test/${browserName}/${role}/${s}`);
    await inputs.first().fill(urls[0]);
    await page.evaluate(()=>$('.matrix').first().data('matrix').$entriesContainer.children().last().data('entry').duplicate());
    await page.waitForFunction(()=>$('.matrix').first().data('matrix').$entriesContainer.children().length===2);
    await inputs.nth(1).waitFor();
    assert.equal(await inputs.nth(1).inputValue(),urls[0]);
    await inputs.nth(1).fill(urls[1]);
    await page.waitForFunction(()=>!!$('.matrix').first().data('matrix').$entriesContainer.children().last().data('entry'));
    await page.evaluate(()=>{const e=$('.matrix').first().data('matrix').$entriesContainer.children().last().data('entry');e.onActionSelect(e.$actionMenu.find('[data-action="copy"]')[0]);});
    await page.evaluate(()=>$('.matrix').first().data('matrix').pasteEntries());
    await inputs.nth(2).waitFor();
    assert.equal(await inputs.nth(2).inputValue(),urls[1]);
    await inputs.nth(2).fill(urls[2]);
    await page.waitForFunction(()=>!!$('.matrix').first().data('matrix').$entriesContainer.children().last().data('entry'));
    await page.evaluate(()=>{const m=$('.matrix').first().data('matrix');m.$entriesContainer.children().last().data('entry').moveUp();m.$entriesContainer.children().first().data('entry').selfDestruct();});
    await page.waitForFunction(()=>$('.matrix').first().data('matrix').$entriesContainer.children().length===2);
    // Wait for autosave to finish for this ordinary-save journey. The separate native
    // control case covers Craft's upstream cancellation race on immediate submission.
    await page.waitForTimeout(1500);
    await page.waitForFunction(()=>!$('#main-form').data('elementEditor')?.savingDraft,{},{timeout:60000});
    await page.getByRole('button',{name:'Save',exact:true}).first().click();
    await page.waitForURL(u=>!decodeURIComponent(u.search).includes(`/entries/${f.matrix.section}/`),{timeout:60000});
    // Let Craft's destination index finish loading before leaving it again.
    await page.waitForLoadState('networkidle');
    await page.goto(editorUrl);
    await inputs.nth(1).waitFor();
    assert.deepEqual(await inputs.evaluateAll(ns=>ns.map(n=>n.value)),[urls[2],urls[1]]);
    assert.equal(await page.locator('[data-hyper-input]').first().locator('[data-hyper-links]').first().locator(':scope > [data-hyper-link]').count(),1);
    assert.deepEqual(failedRequests,[]);
    const unexpected=errors.filter(e=>!(e.message==='undefined'||e.message==='Form already being submitted.'||(e.message==="Cannot read properties of undefined (reading 'data')"&&e.stack.includes('/cp.js'))));
    assert.deepEqual(unexpected,[]);
    console.log(JSON.stringify({ok:true,browser:browserName,role,scenarios:['saved card mode renders inline','add','duplicate','copy/paste','reorder','delete','save/reload'],knownCraftCancellationErrors:errors.length}));
} catch(error) {
    console.log(JSON.stringify({errors,failedRequests}));
    throw error;
} finally {await browser.close();}
