'use strict';
// Run with: node --test test/message-wrapper-table-width.test.js
//
// Discussion #178. cleanHtml turns a fixed width over 100px into width:100%
// plus that max-width, and a table with no width of its own shrink wraps, so
// a 640px mail inside a bare wrapper table collapsed to one word across. Since
// 3bd96847e such a wrapper is given width:100%.
//
// That rule first fired on any percentage inside the table. MJML mail puts
// each image in a bare table around a fixed width cell, with the image itself
// at width:100% and no max-width, so every one of those tables went to the
// pane's width and stretched its cell: a 12px icon rendered 259px across. Only
// a percentage with a max-width, which is what the conversion produces, now
// counts.
//
// Measured in headless Chrome against the reported AfterShip mail and the nine
// #178 shapes at a 587px and a 287px pane: the images are back to their cells
// (134, 347, 107, 107, 12 and 23px), and every shape renders as before.
//
// No DOM in node, so the real loop is sliced out of Html.js and run against
// stand-in tables.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const html = fs.readFileSync(path.join(__dirname, '..', 'dev/Common/Html.js'), 'utf8');

const start = html.indexOf("tmpl.content.querySelectorAll('table').forEach(table => {");
const end = html.indexOf('\n\t\t});', start) + 6;

const el = (width = '', maxWidth = '') => ({ style: { width, maxWidth } });
const table = (...inner) => Object.assign(el(), { querySelectorAll: () => inner });

const widened = t => {
	new Function('tmpl', html.slice(start, end))({ content: { querySelectorAll: () => [t] } });
	return t.style.width;
};

test('the wrapper loop is where this test looks for it', () => {
	assert.notStrictEqual(start, -1, 'no table wrapper loop found in dev/Common/Html.js');
});

test('a bare wrapper around a converted fixed width gets a width', () => {
	assert.strictEqual(widened(table(el('100%', '640px'))), '100%');
});

test("a bare table around an image at the sender's own width:100% is left alone", () => {
	assert.strictEqual(widened(table(el('100%'))), '');
});

test('a table that has a width keeps it', () => {
	const t = table(el('100%', '640px'));
	t.style.width = '320px';
	assert.strictEqual(widened(t), '320px');
});
