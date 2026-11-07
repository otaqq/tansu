# Protocol

The HTTP JSON API between the UI (`ui/`) and a back end. Every back end implements this API, so the same UI works with any of them.

Protocol version: **1**.

## Basics

- **Base URL.** All endpoints live under one base URL chosen by the host app, for example `/tansu`. The UI gets it from its `endpoint` attribute.
- **Authentication.** tansu does not log users in. Requests carry the host app's session cookie (same origin, `credentials: "same-origin"`). For CSRF protection the UI sends the token given in its `csrf-token` attribute as the `X-CSRF-TOKEN` header on every request that changes something.
- **Owner.** Each owner (a company, a user, a team) has its own root folder. Which owner a request is for is decided by the back end, usually from the session. A host that lets one user work with several owners (an administrator, for example) can read the optional `owner` query parameter, which the UI adds to every request when its `owner` attribute is set. The back end must check that the user may access that owner; the parameter is only a request.
- **Bodies.** Requests with a body send JSON (`Content-Type: application/json`), except uploads, which send `multipart/form-data`. Responses are JSON (`Content-Type: application/json`), except `GET /raw` and `204 No Content`.

## Paths

Folders and files are addressed by a path relative to the owner's root.

- A path starts with `/`. The root itself is `/`.
- Segments are separated by `/`. A segment is never empty, `.` or `..`, never starts with `.`, and contains no `\`, no control characters and no NUL. A segment is at most 255 bytes of UTF-8.
- A trailing `/` is not part of a path (`/photos`, not `/photos/`).

A request with a path that breaks these rules fails with `invalid_path`.

## Names

When a folder or file is created or renamed, the back end normalizes the name the client sent:

1. Unicode NFC.
2. Control characters are removed. `/ \ : * ? " < > |` and whitespace runs become `-`. Leading and trailing `.`, `-` and spaces are removed.
3. Truncated to 255 bytes of UTF-8 (without cutting a character; the extension is kept).
4. For files, the extension is set from the content, not from the name the client sent: an upload named `photo.png` that contains a JPEG is stored as `photo.jpg`. Extensions are lower case.

A name that is empty after this fails with `invalid_name`.

**Collisions.** An upload never overwrites a file. When the name is taken, the back end adds `-1`, `-2`, … before the extension (`photo-1.jpg`). Creating or renaming a folder, and renaming a file, fail with `name_taken` instead, so the user sees that the name was not available.

## Objects

**Folder**

```json
{ "name": "photos", "path": "/photos" }
```

**File**

```json
{
  "name": "shop.jpg",
  "path": "/photos/shop.jpg",
  "size": 183204,
  "type": "image/jpeg",
  "modified": "2026-11-07T10:18:40+09:00",
  "url": "/storage/tansu/42/photos/shop.jpg"
}
```

| Field | Meaning |
|---|---|
| `size` | Bytes |
| `type` | Media type, from the content checked at upload |
| `modified` | ISO 8601 date and time of the last change |
| `url` | Where the browser loads the file. Either a public URL of the storage, or `GET /raw` of this API |

Folders and files are sorted by name, case-insensitively, with numbers in natural order (`2` before `10`).

## Endpoints

All paths below are relative to the base URL.

| Method and path | Request | Response |
|---|---|---|
| `GET /info` | | `200` Info |
| `GET /tree` | | `200` `{ "root": Tree }` |
| `GET /folder?path=` | | `200` `{ "path", "folders": [Folder], "files": [File] }` |
| `POST /folders` | `{ "path": parent, "name" }` | `201` Folder |
| `PATCH /folders` | `{ "path", "name": new name }` | `200` Folder |
| `DELETE /folders?path=` | | `204` |
| `POST /files` | multipart: `path` (folder), `file` | `201` File |
| `PATCH /files` | `{ "path", "name": new name }` | `200` File |
| `DELETE /files?path=` | | `204` |
| `GET /raw?path=` | | `200` the file's bytes |

### `GET /info`

What the UI needs before it shows anything: what can be uploaded and how much room is left.

