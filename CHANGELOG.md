# Changelog

All notable changes to `oscaraccountant/sdk` are in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
versions follow [Semantic Versioning](https://semver.org/).

## 0.1.0 - unreleased

The first version.

### Added

- `Oscar`, with one property per resource of the public API.
- The models and the enums of the API, generated from its OpenAPI schema, in
  `OscarAccountant\Generated\Models` and `OscarAccountant\Generated\Enums`.
  An enum field keeps a value that this version does not know as a string.
- The credential order: `apiKey` or `tokenProvider`, then `OSCAR_API_KEY`,
  then the login of the `oscar` CLI when you ask for it.
- Retries with backoff and `Retry-After`, and an automatic `Idempotency-Key`.
- Pagination: one page, or every item of every page.
- The error classes, from the RFC 9457 problem of the API.
- File upload (`multipart/form-data`) and file download.
- A transport on the PHP extension `curl` with no Composer dependency, and a
  transport for a PSR-18 client.
