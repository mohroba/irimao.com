const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('weekday groups validate independently and reset restores validity', () => {
    function group(checked) {
        const boxes = [false, checked].map(value => ({
            checked: value,
            message: '',
            setCustomValidity(message) { this.message = message; }
        }));
        const form = { addEventListener(event, handler) { this[event] = handler; } };
        return {
            boxes, form,
            querySelectorAll() { return boxes; },
            addEventListener(event, handler) { this[event] = handler; },
            closest() { return form; }
        };
    }
    const empty = group(false);
    const selected = group(true);
    vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../../assets/js/monthly-class-weekdays.js'), 'utf8'), {
        document: { querySelectorAll() { return [empty, selected]; } },
        setTimeout(handler) { handler(); }
    });
    assert.notEqual(empty.boxes[0].message, '');
    assert.equal(selected.boxes[0].message, '');
    empty.boxes[1].checked = true;
    empty.change();
    assert.equal(empty.boxes[0].message, '');
    empty.boxes[1].checked = false;
    empty.form.reset();
    assert.notEqual(empty.boxes[0].message, '');
    assert.equal(selected.boxes[0].message, '');
});
