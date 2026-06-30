/* Tachyon Webmail (c) Tachyon | Licensed under AGPL v3 */
const del = require('del');

const { config } = require('./config');

exports.del = (dir) => del(dir);

exports.cleanStatic = () => del(config.paths.staticJS) && del(config.paths.staticCSS);
