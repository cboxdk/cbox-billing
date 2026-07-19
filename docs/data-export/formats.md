---
title: Formats — CSV & NDJSON
description: The two export encodings — RFC-4180 CSV for spreadsheets and newline-delimited JSON for warehouses — and exactly how each column type is rendered in each.
weight: 20
---

# Formats — CSV & NDJSON

Every dataset streams as either **CSV** or **NDJSON**. The choice is a query/config value;
the same typed schema drives both.

## CSV (`text/csv`)

RFC-4180: one header row of column names, then one row per record. Quoting is done by PHP's
native `fputcsv` (a comma delimiter, `"` enclosure, `\r\n` line ending), so a value
containing a comma, quote or newline is escaped correctly.

Rendering per column type:

| Type | CSV cell |
|---|---|
| `string` | the value, quoted if needed |
| `integer` | the number, unquoted |
| `boolean` | `true` / `false` |
| `timestamp` | ISO-8601 UTC string (e.g. `2026-06-15T10:00:00Z`) |
| `json` | the compact JSON string |
| null | empty field |

CSV is the interchange for spreadsheets and finance/RevOps tooling.

## NDJSON (`application/x-ndjson`)

Newline-delimited JSON — one JSON object per line, no header. This is the format
Snowflake, BigQuery and Redshift load natively, and the only meaningful encoding for the
raw usage-event stream (which carries typed and nullable fields).

Types are **preserved**:

| Type | NDJSON value |
|---|---|
| `string` | JSON string |
| `integer` | JSON number |
| `boolean` | JSON `true` / `false` |
| `timestamp` | ISO-8601 UTC string |
| `json` | a **nested** JSON object/array (decoded from its stored string, not double-encoded) |
| null | JSON `null` |

Example line from `usage_events`:

```json
{"event_id":"evt-1","org":"acme","meter":"api.requests","service":"edge","value":4200,"unique_key":null,"weight":1,"occurred_at":"2023-11-14T22:13:20.000Z","occurred_at_ms":1700000000000}
```

Note the raw millisecond epoch (`occurred_at_ms`) is exported alongside the ISO-8601
`occurred_at`, so a warehouse can re-partition on the exact instant without reparsing.

## Money & currency

All money is an integer count of **minor units** in a `*_minor` column, paired with the
row's ISO-4217 `currency`. A consumer scales deterministically (`amount_minor / 100` for a
2-decimal currency). No amount is ever exported as a float.
