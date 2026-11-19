/** Ratings are stored as half stars: 1 = ½★ … 10 = ★★★★★. Pure, so they are tested without a browser. */

/** The rating under a pointer `x` pixels into a `width`-pixel row of five stars. */
export function ratingAt(x, width) {
  if (!(width > 0)) return 0;
  return Math.min(10, Math.max(1, Math.ceil((x / width) * 10)));
}

/** "4.5" for 9, "4" for 8. */
export function starsLabel(rating) {
  return String(rating / 2);
}
