import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import LinkButton from 'flarum/common/components/LinkButton';
import UserPage from 'flarum/forum/components/UserPage';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import { registerModels } from '../common/models';
import registerWidgets from './widgets';
import { t } from './util';

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

  registerWidgets();
});
