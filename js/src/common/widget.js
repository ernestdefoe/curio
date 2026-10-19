/**
 * The FoF Forum Widgets entry, shared with the admin side (which must register
 * it too, or it never appears in FoF's layout editor and cannot be moved or
 * dragged back out). Its first place is the right-hand column; the admin
 * moves it from there.
 */
export const fofWidget = (component) => ({ key: 'curioRecent', component, isDisabled: false, isUnique: true, placement: 'end', position: 0 });
