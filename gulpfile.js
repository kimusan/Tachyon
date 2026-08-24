/* Tachyon Webmail (c) Tachyon | Licensed under AGPL v3 */
const gulp = require('gulp');

const { cleanStatic } = require('./tasks/common');
const { js, jsLint } = require('./tasks/js');
const { css, cssLint } = require('./tasks/css');
const { buildIcons } = require('./tasks/icons');
const { sri } = require('./tasks/sri');
const { i18n } = require('./tasks/i18n');

const clean = gulp.series(cleanStatic);

const lint = gulp.parallel(jsLint, cssLint);

const buildState1 = gulp.parallel(js, gulp.series(buildIcons, css));
const buildState2 = gulp.series(clean, buildState1, sri);

const build = gulp.parallel(lint, buildState2);

exports.css = gulp.series(buildIcons, css);
exports.buildIcons = buildIcons;
exports.lint = lint;
exports.sri = sri;
exports.i18n = i18n;
exports.build = build;
exports.default = build;
