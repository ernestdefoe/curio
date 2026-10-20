import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Select from 'flarum/common/components/Select';
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
                  onclick={() =>
                    app.modal.show(CollectionEditModal, {
                      collection: c,
                      onimport: () => this.refresh(),
                      onsave: (saved) => m.route.set(collectionUrl(saved), null, { replace: true }),
                      ondelete: () => m.route.set(app.route('user.curio', { username: c.user().slug() })),
                    })
                  }
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

  toolbar(c, facetField, facets) {
    const canArrange = c.canEdit() && this.sort === 'position' && this.status === 'all' && !this.facet && !this.progress && !this.q;
    const type = c.type();

    return (
      <div className="CurioToolbar">
        <div className="ButtonGroup" role="group" aria-label={text('page.filter_label')}>
          {FILTERS.map((s) => (
            <Button
              className={'Button' + (this.status === s ? ' Button--primary' : '')}
              aria-pressed={this.status === s ? 'true' : 'false'}
              onclick={() => {
                this.status = s;
                this.load(true);
              }}
            >
              {t('filter.' + s)}
            </Button>
          ))}
        </div>
        {facetField && Object.keys(facets).length > 1 ? (
          <Select
            value={this.facet}
            onchange={(v) => this.filterFacet(v)}
            options={{
              '': text('filter.any', { field: label(facetField.labels) }),
              ...Object.fromEntries(Object.keys(facets).map((k) => [k, optionLabel(facetField, k, app.translator.getLocale())])),
            }}
          />
        ) : null}
        {type?.progress() ? (
          <Select
            value={this.progress}
            onchange={(v) => {
              this.progress = v;
              this.load(true);
            }}
            options={{ '': text('progress.any'), ...Object.fromEntries(PROGRESS.map((p) => [p, text('progress.' + p)])) }}
          />
        ) : null}
        <div className="CurioToolbar-right">
          <div className="Form-group CurioSearchField">
            <Icon name="fas fa-magnifying-glass" />
            <input
              className="FormControl"
              type="search"
              value={this.q}
              placeholder={text('page.search', { count: c.ownedCount() + c.wishCount() })}
              aria-label={text('page.search', { count: c.ownedCount() + c.wishCount() })}
              oninput={(e) => {
                this.q = e.target.value;
                clearTimeout(this.debounce);
                this.debounce = setTimeout(() => this.load(true), 300);
              }}
            />
          </div>
          <Select
            value={this.sort}
            onchange={(v) => {
              this.sort = v;
              this.load(true);
            }}
            options={Object.fromEntries(Object.keys(SORTS).map((k) => [k, text('sort.' + k)]))}
          />
          <div className="ButtonGroup" role="group" aria-label={text('page.view_label')}>
            <Button
              className={'Button Button--icon' + (this.view_ === 'grid' ? ' active' : '')}
              icon="fas fa-grip"
              aria-label={text('page.view_grid')}
              onclick={() => (this.view_ = 'grid')}
            />
            <Button
              className={'Button Button--icon' + (this.view_ === 'list' ? ' active' : '')}
              icon="fas fa-list"
              aria-label={text('page.view_list')}
              onclick={() => (this.view_ = 'list')}
            />
          </div>
          {canArrange && this.items.length > 1 ? (
            <Button
              className={'Button' + (this.arranging ? ' Button--primary' : '')}
              icon={this.arranging ? 'fas fa-check' : 'fas fa-up-down-left-right'}
              onclick={() => {
                this.arranging = !this.arranging;
                this.view_ = 'grid';
                if (this.arranging && this.more) this.load(false);
              }}
            >
              {this.arranging ? t('page.arrange_done') : t('page.arrange')}
            </Button>
          ) : null}
        </div>
      </div>
    );
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
                <span className="CurioItemCard-tags">{statusPill(item.status())}</span>
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
              {progressPill(item, type)}
            </li>
          );
        })}
      </ul>
    );
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
                <th>{label(f.labels)}</th>
              ))}
              <th>{t('item.condition')}</th>
              <th>{t('table.rating')}</th>
              {type?.progress() ? <th>{t('table.progress')}</th> : null}
              <th>{t('table.status')}</th>
            </tr>
          </thead>
          <tbody>
            {this.items.map((item) => (
              <tr key={item.id()} onclick={() => this.open(item.id())}>
                <td className="CurioTable-thumb">{cover(item.coverUrl(), shape, item.status() === 'wishlist')}</td>
                <td>
                  <button type="button" className="CurioTable-title" onclick={(e) => (e.stopPropagation(), this.open(item.id()))}>
                    {item.displayTitle()}
                  </button>
                  <div className="CurioItemCard-sub">{[item.subtitle(), item.year()].filter(Boolean).join(' · ')}</div>
                </td>
                {fields.map((f) => {
                  const v = (item.fields() || {})[f.key];
                  return <td>{v === undefined ? '—' : f.kind === 'select' ? optionLabel(f, v, app.translator.getLocale()) : v}</td>;
                })}
                <td>
                  {item.condition() ? <span className={'CurioCond CurioCond--' + item.condition()}>{t('condition.' + item.condition())}</span> : '—'}
                </td>
                <td>{item.rating() ? <Stars value={item.rating()} small /> : '—'}</td>
                {type?.progress() ? <td>{progressPill(item, type) || '—'}</td> : null}
                <td>{statusPill(item.status()) || <span className="CurioStatus CurioStatus--owned">{t('status.owned')}</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }
}
