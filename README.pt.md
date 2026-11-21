[English](README.md) | [Português](README.pt.md)

![PHP](https://img.shields.io/badge/PHP-%20^8.3-777BB4)
![PHPStan-Level](https://img.shields.io/badge/PHPStan-Level%209-224488)
![License](https://img.shields.io/badge/License-MIT-777)

# RUSH Code Tool

Entrega de código para hospedagem compartilhada com o
[RUSH](https://github.com/rushphp/rush).

> **Pré-1.0:** releases minor podem incluir mudanças incompatíveis.

## 📦 Instalação

```bash
composer require --dev rushphp/code-tool
```

Habilite no `rush.json`:

```json
{
    "tools": {
        "code": true
    }
}
```

## 🧱 Layout (obrigatório)

O `code.layout.json` na raiz do projeto define o que é publicado e como. Ele
não contém credenciais e é separado do `rush.json`.

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
rush code layout:init                  # template custom
rush code layout:init --preset=slim    # preset instalado/local
rush code layout:init --preset=slim --force
rush code layout:presets
rush code layout:validate
```

`custom` é embutido. Os demais presets são instalados como pacotes Composer que
declaram `extra.rush.code.presets` ou colocados em `.rush/code/presets/`.

O estado de deploy fica em `.deploy`: dentro de `root` quando o target tem um,
senão dentro de `webroot` (protegido). O tool é dono de releases, backups,
eventos e locks.

## 🚀 Comandos

```bash
rush code check --target=staging
rush code push --target=staging
rush code promote --from=staging
rush code releases
rush code rollback --target=production
```

## ⚙️ Configuração

```text
.rush/code/config.json
```

O `config.json` contém os defaults `global` e os targets `development`,
`staging` e `production`. Arquivos de autenticação SSH, espelhos locais de
release, presets e overrides ficam em `.rush/code/`. O layout do projeto é o
`code.layout.json`, separado e versionado.

```text
.rush/code/
├── config.json
├── auth/
├── stub/
├── releases/
├── events/
└── override/<target>/{application,public}
```

Todo o diretório `.rush/` é estado local e não deve ser versionado.

## 🤝 Contribuições

Contribuições são bem-vindas. Abra uma issue ou envie um pull request.

## 📄 Licença

MIT. Consulte [LICENSE](LICENSE).
