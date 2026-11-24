// Store projection must keep Craft's posted empty values. Craft reads a stored `null`
// as "no value yet" and applies field defaults, so `''` → `null` reverts an editor's choice.
import assert from 'node:assert/strict';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const bundle = await build({stdin: {contents: "export {serializeLinksForStore} from './src/web/assets/field/src/js/input/serialize';", resolveDir: root}, bundle: true, format: 'esm', write: false});
const {serializeLinksForStore} = await import(`data:text/javascript;base64,${Buffer.from(bundle.outputFiles[0].text).toString('base64')}`);

// Shapes Craft posts for an off Lightswitch, an empty Checkboxes group, an empty
// Multi-select and a Table row with an empty Number cell.
const cleared = () => ({
    toggle: '',
    checks: '',
    multi: '',
    table: [{col1: 'Delta', col2: {value: '', locale: 'en-US'}}],
});
const link = (id, fields) => ({id, handle: 'url', linkTypeHandle: 'url', linkValue: `https://example.test/${id}`, fields});

// A new link has no page-load reference.
const [added] = serializeLinksForStore([link('new1', cleared())], []);
assert.deepEqual(added.fields, cleared(), 'new link keeps cleared values');

// A link saved with null values (default applied on read) is edited again.
const storedNull = link('saved', {toggle: null, checks: null, multi: null, table: [{col1: 'Delta', col2: null}]});
const [reEdited] = serializeLinksForStore([link('saved', cleared())], [storedNull]);
assert.deepEqual(reEdited.fields, cleared(), 'link stored with null keeps cleared values');

// Reordering compares by index, so a moved link meets another link's reference.
const [moved] = serializeLinksForStore([link('other', cleared())], [link('saved', {})]);
assert.deepEqual(moved.fields, cleared(), 'moved link keeps cleared values');

// Existing behaviour: array references and empty widget objects still store [].
const [existing] = serializeLinksForStore(
    [link('saved', {checks: '', multi: null, related: {}})],
    [link('saved', {checks: ['a'], multi: ['a']})],
);
assert.deepEqual(existing.fields, {checks: [], multi: [], related: []}, 'array-shaped clears store []');

console.log(JSON.stringify({ok: true, scenarios: ['new link empty values', 'null-stored link re-edit', 'reordered link', 'Table empty Number cell', 'array-shaped clears']}));
