# Roadmap

## Goals

- A media explorer that is small enough to understand and safe by default.
- No browser dependencies and no build step: plain ES modules and CSS.
- The same UI for any back end, through one documented HTTP API.

## Non-goals

- A general desktop-style file manager (copy/move between owners, archives, editors).
- Image editing (crop, filters). Thumbnails and resizing for display are in scope.
- A WordPress plugin: WordPress already has a media library.

## v0.1 — PHP / Laravel

### UI (`ui/`)

- `<tansu-explorer>`: folder tree, grid with thumbnails, preview, file info
- Upload: drag and drop, several files at once, progress
- Folders: create, rename, delete (tree, any depth)
- Files: rename, delete, preview
- `<tansu-picker>`: a button that opens the explorer in a modal; choosing a file fills a form field (path or URL) and shows a preview next to it. Also emits an event, so hosts can react in their own way
- Styles through CSS custom properties; no assumptions about the host's CSS framework

### Server (`lang/php/`)

- Storage through Flysystem (Laravel: a filesystem disk), per-owner root
- Owner resolution and authorization supplied by the host (Laravel: a resolver class and policies)
- Limits
  - Global: maximum size of one file, allowed types
  - Per owner: total size, number of files
- Name collisions: never overwrite; add a suffix
- Deleting a file that is in use: the host can veto through a hook (for example, when a record still points at it)

### Safe defaults

| Default | Why |
|---|---|
| Check the file content (magic bytes / image decode), not the extension | A `.jpg` name says nothing about what the file is |
| SVG off | SVG can carry scripts. Shown through `<img>` they do not run, but opening the file URL directly runs them on the app's origin (stored XSS). When enabled, SVG is sanitized before it is stored |
| Normalize file names | Avoid path tricks, control characters and names that break URLs |
| Strip EXIF | Photos often carry GPS positions and device details |
| Never serve executable types | Uploads must not become server-side scripts |

### Documentation

- `protocol/`: endpoints, request/response shapes, errors
- Laravel installation and configuration

## v0.2 — file groups and image optimization

Breaking for apps that do not set `accept`: the default grows from images to images, documents and videos. Released as v0.2.0 with the changes in the release notes:

- `FileTypes::DEFAULT` (still a list of media types) now also has PDF, MP4 and WebM; `FileTypes::DEFAULT_GROUPS` is the group form.
- `Limits::toArray()` and `GET /info` add `file_sizes`; `accept` entries add `group`. `limits.file_size` stays the largest size, so old clients keep working.
- In the Laravel config, maps are merged over the package defaults at every depth (no change for apps that wrote every key).

### File groups

`accept` takes group names as well as media types. A group stands for the types listed below; SVG belongs to no group and is accepted only when named (`'image/svg+xml'`).

| Group | Types | Stored as |
|---|---|---|
| `image` | `image/jpeg`, `image/png`, `image/gif`, `image/webp` | jpg, png, gif, webp |
| `document` | `application/pdf` | pdf |
| `video` | `video/mp4`, `video/webm` | mp4, webm |

```php
'accept' => ['image', 'document', 'video'],   // default
'accept' => ['image'],                        // an app that only wants pictures
'accept' => ['image', 'image/svg+xml'],       // SVG on top (sanitized)
```

- Types are checked by content, as in v0.1: PDF by `%PDF-`, MP4 by the `ftyp` box, WebM by the EBML header with the `webm` doc type.
- Office documents are not in `document`: they can carry macros.
- Metadata is stripped from images only. PDF and video metadata (author, device, and in phone videos the GPS position) cannot be removed without external tools such as ffmpeg; the docs say so.
- UI: images get thumbnails; documents and videos get an icon per group. The preview shows images inline, videos in `<video controls>`, and PDFs as an "Open in a new tab" link.

### Limits per group

`limits.file_size` is either one number for every type (as in v0.1) or a map by group. A type named on its own (such as SVG) uses the limit of its top-level type (`image`).

```php
'limits' => [
    'file_size' => ['image' => 5 * 1024 * 1024, 'document' => 10 * 1024 * 1024, 'video' => 50 * 1024 * 1024],
    'total_size' => null,
    'files' => null,
],
```

- `file_size` applies to the uploaded file, before optimization. It does not bound the memory a decode needs (PNG compresses flat areas a thousand to one); `optimize.max_pixels` and the memory check below do.
- In the Laravel config a map is merged over the default map, so a group the app leaves out keeps its default size. One number sets the same size for every group.
- PHP's `upload_max_filesize` and `post_max_size` must be at least the largest `file_size`.

### Config merge

The app's `config/tansu.php` is merged over the package defaults key by key at every depth (Laravel's `mergeConfigFrom` replaces nested arrays whole). An app writes only what it changes, for example `'limits' => ['file_size' => ['image' => 4 * 1024 * 1024]]`. Lists (`accept`, `routes.middleware`) are replaced, not merged.

### Image optimization

Off unless the app turns it on. When on, the person uploading chooses, per upload, whether and how images are made smaller:

- **Resize**: fit inside a width × height box, keeping the aspect ratio. Never enlarges.
- **Compress**: re-encode at a quality from 1 to 100.
- **Both**.

