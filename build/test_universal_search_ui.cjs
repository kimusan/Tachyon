const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('dev/View/Popup/AdvancedSearch.js', 'utf8')
    .replace(/^import .*;\n/gm, '').replace('export class ', 'class ')
    + '\nthis.Popup = AdvancedSearchPopupView;';
const enabled = new Set(['AccountSearch', 'SubtreeSearch']);
const context = vm.createContext({
    URLSearchParams, FormData, Date,
    AbstractViewPopup: class {},
    addObservablesTo(target, values) {
        for (const [key, initial] of Object.entries(values)) {
            let value = initial;
            target[key] = function(next) { if (arguments.length) value = next; return value; };
        }
    },
    addComputablesTo: Object.assign,
    i18n: key => key,
    translateTrigger() {},
    pString: value => value || '',
    SettingsCapa: name => enabled.has(name),
    FolderUserStore: {hasCapability: () => false}
});
vm.runInContext(source, context);
const popup = new context.Popup();
assert.equal(popup.includeSpamTrash(), false);
assert.ok(popup.selectedTree().some(option => option.id === 'all'));
popup.selectedTreeValue('all');
popup.subject('invoice');
assert.equal(popup.buildSearchString(), 'subject=invoice&in=all');
popup.includeSpamTrash(true);
assert.equal(popup.buildSearchString(), 'subject=invoice&in=all&include-spam-trash');
popup.onShow('subject=receipt&in=all&include-spam-trash&attachment');
assert.equal(popup.selectedTreeValue(), 'all');
assert.equal(popup.includeSpamTrash(), true);
assert.equal(popup.hasAttachment(), true);
popup.selectedTreeValue('');
assert.ok(!popup.buildSearchString().includes('include-spam-trash'));
popup.onShow('subject=receipt&in=all');
assert.equal(popup.includeSpamTrash(), false);
enabled.delete('AccountSearch');
assert.ok(!popup.selectedTree().some(option => option.id === 'all'));
console.log('Universal search form checks passed.');
