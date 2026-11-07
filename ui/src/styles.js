/**
 * Styles of the components, inside their shadow roots. Hosts theme them with CSS custom properties
 * (set on the element or any ancestor) and with ::part().
 */

const tokens = `
  --_font: var(--tansu-font, inherit);
  --_color: var(--tansu-color, #1f2328);
  --_background: var(--tansu-background, #fff);
  --_muted: var(--tansu-muted, #656d76);
  --_surface: var(--tansu-surface, #f6f8fa);
  --_border: var(--tansu-border, #d0d7de);
  --_accent: var(--tansu-accent, #0969da);
  --_accent-contrast: var(--tansu-accent-contrast, #fff);
  --_selected: var(--tansu-selected, color-mix(in srgb, var(--_accent) 14%, transparent));
  --_danger: var(--tansu-danger, #cf222e);
  --_warning: var(--tansu-warning, #9a6700);
  --_radius: var(--tansu-radius, 6px);
  --_tile: var(--tansu-tile-size, 120px);
`;

const controls = `
  [hidden] { display: none !important; }
  button, a.button {
    font: inherit; color: inherit; cursor: pointer; text-decoration: none; display: inline-block;
    border: 1px solid var(--_border); border-radius: var(--_radius);
    background: var(--_background); padding: 0.35em 0.8em;
  }
  button:hover:not(:disabled), a.button:hover { background: var(--_surface); }
  button:disabled { opacity: 0.5; cursor: default; }
  button.primary { background: var(--_accent); border-color: var(--_accent); color: var(--_accent-contrast); }
  button.primary:hover:not(:disabled) { background: color-mix(in srgb, var(--_accent) 85%, #000); }
  button.danger { color: var(--_danger); }
  :focus-visible { outline: 2px solid var(--_accent); outline-offset: 1px; }
  dialog {
    font: inherit; color: var(--_color); background: var(--_background);
    border: 1px solid var(--_border); border-radius: var(--_radius); padding: 1rem;
    max-width: min(28rem, calc(100vw - 2rem));
  }
  dialog::backdrop { background: rgb(0 0 0 / 0.35); }
  dialog form { display: grid; gap: 0.75rem; }
  dialog input {
    font: inherit; padding: 0.4em 0.5em; border: 1px solid var(--_border); border-radius: var(--_radius);
    background: var(--_background); color: inherit;
  }
  .actions { display: flex; gap: 0.5rem; justify-content: flex-end; flex-wrap: wrap; }
  .error { color: var(--_danger); }
`;

