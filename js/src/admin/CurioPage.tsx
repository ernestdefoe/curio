import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import TypesEditor from './TypesEditor';

/** Settings, then the type editor, then permissions. */
export default class CurioPage extends ExtensionPage {
  sections(vnode) {
    const items = super.sections(vnode);
    items.setPriority('content', 30);
    items.add(
      'types',
      <div className="ExtensionPage-settings CurioAdmin-section">
        <div className="container">
          <TypesEditor />
        </div>
      </div>,
      20
    );
    items.setPriority('permissions', 10);
    return items;
  }
}
