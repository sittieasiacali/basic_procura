import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const template = readFileSync(new URL('../../resources/views/admin/accounts/partials/create-modal.blade.php', import.meta.url), 'utf8');
const start = template.indexOf('    const syncRole = () => {');
const end = template.indexOf('    roles.forEach(', start);
assert.ok(start >= 0 && end > start);
const roles = ['tl', 'om', 'smt', 'purchasing'].map(value => ({ value, checked: false }));
const inputs = [{ required: false }, { required: false }];
const fields = { hidden: false, disabled: false, querySelectorAll: () => inputs };
const campaign = { required: false };
const sync = vm.runInNewContext(template.slice(start, end) + '\nsyncRole;', {
    roles, requestorRoleIds: ['tl'], campaignRoleIds: ['tl', 'om', 'smt'],
    requestorFields: fields, modal: { querySelector: () => campaign },
});
function select(selected, recipients, campaignRequired) {
    roles.forEach(role => { role.checked = selected.includes(role.value); });
    sync();
    assert.equal(fields.hidden, !recipients);
    assert.equal(fields.disabled, !recipients);
    assert.ok(inputs.every(input => input.required === recipients));
    assert.equal(campaign.required, campaignRequired);
}
select(['tl'], true, true);
select(['om'], false, true);
select(['smt'], false, true);
select(['om', 'smt'], false, true);
select(['om', 'tl'], true, true);
select(['om'], false, true);
select(['purchasing'], false, false);
select([], false, false);
select(['tl'], true, true);
console.log('Account role fields passed: TL/OM/SMT, multiple roles, removing TL, empty selection and campaign requirements.');