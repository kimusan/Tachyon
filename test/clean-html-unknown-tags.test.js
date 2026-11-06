'use strict';
// Run with: node --test test/clean-html-unknown-tags.test.js
//
// #191: a helpdesk mailer wrapped the whole message in <root>, and the HTML
// view showed only the two lines outside it. cleanHtml() removes every element
// not on allowedTags, content included, which is right for <script> or <svg>
// but not for a tag the parser does not know. Those are inert, so they are now
// unwrapped first and their children go through the same removal pass as
// everything else.
//
// There is no DOM in node, so the real unwrap statement is sliced out of
// Html.js and run against stand-in elements. Measured in headless Chrome:
// <root>, <o:p> and <xml> parse as HTMLUnknownElement, <my-el> as a plain
// HTMLElement, and script, iframe, svg, math, title, noscript and template as
// none of those.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const html = fs.readFileSync(path.join(__dirname, '..', 'dev/Common/Html.js'), 'utf8');

const start = html.indexOf("tmpl.content.querySelectorAll('*').forEach(oElement =>");
const end = html.indexOf(');', start) + 2;
const removal = html.indexOf("':not('+allowedTags+')");

class HTMLElement {
	constructor(tagName) { this.tagName = tagName; }
}
class HTMLUnknownElement extends HTMLElement {}
class SVGElement {
	constructor(tagName) { this.tagName = tagName; }
}

const unwrapped = elements => {
	const out = [];
	new Function('tmpl', 'HTMLElement', 'HTMLUnknownElement', 'replaceWithChildren', html.slice(start, end))(
		{ content: { querySelectorAll: () => elements } },
		HTMLElement, HTMLUnknownElement, el => out.push(el.tagName)
	);
	return out;
};

test('unknown elements are unwrapped before the removal pass', () => {
	assert.notStrictEqual(start, -1, 'no unwrap of unknown elements found in dev/Common/Html.js');
	assert.notStrictEqual(removal, -1, 'the allowedTags removal pass moved');
	assert.ok(start < removal,
		'the unwrap runs after the removal pass, so <root> and its content are already gone');
});

test('<root>, <o:p> and custom elements keep their content', () => {
	assert.deepStrictEqual(unwrapped([
		new HTMLUnknownElement('ROOT'),
		new HTMLUnknownElement('O:P'),
		new HTMLElement('MY-EL')
	]), ['ROOT', 'O:P', 'MY-EL']);
});

test('<xml>, known tags and SVG elements are left for the removal pass', () => {
	assert.deepStrictEqual(unwrapped([
		new HTMLUnknownElement('XML'),
		new HTMLElement('SCRIPT'),
		new HTMLElement('IFRAME'),
		new HTMLElement('NOSCRIPT'),
		new HTMLElement('TITLE'),
		new SVGElement('font-face')
	]), []);
});
