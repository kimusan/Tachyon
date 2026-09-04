/* Tachyon Webmail (c) Tachyon | Licensed under AGPL v3 */
const gulp = require('gulp');

const concat = require('gulp-concat');
const rename = require('gulp-rename');
const replace = require('gulp-replace');
const eol = require('gulp-eol');
const filter = require('gulp-filter');
const expect = require('gulp-expect-file');
const gcmq = require('gulp-group-css-media-queries');
const less = require('gulp-less');

const { config } = require('./config');
const { del } = require('./common');

const cleanCss = require('gulp-clean-css');
const cssClean = () => del(config.paths.staticCSS + '/*.css');

const cssBootBuild = () => {
	const
		src = config.paths.css.boot.src;
	return gulp
		.src(src)
		.pipe(expect.real({ errorOnFailure: true }, src))
		.pipe(concat(config.paths.css.boot.name))
		.pipe(replace(/\.\.\/(img|images|fonts|svg)\//g, '$1/'))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssMainBuild = () => {
	const
		lessFilter = filter('**/*.less', { restore: true }),
		src = config.paths.css.main.src.concat([config.paths.less.main.src]);

	return gulp
		.src(src)
		.pipe(expect.real({ errorOnFailure: true }, src))
		.pipe(lessFilter)
		.pipe(
			less({
				'paths': config.paths.less.main.options.paths
			})
		)
		.pipe(lessFilter.restore)
		.pipe(concat(config.paths.css.main.name))
		.pipe(replace(/\.\.\/(img|images|fonts|svg)\//g, '$1/'))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssAdminBuild = () => {
	const
		lessFilter = filter('**/*.less', { restore: true }),
		src = config.paths.css.admin.src;

	return gulp
		.src(src)
		.pipe(expect.real({ errorOnFailure: true }, src))
		.pipe(lessFilter)
		.pipe(
			less({
				'paths': config.paths.less.main.options.paths
			})
		)
		.pipe(lessFilter.restore)
		.pipe(concat(config.paths.css.admin.name))
		.pipe(replace(/\.\.\/(img|images|fonts|svg)\//g, '$1/'))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

// Same reason as the date picker: the theme mapping has to come after the
// library's own variables, and this sheet is injected at runtime.
const cssCalendarBuild = () => {
	return gulp
		.src(['vendors/event-calendar/dist/event-calendar.min.css', 'dev/Styles/CalendarTheme.css'])
		.pipe(concat('calendar.css', { separator: '\n\n' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

// The theme mapping is concatenated after the library's own variables rather
// than living in app.css. This stylesheet is injected at runtime, so it lands
// later in the cascade than app.css and would otherwise override it.
const cssDatePickerBuild = () => {
	return gulp
		.src(['vendors/air-datepicker/air-datepicker.css', 'dev/Styles/DatePickerTheme.css'])
		.pipe(concat('datepicker.css', { separator: '\n\n' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssBootMin = () => {
	return gulp
		.src(config.paths.staticCSS + config.paths.css.boot.name)
		.pipe(cleanCss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssMainMin = () => {
	return gulp
		.src(config.paths.staticCSS + config.paths.css.main.name)
		.pipe(gcmq())
		.pipe(cleanCss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssAdminMin = () => {
	return gulp
		.src(config.paths.staticCSS + config.paths.css.admin.name)
		.pipe(gcmq())
		.pipe(cleanCss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssDatePickerMin = () => {
	return gulp
		.src(config.paths.staticCSS + 'datepicker.css')
		.pipe(cleanCss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssCalendarMin = () => {
	return gulp
		.src(config.paths.staticCSS + 'calendar.css')
		.pipe(cleanCss())
		.pipe(rename({ suffix: '.min' }))
		.pipe(eol('\n', true))
		.pipe(gulp.dest(config.paths.staticCSS));
};

const cssBuild = gulp.parallel(cssBootBuild, cssMainBuild, cssAdminBuild, cssCalendarBuild, cssDatePickerBuild);
const cssMin = gulp.parallel(cssBootMin, cssMainMin, cssAdminMin, cssCalendarMin, cssDatePickerMin);

const cssLint = (done) => done();

const cssState1 = gulp.series(cssLint);
const cssState2 = gulp.series(cssClean, cssBuild, cssMin);

exports.cssLint = cssLint;
exports.cssBuild = cssBuild;

exports.css = gulp.parallel(cssState1, cssState2);
