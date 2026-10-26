import app from 'flarum/admin/app';
import { Admin } from 'flarum/common/extenders';
import { extend as after } from 'flarum/common/extend';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Link from 'flarum/common/components/Link';
import { registerModels } from '../common/models';
import TypesEditor from './TypesEditor';
import { fofWidget } from '../common/widget';

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

  // FoF Forum Widgets' layout editor only lists widgets registered on the
  // admin side too. Registered each time the editor opens, from the setting
  // as it is now: read only at boot, switching "Offer the widget" on left the
  // editor without it until the whole admin was reloaded.
  const fof = (path) => {
    const mod = globalThis.flarum?.reg?.get?.('fof-forum-widgets-core', path);
    return mod?.default ?? mod;
  };
  const Widgets = fof('common/extend/Widgets');
  const WidgetEditor = fof('admin/components/WidgetEditor');
  if (!Widgets || !WidgetEditor) return;

  after(WidgetEditor.prototype, 'oninit', () => {
    const on = ['1', 'true', true].includes(app.data.settings['ernestdefoe-curio.widget']);
    const id = 'ernestdefoe-curio:curioRecent';
    const has = app.widgets.widgets.some((w) => w.id === id);
    if (on && !has) {
      new Widgets()
        .add({ ...fofWidget(null), title: () => t('settings.widget_title'), icon: 'fas fa-boxes-stacked' })
        .extend(app, 'ernestdefoe-curio');
    } else if (!on && has) {
      app.widgets.widgets = app.widgets.widgets.filter((w) => w.id !== id);
    }
  });
});

// Callbacks, not values: this module runs before the app has booted.
export const extend = [
  new Admin()
    .page(CurioPage)
    .setting(() => ({
      setting: 'ernestdefoe-curio.browse_layout',
      type: 'select',
      label: t('settings.layout_label'),
      help: t('settings.layout_help'),
      options: { tags: t('settings.layout_tags'), covers: t('settings.layout_covers'), compact: t('settings.layout_compact') },
      default: 'tags',
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.browse_ranking',
      type: 'select',
      label: t('settings.ranking_label'),
      help: t('settings.ranking_help'),
      options: { collections: t('settings.ranking_collections'), collectors: t('settings.ranking_collectors'), both: t('settings.ranking_both') },
      default: 'collections',
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.type_color',
      type: 'select',
      label: t('settings.type_color_label'),
      help: t('settings.type_color_help'),
      options: { background: t('settings.type_color_background'), text: t('settings.type_color_text') },
      default: 'background',
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.latest_changes',
      type: 'boolean',
      label: t('settings.latest_label'),
      help: t('settings.latest_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.hide_empty_types',
      type: 'boolean',
      label: t('settings.hide_empty_label'),
      help: t('settings.hide_empty_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.cover_fit',
      type: 'select',
      label: t('settings.cover_fit_label'),
      help: t('settings.cover_fit_help'),
      options: { blur: t('settings.cover_fit_blur'), plain: t('settings.cover_fit_plain'), crop: t('settings.cover_fit_crop') },
      default: 'blur',
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.shortcut',
      type: 'select',
      label: t('settings.shortcut_label'),
      help: t('settings.shortcut_help'),
      options: {
        both: t('settings.shortcut_both'),
        sidebar: t('settings.shortcut_sidebar'),
        menu: t('settings.shortcut_menu'),
        none: t('settings.shortcut_none'),
      },
      default: 'both',
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.widget',
      type: 'boolean',
      label: t('settings.widget_label'),
      help: t('settings.widget_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.igdb_client_id',
      type: 'text',
      label: t('settings.igdb_id_label'),
      help: (
        <>
          {t('settings.igdb_help')}{' '}
          <Link href="https://dev.twitch.tv/console/apps/create" external target="_blank">
            {t('settings.igdb_link')}
          </Link>{' '}
          <Link href="https://api-docs.igdb.com/#account-creation" external target="_blank">
            {t('settings.igdb_docs')}
          </Link>
        </>
      ),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.igdb_client_secret',
      type: 'password',
      label: t('settings.igdb_secret_label'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.barcodes',
      type: 'boolean',
      label: t('settings.barcodes_label'),
      help: t('settings.barcodes_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.upcitemdb_key',
      type: 'text',
      label: t('settings.upc_label'),
      help: (
        <>
          {t('settings.upc_help')}{' '}
          <Link href="https://devs.upcitemdb.com/" external target="_blank">
            {t('settings.upc_link')}
          </Link>
        </>
      ),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.barcodelookup_key',
      type: 'password',
      label: t('settings.barcodelookup_label'),
      help: (
        <>
          {t('settings.barcodelookup_help')}{' '}
          <Link href="https://www.barcodelookup.com/api" external target="_blank">
            {t('settings.barcodelookup_link')}
          </Link>
        </>
      ),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.eansearch_token',
      type: 'password',
      label: t('settings.eansearch_label'),
      help: (
        <>
          {t('settings.eansearch_help')}{' '}
          <Link href="https://www.ean-search.org/ean-database-api.html" external target="_blank">
            {t('settings.eansearch_link')}
          </Link>
        </>
      ),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.ebay_app_id',
      type: 'text',
      label: t('settings.ebay_app_label'),
      help: (
        <>
          {t('settings.ebay_help')}{' '}
          <Link href="https://developer.ebay.com/my/keys" external target="_blank">
            {t('settings.ebay_link')}
          </Link>
        </>
      ),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.ebay_cert_id',
      type: 'password',
      label: t('settings.ebay_cert_label'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-curio.ebay_marketplace',
      type: 'select',
      label: t('settings.ebay_market_label'),
      help: t('settings.ebay_market_help'),
      options: Object.fromEntries(
        [
          '',
          'EBAY_US',
          'EBAY_GB',
          'EBAY_DE',
          'EBAY_FR',
          'EBAY_IT',
          'EBAY_ES',
          'EBAY_NL',
          'EBAY_BE',
          'EBAY_AT',
          'EBAY_CH',
          'EBAY_IE',
          'EBAY_PL',
          'EBAY_CA',
          'EBAY_AU',
        ].map((id) => [id, id ? id.replace('EBAY_', 'eBay ') : t('settings.ebay_market_auto')])
      ),
      default: '',
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
    .permission(() => ({ icon: 'fas fa-file-import', label: t('permissions.import'), permission: 'ernestdefoe-curio.import' }), 'start', 89)
    .permission(() => ({ icon: 'fas fa-file-export', label: t('permissions.export'), permission: 'ernestdefoe-curio.export' }), 'start', 88)
    .permission(() => ({ icon: 'fas fa-boxes-stacked', label: t('permissions.moderate'), permission: 'ernestdefoe-curio.moderate' }), 'moderate', 90)
    .permission(
      () => ({ icon: 'fas fa-pen-to-square', label: t('permissions.edit_items'), permission: 'ernestdefoe-curio.editItems' }),
      'moderate',
      89
    ),
];
