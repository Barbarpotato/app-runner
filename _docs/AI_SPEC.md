# AI CODING GUIDE — Writing Code for a Superlindey Project

This is the **playbook an LLM follows when it writes PHP into a Superlindey project** —
whether through the web UI or through the `superlindey/mcp.php` MCP tools. There are only
**three places** you ever write code, and they all share the same runtime: the object engine
(`_LindseyEngine`) and the `QUERYBUILDER`.

> **You never write a whole file.** You write a *code body* into a managed slot. The platform
> wraps it (validation, routing, ownership, full-file regeneration). Learn the slot's contract,
> write only the body, return — never `echo`/`print`/`exit` unless the contract says so.

---

## Table of Contents

1. [The three code slots](#1-the-three-code-slots)
2. [The object access model](#2-the-object-access-model)
3. [Engine method matrix (`get` vs `_get`, …)](#3-engine-method-matrix)
4. [QUERYBUILDER in one screen](#4-querybuilder-in-one-screen)
5. [Slot 1 — Channel endpoints](#5-slot-1--channel-endpoints)
6. [Authoring the endpoint API spec (JSON)](#6-authoring-the-endpoint-api-spec-json)
7. [Slot 2 — Object hooks](#7-slot-2--object-hooks)
8. [Slot 3 — Custom functions](#8-slot-3--custom-functions)
9. [Cross-cutting rules](#9-cross-cutting-rules)
10. [MCP tool → pattern map](#10-mcp-tool--pattern-map)

---

## 1. The three code slots

| Slot | Where it lives | Header / contract | MCP tools |
| ---- | -------------- | ----------------- | --------- |
| **Channel endpoint** | `channels/<channel>/<endpoint>` → `run($context, $objects)` | return an array; never echo | `*_channel_page` |
| **Object hook** | overrides one engine method (`get`/`set`/`delete`/`set_<state>`) of one object | call `parent::<method>(...)`; return its result | `*_object_hook` |
| **Custom function** | a free function `function <name>($DATA = array())` | return a value | `*_custom_function` |

All three execute **inside `Bootloader.php`**, so every global object and the `QUERYBUILDER`
class are already loaded — you never `require`/`include` anything.

---

## 2. The object access model

`Bootloader` reads `Library/config.json` and, for every **model title** (the group name, e.g.
`CENSUS`), creates a global `stdClass` whose properties are the objects of that model:

```php
$CENSUS->census_census   // main object  -> a wrapper extending _LindseyEngine
$CENSUS->census_area     // main object
$CENSUS->census_area_type// grouping object
```

- The **global variable name = the model title** (`$CENSUS`, `$PAYMENT`, …).
- The **property name = the object name** (`census_census`), snake_case, exactly as in the model.
- Each property is a wrapper class (PascalCase of the object name) that **extends
  `_LindseyEngine`**, so it exposes the full engine API below.

How you reach it depends on the slot:

```php
// In a channel run(): objects are extracted into local scope for you.
extract($objects);          // already called by the wrapper
$CENSUS->census_census->_get($qb);

// In a hook or custom function: pull it in explicitly.
global $CENSUS;
$CENSUS->census_census->_get($qb);
```

---

## 3. Engine method matrix

Every object exposes two flavours of each verb. The **underscore form takes a `QUERYBUILDER`**;
the **plain form takes a plain array / id**. They are not interchangeable — passing the wrong
type throws.

| Verb | Plain form | `QUERYBUILDER` form | Notes |
| ---- | ---------- | ------------------- | ----- |
| Read | `get($arrayOrId)` | `_get($qb)` | `_get` adds relations, pagination, FK expansion. Use it for anything non-trivial. |
| Write | `set($arrayWithMaybeId)` | `_set($qb)` | `set`: `id` present → UPDATE, absent → INSERT. `_set`: builder has a `filter()` → UPDATE, none → INSERT. Values go in `->data([...])`. |
| Delete | `delete($id)` | `_delete($qb)` | `_delete` **requires** a `filter()` (no full-table wipe). |

```php
// Read (rich): relations, pagination, joins -> _get
$qb = (new QUERYBUILDER())
    ->fields(['id', 'year', 'census_date'])
    ->filter('year', 2026, '=')
    ->sort('census_date', 'DESC');
$rows = $CENSUS->census_census->_get($qb);

// Read (trivial lookup by a couple of equals) -> get
$one = $CENSUS->census_area->get(['id' => 5]);

// Insert
$qb = (new QUERYBUILDER())->data(['year' => 2026, 'census_area_id' => 3]);
$newId = $CENSUS->census_census->_set($qb);

// Update (filter => UPDATE)
$qb = (new QUERYBUILDER())
    ->filter('id', $id, '=')
    ->data(['year' => 2027]);
$CENSUS->census_census->_set($qb);

// Delete (filter mandatory)
$qb = (new QUERYBUILDER())->filter('id', $id, '=');
$CENSUS->census_census->_delete($qb);
```

> **Why `_get` over `get` in business logic?** `get()` only AND-joins simple equals and cannot
> express joins, OR-groups, aggregates, pagination, or eager relations. Reserve `get([...])` for
> tiny lookups (and dropdown option lists like `->get(array())`); use `_get($qb)` everywhere else.

---

## 4. QUERYBUILDER in one screen

`QUERYBUILDER` is a **pure** SQL-SELECT/UPDATE/DELETE builder — it only assembles a plan; the
engine executes it. Values are always bound as named params (injection-safe); **identifiers
(field names, raw SQL, join conditions) are not** — never put user input there.

```php
$qb = (new QUERYBUILDER())
    ->fields(['id', 'name', 'census_area_type.name AS type_name'])
    ->join('census_area_type')                 // LEFT JOIN
    ->filter('census_area_type_id', 2, '=')
    ->where_group(function ($q) {              // (a OR b)
        $q->filter('name', '%utara%', 'LIKE')
          ->or_filter('name', '%selatan%', 'LIKE');
    })
    ->sort('name', 'ASC')
    ->page_number(1)->per_page(20);
```

- Operators: `= != > < >= <=`, `LIKE`/`NOT LIKE`, `IN`/`NOT IN` (non-empty array),
  `BETWEEN` (2-element array), `IS NULL`/`IS NOT NULL`.
- OR / nesting: `or_filter()`, `where_group()`, `or_where_group()`.
- Aggregates/grouping: `count()/sum()/avg()/min()/max()`, `group_by()`, `having()`.
- Relations: `properties('name', [...])` (belongs-to → object), `children('name', [...])`
  (has-many → array), `fk('table')` (flat FK column).
- Writes: `data([...])` (SET/VALUES). Read-only methods are rejected on a write builder.
- Ownership: set it via `ownership_data($field[, $value])` only — **never** via `filter()`
  (the engine AND-s ownership at the outermost level so an `OR` can't escape the scope).

**Full reference (read this before any non-trivial query):**

- [../QUERYBUILDER/GET.md](../QUERYBUILDER/GET.md) — reads, filters, joins, relations, pagination, ownership
- [../QUERYBUILDER/SET.md](../QUERYBUILDER/SET.md) — `_set()` UPDATE/INSERT and `data()`
- [../QUERYBUILDER/DELETE.md](../QUERYBUILDER/DELETE.md) — `_delete()`
- <a href="https://github.com/Barbarpotato/app-runner">Engine deep dive</a>

---

## 5. Slot 1 — Channel endpoints

An endpoint is one HTTP route. The platform generates the full file (request parsing, JSON-Schema
validation against `specs/<endpoint>.json`, pagination headers, response validation). **You only
write the body of `run()`** — between the `// START CODE DATA` / `// END CODE DATA` markers.

```php
function run($context, $objects)
{
    extract($objects);                 // -> $CENSUS, $APP, … in local scope

    // START CODE DATA
    $headers = $context['headers'];    // validated, lowercased header map
    $query   = $context['query'];      // $_GET
    $body    = $context['body'];       // decoded JSON body

    $qb = (new QUERYBUILDER())
        ->fields(['id', 'year', 'population_male_adult',
                  'census_area.name AS census_area'])
        ->join('census_area');
    if (isset($query['year'])) {
        $qb->filter('year', $query['year'], '=');
    }
    $result = $CENSUS->census_census->_get($qb);

    return ['status' => 'success', 'data' => $result];
    // END CODE DATA
}
```

Contract:

- **Return an array** (it is validated against the response schema, then JSON-encoded for you).
  Do **not** `echo`, `print`, or `exit`.
- `type: page` = GET (reads), `type: action` = POST (writes). Pick the matching engine verb.
- Pagination is automatic: if a `_get($qb)` with `per_page`/`page_number` runs, the platform
  publishes `$GLOBALS['pagination_data']` and emits `X-Total-Page` etc. headers — you don't.
- Throw an `Exception` to fail the request; the platform turns it into an error response.
- The endpoint's request/response shape lives in its **api_specification** (JSON), edited
  separately (`update_channel_page_api_spec`) — keep code and spec in sync. See [§6](#6-authoring-the-endpoint-api-spec-json).

---

## 6. Authoring the endpoint API spec (JSON)

Every endpoint owns a spec — the `api_specification` stored on the page and exported to
`channels/<channel>/specs/<endpoint>.json`. **It is the single source of truth for the
endpoint**: the generated file reads it at runtime to validate the request (`pre_validation`),
validate your returned array (`post_validation`), serve `GET …/api-spec`, and build the dynamic
`…/form` descriptor. The spec is *the endpoint's own contract* — write it first, then write
`run()` to honour it.

> **The validator is strict (closed schemas).** For any `type: object`, **every key must be
> declared in `properties` or it is rejected** — both on the incoming request *and on the array
> you return*. So: list every field the client may send, and every key your `run()` returns. An
> undeclared key = `VALIDATION_ERROR`.

### Top-level skeleton

```json
{
  "path": "load_record_list",
  "method": "GET",
  "description": "",
  "form-data-endpoint": "load_record_list/form",
  "request":  { "headers": { … }, "query": { … } | [], "body": { … } | null },
  "response": { "success": { "code": 200, "headers": { … }, "body": { … } }, "errors": [] }
}
```

| Key | Rule |
| --- | ---- |
| `path` | endpoint name **without** `.php` (matches the file/endpoint). |
| `method` | `GET` for a `page`, `POST` for an `action`. |
| `form-data-endpoint` | `"<path>/form"` when a request `body` exists; `null` otherwise. |
| `request.headers` | JSON-Schema object (see below). |
| `request.query` | flat map, or `[]` when there are none. |
| `request.body` | JSON-Schema object, or `null` (always `null` for GET). |
| `response.success` | `{ code, headers, body }`. |
| `response.errors` | array; usually `[]`. |

### Type vocabulary

`object`, `array` (needs `items`), `string`, `number` (validated with `is_numeric` — query values
arrive as strings, so numeric strings pass), `boolean`, and `datetime` (a string field, e.g.
ISO-8601 / `YMD`). There is no `integer`/`float` — use `number`.

### `request.headers` — a JSON-Schema object

```json
"headers": {
  "type": "object",
  "properties": {
    "x-api-key": { "type": "string" },
    "x-ownership-data": {
      "type": "object",
      "properties": {
        "ownership_member_number": { "type": "string" },
        "ownership_organization_number": { "type": "string" }
      }
    }
  },
  "required": ["x-api-key"]
}
```

- `x-api-key` is **always required** (auth is enforced in `Bootloader`).
- Header names are lowercase. An `object`/`array` header travels as a **JSON string** and is
  decoded before validation — that's how `x-ownership-data` carries the ownership scope.

### `request.query` — flat map (note: not a JSON-Schema object)

Query is the one place that **does not** use a `required: []` array. It is a flat map keyed by
name, and each entry carries its own `required` **boolean**:

```json
"query": {
  "year": { "type": "number", "required": true, "description": "", "example": "2026" }
}
```

Use `[]` (empty array) when the endpoint takes no query parameters.

### `request.body` — JSON-Schema object (POST actions)

```json
"body": {
  "type": "object",
  "properties": {
    "population_male_adult": { "type": "number", "model": "", "group_model_title": "" },
    "census_area_id": { "type": "number", "model": "census_area", "group_model_title": "CENSUS" },
    "census_date":    { "type": "datetime", "description": "format must be YMD", "model": "", "group_model_title": "" },
    "id":             { "type": "string",  "model": "census_census", "group_model_title": "CENSUS" }
  },
  "required": ["population_male_adult", "census_area_id", "year", "census_date"]
}
```

- Here `required` **is** a JSON-Schema array of property names (unlike `query`).
- Body fields may carry UI metadata used only by the `…/form` view:
  - `model` — the object name whose rows become the dropdown options (e.g. `census_area`).
  - `group_model_title` — the model-title global that owns that object (e.g. `CENSUS`).
  - The form view resolves options via `$<group_model_title>->{model}->get([])`. Leave both as
    `""` for a plain input.
  - `description` — optional help text (e.g. a date format hint).

### `response.success` — declare exactly what `run()` returns

```json
"response": {
  "success": {
    "code": 200,
    "headers": { "type": "object", "properties": [] },
    "body": {
      "type": "object",
      "properties": {
        "status": { "type": "string" },
        "data": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": {
              "id": { "type": "number" },
              "year": { "type": "number" },
              "census_date": { "type": "string" },
              "census_area": { "type": "string" }
            }
          }
        }
      }
    }
  },
  "errors": []
}
```

Because object schemas are closed, the response `body.properties` must name **every key your
`run()` returns**, recursively into array `items`. If `run()` returns `['status' => …, 'data' => …]`
but the schema omits `status`, the response fails validation. (Note: `datetime`/`timestamp` values
come back as strings, so type them `string` in responses.)

### The golden rule

The spec and `run()` are two halves of one contract:

1. Anything `run()` reads from `$context['query'|'body'|'headers']` must be declared in `request`.
2. Anything `run()` returns must be declared in `response.success.body`.

Keep them in lockstep. With MCP, write the spec with `update_channel_page_api_spec` and the code
with `update_channel_page` as a pair — never one without the other.

---

## 7. Slot 2 — Object hooks

A hook **overrides one engine method of one object**. The platform calls your hook instead of the
built-in method; you run pre-logic, delegate to the real implementation via `parent::`, run
post-logic, and return its result. Hook code receives the **same arguments as the method it
overrides** (`$save_data` for `set`, `$filters` for `get`, etc.).

```php
// Hook: census_area -> set  (runs on every insert/update of census_area)
// custom pre-hook
if (isset($save_data['id']) && $save_data['parent_id'] == $save_data['id'] && $save_data['id'] != 0) {
    throw new Exception('unable to set parent_id');   // abort the write
}

global $CENSUS;

// Guard: reject duplicates before saving
$qb = (new QUERYBUILDER())->filter('name', $save_data['name'], '=');
if (count($CENSUS->census_area->_get($qb)) > 0) {
    throw new Exception('Data already exist');
}

$res = parent::set($save_data);   // call the real implementation
// custom post-hook here …
return $res;
```

Rules:

- **Always call `parent::<method>(...)`** with the same argument and **return its result**,
  unless you intend to fully replace the behaviour.
- **Throw to abort** — a thrown `Exception` cancels the operation (the surrounding transaction
  rolls back on shutdown).
- Common `function_name`s: `get`, `set`, `delete`, and `set_<state>` (state-machine transitions).
- Use the **underscore methods** (`_get`/`_set`/`_delete`) for any querying you do *inside* a hook.

---

## 8. Slot 3 — Custom functions

A custom function is a standalone, reusable routine — not tied to one object. Signature is fixed:

```php
function _generate_census_number($DATA = array())
{
    global $CENSUS;

    $prefix = $DATA['prefix'];

    $qb = (new QUERYBUILDER())->filter('prefix', $prefix, '=');
    $rows = $CENSUS->census_app_numbering->_get($qb);

    $save = ['prefix' => $prefix];
    if (count($rows) === 1) {
        $save['id']      = $rows[0]['id'];          // -> UPDATE
        $save['counter'] = $rows[0]['counter'] + 1;
    } else {
        $save['counter'] = 1;                       // -> INSERT
    }
    $CENSUS->census_app_numbering->set($save);

    return $prefix . $save['counter'];
}
```

Rules:

- Header is always `function <name>($DATA = array())` — read inputs from `$DATA`.
- Pull objects with `global $TITLE`.
- **Return** a value; callers (endpoints, hooks, other custom functions) consume it.

---

## 9. Cross-cutting rules

- **PHP 7.0 target.** This project runs on `php:7.0.33-apache`. No arrow functions in older spots,
  no `str_contains`/`str_starts_with`, no typed properties, no `??=`. Plain `??` and array
  short-syntax are fine.
- **Ownership is enforced for you.** When you read/write through an object, the engine injects the
  caller's ownership scope from the validated header. Don't re-filter ownership in `filter()`; pin
  a server-side value with `ownership_data()` only when you must override it.
- **Validation is automatic in endpoints** (request against the spec, response against the
  success schema). Inside hooks/custom functions you validate your own invariants by `throw`ing.
- **Never trust identifiers.** User input belongs only in *values* (`filter`, `data`, `values`),
  never as a field name, raw SQL fragment, join condition, or alias.
- **One transaction per request.** It commits on success, rolls back if anything throws.

---

## 10. MCP tool → pattern map

When driving this project through `superlindey/mcp.php`, always **discover before writing**:
`list_projects` → `list_models`/`get_model` (to learn object & field names) → then write.

| You want to… | MCP tool | Code you put in `…_text` / `code` | Follow section |
| ------------ | -------- | --------------------------------- | -------------- |
| Add/replace an HTTP route | `create_channel_page` / `update_channel_page` | body of `run()` only | [§5](#5-slot-1--channel-endpoints) |
| Adjust a route's request/response shape | `update_channel_page_api_spec` | JSON spec (not PHP) | [§6](#6-authoring-the-endpoint-api-spec-json) |
| Intercept an object's get/set/delete | `create_object_hook` / `update_object_hook` | hook body w/ `parent::` | [§7](#7-slot-2--object-hooks) |
| Add reusable logic | `create_custom_function` / `update_custom_function` | `function <name>($DATA=array())` body | [§8](#8-slot-3--custom-functions) |
| Inspect existing code before editing | `get_channel_page` / `get_object_hook` / `get_custom_function` | — | — |

Naming the MCP tools enforce: object/field/function names are validated as **lowercase
snake_case**. Object hooks must target an object that actually exists in the named model — so
`get_model` first, then use the real object names in `$CENSUS->census_census->…`.
