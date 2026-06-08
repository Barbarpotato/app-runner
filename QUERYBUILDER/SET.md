# QUERYBUILDER — `set($qb)` (UPDATE / INSERT)

Besides SELECT (see [GET.md](GET.md)), a `QUERYBUILDER` can also drive writes. Hand a builder to
the engine's `set()` and it becomes either an **UPDATE** or an **INSERT** — decided purely by
whether the builder carries a WHERE filter.

```
set($qb):
  has a filter   → UPDATE   (a WHERE is mandatory — never a full-table update)
  has no filter  → INSERT   (a new row — safe by definition)
```

The builder stays **pure** (it only assembles SQL); the engine resolves ownership, filters to
savable fields, and executes.

> **Filtering reference.** `set($qb)`'s WHERE reuses the exact same filter API as reads —
> `filter()`, `or_filter()`, `where_group()`, operators (`=`, `IN`, `BETWEEN`, `LIKE`, `IS NULL`…),
> and raw filters with `values()`. They are documented once in **[GET.md](GET.md#filtering-where)**;
> this page covers only what is write-specific.

---

## Table of Contents

1. [Quick Start](#quick-start)
2. [Method Reference](#method-reference)
3. [Setting column values — `data()`](#setting-column-values--data)
4. [UPDATE (filter present)](#update-filter-present)
5. [INSERT (no filter)](#insert-no-filter)
6. [Ownership](#ownership)
7. [Guards & rejected clauses](#guards--rejected-clauses)
8. [The Update Plan (`buildUpdate`)](#the-update-plan-buildupdate)
9. [Best Practices](#best-practices)
10. [Error Reference](#error-reference)

---

## Quick Start

```php
// UPDATE — filter present
$engine->set(
    (new QUERYBUILDER())
        ->filter('state', 'active', '=')
        ->data(['state' => 'closed', 'note' => 'bulk close'])
);

// INSERT — no filter
$engine->set(
    (new QUERYBUILDER())->data(['full_name' => 'Budi', 'state' => 'active'])
);
```

---

## Method Reference

Write-specific methods, plus the shared ones most used on a write builder:

| Method                              | Purpose                                                       |
| ----------------------------------- | ------------------------------------------------------------ |
| `data($map)`                        | Column => value to write (SET on UPDATE / VALUES on INSERT). Merges across calls. |
| `getData()`                         | Read the collected `data()` map (engine-managed).            |
| `hasConditions()`                   | True if a WHERE is present — decides UPDATE vs INSERT (engine-managed). |
| `filter()/or_filter()/where_group()`| WHERE for the UPDATE (see [GET.md](GET.md#filtering-where)). Not allowed for ownership fields. |
| `ownership_data($field[, $value])`  | Pin/scope an ownership field (must be configured; pin wins over header). |
| `buildUpdate($setData[, $rawSet])`  | Produce the pure UPDATE plan.                                |

> SELECT-only methods (`fields`, `join`, `fk`, `children`, `properties`, `group_by`, `having`,
> aggregates, `distinct`, `sort`, pagination) are **rejected** on a write builder — see
> [Guards](#guards--rejected-clauses).

---

## Setting column values — `data()`

```php
$qb->data(['state' => 'closed', 'note' => 'x']);
```

- Holds the `column => value` map written as **SET** (UPDATE) or **VALUES** (INSERT).
- **Merges** across calls: `->data(['a'=>1])->data(['b'=>2])` ≡ `->data(['a'=>1,'b'=>2])`.
- Values bind as `:sN` placeholders (separate from WHERE's `:fN` and HAVING's `:hN`).
- A `set($qb)` with no `data()` throws.
- **Never put an ownership field in `data()`.** Ownership is **immutable** — set once at creation
  from the authenticated context, never reassigned via a normal write. An ownership field in
  `data()` **throws** (both UPDATE and INSERT). Use `ownership_data()` for scoping/creation.

---

## UPDATE (filter present)

```php
$qb = (new QUERYBUILDER())
    ->filter('state', 'active', '=')
    ->or_filter('state', 'pending', '=')
    ->data(['state' => 'closed', 'note' => 'bulk close']);

$engine->set($qb);
```

```sql
UPDATE personalia_member
SET state = :s0, note = :s1, updated_at = CURRENT_TIMESTAMP
WHERE 1=1
  AND (personalia_member.state = :f0 OR personalia_member.state = :f1)
  AND personalia_member.member_number = :f2
```
```
:s0=closed  :s1='bulk close'  :f0=active  :f1=pending  :f2=M001
```

- The full WHERE engine is reused — `OR`, nested `where_group()`, `IN`, `BETWEEN`, `LIKE`, etc.
- Only **savable** fields (`can_be_saved` in config) are written; any other key in `data()` is
  silently ignored. If nothing savable remains, it throws.
- `updated_at = CURRENT_TIMESTAMP` is appended automatically when the model has that column.
- **Ownership is strict and OR-safe** (see [Ownership](#ownership)).
- If the statement matches **0 rows**, it throws (`No rows updated: ...`).

---

## INSERT (no filter)

```php
$engine->set((new QUERYBUILDER())->data(['full_name' => 'Budi', 'state' => 'active']));
```

```sql
INSERT INTO personalia_member (full_name, state, member_number, created_at, updated_at)
VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
-- 'Budi', 'active', 'M001'
```

- A builder with **no WHERE** can never become a full-table UPDATE — it is treated as INSERT.
- Ownership columns are **planted** into the new row: from a channel-level
  `->ownership_data('field', $value)` **pin** if present (it wins), otherwise from
  `$GLOBALS['ownership_data']`. `created_at` / `updated_at` default to `CURRENT_TIMESTAMP`.
- Returns the new row id (`lastInsertId`).

> The legacy array insert `set(['field' => value])` is unchanged — it reads ownership strictly
> from `$GLOBALS['ownership_data']` (no pin concept).

---

## Ownership

Same field list (`config['ownership']`) and `ownership_data()` API as reads, but **strict**:

- The value is taken from a channel-level `->ownership_data('field', $value)` **pin** if present
  (it **wins** over the header), otherwise from `$GLOBALS['ownership_data']`.
- **Strict (unlike reads):** if an ownership field has no value from either source, the write
  **throws** — reads skip leniently, writes do not.
- Ownership is **AND-ed at the outermost level**, so a top-level `OR` in the filter can never
  widen a write past the ownership scope (see the UPDATE example above).
- A field passed to `ownership_data()` that is **not** a configured ownership field throws (same
  smart validation as reads).
- **Never via `filter()`.** Using an ownership field in `filter()` / `or_filter()` / a nested
  group / a raw filter **throws** at build — scoping must go through `ownership_data()`. (Applies
  to the UPDATE WHERE.)

```php
// explicit pin wins over the header value
$qb->filter('state', 'active', '=')
   ->ownership_data('member_number', 'M001')
   ->data(['state' => 'closed']);
```

> A pinned value is **trusted** and is _not_ re-validated against the token's
> `ownership_data_binding` — only pin server-side values, never raw client input.

### Multiple ownership fields

Call `ownership_data()` once per field — each is AND-ed separately at the outermost level. You may
pin some fields and let the rest fall back to the header. Calling the **same** field twice keeps the
**last** value (the override map is keyed by field).

```php
$qb->filter('state', 'active', '=')
   ->ownership_data('member_number', 'M001')
   ->ownership_data('organization_number', 'ORG01')   // both enforced
   ->data(['state' => 'closed']);
```

```sql
... WHERE 1=1 AND (personalia_member.state = :f0)
        AND personalia_member.member_number = :f1
        AND personalia_member.organization_number = :f2
```

---

## Guards & rejected clauses

A write builder rejects SELECT-only clauses (they would produce invalid or misleading SQL):

`fields()`, `join()`, `fk()`, `children()`, `properties()`, `group_by()`, `having()`,
aggregates (`count/sum/avg/min/max`), `distinct()`, `sort()`, and pagination.

---

## The Update Plan (`buildUpdate`)

`buildUpdate($setData, $rawSet = [])` returns a pure plan (no DB access), analogous to `build()`:

```php
[
  'sql'    => 'UPDATE ... SET ... WHERE 1=1 ...',
  'params' => [':s0' => ..., ':f0' => ...],   // SET (:sN) + WHERE (:fN) named params
]
```

- `$setData` — `column => value` map (the engine passes it already filtered to savable fields).
- `$rawSet` — raw `"col = expr"` assignments the engine controls (e.g.
  `"updated_at = CURRENT_TIMESTAMP"`).

The engine drives this inside `set()`; call it directly only for tests/custom execution.

---

## Best Practices

- **Always include a filter when you mean to UPDATE.** No filter silently becomes an INSERT — if
  you intended an update, you would create a stray row instead. Double-check the WHERE is present.
- **Treat ownership as immutable.** Scope/seed it with `ownership_data()`; never via `data()` or
  `filter()`. Let the engine pull it from the header unless you have a deliberate server-side pin.
- **Never pass client input to `ownership_data()`** — a pin is trusted and bypasses the binding check.
- **Keep `data()` to writable columns.** Non-savable fields are dropped; relying on them to be
  written is a silent no-op.
- **Pair bulk updates with a precise WHERE.** The rich WHERE (OR/IN/groups) is powerful — scope it
  tightly so you only touch the rows you intend.

---

## Error Reference

| Situation                              | Message (excerpt)                                                     |
| -------------------------------------- | -------------------------------------------------------------------- |
| `set($qb)` with no `data()`            | `set($qb): column values are required via ->data([...])`             |
| Ownership field in `data()`            | `set($qb): ownership field 'X' cannot be set via ->data() (ownership is immutable)` |
| Ownership field in `filter()` (struct/raw) | `filter(): ownership field 'X' must be set via ownership_data(), not filter().` |
| `data()` has no savable fields         | `set($qb): ->data() contains no savable fields to update`            |
| UPDATE matches 0 rows                  | `No rows updated: no record matched the filter or ownership mismatch` |
| Ownership value missing (strict write) | `Invalid request: ownership field 'X' not included in ownership_data` |
| Non-ownership field in `ownership_data()` | `ownership_data(): 'X' is not an ownership field for model 'Y'`    |
| SELECT-only clause on a write builder  | `buildUpdate(): these SELECT-only clauses are not allowed on a write query: ...` |
| `buildUpdate()` with nothing to set    | `buildUpdate(): no columns to set.`                                  |

---

## See also

- [../README.md](../README.md) — overview & index.
- [GET.md](GET.md) — SELECT, filtering, OR/nested groups, ownership reads.
- [DELETE.md](DELETE.md) — `delete($qb)`.
