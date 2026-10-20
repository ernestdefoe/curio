import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Select from 'flarum/common/components/Select';
import Tooltip from 'flarum/common/components/Tooltip';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import { t, text, label, collectionUrl, PROGRESS } from '../util';
import { optionLabel } from '../../common/labels';
import { typeBadge, statusPill, progressPill, owner, cover } from './bits';
import GridSort from '../../common/gridSort';
import ItemModal from './ItemModal';
import ItemEditModal from './ItemEditModal';
import CollectionEditModal from './CollectionEditModal';
import useStyles from '../styles';
import Stars from './Stars';

const PAGE = 48;
const FILTERS = ['all', 'owned', 'wishlist', 'trade'];
const SORTS = { position: 'position', recent: '-createdAt', title: 'title', year: '-year', rating: '-rating' };

/** One member's collection: a hero built from their own covers, then the shelf. */
export default class CollectionPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    useStyles();
    this.bodyClass = 'App--curio';
    this.collection = null;
    this.items = [];
    this.status = 'all';
    this.facet = '';
    this.progress = '';
    this.sort = 'position';
    this.q = '';
    this.view_ = 'grid';
    this.arranging = false;
    this.sorter = new GridSort(
      () => this.items,
      () => this.saveOrder()
    );

    const id = parseInt(m.route.param('id'), 10);
    app.store
      .find('curio-collections', String(id))
      .then((collection) => {
        this.collection = collection;
        app.setTitle(collection.name());
        app.history.push('curio.collection', collection.name());
        this.load(true);
        const open = m.route.param('item');
        if (open) this.open(open);
      })
      .catch(() => (this.missing = true))
      .finally(() => m.redraw());
  }

  load(reset) {
    if (reset) this.items = [];
    this.loading = true;
    const filter = { collection: this.collection.id() };
    if (this.status !== 'all') filter.status = this.status;
    if (this.facet) filter.facet = this.facet;
    if (this.progress) filter.progress = this.progress;
    if (this.q.trim()) filter.q = this.q.trim();

    return app.store
      .find('curio-items', { filter, sort: SORTS[this.sort], page: { offset: this.items.length, limit: this.arranging ? 96 : PAGE } })
      .then((items) => {
        this.items.push(...items);
        this.more = !!items.payload?.links?.next;
        this.loading = false;
        m.redraw();
        // Arranging moves everything, so everything is loaded.
        if (this.arranging && this.more) return this.load(false);
      });
  }

  refresh() {
    app.store.find('curio-collections', this.collection.id()).then(() => this.load(true));
  }

  open(itemId) {
    app.modal.show(ItemModal, { itemId: String(itemId), onchange: () => this.refresh() });
  }

  saveOrder() {
    this.collection.save({ order: this.items.map((item) => item.id()) });
  }

  view() {
    if (this.missing)
      return (
        <div className="container CurioPage">
          <Placeholder text={t('page.missing')} />
        </div>
      );
    if (!this.collection) return <LoadingIndicator display="block" />;

    const c = this.collection;
    const type = c.type();
    const facetField = (type?.fields() || []).find((f) => f.kind === 'select');
    const facets = c.facets() || {};

    return (
      <div className={'CurioPage CurioPage--' + (type ? type.shape() : 'portrait')} style={{ '--curio-accent': type?.color() }}>
        {this.hero(c, type, facetField, facets)}
        <div className="container CurioPage-body">
          {this.toolbar(c, facetField, facets)}
          {this.items.length ? (
            this.view_ === 'list' ? (
              this.list(type)
            ) : (
              this.grid(type, facetField)
            )
          ) : this.loading ? null : (
            <Placeholder text={t('page.empty')} />
          )}
          {this.loading ? <LoadingIndicator display="block" /> : null}
          {this.more && !this.loading && !this.arranging ? (
            <div className="CurioMoreRow">
              <Button className="Button" onclick={() => this.load(false)}>
                {t('page.load_more')}
              </Button>
            </div>
          ) : null}
        </div>
      </div>
    );
  }

  hero(c, type, facetField, facets) {
    const backdrop = c.preview() || [];
    const mine = c.canEdit();

    return (
      <header className="Hero CurioHero">
        <div className="CurioHero-backdrop" aria-hidden="true">
          {backdrop.length ? Array.from({ length: 12 }, (_, i) => <img src={backdrop[i % backdrop.length]} alt="" />) : null}
        </div>
        <div className="container CurioHero-inner">
          <div className="CurioHero-main">
            {typeBadge(type, 'CurioTypeBadge--solid')}
            <h1 className="CurioHero-title">{c.name()}</h1>
            <div className="CurioHero-meta">
              {owner(c.user())}
              <span>{t('page.since', { date: dayjs(c.createdAt()).format('MMMM YYYY') })}</span>
              {c.followerCount() ? <span>{t('page.followers', { count: c.followerCount() })}</span> : null}
              {c.visibility() !== 'public' ? (
                <span>
                  <Icon name={c.visibility() === 'private' ? 'fas fa-lock' : 'fas fa-user-group'} /> {t('visibility.' + c.visibility())}
                </span>
              ) : null}
            </div>
            {c.description() ? <p className="CurioHero-desc">{c.description()}</p> : null}
            {facetField && Object.keys(facets).length ? (
              <div className="CurioHero-breakdown">
                {Object.entries(facets).map(([key, count]) => (
                  <button
                    type="button"
                    className={'CurioChip' + (this.facet === key ? ' active' : '')}
                    onclick={() => this.filterFacet(this.facet === key ? '' : key)}
                  >
                    {optionLabel(facetField, key, app.translator.getLocale())} <b>{count}</b>
                  </button>
                ))}
              </div>
            ) : null}
          </div>
          <div className="CurioHero-side">
            <div className="CurioStats">
              <div>
                <b>{c.ownedCount()}</b>
                <span>{t('page.owned')}</span>
              </div>
              <div>
                <b>{c.wishCount()}</b>
                <span>{t('page.wanted')}</span>
              </div>
              <div>
                <b>{c.tradeCount()}</b>
                <span>{t('page.trade')}</span>
              </div>
            </div>
            <div className="CurioHero-buttons">
              {mine ? (
                <Button
                  className="Button Button--primary"
                  icon="fas fa-plus"
                  onclick={() => app.modal.show(ItemEditModal, { collection: c, onsave: () => this.refresh() })}
                >
                  {t('page.add_item')}
                </Button>
              ) : null}
              {!mine && app.session.user ? (
                <Button
                  className={'Button ' + (c.followed() ? 'Button--primary' : 'CurioHero-ghost')}
                  icon={c.followed() ? 'fas fa-bell' : 'far fa-bell'}
                  aria-pressed={c.followed() ? 'true' : 'false'}
                  onclick={() => c.save({ followed: !c.followed() })}
                >
                  {c.followed() ? t('page.following') : t('page.follow')}
                </Button>
              ) : null}
              {mine || c.canFeature() ? (
                <Button
                  className="Button CurioHero-ghost"
                  icon="fas fa-gear"
                  onclick={() => {
                    const typeId = c.type()?.id();
                    app.modal.show(CollectionEditModal, {
                      collection: c,
                      onimport: () => this.refresh(),
                      onsave: (saved) => {
                        m.route.set(collectionUrl(saved), null, { replace: true });
                        // A new type works each item's facet out again.
                        if (saved.type()?.id() !== typeId) this.filterFacet('');
                      },
                      ondelete: () => m.route.set(app.route('user.curio', { username: c.user().slug() })),
                    });
                  }}
                >
                  {t('page.settings')}
                </Button>
              ) : null}
            </div>
          </div>
        </div>
      </header>
    );
  }

  filterFacet(key) {
    this.facet = key;
    this.load(true);
  }

  /**
   * One row: what to show (status, the type's choice field, progress) on the
   * left; search, order, view and arranging on the right. Search and Arrange
   * are icon buttons; search opens into a field and closes again when empty.
   */
  toolbar(c, facetField, facets) {
    const canArrange = c.canEdit() && this.sort === 'position' && this.status === 'all' && !this.facet && !this.progress && !this.q;
    const type = c.type();
    const searchLabel = text('page.search', { count: c.ownedCount() + c.wishCount() });
    const reload = (key) => (v) => {
      this[key] = v;
      this.load(true);
    };

    return (
      <div className="CurioToolbar">
        <div className="CurioToolbar-filters">
          <Select
            value={this.status}
            onchange={reload('status')}
            aria-label={text('page.filter_label')}
            options={Object.fromEntries(FILTERS.map((s) => [s, text('filter.' + s)]))}
          />
          {facetField && Object.keys(facets).length > 1 ? (
            <Select
              value={this.facet}
              onchange={(v) => this.filterFacet(v)}
              aria-label={label(facetField.labels)}
              options={{
                '': text('filter.any', { field: label(facetField.labels) }),
                ...Object.fromEntries(Object.keys(facets).map((k) => [k, optionLabel(facetField, k, app.translator.getLocale())])),
              }}
            />
          ) : null}
          {type?.progress() ? (
            <Select
              value={this.progress}
              onchange={reload('progress')}
              aria-label={text('table.progress')}
              options={{ '': text('progress.any'), ...Object.fromEntries(PROGRESS.map((p) => [p, text('progress.' + p)])) }}
            />
          ) : null}
        </div>
        <div className={'CurioToolbar-right' + (this.searching || this.q ? ' is-searching' : '')}>
          {this.searching || this.q ? (
            <div className="Form-group CurioSearchField">
              <Icon name="fas fa-magnifying-glass" />
              <input
                className="FormControl"
                type="search"
                value={this.q}
                placeholder={searchLabel}
                aria-label={searchLabel}
                oncreate={(vnode) => vnode.dom.focus()}
                onblur={() => (this.searching = false)}
                onkeydown={(e) => {
                  if (e.key !== 'Escape') return;
                  e.preventDefault();
                  this.searching = false;
                  if (this.q) reload('q')('');
                }}
                oninput={(e) => {
                  this.q = e.target.value;
                  clearTimeout(this.debounce);
                  this.debounce = setTimeout(() => this.load(true), 300);
                }}
              />
            </div>
          ) : (
            <Tooltip text={searchLabel}>
              <Button
                className="Button Button--icon"
                icon="fas fa-magnifying-glass"
                aria-label={searchLabel}
                onclick={() => (this.searching = true)}
              />
            </Tooltip>
          )}
          <Select
            value={this.sort}
            onchange={reload('sort')}
            aria-label={text('page.sort_label')}
            options={Object.fromEntries(Object.keys(SORTS).map((k) => [k, text('sort.' + k)]))}
          />
          <div className="ButtonGroup" role="group" aria-label={text('page.view_label')}>
            <Button
              className={'Button Button--icon' + (this.view_ === 'grid' ? ' active' : '')}
              icon="fas fa-grip"
              aria-label={text('page.view_grid')}
              aria-pressed={this.view_ === 'grid' ? 'true' : 'false'}
              onclick={() => (this.view_ = 'grid')}
            />
            <Button
              className={'Button Button--icon' + (this.view_ === 'list' ? ' active' : '')}
              icon="fas fa-list"
              aria-label={text('page.view_list')}
              aria-pressed={this.view_ === 'list' ? 'true' : 'false'}
              onclick={() => (this.view_ = 'list')}
            />
          </div>
          {this.arranging ? (
            <Button className="Button Button--primary" icon="fas fa-check" onclick={() => this.arrange(false)}>
              {t('page.arrange_done')}
            </Button>
          ) : canArrange && this.items.length > 1 ? (
            <Tooltip text={text('page.arrange')}>
              <Button
                className="Button Button--icon"
                icon="fas fa-up-down-left-right"
                aria-label={text('page.arrange')}
                onclick={() => this.arrange(true)}
              />
            </Tooltip>
          ) : null}
        </div>
      </div>
    );
  }

  arrange(on) {
    this.arranging = on;
    this.view_ = 'grid';
    if (on && this.more) this.load(false);
  }

  grid(type, facetField) {
    const shape = type ? type.shape() : 'portrait';

    return (
      <ul className={'CurioGrid' + (this.arranging ? ' is-arranging' : '')} data-sortable={this.arranging ? 'true' : undefined}>
        {this.items.map((item, index) => {
          const wanted = item.status() === 'wishlist';
          const facet = facetField && item.facet() ? optionLabel(facetField, item.facet(), app.translator.getLocale()) : null;
          const attrs = this.arranging ? this.sorter.attrs(index) : { onclick: () => this.open(item.id()) };

          return (
            <li
              key={item.id()}
              className={'CurioItemCard' + (wanted ? ' CurioItemCard--wanted' : '') + (this.sorter.dragging === index ? ' is-dragging' : '')}
              {...attrs}
            >
              <button type="button" className="CurioItemCard-art" tabindex={this.arranging ? -1 : 0} aria-label={item.displayTitle()}>
                {cover(item.coverUrl(), shape, wanted)}
                {item.favourite() ? <Icon name="fas fa-star" className="CurioItemCard-fav" /> : null}
                {(item.photos() || []).length > 1 ? (
                  <span className="CurioItemCard-photos">
                    <Icon name="fas fa-camera" /> {item.photos().length}
                  </span>
                ) : null}
                {this.arranging ? <Icon name="fas fa-grip" className="CurioItemCard-grip" /> : null}
              </button>
              <div className="CurioItemCard-title">{item.displayTitle()}</div>
              <div className="CurioItemCard-sub">{[item.subtitle(), item.year()].filter(Boolean).join(' · ')}</div>
              {facet ? <span className="CurioItemCard-field">{facet}</span> : null}
              {item.rating() ? <Stars value={item.rating()} small /> : null}
              {this.state(item, type)}
            </li>
          );
        })}
      </ul>
    );
  }

  /** Wishlist or open to trade, and progress: one quiet line under the cover. */
  state(item, type) {
    const status = statusPill(item.status());
    const progress = progressPill(item, type);
    return status || progress ? (
      <div className="CurioItemCard-state">
        {progress}
        {status}
      </div>
    ) : null;
  }

  list(type) {
    const fields = (type?.fields() || []).filter((f) => f.kind !== 'bool').slice(0, 2);
    const shape = type ? type.shape() : 'portrait';

    return (
      <div className="CurioTableWrap">
        <table className="CurioTable">
          <thead>
            <tr>
              <th colSpan="2">{t('table.title')}</th>
              {fields.map((f) => (
                <th className="CurioTable-more">{label(f.labels)}</th>
              ))}
              <th className="CurioTable-more">{t('item.condition')}</th>
              <th className="CurioTable-more">{t('table.rating')}</th>
              {type?.progress() ? <th className="CurioTable-more">{t('table.progress')}</th> : null}
              <th className="CurioTable-more">{t('table.status')}</th>
            </tr>
          </thead>
          <tbody>
            {this.items.map((item) => {
              const values = fields.map((f) => {
                const v = (item.fields() || {})[f.key];
                return v === undefined || v === '' ? null : f.kind === 'select' ? optionLabel(f, v, app.translator.getLocale()) : v;
              });
              // Each is drawn twice (column and phone stack): Mithril needs a fresh vnode per place.
              const condition = () =>
                item.condition() ? <span className={'CurioCond CurioCond--' + item.condition()}>{t('condition.' + item.condition())}</span> : null;
              const stars = () => (item.rating() ? <Stars value={item.rating()} small /> : null);
              const progress = () => (type?.progress() ? progressPill(item, type) : null);
              const status = () => statusPill(item.status());
              const pills = [condition(), stars(), progress(), status()].filter(Boolean);

              return (
                <tr key={item.id()} onclick={() => this.open(item.id())}>
                  <td className="CurioTable-thumb">{cover(item.coverUrl(), shape, item.status() === 'wishlist')}</td>
                  <td className="CurioTable-main">
                    <button type="button" className="CurioTable-title" onclick={(e) => (e.stopPropagation(), this.open(item.id()))}>
                      {item.displayTitle()}
                    </button>
                    <div className="CurioItemCard-sub">{[item.subtitle(), item.year()].filter(Boolean).join(' · ')}</div>
                    {/* On a phone the other columns fold in here. */}
                    <div className="CurioTable-stack">
                      {values.some((v) => v !== null) ? (
                        <div className="CurioTable-values">{values.filter((v) => v !== null).join(' · ')}</div>
                      ) : null}
                      {pills.length ? <div className="CurioTable-pills">{pills}</div> : null}
                    </div>
                  </td>
                  {values.map((v) => (
                    <td className="CurioTable-more">{v === null ? '—' : v}</td>
                  ))}
                  <td className="CurioTable-more">{condition() || '—'}</td>
                  <td className="CurioTable-more">{stars() || '—'}</td>
                  {type?.progress() ? <td className="CurioTable-more">{progress() || '—'}</td> : null}
                  <td className="CurioTable-more">{status() || <span className="CurioStatus CurioStatus--owned">{t('status.owned')}</span>}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    );
  }
}
