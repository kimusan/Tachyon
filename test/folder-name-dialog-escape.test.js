'use strict';
// Run with: node --test test/folder-name-dialog-escape.test.js
//
// GHSA-w5qm-c43v-c7cq: two confirmation dialogs render their text through an
// html: binding and put a folder name into it unescaped. A folder in a shared
// namespace is named by another user, so its name put markup into the dialog
// the victim was about to confirm: moving every message a list matches (#182),
// and purging a folder. The locale strings carry their own markup
// (DANGER_DESC_HTML_1 wraps the name in <strong>), so the value is escaped, not
// the translated result.
//
// Verified in headless Chrome against a GreenMail folder named
// `Archive <b>tagtest</b>`: both dialogs showed the name in bold before, and
// literally after. What this file pins is that each call site still escapes it.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const read = f => fs.readFileSync(path.join(__dirname, '..', f), 'utf8');

const sites = [
	['dev/Stores/User/Messagelist.js', 'MESSAGE_LIST/CONFIRM_ALL_MOVE'],
	['dev/View/Popup/FolderClear.js', 'POPUPS_CLEAR_FOLDER/DANGER_DESC_HTML_1']
];

for (const [file, key] of sites) {
	test(`${file} escapes the folder name in ${key}`, () => {
		// FolderClear keeps a commented-out older version of the call
		const src = read(file).split('\n').filter(l => !/^\s*\/\//.test(l)).join('\n');
		assert.match(src, /^import \{[^}]*\bencodeHtml\b[^}]*\} from 'Common\/Html';$/m,
			'encodeHtml is not imported');
		// The i18n() call for this key, up to the first FOLDER value passed to it
		const call = src.slice(src.indexOf(key));
		const folder = call.match(/FOLDER:\s*([^\n}]+)/);
		assert.ok(folder, `no FOLDER value passed for ${key}`);
		assert.match(folder[1], /^encodeHtml\(/,
			`FOLDER reaches an html: binding unescaped: ${folder[1].trim()}`);
	});
}

test('the Ask and FolderClear dialogs still render as HTML, so the escaping is needed', () => {
	assert.match(read('tachyon/v/0.0.0/app/templates/Views/Common/PopupsAsk.html'), /html: askDesc/);
	assert.match(read('tachyon/v/0.0.0/app/templates/Views/User/PopupsFolderClear.html'), /html: dangerDescHtml/);
});
