/**
 * Messages of the UI. Hosts can add languages or change texts with `setMessages`.
 */

const catalogs = {
  en: {
    root: 'All files',
    newFolder: 'New folder',
    upload: 'Upload',
    rename: 'Rename',
    delete: 'Delete',
    choose: 'Choose',
    remove: 'Remove',
    open: 'Open',
    cancel: 'Cancel',
    ok: 'OK',
    close: 'Close',
    folderName: 'Folder name',
    newName: 'New name',
    confirmDeleteFile: 'Delete "{name}"?',
    confirmDeleteFolder: 'Delete the folder "{name}" and everything in it?',
    empty: 'This folder is empty.',
    dropHere: 'Drop files here to upload them.',
    usage: '{size} of {total} · {files} of {maxFiles} files',
    usageNoLimit: '{size} · {files} files',
    usageLabel: 'Storage used',
    'limits.one': 'Up to {max} per file',
    'limits.image': 'Images up to {max}',
    'limits.document': 'Documents up to {max}',
    'limits.video': 'Videos up to {max}',
    'quotaFull.files': 'No room left: {files} of {maxFiles} files are used. Delete files to upload more.',
    'quotaFull.size': 'No room left: {size} of {total} is used. Delete files to upload more.',
    size: 'Size',
    type: 'Type',
    modified: 'Modified',
    nothingSelected: 'Select a file to see it here.',
    loading: 'Loading…',
    pick: 'Choose an image',
    noImage: 'No image',
    uploaded: 'Uploaded',
    failed: 'Failed',
    openInNewTab: 'Open in a new tab',
    optimize: 'Optimize images',
    optimizeNone: 'Keep the original',
    optimizeCustom: 'Custom',
    optimizeSize: '{width}×{height} px',
    optimizeQuality: 'quality {quality}%',
    resize: 'Resize to fit',
    width: 'Width',
    height: 'Height',
    compress: 'Compress',
    quality: 'Quality',
    optimizeHintLabel: 'About optimizing images',
    optimizeHint: 'Applies to JPEG, PNG, GIF and WebP images; other files are stored as they are. Resizing fits the image inside the size and never enlarges it. Quality applies to JPEG and WebP only: PNG is lossless, so only resizing applies. Animated images are stored as they are. When the server cannot process an image (for example, not enough memory), the original is stored and you are told.',
    'skipped.animated': 'Stored without optimizing: animated images are kept as they are.',
    'skipped.memory': 'Stored without optimizing: the server does not have enough memory for this image.',
    'skipped.gd_missing': 'Stored without optimizing: the server cannot process images.',
    'skipped.format_unsupported': 'Stored without optimizing: the server cannot process this format.',
    'skipped.other': 'Stored without optimizing ({reason}).',
    'error.unauthenticated': 'You are not logged in. Reload the page and log in again.',
    'error.forbidden': 'You are not allowed to do this.',
    'error.not_found': 'It no longer exists. It may have been changed in another window.',
    'error.invalid_path': 'The path is not valid.',
    'error.invalid_name': 'Enter a name.',
    'error.name_taken': 'That name is already taken.',
    'error.in_use': 'It is in use and cannot be changed or deleted.',
    'error.file_too_large': 'The file is too large (at most {max}).',
    'error.type_not_allowed': 'This type of file cannot be uploaded ({types}).',
    'error.quota_exceeded': 'There is not enough room left.',
    'error.invalid_request': 'The request was not accepted ({message}).',
    'error.upload_failed': 'The upload did not finish. Try again.',
    'error.network': 'The server could not be reached.',
    'error.other': 'Something went wrong ({message}).',
  },
  ja: {
    root: 'すべてのファイル',
    newFolder: '新しいフォルダ',
    upload: 'アップロード',
    rename: '名前を変更',
    delete: '削除',
    choose: '選ぶ',
    remove: '外す',
    open: '開く',
    cancel: 'キャンセル',
    ok: 'OK',
    close: '閉じる',
    folderName: 'フォルダ名',
    newName: '新しい名前',
    confirmDeleteFile: '「{name}」を削除しますか？',
    confirmDeleteFolder: 'フォルダ「{name}」と、その中身をすべて削除しますか？',
    empty: 'このフォルダは空です。',
    dropHere: 'ここにファイルをドロップするとアップロードします。',
    usage: '{size} / {total}・{files} / {maxFiles} ファイル',
    usageNoLimit: '{size}・{files} ファイル',
    usageLabel: '使用量',
    'limits.one': '1 ファイル {max} まで',
    'limits.image': '画像は {max} まで',
    'limits.document': '文書は {max} まで',
    'limits.video': '動画は {max} まで',
    'quotaFull.files': '上限に達しています（{files} / {maxFiles} ファイル）。アップロードするには、ファイルを削除してください。',
    'quotaFull.size': '上限に達しています（{size} / {total}）。アップロードするには、ファイルを削除してください。',
    size: 'サイズ',
    type: '種類',
    modified: '更新日時',
    nothingSelected: 'ファイルを選ぶと、ここに表示します。',
    loading: '読み込み中…',
    pick: '画像を選ぶ',
    noImage: '画像なし',
    uploaded: 'アップロードしました',
    failed: '失敗しました',
    openInNewTab: '新しいタブで開く',
    optimize: '画像の最適化',
    optimizeNone: 'そのまま',
    optimizeCustom: 'カスタム',
    optimizeSize: '{width}×{height} px',
    optimizeQuality: '品質 {quality}%',
    resize: 'サイズを縮小（枠に収める）',
    width: '幅',
    height: '高さ',
    compress: 'ファイルサイズを縮小',
    quality: '品質',
    optimizeHintLabel: '画像の最適化について',
    optimizeHint: 'JPEG・PNG・GIF・WebP の画像に効きます。ほかのファイルはそのまま保存します。縮小は指定した枠に収まるように小さくし、拡大はしません。品質は JPEG と WebP だけに効きます（PNG は可逆圧縮なので縮小だけが効きます）。アニメーション画像はそのまま保存します。サーバーで処理できないとき（メモリ不足など）は元の画像のまま保存し、そのことを表示します。',
    'skipped.animated': 'アニメーション画像のため、最適化せずに保存しました。',
    'skipped.memory': 'サーバーのメモリが足りないため、最適化せずに保存しました。',
    'skipped.gd_missing': 'サーバーが画像を処理できないため、最適化せずに保存しました。',
    'skipped.format_unsupported': 'サーバーがこの形式を処理できないため、最適化せずに保存しました。',
    'skipped.other': '最適化せずに保存しました（{reason}）。',
    'error.unauthenticated': 'ログインしていません。ページを読み込み直して、ログインし直してください。',
    'error.forbidden': 'この操作はできません。',
    'error.not_found': '見つかりません。別の画面で変更された可能性があります。',
    'error.invalid_path': 'パスが正しくありません。',
    'error.invalid_name': '名前を入力してください。',
    'error.name_taken': 'その名前はすでに使われています。',
    'error.in_use': '使用中のため、変更・削除できません。',
    'error.file_too_large': 'ファイルが大きすぎます（{max} まで）。',
    'error.type_not_allowed': 'この種類のファイルはアップロードできません（{types}）。',
    'error.quota_exceeded': '容量またはファイル数の上限に達しています。',
    'error.invalid_request': 'リクエストを受け付けられませんでした（{message}）。',
    'error.upload_failed': 'アップロードが完了しませんでした。もう一度お試しください。',
    'error.network': 'サーバーに接続できません。',
    'error.other': 'エラーが発生しました（{message}）。',
  },
};

