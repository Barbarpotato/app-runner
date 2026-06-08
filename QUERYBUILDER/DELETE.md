# QUERYBUILDER — `delete($qb)`

A `QUERYBUILDER` can drive a **DELETE** with the same rich WHERE it uses for SELECT — `OR`,
nested `where_group()`, `IN`, `BETWEEN`, `LIKE`, etc. Hand the builder to the engine's `delete()`.

The builder stays **pure** (it only assembles SQL); the engine resolves ownership and executes.

> **Filtering reference.** `delete($qb)`'s WHERE reuses the exact same filter API as reads —
> `filter()`, `or_filter()`, `where_group()`, operators (`=`, `IN`, `BETWEEN`, `LIKE`, `IS NULL`…),
> and raw filters with `values()`. They are documented once in **[GET.md](GET.md#filtering-where)**;
> this page covers only what is delete-specific.

---

## Table of Contents

1. [Quick Start](#quick-start)
2. [Method Reference](#method-reference)
3. [A filter is mandatory (full-table guard)](#a-filter-is-mandatory-full-table-guard)
4. [Ownership](#ownership)
5. [Guards & rejected clauses](#guards--rejected-clauses)
6. [The Delete Plan (`buildDelete`)](#the-delete-plan-builddelete)
7. [Best Practices](#best-practices)
8. [Error Reference](#error-reference)

---

## Method Reference

| Method                              | Purpose                                                       |
| ----------------------------------- | ------------------------------------------------------------ |
| `filter()/or_filter()/where_group()`| WHERE for the DELETE (see [GET.md](GET.md#filtering-where)). Mandatory; not allowed for ownership fields. |
| `hasConditions()`                   | True if a WHERE is present — the full-table guard (engine-managed). |
| `ownership_data($field[, $value])`  | Pin/scope an ownership field (must be configured; pin wins over header). |
| `buildDelete()`                     | Produce the pure DELETE plan.                                |

> SELECT-only methods (`fields`, `join`, `fk`, `children`, `properties`, `group_by`, `having`,
> aggregates, `distinct`, `sort`, pagination) and `data()` are **rejected**/ignored on a delete
> builder — see [Guards](#guards--rejected-clauses).

---

## Quick Start

```php
$engine->delete(
    (new QUERYBUILDER())
        ->filter('state', 'inactive', '=')
        ->filter('id', [10, 11, 12], 'IN')
);
```

```sql
DELETE FROM personalia_member
WHERE 1=1
  AND (personalia_member.state = :f0 AND personalia_member.id IN (:f1, :f2, :f3))
  AND personalia_member.member_number = :f4
-- :f0=inactive :f1=10 :f2=11 :f3=12 :f4=M001
```

---

## A filter is mandatory (full-table guard)

`delete($qb)` requires **at least one filter**. An empty builder is rejected — there is no
INSERT analog for DELETE, so a no-WHERE delete (which would wipe the table) always throws:

```php
$engine->delete(new QUERYBUILDER());
// throws: delete($qb): at least one filter is required (full-table guard)
```

If the statement matches **0 rows**, it also throws (`No rows deleted: ...`).

---

## Ownership

Identical to [`set($qb)`](SET.md#ownership) — **strict** and **OR-safe**:

- Value comes from a channel-level `->ownership_data('field', $value)` **pin** (wins over the
  header) or from `$GLOBALS['ownership_data']`.
- A missing ownership value (no pin, no header) **throws** (writes are strict).
- Ownership is **AND-ed at the outermost level**, so a top-level `OR` cannot delete rows outside
  the ownership scope.
- A non-ownership field passed to `ownership_data()` throws (smart validation).
- **Never via `filter()`.** Using an ownership field in `filter()` / `or_filter()` / a nested
  group / a raw filter **throws** at build — scoping must go through `ownership_data()`.

> A pinned value is **trusted** and is _not_ re-validated against the token's
> `ownership_data_binding` — only pin server-side values, never raw client input.

### Multiple ownership fields

Call `ownership_data()` once per field — each is AND-ed separately at the outermost level (pin some,
let the rest fall back to the header; same field twice → last wins).

```php
$qb->filter('state', 'inactive', '=')
   ->ownership_data('member_number', 'M001')
   ->ownership_data('organization_number', 'ORG01');
// DELETE ... WHERE 1=1 AND (state=:f0) AND member_number=:f1 AND organization_number=:f2
```

---

## Guards & rejected clauses

A write builder rejects SELECT-only clauses: `fields()`, `join()`, `fk()`, `children()`,
`properties()`, `group_by()`, `having()`, aggregates, `distinct()`, `sort()`, and pagination.

> Wrapper overrides still win: e.g. a model class that overrides `delete()` to `throw
> "Member cannot be deleted"` is unaffected — the QueryBuilder branch lives in the parent
> `_LindseyEngine`, so the wrapper's contract is preserved.

---

## The Delete Plan (`buildDelete`)

`buildDelete()` returns a pure plan (no DB access), analogous to `build()`:

```php
[
  'sql'    => 'DELETE FROM ... WHERE 1=1 ...',
  'params' => [':f0' => ...],   // WHERE (:fN) named params
]
```

The engine drives this inside `delete()`; call it directly only for tests/custom execution.

---

## Best Practices

- **Scope tightly.** DELETE has no INSERT fallback — an over-broad WHERE deletes more than intended.
  Prefer precise filters (`id IN (...)`, explicit states) over loose ones.
- **Let ownership stay automatic.** It is AND-ed at the outermost level from the header; only pin a
  server-side value when you have a deliberate reason — never client input.
- **Don't rely on `sort()`/pagination to limit a delete** — they are rejected; a delete affects all
  matching rows.
- **Prefer a model wrapper for business rules.** If certain rows must never be deleted, enforce it in
  the model's `delete()` override — it still wins over the QueryBuilder branch.

---

## Error Reference

| Situation                                 | Message (excerpt)                                                     |
| ----------------------------------------- | -------------------------------------------------------------------- |
| `delete($qb)` with no filter              | `delete($qb): at least one filter is required (full-table guard)`    |
| Ownership field used in `filter()` (struct/raw) | `filter(): ownership field 'X' must be set via ownership_data(), not filter().` |
| DELETE matches 0 rows                     | `No rows deleted: no record matched the filter or ownership mismatch` |
| Ownership value missing (strict write)    | `Invalid request: ownership field 'X' not included in ownership_data` |
| Non-ownership field in `ownership_data()` | `ownership_data(): 'X' is not an ownership field for model 'Y'`      |
| SELECT-only clause on a write builder     | `buildDelete(): these SELECT-only clauses are not allowed on a write query: ...` |

---

## See also

- [../README.md](../README.md) — overview & index.
- [GET.md](GET.md) — SELECT, filtering, OR/nested groups.
- [SET.md](SET.md) — `set($qb)` (UPDATE / INSERT) and `data()`.
