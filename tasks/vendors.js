/* Tachyon Webmail (c) Tachyon | Licensed under AGPL v3 */
const gulp = require('gulp');

const { config } = require('./config');
const { del } = require('./common');

// fontastic
const fontasticFontsClear = () => del('tachyon/v/' + config.devVersion + '/static/css/fonts/snappymail.*');

const fontasticFontsCopy = () =>
	gulp
		.src('vendors/fontastic/fonts/snappymail.*', { encoding: false })
		.pipe(gulp.dest('tachyon/v/' + config.devVersion + '/static/css/fonts'));

const fontastic = gulp.series(fontasticFontsClear, fontasticFontsCopy);

exports.vendors = gulp.parallel(fontastic);
