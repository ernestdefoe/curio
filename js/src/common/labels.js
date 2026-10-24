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

/**
 * A member's web address, only when it is plain http(s): a detail switched
 * to Link after holding text may keep any old value, so nothing else becomes an href.
 */
export function safeUrl(value) {
  try {
    const url = new URL(String(value));
    return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url : null;
  } catch (e) {
    return null;
  }
}

/** A member's link: a new tab, never followed by search engines, no opener. */
export function linkTo(value, text) {
  const url = safeUrl(value);
  if (!url) return String(value);
  return m('a', { href: url.href, target: '_blank', rel: 'nofollow ugc noopener noreferrer' }, text || url.hostname.replace(/^www\./, ''));
}

/** A field's value for display ("Yes" for a checked box is the caller's word). */
export function fieldValue(field, value, locale) {
  if (value === undefined || value === null || value === '') return null;
  if (field.kind === 'select') return optionLabel(field, value, locale);
  if (field.kind === 'link') return linkTo(value);
  return String(value);
}

/** The type's link detail marked as the member's review, if any. */
export function reviewField(fields) {
  return (fields || []).find((f) => f.kind === 'link' && f.review) || null;
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
