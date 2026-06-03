# QUERYBUILDER Documentation

## Overview

`QUERYBUILDER` is a flexible, chainable query builder designed to work with `_LindseyEngine`. It allows you to construct SQL queries dynamically with support for:

- Structured filters
- Raw SQL filters
- Joins
- Relationships (children & properties)
- Sorting
- Pagination
- Foreign key expansion (`fk()`)

---

## Basic Usage

```php
$qb = new QUERYBUILDER('users');

$result = $qb->_get();
```

---

## Integration with `_LindseyEngine`

```php
$engine = new _LindseyEngine('users', $jsonData);

$result = $engine->_get(
    (new QUERYBUILDER())
        ->fields(['id', 'name'])
        ->filter('status', 'active', '=')
);
```

---

## Selecting Fields

```php
$qb->fields(['id', 'name', 'email']);
```

- Default: `*`
- Automatically includes `id` if not specified

---

## Filtering

### Structured Filter

```php
$qb->filter('status', 'active', '=');
```

### Supported Operators

- `=`, `!=`, `>`, `<`, `>=`, `<=`
- `LIKE`, `NOT LIKE`
- `IN`, `NOT IN`
- `BETWEEN`, `NOT BETWEEN`
- `IS NULL`, `IS NOT NULL`

### Examples

```php
$qb->filter('age', 18, '>=');

$qb->filter('name', '%john%', 'LIKE');

$qb->filter('id', [1,2,3], 'IN');

$qb->filter('created_at', ['2024-01-01', '2024-12-31'], 'BETWEEN');
```

---

## Raw Filters

```php
$qb->filter("salary > :minSalary")
   ->values([
       ':minSalary' => 5000
   ]);
```

### Rules

- Must use named placeholders (`:name`)
- Must match values count exactly

---

## Dynamic Filter Methods

```php
$qb->filter_by_status('active');
```

Equivalent to:

```php
$qb->filter('status', 'active', '=');
```

---

## Sorting

```php
$qb->sort('created_at', 'DESC');
```

Multiple:

```php
$qb->sort([
    'name' => 'ASC',
    'id' => 'DESC'
]);
```

---

## Pagination

```php
$qb->page_number(1)
   ->per_page(10);
```

### Output (via globals)

```php
$GLOBALS['pagination_data'] = [
    'total_count' => ...,
    'total_page' => ...
];
```

---

## Joins

### Auto Join

```php
$qb->join('companies');
```

Automatically becomes:

```sql
users.company_id = companies.id
```

### Custom Join

```php
$qb->join('companies', 'users.company_id = companies.id');
```

---

## Foreign Key Expansion (`fk()`)

```php
$qb->fk('companies');
```

This will:

- Join `companies`
- Add `companies.id AS companies_id` into SELECT

Custom FK:

```php
$qb->fk('companies', 'company_ref_id');
```

---

## Properties (Belongs To)

```php
$qb->properties('company', ['id', 'name']);
```

- Uses `company_id` from main table
- Returns a single object

### Result Example

```json
{
	"id": 1,
	"company_id": 10,
	"company": {
		"id": 10,
		"name": "Google"
	}
}
```

---

## Children (Has Many)

```php
$qb->children('orders', ['id', 'total']);
```

- Foreign key resolved via config
- Returns array

### Result Example

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

## Full Example

```php
$qb = (new QUERYBUILDER('users'))
    ->fields(['id', 'name', 'company_id'])
    ->filter('status', 'active', '=')
    ->filter('age', 18, '>=')
    ->join('companies')
    ->properties('company', ['id', 'name'])
    ->children('orders', ['id', 'total'])
    ->sort('created_at', 'DESC')
    ->page_number(1)
    ->per_page(10);

$result = $qb->_get();
```

---

## Ownership (via `_LindseyEngine`)

When used through `_LindseyEngine`, ownership filters are automatically injected:

```php
$GLOBALS['ownership_data'] = [
    'client_id' => 123
];
```

Equivalent to:

```sql
WHERE client_id = 123
```

---

## Internal Behavior Notes

- All filters use **named parameters** (`:f0`, `:f1`, ...)
- Prevents mixing positional and named parameters (PDO safe)
- Automatically qualifies fields with table name
- Prevents ambiguous column errors in joins

---

## Error Handling

### Common Exceptions

- Missing object name
- Invalid filter arguments
- Mismatched raw filter placeholders
- Missing foreign key in config
- Invalid child/property relation

---

## Best Practices

- Always use `fields()` to limit payload size
- Use structured filters over raw filters when possible
- Use `properties()` instead of manual joins for relations
- Avoid `*` in production queries
- Combine pagination with sorting

---

## Summary

`QUERYBUILDER` provides a powerful abstraction for:

- Clean query composition
- Safe SQL execution
- Config-driven relationships
- Scalable API querying

---
