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
