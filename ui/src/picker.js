/**
 * <tansu-picker>: a form field that opens the explorer in a modal and keeps the chosen file.
 *
 * It is a form-associated custom element: with a `name` it submits its value like an <input>.
 *
 * Attributes
 *   name, required, disabled  as on <input>
 *   value       the current value (a path, or a URL with value-type="url")
 *   value-type  "path" (default) or "url": which field of the chosen file becomes the value
 *   src         URL of the current image, for the preview (a host that stores paths knows the URL)
 *   endpoint, owner, csrf-token, lang   passed to the explorer
 *   label       text of the button (default "Choose an image")
 *
 * Events
 *   change, input       the value changed (also when removed)
 *   tansu-select        detail: { file } a file was chosen (bubbles from the explorer)
 */
import { adoptStyles, h, replace } from './dom.js';
import { languageOf, translator } from './i18n.js';
import { pickerStyles } from './styles.js';

export class TansuPicker extends HTMLElement {
  static formAssociated = true;

  static observedAttributes = ['value', 'src', 'disabled', 'required', 'lang', 'label'];

  #internals = null;
  #els = {};
  #file = null;
  #default = null;

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    adoptStyles(this.shadowRoot, pickerStyles);
    this.#internals = typeof this.attachInternals === 'function' ? this.attachInternals() : null;
  }

  get value() {
    return this.getAttribute('value') || '';
  }

  set value(value) {
    if (value) this.setAttribute('value', value);
    else this.removeAttribute('value');
  }

  /** The chosen file as the API returned it, or null when the value came from the page. */
  get file() {
    return this.#file;
  }

  get form() {
    return this.#internals?.form ?? null;
  }

  get name() {
    return this.getAttribute('name');
  }

  connectedCallback() {
    // What a form reset goes back to: the value and preview the page rendered
    this.#default ??= { value: this.value, src: this.getAttribute('src') };
    if (!this.#els.root) this.#build();
    this.#sync();
  }

  attributeChangedCallback() {
    if (this.#els.root) {
      if (this.#els.lang !== languageOf(this)) this.#build();
      this.#sync();
    }
  }

  /** Called by the form when it is reset. */
  formResetCallback() {
    this.#file = null;
    if (this.#default?.src) this.setAttribute('src', this.#default.src);
    else this.removeAttribute('src');
    this.value = this.#default?.value || '';
  }

  formDisabledCallback(disabled) {
    this.toggleAttribute('disabled', disabled);
  }

  /** Opens the explorer. */
  open() {
    if (this.hasAttribute('disabled')) return;
    const explorer = h('tansu-explorer', {
      selectable: true,
      endpoint: this.getAttribute('endpoint'),
      owner: this.getAttribute('owner'),
      'csrf-token': this.getAttribute('csrf-token'),
      lang: this.getAttribute('lang'),
      path: this.#folderOfValue(),
    });
    explorer.addEventListener('tansu-select', (event) => {
      this.#choose(event.detail.file);
      this.#els.modal.close();
    });
    replace(this.#els.body, explorer);
    this.#els.modal.showModal();
  }

  /** Clears the value. */
  clear() {
    this.#file = null;
    this.removeAttribute('src');
    this.value = '';
    this.#emit();
  }

  #choose(file) {
    this.#file = file;
    this.setAttribute('src', file.url);
    this.value = this.getAttribute('value-type') === 'url' ? file.url : file.path;
    this.#emit();
  }

  #emit() {
    this.dispatchEvent(new Event('input', { bubbles: true, composed: true }));
    this.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /** The folder to start in: the folder of the current path value, when the value is a path. */
  #folderOfValue() {
    const value = this.value;
    if (this.getAttribute('value-type') === 'url' || !value.startsWith('/')) return '/';
    const parent = value.slice(0, value.lastIndexOf('/'));
    return parent || '/';
  }

  #build() {
    const lang = languageOf(this);
    const t = translator(lang);
    const body = h('div', { class: 'body' });
    const modal = h('dialog', { class: 'modal', part: 'modal' },
      h('header', {},
        h('h2', {}, this.getAttribute('label') || t('pick')),
        h('button', { type: 'button', onclick: () => modal.close() }, t('close'))),
      body);
    // Do not keep the explorer around: it loads again with fresh data next time
    modal.addEventListener('close', () => replace(body));
    const preview = h('div', { class: 'preview', part: 'preview' });
    const file = h('div', { class: 'file', part: 'file' });
    const choose = h('button', { type: 'button', class: 'primary', part: 'choose', onclick: () => this.open() });
    const remove = h('button', { type: 'button', part: 'remove', onclick: () => this.clear() }, t('remove'));
    const root = h('div', { class: 'picker', part: 'picker' }, preview, h('div', { class: 'side' }, choose, remove, file));
    replace(this.shadowRoot, root, modal);
    this.#els = { root, preview, file, choose, remove, modal, body, lang };
  }

  #sync() {
    const t = translator(this.#els.lang);
    const value = this.value;
    const src = this.getAttribute('src');
    const disabled = this.hasAttribute('disabled');
    replace(this.#els.preview, src ? h('img', { src, alt: '' }) : t('noImage'));
    replace(this.#els.file, value ? value.slice(value.lastIndexOf('/') + 1) : '');
    replace(this.#els.choose, this.getAttribute('label') || t('pick'));
    this.#els.choose.disabled = disabled;
    this.#els.remove.disabled = disabled;
    this.#els.remove.hidden = !value;
    if (this.#internals) {
      this.#internals.setFormValue(value || null);
      if (this.hasAttribute('required') && !value) {
        this.#internals.setValidity({ valueMissing: true }, t('pick'), this.#els.choose);
      } else {
        this.#internals.setValidity({});
      }
    }
  }
}
