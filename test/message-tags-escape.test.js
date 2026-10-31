'use strict';
// Run with: node --test test/message-tags-escape.test.js
//
// GHSA-vqwv-r925-cmrq: the message view renders IMAP keywords through an
// html: binding, and an IMAP atom may hold < and >. Only the $ namespace is
// allow-listed (since #171); everything else just has to miss a five-entry deny
// list, so a keyword like <img/src=x> reached the tag area as markup. The tag
// area sits outside the message body, so neither cleanHtml nor the remote-image
// consent gate covers it, and img-src allows http: by default: an injected
// image loads the moment the message is opened.
//
// A keyword is not always the user's own. A Sieve script with variables and
// imap4flags can copy a header into a flag, and on a shared folder another user
// may hold the right to set one.
//
// The real tagsToHTML expression, the real encodeHtml and the real keyword gate
// are sliced out of the sources and run here, so rewriting any of them without
// the escaping fails here rather than in someone's mailbox.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const read = f => fs.readFileSync(path.join(__dirname, '..', f), 'utf8');

const htmlSrc = read('dev/Common/Html.js'),
	folderSrc = read('dev/Stores/User/Folder.js'),
	viewSrc = read('dev/View/User/MailBox/MessageView.js'),
	template = read('tachyon/v/0.0.0/app/templates/Views/User/MailMessageView.html'),
	strings = JSON.parse(read('tachyon/v/0.0.0/app/localization/en/user.json'));

// Takes the declaration as it stands in the source, so nothing is reimplemented.
const slice = (src, from, to) => {
	const i = src.indexOf(from);
	assert.notStrictEqual(i, -1, `"${from}" is gone from the source`);
	const j = src.indexOf(to, i);
	assert.notStrictEqual(j, -1, `"${to}" is missing after "${from}"`);
	return src.slice(i, j + to.length);
};
const noComma = s => s.trim().replace(/,$/, '');

const decls = 'const '
	+ slice(htmlSrc, 'htmlre = /', '/g,') + ' '
	+ slice(htmlSrc, 'htmlmap = {', '},') + ' '
	+ noComma(htmlSrc.split('\n').find(l => l.includes('encodeHtml = text =>'))) + ';\n'
	+ 'const '
	+ slice(folderSrc, 'shownKeywords = [', '],') + ' '
	+ slice(folderSrc, 'ignoredKeywords = [', '],') + ' '
	+ noComma(slice(folderSrc, 'isAllowedKeyword = value => {', '},')) + ';';

const render = new Function('currentMessage', 'i18n',
	decls + '\nreturn ({ ' + slice(viewSrc, 'tagsToHTML: () =>', ".join(' '),") + ' }).tagsToHTML();');
const allowed = new Function('value', decls + '\nreturn isAllowedKeyword(value);');

// The same lookup and fallback as Common/Translator: the key, else the default,
// which tagsToHTML passes as the keyword itself.
const i18n = (key, valueList, defaulValue) => {
	const [group, name] = key.split('/');
	return strings[group]?.[name] || defaulValue || key;
};
const tags = (...flags) => render(() => ({ flags: () => flags }), i18n);

test('the keyword gate is not what stops markup', () => {
	// If this ever turns into an allow list for every namespace the escaping below
	// becomes belt and braces, which is fine. Until then it is the only defence.
	assert.strictEqual(allowed('<img/src=x>'), true,
		'the deny list now rejects markup; the test above it needs rewriting');
	assert.strictEqual(allowed('$notregistered'), false, 'the $ allow list is gone');
	assert.strictEqual(allowed('\\Seen'), false, 'system flags are no longer skipped');
});

test('a keyword carrying markup is rendered inert', () => {
	const out = tags('<img/src=x>');
	assert.ok(!out.includes('<img'), `an injected element survived: ${out}`);
	assert.ok(out.includes('&lt;img/src=x&gt;'), `the keyword was not escaped: ${out}`);
	assert.strictEqual(out.match(/<span/g).length, 1, `more than one element was rendered: ${out}`);
	assert.strictEqual(out.match(/</g).length, 2, `unescaped < outside the span: ${out}`);
});

test('a keyword cannot close the span and open its own element', () => {
	// The class attribute cannot be broken out of, since " is not valid in an
	// atom, but > is, so without escaping the tag itself ends early.
	const out = tags('x><img/src=y>');
	assert.ok(!out.includes('<img'), `the span was closed early: ${out}`);
	assert.strictEqual(out.match(/<span/g).length, 1, `extra elements were rendered: ${out}`);
});

test('& and " in a keyword do not escape their context', () => {
	const out = tags('a&b"c');
	assert.ok(out.includes('&amp;'), `& was not escaped: ${out}`);
	assert.ok(!/class="[^"]*"[^>]*"/.test(out), `a quote reached the class attribute: ${out}`);
});

test('ordinary keywords still render as before', () => {
	assert.strictEqual(tags('$important'),
		'<span class="focused msgflag-$important">Important</span>',
		'a translated keyword no longer renders its label and class');
	assert.strictEqual(tags('project-x'),
		'<span class="focused msgflag-project-x">project-x</span>',
		'an untranslated keyword no longer falls back to its own name');
	assert.strictEqual(tags('junk'), '', 'a denied keyword is rendered');
	assert.strictEqual(tags('$label1', 'junk', '$label2'),
		'<span class="focused msgflag-$label1">Important</span>  <span class="focused msgflag-$label2">Work</span>',
		'the joining of several keywords changed');
});

test('the binding that makes the escaping necessary is still html:', () => {
	// A switch to text: would make this file redundant rather than wrong, but it
	// should be a decision, not a surprise.
	assert.match(template, /data-bind="html: tagsToHTML"/,
		'tagsToHTML is no longer bound with html:, so this file needs revisiting');
});
