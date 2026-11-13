import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import PostUser from 'flarum/forum/components/PostUser';
import UserCard from 'flarum/forum/components/UserCard';
import { t, text } from './util';

/** Where the admin chose to show it: "posts", "card", both or neither. */
const shows = (place) => ['both', place].includes(app.forum.attribute('curioCollector'));

/**
 * The collector badge: an icon (with the admin's label, if any) for members
 * with a collection the reader may see. The server sends the count only then,
 * so a member whose collections are all private, or members-only to a guest,
 * shows nothing.
 */
function badge(user, count, className, label) {
  const color = app.forum.attribute('curioCollectorColor');

  return (
    <Link
      className={'CurioCollector ' + className}
      href={app.route('user.curio', { username: user.slug() })}
      title={text('count.collections', { count })}
      aria-label={label ? null : text('count.collections', { count })}
      style={color ? { '--curio-collector': color } : null}
    >
      <Icon name={app.forum.attribute('curioCollectorIcon') || 'fas fa-boxes-stacked'} />
      {label ? <span className="CurioCollector-label">{label}</span> : null}
    </Link>
  );
}

export default function collector() {
  // Beside the name, apart from the group badges core stacks on the avatar.
  extend(PostUser.prototype, 'userViewItems', function (items, user) {
    const count = user.attribute('curioCollections');
    if (!count || !shows('posts')) return;
    items.add('curioCollector', badge(user, count, 'CurioCollector--post', app.forum.attribute('curioCollectorText')), 95);
  });

  // After "Joined …", with the number of collections.
  extend(UserCard.prototype, 'infoItems', function (items) {
    const user = this.attrs.user;
    const count = user.attribute('curioCollections');
    if (!count || !shows('card')) return;
    items.add('curioCollector', badge(user, count, 'CurioCollector--card', t('count.collections', { count })), 85);
  });
}
