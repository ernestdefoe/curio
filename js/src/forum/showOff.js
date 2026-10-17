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
  pending = { id: item.id(), title: item.displayTitle(), cover: item.coverUrl() };
  app.modal.close();
  return IndexSidebar.prototype.newDiscussionAction.call({}).catch(() => (pending = null));
}

export function applyShowOff(composer) {
  if (!pending) return;
  composer.fields.curioItem = pending;
  if (!composer.fields.title())
    composer.fields.title(extractText(app.translator.trans('ernestdefoe-curio.forum.showoff.title', { title: pending.title })));
  pending = null;
}
