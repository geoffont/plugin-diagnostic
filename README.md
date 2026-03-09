# Plugin Diagnostic WordPress

Plugin WordPress de diagnostic pour détecter et corriger les blocs Gutenberg problématiques.

## Fonctionnalités

- **Scanner de blocs** — Détection automatique des blocs Gutenberg corrompus ou non enregistrés sur l'ensemble du site
- **Récupération de blocs** — Outil de récupération batch pour corriger les blocs en erreur
- **Générateur de posts** — Génération de contenu Gutenberg structuré

## Prérequis

- WordPress 5.0+
- PHP 7.4+
- Éditeur Gutenberg activé

## Installation

1. Copier le dossier dans `/wp-content/plugins/`
2. Activer le plugin depuis l'interface WordPress
3. Accéder au menu **Diagnostic** dans l'administration

## Architecture

```
plugin-diagnostic/
├── diagnostic.php          # Point d'entrée principal
├── autoload.php            # Chargement automatique PSR-4
├── src/
│   ├── Plugin.php          # Orchestration (Singleton)
│   ├── Common/
│   │   ├── Constants.php   # Constantes globales
│   │   └── Functions.php   # Utilitaires
│   ├── Core/
│   │   ├── AdminMenu.php   # Menus d'administration
│   │   └── Assets.php      # Gestion des assets
│   └── Features/
│       ├── Scanner/        # Scan des blocs problématiques
│       ├── BlockRecovery/  # Récupération de blocs
│       └── PostGenerator/  # Génération de posts
└── tests/                  # Tests unitaires PHPUnit
```

## Types de problèmes détectés

| Code | Description | Sévérité |
|------|-------------|----------|
| `CREATE_BLOCK_UNREGISTERED` | Bloc create-block non enregistré | HIGH |
| `BLOCK_CONVERT_TO_HTML` | Bloc incompatible nécessitant une conversion | HIGH |
| `BLOCK_RECOVERY_MODE` | Bloc en mode tentative de récupération | MEDIUM |

## Développement

### Lancer les tests

```bash
./vendor/bin/phpunit
```

### Structure des features

Chaque feature suit le même pattern :

```
Feature/
├── Feature.php          # Initialisation, hooks, assets
├── Core/                # Logique métier
├── UI/Screens/          # Interface d'administration
└── Assets/              # JS et CSS
```

## Changelog

Voir [CHANGELOG.md](CHANGELOG.md) pour l'historique complet des versions.

## Auteur

Geoffroy Fontaine
