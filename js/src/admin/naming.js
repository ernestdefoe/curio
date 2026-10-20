// Names and slugs for copied and imported types (admin only, kept out of the forum bundle).
/**
 * The next free numbered name for a copy: "Games" and "Games 2" both give
 * "Games 3" when "Games 2" is taken, never "Games 2 2".
 */
export function nextName(name, taken) {
  if (!name || !name.trim()) return name;
  const stem = name.replace(/\s+\d+$/, '') || name;
  const used = new Set(taken);
  let n = 2;
  while (used.has(`${stem} ${n}`)) n++;
  return `${stem} ${n}`;
}

/** A slug not in use yet: "games", then "games-2", "games-3"… */
export function freeSlug(base, taken) {
  const used = new Set(taken);
  if (!used.has(base)) return base;
  const stem = base.replace(/-\d+$/, '') || base;
  let n = 2;
  while (used.has(`${stem}-${n}`)) n++;
  return `${stem}-${n}`;
}
