/** Run: node tests/test-catalog-tiers-tabs.mjs */
import fs from 'node:fs';
import vm from 'node:vm';

let checks = 0;
function verify(condition, label) {
    checks += 1;
    if (!condition) throw new Error(label);
}

class FakeElement {
    constructor(attributes = {}) {
        this.attributes = { ...attributes };
        this.listeners = {};
        this.hidden = false;
        this.focused = false;
        this.classList = { values: new Set(), add: (value) => this.classList.values.add(value) };
    }
    getAttribute(name) { return this.attributes[name] ?? null; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    focus() { this.focused = true; }
    dispatch(name, event = {}) { this.listeners[name].call(this, event); }
}

function loadTabs(search = '') {
    const detailsTab = new FakeElement({ 'aria-controls': 'pge-tier-tabpanel-details', 'data-pge-tier-tab': 'details' });
    const featuresTab = new FakeElement({ 'aria-controls': 'pge-tier-tabpanel-features', 'data-pge-tier-tab': 'features' });
    const detailsPanel = new FakeElement();
    const featuresPanel = new FakeElement();
    const tabs = [detailsTab, featuresTab];
    const container = new FakeElement();
    container.querySelectorAll = (selector) => selector === '[role="tab"]' ? tabs : [];
    const panels = { 'pge-tier-tabpanel-details': detailsPanel, 'pge-tier-tabpanel-features': featuresPanel };
    const document = {
        readyState: 'complete',
        querySelectorAll: (selector) => selector === '.pge-catalog-tiers [data-pge-tier-tabs]' ? [container] : [],
        getElementById: (id) => panels[id] ?? null,
        addEventListener: () => { throw new Error('unexpected deferred init'); },
    };
    const history = { calls: [], replaceState(...args) { this.calls.push(args); } };
    const window = { location: { href: `https://example.test/wp-admin/admin.php?page=pge-catalog-tiers&action=edit${search}` }, history };
    vm.runInNewContext(fs.readFileSync(new URL('../assets/js/catalog-tiers-admin.js', import.meta.url), 'utf8'), { document, window, URL });
    return { tabs, panels, container, history };
}

const defaultTabs = loadTabs();
verify(defaultTabs.container.classList.values.has('pge-tier-tabs-ready'), 'tabs activate only after JavaScript initialization');
verify(defaultTabs.tabs[0].getAttribute('aria-selected') === 'true' && defaultTabs.tabs[0].getAttribute('tabindex') === '0', 'details tab is selected by default');
verify(defaultTabs.tabs[1].getAttribute('aria-selected') === 'false' && defaultTabs.tabs[1].getAttribute('tabindex') === '-1', 'features tab is inactive by default');
verify(defaultTabs.panels['pge-tier-tabpanel-details'].hidden === false && defaultTabs.panels['pge-tier-tabpanel-features'].hidden === true, 'only the active panel is visible after initialization');
verify(defaultTabs.history.calls.at(-1)[2].includes('tab=details'), 'default state is reflected in the URL without navigation');

defaultTabs.tabs[1].dispatch('click');
verify(defaultTabs.tabs[1].getAttribute('aria-selected') === 'true' && defaultTabs.tabs[0].getAttribute('aria-selected') === 'false', 'click selects features without a form submission');
verify(defaultTabs.panels['pge-tier-tabpanel-features'].hidden === false && defaultTabs.panels['pge-tier-tabpanel-details'].hidden === true, 'click swaps panel visibility without recreating panels');
verify(defaultTabs.history.calls.at(-1)[2].includes('tab=features'), 'feature selection is retained in the current URL');
verify(Object.keys(defaultTabs.tabs[0].listeners).sort().join(',') === 'click,keydown', 'tab control has no submit handler');

let prevented = false;
defaultTabs.tabs[1].dispatch('keydown', { key: 'ArrowLeft', preventDefault() { prevented = true; } });
verify(prevented && defaultTabs.tabs[0].focused, 'arrow key moves focus to the adjacent tab');
verify(defaultTabs.tabs[0].getAttribute('aria-selected') === 'true', 'arrow key activates the focused tab');
defaultTabs.tabs[0].dispatch('keydown', { key: 'End', preventDefault() {} });
verify(defaultTabs.tabs[1].focused && defaultTabs.tabs[1].getAttribute('aria-selected') === 'true', 'End key activates the final tab');
defaultTabs.tabs[1].dispatch('keydown', { key: 'Home', preventDefault() {} });
verify(defaultTabs.tabs[0].focused && defaultTabs.tabs[0].getAttribute('aria-selected') === 'true', 'Home key activates the first tab');

const featureTabs = loadTabs('&tab=features');
verify(featureTabs.tabs[1].getAttribute('aria-selected') === 'true', 'tab=features opens the features tab directly');
verify(featureTabs.panels['pge-tier-tabpanel-details'].hidden === true && featureTabs.panels['pge-tier-tabpanel-features'].hidden === false, 'direct feature link exposes only features after initialization');

console.log(`PASS: ${checks}/${checks} tab behavior and accessibility assertions`);
