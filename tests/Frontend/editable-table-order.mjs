// Craft editable tables never renumber row IDs. Portal and Matrix snapshots must store
// rows in DOM order without holes, as Craft's own form submission does.
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
].find(candidate => fs.existsSync(path.join(candidate, 'src/web/assets/cp/dist/cp.js.map')));
if (!craftPath) throw new Error('Run ddev test first or set HYPER_CRAFT_PATH to an installed Craft package.');
const map = JSON.parse(fs.readFileSync(path.join(craftPath, 'src/web/assets/cp/dist/cp.js.map'), 'utf8'));
const craftSource = name => map.sourcesContent[map.sources.findIndex(source => source.endsWith(name))];
// Use Craft's own expansion and EditableTable so a Craft change to either shows up here.
const craftJs = craftSource('/Craft.js');
const expandStart = craftJs.indexOf('expandPostArray: function (arr) {');
assert(expandStart >= 0, 'Locate the installed Craft.expandPostArray.');
const expandSource = craftJs.slice(expandStart, craftJs.indexOf('\n  },\n', expandStart) + 4).replace(/^expandPostArray: /, '');
const editableTableSource = craftSource('/EditableTable.js');
assert(editableTableSource, 'Locate the installed Craft.EditableTable.');
const source = './src/web/assets/field/src/js/input/';
const bundle = await build({stdin: {contents: `export {mergeLinkWithBlockContent} from '${source}blockContent';export {matrixEntryData} from '${source}matrix';export {serializeLinksForStore} from '${source}serialize';`, resolveDir: root}, bundle: true, format: 'iife', globalName: 'TableOrder', write: false});
const browser = await ({chromium, firefox, webkit}[process.env.HYPER_BROWSER || 'chromium']).launch({headless: true});
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent('<div id="host"></div>');
    await page.addScriptTag({path: path.join(craftPath, 'src/web/assets/jquery/dist/jquery.js')});
    await page.addScriptTag({path: path.join(craftPath, 'src/web/assets/garnish/dist/garnish.js')});
    await page.evaluate(({expandSource, editableTableSource}) => {
        window.Craft = {expandPostArray: (0, eval)(`(${expandSource})`), hasMousePointerEvents: () => false, t: (_category, message) => message};
        (0, eval)(editableTableSource);
        // Markup as `_includes/forms/editableTable.twig` renders it. The zero-height
        // wrapper keeps EditableTable from building row UI the test doesn't need.
        window.tableHtml = (id, name, rows) => `<div style="height:0;overflow:hidden">${'<input type="hidden" name="' + name + '" value="">'}<table id="${id}" class="editable"><tbody>${rows.map(([rowId, label, qty]) => `<tr data-id="${rowId}"><td><input name="${name}[${rowId}][col1]" value="${label}"></td><td><input name="${name}[${rowId}][col2][value]" value="${qty}"><input type="hidden" name="${name}[${rowId}][col2][locale]" value="en-US"></td></tr>`).join('')}</tbody></table></div>`;
        window.mountTable = (id, name) => new Craft.EditableTable(id, name, {col1: {type: 'singleline'}, col2: {type: 'number'}}, {allowAdd: true, allowDelete: true, allowReorder: true});
        window.rows = [[0, 'Alpha', '1'], [1, 'Beta', '2'], [2, 'Gamma', '3']];
        // Craft's deleteRow removes the <tr>, DataTableSorter and moveUp move it, and addRow
        // appends `biggestId + 1`. None of them rename the row's inputs.
        window.edits = {
            delete: id => { $(`#${id} tr[data-id="1"]`).remove(); },
            reorder: id => { $(`#${id} tr[data-id="2"]`).prependTo($(`#${id} > tbody`)); },
            replaceLast: id => {
                $(`#${id} tr[data-id="2"]`).remove();
                const name = $(`#${id}`).data('editable-table').baseName;
                $(`#${id} > tbody`).append($(tableHtml('tmp', name, [[3, 'Delta', '4']])).find('tr'));
            },
        };
    }, {expandSource, editableTableSource});
    await page.addScriptTag({content: bundle.outputFiles[0].text});

    // A saved link's numeric ID sits in the same names as the row IDs, so the row segment
    // must come from the table, not from the first numeric key.
    const portalFor = linkId => page.evaluate((linkId) => {
        const results = {};
        for (const edit of Object.keys(edits)) {
            const name = `fields[hyperData][${linkId}][fields][specs]`;
            const attrsName = `fields[hyperData][${linkId}][customAttributes]`;
            host.innerHTML = `<div data-hyper-input><div data-hyper-link id="block"><div data-hyper-portal>${tableHtml('specs', name, rows)}${tableHtml('attrs', attrsName, rows)}</div></div></div>`;
            mountTable('specs', name);
            mountTable('attrs', attrsName);
            edits[edit]('specs');
            edits[edit]('attrs');
            const link = {id: linkId, handle: 'url', linkTypeHandle: 'url', linkValue: 'https://example.test', fields: {}};
            const merged = TableOrder.mergeLinkWithBlockContent(link, document.querySelector('#block'));
            const [stored] = TableOrder.serializeLinksForStore([merged], [link]);
            results[edit] = {
                specs: merged.fields.specs.map(row => row?.col1 ?? null),
                attrs: merged.customAttributes.map(row => row?.col1 ?? null),
                json: JSON.stringify(stored),
            };
        }
        return results;
    }, linkId);
    const portal = await portalFor('row');
    assert.deepEqual(portal.delete.specs, ['Alpha', 'Gamma'], 'deleting a middle row leaves no hole');
    assert.deepEqual(portal.reorder.specs, ['Gamma', 'Alpha', 'Beta'], 'reordered rows keep DOM order');
    assert.deepEqual(portal.replaceLast.specs, ['Alpha', 'Beta', 'Delta'], 'a row added after a delete leaves no hole');
    assert.deepEqual(portal.delete.attrs, ['Alpha', 'Gamma'], 'custom attributes table leaves no hole');
    assert.deepEqual(portal.reorder.attrs, ['Gamma', 'Alpha', 'Beta'], 'custom attributes table keeps DOM order');
    for (const result of Object.values(portal)) {
        assert(!result.json.includes('null'), `store has no null rows: ${result.json}`);
    }
    const saved = await portalFor('1');
    assert.deepEqual(saved.delete.specs, ['Alpha', 'Gamma'], 'numeric link ID: deleting a middle row leaves no hole');
    assert.deepEqual(saved.reorder.specs, ['Gamma', 'Alpha', 'Beta'], 'numeric link ID: reordered rows keep DOM order');
    assert.deepEqual(saved.replaceLast.specs, ['Alpha', 'Beta', 'Delta'], 'numeric link ID: a row added after a delete leaves no hole');
    assert.deepEqual(JSON.parse(portal.reorder.json).fields.specs[0], {col1: 'Gamma', col2: {value: '3', locale: 'en-US'}}, 'nested cell inputs move with their row');

    // Matrix entries inside a link snapshot through matrixEntryData (copy, duplicate, refresh).
    const matrix = await page.evaluate(() => {
        const name = 'fields[hyperData][row][fields][blocks][entries][uid:abc][fields][specs]';
        host.innerHTML = `<div data-hyper-input><div id="entry" data-base-input-name="fields[hyperData][row][fields][blocks][entries][uid:abc]"><input name="fields[hyperData][row][fields][blocks][entries][uid:abc][type]" value="text">${tableHtml('nested', name, rows)}</div></div>`;
        mountTable('nested', name);
        edits.delete('nested');
        edits.reorder('nested');
        return TableOrder.matrixEntryData(document.querySelector('#entry'));
    });
    assert.deepEqual(matrix.fields.specs.map(row => row?.col1 ?? null), ['Gamma', 'Alpha'], 'Matrix entry table rows keep DOM order without holes');
    assert.equal(matrix.type, 'text');

    // Only editable-table rows are renumbered. Other integer keys keep Craft's expansion.
    const untouched = await page.evaluate(() => {
        host.innerHTML = '<div data-hyper-input><div data-hyper-link id="block"><div data-hyper-portal><input name="fields[hyperData][row][fields][ids][5]" value="five"><input name="fields[hyperData][row][fields][ids][2]" value="two"></div></div></div>';
        const merged = TableOrder.mergeLinkWithBlockContent({id: 'row', handle: 'url', fields: {}}, document.querySelector('#block'));
        return Object.entries(merged.fields.ids);
    });
    assert.deepEqual(untouched, [['2', 'two'], ['5', 'five']]);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ok: true, scenarios: ['portal Table delete', 'numeric link ID', 'portal Table reorder', 'portal Table delete last then add', 'custom attributes delete and reorder', 'Matrix entry Table', 'non-table integer keys unchanged']}));
} finally {
    await browser.close();
}
