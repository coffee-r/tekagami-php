# tekagami-php

> [!NOTE]
> This package is experimental.

`coffee-r/tekagami-php` is the PHP observer for tekagami. It records what a
running PHP web application actually did as one `tekagami-v1` JSONL record per
HTTP request.

The observer emits observed facts: HTTP input/output shapes, SQL timeline,
custom events, write effects, and capture errors. It does not infer business
rules, name specifications, or measure performance as an APM.

For the language-neutral JSONL contract, see
[`contracts/schema`](../contracts/schema/README.md). For deterministic
`summary`, `report`, `export`, and `diff` commands, see
[`tools/tekagami-data`](../tools/tekagami-data/README.md).

## Installation

```bash
composer require coffee-r/tekagami-php
```

Runtime requirement: PHP 7.0 or later.

Development tests require PHP 7.3 or later because PHPUnit `^9.5` is used.

## Basic Usage

Create one collector per HTTP request, call `start()` near request entry, add
observed events during the request, then call `finish()` near response output.

```php
use CoffeeR\Tekagami\Collector;
use CoffeeR\Tekagami\Config;
use CoffeeR\Tekagami\Flow;
use CoffeeR\Tekagami\Http\HttpInput;
use CoffeeR\Tekagami\Http\HttpResponse;
use CoffeeR\Tekagami\Sink\JsonlSink;
use CoffeeR\Tekagami\Sql\SqliteSqlAnalyzer;

$collector = new Collector(
    new Config(getenv('TEKAGAMI_SECRET') ?: null),
    new JsonlSink('/var/log/tekagami/your-app.production.jsonl'),
    new SqliteSqlAnalyzer()
);

$http = new HttpInput($request->method(), $request->path());
$http->pathPattern       = '/products/{id}';
$http->queryRaw          = $request->query();
$http->requestRaw        = $requestBody;
$http->requestHeadersRaw = $requestHeaders;

$collector->start($http);

// Use Flow only when a development or QA scenario needs explicit correlation.
// $collector->start($http, new Flow('qa-order-cod-001', 1));

// Preferred: placeholder SQL plus binds.
$collector->addSql($sql, $binds, ['source' => 'db-wrapper']);

// Fallback: expanded SQL from last_query() or query history.
$collector->addExpandedSql($db->last_query(), ['source' => 'ci3-last_query']);

// Stable labels can act as observation anchors for important branches.
$collector->addCustom('purchase_limit_rejected', ['reason' => 'one_time']);

$response = new HttpResponse();
$response->status             = http_response_code();
$response->responseKind       = 'json';
$response->contentType        = 'application/json';
$response->responseHeadersRaw = $responseHeaders;
$response->responseBodyRaw    = $responseData;

$collector->finish($response);
```

If no `Flow` is passed, `flow.flow_id` and `flow.seq` are recorded as `null`.
This is the normal mode. Flow is for explicit investigation scenarios, not for
inferring business workflows from production sessions.

## Public API

`Collector` implements `CollectorInterface`:

| Method | Purpose |
|---|---|
| `start(HttpInput $http, $flow = null)` | Starts one request trace. |
| `getActiveTraceId()` | Returns the active trace id, or `null`. |
| `addSql($sql, array $binds = [], array $options = [])` | Records placeholder SQL plus binds. Preferred when available. |
| `addExpandedSql($sql, array $options = [])` | Records already-expanded SQL. Lower confidence because binds are lost. |
| `addCustom($label, $data = null)` | Records a custom observation with `data_shape`. |
| `addError($type, $message = null, $at = null)` | Records an observed application or capture error. |
| `finish(HttpResponse $response)` | Builds and writes the JSONL record, then resets the collector. |

All methods are designed not to throw into the observed application. If sink
writing fails, tekagami writes to PHP `error_log()` and lets the application
continue.

## Configuration

Constructor:

```php
new Config($secret = null, array $options = [])
```

`$secret` is the HMAC-SHA256 shared secret. Other settings are passed through
`$options`.

| Option | Type | Default | Description |
|---|---|---|---|
| `secret` | `string|null` | `null` | First constructor argument. Enables irreversible HMAC token fields when set. |
| `keepKeys` | `array` | `[]` | Case-insensitive allowlist of query/body keys whose raw values may be recorded in `*_values`. |
| `keepHeaderKeys` | `array` | `[]` | Case-insensitive allowlist of request headers to record as shape/token. Empty means no request header presence is recorded. |
| `keepResponseHeaderKeys` | `array` | `[]` | Case-insensitive allowlist of response headers to record as shape/token. Empty means no response header presence is recorded. |
| `sqlValueAllowlist` | `array` | `[]` | SQL value allowlist, using `table.column` or `column`, for `observed_values`. |
| `enabled` | `bool` | `true` | When `false`, collector methods return immediately. Lower cost than `NullSink`. |
| `captureText` | `bool` | `false` | Stores raw SQL in `statement_text`. Plaintext and not recommended for production. |
| `captureEffects` | `bool` | `true` | Emits write summaries in `effects[]`. |
| `tokenHmacLength` | `int` | `12` | Hex length of generated HMAC token fragments. |
| `maxDepth` | `int` | `10` | Maximum recursion depth for shape generation. |
| `maxShapeNodes` | `int` | `10000` | Maximum visited nodes during shape generation. |
| `maxTimelineSize` | `int|null` | `500` | Maximum timeline events. Extra events are ignored and recorded as capture failure. `null` means unlimited. |

