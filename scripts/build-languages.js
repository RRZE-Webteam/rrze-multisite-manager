/* eslint-disable no-console */
'use strict';

var childProcess = require('child_process');
var path = require('path');

var pluginRoot = process.cwd();
var textdomain = 'rrze-multisite-manager';
var languagesDir = path.join(pluginRoot, 'languages');
var potFile = path.join(languagesDir, textdomain + '.pot');
var locales = ['de_DE', 'de_DE_formal'];

function run(command, args) {
    var result = childProcess.spawnSync(command, args, {
        cwd: pluginRoot,
        stdio: 'inherit'
    });

    if (result.error) {
        throw result.error;
    }

    if (result.status !== 0) {
        throw new Error(command + ' failed with exit code ' + String(result.status));
    }
}

run('wp', [
    'i18n',
    'make-pot',
    '.',
    potFile,
    '--domain=' + textdomain,
    '--exclude=node_modules,build,.git'
]);

// Restore the package-driven POT metadata after WP-CLI generated the catalog.
run(process.execPath, [path.join(pluginRoot, 'scripts', 'build-version.js'), 'sync']);

locales.forEach(function updateCatalog(locale) {
    var poFile = path.join(languagesDir, textdomain + '-' + locale + '.po');
    var moFile = path.join(languagesDir, textdomain + '-' + locale + '.mo');

    run('msgmerge', ['--update', '--backup=none', poFile, potFile]);
    run('msgfmt', ['--check', '--output-file=' + moFile, poFile]);
});

console.log('Language catalogs updated and compiled.');
