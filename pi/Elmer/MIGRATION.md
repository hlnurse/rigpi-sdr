# Migrating to v0.18

v0.18 renames configured knowledge inputs from **sources** to **connectors**.

The default `config.json` now includes:

```json
"config/connectors.json"
```

Older configurations containing a `sources` array remain supported. Internally, the builder maps `sources` to `connectors`, so existing v0.14–v0.17 configurations do not need an immediate rewrite.

To migrate manually:

1. Rename `config/sources.json` to `config/connectors.json`.
2. Rename the top-level `sources` array to `connectors`.
3. Optionally add `name`, `category`, `tags`, and `refresh` to each connector.
4. Keep connector-specific settings under `options`.

Connector types that are configured but not implemented are safely ignored while disabled.
