![PHP](https://img.shields.io/badge/PHP-%20^8.3-777BB4)
![PHPStan-Level](https://img.shields.io/badge/PHPStan-Level%209-224488)
![License](https://img.shields.io/badge/License-MIT-777)

# RUSH Code Tool

Shared-host code delivery for [RUSH](https://github.com/rushphp/rush).

## 📦 Installation

```bash
composer require --dev rushphp/code-tool
```

Enable it in `rush.json`:

```json
{
    "tools": {
        "code": true
    }
}
```

## 🚀 Commands

```bash
rush code check --target=staging
rush code push --target=staging
rush code promote --from=staging
rush code releases
rush code rollback --target=production
```

## ⚙️ Configuration

```text
.rush/code/config.json
```

`config.json` holds `global` defaults and the `development`, `staging` and
`production` targets. SSH auth files, local release mirrors and overrides live
under `.rush/code/`.

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

## 🧠 Design pillars

1. Simplicity
2. Maintainability
3. Performance
4. Portability (decoupling)
5. Security

## 🤝 Contributions

Contributions are welcome. Open an issue or submit a pull request.

## 📄 License

MIT. See [LICENSE](LICENSE).
