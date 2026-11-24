'use strict';
// Run with: node --test test/clean-html-style-urls.test.js
//
// With "allow styles" on, a <style> block in a message kept its rules as
// written. Style attributes only keep url() in three properties, and those go
// through the remote content consent; a rule in a <style> block went through
// neither, so .x{background:url(?/ProxyExternal/...)} made the server fetch a
// sender's URL (and, with the proxy off, the browser itself) as soon as the
// message was opened: a read receipt without consent. Rules now lose every
// declaration that loads something, except inline data:image/ ones.
//
// There is no DOM in node, so the helper is sliced out of Html.js.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const html = fs.readFileSync(path.join(__dirname, '..', 'dev/Common/Html.js'), 'utf8');
const start = html.indexOf('dropCSSUrls = rules =>');
const end = html.indexOf(',\n\n', start);
const dropCSSUrls = start === -1 ? null
	: new Function(`return ${html.slice(start, end).replace(/^dropCSSUrls = /, '')}`)();

test('rules in <style> blocks go through dropCSSUrls()', () => {
	assert.ok(dropCSSUrls, 'no dropCSSUrls() in dev/Common/Html.js: <style> rules keep their url()');
	assert.match(html, /rules: dropCSSUrls\(cleanCSS\(arr\[4\]\)\)/, 'parseCSS() does not use dropCSSUrls()');
});

test('declarations that load something are dropped', () => {
	for (const rule of [
		'background:url(?/ProxyExternal/aHR0cHM6Ly9ldmls)',
		'background-image: url("https://tracker.example/p.gif")',
		"list-style-image:url('//tracker.example/p.gif')",
		'border-image-source: URL(https://tracker.example/b)',
		'background: image-set("https://tracker.example/a.png" 1x)',
		'background: -webkit-image-set(url(https://tracker.example/a.png) 1x)',
		'mask: url(https://tracker.example/m.svg#x)',
		'background: \\75 rl(https://tracker.example/e)',
		'cursor: url(https://tracker.example/c), auto'
	]) {
		const out = dropCSSUrls(`color:red;${rule};margin:0`);
		assert.ok(!/tracker|ProxyExternal/i.test(out), `kept: ${rule} -> ${out}`);
		assert.match(out, /color:red;/, `lost the declaration before: ${out}`);
		assert.match(out, /margin:0/, `lost the declaration after: ${out}`);
	}
});

test('inline images and ordinary declarations stay', () => {
	assert.strictEqual(dropCSSUrls('color:red; font-weight:bold'), 'color:red; font-weight:bold');
	assert.strictEqual(dropCSSUrls('background:url(data:image/png;base64,AAAA) no-repeat'),
		'background:url(data:image/png;base64,AAAA) no-repeat');
	assert.strictEqual(dropCSSUrls('content:"\\2022"'), 'content:"\\2022"');
});
