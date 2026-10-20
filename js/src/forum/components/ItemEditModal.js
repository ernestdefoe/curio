import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import { t, text, label, locale, STATUSES, CONDITIONS, PROGRESS, lookup, closeThen } from '../util';
import { pick, onlyOption } from '../../common/labels';
import Stars from './Stars';
import { canScan, scan } from '../scanner';
import useStyles from '../styles';

const MAX_PHOTOS = 6;
const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'BRL', 'MXN', 'INR'];

const defaultCurrency = () => {
  const l = locale();
  if (l === 'en-GB') return 'GBP';
  return ['fr', 'de', 'es', 'it', 'nl', 'pt', 'fi', 'el'].includes(l.split(/[-_]/)[0]) ? 'EUR' : 'USD';
};

/**
 * Add an item (look it up or scan it, then details and photos) or change one.
 * `prefill` starts from another member's item: "I have this too".
 */
export default class ItemEditModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);
    useStyles();
    const item = this.attrs.item;
    const from = this.attrs.prefill;

    this.collection = this.attrs.collection;
    this.type = this.collection.type();
    // A new item starts at the look-up: even a type with no title search takes a barcode.
    this.step = item || from || this.attrs.work ? 'details' : 'find';

    this.query = Stream('');
    this.results = null;
    this.barcode = null;
    this.searching = false;
    this.resolving = false;
    this.scanning = null;
    this.work = item ? item.work() : from ? from.work() : this.attrs.work || null;

    const src = item || from;
    const work = !src && this.work;
    this.itemTitle = Stream(src ? src.title() : work ? work.originalTitle() : '');
    this.subtitle = Stream(src ? src.subtitle() || '' : work ? work.creator() || '' : '');
    this.year = Stream(src && src.year() ? String(src.year()) : work && work.year() ? String(work.year()) : '');
    this.status = Stream(item ? item.status() : this.attrs.status || 'owned');
    this.condition = Stream(item ? item.condition() || '' : '');
    this.acquiredOn = Stream(item ? item.acquiredOn() || '' : '');
    this.fields = Object.assign({}, src ? src.fields() || {} : {});
    this.notes = Stream(item ? item.notes() || '' : '');
    this.favourite = Stream(item ? !!item.favourite() : false);
    this.rating = Stream(item ? item.rating() || null : null);
    this.progress = Stream(item ? item.progress() || '' : '');
    this.pricePaid = Stream(item && item.pricePaid() !== null && item.pricePaid() !== undefined ? String(item.pricePaid()) : '');
    this.priceCurrency = Stream(item ? item.priceCurrency() || defaultCurrency() : defaultCurrency());
    this.code = null;
    this.queued = [];
    if (work) this.prefillFromFacts(work.facts());
  }

  onremove(vnode) {
    super.onremove(vnode);
    this.scanning && this.scanning.stop();
    this.queued.forEach((q) => URL.revokeObjectURL(q.url));
  }

  className() {
    return 'CurioItemEditModal Modal--medium';
  }

  title() {
    return this.attrs.item ? t('item_edit.title_edit') : t('item_edit.title_new', { name: this.collection.name() });
  }

  content() {
    return (
      <div className="Modal-body">
        {this.attrs.item ? null : (
          <div className="CurioTabs" role="tablist">
            <button
              type="button"
              role="tab"
              aria-selected={this.step === 'find' ? 'true' : 'false'}
              className={this.step === 'find' ? 'active' : ''}
              onclick={() => (this.step = 'find')}
            >
              <Icon name="fas fa-magnifying-glass" /> {t('item_edit.tab_find')}
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={this.step === 'details' ? 'true' : 'false'}
              className={this.step === 'details' ? 'active' : ''}
              onclick={() => (this.step = 'details')}
            >
              <Icon name="fas fa-pen" /> {t('item_edit.tab_details')}
            </button>
          </div>
        )}
        {this.step === 'find' ? this.findStep() : this.detailsStep()}
      </div>
    );
  }

  // ── Look it up ─────────────────────────────────────────────────────────
  findStep() {
    const searchable = this.type.searchable();

    return (
      <div className="CurioFind">
        <div className="CurioFind-row">
          <div className="Form-group CurioSearchField CurioSearchField--big">
            <Icon name={this.barcode ? 'fas fa-barcode' : 'fas fa-magnifying-glass'} />
            <input
              className="FormControl"
              value={this.query()}
              placeholder={text(searchable ? 'item_edit.search_placeholder' : 'item_edit.barcode_placeholder')}
              aria-label={text(searchable ? 'item_edit.search_placeholder' : 'item_edit.barcode_placeholder')}
              oninput={(e) => this.typed(e.target.value)}
              onkeydown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  this.search();
                }
              }}
            />
          </div>
          {canScan() ? (
            <Button
              type="button"
              className={'Button CurioScanButton' + (this.scanning ? ' Button--primary' : '')}
              icon="fas fa-barcode"
              onclick={() => this.toggleScan()}
            >
              {this.scanning ? t('item_edit.scan_stop') : t('item_edit.scan')}
            </Button>
          ) : null}
        </div>

        {this.scanning ? (
          <div className="CurioScanner">
            <video playsinline muted oncreate={(v) => this.startScan(v.dom)} />
            <span className="CurioScanner-line" />
          </div>
        ) : null}

        {this.barcode ? (
          <div className={'CurioScanned' + (this.barcode.results.length || this.barcode.title ? '' : ' CurioScanned--miss')}>
            <Icon name={this.barcode.results.length || this.barcode.title ? 'fas fa-circle-check' : 'fas fa-circle-question'} />{' '}
            {this.barcode.results.length || this.barcode.title
              ? t('item_edit.barcode_found', { code: this.barcode.code })
              : t('item_edit.barcode_unknown', { code: this.barcode.code })}
          </div>
        ) : null}

        {this.searching ? <LoadingIndicator display="block" /> : this.resultList()}

        {this.resolving ? <LoadingIndicator display="block" /> : null}

        <p className="CurioHint">
          <Icon name="fas fa-language" /> {t('item_edit.find_hint')}
        </p>
        <div className="CurioModalFooter">
          <Button type="button" className="Button Button--link" onclick={() => this.manual()}>
            {t('item_edit.by_hand')}
          </Button>
        </div>
      </div>
    );
  }

  resultList() {
    if (!this.results) return null;
    if (!this.results.length) return <p className="CurioEmpty">{t('item_edit.no_results')}</p>;

    return (
      <ul className="CurioResults">
        {this.results.map((r) => (
          <li>
            <button type="button" className="CurioResult" onclick={() => this.pick(r)} disabled={this.resolving}>
              <span className={'CurioResult-cover CurioResult-cover--' + this.type.shape()}>
                {r.cover ? <img src={r.cover} alt="" loading="lazy" /> : <Icon name={this.type.icon()} />}
              </span>
              <span className="CurioResult-body">
                <strong>
                  {r.title} {r.year ? <span className="CurioMuted">({r.year})</span> : null}
                </strong>
                {r.creator ? <span className="CurioMuted">{r.creator}</span> : null}
                {this.barcode && Object.keys(this.barcode.fields || {}).length ? (
                  <span className="CurioResult-chips">
                    {this.type
                      .fields()
                      .filter((f) => this.barcode.fields[f.key])
                      .map((f) => (
                        <span className="CurioChip CurioChip--found">{this.fieldText(f, this.barcode.fields[f.key])}</span>
                      ))}
                  </span>
                ) : null}
              </span>
              <Icon name="fas fa-chevron-right" className="CurioResult-go" />
            </button>
          </li>
        ))}
      </ul>
    );
  }

  typed(value) {
    this.query(value);
    clearTimeout(this.debounce);
    // A barcode is searched when complete (Enter or a scanner's own Enter), not while typing digits.
    if (/^[\d\s-]*$/.test(value)) return;
    this.debounce = setTimeout(() => this.search(), 350);
  }

  search() {
    const q = this.query().trim();
    if (!q) return;

    this.searching = true;
    this.barcode = null;
    const asked = q;

    lookup({ type: this.type.id(), q })
      .then((response) => {
        if (this.query().trim() !== asked) return;
        this.results = response.results || [];
        this.barcode = response.barcode || null;
        this.code = response.barcode ? response.barcode.code : null;
      })
      .catch(() => (this.results = []))
      .finally(() => {
        this.searching = false;
        m.redraw();
      });
  }

  toggleScan() {
    if (this.scanning) {
      this.scanning.stop();
      this.scanning = null;
      return;
    }
    this.scanning = { stop() {} };
  }

  startScan(video) {
    const session = scan(video);
    this.scanning = session;
    session.result
      .then((code) => {
        if (!code) return;
        this.scanning = null;
        this.query(code);
        this.search();
      })
      .catch(() => {
        this.scanning = null;
        this.alertAttrs = { type: 'error', content: t('item_edit.camera_error') };
      })
      .finally(() => m.redraw());
  }

  manual() {
    if (this.barcode) {
      this.code = this.barcode.code;
      if (this.barcode.title && !this.itemTitle()) this.itemTitle(this.barcode.title);
      Object.assign(this.fields, this.barcode.fields || {});
    } else if (this.query().trim() && !/^\d/.test(this.query())) {
      this.itemTitle(this.itemTitle() || this.query().trim());
    }
    this.step = 'details';
  }

  pick(result) {
    this.resolving = true;
    const found = result.workId ? Promise.resolve(result.workId) : lookup({ source: result.source, id: result.id }).then((r) => r.workId);

    found
      .then((workId) => app.store.find('curio-works', workId))
      .then((work) => {
        this.work = work;
        this.itemTitle(work.originalTitle());
        this.subtitle(work.creator() || '');
        this.year(work.year() ? String(work.year()) : '');
        if (this.barcode) Object.assign(this.fields, this.barcode.fields || {});
        this.prefillFromFacts(work.facts());
        this.step = 'details';
      })
      .catch(() => (this.alertAttrs = { type: 'error', content: t('item_edit.lookup_failed') }))
      .finally(() => {
        this.resolving = false;
        m.redraw();
      });
  }

  /** A game found on IGDB names its platforms: when only one of them is an option here, it is chosen. */
  prefillFromFacts(facts) {
    if (!facts || !facts.platforms) return;
    (this.type.fields() || [])
      .filter((f) => f.kind === 'select' && f.key === 'platform' && !this.fields[f.key])
      .forEach((f) => {
        const key = onlyOption(f, facts.platforms);
        if (key) this.fields[f.key] = key;
      });
  }

  // ── Details & photos ───────────────────────────────────────────────────
  fieldText(field, value) {
    if (field.kind === 'bool') return label(field.labels);
    if (field.kind === 'select') {
      const option = (field.options || []).find((o) => o.key === value);
      return option ? label(option.labels) : value;
    }
    return value;
  }

  detailsStep() {
    const item = this.attrs.item;
    const photos = item ? item.photos() || [] : [];
    const cover = this.work ? this.work.coverUrl() : null;
    const room = MAX_PHOTOS - photos.length - this.queued.length;

    return (
      <div className="CurioDetails">
        <div className="CurioDetails-media">
          <div className={'CurioDetails-cover CurioDetails-cover--' + this.type.shape()}>
            {photos[0] || this.queued[0] ? (
              <img src={photos[0] ? photos[0].small : this.queued[0].url} alt="" />
            ) : cover ? (
              <img src={cover} alt="" />
            ) : (
              <Icon name={this.type.icon()} />
            )}
            {cover && !photos.length && !this.queued.length ? (
              <span className="CurioDetails-coverLabel">{t('item_edit.cover_looked_up')}</span>
            ) : null}
          </div>
          <div className="CurioThumbs">
            {photos.map((p, i) => (
              <span className="CurioThumb">
                <img src={p.small} alt="" />
                <button type="button" className="CurioThumb-remove" aria-label={text('item_edit.remove_photo')} onclick={() => this.removePhoto(i)}>
                  <Icon name="fas fa-xmark" />
                </button>
              </span>
            ))}
            {this.queued.map((q, i) => (
              <span className="CurioThumb">
                <img src={q.url} alt="" />
                <button
                  type="button"
                  className="CurioThumb-remove"
                  aria-label={text('item_edit.remove_photo')}
                  onclick={() => {
                    URL.revokeObjectURL(q.url);
                    this.queued.splice(i, 1);
                  }}
                >
                  <Icon name="fas fa-xmark" />
                </button>
              </span>
            ))}
          </div>
          {room > 0 ? (
            <label className="CurioDrop" ondragover={(e) => e.preventDefault()} ondrop={(e) => this.dropped(e)}>
              <input type="file" accept="image/jpeg,image/png,image/webp" multiple onchange={(e) => this.chosen(e.target.files)} />
              <Icon name="fas fa-camera" />
              <span>{t('item_edit.add_photos')}</span>
              <small>{t('item_edit.photos_hint', { count: room })}</small>
            </label>
          ) : null}
        </div>

        <div className="CurioDetails-fields" onpaste={(e) => this.pasted(e)}>
          <div className="Form-group">
            <label for="curio-title">{t('item_edit.title')}</label>
            <input id="curio-title" name="title" className="FormControl" maxlength="255" bidi={this.itemTitle} />
            {this.work && this.work.languages() > 1 ? (
              <small className="CurioHint">
                <Icon name="fas fa-language" /> {t('item_edit.translated_hint', { count: this.work.languages() })}
              </small>
            ) : null}
          </div>
          <div className="CurioPair">
            <div className="Form-group">
              <label for="curio-subtitle">{t('item_edit.subtitle')}</label>
              <input id="curio-subtitle" name="subtitle" className="FormControl" maxlength="255" bidi={this.subtitle} />
            </div>
            <div className="Form-group CurioPair-small">
              <label for="curio-year">{t('item_edit.year')}</label>
              <input id="curio-year" name="year" className="FormControl" inputmode="numeric" maxlength="4" bidi={this.year} />
            </div>
          </div>

          {this.typeFields()}

          <div className="Form-group">
            <label>{t('item_edit.status')}</label>
            <div className="ButtonGroup CurioStatusPick" role="radiogroup">
              {STATUSES.map((s) => (
                <Button
                  type="button"
                  role="radio"
                  aria-checked={this.status() === s ? 'true' : 'false'}
                  className={'Button' + (this.status() === s ? ' Button--primary' : '')}
                  onclick={() => this.status(s)}
                >
                  {t('status.' + s)}
                </Button>
              ))}
            </div>
          </div>

          {this.type.progress() && this.status() !== 'wishlist' ? (
            <div className="Form-group">
              <label for="curio-progress">{t('progress.label')}</label>
              <Select
                id="curio-progress"
                value={this.progress()}
                onchange={this.progress}
                options={{ '': text('progress.none'), ...Object.fromEntries(PROGRESS.map((p) => [p, text('progress.' + p)])) }}
              />
            </div>
          ) : null}

          {this.status() === 'wishlist' ? null : (
            <div className="CurioPair">
              <div className="Form-group">
                <label for="curio-condition">{t('item_edit.condition')}</label>
                <Select
                  id="curio-condition"
                  value={this.condition()}
                  onchange={this.condition}
                  options={{ '': text('item_edit.condition_none'), ...Object.fromEntries(CONDITIONS.map((c) => [c, text('condition.' + c)])) }}
                />
              </div>
              <div className="Form-group">
                <label for="curio-acquired">{t('item_edit.acquired')}</label>
                <input
                  id="curio-acquired"
                  name="acquiredOn"
                  type="date"
                  className="FormControl"
                  max={new Date().toISOString().slice(0, 10)}
                  bidi={this.acquiredOn}
                />
              </div>
            </div>
          )}

          <div className="Form-group">
            <label for="curio-notes">{t('item_edit.notes')}</label>
            <textarea
              id="curio-notes"
              name="notes"
              className="FormControl"
              rows="3"
              maxlength="2000"
              bidi={this.notes}
              placeholder={text('item_edit.notes_placeholder')}
            />
          </div>

          {this.status() === 'wishlist' ? null : (
            <div className="Form-group">
              <label for="curio-price">
                {t('item_edit.price')}{' '}
                <span className="CurioPrivate">
                  <Icon name="fas fa-lock" /> {t('item_edit.price_private')}
                </span>
              </label>
              <div className="CurioPrice">
                <input id="curio-price" name="pricePaid" className="FormControl" inputmode="decimal" bidi={this.pricePaid} />
                <Select value={this.priceCurrency()} onchange={this.priceCurrency} options={Object.fromEntries(CURRENCIES.map((c) => [c, c]))} />
              </div>
            </div>
          )}

          {this.status() === 'wishlist' ? null : (
            <div className="Form-group">
              <label>{t('item_edit.rating')}</label>
              <div className="CurioRating">
                <Stars value={this.rating()} onchange={this.rating} label={text('item_edit.rating')} />
                <small>{this.rating() ? t('item_edit.rating_value', { stars: this.rating() / 2 }) : t('item_edit.rating_none')}</small>
              </div>
            </div>
          )}

          <div className="Form-group">
            <Switch state={this.favourite()} onchange={this.favourite}>
              <Icon name="fas fa-star" /> {t('item_edit.favourite')}
            </Switch>
          </div>

          <div className="CurioModalFooter">
            {this.attrs.item ? (
              <Button type="button" className="Button Button--danger" icon="fas fa-trash" onclick={() => this.deleteItem()}>
                {t('item_edit.delete')}
              </Button>
            ) : (
              <span />
            )}
            <Button type="submit" className="Button Button--primary" icon="fas fa-check" loading={this.loading} disabled={!this.itemTitle().trim()}>
              {this.attrs.item ? t('item_edit.save') : t('item_edit.add')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  typeFields() {
    const fields = this.type.fields() || [];
    if (!fields.length) return null;

    return (
      <div className="CurioFieldGrid">
        {fields.map((f) => {
          const id = 'curio-field-' + f.key;
          const value = this.fields[f.key];
          const set = (v) => {
            if (v === '' || v === null || v === false) delete this.fields[f.key];
            else this.fields[f.key] = v;
          };

          if (f.kind === 'bool') {
            return (
              <div className="Form-group CurioFieldGrid-bool">
                <Switch state={!!value} onchange={set}>
                  {label(f.labels)}
                </Switch>
              </div>
            );
          }

          return (
            <div className="Form-group">
              <label for={id}>{label(f.labels)}</label>
              {f.kind === 'select' ? (
                <Select
                  id={id}
                  value={value || ''}
                  onchange={set}
                  options={{ '': text('item_edit.choose'), ...Object.fromEntries((f.options || []).map((o) => [o.key, pick(o.labels, locale())])) }}
                />
              ) : (
                <input
                  id={id}
                  className="FormControl"
                  type={f.kind === 'number' ? 'number' : f.kind === 'date' ? 'date' : 'text'}
                  value={value || ''}
                  maxlength="200"
                  oninput={(e) => set(e.target.value)}
                />
              )}
            </div>
          );
        })}
      </div>
    );
  }

  chosen(files) {
    Array.from(files || [])
      .filter((f) => /^image\/(jpeg|png|webp)$/.test(f.type))
      .slice(0, MAX_PHOTOS - (this.attrs.item?.photos()?.length || 0) - this.queued.length)
      .forEach((file) => this.queued.push({ file, url: URL.createObjectURL(file) }));
    m.redraw();
  }

  dropped(e) {
    e.preventDefault();
    this.chosen(e.dataTransfer.files);
  }

  pasted(e) {
    const files = Array.from(e.clipboardData?.files || []);
    if (files.length) {
      e.preventDefault();
      this.chosen(files);
    }
  }

  removePhoto(index) {
    const item = this.attrs.item;
    app.request({ method: 'DELETE', url: `${app.forum.attribute('apiUrl')}/curio-items/${item.id()}/photos/${index}` }).then((payload) => {
      app.store.pushPayload(payload);
      m.redraw();
    });
  }

  uploadQueued(item) {
    return this.queued.reduce(
      (chain, q) =>
        chain.then(() => {
          const body = new FormData();
          body.append('photo', q.file);
          return app
            .request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/curio-items/${item.id()}/photos`, serialize: (raw) => raw, body })
            .then((payload) => app.store.pushPayload(payload));
        }),
      Promise.resolve()
    );
  }

  onsubmit(e) {
    e.preventDefault();
    const item = this.attrs.item;
    const wanted = this.status() === 'wishlist';
    const price = this.pricePaid().trim().replace(',', '.');

    const attrs = {
      title: this.itemTitle().trim(),
      subtitle: this.subtitle().trim() || null,
      year: /^\d{1,4}$/.test(this.year().trim()) ? Number(this.year().trim()) : null,
      status: this.status(),
      condition: wanted ? null : this.condition() || null,
      acquiredOn: wanted ? null : this.acquiredOn() || null,
      fields: this.fields,
      notes: this.notes().trim() || null,
      favourite: this.favourite(),
      rating: wanted ? null : this.rating(),
      progress: wanted ? null : this.type.progress() ? this.progress() || null : item?.progress() || null,
      pricePaid: wanted || price === '' || isNaN(Number(price)) ? null : Number(price),
      priceCurrency: wanted || price === '' ? null : this.priceCurrency(),
    };

    if (!item) {
      attrs.relationships = { collection: this.collection };
      if (this.work) attrs.relationships.work = this.work;
      if (this.code) attrs.barcode = this.code;
    }

    this.loading = true;
    (item || app.store.createRecord('curio-items'))
      .save(attrs)
      .then((saved) => this.uploadQueued(saved).then(() => saved))
      .then((saved) => closeThen(this.attrs.onsave, saved))
      .catch((error) => {
        this.loading = false;
        this.onerror(error);
      });
  }

  deleteItem() {
    const item = this.attrs.item;
    if (!confirm(text('item_edit.delete_confirm', { title: item.title() }))) return;
    this.loading = true;
    item
      .delete()
      .then(() => closeThen(this.attrs.ondelete, item))
      .catch((error) => {
        this.loading = false;
        this.onerror(error);
      });
  }
}
