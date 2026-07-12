import assert from 'node:assert/strict';
import test from 'node:test';
import {
  detectBrowserLocale,
  detectLocale,
  detectLocalePreference,
  locale,
  localeLabel,
  messageCatalogs,
  localePreference,
  localeShort,
  normalizeLocale,
  normalizeLocalePreference,
  setLocale,
  t
} from '../frontend/lib/i18n.js';

function placeholders(message) {
  return [...String(message).matchAll(/\{([A-Za-z0-9_]+)\}/g)].map((match) => match[1]).sort();
}

test('normalizes supported locales and falls back to English', () => {
  assert.equal(normalizeLocale('en'), 'en');
  assert.equal(normalizeLocale('fr'), 'fr');
  assert.equal(normalizeLocale('zh-CN'), 'zh-CN');
  assert.equal(normalizeLocale('fr-FR'), 'en');
  assert.equal(normalizeLocale(null), 'en');
});

test('locale JSON catalogs have identical keys and placeholders', () => {
  const englishKeys = Object.keys(messageCatalogs.en).sort();
  assert.ok(englishKeys.length > 0);
  for (const [localeId, catalog] of Object.entries(messageCatalogs)) {
    assert.deepEqual(Object.keys(catalog).sort(), englishKeys, `${localeId} keys`);
    for (const key of englishKeys) {
      assert.equal(typeof catalog[key], 'string', `${localeId}.${key} type`);
      assert.notEqual(catalog[key], '', `${localeId}.${key} value`);
      assert.deepEqual(placeholders(catalog[key]), placeholders(messageCatalogs.en[key]), `${localeId}.${key} placeholders`);
    }
  }
});

test('normalizes locale preferences independently from effective locales', () => {
  assert.equal(normalizeLocalePreference('auto'), 'auto');
  assert.equal(normalizeLocalePreference('en'), 'en');
  assert.equal(normalizeLocalePreference('fr'), 'fr');
  assert.equal(normalizeLocalePreference('zh-CN'), 'zh-CN');
  assert.equal(normalizeLocalePreference('xx'), 'auto');
  assert.equal(detectLocalePreference({ getItem: () => null }), 'auto');
  assert.equal(detectLocalePreference({ getItem: () => 'fr' }), 'fr');
});

test('detects a saved locale before resolving Auto from the browser', () => {
  const frenchBrowser = { language: 'fr-FR' };
  assert.equal(detectLocale({ getItem: () => 'en' }, frenchBrowser), 'en');
  assert.equal(detectLocale({ getItem: () => 'fr' }, { language: 'en-US' }), 'fr');
  assert.equal(detectLocale({ getItem: () => 'auto' }, frenchBrowser), 'fr');
  assert.equal(detectLocale({ getItem: () => null }, frenchBrowser), 'fr');
  assert.equal(detectLocale({ getItem: () => 'xx' }, { language: 'de-DE' }), 'de');
  assert.equal(detectLocale({ getItem: () => { throw new Error('blocked'); } }, frenchBrowser), 'fr');
  assert.equal(detectBrowserLocale({ languages: ['fr-CA'], language: 'en-US' }), 'fr');
  assert.equal(detectBrowserLocale({ languages: ['zh-Hans-CN'], language: 'en-US' }), 'zh-CN');
  assert.equal(detectBrowserLocale({ languages: ['es-MX'], language: 'en-US' }), 'es');
  assert.equal(detectBrowserLocale({ languages: ['pt-PT'], language: 'en-US' }), 'pt-BR');
  assert.equal(detectBrowserLocale({ languages: ['ar-EG'], language: 'en-US' }), 'ar');
});

test('translates, interpolates, and falls back to English source text', () => {
  setLocale('en');
  assert.equal(t('Inventory'), 'Inventory');
  assert.equal(t('{count} changes', { count: 3 }), '3 changes');

  setLocale('fr');
  assert.equal(t('Inventory'), 'Inventaire');
  assert.equal(t('Down'), 'Éteint');
  assert.equal(t('Netboot'), 'Netboot');
  assert.equal(t('Netboot images'), 'Images Netboot');
  assert.equal(t('{count} changes', { count: 3 }), '3 modifications');
  assert.equal(t('Untranslated source'), 'Untranslated source');
  assert.equal(localeLabel(), 'Français');
  assert.equal(localeShort(), 'FR');

  setLocale('en');
  assert.equal(locale.value, 'en');
  assert.equal(localeLabel(), 'English');
  assert.equal(localeShort(), 'EN');

  setLocale('zh-CN');
  assert.equal(t('Inventory'), '设备清单');
  assert.equal(t('{count} services', { count: 3 }), '3 个服务');
});

test('persists the locale and updates the document language when available', () => {
  const originalStorage = globalThis.localStorage;
  const originalDocument = globalThis.document;
  const saved = new Map();
  globalThis.localStorage = { setItem: (key, value) => saved.set(key, value) };
  globalThis.document = { documentElement: { lang: '' } };

  try {
    setLocale('fr');
    assert.equal(saved.get('fenping_locale'), 'fr');
    assert.equal(globalThis.document.documentElement.lang, 'fr');
    assert.equal(globalThis.document.documentElement.dir, 'ltr');
    setLocale('ar');
    assert.equal(globalThis.document.documentElement.lang, 'ar');
    assert.equal(globalThis.document.documentElement.dir, 'rtl');
    setLocale('auto');
    assert.equal(localePreference.value, 'auto');
    assert.equal(saved.get('fenping_locale'), 'auto');
  } finally {
    setLocale('en');
    if (originalStorage === undefined) delete globalThis.localStorage;
    else globalThis.localStorage = originalStorage;
    if (originalDocument === undefined) delete globalThis.document;
    else globalThis.document = originalDocument;
  }
});
