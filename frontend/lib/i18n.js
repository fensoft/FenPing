import { ref } from 'vue';
import arabic from '../locales/ar.json' with { type: 'json' };
import german from '../locales/de.json' with { type: 'json' };
import english from '../locales/en.json' with { type: 'json' };
import spanish from '../locales/es.json' with { type: 'json' };
import french from '../locales/fr.json' with { type: 'json' };
import indonesian from '../locales/id.json' with { type: 'json' };
import japanese from '../locales/ja.json' with { type: 'json' };
import brazilianPortuguese from '../locales/pt-BR.json' with { type: 'json' };
import russian from '../locales/ru.json' with { type: 'json' };
import simplifiedChinese from '../locales/zh-CN.json' with { type: 'json' };

export const supportedLocales = Object.freeze([
  Object.freeze({ id: 'en', label: 'English', direction: 'ltr' }),
  Object.freeze({ id: 'zh-CN', label: '简体中文', direction: 'ltr' }),
  Object.freeze({ id: 'es', label: 'Español', direction: 'ltr' }),
  Object.freeze({ id: 'fr', label: 'Français', direction: 'ltr' }),
  Object.freeze({ id: 'ar', label: 'العربية', direction: 'rtl' }),
  Object.freeze({ id: 'pt-BR', label: 'Português', direction: 'ltr' }),
  Object.freeze({ id: 'id', label: 'Indonesia', direction: 'ltr' }),
  Object.freeze({ id: 'ja', label: '日本語', direction: 'ltr' }),
  Object.freeze({ id: 'ru', label: 'Русский', direction: 'ltr' }),
  Object.freeze({ id: 'de', label: 'Deutsch', direction: 'ltr' })
]);

export const messageCatalogs = Object.freeze({
  en: Object.freeze(english),
  es: Object.freeze(spanish),
  fr: Object.freeze(french),
  ar: Object.freeze(arabic),
  'pt-BR': Object.freeze(brazilianPortuguese),
  id: Object.freeze(indonesian),
  ja: Object.freeze(japanese),
  ru: Object.freeze(russian),
  de: Object.freeze(german),
  'zh-CN': Object.freeze(simplifiedChinese)
});

function applyDocumentLocale() {
  if (!globalThis.document?.documentElement) return;
  const selected = supportedLocales.find((item) => item.id === locale.value);
  globalThis.document.documentElement.lang = locale.value;
  globalThis.document.documentElement.dir = selected?.direction || 'ltr';
}

export function normalizeLocale(value) {
  return supportedLocales.some((item) => item.id === value) ? value : 'en';
}

export function normalizeLocalePreference(value) {
  return value === 'auto' || supportedLocales.some((item) => item.id === value) ? value : 'auto';
}

export function detectLocalePreference(storage) {
  try {
    return normalizeLocalePreference((storage === undefined ? globalThis.localStorage : storage)?.getItem('fenping_locale'));
  } catch {
    return 'auto';
  }
}

export function detectBrowserLocale(navigatorValue) {
  const browser = navigatorValue === undefined ? globalThis.navigator : navigatorValue;
  const requestedLocales = Array.isArray(browser?.languages) && browser.languages.length > 0
    ? browser.languages
    : [browser?.language || ''];
  for (const requested of requestedLocales) {
    const normalized = String(requested).toLowerCase().replace('_', '-');
    const exact = supportedLocales.find((item) => item.id.toLowerCase() === normalized);
    if (exact) return exact.id;
    const base = normalized.split('-')[0];
    const baseMatch = supportedLocales.find((item) => item.id.toLowerCase().split('-')[0] === base);
    if (baseMatch) return baseMatch.id;
  }
  return 'en';
}

export function detectLocale(storage, navigatorValue) {
  const preference = detectLocalePreference(storage);
  return preference === 'auto' ? detectBrowserLocale(navigatorValue) : preference;
}

export const localePreference = ref(detectLocalePreference());
export const locale = ref(localePreference.value === 'auto' ? detectBrowserLocale() : normalizeLocale(localePreference.value));

export function setLocale(value) {
  localePreference.value = normalizeLocalePreference(value);
  locale.value = localePreference.value === 'auto' ? detectBrowserLocale() : normalizeLocale(localePreference.value);
  try { globalThis.localStorage?.setItem('fenping_locale', localePreference.value); } catch {}
  applyDocumentLocale();
}

export function localeLabel(value = locale.value) {
  return supportedLocales.find((item) => item.id === value)?.label || 'English';
}

export function localeShort(value = locale.value) {
  return supportedLocales.find((item) => item.id === value)?.id.toUpperCase() || 'EN';
}

export function t(message, values = {}) {
  const template = messageCatalogs[locale.value]?.[message] ?? messageCatalogs.en[message] ?? message;
  return template.replace(/\{([A-Za-z0-9_]+)\}/g, (match, key) => values[key] === undefined ? match : String(values[key]));
}

applyDocumentLocale();
