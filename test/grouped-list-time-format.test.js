'use strict';
// Run with: node --test test/grouped-list-time-format.test.js
//
// A row in the grouped message list shows only the time when its group label
// already carries the date, which is true of day groups and nothing else. The
// format used to come from the sort mode, so a search across folders, grouped
// by folder and sorted by date, showed "15:36" for a message from 2024 (#150).
// It now comes from the group: a day group says 'LT', and every other group,
// a plugin's included, falls back to the full 'AUTO' format.
//
// Measured in headless Chrome against the real template under the vendored
// knockout: before, folder rows rendered 'LT'; after, day rows 'LT' and folder
// and sender rows 'AUTO'. What this file pins is the agreement between the view
// and the template.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const read = f => fs.readFileSync(path.join(__dirname, '..', f), 'utf8');

const view = read('dev/View/User/MailBox/MessageList.js');
const template = read('tachyon/v/0.0.0/app/templates/Views/User/MailMessageList.html');
const grouped = template.slice(template.indexOf('<!-- ko if: listGrouped -->'),
	template.indexOf('<!-- ko ifnot: listGrouped -->'));

test('a grouped row reads its format from the group, defaulting to AUTO', () => {
	assert.match(grouped, /'data-time-format':\s*\$parent\.timeFormat \|\| 'AUTO'/,
		'the grouped row no longer takes its time format from its group');
	assert.doesNotMatch(template, /\$root\.timeFormat/,
		'the template reads a list-wide time format again');
});

test('only the day groups ask for the time alone', () => {
	const day = view.slice(view.indexOf("sort.includes('DATE')) {"));
	assert.match(day.slice(0, day.indexOf('list.push(current)')), /timeFormat: 'LT'/,
		'day groups no longer say their label carries the date');
	assert.strictEqual((view.match(/timeFormat: 'LT'/g) || []).length, 1,
		'a group other than the day groups shows the time alone');
});
