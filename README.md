[English](README.md) | [Português](README.pt.md)

![PHP](https://img.shields.io/badge/PHP-%20^8.3-777BB4)
![PHPStan-Level](https://img.shields.io/badge/PHPStan-Level%209-224488)
![License](https://img.shields.io/badge/License-MIT-777)

# RUSH Code Tool

Shared-host code delivery for [RUSH](https://github.com/rushphp/rush).

> **Pre-1.0:** minor releases may include breaking changes.

## 📦 Installation

```bash
composer require --dev rushphp/code-tool
```

This also installs the RUSH runtime (`rushphp/rush`) in the development
context, so `vendor/bin/rush` is available.

Enable it in `rush.json`:

```json
{
    "tools": {
        "code": true
    }
}
```

`rush install` creates the local `.rush/code` control layout even before
`code.layout.json` exists. Deploy commands still require a valid layout.

## 🧱 Layout (required)

`code.layout.json` at the project root defines what is published and how. It
contains no credentials and is separate from `rush.json`.

> Shared-host note: the tool assumes a single SSH key per host. Operator and
event records are an audit trail, not an authorization boundary; coordinate
production deploys with your team.

```json
{
  "php": { "min": "8.3" },
  "publish": [
    { "from": "application", "to": "$root/application" },
    { "from": "public", "to": "$webroot" }
  ],
  "env": [
    { "from": "application/env.json", "to": "$root/application/env.json" }
  ],
  "writable": ["$root/application/storage"],
  "exclude": ["application/storage", "application/env.json", "public/.maintenance"],
  "entrypoint": "public/index.php",
  "maintenance": { "type": "marker", "path": "$webroot/.maintenance" },
  "build": { "composer": true }
}
```

```bash
rush code layout:init                  # custom template
rush code layout:init --preset=slim    # installed/local preset
rush code layout:init --preset=slim --force
rush code layout:presets
rush code layout:validate
```

`custom` is built in. Other presets are installed as Composer packages
declaring `extra.rush.code.presets` or placed in `.rush/code/presets/`.

Deploy state lives in `.deploy`: under `root` when the target has one,
otherwise under `webroot` (protected). The tool owns releases, backups and
events.

## 🚀 Commands

```bash
rush code check --target=staging
rush code push --target=staging
rush code promote --from=staging
rush code promote --from=development --to=production
rush code releases
rush code rollback --target=production
```

## ⚙️ Configuration

```text
.rush/code/config.json
```

`config.json` holds `global` defaults and the `development`, `staging` and
`production` targets. SSH auth files, local release mirrors, presets and
overrides live under `.rush/code/`. The project layout is the separate,
versioned `code.layout.json`.

```text
.rush/code/
├── config.json
├── auth/
├── stub/
├── releases/
├── events/
└── override/<target>/{application,public}
```

The whole `.rush/` directory is local state and must not be committed.

## 🤝 Contributions

Contributions are welcome. Open an issue or submit a pull request.

## 📄 License

MIT. See [LICENSE](LICENSE).
