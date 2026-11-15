/*
 * Run from the repository root in PowerShell with Node/npm and PHP on PATH
 * and Composer dependencies installed. Install JS test dependencies outside
 * the repository; preserve the session's existing NODE_PATH afterward:
 *
 * $weighTestDir = Join-Path ([System.IO.Path]::GetTempPath()) ('irimao-weigh-tests-' + [guid]::NewGuid().ToString('N'))
 * New-Item -ItemType Directory -Path $weighTestDir | Out-Null
 * npm install --prefix $weighTestDir --no-audit --no-fund jquery@3.7.1 jsdom@26.1.0
 * if ($LASTEXITCODE -ne 0) { throw 'Test dependency installation failed' }
 * $weighPreviousNodePath = $env:NODE_PATH
 * try {
 *     $env:NODE_PATH = Join-Path $weighTestDir 'node_modules'
 *     node --test tests/js/weigh-in-ajax.test.cjs
 *     if ($LASTEXITCODE -ne 0) { throw 'Weigh-in tests failed' }
 * } finally {
 *     $env:NODE_PATH = $weighPreviousNodePath
 * }
 *
 * The temporary dependency directory is retained for reuse. No root package
 * manifest or lockfile is created or changed.
 */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');
const jquery = require('jquery');

const root = path.resolve(__dirname, '../..');
const page = JSON.parse(execFileSync('php', [path.join(root, 'tests/fixtures/competition-admin.php'), 'weigh-page'], { encoding: 'utf8' }));
const library = fs.readFileSync(path.join(root, 'assets/js/jquery.dataTables.min.js'), 'utf8');

async function requestFor(reservedAttribute) {
    const dom = new JSDOM(page.html, { url: 'https://fixture.invalid/admin.php', runScripts: 'outside-only' });
    const { window } = dom;
    const $ = jquery(window);
    window.jQuery = window.$ = $;
    window.alert = (message) => { throw new Error(message); };
    window.eval(library);
    if (reservedAttribute) $('#imao-weigh-in-table').attr('data-ajax', '/admin-ajax.php');
    let timer;
    try {
        return await new Promise((resolve, reject) => {
            timer = setTimeout(() => reject(new Error('No DataTables request')), 3000);
            $.ajax = (options) => {
                if (options.url.endsWith('/fa.json')) {
                    options.success({});
                } else {
                    resolve(options);
                }
                return { readyState: 4, abort() {} };
            };
            window.eval(page.script);
        });
    } finally {
        clearTimeout(timer);
        window.close();
    }
}

test('rendered weigh-in table preserves POST, action, competition and nonce with bundled DataTables', async () => {
    const request = await requestFor(false);
    assert.equal(request.url, '/admin-ajax.php');
    assert.equal(request.type, 'POST');
    assert.equal(request.data.action, 'imao_weigh_ins_datatable');
    assert.equal(request.data.competition_id, 13800);
    assert.equal(request.data.nonce, 'fixture-nonce');
    assert.equal(request.data.length, 25);
});

test('reserved data-ajax reproduces the MonthlyClassReports override and validates the regression test', async () => {
    const request = await requestFor(true);
    assert.equal(request.type, 'GET');
    assert.equal(request.data.action, undefined);
});

test('handler excludes refunds and preserves pagination, search and off-page values', () => {
    const output = execFileSync('php', [path.join(root, 'tests/fixtures/competition-admin.php')], { encoding: 'utf8' });
    assert.match(output, /11 checks passed/);
});
