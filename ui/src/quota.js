/**
 * What the explorer shows about limits before an upload: the largest file per group, the accepted types,
 * and how full the owner's quota is. Pure functions over GET /info.
 */
import { fileGroup } from './optimize.js';

/** Share of the quota from which the meter warns */
export const NEAR = 0.9;

/**
 * The largest upload per accepted group (one entry with group null when they are all the same), and the
 * accepted types as upper-case extensions.
 *
 * @param {{accept: Array<{type: string, extensions: string[], group?: string}>, limits: {file_size: number, file_sizes?: Record<string, number>|null}}} info
 * @returns {{sizes: Array<{group: string|null, max: number}>, types: string[]}}
 */
export function limitsSummary(info) {
  const types = info.accept.map((type) => type.extensions[0].toUpperCase());
  const sizes = info.limits.file_sizes;
  if (!sizes) return { sizes: [{ group: null, max: info.limits.file_size }], types };
  const groups = [...new Set(info.accept.map((type) => type.group ?? fileGroup(type.type)))];
  const perGroup = groups.map((group) => ({ group, max: sizes[group] ?? info.limits.file_size }));
  const same = perGroup.every((entry) => entry.max === perGroup[0].max);
  return { sizes: same ? [{ group: null, max: perGroup[0].max }] : perGroup, types };
}

/**
 * How full the owner's quota is, or null when it has none. The fraction is the larger of size and file count.
 * Full means no upload can fit: every file is used, or no byte is left.
 *
 * @param {{usage: {size: number, files: number}, limits: {total_size: number|null, files: number|null}}} info
 * @returns {{fraction: number, level: 'ok'|'near'|'full', full: boolean, reason: 'files'|'size'|null}|null}
 */
export function quotaState(info) {
  const { usage, limits } = info;
  if (limits.total_size === null && limits.files === null) return null;
  const byFiles = limits.files === null ? 0 : usage.files / limits.files;
  const bySize = limits.total_size === null ? 0 : usage.size / limits.total_size;
  const reason = limits.files !== null && usage.files >= limits.files ? 'files'
    : limits.total_size !== null && usage.size >= limits.total_size ? 'size'
      : null;
  const fraction = Math.min(1, Math.max(byFiles, bySize));
  const full = reason !== null;
  return { fraction, level: full ? 'full' : fraction >= NEAR ? 'near' : 'ok', full, reason };
}
