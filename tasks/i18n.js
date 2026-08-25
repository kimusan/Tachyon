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

const NOTE = [
	'Do not add English text for missing keys: they already fall back to English,',
	'and filling them in makes untranslated strings impossible to find again.'
];

const i18n = done => {
	const refUser = read(REFERENCE, 'user'),
		refAdmin = read(REFERENCE, 'admin'),
		total = Object.keys(refUser).length + Object.keys(refAdmin).length,
		locales = fs.readdirSync(L10N)
			.filter(d => d !== REFERENCE && fs.statSync(path.join(L10N, d)).isDirectory())
			.sort();

	const rows = locales.map(locale => {
		const u = compare(refUser, read(locale, 'user')),
			a = compare(refAdmin, read(locale, 'admin')),
			missing = u.missing.length + a.missing.length;
		return {
			locale,
			missing,
			maybe: u.maybe.length + a.maybe.length,
			done: (100 * (total - missing) / total).toFixed(1),
			where: bySection(u.missing.concat(a.missing)),
			// Kept apart so the terminal keeps its one line per locale while the
			// report can name the keys a translator would actually go and fill in
			keys: {
				user: u.missing, admin: a.missing,
				userMaybe: u.maybe, adminMaybe: a.maybe
			}
		};
	}).sort((x, y) => x.missing - y.missing || x.locale.localeCompare(y.locale));

	// Only missing keys count against a locale. A string identical to English
	// is a hint, and counting it here contradicted the 100.0% on its own row.
	const complete = rows.filter(r => !r.missing).length,
		table = [
			'locale   complete   missing   untranslated?  missing from',
			...rows.map(r =>
				`${r.locale.padEnd(8)} ${(r.done + '%').padStart(8)} ${String(r.missing).padStart(9)} ${String(r.maybe).padStart(14)}  ${r.where}`)
		];

	console.log(`\nReference ${REFERENCE}: ${Object.keys(refUser).length} user + ${Object.keys(refAdmin).length} admin strings\n`);
	table.forEach(line => console.log(line));
	console.log(`\n${complete} of ${rows.length} locales have every string translated. Reference is ${L10N}/${REFERENCE}.`);
	NOTE.forEach(line => console.log(line));
	console.log('');

	// Attached to GitHub releases, so translators can see where their language
	// stands without checking out the repository and running gulp.
	const out = process.env.I18N_REPORT;
	if (out) {
		const version = JSON.parse(fs.readFileSync('package.json', 'utf8')).version,
			lines = [
				`Tachyon ${version} translation status`,
				'',
				`Reference ${REFERENCE}: ${Object.keys(refUser).length} user + ${Object.keys(refAdmin).length} admin strings.`,
				`${complete} of ${rows.length} locales have every string translated.`,
				'',
				...NOTE,
				'',
				'"untranslated?" counts strings identical to their English source. That is a',
				'hint rather than a fault: some words are spelled the same in both languages.',
				'',
				...table,
				''
			];

		rows.forEach(r => {
			lines.push('', '-'.repeat(72), `${r.locale}  ${r.done}% complete`, '');
			if (!r.missing && !r.maybe) {
				lines.push('  Nothing missing. Thank you.');
				return;
			}
			r.missing || lines.push('  Nothing missing. The entries below are only hints.', '');
			[['user.json', r.keys.user], ['admin.json', r.keys.admin]].forEach(([file, keys]) => {
				keys.length && lines.push(`  Missing from ${file} (${keys.length}):`,
					...keys.map(k => '    ' + k), '');
			});
			[['user.json', r.keys.userMaybe], ['admin.json', r.keys.adminMaybe]].forEach(([file, keys]) => {
				keys.length && lines.push(`  Same as English in ${file} (${keys.length}), possibly untranslated:`,
					...keys.map(k => '    ' + k), '');
			});
		});

		fs.mkdirSync(path.dirname(out), { recursive: true });
		fs.writeFileSync(out, lines.join('\n').replace(/\n{3,}/g, '\n\n') + '\n');
		console.log(`Report written to ${out}\n`);
	}

	done();
};

exports.i18n = i18n;
