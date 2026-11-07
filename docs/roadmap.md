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
