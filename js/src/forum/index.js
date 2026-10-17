import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import DiscussionHero from 'flarum/forum/components/DiscussionHero';
import LinkButton from 'flarum/common/components/LinkButton';
import UserPage from 'flarum/forum/components/UserPage';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import { registerModels } from '../common/models';
import registerWidgets from './widgets';
import { t } from './util';
import { applyShowOff } from './showOff';
/** Loaded the first time a "Show it off" discussion is opened, never with other pages. */
let ItemHero = null;
let heroLoading = null;
const loadHero = () =>
  (heroLoading ??= import('./components/ItemHero').then((module) => {
    ItemHero = module.default;
    m.redraw();
  }));

app.initializers.add('ernestdefoe-curio', () => {
  registerModels(app);

  // Pages load on first visit, never with every forum page.
  app.routes['curio.browse'] = { path: '/collections', component: () => import('./components/BrowsePage') };
  app.routes['curio.collection'] = { path: '/collections/:id', component: () => import('./components/CollectionPage') };
  app.routes['user.curio'] = { path: '/u/:username/collections', component: () => import('./components/UserCollectionsPage') };

  extend(UserPage.prototype, 'navItems', function (items) {
    if (!this.user || !app.forum.attribute('canViewCurio')) return;
    items.add(
      'curio',
      <LinkButton href={app.route('user.curio', { username: this.user.slug() })} icon="fas fa-boxes-stacked">
        {t('profile.nav')}
      </LinkButton>,
      85
    );
  });

  extend(IndexSidebar.prototype, 'navItems', function (items) {
    if (!app.forum.attribute('canViewCurio')) return;
    items.add(
      'curio',
      <LinkButton href={app.route('curio.browse')} icon="fas fa-boxes-stacked">
        {t('browse.title')}
      </LinkButton>,
      85
    );
  });

  // "Show it off": the composer carries the item it was opened for.
  extend('flarum/forum/components/DiscussionComposer', 'oncreate', function () {
    applyShowOff(this.composer);
  });

  extend('flarum/forum/components/DiscussionComposer', 'headerItems', function (items) {
    const item = this.composer.fields.curioItem;
    if (!item) return;
    items.add(
      'curio',
      <span className="CurioComposerItem">
        {item.cover ? <img src={item.cover} alt="" /> : null}
        {t('showoff.composer', { title: <b>{item.title}</b> })}
        <button
          type="button"
          className="Button Button--icon Button--link"
          aria-label={t('showoff.remove')}
          onclick={() => (this.composer.fields.curioItem = null)}
        >
          <i className="fas fa-times" aria-hidden="true" />
        </button>
      </span>,
      -10
    );
  });

  extend('flarum/forum/components/DiscussionComposer', 'data', function (data) {
    const item = this.composer.fields.curioItem;
    if (item) data.curioItemId = Number(item.id);
  });

  // Its discussion page opens with the item as the hero (unless Marquee already has the hero).
  override(DiscussionHero.prototype, 'view', function (original) {
    const discussion = this.attrs.discussion;
    const item = discussion.curioItem && discussion.curioItem();
    if (!item || (discussion.marqueeMedia && discussion.marqueeMedia())) return original();
    if (!ItemHero) {
      loadHero();
      return original();
    }
    return <ItemHero discussion={discussion} item={item} />;
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', (items) => {
    items.add('curioWishlistMatch', { name: 'curioWishlistMatch', icon: 'fas fa-right-left', label: t('notifications.match_setting') });
    items.add('curioNewItems', { name: 'curioNewItems', icon: 'fas fa-boxes-stacked', label: t('notifications.new_items_setting') });
  });

  extendMarquee();
  registerWidgets();
});

/**
 * With Marquee installed, its cards get "Add to collection". Found at run
 * time, so Curio works the same without it.
 */
function extendMarquee() {
  const mod = globalThis.flarum?.reg?.get?.('ernestdefoe-marquee', 'forum/components/MediaHero');
  const MediaHero = mod?.default ?? mod;
  if (!MediaHero?.prototype?.actionItems) return;

  extend(MediaHero.prototype, 'actionItems', function (items) {
    const media = this.attrs.link?.media;
    if (!media || !app.session.user || !app.forum.attribute('canCreateCurio')) return;
    items.add(
      'curio',
      <Button
        className="Button CurioMarqueeButton"
        icon="fas fa-boxes-stacked"
        onclick={(e) => {
          const button = e.currentTarget;
          button.disabled = true;
          import('./fromMarquee').then((module) => module.default(media)).finally(() => (button.disabled = false));
        }}
      >
        {t('marquee.add')}
      </Button>,
      65
    );
  });
}