```php
'optimize' => [
    'enabled' => false,
    // What the upload form selects first: null (keep the original), a preset key or 'custom'
    'default' => null,
    // Named choices; each may resize, compress or both. Labels are a string or a map by language.
    'presets' => [
        // 'large' => ['label' => ['en' => 'Large', 'ja' => '大'], 'max_width' => 1920, 'max_height' => 1920, 'quality' => 85],
    ],
    // Free input next to the presets; null hides it. Values outside the ranges are refused.
    'custom' => [
        'max_width' => ['min' => 16, 'max' => 4096, 'default' => 1920],
        'max_height' => ['min' => 16, 'max' => 4096, 'default' => 1920],
        'quality' => ['min' => 40, 'max' => 100, 'default' => 85],
    ],
    // Safety factor on the estimated decode memory (see the limits below); at least 1.0
    'memory_margin' => 2.0,
    // Images with more pixels are never decoded, even without a memory limit
    'max_pixels' => 50_000_000,
    // Laravel log channel for skipped optimizations; null uses the default channel
    'log_channel' => null,
],
```

- The app's values override the package defaults through the config merge above. A page can also pick the first selection for one explorer: `<tansu-explorer optimize="logo">` (a preset key, `custom` or `none`), so a logo field can start on the logo preset.
- UI: the upload area shows a select (Original / each preset / Custom). Custom shows a "Resize" checkbox with width and height, and a "Compress" checkbox with quality. A hint (tooltip) next to them lists the limits below. All new texts are in `i18n.js` in `en` and `ja`.
- Protocol: `GET /info` returns `optimize` (enabled, default, presets with their labels as configured, custom ranges); the UI picks the label for its language. `POST /files` takes optional `optimize` (`none`, a preset key or `custom`) with `max_width`, `max_height` and `quality` for `custom`. The server checks them against the config; a preset key it does not know or a value out of range fails with `invalid_request`. The created file reports `optimized: true|false`.
- Runs with GD, after the content check and together with metadata stripping (one decode, one encode).
- Limits, shown in the hint:
  - Quality applies to JPEG and WebP only. PNG is lossless, so only resizing applies; GIF is resized and saved as GIF.
  - Animated GIFs (and animated WebP) are stored as they are: GD keeps only the first frame.
  - When GD is missing, lacks the format (WebP), or would not fit in memory, the original is stored and `optimized` is `false`. Running out of memory is a fatal error in PHP and cannot be caught, so the size is read first (`getimagesize`) and the decoded size is compared with the free part of `memory_limit` before decoding: 4 bytes a pixel (8 for PNG, whose reader also holds the raw rows; measured), a second copy when a JPEG is turned upright, plus the resized copy. The estimate is multiplied by `memory_margin` (default 2.0) before the comparison, because GD's real use varies with the format and the PHP build; a decode that would only just fit is skipped instead of risking a fatal error. Images with more than `max_pixels` are skipped whatever the memory limit, so `memory_limit = -1` cannot let a tiny PNG of 30 000 × 30 000 pixels take the server down. A 6000 × 4000 photo needs about 100 MB (200 MB with the margin), so shared hosting with a low `memory_limit` may skip large photos.
  - The same check guards turning a rotated JPEG upright when metadata is stripped (also without optimization). When it does not pass, the photo is not decoded: its metadata is removed and a minimal EXIF segment with only the orientation is kept, so it still shows the right way up.
- When an optimization the person asked for is skipped, the upload still succeeds, and both the person and the operator are told:
  - Protocol: the created file carries `optimized: false` and `optimization_skipped` with a reason code: `animated`, `format_unsupported` (GD lacks the format, such as WebP), `gd_missing` or `memory`. Compressing a PNG is not a skip (only the resize applies), so it has no code.
  - UI: the upload list shows a warning on that file ("Stored without optimizing: not enough memory", and so on, in `en` and `ja`). The message stays until the person closes it.
  - Log: one entry per skip through the PSR-3 logger (Laravel: `optimize.log_channel`). `warning` for `memory`, `gd_missing` and `format_unsupported`, which point at the server setup; `info` for `animated`. The context has the owner, path, type, width × height, the estimated and the free memory (bytes) and `memory_limit`. No file content and no user data beyond the owner key.

## v0.3 — limits in sight

The explorer shows the limits before people run into them. UI only: `GET /info` already has everything.

- **Limits next to the upload button**: the largest file, per group when the sizes differ ("Images up to 5 MB · Documents up to 10 MB · Videos up to 50 MB"), else once ("Up to 4 MB per file"), then the accepted types.
- **Usage meter**: the used share of the quota (the larger of total size and file count) as a bar next to the usage text, `role="meter"` with `aria-valuenow` in percent. At 90 % it turns to `--tansu-warning`, when full to `--tansu-danger`.
- **Full quota**: when every file is used or no byte is left, the upload button is disabled with the reason as its title, drops are refused with the reason in the status line, and the reason stays under the meter ("No room left: 100 of 100 files are used. Delete files to upload more."). Deleting files makes room again. The back end still checks every upload (`quota_exceeded`): a quota that is nearly full can still refuse a large file.
- Pure helpers in `ui/src/quota.js` (`limitsSummary`, `quotaState`); texts in `en` and `ja`.

## Later

- `conformance/`: HTTP-level test suite for any back end
- Other back ends (`lang/ruby` for Rails, `lang/go`, …)
- npm release of `ui/`

## Developing tansu together with an app

tansu can be used from an app before any release:

1. The app requires the branch through a VCS repository; `composer.lock` pins the commit, so CI and deploys get the same code.

   ```json
   "repositories": [{ "type": "vcs", "url": "https://github.com/otaqq/tansu" }],
   "require": { "otaqq/tansu": "dev-main" }
   ```

2. To edit both at once, clone tansu inside the app (ignored by the app's git), and replace `vendor/otaqq/tansu` with a relative symlink to it. `composer.json` and `composer.lock` stay unchanged; run `composer update otaqq/tansu` to take a new commit for real.