export const explorerStyles = `
  :host {
    ${tokens}
    display: block; font: var(--_font); color: var(--_color);
    height: var(--tansu-height, 32rem); min-height: 16rem;
  }
  :host([hidden]) { display: none; }
  * { box-sizing: border-box; }
  ${controls}
  .layout {
    display: grid; grid-template-columns: minmax(10rem, 14rem) 1fr minmax(12rem, 16rem);
    height: 100%; border: 1px solid var(--_border); border-radius: var(--_radius);
    background: var(--_background); overflow: hidden;
  }
  .tree, .details { overflow: auto; padding: 0.5rem; background: var(--_surface); }
  .tree { border-right: 1px solid var(--_border); }
  .details { border-left: 1px solid var(--_border); display: flex; flex-direction: column; gap: 0.75rem; }
  .main { display: flex; flex-direction: column; min-width: 0; position: relative; }
  .toolbar {
    display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;
    padding: 0.5rem; border-bottom: 1px solid var(--_border);
  }
  .crumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 0.15rem; flex: 1 1 12rem; min-width: 0; }
  .crumbs button { border: none; background: none; padding: 0.15em 0.35em; }
  .crumbs span { color: var(--_muted); }
  .usage { color: var(--_muted); font-size: 0.85em; padding: 0 0.5rem 0.5rem; }
  .grid {
    flex: 1; overflow: auto; padding: 0.75rem; align-content: start;
    display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fill, minmax(var(--_tile), 1fr));
  }
  .tile {
    display: flex; flex-direction: column; align-items: stretch; gap: 0.35rem; padding: 0.35rem;
    border: 1px solid transparent; border-radius: var(--_radius); background: none; text-align: center;
  }
  .tile:hover:not(:disabled) { background: var(--_surface); }
  .tile[aria-selected="true"] { background: var(--_selected); border-color: var(--_accent); }
  .thumb {
    aspect-ratio: 1; width: 100%; display: grid; place-items: center; overflow: hidden;
    border-radius: calc(var(--_radius) - 2px); background: var(--_surface);
  }
  .thumb img { width: 100%; height: 100%; object-fit: contain; }
  .thumb svg, .preview svg { width: 50%; height: 50%; fill: var(--_muted); }
  .name { font-size: 0.85em; overflow-wrap: anywhere; line-height: 1.3; }
  .message { color: var(--_muted); padding: 2rem 1rem; text-align: center; grid-column: 1 / -1; }
  .drop {
    position: absolute; inset: 0; display: grid; place-items: center; pointer-events: none;
    background: color-mix(in srgb, var(--_accent) 12%, transparent); border: 2px dashed var(--_accent);
    color: var(--_accent); font-weight: 600;
  }
  .uploads { list-style: none; margin: 0; padding: 0 0.75rem 0.5rem; display: grid; gap: 0.25rem; font-size: 0.85em; }
  .uploads li { display: grid; grid-template-columns: 1fr auto; gap: 0.5rem; align-items: center; }
  .uploads progress { grid-column: 1 / -1; width: 100%; height: 0.4rem; }
  .uploads .warning {
    grid-column: 1 / -1; display: flex; gap: 0.5rem; align-items: flex-start; justify-content: space-between;
    color: var(--_warning); border: 1px solid currentColor; border-radius: var(--_radius); padding: 0.25rem 0.5rem;
  }
  .uploads .dismiss { border: none; background: none; color: inherit; padding: 0 0.25rem; line-height: 1; }
  .optimize {
    display: flex; flex-wrap: wrap; gap: 0.5rem 0.75rem; align-items: center;
    padding: 0.4rem 0.5rem; border-bottom: 1px solid var(--_border); font-size: 0.9em;
  }
  .optimize .choice { display: flex; gap: 0.4rem; align-items: center; }
  .optimize select, .optimize input[type="number"] {
    font: inherit; color: inherit; background: var(--_background);
    border: 1px solid var(--_border); border-radius: var(--_radius); padding: 0.2em 0.35em;
  }
  .optimize input[type="number"] { width: 5.5em; }
  .optimize .custom { display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; }
  .optimize .field { display: flex; gap: 0.3rem; align-items: center; }
  .optimize .field label { display: flex; gap: 0.25rem; align-items: center; }
  .optimize .hint {
    width: 1.6em; height: 1.6em; padding: 0; border-radius: 50%; font-weight: 600; color: var(--_muted); line-height: 1;
  }
  .optimize-hint { flex-basis: 100%; margin: 0; color: var(--_muted); font-size: 0.9em; }
  .tree ul { list-style: none; margin: 0; padding-left: 0.9rem; }
  .tree > ul { padding-left: 0; }
  .tree button {
    border: none; background: none; padding: 0.2em 0.35em; width: 100%; text-align: left;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .tree button[aria-current="true"] { background: var(--_selected); font-weight: 600; }
  .preview { aspect-ratio: 1; display: grid; place-items: center; background: var(--_background); border-radius: var(--_radius); overflow: hidden; }
  .preview img, .preview video { max-width: 100%; max-height: 100%; object-fit: contain; }
  .details h3 { margin: 0; font-size: 1em; overflow-wrap: anywhere; }
  .details dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 0.75rem; font-size: 0.85em; }
  .details dt { color: var(--_muted); }
  .details dd { margin: 0; overflow-wrap: anywhere; }
  .details .actions { justify-content: flex-start; }
  .details .hint { color: var(--_muted); font-size: 0.9em; }
  .status { padding: 0 0.75rem 0.5rem; font-size: 0.85em; }
  @media (max-width: 48rem) {
    :host { height: auto; }
    .layout { grid-template-columns: 1fr; height: auto; }
    .tree { border-right: none; border-bottom: 1px solid var(--_border); max-height: 10rem; }
    .details { border-left: none; border-top: 1px solid var(--_border); }
    .grid { min-height: 16rem; max-height: 60vh; }
  }
`;

export const pickerStyles = `
  :host { ${tokens} display: inline-flex; font: var(--_font); color: var(--_color); }
  :host([hidden]) { display: none; }
  * { box-sizing: border-box; }
  ${controls}
  .picker { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
  .preview {
    width: var(--tansu-preview-size, 96px); aspect-ratio: 1; display: grid; place-items: center; overflow: hidden;
    border: 1px solid var(--_border); border-radius: var(--_radius); background: var(--_surface);
    color: var(--_muted); font-size: 0.8em; text-align: center;
  }
  .preview img { width: 100%; height: 100%; object-fit: contain; }
  .side { display: grid; gap: 0.4rem; justify-items: start; }
  .file { font-size: 0.85em; color: var(--_muted); overflow-wrap: anywhere; }
  dialog.modal { width: min(64rem, calc(100vw - 2rem)); max-width: none; padding: 0.75rem; }
  dialog.modal header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; gap: 1rem; }
  dialog.modal h2 { margin: 0; font-size: 1.05em; }
  tansu-explorer { --tansu-height: min(36rem, calc(100vh - 8rem)); }
`;
