/* Tachyon Webmail (c) Tachyon | Licensed under AGPL v3 */
const fs = require('fs');
const path = require('path');

const L10N = 'tachyon/v/0.0.0/app/localization';
const REFERENCE = 'en';

const flatten = (obj, prefix = '') =>
	Object.entries(obj).reduce((out, [key, value]) => {
		Object.assign(out, (value && 'object' === typeof value)
			? flatten(value, prefix + key + '/')
			: { [prefix + key]: value });
		return out;
	}, {});

const read = (locale, file) => {
	const p = path.join(L10N, locale, file + '.json');
	return fs.existsSync(p) ? flatten(JSON.parse(fs.readFileSync(p, 'utf8'))) : null;
};

/**
 * Missing keys are the reliable signal, because L10n::load layers the locale
 * over English, so anything absent already falls back. Copying English in would
 * destroy that signal, which is how untranslated strings went unnoticed before.
 * Strings equal to their English source are only a hint: plenty of words are
 * spelled the same in several languages, so short ones are not counted.
 */
const compare = (ref, loc) => {
	if (!loc) {
		return { missing: Object.keys(ref), maybe: [] };
	}
	const missing = Object.keys(ref).filter(k => !(k in loc)),
		maybe = Object.keys(loc).filter(k =>
			k in ref && loc[k] === ref[k] && ref[k].includes(' ') && 8 < ref[k].length);
	return { missing, maybe };
};

const bySection = keys =>
	Object.entries(keys.reduce((out, k) => {
		const s = k.split('/')[0];
		out[s] = (out[s] || 0) + 1;
		return out;
	}, {})).sort((a, b) => b[1] - a[1]).map(([s, n]) => `${s} ${n}`).join(', ');

const i18n = done => {
	const refUser = read(REFERENCE, 'user'),
		refAdmin = read(REFERENCE, 'admin'),
		total = Object.keys(refUser).length + Object.keys(refAdmin).length,
		locales = fs.readdirSync(L10N)
			.filter(d => d !== REFERENCE && fs.statSync(path.join(L10N, d)).isDirectory())
			.sort();

	console.log(`\nReference ${REFERENCE}: ${Object.keys(refUser).length} user + ${Object.keys(refAdmin).length} admin strings\n`);

	const rows = locales.map(locale => {
		const u = compare(refUser, read(locale, 'user')),
			a = compare(refAdmin, read(locale, 'admin')),
			missing = u.missing.length + a.missing.length;
		return {
			locale,
			missing,
			maybe: u.maybe.length + a.maybe.length,
			done: (100 * (total - missing) / total).toFixed(1),
			where: bySection(u.missing.concat(a.missing))
		};
	}).sort((x, y) => x.missing - y.missing || x.locale.localeCompare(y.locale));

	console.log('locale   complete   missing   untranslated?  missing from');
	rows.forEach(r => console.log(
		`${r.locale.padEnd(8)} ${(r.done + '%').padStart(8)} ${String(r.missing).padStart(9)} ${String(r.maybe).padStart(14)}  ${r.where}`
	));

	const complete = rows.filter(r => !r.missing && !r.maybe).length;
	console.log(`\n${complete} of ${rows.length} locales complete. Reference is ${L10N}/${REFERENCE}.`);
	console.log('Do not add English text for missing keys: they already fall back to English,');
	console.log('and filling them in makes untranslated strings impossible to find again.\n');
	done();
};

exports.i18n = i18n;
