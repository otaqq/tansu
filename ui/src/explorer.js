/**
 * <tansu-explorer>: folder tree, grid of files, upload by button or drag and drop, preview and file info.
 *
 * Attributes
 *   endpoint    base URL of the API (required)
 *   owner       sent as the `owner` query parameter
 *   csrf-token  sent as X-CSRF-TOKEN; defaults to <meta name="csrf-token">
 *   path        the folder to show; updated as the user moves around
 *   selectable  shows a "Choose" button; choosing fires `tansu-select`
 *   lang        language of the UI (else the closest [lang], else the document's)
 *
 * Events (bubble, composed)
 *   tansu-select  detail: { file }                 a file was chosen (selectable only)
 *   tansu-change  detail: { action, path, item }   something was created, uploaded, renamed or deleted
 *   tansu-error   detail: { error }                an operation failed (the message is also shown)
 */
import { TansuClient, TansuError } from './api.js';
import { adoptStyles, h, replace } from './dom.js';
import { formatDate, formatSize, languageOf, translator } from './i18n.js';
import { explorerStyles } from './styles.js';

const SVG_NS = 'http://www.w3.org/2000/svg';
const FOLDER_ICON = 'M2 5.5A1.5 1.5 0 0 1 3.5 4h4.38a1.5 1.5 0 0 1 1.06.44L10.5 6h10A1.5 1.5 0 0 1 22 7.5v11a1.5 1.5 0 0 1-1.5 1.5h-17A1.5 1.5 0 0 1 2 18.5z';
const FILE_ICON = 'M6 2h8l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V8h4.5z';

function icon(pathData) {
  const svg = document.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS(SVG_NS, 'path');
  path.setAttribute('d', pathData);
  svg.append(path);
  return svg;
}

function parentOf(path) {
  const parent = path.slice(0, path.lastIndexOf('/'));
  return parent === '' ? '/' : parent;
}

function joinPath(folder, name) {
  return `${folder === '/' ? '' : folder}/${name}`;
}

export class TansuExplorer extends HTMLElement {
  static observedAttributes = ['endpoint', 'owner', 'csrf-token', 'path', 'selectable', 'lang'];

