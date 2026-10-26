/**
 * Admin-made labels are kept per language ({en: "Platform", fr: "Plateforme"}).
 * The reader's language wins, then its base language, then English, then any.
 */
export function pick(labels, locale) {
  if (!labels || typeof labels !== 'object') return labels || '';
  const base = (locale || 'en').split(/[-_]/)[0];
  return labels[locale] || labels[base] || labels.en || Object.values(labels)[0] || '';
}
