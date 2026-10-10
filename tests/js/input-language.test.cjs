const assert = require('node:assert/strict');
const test = require('node:test');
const fs = require('node:fs');
const vm = require('node:vm');
const sandbox = { module: { exports: {} } };
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../../assets/js/input-language.js'), 'utf8'), sandbox);
const normalize = sandbox.module.exports;

test('Persian names normalize Arabic characters and reject Latin text', () => {
    assert.equal(normalize('علي كاظمي John', 'fa'), 'علی کاظمی ');
    assert.equal(normalize('محمدرضا‌ حسینی', 'fa'), 'محمدرضا‌ حسینی');
});
test('English names preserve spaces and name punctuation and reject Persian', () => {
    assert.equal(normalize("John O’Neil-Smith علی", 'en'), "John O’Neil-Smith ");
});
