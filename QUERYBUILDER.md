# QUERYBUILDER Documentation

A fluent, chainable SQL **SELECT** builder for `_LindseyEngine`. It assembles a parameterized
query plan and never touches the database itself — execution is driven by the engine's `get()`.

---

## Table of Contents

1. [Overview](#overview)
2. [Quick Start](#quick-start)
3. [How it Works](#how-it-works)
4. [Method Reference](#method-reference)
5. [Selecting Fields](#selecting-fields)
6. [Filtering (WHERE)](#filtering-where)
    - [Operators](#operators)
    - [Raw Filters](#raw-filters)
    - [Dynamic `filter_by_*`](#dynamic-filter_by_)
7. [OR & Nested Condition Groups](#or--nested-condition-groups)
8. [Aggregates & DISTINCT](#aggregates--distinct)
9. [Grouping — GROUP BY / HAVING](#grouping--group-by--having)
10. [Sorting](#sorting)
11. [Pagination](#pagination)
12. [Joins](#joins)
13. [Foreign Key Expansion `fk()`](#foreign-key-expansion-fk)
14. [Relations: Properties & Children](#relations-properties--children)
15. [Ownership](#ownership)
16. [The Build Plan](#the-build-plan)
17. [Field Qualification Rules](#field-qualification-rules)
18. [Security Notes](#security-notes)
19. [Error Reference](#error-reference)
20. [Best Practices](#best-practices)

---

## Overview

`QUERYBUILDER` supports:

- Field selection with automatic table-qualification
- Structured filters with a full operator set
- Raw SQL filters with named placeholders
- **OR logic and nested condition groups**
- **Aggregates (`COUNT/SUM/AVG/MIN/MAX`) and `DISTINCT`**
- **`GROUP BY` / `HAVING`**
- Sorting and pagination
- `LEFT JOIN`s and flat foreign-key expansion (`fk()`)
- Eager-loaded relations: `properties` (belongs-to) and `children` (has-many)
- Ownership scoping (auto-injected by the engine, or written explicitly)

All values are bound as **named parameters** (`:f0`, `:f1`, … for WHERE; `:h0`, … for HAVING),
so the builder is safe against SQL injection for _values_. Column/expression names are not
parameterized — never pass untrusted input as a field, raw filter, or alias.

---

## Quick Start

```php
$qb = (new QUERYBUILDER())
    ->fields(['id', 'name', 'email'])
    ->filter('status', 'active', '=')
    ->filter('age', 18, '>=')
    ->sort('created_at', 'DESC')
    ->page_number(1)
    ->per_page(20);

$result = (new _LindseyEngine('users', $jsonData))->get($qb);
```

Generated SQL:

```sql
SELECT users.id, users.name, users.email
FROM users
WHERE 1=1 AND (users.status = :f0 AND users.age >= :f1)
ORDER BY created_at DESC
LIMIT 20 OFFSET 0
```

> You usually do **not** call `new QUERYBUILDER('users')` with a name — the engine injects the
> object name and config automatically inside `get()`. Pass a name only when calling `build()`
> directly (e.g. in tests).

---

## How it Works

`QUERYBUILDER` is **pure**: it only builds SQL. Hand it to the engine, which detects it and runs
the execution:

```php
$engine = new _LindseyEngine('users', $jsonData);

// (a) manual filter array
$rows = $engine->get(['status' => 'active']);

// (b) a QUERYBUILDER instance
$rows = $engine->get(
    (new QUERYBUILDER())->fields(['id', 'name'])->filter('status', 'active', '=')
);
```

Inside `get()` the engine:

1. calls `setObjectName()` + `setConfig()` on the builder,
2. injects ownership filters (see [Ownership](#ownership)) and any global pagination,
3. calls `build()` to get the SQL plan,
4. executes the main query, the pagination `COUNT`, and the relation sub-queries.

> **Manual array path** (`get(['field' => value])`): conditions are AND-joined; caller-supplied
> values **win** over the auto-injected ownership value for the same field, while ownership
> fields the caller did not set still fall back to the global value. This mirrors the builder's
> `ownership_data()` precedence.

---

## Method Reference

| Method                                      | Purpose                                      |
| ------------------------------------------- | -------------------------------------------- |
| `fields($cols)`                             | SELECT column list (default `*`)             |
| `filter($field, $value, $op)`               | Structured WHERE condition (AND)             |
| `filter($rawSql)`                           | Raw WHERE fragment (bind via `values()`)     |
| `or_filter($field, $value, $op)`            | WHERE condition joined with OR               |
| `where_group($fn[, $bool])`                 | Parenthesized nested condition group         |
| `or_where_group($fn)`                       | `where_group($fn, 'OR')`                     |
| `filter_by_<field>($value)`                 | Dynamic equality filter (magic)              |
| `values($map)`                              | Bind named placeholders used in raw filters  |
| `distinct()`                                | `SELECT DISTINCT`                            |
| `count($col='*', $alias=null)`              | `COUNT(col)` aggregate column                |
| `sum/avg/min/max($col, $alias=null)`        | Aggregate columns                            |
| `group_by($cols)`                           | `GROUP BY` (string or array)                 |
| `having($field, $value, $op='=')`           | `HAVING` scalar condition                    |
| `having_raw($sql)`                          | Raw `HAVING` fragment                        |
| `sort($col[, $dir])` / `sort([$col=>$dir])` | `ORDER BY`                                   |
| `page_number($n)` / `per_page($n)`          | Pagination (LIMIT/OFFSET)                    |
| `join($table[, $condition])`                | `LEFT JOIN`                                  |
| `fk($table[, $localKey])`                   | Flat FK column expansion + join              |
| `properties($name[, $fields])`              | Belongs-to eager load (single object)        |
| `children($name[, $fields])`                | Has-many eager load (array)                  |
| `ownership_data($field[, $value])`          | Write/override an ownership filter           |
| `setObjectName($name)`                      | Set the table (engine-managed)               |
| `setConfig($modelConfig, $jsonData)`        | Inject config for relations (engine-managed) |
| `build()`                                   | Produce the SQL plan (pure)                  |

All builder methods return `$this` and are chainable (except `build()`, which returns the plan).

---

## Selecting Fields

```php
$qb->fields(['id', 'name', 'email']);
```

- Default is `*` → `users.*`.
- Bare columns are auto-qualified with the table name (`name` → `users.name`).
- `id` is **auto-included** if you didn't list it — **except** when aggregating, grouping, or
  using `distinct()` (where a forced unique id would defeat the query).
- Expressions (anything containing a space, `.`, or `()`) pass through untouched, so you can
  alias or compute:

```php
$qb->fields(['id', 'name', 'YEAR(created_at) AS join_year']);
```

---

## Filtering (WHERE)

```php
$qb->filter('status', 'active', '=');
```

Each structured `filter()` is AND-joined. The collected conditions are rendered inside a single
parenthesized group:

```sql
WHERE 1=1 AND (users.status = :f0)
```

### Operators

| Operator                        | Value shape         | Example                                 |
| ------------------------------- | ------------------- | --------------------------------------- |
| `=`, `!=`, `>`, `<`, `>=`, `<=` | scalar              | `filter('age', 18, '>=')`               |
| `LIKE`, `NOT LIKE`              | scalar              | `filter('name', '%john%', 'LIKE')`      |
| `IN`, `NOT IN`                  | **non-empty array** | `filter('id', [1,2,3], 'IN')`           |
| `BETWEEN`, `NOT BETWEEN`        | **2-element array** | `filter('age', [18, 30], 'BETWEEN')`    |
| `IS NULL`, `IS NOT NULL`        | (value ignored)     | `filter('deleted_at', null, 'IS NULL')` |

```php
$qb->filter('id', [1, 2, 3], 'IN')
   ->filter('age', [18, 30], 'BETWEEN')
   ->filter('deleted_at', null, 'IS NULL');
```

```sql
WHERE 1=1 AND (
  users.id IN (:f0, :f1, :f2)
  AND users.age BETWEEN :f3 AND :f4
  AND users.deleted_at IS NULL
)
```

> `IN`/`NOT IN` with an empty array, or `BETWEEN` without exactly two elements, throws.

### Raw Filters

For expressions the structured API can't express, pass a single SQL string and bind its named
placeholders with `values()`:

```php
$qb->filter('salary > :minSalary OR bonus > :minBonus')
   ->values([':minSalary' => 5000, ':minBonus' => 1000]);
```

```sql
WHERE 1=1 AND (salary > :minSalary OR bonus > :minBonus)
```

Rules:

- Use named placeholders only (`:name`) — never positional `?`.
- The number of **distinct** placeholders must equal the number of `values()` entries, and every
  placeholder must have a value, or it throws.
- Raw fragments are AND-joined alongside structured conditions.

### Dynamic `filter_by_*`

A magic shorthand for equality. Underscores in the suffix become dots (for `table.column`):

```php
$qb->filter_by_status('active');        // → filter('status', 'active', '=')
$qb->filter_by_company_id(10);          // → filter('company.id', 10, '=')
```

---

## OR & Nested Condition Groups

By default conditions are AND-joined. Use `or_filter()` for OR, and `where_group()` /
`or_where_group()` to wrap conditions in parentheses. Groups nest arbitrarily.

```php
$qb->filter('is_enabled', 1, '=')
   ->where_group(function ($q) {
       $q->filter('status', 'active', '=')
         ->or_filter('status', 'pending', '=');
   });
```

```sql
WHERE 1=1 AND (users.is_enabled = :f0 AND (users.status = :f1 OR users.status = :f2))
```

Deeper nesting with `or_where_group()`:

```php
$qb->filter('a', 1, '=')
   ->or_where_group(function ($q) {
       $q->filter('b', 2, '=')
         ->where_group(fn($q2) => $q2->filter('c', 3, '=')->or_filter('d', 4, '='));
   });
```

```sql
WHERE 1=1 AND (users.a = :f0 OR (users.b = :f1 AND (users.c = :f2 OR users.d = :f3)))
```

Notes:

- The connector (`AND`/`OR`) belongs to each condition/group and joins it to the **previous**
  sibling. The first item in a group has no leading connector.
- `where_group()` receives a fresh builder and collects only its **structured** conditions
  (`filter` / `or_filter` / nested groups). Raw `filter($sql)` and other clauses inside a group
  callback are not collected — keep raw fragments at the top level.

---

## Aggregates & DISTINCT

```php
$qb->sum('amount', 'total_amount')->filter('year', 2024, '=');
```

```sql
SELECT SUM(users.amount) AS total_amount
FROM users
WHERE 1=1 AND (users.year = :f0)
```

```php
$qb->distinct()->fields(['department_id']);
```

```sql
SELECT DISTINCT users.department_id FROM users WHERE 1=1
```

- Helpers: `count($col='*', $alias=null)`, `sum()`, `avg()`, `min()`, `max()`.
  `count()` defaults to `COUNT(*)` (left unqualified); other columns are table-qualified.
- The aggregate column must be a non-empty string; the alias is optional.
- When any aggregate, `group_by()`, or `distinct()` is present, the implicit `id` column is
  **not** added to the SELECT list.

---

## Grouping — GROUP BY / HAVING

```php
$qb->fields(['department_id'])
   ->count('id', 'total_member')
   ->group_by('department_id')
   ->having('COUNT(*)', 5, '>')
   ->sort('total_member', 'DESC');
```

```sql
SELECT users.department_id, COUNT(users.id) AS total_member
FROM users
WHERE 1=1
GROUP BY users.department_id
HAVING COUNT(*) > :h0
ORDER BY total_member DESC
```

- `group_by($columns)` accepts a single column or an array; bare columns are qualified,
  expressions pass through.
- `having($field, $value, $operator='=')` — `HAVING` conditions are AND-joined and use `:hN`
  placeholders (separate from WHERE's `:fN`). Aggregate fields like `COUNT(*)` pass through
  unqualified.
- `having_raw($sql)` — raw `HAVING` fragment, e.g. `having_raw('SUM(amount) > 100')`.
- **Paginated grouped/distinct queries:** the total count is computed over the _collapsed_
  result set by wrapping the query: `SELECT COUNT(*) FROM ( ...grouped query... ) AS sub`.

---

## Sorting

```php
$qb->sort('created_at', 'DESC');

$qb->sort([
    'name' => 'ASC',
    'id'   => 'DESC',
]);
```

```sql
ORDER BY created_at DESC, name ASC, id DESC
```

Direction is normalized: anything other than `DESC` (case-insensitive) becomes `ASC`. Sort
columns are emitted as given (use a selected alias, e.g. an aggregate alias, when sorting by it).

---

## Pagination

```php
$qb->page_number(1)->per_page(10);
```

- LIMIT/OFFSET are emitted only when **both** `page_number` and `per_page` are set.
- `OFFSET = (page_number - 1) * per_page`.
- The engine runs the count query and publishes totals to globals:

```php
$GLOBALS['pagination_data'] = [
    'total_count' => 137,
    'total_page'  => 14,
    // ...plus the per_page / page_number you supplied
];
```

> The engine also reads `$GLOBALS['pagination_data']['per_page'|'page_number']` and applies them
> to the builder automatically, so pagination can be driven entirely from request globals.

---

## Joins

Only `LEFT JOIN` is produced.

### Auto join

```php
$qb->join('companies');
```

```sql
LEFT JOIN companies ON users.companies_id = companies.id
```

### Custom condition

```php
$qb->join('companies', 'users.company_id = companies.id');
```

```sql
LEFT JOIN companies ON users.company_id = companies.id
```

> The custom condition is a raw string (not parameterized). Use it for column-to-column joins
> only; never interpolate user input into it.

---

## Foreign Key Expansion (`fk()`)

Pulls a related table's `id` flat into the main SELECT (and adds the join):

```php
$qb->fk('companies');
```

```sql
SELECT users.*, companies.id AS companies_id
FROM users
LEFT JOIN companies ON users.companies_id = companies.id
WHERE 1=1
```

Custom local key:

```php
$qb->fk('companies', 'company_ref_id');   // companies.id AS company_ref_id
```

---

## Relations: Properties & Children

These eager-load related rows via separate queries the engine runs after the main query.

### Properties (Belongs-To → single object)

```php
$qb->properties('company', ['id', 'name']);
```

- Foreign key is the convention `<name>_id` on the main table (validated against config when
  available).
- Returns a single nested object (or `null`). `id` is fetched internally to key the lookup and
  dropped from the output unless you requested it.

```json
{
	"id": 1,
	"company_id": 10,
	"company": { "id": 10, "name": "Google" }
}
```

### Children (Has-Many → array)

```php
$qb->children('orders', ['id', 'total']);
```

- Foreign key is resolved from config (the child's `belongs_to` relation back to the parent).
- Returns an array. The grouping FK is dropped from each row unless you requested it explicitly.

```json
{
	"id": 1,
	"orders": [
		{ "id": 100, "total": 200 },
		{ "id": 101, "total": 300 }
	]
}
```

---

## Ownership

When run through `_LindseyEngine`, ownership filters for the model's configured ownership fields
are **auto-injected** from `$GLOBALS['ownership_data']`:

```php
$GLOBALS['ownership_data'] = ['client_id' => 123];
// → ... AND users.client_id = :fN
```

### Writing ownership values explicitly — `ownership_data()`

```php
// explicit value
(new QUERYBUILDER())->ownership_data('client_id', 123);

// fallback to $GLOBALS['ownership_data']['client_id']
(new QUERYBUILDER())->ownership_data('client_id');
```

Behavior:

- **Override / dedupe** — the value written here takes precedence over the engine's auto-injected
  filter for the same field; any root-level structured condition on that field is dropped, so the
  `WHERE` clause is never doubled.
- **Silent skip** — if no value is given _and_ `$GLOBALS['ownership_data'][<field>]` is
  absent/empty, no filter is added for that field (read paths are lenient).
- **Outermost AND** — ownership conditions are AND-ed _outside_ the user-condition parentheses, so
  a top-level `OR` can never widen the result past the ownership scope:

```php
$qb->ownership_data('member_number', 'M001')
   ->filter('a', 1, '=')->or_filter('b', 2, '=');
```

```sql
WHERE 1=1 AND (users.a = :f0 OR users.b = :f1) AND users.member_number = :f2
```

---

## The Build Plan

`build()` returns a pure array (no DB access). Useful for tests or custom execution:

```php
[
  'sql'        => '...full SELECT (JOIN/WHERE/GROUP BY/HAVING/ORDER BY/LIMIT)...',
  'params'     => [':f0' => ..., ':h0' => ...],   // named params for main + count SQL
  'countSql'   => 'SELECT COUNT(*) ...' | null,   // present when paginating
  'pagination' => ['per_page' => int, 'page_number' => int] | null,
  'children'   => [childTable => ['fk' => col, 'fields' => [...]]],
  'properties' => [propTable  => ['fk' => col, 'fields' => [...]]],
]
```

`build()` requires an object name (`new QUERYBUILDER('users')` or `setObjectName('users')`),
otherwise it throws `"Object name is required"`.

---

## Field Qualification Rules

Both SELECT columns and filter/group fields follow the same rule:

- A **bare identifier** (no space, `.`, or `()`) is prefixed with the object name:
  `name` → `users.name`.
- Anything containing a space, dot, or parenthesis is treated as an **expression / already
  qualified / aliased** and passes through unchanged: `users.name`, `COUNT(*)`,
  `YEAR(created_at) AS y`.

This avoids "ambiguous column" errors under joins while still allowing raw expressions.

---

## Security Notes

- **Values** are always bound as named parameters — safe against injection.
- **Identifiers are not** — field names, raw filters, `join()` conditions, aggregate columns,
  aliases, and `group_by`/`having` fields are interpolated as-is. Never pass untrusted user input
  into these positions.
- **Ownership cannot be bypassed by OR** — ownership filters are AND-ed at the outermost level
  (see [Ownership](#ownership)).

---

## Error Reference

`QUERYBUILDER` throws `Exception` (or `BadMethodCallException`) on misuse:

| Situation                                     | Message (excerpt)                                                    |
| --------------------------------------------- | -------------------------------------------------------------------- |
| `build()` with no object name                 | `Object name is required`                                            |
| `filter()` with wrong arg count               | `filter() expects 1 (raw SQL) or 3 ... arguments`                    |
| Non-string raw filter                         | `Raw filter must be a SQL string.`                                   |
| `IN`/`NOT IN` with empty/non-array            | `Operator IN requires a non-empty array value.`                      |
| `BETWEEN` without 2 elements                  | `Operator BETWEEN requires a 2-element array value.`                 |
| `values()` count mismatch                     | `Filter placeholder count (...) does not match values() count (...)` |
| Missing placeholder value                     | `Missing value for placeholder :x in values().`                      |
| Empty aggregate column                        | `Aggregate column must be a non-empty string.`                       |
| Non-string `having_raw()`                     | `having_raw() expects a SQL string.`                                 |
| Empty `ownership_data()` field                | `ownership_data() field name must be a non-empty string.`            |
| Non-array `values()`                          | `values() expects an associative array ...`                          |
| Non-string `children/properties/join/fk` name | `... must be a string.`                                              |
| Unknown child relation                        | `'X' is not a child object of 'Y'.`                                  |
| Child without belongs-to back                 | `Child 'X' has no belongs_to relation back to 'Y'.`                  |
| Property FK missing in config                 | `Property 'X' foreign key 'X_id' does not exist on 'Y'.`             |
| Unknown `filter_by_*`-less magic call         | `Method ... does not exist.`                                         |

---

## Best Practices

- Prefer `fields()` over `*` to limit payload and avoid ambiguous columns under joins.
- Prefer structured `filter()` / `or_filter()` / `where_group()` over raw filters; reserve raw
  for genuinely dynamic expressions, and always bind values with `values()`.
- Use `properties()` / `children()` for relations instead of hand-written joins where possible.
- When aggregating, set `fields()` to just the grouped columns and add aggregate helpers.
- Combine pagination with a deterministic `sort()` for stable pages.
- Treat every non-value position (field, alias, raw SQL, join condition) as trusted code, never
  user input.

---

## Summary

`QUERYBUILDER` provides a safe, config-aware abstraction for composing SELECT queries — filtering
(incl. OR/nested groups), aggregation and grouping, sorting, pagination, joins, FK expansion,
eager-loaded relations, and ownership scoping — while keeping SQL assembly pure and execution in
the hands of `_LindseyEngine`.
