'use strict';
// Run with: node --test test/message-image-table-width.test.js
//
// An HTML message's images are capped with max-width: 100%. A percentage
// max-width lets an image add nothing to the width of the table cell it sits
// in, so a table declaring too small a width kept that width and squeezed its
// images down to it. The Acer newsletter in discussion #178 declares a 40px
// table around 700px images and rendered as a 74px column; Thunderbird and
// Outlook let the images widen the table instead.
//
// Images in a table are now capped at 100cqw, the width of the message pane,
// which is a length rather than a percentage of the cell. That needs the pane
// to be a size container. A quote is one too, or its indent pushes such an
// image past the pane; but not a quote inside a table, because a size container
// in a shrink-to-fit cell shrinks to nothing.
//
// Measured in Tachyon on Nextcloud 34 at two pane widths over ten shapes: the
// Acer mail and a synthetic 40px table now reach the pane, and a bare wrapper,
// unsized photos in and out of tables, signature icons, a sized image, a text
// table and a quoted photo render exactly as before. With the table exclusion
// removed, a quote inside a bare table measured 47px instead of 567px. What
// this file pins is the four rules agreeing with each other.
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const less = fs.readFileSync(path.join(__dirname, '..', 'dev/Styles/User/MessageView.less'), 'utf8');

// The body of the &.html block under .b-text-part, up to its closing brace.
const html = (() => {
	const at = less.indexOf('\t\t&.html {');
	assert.notStrictEqual(at, -1, 'the .b-text-part &.html block is gone');
	const end = less.indexOf('\n\t\t}', at);
	return less.slice(at, end);
})();
// The declarations of a rule written directly in that block.
const rule = selector => {
	const m = html.match(new RegExp('\\n\\t\\t\\t' + selector.replace(/ /g, '\\s+') + '\\s*\\{([^}]*)\\}'));
	return m ? m[1] : null;
};

test('the message part is a size container', () => {
	assert.match(html, /^\t\t&\.html \{\n\t\t\tcontainer-type: inline-size;/,
		'the HTML part is no longer a container, so 100cqw resolves against the viewport');
});

test('images in a table are capped at the pane, not the cell', () => {
	assert.match(rule('table img') || '', /max-width:\s*100cqw/,
		'images in a table are back on a percentage cap, which lets a table declaring '
		+ 'a narrow width squeeze them to it (#178)');
	assert.match(rule('img') || '', /max-width:\s*100%/,
		'the general image cap is gone; 100% stays the fallback where cqw is unsupported');
});

test('a quote is a container, except inside a table', () => {
	assert.match(rule('blockquote') || '', /container-type:\s*inline-size/,
		'a quote is no longer a container, so its indent pushes a capped image past the pane');
	assert.match(rule('table blockquote') || '', /container-type:\s*normal/,
		'a quote inside a table is a size container, which collapses it in a shrink-to-fit cell');
});

test('no selector the minifier rewrites', () => {
	// The build minifier turned :not(table blockquote) into :not(tableblockquote),
	// which is a valid selector for an element that does not exist.
	assert.doesNotMatch(html, /:not\([^)]*\s[^)]*\)/,
		'a :not() with a descendant combinator is mangled by the CSS minifier');
});
