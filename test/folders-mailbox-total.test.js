'use strict';
// Run with: node --test test/folders-mailbox-total.test.js
//
// FolderUserStore.totalEmailsInMailbox sums every folder the server listed, for
// discussion #181, where the asked-for number is "absolutely all emails" so a
// backup or an import can be held against it. Two things make it fragile enough
// to pin, because both look like tidying up.
//
// The walk descends with subFolders(), not the subFolders.length guard used in
// getNextFolderNames. That .length is a plain getter on the observable which
// skips knockout's dependency tracker, so guarding on it means an empty subtree
// is never subscribed to and a folder created later never reaches the total.
// Measured: with the guard restored, a first child under a childless folder left
// the sum at 8 instead of 58.
//
// And the sum is gated on the IMAP LIST-STATUS capability. Without it the folder
// list arrives carrying no counts at all, only the folders opened since have
// one, and summing those produces a number that looks exactly like a complete
// one. Measured: with the gate deleted the same two trees reported 30 and 4912
// rather than declining to answer.
//
// There is no DOM in node and no knockout in the test dependencies, so the
// behaviour above was measured in headless Chrome against the real store module
// bundled by rollup, ten cases. What this file pins is the agreements between
// files that no single file shows.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const read = f => fs.readFileSync(path.join(__dirname, '..', f), 'utf8');

const store = read('dev/Stores/User/Folder.js');
const model = read('dev/Model/FolderCollection.js');
const settings = read('dev/Settings/User/Folders.js');
const template = read('tachyon/v/0.0.0/app/templates/Views/User/SettingsFolders.html');
const strings = JSON.parse(read('tachyon/v/0.0.0/app/localization/en/user.json'));

const computed = (() => {
	const at = store.indexOf('totalEmailsInMailbox');
	assert.notStrictEqual(at, -1, 'totalEmailsInMailbox is gone from the folder store');
	// Up to the end of the computed, which is the next line at that indent.
	const rest = store.slice(at);
	const end = rest.indexOf('\n\t\t\t}');
	return rest.slice(0, -1 === end ? rest.length : end);
})();

test('the sum descends with subFolders(), not the length guard', () => {
	assert.match(computed, /walk\(\s*folder\.subFolders\(\)\s*\)/,
		'the walk no longer calls subFolders(), so it cannot sum nested folders');
	assert.doesNotMatch(computed, /subFolders\.length/,
		'the walk guards on subFolders.length, which reads past knockout\'s dependency '
		+ 'tracker: an empty subtree is never subscribed to and a folder added later '
		+ 'is missing from the total until something else forces a recompute');
});

test('the sum refuses to answer without LIST-STATUS', () => {
	assert.match(computed, /hasCapability\('LIST-STATUS'\)/,
		'the LIST-STATUS gate is gone, so on a server without it the total is built '
		+ 'from whichever folders happen to have been opened and reads as complete');
	assert.match(computed, /return null/,
		'the gate no longer returns null, so there is nothing for the view to hide on');
});

test('the folder model still names the two fields the walk reads', () => {
	// addObservablesTo gives totalEmails a default of 0, which is why the walk
	// needs no null handling and why an empty mailbox is 0 rather than unknown.
	assert.match(model, /^\t\t\ttotalEmails: 0,$/m,
		'FolderModel no longer declares totalEmails, so every folder would sum as undefined');
	assert.match(model, /this\.subFolders = ko\.observableArray\(/,
		'FolderModel no longer declares subFolders as an observableArray');
});

test('an unknown total hides the line instead of printing something', () => {
	assert.match(settings, /null === total \? '' :/,
		'the view model no longer maps the unknown total to an empty string, so a '
		+ 'server without LIST-STATUS would render "null" or an empty label');
	assert.match(template, /data-bind="visible: totalMessages"/,
		'the line is no longer hidden when the total is unknown');
	assert.match(template, /data-bind="text: totalMessages"/,
		'the line no longer prints the total');
});

test('the label the template asks for exists', () => {
	assert.match(template, /data-i18n="SETTINGS_FOLDERS\/TOTAL_MESSAGES"/,
		'the template no longer asks for the TOTAL_MESSAGES label');
	assert.ok(strings.SETTINGS_FOLDERS?.TOTAL_MESSAGES,
		'SETTINGS_FOLDERS/TOTAL_MESSAGES is missing from en/user.json, so the label '
		+ 'renders empty and the number appears with nothing explaining it');
});
