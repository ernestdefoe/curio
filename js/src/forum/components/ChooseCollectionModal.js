import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { t } from '../util';
import { typeBadge } from './bits';
import useStyles from '../styles';

/**
 * Which of your collections should it go in? Grouped by type: the types it
 * came from first, then types looked up the same way (another console's
 * games), then the rest. "New collection" starts one of the first type.
 * `collections` is the list, or the promise of it: the window opens at once
 * and fills in when they arrive.
 */
export default class ChooseCollectionModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    useStyles();
    this.collections = Array.isArray(this.attrs.collections) ? this.attrs.collections : null;
    if (!this.collections) {
      Promise.resolve(this.attrs.collections)
        .then(
          (list) => (this.collections = list),
          () => (this.collections = [])
        )
        .finally(() => m.redraw());
    }
  }

  className() {
    return 'CurioChooseModal Modal--small';
  }

  title() {
    return this.attrs.title || t('item.choose_collection');
  }

  groups() {
    const wanted = this.attrs.typeIds || [];
    // Looked up the same way as the types it came from (an Xbox game and a PlayStation shelf both use the games look-up).
    const sources = new Set(wanted.map((id) => app.store.getById('curio-types', id)?.source()).filter((source) => source && source !== 'none'));
    const groups = new Map();
    this.collections.forEach((c) => {
      const type = c.type();
      const key = type ? type.id() : '';
      if (!groups.has(key)) groups.set(key, { type, collections: [] });
      groups.get(key).collections.push(c);
    });
    const rank = ({ type }) => {
      if (!type) return 3;
      const i = wanted.indexOf(type.id());
      return i >= 0 ? i / 100 : sources.has(type.source()) ? 1 : 2;
    };

    return [...groups.values()].sort((a, b) => rank(a) - rank(b) || (a.type?.position() || 0) - (b.type?.position() || 0));
  }

  content() {
    if (!this.collections)
      return (
        <div className="Modal-body">
          <LoadingIndicator />
        </div>
      );

    const groups = this.groups();

    return (
      <div className="Modal-body">
        {groups.length ? null : <p className="CurioChooseList-empty">{t('item.choose_none')}</p>}
        {groups.map(({ type, collections }) => (
          <section className="CurioChooseList-group">
            {type ? <h4 className="CurioChooseList-type">{typeBadge(type)}</h4> : null}
            <ul className="CurioChooseList">
              {collections.map((c) => (
                <li>
                  <Button className="Button CurioChooseList-item" onclick={() => this.attrs.onchoose(c)}>
                    <span>{c.name()}</span>
                    <span className="CurioMuted">{t('count.items', { count: c.ownedCount() || 0 })}</span>
                  </Button>
                </li>
              ))}
            </ul>
          </section>
        ))}
        <Button
          className={'Button CurioChooseList-new' + (groups.length ? ' Button--link' : ' Button--primary')}
          icon="fas fa-plus"
          onclick={() => this.attrs.onnew()}
        >
          {t('item.choose_new')}
        </Button>
      </div>
    );
  }
}
