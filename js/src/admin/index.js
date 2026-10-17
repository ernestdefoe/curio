import app from 'flarum/admin/app';
import { Admin } from 'flarum/common/extenders';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Link from 'flarum/common/components/Link';
import { registerModels } from '../common/models';
import TypesEditor from './TypesEditor';

const t = (key, params) => app.translator.trans(`ernestdefoe-curio.admin.${key}`, params);

/** Settings, then the type editor, then permissions. */
class CurioPage extends ExtensionPage {
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

app.initializers.add('ernestdefoe-curio', () => {
  registerModels(app);
});

// Callbacks, not values: this module runs before the app has booted.
export const extend = [
  new Admin()
    .page(CurioPage)
    .setting(() => ({
      setting: 'ernestdefoe-curio.upcitemdb_key',
      type: 'text',
      label: t('settings.upc_label'),
      help: (
        <>
          {t('settings.upc_help')}{' '}
          <Link href="https://www.upcitemdb.com/wp/docs/main/development/getting-started/" external target="_blank">
            {t('settings.upc_link')}
          </Link>
        </>
      ),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.deepl_key',
      type: 'text',
      label: t('settings.deepl_label'),
      help: (
        <>
          {t('settings.deepl_help')}{' '}
          <Link href="https://www.deepl.com/your-account/keys" external target="_blank">
            {t('settings.deepl_link')}
          </Link>
        </>
      ),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.max_upload_kb',
      type: 'number',
      min: 256,
      max: 20480,
      label: t('settings.max_upload_label'),
      help: t('settings.max_upload_help'),
    }))
    .permission(
      () => ({ icon: 'fas fa-boxes-stacked', label: t('permissions.view'), permission: 'ernestdefoe-curio.view', allowGuest: true }),
      'view',
      90
    )
    .permission(() => ({ icon: 'fas fa-plus', label: t('permissions.create'), permission: 'ernestdefoe-curio.create' }), 'start', 90)
    .permission(() => ({ icon: 'fas fa-boxes-stacked', label: t('permissions.moderate'), permission: 'ernestdefoe-curio.moderate' }), 'moderate', 90),
];