## Value Safety

Raw values are not recorded by default. Shapes preserve structure and scalar
types. HMAC tokens, when `secret` is set, allow correlation of equal values
without storing the original value.

Only values explicitly allowed by `keepKeys` or `sqlValueAllowlist` are stored
as plaintext. Do not allowlist passwords, tokens, email addresses, phone
numbers, addresses, postal codes, authentication data, payment data, customer
ids, order ids, or other values strongly tied to people or transactions. Use
tokens for correlation-only use cases.

Request and response headers are stricter: only headers named in
`keepHeaderKeys` or `keepResponseHeaderKeys` are recorded, and header raw values
are never stored. `Authorization`, `Cookie`, `Set-Cookie`, `Location`, and
`X-Api-Key` are sensitive even as presence information, so keep them out unless
there is a specific investigation reason.

Avoid passing raw exception messages to `addError()` in production. They can
contain DSNs, SQL, file paths, or user data. Prefer fixed error types or
sanitized messages.

## SQL Capture

Use the highest-quality SQL source available.

| API | Input | `analysis.input_quality` | Typical source |
|---|---|---|---|
| `addSql($sql, $binds, ['source' => '...'])` | Placeholder SQL plus binds | `bound_sql` | PDO wrapper, DB middleware, Laravel `DB::listen`, Doctrine middleware, patched CI3 DB driver |
| `addExpandedSql($sql, ['source' => '...'])` | Expanded SQL | `expanded_sql` | CI3 `last_query()` or query history when binds are unavailable |

`addExpandedSql()` records warnings such as
`expanded_sql_may_fragment_statement_hash`. It is useful when there is no better
source, but prefer `addSql()` for migration comparison because statement hashes
are more stable.

`sqlValueAllowlist` extraction is best-effort and regex based. It handles common
`INSERT ... VALUES (...)`, `UPDATE ... SET ...`, and `WHERE col = value` cases,
but may miss values inside functions, strings with commas, complex subqueries,
or dialect-specific literals. Missing extraction does not fail the observation;
`statement_normalized`, `statement_hash`, and `statement_fingerprint` remain the
primary evidence.

## SQL Dialect

The SQL analyzer is required as the third `Collector` constructor argument.
Bundled analyzers:

- `CoffeeR\Tekagami\Sql\OracleSqlAnalyzer`
- `CoffeeR\Tekagami\Sql\SqliteSqlAnalyzer`

```php
$collector = new Collector(
    $config,
    $sink,
    new CoffeeR\Tekagami\Sql\OracleSqlAnalyzer()
);
```

The selected dialect is recorded on each SQL event as `analysis.dialect`.

For PostgreSQL, MySQL, SQL Server, or another database, implement
`SqlAnalyzerInterface` or extend `AbstractSqlAnalyzer`, then inject that
implementation into `Collector`.

## Custom Events

`addCustom()` is for observations that SQL alone cannot explain: cache access,
external HTTP calls, queue pushes, file writes, or important application
branches.

The `data_shape` is not part of behavior pattern signatures. If a branch must be
compared as a distinct behavior, encode the stable distinction in the label, for
example `payment_method_rejected_cod` rather than only
`payment_method_rejected` with a data value.

## Log Volume

Primary controls:

- `maxTimelineSize`: prevents one request from producing an unbounded timeline.
- `maxShapeNodes`: bounds very large JSON or view variable shapes.
- OS-level log rotation: keeps JSONL files manageable over time.

When a limit is reached, tekagami truncates only its own observation and records
a `capture_failure` in `errors[]`. The application request continues.

## Data Tools

This package only writes `tekagami-v1` JSONL. Process those logs from the
repository root with the language-neutral data tools:

```bash
php tools/tekagami-data/bin/tekagami summary trace.jsonl
php tools/tekagami-data/bin/tekagami report trace.jsonl
php tools/tekagami-data/bin/tekagami export trace.jsonl > trace.export.json
php tools/tekagami-data/bin/tekagami diff legacy.export.json target.export.json
```

See [`tools/tekagami-data/README.md`](../tools/tekagami-data/README.md) for CLI
options and behavior.

## Schema

Output records must conform to
[`contracts/schema/tekagami-v1.schema.json`](../contracts/schema/tekagami-v1.schema.json).
Tests in this package include schema conformance checks for fixtures and
collector output.

## Running Tests

```bash
cd observer-php
composer install
./vendor/bin/phpunit
```

## License

MIT License - Copyright 2026 coffee-r
