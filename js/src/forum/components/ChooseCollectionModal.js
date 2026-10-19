import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import { t } from '../util';
import { typeBadge } from './bits';
import useStyles from '../styles';

/** Which of your collections should it go in? */
export default class ChooseCollectionModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    useStyles();
  }

  className() {
    return 'CurioChooseModal Modal--small';
  }

  title() {
    return t('item.choose_collection');
  }

  content() {
    return (
      <div className="Modal-body">
        <ul className="CurioChooseList">
          {this.attrs.collections.map((c) => (
            <li>
              <Button className="Button CurioChooseList-item" onclick={() => this.attrs.onchoose(c)}>
                <span>{c.name()}</span> {typeBadge(c.type())}
              </Button>
            </li>
          ))}
        </ul>
        {this.attrs.onnew ? (
          <Button className="Button Button--link CurioChooseList-new" icon="fas fa-plus" onclick={() => this.attrs.onnew()}>
            {t('profile.new')}
          </Button>
        ) : null}
      </div>
    );
  }
}
