# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PHP application generated/managed by a "Superlindey" project. It has two independent request paths:

- **`index.php`** (root) — the public JSON API entrypoint. No sessions; auth is via `X-API-Key` header. Routes to `channels/<channel_name>/<endpoint>.php`.
- **`app/index.php`** — a session-based admin dashboard (login, API token management, error log viewer, a DB IDE) under `app/controller/*_controller.php` + `app/view/*`.

Both require `_db_config.php` and `Library/config.json` to exist (checked at the top of each entrypoint); if missing, `app/info/not_setup_properly.php` is shown. `_db_config.php`, `Library/`, and `channels/` (generated content) are gitignored — see "Generated vs tracked" below.

## No build/lint/test tooling

There is no `composer.json`, no test runner, no linter config in this repo. This is plain PHP served directly by Apache (`.htaccess` rewrites everything through `index.php` except real files/dirs). Verify changes by hitting the endpoint with curl/Postman against a locally configured `_db_config.php` + `Library/config.json`, or by reading `logs/error.log`.

CLI entrypoint: `php setup.php install|update|uninstall <url> [flags]` — used by an external deploy control plane to (re)generate `Library/` and `channels/` from a fetched Superlindey project config and to provision/migrate the database. Not something you typically run by hand mid-task.

## Request flow (`Bootloader.php`)

Both entrypoints funnel through `Bootloader::run()` (`index.php` calls it directly; `app/index.php` has its own separate controller dispatch and does not use it). For the public API path:

1. Load `Library/config.json` via `lindsey_load_config()` (`utils/lazy_load_engine.php`) — decoded once and cached as a compiled PHP array (`Library/config.cache.php`, opcache-friendly), regenerated automatically when `config.json`'s mtime changes.
2. For each `object_title` in `config['object_models']`, build a `LindseyLazyContainer` (global `$$title`, e.g. `$CENSUS`) that only instantiates a model's wrapper class on first property access (`__get`), not eagerly.
3. Parse `REQUEST_URI` to find the matching `channel` in config (`type === 'api'`, `channel_name` match).
4. Require `X-API-Key` header; look it up in `auth_pdo.api_tokens` for `scopes`, `channel_list`, `ownership_data_binding`.
5. Optionally parse `X-Pagination-Data` and `X-Ownership-Data` headers (strict JSON-object-of-strings validation) into `$GLOBALS['pagination_data']` / `$GLOBALS['ownership_data']`.
6. Enforce: token's `channel_list` contains the requested channel; `X-Ownership-Data` values are within the token's `ownership_data_binding` (per-key allowlist, `"*"` = any value); reject path traversal (`..`, null byte) in the endpoint path; GET-only for normal endpoints, POST-only for endpoints starting with `_` (action endpoints); `write` scope required for `_` endpoints, `read` scope forbidden from them.
7. Include `channels/<channel_name>/<endpoint>.php`. Action endpoints (`_`-prefixed) run inside a DB transaction (`$pdo->beginTransaction()`), committed on clean completion or rolled back on exception/shutdown.
8. Errors: caught centrally, logged to `logs/error.log` (channel/endpoint/token/body/query included), HTTP code derived from where the exception originated (`/channels/` → 400, `/Library/wrapper/` → 422, else 500). 500 responses return a generic message to the client (schema/query details never leak); 400/422 are expected validation errors and their message is returned as-is.

## Channel endpoint anatomy

A channel is a directory under `channels/<name>/` with an `api-spec.php` (merges all `specs/*.json` into one discovery blob) and one PHP file per endpoint, each backed by a same-named JSON spec in `specs/`. Endpoint files (e.g. `channels/surveyor/_create_record.php`) follow a fixed template:

1. Load the matching `specs/<endpoint>.json` spec.
2. If URL contains `/api-spec`, echo the raw spec JSON and exit.
3. If URL contains `/form` (POST only), build and return a form descriptor from the spec's body schema — resolves `model`-backed fields to live option lists via the global model containers (read-only, does not run business logic).
4. `pre_validation($spec)` — validates headers/query/body against the JSON-Schema-like spec (`type: object|array|string|number|boolean|datetime`), returns `{headers, query, body}`.
5. `run($context, $objects)` — the actual business logic. `$objects` is every global object (`$GLOBALS` object values, i.e. the `LindseyLazyContainer`s like `$CENSUS`) `extract()`-ed into scope. Build a query with `new QUERYBUILDER()`, execute via `$MODEL_GROUP->object_name->_get()/_set()/_delete()`, and `return` (never `echo`) the response array. The body of this function between `// START CODE DATA` / `// END CODE DATA` is what a Superlindey code-gen tool rewrites — don't remove those markers.
6. `post_validation($spec, $response)` — validates the return value against the spec's success response schema.
7. Pagination headers (`X-Total-Data`, `X-Per-Page`, `X-Current-Page`, `X-Total-Page`) are emitted if `$GLOBALS['pagination_data']` was set.