```json
{
  "protocol": 1,
  "accept": [
    { "type": "image/jpeg", "extensions": ["jpg", "jpeg"] },
    { "type": "image/png", "extensions": ["png"] }
  ],
  "limits": { "file_size": 2097152, "total_size": 10485760, "files": 50 },
  "usage": { "size": 3145728, "files": 12 },
  "can": { "manage": true }
}
```

- `limits.file_size` is the largest single upload in bytes. `limits.total_size` and `limits.files` are the owner's quota; `null` means no limit.
- `can.manage` is `false` when the user may only look and pick. The UI then hides upload, rename and delete; the back end refuses them anyway (`forbidden`).

### `GET /tree`

All folders, nested. Files are not included.

```json
{ "root": { "name": "", "path": "/", "folders": [
  { "name": "photos", "path": "/photos", "folders": [] }
] } }
```

### `DELETE /folders`

Deletes the folder with everything in it. If any file inside is in use (see `in_use`), nothing is deleted.

### `POST /files`

One file per request, so the UI can show progress per file and a failure stops only that file. The back end checks, in this order: the user may manage (`forbidden`); the folder exists (`not_found`); the size (`file_too_large`); the content type (`type_not_allowed`); the quota (`quota_exceeded`). Then it cleans the file (see below), picks a free name and stores it. The response is the stored file; its `name` may differ from the one sent.

### `PATCH /files`, `PATCH /folders`

Renames in place; moving to another folder is not part of protocol 1. The extension of a file never changes: the back end keeps the current extension whatever the new name ends with.

### `GET /raw`

Returns the file's bytes with its `Content-Type`, `Content-Disposition: inline`, `X-Content-Type-Options: nosniff` and `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`. Back ends that give out public storage URLs need not implement it (`404`).

## Content checks

Back ends check every upload by its content, never by its name or by the type the browser sent.

- **Raster images** (JPEG, PNG, GIF, WebP) are recognized by their header, which must describe an image of that type with a width and height. The stored extension always follows that type, so a script disguised as an image is refused, and an image can never be stored under an executable extension.
- **Metadata.** EXIF, XMP, IPTC and text metadata (GPS position, camera serial numbers, comments, …) is removed from JPEG, PNG and WebP before the file is stored, without re-encoding. A JPEG that the EXIF orientation says is rotated or mirrored is re-encoded upright instead, so it still shows the right way up.
- **SVG** is off unless the host enables it. When enabled, it is parsed as XML and only an allowlist of elements and attributes is kept: no scripts, no event handlers, no `foreignObject`, no external references. A file that does not parse, or has a `DOCTYPE`, fails with `type_not_allowed`.

## Errors

Every error has one shape:

```json
{ "error": { "code": "quota_exceeded", "message": "There is not enough room left for this file." } }
```

`message` is for people and may be shown as is; the UI chooses its own text by `code` and falls back to `message` for codes it does not know. Some errors add `path`, the path the error is about.

| Code | Status | When |
|---|---|---|
| `unauthenticated` | 401 | Not logged in |
| `forbidden` | 403 | Not allowed for this owner, or not allowed to change anything |
| `not_found` | 404 | The folder or file does not exist |
| `invalid_path` | 422 | The path breaks the rules above |
| `invalid_name` | 422 | The name is empty after normalizing |
| `name_taken` | 409 | A folder or file with that name already exists |
| `in_use` | 409 | The host still uses the file (for example a record points at it). Renaming or deleting it, or a folder that contains it, is refused. `path` is a file that is in use |
| `file_too_large` | 413 | Larger than `limits.file_size`, or larger than the server accepts at all |
| `upload_failed` | 400 | No file arrived, or it arrived incomplete |
| `type_not_allowed` | 415 | The content is not one of the accepted types |
| `quota_exceeded` | 422 | The owner's total size or file count would go over its limit |

Hosts may answer with their own status codes before tansu runs (for example a CSRF failure). The UI shows those as a general error.

## Versioning

`GET /info` returns `protocol`. Additions that old clients can ignore (new fields, new endpoints, new error codes) keep the version. Changes that break old clients raise it; a back end may then serve both under different base URLs.
