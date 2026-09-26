const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '../../../..');
const files = [
  path.join(root, 'wp-content/themes/pgevents-pro/page-create-event.php'),
  path.join(__dirname, '../templates/dashboard-create.php'),
];
let passed = 0;
function check(label, condition) {
  if (!condition) throw new Error(`FAIL: ${label}`);
  passed += 1;
  process.stdout.write(`PASS: ${label}\n`);
}

for (const file of files) {
  const source = fs.readFileSync(file, 'utf8');
  const label = path.basename(file);
  check(`${label} sends operation ID`, source.includes("formData.append('event_creation_operation_id', currentEventOperationId())"));
  const successAt = source.indexOf('window.sessionStorage.removeItem(eventOperationStorageKey)');
  const failureAt = source.indexOf('catch');
  check(`${label} clears only in success branch`, successAt > 0 && (failureAt < 0 || successAt < failureAt));
  check(`${label} does not clear in finally`, !/finally\s*\{[^}]*removeItem/s.test(source));

  const makeUuid = source.match(/function createEventOperationUuid\(\) \{[\s\S]*?\n    \}/);
  const currentUuid = source.match(/function currentEventOperationId\(\) \{[\s\S]*?\n    \}/);
  check(`${label} operation functions are present`, Boolean(makeUuid && currentUuid));
  const values = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'];
  const storage = new Map();
  const context = {
    eventOperationStorageKey: 'pge_event_creation_operation:7:cycle-a',
    Uint8Array,
    Array,
    window: {
      sessionStorage: {
        getItem: key => storage.get(key) || null,
        setItem: (key, value) => storage.set(key, value),
        removeItem: key => storage.delete(key),
      },
      crypto: { randomUUID: () => values.shift() },
    },
  };
  vm.createContext(context);
  vm.runInContext(`${makeUuid[0]}\n${currentUuid[0]}`, context);
  const first = vm.runInContext('currentEventOperationId()', context);
  const retry = vm.runInContext('currentEventOperationId()', context);
  check(`${label} retry/reload keeps UUID`, first === retry);
  context.window.sessionStorage.removeItem(context.eventOperationStorageKey);
  const next = vm.runInContext('currentEventOperationId()', context);
  check(`${label} next successful operation gets new UUID`, next !== first);
}

process.stdout.write(`\n${passed}/${passed} passed\n`);
