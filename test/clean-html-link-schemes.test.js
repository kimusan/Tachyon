'use strict';
// Run with: node --test test/clean-html-link-schemes.test.js
//
// cleanHtml() kept any href that started with a scheme, so a message could
// carry <a href="javascript:…"> or data:text/html links. Upstream fixed this
// in e3383df25 (GHSA-7463-5wp4-wx54) with an allow-list, ported here as is.
//
// The check runs on oElement.href, which the URL parser has already
// normalised: leading spaces, tabs and newlines inside the scheme are gone and
// the scheme is lower case. So " java\nscript:" arrives as "javascript:".
// Verified in headless Chrome against a GreenMail message with javascript: in
// three spellings, data:, vbscript: and file: (all lost their href) and
// https, mailto, tel and an upper-case HTTP link (all kept).
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const html = fs.readFileSync(path.join(__dirname, '..', 'dev/Common/Html.js'), 'utf8');

const line = html.split('\n').find(l => /^\s*schemesre = /.test(l));
const schemesre = line && new Function(`return ${line.replace(/^\s*schemesre = /, '').replace(/,\s*$/, '')}`)();

test('the scheme allow-list exists', () => {
	assert.ok(schemesre instanceof RegExp, 'no schemesre allow-list in dev/Common/Html.js');
});

test('script-capable and local schemes are refused', () => {
	for (const href of ['javascript:alert(1)', 'data:text/html,x', 'vbscript:x', 'file:///etc/passwd',
		'blob:https://example.com/x', 'about:blank', 'httpx://example.com', 'mailtox:a@b']) {
		assert.ok(!schemesre.test(href), `${href} is allowed`);
	}
});

test('ordinary link schemes are kept', () => {
	for (const href of ['https://example.com/', 'http://example.com/', 'mailto:a@example.com',
		'tel:+4512345678', 'sms:+4512345678', 'geo:55.6,12.5', 'msteams:/l/chat/0/0']) {
		assert.ok(schemesre.test(href), `${href} is refused`);
	}
});

test('a link failing the allow-list loses its href', () => {
	const at = html.indexOf('!schemesre.test(value)');
	assert.notStrictEqual(at, -1, 'the <a> branch no longer checks schemesre');
	const branch = html.slice(at, html.indexOf('} else {', at));
	assert.match(branch, /delAttribute\('href'\)/, 'a disallowed href is kept');
	assert.ok(at < html.indexOf('oElement.href = stripTracking(value)'),
		'the allow-list check runs after the href has already been accepted');
});
