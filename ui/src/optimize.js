/**
 * Image optimization offered by the server (GET /info `optimize`), and the upload limit that applies to a file.
 * Pure functions, so the explorer only renders and the rules can be tested alone.
 */

/**
 * The first state of the optimize controls, or null when the server offers none.
 *
 * @param {object} [optimize] `optimize` of GET /info
 * @param {string|null} [requested] the explorer's `optimize` attribute: a preset key, "custom" or "none"
 * @returns {{choice: string, custom: {resize: boolean, maxWidth: number, maxHeight: number, compress: boolean, quality: number}}|null}
 */
export function initialOptimize(optimize, requested = null) {
  if (!optimize?.enabled) return null;
  const offered = (choice) => choice === 'none'
    || (choice === 'custom' && Boolean(optimize.custom))
    || optimize.presets.some((preset) => preset.key === choice);
  const choice = [requested, optimize.default].find((candidate) => candidate && offered(candidate)) || 'none';
  const custom = optimize.custom;
  return {
    choice,
    custom: {
      resize: true,
      maxWidth: custom?.max_width.default ?? 0,
      maxHeight: custom?.max_height.default ?? 0,
      compress: true,
      quality: custom?.quality.default ?? 0,
    },
  };
}

/**
 * The form fields an upload sends for the state (none when keeping the original).
 *
 * @param {ReturnType<typeof initialOptimize>} state
 * @returns {Record<string, string|number>}
 */
export function optimizeFields(state) {
  if (!state || state.choice === 'none') return {};
  if (state.choice !== 'custom') return { optimize: state.choice };
  const { resize, maxWidth, maxHeight, compress, quality } = state.custom;
  if (!resize && !compress) return {};
  return {
    optimize: 'custom',
    ...(resize ? { max_width: maxWidth, max_height: maxHeight } : {}),
    ...(compress ? { quality } : {}),
  };
}

/**
 * A preset label: a string, or a map by language (the language, else English, else the first).
 *
 * @param {string|Record<string, string>} label
 * @param {string} lang
 */
export function labelOf(label, lang) {
  if (typeof label === 'string') return label;
  return label[lang] ?? label.en ?? Object.values(label)[0] ?? '';
}

/**
 * The group of a media type: "image", "video" or "document".
 *
 * @param {string} type
 */
export function fileGroup(type) {
  if (type.startsWith('image/')) return 'image';
  if (type.startsWith('video/')) return 'video';
  return 'document';
}

/**
 * The largest upload for a file: the size of its group when the server gives sizes by group, else the one size.
 *
 * @param {{accept: Array<{type: string, extensions: string[], group?: string}>, limits: {file_size: number, file_sizes?: Record<string, number>|null}}} info
 * @param {{name: string, type: string}} file
 */
export function fileSizeLimit(info, file) {
  const sizes = info.limits.file_sizes;
  if (!sizes) return info.limits.file_size;
  const extension = file.name.includes('.') ? file.name.slice(file.name.lastIndexOf('.') + 1).toLowerCase() : '';
  const accepted = info.accept.find((type) => type.type === file.type || type.extensions.includes(extension));
  const group = accepted ? accepted.group ?? fileGroup(accepted.type) : null;
  return (group && sizes[group]) ?? info.limits.file_size;
}