  #client = null;
  #info = null;
  #tree = null;
  #listing = null;
  #path = '/';
  #selected = null;
  #uploads = [];
  #status = null;
  #loading = false;
  #dragDepth = 0;
  #connected = false;
  #els = {};

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    adoptStyles(this.shadowRoot, explorerStyles);
  }

  /** The folder shown. */
  get path() {
    return this.#path;
  }

  set path(value) {
    this.setAttribute('path', value);
  }

  /** The selected file or folder, or null. */
  get selected() {
    return this.#selected;
  }

  /** The API client, for hosts that want to call the API themselves. */
  get client() {
    return this.#client;
  }

  connectedCallback() {
    this.#connected = true;
    if (!this.#els.layout) this.#build();
    this.#reconnect();
  }

  disconnectedCallback() {
    this.#connected = false;
  }

  attributeChangedCallback(name, oldValue, newValue) {
    if (!this.#connected || oldValue === newValue) return;
    if (name === 'path') {
      if (newValue && newValue !== this.#path) this.open(newValue);
    } else if (name === 'lang') {
      this.#build();
      this.#render();
    } else if (name === 'selectable') {
      this.#renderDetails();
    } else {
      this.#reconnect();
    }
  }

  /** Shows a folder. */
  async open(path) {
    this.#path = path || '/';
    this.#selected = null;
    if (this.getAttribute('path') !== this.#path) this.setAttribute('path', this.#path);
    await this.#loadFolder();
  }

  /** Loads everything again from the server. */
  async refresh() {
    await Promise.all([this.#loadInfo(), this.#loadTree(), this.#loadFolder()]);
  }

  get #t() {
    return translator(languageOf(this));
  }

  get #lang() {
    return languageOf(this);
  }

  get #canManage() {
    return Boolean(this.#info?.can?.manage);
  }

  #reconnect() {
    const endpoint = this.getAttribute('endpoint');
    if (!endpoint) return;
    const csrfToken = this.getAttribute('csrf-token') ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    this.#client = new TansuClient({ endpoint, owner: this.getAttribute('owner') || '', csrfToken });
    this.#path = this.getAttribute('path') || '/';
    this.#selected = null;
    this.refresh();
  }

  // Answers to an older client (endpoint or owner changed since) or for another folder are dropped,
  // so a slow response cannot overwrite a newer one.
  async #loadInfo() {
    const client = this.#client;
    try {
      const info = await client.info();
      if (client !== this.#client) return;
      this.#info = info;
    } catch (error) {
      if (client !== this.#client) return;
      this.#fail(error);
    }
    this.#renderToolbar();
    this.#renderUsage();
    this.#renderDetails();
  }

  async #loadTree() {
    const client = this.#client;
    try {
      const { root } = await client.tree();
      if (client !== this.#client) return;
      this.#tree = root;
    } catch (error) {
      if (client !== this.#client) return;
      this.#tree = null;
      this.#fail(error);
    }
    this.#renderTree();
  }

  async #loadFolder() {
    const client = this.#client;
    const path = this.#path;
    const stale = () => client !== this.#client || path !== this.#path;
    this.#loading = true;
    this.#renderGrid();
    try {
      const listing = await client.folder(path);
      if (stale()) return;
      this.#listing = listing;
    } catch (error) {
      if (stale()) return;
      if (error instanceof TansuError && error.code === 'not_found' && this.#path !== '/') {
        this.#path = '/';
        this.setAttribute('path', '/');
        this.#loading = false;
        return this.#loadFolder();
      }
      this.#listing = null;
      this.#fail(error);
    }
    this.#loading = false;
    if (this.#selected && !this.#items().some((item) => item.path === this.#selected.path)) this.#selected = null;
    this.#render();
  }

  #items() {
    return this.#listing ? [...this.#listing.folders, ...this.#listing.files] : [];
  }

  #build() {
    const t = this.#t;
    const input = h('input', { type: 'file', multiple: true, hidden: true, onchange: (event) => {
      this.#upload([...event.target.files]);
      event.target.value = '';
    } });
    this.#els = {
      input,
      tree: h('nav', { class: 'tree', part: 'tree', 'aria-label': t('root') }),
      crumbs: h('div', { class: 'crumbs' }),
      buttons: h('div', { class: 'actions' }),
      grid: h('div', { class: 'grid', part: 'grid', role: 'listbox', 'aria-label': t('root') }),
      uploads: h('ul', { class: 'uploads', part: 'uploads' }),
      status: h('div', { class: 'status', role: 'status', 'aria-live': 'polite' }),
      usage: h('div', { class: 'usage', part: 'usage' }),
      details: h('aside', { class: 'details', part: 'details' }),
      drop: h('div', { class: 'drop', hidden: true }, t('dropHere')),
      dialog: h('dialog', { part: 'dialog' }),
    };
    const main = h('div', { class: 'main' },
      h('div', { class: 'toolbar', part: 'toolbar' }, this.#els.crumbs, this.#els.buttons),
      this.#els.grid, this.#els.uploads, this.#els.status, this.#els.usage, this.#els.drop);
    main.addEventListener('dragenter', (event) => this.#onDrag(event, 1));
    main.addEventListener('dragleave', (event) => this.#onDrag(event, -1));
    main.addEventListener('dragover', (event) => {
      if (this.#canManage && event.dataTransfer?.types.includes('Files')) event.preventDefault();
    });
    main.addEventListener('drop', (event) => {
      if (!this.#canManage) return;
      event.preventDefault();
      this.#dragDepth = 0;
      this.#els.drop.hidden = true;
      this.#upload([...(event.dataTransfer?.files || [])]);
    });
    this.#els.layout = h('div', { class: 'layout', part: 'layout' }, this.#els.tree, main, this.#els.details);
    replace(this.shadowRoot, this.#els.layout, input, this.#els.dialog);
  }

  #onDrag(event, step) {
    if (!this.#canManage || !event.dataTransfer?.types.includes('Files')) return;
    event.preventDefault();
    this.#dragDepth = Math.max(0, this.#dragDepth + step);
    this.#els.drop.hidden = this.#dragDepth === 0;
  }

  #render() {
    this.#renderTree();
    this.#renderToolbar();
    this.#renderGrid();
    this.#renderDetails();
    this.#renderUploads();
    this.#renderUsage();
    this.#renderStatus();
  }

  #renderTree() {
    const t = this.#t;
    const node = (folder) => h('li', {},
      h('button', {
        type: 'button',
        'aria-current': folder.path === this.#path ? 'true' : 'false',
        title: folder.path,
        onclick: () => this.open(folder.path),
      }, folder.path === '/' ? t('root') : folder.name),
      folder.folders.length ? h('ul', {}, folder.folders.map(node)) : null);
    replace(this.#els.tree, this.#tree ? h('ul', {}, node(this.#tree)) : null);
  }

  #renderToolbar() {
    const t = this.#t;
    const segments = this.#path === '/' ? [] : this.#path.slice(1).split('/');
    const crumbs = [h('button', { type: 'button', onclick: () => this.open('/') }, t('root'))];
    segments.forEach((name, index) => {
      const path = `/${segments.slice(0, index + 1).join('/')}`;
      crumbs.push(h('span', { 'aria-hidden': 'true' }, '/'));
      crumbs.push(index === segments.length - 1
        ? h('strong', { 'aria-current': 'page' }, name)
        : h('button', { type: 'button', onclick: () => this.open(path) }, name));
    });
    replace(this.#els.crumbs, crumbs);

    if (this.#info) {
      this.#els.input.accept = this.#info.accept
        .flatMap((type) => [type.type, ...type.extensions.map((extension) => `.${extension}`)]).join(',');
    }
    replace(this.#els.buttons, this.#canManage ? [
      h('button', { type: 'button', onclick: () => this.#createFolder() }, t('newFolder')),
      h('button', { type: 'button', class: 'primary', onclick: () => this.#els.input.click() }, t('upload')),
    ] : null);
  }

  #renderGrid() {
    const t = this.#t;
    if (this.#loading && !this.#listing) {
      replace(this.#els.grid, h('p', { class: 'message' }, t('loading')));
      return;
    }
    const items = this.#items();
    if (!items.length) {
      replace(this.#els.grid, h('p', { class: 'message' }, this.#listing ? t('empty') : ''));
      return;
    }
    replace(this.#els.grid, items.map((item) => {
      const isFolder = !('size' in item);
      const thumb = isFolder
        ? icon(FOLDER_ICON)
        : item.type.startsWith('image/')
          ? h('img', { src: item.url, alt: '', loading: 'lazy', decoding: 'async' })
          : icon(FILE_ICON);
      const tile = h('button', {
        type: 'button',
        class: 'tile',
        part: 'tile',
        role: 'option',
        title: item.name,
        'aria-selected': this.#selected?.path === item.path ? 'true' : 'false',
        dataset: { path: item.path },
        onclick: () => this.#select(item),
        ondblclick: () => this.#activate(item),
        onkeydown: (event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            this.#activate(item);
          } else if (event.key === 'Delete' && this.#canManage) {
            this.#delete(item);
          } else if (event.key === 'F2' && this.#canManage) {
            this.#rename(item);
          }
        },
      }, h('span', { class: 'thumb' }, thumb), h('span', { class: 'name' }, item.name));
      return tile;
    }));
  }

  #renderDetails() {
    const t = this.#t;
    const item = this.#selected;
    if (!item) {
      replace(this.#els.details, h('p', { class: 'hint' }, t('nothingSelected')));
      return;
    }
    const isFolder = !('size' in item);
    const manage = this.#canManage ? [
      h('button', { type: 'button', onclick: () => this.#rename(item) }, t('rename')),
      h('button', { type: 'button', class: 'danger', onclick: () => this.#delete(item) }, t('delete')),
    ] : [];
    if (isFolder) {
      replace(this.#els.details,
        h('div', { class: 'preview' }, icon(FOLDER_ICON)),
        h('h3', {}, item.name),
        h('div', { class: 'actions' }, h('button', { type: 'button', onclick: () => this.open(item.path) }, t('open')), manage));
      return;
    }
    const lang = this.#lang;
    replace(this.#els.details,
      h('div', { class: 'preview', part: 'preview' },
        item.type.startsWith('image/') ? h('img', { src: item.url, alt: item.name }) : icon(FILE_ICON)),
      h('h3', {}, item.name),
      h('dl', {},
        h('dt', {}, t('size')), h('dd', {}, formatSize(item.size, lang)),
        h('dt', {}, t('type')), h('dd', {}, item.type),
        h('dt', {}, t('modified')), h('dd', {}, formatDate(item.modified, lang))),
      h('div', { class: 'actions' },
        this.hasAttribute('selectable') ? h('button', { type: 'button', class: 'primary', onclick: () => this.#choose(item) }, t('choose')) : null,
        h('a', { href: item.url, target: '_blank', rel: 'noopener', class: 'button', part: 'link' }, t('open')),
        manage));
  }

  #renderUploads() {
    const t = this.#t;
    replace(this.#els.uploads, this.#uploads.map((upload) => h('li', {},
      h('span', {}, upload.name),
      h('span', { class: upload.error ? 'error' : '' }, upload.error ? this.#message(upload.error) : upload.done ? t('uploaded') : `${Math.round(upload.progress * 100)}%`),
      upload.done || upload.error ? null : h('progress', { max: 1, value: upload.progress }))));
  }

  #renderUsage() {
    const info = this.#info;
    if (!info) {
      replace(this.#els.usage);
      return;
    }
    const t = this.#t;
    const lang = this.#lang;
    const { usage, limits } = info;
    replace(this.#els.usage, limits.total_size !== null || limits.files !== null
      ? t('usage', {
        size: formatSize(usage.size, lang),
        total: limits.total_size === null ? '∞' : formatSize(limits.total_size, lang),
        files: usage.files,
        maxFiles: limits.files === null ? '∞' : limits.files,
      })
      : t('usageNoLimit', { size: formatSize(usage.size, lang), files: usage.files }));
  }

  #renderStatus() {
    replace(this.#els.status, this.#status ? h('span', { class: 'error' }, this.#status) : null);
  }

  #select(item) {
    this.#selected = item;
    for (const tile of this.#els.grid.querySelectorAll('.tile')) {
      tile.setAttribute('aria-selected', tile.dataset.path === item.path ? 'true' : 'false');
    }
    this.#renderDetails();
  }

  #activate(item) {
    if (!('size' in item)) {
      this.open(item.path);
    } else if (this.hasAttribute('selectable')) {
      this.#choose(item);
    } else {
      this.#select(item);
    }
  }

  #choose(file) {
    this.dispatchEvent(new CustomEvent('tansu-select', { detail: { file }, bubbles: true, composed: true }));
  }

  #changed(action, path, item = null) {
    this.dispatchEvent(new CustomEvent('tansu-change', { detail: { action, path, item }, bubbles: true, composed: true }));
  }

  #message(error) {
    const t = this.#t;
    if (!(error instanceof TansuError)) return t('error.other', { message: String(error?.message || error) });
    const key = `error.${error.code}`;
    const text = t(key, {
      max: this.#info ? formatSize(this.#info.limits.file_size, this.#lang) : '',
      types: this.#info ? this.#info.accept.map((type) => type.extensions[0].toUpperCase()).join(', ') : '',
    });
    return text === key ? t('error.other', { message: error.message }) : text;
  }

  #fail(error) {
    this.#status = this.#message(error);
    this.#renderStatus();
    this.dispatchEvent(new CustomEvent('tansu-error', { detail: { error }, bubbles: true, composed: true }));
  }

  async #run(action) {
    this.#status = null;
    this.#renderStatus();
    try {
      return await action();
    } catch (error) {
      this.#fail(error);
      return undefined;
    }
  }

  async #createFolder() {
    const name = await this.#ask(this.#t('newFolder'), this.#t('folderName'), '');
    if (name === null) return;
    const folder = await this.#run(() => this.#client.createFolder(this.#path, name));
    if (!folder) return;
    this.#changed('folder-created', folder.path, folder);
    await Promise.all([this.#loadTree(), this.#loadFolder()]);
  }

  async #rename(item) {
    const isFolder = !('size' in item);
    const extension = isFolder ? '' : item.name.slice(item.name.lastIndexOf('.'));
    const current = isFolder || !item.name.includes('.') ? item.name : item.name.slice(0, -extension.length);
    const name = await this.#ask(this.#t('rename'), this.#t('newName'), current, isFolder ? '' : extension);
    if (name === null || name === current) return;
    const renamed = await this.#run(() => (isFolder ? this.#client.renameFolder(item.path, name) : this.#client.renameFile(item.path, name)));
    if (!renamed) return;
    this.#changed(isFolder ? 'folder-renamed' : 'file-renamed', item.path, renamed);
    this.#selected = renamed;
    await Promise.all(isFolder ? [this.#loadTree(), this.#loadFolder()] : [this.#loadFolder()]);
  }

  async #delete(item) {
    const isFolder = !('size' in item);
    const t = this.#t;
    if (!(await this.#confirm(t(isFolder ? 'confirmDeleteFolder' : 'confirmDeleteFile', { name: item.name })))) return;
    const done = await this.#run(async () => {
      await (isFolder ? this.#client.deleteFolder(item.path) : this.#client.deleteFile(item.path));
      return true;
    });
    if (!done) return;
    this.#changed(isFolder ? 'folder-deleted' : 'file-deleted', item.path);
    this.#selected = null;
    await Promise.all([this.#loadInfo(), isFolder ? this.#loadTree() : null, this.#loadFolder()]);
  }

  /** Uploads files one after another into the current folder. */
  async #upload(files) {
    if (!files.length || !this.#canManage) return;
    const folder = this.#path;
    const queue = files.map((file) => ({ file, name: file.name, progress: 0, done: false, error: null }));
    this.#uploads = [...this.#uploads.filter((upload) => !upload.done && !upload.error), ...queue];
    this.#status = null;
    this.#render();
    for (const upload of queue) {
      upload.error = this.#precheck(upload.file);
      if (upload.error) {
        this.#renderUploads();
        continue;
      }
      try {
        const stored = await this.#client.upload(folder, upload.file, {
          onProgress: (fraction) => {
            upload.progress = fraction;
            this.#renderUploads();
          },
        });
        upload.done = true;
        upload.progress = 1;
        this.#changed('file-uploaded', stored.path, stored);
      } catch (error) {
        upload.error = error;
        this.dispatchEvent(new CustomEvent('tansu-error', { detail: { error }, bubbles: true, composed: true }));
      }
      this.#renderUploads();
    }
    await Promise.all([this.#loadInfo(), folder === this.#path ? this.#loadFolder() : null]);
    setTimeout(() => {
      this.#uploads = this.#uploads.filter((upload) => !upload.done);
      this.#renderUploads();
    }, 3000);
  }

  /** Checks size and type before sending; the server checks again by content. */
  #precheck(file) {
    if (!this.#info) return null;
    if (file.size > this.#info.limits.file_size) return new TansuError('file_too_large', 'The file is too large.');
    const extension = file.name.includes('.') ? file.name.slice(file.name.lastIndexOf('.') + 1).toLowerCase() : '';
    const accepted = this.#info.accept.some((type) => type.type === file.type || type.extensions.includes(extension));
    return accepted ? null : new TansuError('type_not_allowed', 'This type of file is not allowed.');
  }

  /** Asks for a name in a modal dialog; resolves to the text, or null when cancelled. */
  #ask(title, label, value, suffix = '') {
    const t = this.#t;
    const input = h('input', { type: 'text', required: true, value, 'aria-label': label });
    input.value = value;
    return this.#dialog((close) => h('form', { method: 'dialog', onsubmit: (event) => {
      event.preventDefault();
      if (input.value.trim()) close(input.value.trim());
    } },
    h('strong', {}, title),
    h('label', {}, label),
    suffix ? h('div', { class: 'actions', style: 'justify-content: stretch; align-items: center; flex-wrap: nowrap' }, input, h('span', {}, suffix)) : input,
    h('div', { class: 'actions' },
      h('button', { type: 'button', onclick: () => close(null) }, t('cancel')),
      h('button', { type: 'submit', class: 'primary' }, t('ok')))), input);
  }

  #confirm(message) {
    const t = this.#t;
    let ok;
    const view = (close) => h('form', { method: 'dialog', onsubmit: (event) => {
      event.preventDefault();
      close(true);
    } },
    h('p', { style: 'margin: 0' }, message),
    h('div', { class: 'actions' },
      h('button', { type: 'button', onclick: () => close(false) }, t('cancel')),
      ok = h('button', { type: 'submit', class: 'primary' }, t('delete'))));
    return this.#dialog(view, () => ok).then(Boolean);
  }

  #dialog(view, focus) {
    const dialog = this.#els.dialog;
    return new Promise((resolve) => {
      let settled = false;
      const close = (value) => {
        if (settled) return;
        settled = true;
        dialog.close();
        resolve(value);
      };
      replace(dialog, view(close));
      dialog.addEventListener('close', () => close(null), { once: true });
      dialog.showModal();
      const target = typeof focus === 'function' ? focus() : focus;
      target?.focus();
      target?.select?.();
    });
  }
}
