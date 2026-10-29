import app from 'flarum/forum/app';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import extractText from 'flarum/common/utils/extractText';

/**
 * "Show it off": open the composer already titled and linked to one of your
 * items. The composer loads lazily and starts empty, so the link is applied
 * when it is created (applyShowOff), before its tag picker opens.
 */
let pending = null;

export function startShowOff(item) {
  app.modal.close();

  // A title Marquee knows, on a forum with a tag for its kind: Marquee's own
  // start, so the discussion is tagged, titled and opens with the title's card.
  // It is the title's discussion, not this copy's: no item is attached (the
  // item finds the discussion through its title), so no second card waits
  // behind Marquee's and the composer shows no "You're showing off" line.
  const title = item.marquee();
  const marqueeTags = app.forum.attribute('marqueeTags') || {};
  const mod = title && Object.values(marqueeTags).includes(title.kind) && flarum.reg?.get?.('ernestdefoe-marquee', 'forum/utils/startDiscussion');
  if (mod?.startDiscussion) {
    const { kind, externalId, title: name, year, artist } = title;
    return mod
      .startDiscussion({ id: null, kind, externalId, title: name, year, poster: null, artist, seasons: null, season: null, episode: null })
      .catch(() => {});
  }

  pending = { id: item.id(), title: item.displayTitle(), cover: item.coverUrl() };
  return IndexSidebar.prototype.newDiscussionAction.call({}).catch(() => (pending = null));
}

export function applyShowOff(composer) {
  if (!pending) return;
  composer.fields.curioItem = pending;
  if (!composer.fields.title())
    composer.fields.title(extractText(app.translator.trans('ernestdefoe-curio.forum.showoff.title', { title: pending.title })));
  pending = null;
}
