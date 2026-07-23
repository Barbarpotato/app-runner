# Membership, ownership whitelist & session auth

Replaces the old `api_tokens.ownership_data_binding` feature (client-header-trust model,
`X-Ownership-Data`) with an admin-provisioned **membership** model and a **session-token** login
flow, fully decoupled from `api_tokens`. `api_tokens`/`X-API-Key` still governs *which app/channel*
a caller may hit; `membership`/`session_token` governs *which member is acting and what they own*.
The two are independent, composable layers on every request.

Status: **implemented** (steps 1–4 below). Engine-side ownership whitelist enforcement is live in
`Library/engine/_LindseyEngine.php` (shipped upstream from Superlindey — see
`../superlindey/notes/2026-07-22-write-path-whitelist-check.md` and
`2026-07-22-write-path-ownership-existing-row-fallback.md` for the exact engine-side change log).

## 1. Database schema

Three tables in the **auth DB** (`cenauth`), same place as `api_tokens` — access-control data, not
business data. Defined in `templates/_setup_auth.txt` (applies to fresh installs automatically);
already applied by hand to the live DB for this instance.

```sql
CREATE TABLE membership (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    member_identifier VARCHAR(100) NOT NULL,   -- free reference into external user-services, unvalidated here
    label VARCHAR(150) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_member_identifier (member_identifier)  -- one membership row per member
);

CREATE TABLE membership_ownership_value (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    membership_id BIGINT NOT NULL,
    scope_name VARCHAR(100) NOT NULL,          -- matches project_info.ownership_scopes[].name
    value VARCHAR(250) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_membership_scope_value (membership_id, scope_name, value),  -- multi-row per scope = GRANT list
    KEY idx_scope_value (scope_name, value),
    FOREIGN KEY (membership_id) REFERENCES membership(id) ON DELETE CASCADE
);

CREATE TABLE membership_session (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    membership_id BIGINT NOT NULL,
    session_token VARCHAR(255) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (membership_id) REFERENCES membership(id) ON DELETE CASCADE
);
```

Design notes: no `active` flag (deactivation = delete the row). No `type`/`ref_object`/`values`
stored on `membership_ownership_value` — that metadata lives only in `Library/config.json →
project_info.ownership_scopes`, read live at write time, so it can evolve without touching stored
rows. Multiple rows per `(membership_id, scope_name)` are allowed on purpose — a member owning more
than one value for the same scope (e.g. two organizations) falls out of the schema for free.

## 2. Membership CRUD (dashboard)

`app/model/membership.php` + `app/controller/membership_controller.php` +
`app/view/membership/{index,add,edit}.php`. Reachable at `?action=membership` in the admin
dashboard (nav link in `app/view/header.php`).

- Admin manually types `member_identifier` — no lookup/validation against any local table (the
  real member record lives in user-services).
- One input per entry in `project_info.ownership_scopes`, rendered as a **tag input** (type a
  value, Enter/Add turns it into a removable chip — multiple chips per scope allowed). Any text
  still sitting in the input box (not yet committed to a chip) is auto-committed on form submit,
  so a value isn't silently lost if the admin forgets to click Add.
- Each scope gets **suggestions** depending on its type (`MembershipController::getScopesWithSuggestions()`):
  - `object_ref` → real rows queried live from the **business DB** (`$pdo`, not `$auth_pdo`),
    `SELECT DISTINCT <ref_field> FROM <ref_object>`.
  - `enum` → the scope's declared `values`.
  - `free_text` → no suggestions, free input.
  Suggestions are rendered as an HTML5 `<datalist>` — a hint, not a native enforcement.
- **Server-side validation** (`MembershipController::validateOwnershipValues()`) rejects any
  submitted `object_ref`/`enum` value that isn't actually in that scope's domain — the `<datalist>`
  alone doesn't stop a typed, non-matching value from being submitted, so this check is what
  actually enforces it. `free_text` stays unrestricted.
- Submit writes one `membership` row + one `membership_ownership_value` row per scope/value pair
  (old rows fully replaced on edit — `DELETE` then re-`INSERT`, inside a transaction).

## 3. `/auth` — login & session

Self-contained folder at the project root, sibling to `channels/`/`app/` — **not** routed through
`Bootloader.php`/the channel system (login isn't a Superlindey-exported channel, it's app-runner's
own local infrastructure). Each file brings its own `_db_config.php` include.

- **`auth/login.php`** (`POST`) — body `{member_identifier, password}` → calls
  `resolve_user_identity()` → looks up `membership` by the resolved identity (not found → `403`,
  membership must already be admin-provisioned) → mints `session_token`
  (`bin2hex(random_bytes(32))`) → `membership_session` row, **hard 2-hour expiry, no refresh** →
  returns `{session_token, expires_at}`.
  - **Single active session per member**: any of that member's existing session rows are deleted
    before the new one is inserted (logging in elsewhere invalidates the previous session).
  - Piggybacks a sweep of *all* globally expired session rows on every login call, so the table
    stays bounded without a separate cron job.
