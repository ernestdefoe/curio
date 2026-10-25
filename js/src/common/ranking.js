/** The Collections page's lists below the latest activity. Pure, so they are tested without a browser. */

/**
 * Which lists the Collections page shows, in order: the featured collections
 * when any are featured, then what the admin chose to rank ("collections",
 * "collectors" or "both"; anything else = collections, the default).
 * Featuring a collection adds the featured list; it never takes the place of
 * a ranking (until 1.14.2, "Top collections" only stood in while nothing was
 * featured, so featuring one made it vanish).
 */
export function browseSections(ranking, featuredCount) {
  const sections = featuredCount > 0 ? ['featured'] : [];
  if (ranking !== 'collectors') sections.push('top');
  if (ranking === 'collectors' || ranking === 'both') sections.push('collectors');
  return sections;
}
