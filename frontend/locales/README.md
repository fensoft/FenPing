# Locale catalogs

FenPing translations use flat UTF-8 JSON catalogs named with their locale identifier, such as `en.json`, `fr.json`, and `zh-CN.json`.

- `en.json` is the canonical catalog and fallback language.
- Every locale file must contain exactly the same message keys as `en.json`.
- Values must be non-empty strings.
- Named placeholders use `{name}` syntax and must match the placeholders in the English value.
- Values are plain text, not HTML.
- Add a catalog import and an entry in `supportedLocales` in `frontend/lib/i18n.js` when registering a language.
- Set the locale's `direction` to `rtl` when its interface reads right to left.

`npm test` checks catalog key parity and placeholder consistency.