`channels/info.php` is the one public discovery endpoint left unprotected by `.htaccess` on purpose — it scans every channel's `specs/*.json` and returns a usage guide + endpoint list + per-channel `user_stories` (read from `Library/config.json`) to drive an LLM/FE client through: read `info.php` → read `api_spec_endpoint` → optionally `form_endpoint` → call the real endpoint.

## QUERYBUILDER / ownership model

`QUERYBUILDER` (in `Library/engine/_LindseyEngine.php`) is a pure, chainable SQL plan builder consumed by the engine's `get()`/`set()`/`delete()`. Full behavior — filtering, joins, `fk()`, relations, ownership semantics, write guards — is documented in [QUERYBUILDER/GET.md](QUERYBUILDER/GET.md), [QUERYBUILDER/SET.md](QUERYBUILDER/SET.md), [QUERYBUILDER/DELETE.md](QUERYBUILDER/DELETE.md) and the top-level [README.md](README.md). Key points worth internalizing before touching any channel business logic:

- Ownership fields are auto-injected from `$GLOBALS['ownership_data']` (the validated `X-Ownership-Data` header) and always AND-ed at the outermost query level — a top-level `OR` can never widen past ownership scope.
- Ownership fields must never appear in `filter()`/raw filters (throws); set them only via `ownership_data('field', $value)`, and only a channel-level pin (trusted, not header-bound) may override the header value.
- Ownership fields are immutable on `set()`'s `data()` — passing one throws.
- `set($qb)` with no filter is an INSERT, never a mass UPDATE; `delete($qb)` with no filter is rejected outright.
- SELECT-only clauses (`fields`, `join`, `fk`, `children`, `properties`, `group_by`, `having`, aggregates, `distinct`, `sort`, pagination) throw if used on `set`/`delete`.

## Portable app compiled from "app.json"

This repo is a portable runtime shell, not the app itself — the real app (channels, object models, custom functions, endpoint code) is data, compiled locally from one JSON artefact fetched from a Superlindey project. The dashboard UI (`app/view/index.php`) calls this artefact **"app.json"**; on disk it lands as `Library/config.json`.

`php setup.php install <url>` / `update <url>` (`setup.php:78`, `create_app()`) fetches that URL, `json_decode`s it, then:

1. **Deletes** `channels/` and `Library/` entirely and recreates them from scratch (`setup.php:116-121`) — this is a full recompile, not a merge.
2. Writes the fetched JSON verbatim to `Library/config.json`.
3. Generates `Library/custom/<name>.php` from `config['custom_functions']`.
4. For each `config['channels']`: creates `channels/<channel_name>/` (+ `specs/`), writes each page's `code` to `channels/<channel_name>/<endpoint>` and its `specs` to `channels/<channel_name>/specs/<name>.json`, writes any channel `include` (policy) file, and generates `api-spec.php` from `templates/_setup_api_spec.txt`.
5. (`update_database()` / DB-plan path, further down in `setup.php`) diffs object models against the live schema and migrates.

Because of step 1, any hand-edit under `channels/` or `Library/` is wiped on the next `setup.php update` — it's not just "gitignored," it's actively regenerated from `app.json` on every compile. Treat those trees as build output: fine to read for context, fine to hand-edit temporarily to test something, but the durable edit belongs either upstream in the Superlindey project (object models, hooks, channel pages — via the MCP tools) or in the `templates/_setup_*.txt` generator templates if the codegen shape itself needs to change. `_db_config.php`, `logs/`, and `.claude*` are also gitignored (local-only, not app.json-derived).

The `QUERYBUILDER/*.md` docs and this repo's `.php` files under `app/`, `Library/wrapper/*` (autoloaded per-class from `Library/wrapper/`), `utils/`, and root are the actual tracked, hand-maintained source.

## Where app.json actually comes from (`../superlindey`)

