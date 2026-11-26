/**
 * Inline SVG icons (no icon-font or icon-library dependency) and helpers to build icon-only buttons and links.
 *
 * Icon-only controls keep their text in `aria-label` and a native `title` (a tooltip on hover), so they stay
 * accessible and discoverable without any CSS of the host's.
 */
import { h } from './dom.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

/** 24×24 path data, filled with the even-odd rule (so cut-outs like the folder "+" read as holes). */
export const ICONS = {
  folder: 'M2 5.5A1.5 1.5 0 0 1 3.5 4h4.38a1.5 1.5 0 0 1 1.06.44L10.5 6h10A1.5 1.5 0 0 1 22 7.5v11a1.5 1.5 0 0 1-1.5 1.5h-17A1.5 1.5 0 0 1 2 18.5z',
  file: 'M6 2h8l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V8h4.5z',
  document: 'M6 2h8l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V8h4.5zM8 11.5h8V13H8zm0 3.5h8v1.5H8z',
  video: 'M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm7 4v6l5-3z',
  check: 'M9.5 17.2 4.3 12l1.4-1.4 3.8 3.8 8.8-8.8L19.7 7z',
  eye: 'M12 6C7 6 3 12 3 12s4 6 9 6 9-6 9-6-4-6-9-6zm0 9.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5z',
  upload: 'M11 15V8.8l-2.6 2.6L7 10l5-5 5 5-1.4 1.4L13 8.8V15h-2zM5 18h14v2H5z',
  download: 'M11 5h2v6.2l2.6-2.6L17 10l-5 5-5-5 1.4-1.4L11 11.2V5zM5 18h14v2H5z',
  open: 'M14 3v2h3.6l-8.3 8.3 1.4 1.4L19 6.4V10h2V3h-7zM5 5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5h-2v5H5V7h5V5H5z',
  pencil: 'M3 17.2V21h3.8L17.9 9.9l-3.8-3.8L3 17.2zM20.7 7a1 1 0 0 0 0-1.4l-2.3-2.3a1 1 0 0 0-1.4 0L15.2 5l3.8 3.8L20.7 7z',
  trash: 'M9 3h6l1 2h4v2H4V5h4l1-2zM6 8h12l-1 13H7L6 8z',
  folderPlus: 'M2 5.5A1.5 1.5 0 0 1 3.5 4h4.38a1.5 1.5 0 0 1 1.06.44L10.5 6h10A1.5 1.5 0 0 1 22 7.5v11a1.5 1.5 0 0 1-1.5 1.5h-17A1.5 1.5 0 0 1 2 18.5zM11 9v2H9v2h2v2h2v-2h2v-2h-2V9z',
  close: 'M6.4 5 12 10.6 17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4z',
};

export function icon(pathData) {
  const svg = document.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS(SVG_NS, 'path');
  path.setAttribute('d', pathData);
  path.setAttribute('fill-rule', 'evenodd');
  svg.append(path);
  return svg;
}

/** An icon-only <button>. `name` is a key of ICONS; `label` fills aria-label and the title tooltip. */
export function iconButton(name, label, props = {}) {
  const { class: extra, ...rest } = props;
  return h('button', {
    type: 'button', 'aria-label': label, title: label,
    class: ['icon-button', extra].filter(Boolean).join(' '), ...rest,
  }, icon(ICONS[name]));
}

/** An icon-only link styled as a button (for open / download). */
export function iconLink(name, label, props = {}) {
  const { class: extra, ...rest } = props;
  return h('a', {
    'aria-label': label, title: label,
    class: ['button', 'icon-button', extra].filter(Boolean).join(' '), ...rest,
  }, icon(ICONS[name]));
}
