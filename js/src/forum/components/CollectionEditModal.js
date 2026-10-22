import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import SelectDropdown from 'flarum/common/components/SelectDropdown';
import Icon from 'flarum/common/components/Icon';
import Stream from 'flarum/common/utils/Stream';
import { t, text, label, types, loadTypes, closeThen } from '../util';
import useStyles from '../styles';

const VISIBILITIES = ['public', 'members', 'private'];

/** Start a collection, or change one: name, type, description, who sees it. */
export default class CollectionEditModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);
    useStyles();
    const c = this.attrs.collection;

    this.name = Stream(c ? c.name() : '');
    this.description = Stream(c ? c.description() || '' : '');
    this.visibility = Stream(c ? c.visibility() : 'public');
    this.wishlistPublic = Stream(c ? c.wishlistPublic() : true);
    this.featured = Stream(c ? c.featured() : false);
    this.typeId = Stream(c ? c.type()?.id() : this.attrs.typeId || null);

    loadTypes().then(() => {
      if (!this.typeId() && types()[0]) this.typeId(types()[0].id());
      m.redraw();
    });
  }

  className() {
    return 'CurioEditModal Modal--small';
  }

  title() {
    return this.attrs.collection ? t('edit.title_edit') : t('edit.title_new');
  }

  content() {
    const c = this.attrs.collection;

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label>{t('edit.type')}</label>
            {this.typePicker()}
            {c && this.typeId() !== c.type()?.id() ? <p className="helpText">{t('edit.type_change_help')}</p> : null}
          </div>
          <div className="Form-group">
            <label for="curio-name">{t('edit.name')}</label>
            <input id="curio-name" name="name" className="FormControl" maxlength="100" bidi={this.name} placeholder={text('edit.name_placeholder')} />
          </div>
          <div className="Form-group">
            <label for="curio-description">{t('edit.description')}</label>
            <textarea id="curio-description" name="description" className="FormControl" rows="3" maxlength="1000" bidi={this.description} />
          </div>
          <div className="Form-group">
            <label>{t('edit.visibility')}</label>
            <div className="CurioChoices" role="radiogroup">
              {VISIBILITIES.map((v) => (
                <label className={'CurioChoice' + (this.visibility() === v ? ' active' : '')}>
                  <input type="radio" name="visibility" value={v} checked={this.visibility() === v} onchange={() => this.visibility(v)} />
                  <Icon name={{ public: 'fas fa-globe', members: 'fas fa-user-group', private: 'fas fa-lock' }[v]} />
                  <span>
                    <strong>{t('visibility.' + v)}</strong>
                    <small>{t('visibility.' + v + '_help')}</small>
                  </span>
                </label>
              ))}
            </div>
          </div>
          <div className="Form-group">
            <Switch state={this.wishlistPublic()} onchange={this.wishlistPublic}>
              {t('edit.wishlist_public')}
            </Switch>
          </div>
          {c && c.canFeature() ? (
            <div className="Form-group">
              <Switch state={this.featured()} onchange={this.featured}>
                {t('edit.featured')}
              </Switch>
            </div>
          ) : null}
          {c && c.canEdit() ? this.transfer(c) : null}
          <div className="Form-group CurioEditModal-actions">
            <Button className="Button Button--primary" type="submit" loading={this.loading} disabled={!this.name().trim() || !this.typeId()}>
              {c ? t('edit.save') : t('edit.create')}
            </Button>
            {c && c.canDelete() ? (
              <Button className="Button Button--danger" icon="fas fa-trash" onclick={() => this.delete()}>
                {t('edit.delete')}
              </Button>
            ) : null}
          </div>
        </div>
      </div>
    );
  }

  /**
   * The type as a compact menu, each with its icon. Switched-off types are
   * only ever offered to admins, in their own group at the end.
   */
  typePicker() {
    const all = types();
    const selected = all.find((type) => type.id() === this.typeId()) || this.attrs.collection?.type();
    const option = (type) => (
      <Button
        icon={type.icon()}
        active={type === selected}
        itemClassName={type === selected ? 'active' : ''}
        style={{ '--curio-accent': type.color() }}
        onclick={() => this.typeId(type.id())}
      >
        {label(type.names())}
      </Button>
    );
    const off = all.filter((type) => type.enabled() === false);

    return (
      <SelectDropdown
        className="CurioTypeMenu"
        buttonClassName="Button"
        menuClassName="CurioTypeMenu-menu"
        icon={selected ? selected.icon() : 'fas fa-shapes'}
        buttonAttrs={{ style: selected?.color() ? `--curio-accent: ${selected.color()}` : undefined }}
        defaultLabel={text('edit.type_pick')}
        accessibleToggleLabel={selected ? `${text('edit.type')} ${label(selected.names())}` : text('edit.type')}
      >
        {[
          ...all.filter((type) => type.enabled() !== false).map(option),
          off.length ? <div className="Dropdown-header">{t('edit.type_off_group')}</div> : null,
          ...off.map(option),
        ]}
      </SelectDropdown>
    );
  }

  /** Bring a spreadsheet in, or take the collection out as one. */
  transfer(c) {
    // Each needs its permission (Members by default); without either, nothing shows.
    if (!c.canImport() && !c.canExport()) return null;

    return (
      <div className="Form-group CurioTransfer">
        <label>{t('transfer.heading')}</label>
        <p className="helpText">{t('transfer.help')}</p>
        <div className="CurioTransfer-buttons">
          {c.canImport() ? (
            <label className={'Button' + (this.importing ? ' disabled' : '')}>
              <Icon name={this.importing ? 'fas fa-spinner fa-spin' : 'fas fa-file-import'} /> {t('transfer.import')}
              <input type="file" accept=".csv,text/csv" hidden disabled={this.importing} onchange={(e) => this.importFile(c, e.target.files[0])} />
            </label>
          ) : null}
          {c.canExport() ? (
            <a className="Button" href={`${app.forum.attribute('apiUrl')}/curio-collections/${c.id()}/export`} download>
              <Icon name="fas fa-file-export" /> {t('transfer.export')}
            </a>
          ) : null}
        </div>
        {this.imported ? <p className="CurioTransfer-result">{t('transfer.done', this.imported)}</p> : null}
      </div>
    );
  }

  importFile(c, file) {
    if (!file) return;
    const body = new FormData();
    body.append('file', file);
    this.importing = true;
    this.imported = null;

    app
      .request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/curio-collections/${c.id()}/import`, serialize: (raw) => raw, body })
      .then((result) => {
        this.imported = { count: result.imported, skipped: result.skipped, format: text('transfer.format_' + result.format) };
        this.attrs.onimport && this.attrs.onimport();
      })
      .catch((error) => this.onerror(error))
      .finally(() => {
        this.importing = false;
        m.redraw();
      });
  }

  onsubmit(e) {
    e.preventDefault();
    const c = this.attrs.collection;
    const attrs = {
      name: this.name().trim(),
      description: this.description().trim() || null,
      visibility: this.visibility(),
      wishlistPublic: this.wishlistPublic(),
    };

    if (c && c.canFeature()) attrs.featured = this.featured();
    if (!c || this.typeId() !== c.type()?.id()) attrs.relationships = { type: app.store.getById('curio-types', this.typeId()) };

    this.loading = true;
    (c || app.store.createRecord('curio-collections'))
      .save(attrs)
      .then((saved) => closeThen(this.attrs.onsave, saved))
      .catch((error) => {
        this.loading = false;
        this.onerror(error);
      });
  }

  delete() {
    const c = this.attrs.collection;
    if (!confirm(text('edit.delete_confirm', { name: c.name() }))) return;

    this.loading = true;
    c.delete()
      .then(() => closeThen(this.attrs.ondelete))
      .catch((error) => {
        this.loading = false;
        this.onerror(error);
      });
  }
}
