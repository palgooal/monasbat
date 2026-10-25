// Run: node tests/test-catalog-tiers-quota-init.mjs
// Execute the production inline script with a small DOM/event double. No browser
// or server is started. Simulates the edit form arriving after the script.
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source = fs.readFileSync(new URL('../pgevents-core.php', import.meta.url), 'utf8');
const callback = source.slice(source.indexOf('function pge_render_catalog_tiers_page('));
const script = [...callback.matchAll(/<script>([\s\S]*?)<\/script>/g)].find(m => m[1].includes('syncEventQuotaFieldset'))?.[1];
assert.ok(script, 'production script found');
let checks = 0;
const check = (actual, expected) => { assert.equal(actual, expected); checks++; };
function fieldset(id, unlimited, value) {
    const target = { disabled: false, value };
    const radios = [{ checked: !unlimited, events: {} }, { checked: unlimited, events: {} }];
    for (const radio of radios) radio.addEventListener = (event, handler) => { radio.events[event] = handler; };
    return { id, target, radios, getAttribute: () => id, querySelector: () => radios[1], querySelectorAll: () => radios };
}
for (const readyState of ['loading', 'complete']) {
    for (const editUnlimited of [false, true]) {
        const add = fieldset('pge_tier_event_quota_limit', false, '1');
        const edit = fieldset('pge_edit_tier_event_quota_limit', editUnlimited, '7');
        let fields = readyState === 'loading' ? [add] : [add, edit];
        const events = {};
        const document = { readyState, querySelectorAll: () => fields, getElementById: id => fields.find(f => f.id === id)?.target, addEventListener: (event, handler) => { events[event] = handler; } };
        vm.runInNewContext(script, { document });
        if (readyState === 'loading') {
            fields = [add, edit]; document.readyState = 'interactive';
            assert.ok(events.DOMContentLoaded, 'waits for late edit form'); checks++;
            events.DOMContentLoaded();
        }
        check(add.target.disabled, false); check(edit.target.disabled, editUnlimited);
        for (const [current, other] of [[add, edit], [edit, add]]) {
            const otherState = other.target.disabled;
            for (const unlimited of [true, false, true, false]) {
                current.radios[0].checked = !unlimited; current.radios[1].checked = unlimited;
                current.radios[unlimited ? 1 : 0].events.change();
                check(current.target.disabled, unlimited); check(other.target.disabled, otherState);
            }
        }
        check(add.target.value, '1'); check(edit.target.value, '7');
    }
}
console.log(`PASS: ${checks}/${checks} assertions; 4 DOM initialization scenarios`);
