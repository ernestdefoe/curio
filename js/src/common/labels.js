/**
 * Admin-made labels are kept per language ({en: "Platform", fr: "Plateforme"}).
 * The reader's language wins, then its base language, then English, then any.
 */
export function pick(labels, locale) {
  if (!labels || typeof labels !== 'object') return labels || '';
  const base = (locale || 'en').split(/[-_]/)[0];
  return labels[locale] || labels[base] || labels.en || Object.values(labels)[0] || '';
}

/** The label of a select field's option, by key. */
export function optionLabel(field, key, locale) {
  const option = (field.options || []).find((o) => o.key === key);
  return option ? pick(option.labels, locale) : key;
}

/** A field's value as text for display ("Yes" for a checked box is the caller's word). */
export function fieldValue(field, value, locale) {
  if (value === undefined || value === null || value === '') return null;
  if (field.kind === 'select') return optionLabel(field, value, locale);
  return String(value);
}

/**
 * The one option of a choice field that a looked-up list names (a game's
 * platforms: "PlayStation 5", "PC (Microsoft Windows)"), or null when none or
 * several match: a member's copy is on one platform, so a guess is worse than nothing.
 */
export function onlyOption(field, names) {
  const plain = (s) =>
    String(s)
      .toLowerCase()
      .replace(/\(.*?\)/g, '')
      .replace(/[^\p{L}\p{N}]+/gu, ' ')
      .trim();
  const wanted = new Set((names || []).map(plain));
  const found = (field.options || []).filter((o) => Object.values(o.labels || {}).some((l) => wanted.has(plain(l))));
  return found.length === 1 ? found[0].key : null;
}
