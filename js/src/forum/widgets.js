import app from 'flarum/forum/app';
import Avatar from 'flarum/common/components/Avatar';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { t } from './util';
import { fofWidget } from '../common/widget';

/**
 * "Recently added" for the widget hosts a forum may run: Bespoke and
 * fof/forum-widgets-core. Neither is a build dependency; each is found at run
 * time, so the bundle is the same with both, one or none installed. The list
 * is fetched only when a widget is actually on the page.
 *
 * Offered only once an admin turns it on in Curio's settings: FoF Forum
 * Widgets puts every widget it is given on the page straight away, so
 * offering it unasked put a box on forums that never wanted one.
 */
class RecentList {
  oninit(vnode) {
    this.items = null;
    const limit = Math.max(1, Math.min(12, Number(vnode.attrs.limit) || 6));
    app.store
      .find('curio-items', { filter: { status: 'owned' }, include: 'collection.user', sort: '-createdAt', page: { limit } })
      .then((items) => (this.items = items))
      .catch(() => (this.items = []))
      .finally(() => m.redraw());
  }

  view() {
    if (this.items === null) return <LoadingIndicator size="small" display="block" />;
    if (!this.items.length) return <p className="CurioWidget-empty">{t('widget.empty')}</p>;

    return (
      <ul className="CurioWidget-list">
        {this.items.map((item) => {
          const collection = item.collection();
          const user = collection?.user();
          return (
            <li>
              <a href={collection ? app.route('curio.collection', { id: `${collection.id()}-${collection.slug()}` }) + `?item=${item.id()}` : '#'}>
                {item.coverUrl() ? <img src={item.coverUrl()} alt="" loading="lazy" /> : <span className="CurioWidget-blank" />}
                <span>
                  <strong>{item.displayTitle()}</strong>
                  {user ? (
                    <small>
                      <Avatar user={user} /> {user.displayName()}
                    </small>
                  ) : null}
                </span>
              </a>
            </li>
          );
        })}
      </ul>
    );
  }
}

export default function registerWidgets() {
  const flarum = globalThis.flarum;
  // Initializers run before app.forum exists, so the switch is read from the preloaded forum document.
  if (!flarum || !flarum.extensions || !app.data.resources?.[0]?.attributes?.curioWidget) return;

  if ('ernestdefoe-bespoke' in flarum.extensions) {
    globalThis.BespokeWidgetQueue = globalThis.BespokeWidgetQueue || [];
    globalThis.BespokeWidgetQueue.push({
      type: 'curio-recent',
      label: 'ernestdefoe-curio.forum.widget.label',
      icon: 'fas fa-boxes-stacked',
      zones: ['sidebar', 'above-list', 'below-list', 'footer'],
      schema: [
        { key: 'title', type: 'text', label: 'ernestdefoe-curio.forum.widget.field_title', default: '' },
        { key: 'limit', type: 'number', label: 'ernestdefoe-curio.forum.widget.field_limit', default: 6 },
      ],
      component: {
        view(vnode) {
          const s = vnode.attrs.settings || {};
          return (
            <div className="CurioWidget">
              <h4 className="CurioWidget-title">{s.title || t('widget.label')}</h4>
              <RecentList limit={s.limit} />
            </div>
          );
        },
      },
    });
  }

  if ('fof-forum-widgets-core' in flarum.extensions) {
    const widgetsMod = flarum.reg?.get?.('fof-forum-widgets-core', 'common/extend/Widgets');
    const widgetMod = flarum.reg?.get?.('fof-forum-widgets-core', 'common/components/Widget');
    const Widgets = widgetsMod?.default ?? widgetsMod;
    const WidgetBase = widgetMod?.default ?? widgetMod;
    if (!Widgets || !WidgetBase) return;

    class CurioRecentWidget extends WidgetBase {
      className() {
        return 'CurioWidget CurioWidget--fof';
      }

      icon() {
        return 'fas fa-boxes-stacked';
      }

      title() {
        return t('widget.label');
      }

      content() {
        return <RecentList limit={6} />;
      }
    }

    new Widgets().add(fofWidget(CurioRecentWidget)).extend(app, 'ernestdefoe-curio');
  }
}
