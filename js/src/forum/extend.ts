import Extend from 'flarum/common/extenders';
import commonExtend from '../common/extend';
import { WishlistMatchNotification, FOLLOWED } from './components/notifications';

const notifications = new Extend.Notification().add('curioWishlistMatch', WishlistMatchNotification);
FOLLOWED.forEach(([type, component]) => notifications.add(type, component));

export default [
  ...commonExtend,

  // Pages load on first visit, never with every forum page.
  new Extend.Routes()
    .add('curio.browse', '/collections', () => import('./components/BrowsePage'))
    .add('curio.collection', '/collections/:id', () => import('./components/CollectionPage'))
    .add('user.curio', '/u/:username/collections', () => import('./components/UserCollectionsPage')),

  notifications,
];