- **`auth/logout.php`** (`POST`, header `session_token`) — deletes that session row. Immediate
  revocation (this is why session storage is DB-backed/opaque rather than a self-contained JWT).
- **`auth/_user_service_client.php`** — the single swap point for the real user-services
  integration. `resolve_user_identity($member_identifier, $password)` is currently a
  **`ponytail:`-marked stub**: any non-empty credentials succeed, `$member_identifier` itself is
  treated as the resolved identity. **Absolute, deliberately-accepted tech debt** — swap this one
  function's body for a real HTTP call to user-services when it exists; no other file needs to
  change.

### Routing without `.php`

`.htaccess` gained one rule so `/auth/<name>` (no extension) resolves the same as
`/auth/<name>.php`, keeping this folder's URL style consistent with the rest of the API (which
gets extension-less URLs for free through `Bootloader.php`'s own path parsing — `/auth/` bypasses
that entirely, so it needs its own explicit rewrite):

```apache
RewriteCond %{REQUEST_FILENAME}.php -f
RewriteRule ^(auth/[^/]+)$ $1.php [L]
```

## 4. `Bootloader.php` — session resolve (mandatory on every request)

Replaces the old `X-Ownership-Data` header-parsing block entirely. Runs after the `X-API-Key`
check, before the requested channel endpoint file is included:

1. Header `session_token` missing/empty → `401`.
2. `membership_session` lookup by token, `expires_at > NOW()` → missing/expired → `401`.
3. `membership_ownership_value` for that `membership_id` → grouped into
   `{scope_name: [value, value, ...]}` → `$GLOBALS['ownership_data']`.

This is **required for every request through `Bootloader.php`**, regardless of whether the
targeted object declares any `ownership` field at all — harmless when it doesn't (the engine's
`resolveReadOwnership()`/`resolveWriteOwnership()` simply never iterate into
`$GLOBALS['ownership_data']` for an object with an empty `ownership` config). Two things sit
outside this requirement structurally, not as a carve-out: `channels/info.php` (never routed
through `Bootloader.php` — a direct, intentionally-public file) is unaffected; `/api-spec` and
`/form` sub-endpoints *do* still route through `Bootloader.php`, so they also require a valid
`session_token`.

## How the engine consumes `$GLOBALS['ownership_data']`

Example value after step 4 above, for a member with two organizations and one branch in their
whitelist:

```php
$GLOBALS['ownership_data'] = [
    'ownership_organization_name' => ['Org A', 'Org B'],
    'ownership_branch_name'       => ['Cabang X'],
];
```

- **Read** (`get()`/`_get()`) — used as-is, rendered `IN (...)`. The caller sees the union of
  everything they own, no per-request selection needed.
- **Write, value pinned explicitly** (`->ownership_data($field, $value)` from a channel-code
  pin, or a key in `set()`'s data array) — the single value is checked for membership in the
  whitelist (`in_array($value, $whitelist, true)`); not a member → throws. Required on **insert**
  (a new row has no existing ownership to fall back to).
- **Write, value omitted, existing row** (update/delete) — falls back to scoping that field to the
  caller's **entire whitelist** as `IN (...)` in the `WHERE` clause, rather than requiring one
  concrete value. Bulk-safe (no "which row" ambiguity for a filter matching several rows) and
  costs no extra query. A row outside the caller's whitelist still can't be touched (0 rows
  affected → the existing `emptyError` guard).

### Channel-code contract this implies

Worked out against `channels/surveyor/_create_record.php` / `_update_record.php` /
`_delete_record.php`:

- **Create** — pin every declared ownership field explicitly, value read from the request body
  (the client declares which org/branch a new record belongs to):
  ```php
  $qb = (new QUERYBUILDER())
      ->data($save_data)
      ->ownership_data('ownership_organization_name', $body['ownership_organization_name'])
      ->ownership_data('ownership_branch_name', $body['ownership_organization_branch_name']);
  $CENSUS->census_census->_set($qb);
  ```
  Pin the **field name as declared in the object model's `ownership` config** — a spec/body key
  can be named differently (as it is here: body field `ownership_organization_branch_name` vs
  model field `ownership_branch_name`) and `ownership_data()` throws if the pinned name isn't an
  actual configured ownership field.
- **Update/delete** — omit the ownership pin entirely, just target the row:
  ```php
  $qb = (new QUERYBUILDER())
      ->filter('id', $body['id'], '=')
      ->data($save_data);
  $CENSUS->census_census->_set($qb);
  ```
  A `_set()`/`_delete()` call **must** carry `->filter('id', ...)` (or another selective filter) —
  omitting it makes `_set()` an INSERT (never a mass UPDATE, per `QUERYBUILDER/SET.md`), a
  separate, unrelated footgun from the ownership contract.

## Known limitations (tech debt, tracked deliberately)

- `resolve_user_identity()` is a dummy stub (see § 3) — no real user-services integration yet.
- Session has no refresh mechanism; a hard 2-hour expiry always means a full re-login.
- Single active session per member — logging in on a second device invalidates the first.
- Mid-session revocation from user-services isn't propagated (tolerated up to the 2-hour TTL, per
  earlier design decision — not treated as a gap to close).
