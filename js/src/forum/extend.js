import Extend from 'flarum/common/extenders';
import { WishlistMatchNotification, FOLLOWED } from './components/notifications';

const notifications = new Extend.Notification().add('curioWishlistMatch', WishlistMatchNotification);
FOLLOWED.forEach(([type, component]) => notifications.add(type, component));

export default [notifications];
