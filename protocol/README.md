# Protocol

The HTTP JSON API between the UI (`ui/`) and a back end. Every back end implements this API, so the same UI works with any of them.

> **Status:** to be written before the first implementation. Planned contents:

- Base URL and authentication (the host app's session or token; tansu does not log users in)
- Resources: folders and files, addressed by a path relative to the owner's root
- Operations: list a folder, get file info, upload, rename, delete, create/rename/delete a folder
- Limits and errors: one error shape with a machine-readable code (for example `file_too_large`, `quota_exceeded`, `type_not_allowed`, `name_taken`, `in_use`)
- Thumbnails and public URLs
- Versioning of the protocol itself
