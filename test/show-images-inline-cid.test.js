'use strict';
// Run with: node --test test/show-images-inline-cid.test.js
//
// cleanHtml() resolves an inline image to its attachment and keeps the cid:
// in data-x-src, which Compose reads back for a reply. showExternalImages()
// copied every data-x-src into src, so showing a message's external images,
// by click or for a trusted sender, replaced each resolved inline image with
// "cid:..." or the proxy for it, and the image broke.
//
// There is no DOM in node, so the real image loop is sliced out of Message.js
// and run against stand-in images.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '..', 'dev/Model/Message.js'), 'utf8');
const start = source.indexOf("body.querySelectorAll('img[' + attr + ']').forEach(node => {");
const end = source.indexOf('});', start) + 3;

const showImages = (images, useProxy, regex = null) => {
	let hasImages = false;
	const isValid = src => {
		if (null == regex || (regex && src.match(regex))) {
			return true;
		}
		hasImages = true;
	};
	new Function('body', 'attr', 'isValid', 'useProxy', 'proxy', 'let src;\n' + source.slice(start, end))(
		{ querySelectorAll: () => images }, 'data-x-src', isValid, useProxy, url => 'proxy:' + url
	);
	return hasImages;
};

const image = (dataSrc, src = '') => ({ src, getAttribute: () => dataSrc });

test('the image loop is where it was', () => {
	assert.notStrictEqual(start, -1, 'the data-x-src image loop in showExternalImages() is gone');
});

for (const useProxy of [false, true]) {
	test(`an inline image keeps its attachment${useProxy ? ' through the proxy' : ''}`, () => {
		const inline = image('cid:logo@example', '?/Raw/0/view/attachment'),
			remote = image('https://example.com/a.png');
		showImages([inline, remote], useProxy);
		assert.strictEqual(inline.src, '?/Raw/0/view/attachment', 'showing images replaced the resolved inline image');
		assert.strictEqual(remote.src, (useProxy ? 'proxy:' : '') + 'https://example.com/a.png', 'the external image was not shown');
	});
}

test('an inline image alone does not count as a blocked image', () => {
	assert.strictEqual(showImages([image('cid:logo@example', 'x')], false, /^$/), false,
		'an inline image kept the "show images" prompt up for a sender outside the whitelist');
	assert.strictEqual(showImages([image('https://example.com/a.png')], false, /^$/), true);
});
