'use strict';
// Run with: node --test test/select-all-matching.test.js
//
// "Select all" in the message list can reach past the loaded page to every
// message the list matches (#182). The selection is a set of UIDs from the
// server, and what makes it safe is a handful of agreements spread over four
// files, any of which looks like tidying up to remove.
//
// Measured on Nextcloud 34 with GreenMail, 62 messages at 20 a page: ticking
// the page offered the rest; taking it selected 62, kept page 2 ticked, and
// marked all 62 read on the server. A search for "odd" selected its 30, a
// move asked "Move all 30 selected messages to Kept?" and moved exactly those
// 30. A permanent delete named the count, and No left everything as it was.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const read = f => fs.readFileSync(path.join(__dirname, '..', f), 'utf8');

const store = read('dev/Stores/User/Messagelist.js');
const view = read('dev/View/User/MailBox/MessageList.js');
const client = read('tachyon/v/0.0.0/app/libraries/MailSo/Mail/MailClient.php');
const strings = JSON.parse(read('tachyon/v/0.0.0/app/localization/en/user.json'));

const body = (src, start) => {
	const at = src.indexOf(start);
	assert.notStrictEqual(at, -1, `${start} is gone`);
	return src.slice(at, at + 1500);
};

test('every move and drop takes its UIDs from the whole selection', () => {
	for (const name of ['listCheckedOrSelectedByFolder: () => {', 'listCheckedOrSelectedUidsWithSubMails: () => {']) {
		assert.match(body(store, name).slice(0, 200), /allMatching\(\)/,
			`${name.split(':')[0]} no longer looks at the selection beyond the page`);
	}
});

test('acting on all of them always says how many first', () => {
	const move = body(store, 'MessagelistUserStore.moveMessages = (');
	assert.match(move, /if \(MessagelistUserStore\.allMatching\(\) && !confirmed\)/,
		'moving or deleting the whole selection no longer asks');
	assert.match(move, /COUNT: oUids\.size/, 'the question no longer names the count');
	for (const key of ['CONFIRM_ALL_MOVE', 'CONFIRM_ALL_COPY', 'CONFIRM_ALL_DELETE']) {
		assert.match(strings.MESSAGE_LIST[key], /%COUNT%/, `${key} does not say how many`);
	}
});

test('commands that read the loaded messages are off for the whole selection', () => {
	for (const command of ['forwardCommand', 'downloadZipCommand', 'downloadAttachCommand']) {
		assert.match(view, new RegExp(`${command}: pageOnlyHelper`),
			`${command} would act on the page only while saying all are selected`);
	}
});

test('the server builds the selection with the list\'s own search', () => {
	const uids = body(client, 'public function MessageListUids(');
	assert.match(uids, /\$this->GetUids\(\$oParams, \$oInfo\)/,
		'the UIDs no longer come from the same search as the list');
	assert.match(uids, /->sIn\)\)\s*\{\s*throw/,
		'a search across folders is no longer refused');
});