`../superlindey` (sibling dir, `DocumentRoot/superlindey`) is the Superlindey **authoring server** this project's app.json is compiled from — a separate PHP app with its own DB (`projects`, `object_models`, `custom_functions`, `channels`, `channel_pages` tables) and its own admin UI (`Pages/Projects/...`). This repo (`cencus-beta`) is the generated **runtime instance** for the Superlindey project named **`sensus-penduduk`** — confirmed by matching `object_models` keys (`CENSUS`, `OWNERSHIP`) and `channels` (`admin`, `surveyor`) between `../superlindey/artefacts/sensus-penduduk/sensus-penduduk@Dev.json` and this repo's `Library/config.json` / `channels/*`.

Pipeline, traced end to end:

1. **Author** — object models, hooks, custom functions, and channel pages are edited in Superlindey's DB (via its own UI, or via the Superlindey MCP tools if connected — same underlying project). Object model schema is authored/stored in **canonical form** (`object_models.json_data`, key `main_objects`) via `omdsl_expand()`/`omdsl_compact()` in `../superlindey/mcp/object_model_dsl.php` — the single FLAT⟷canonical converter shared by the MCP create/update/get tools *and* the UI's `save_model.php`, so both paths always land on the same shape (default system fields `id`/`creator_user_id`/`creator_user_name`/`created_at`/`updated_at`, typed business fields, deterministic field ordering for stable diffs).
2. **Export** — `../superlindey/Pages/Projects/api/export_project_json.php` (project_id-driven) or `_export_project_helper.php`'s `exportProjectToArtefacts()` reads those tables: decodes each `object_models.json_data` row and merges its `main_objects` in under the row's `model_title`, pulls `hooks`/`custom_functions`/`channels`+`channel_pages`, and calls `generateLindseyEngine()` (`../superlindey/Pages/Projects/engine-setup/_lindsey_engine_generator.php`) for the `_LindseyEngine` key — **that function does not actually generate anything from the object models**: it just checks `main_objects` is non-empty and, if so, returns the contents of a static template file, `_lindsey_engine.txt` (1930 lines), unchanged. Every project's app.json ships the identical engine source; only `object_models`/`channels`/`custom_functions`/`hooks` vary per project. Assembled shape:
   ```json
   { "project_info", "object_models", "hooks", "custom_functions", "channels", "_LindseyEngine" }
   ```
   This *is* app.json's shape — matches 1:1 what `create_app()` in this repo's `setup.php` expects.
3. **Persist artefact** — written to `../superlindey/artefacts/<parent_project_name>/<file>.json` (`_artefact_path.php`: `getProjectArtefactInfo()`), e.g. `sensus-penduduk/sensus-penduduk@Dev.json` for the Dev/parent version, or `sensus-penduduk/sensus-penduduk<code_name><release_number>.json` for a tagged release.
4. **Deploy** — `../superlindey/Pages/Instances/api/deploy_resource.php` / `migrate_instance.php` build `config_url` from that same artefact path (`<server_base>/artefacts/<folder>/<file>`) and POST it to a **deploy control plane** (`../deploy`, its own repo — `deploy.php`/`install.php`/`update.php`, X-API-Key authenticated) running on the target server, which in turn runs this repo's `php setup.php install|update <url> ...` with `config_url` as `<url>`.
5. **Compile** — as documented above: `create_app()` fetches `config_url`, writes `object_models`+`channels`+`custom_functions` out to `channels/`/`Library/custom/`, and writes `_LindseyEngine` verbatim to `Library/engine/_LindseyEngine.php` (`setup.php:243-248`) and per-object wrapper classes extending it into `Library/wrapper/`.

So "app.json" is never hand-written — it's a point-in-time DB export from `../superlindey`. If a change needs to persist across the next `setup.php update`, it must be made in the Superlindey project (object model / hook / channel page), re-exported, then re-installed — not by editing this repo's generated `channels/`/`Library/` directly. `../superlindey/docs/*.md` (`OBJECT_MODELS.md`, `CHANNEL_PAGES.md`, `OBJECT_HOOKS.md`, `CUSTOM_FUNCTIONS.md`, `GET.md`/`SET.md`/`DELETE.md`) is the authoring-side counterpart to this repo's `QUERYBUILDER/*.md`.

## Superlindey MCP

The connected Superlindey MCP server operates on that same authoring DB (object models → hooks/custom functions → channels/pages, bottom-up dependency order) — it's the same `sensus-penduduk` project described above, so MCP edits are exactly the "author" step in the pipeline and only take effect here after an export + `setup.php update`. Call `get_project_map` before modifying an object model, hook, or channel page that already exists, and check dependents first — changing a model field can break every channel/hook built on it. Don't invent object/field names that aren't already in the model; only create them if genuinely missing. MCP-managed code style: snake_case, PHP 7 / MySQL 5.7 syntax only (no PHP 8 features), comments only where non-obvious.
