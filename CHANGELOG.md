# Changelog

All notable changes to `oscaraccountant/sdk` are in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
versions follow [Semantic Versioning](https://semver.org/).

## 0.1.1 - 2026-10-15

### Added

- The operations of the employee register and of payroll in the public
  API: list and read the employees, list, create and read the submissions
  of an employee change, and list and read the pay runs. Their models and
  enums come with them.
- The feature `employees` of the current user, and the permission
  `employees.submit`.
- The parameter `$includeSettledExternal` of the parties list. It includes the parties
  with a key in another system (a Stripe customer) that have no open
  item. By default the list leaves them out unless it is filtered by
  `search`, `reg_code`, `vat_number` or `external_key`.
- The operation that reads the balance of the tax account of a company.
- The field `unit_price` of an invoice line (the unit price in major units,
  a decimal string) and the field `language` of the defaults of a party.
  The API sent both at 0.1.0, but this SDK did not read them.

## 0.1.0 - 2026-10-14

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