/**
 * Adds or overrides messages for a language.
 *
 * @param {string} lang
 * @param {Record<string, string>} messages
 */
export function setMessages(lang, messages) {
  catalogs[lang] = { ...(catalogs[lang] || {}), ...messages };
}

/**
 * The language for an element: its closest `lang` attribute, else the document's, reduced to a known catalog.
 *
 * @param {Element} [element]
 */
export function languageOf(element) {
  const lang = (element?.closest?.('[lang]')?.getAttribute('lang') || globalThis.document?.documentElement?.lang || 'en').toLowerCase();
  if (catalogs[lang]) return lang;
  const base = lang.split('-')[0];
  return catalogs[base] ? base : 'en';
}

/**
 * A translate function for a language: t(key, params) with "{name}" placeholders. Unknown keys fall back to English.
 *
 * @param {string} lang
 */
export function translator(lang) {
  return (key, params = {}) => {
    const text = catalogs[lang]?.[key] ?? catalogs.en[key] ?? key;
    return text.replace(/\{(\w+)\}/g, (match, name) => (name in params ? String(params[name]) : match));
  };
}

/**
 * Human-readable size: 1.5 MB, 120 KB, 900 B.
 *
 * @param {number} bytes
 * @param {string} [lang]
 */
export function formatSize(bytes, lang = 'en') {
  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }
  const digits = unit === 0 || value >= 10 ? 0 : 1;
  return `${new Intl.NumberFormat(lang, { maximumFractionDigits: digits }).format(value)} ${units[unit]}`;
}

/**
 * Date and time in the user's locale.
 *
 * @param {string} iso
 * @param {string} [lang]
 */
export function formatDate(iso, lang = 'en') {
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? iso : new Intl.DateTimeFormat(lang, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}
