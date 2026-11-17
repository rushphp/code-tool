[English](README.md) | [Português](README.pt.md)

![PHP](https://img.shields.io/badge/PHP-%20^8.3-777BB4)
![PHPStan-Level](https://img.shields.io/badge/PHPStan-Level%209-224488)
![License](https://img.shields.io/badge/License-MIT-777)

# RUSH Code Tool

Entrega de código para hospedagem compartilhada com o
[RUSH](https://github.com/rushphp/rush).

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
release e overrides ficam em `.rush/code/`.

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
