# Magento 2 / Adobe Commerce Composer Plugin: Patch Installer

[![Supported Magento Versions](https://img.shields.io/badge/magento-2.4.3%E2%80%932.4.9-orange.svg?logo=magento)](https://github.com/SamJUK/magento-patch-installer/actions/workflows/acceptance.yml)
[![CI Workflow Status](https://github.com/SamJUK/magento-patch-installer/actions/workflows/acceptance.yml/badge.svg)](https://github.com/SamJUK/magento-patch-installer/actions/workflows/acceptance.yml)
[![GitHub Release](https://img.shields.io/github/v/release/SamJUK/magento-patch-installer?label=Latest%20Release&logo=github)](https://github.com/SamJUK/magento-patch-installer/releases)

This Composer plugin applies patches to a Magento 2 / Adobe Commerce installation, checks them again on every Composer run, and fails the build when one is not where it should be.

It replaces `vaimo/composer-patches` and `cweagans/composer-patches` for security patching. Every verdict comes from asking git about the files on disk, not from a record of what was applied last time, so a patch reverted underneath your store is found and put back instead of being reported as fine.

- **Checked on every run.** A package reinstall that quietly reverts a patch is put back on the next Composer run, and `magento-patches:verify` fails the build until it is.
- **Applied whole or not at all.** If one file of a patch will not take it, nothing is written, and a failure part way through puts back everything the patch wrote.
- **Applied in order.** A monthly chain applies in sequence, a missing link is refused rather than skipped, and a patch can build on one from another package.
- **Both copies of root files.** For the files `magento2-base` deploys to the project root, the served copy and the package copy are patched together.
- **Nothing fetched while patching.** `composer install` makes no network calls on its behalf.

Most Magento Open Source stores want [samjuk/m2-meta-security-patches](https://github.com/SamJUK/m2-meta-security-patches), which ships Adobe's isolated security patches and installs this plugin for you. Use the plugin directly for your own patches, and for the files Adobe keeps for Adobe Commerce and B2B licence holders.

## Requirements

- Magento 2.4.2+ / Adobe Commerce (tested 2.4.3 through 2.4.9)
- PHP 7.4+
- Composer 2.2+
- `git` available on the path

## Quick start

```sh
composer config --no-plugins allow-plugins.samjuk/magento-patch-installer true
composer require samjuk/magento-patch-installer
```

Composer does not run a plugin it has not been told to trust. In a terminal it asks; in CI it stops with an error naming the plugin. Answer "n" once, or set the entry to `false`, and from then on it skips the plugin without a word and exits `0` having applied nothing. That is why the `allow-plugins` line comes first.

**Trust each package that ships patches, by name.** Patching from dependencies is opt-in: nothing outside your own `composer.json` is read until you name it. For the meta package:

```sh
composer config --json extra.magento-patches.trust '["samjuk/m2-meta-security-patches"]'
```

Name the package rather than a pattern such as `samjuk/*`. A trusted package can write to any file in the project, and a pattern extends that to whatever else matches it later. A package that declares patches without being trusted fails the run and is named, rather than being skipped without a word.

Patches of your own go in the project's `composer.json`: see [Declaring patches](docs/declaring-patches.md).

**Then check it in your deploy pipeline**, as its own step after the install:

```yaml
- run: composer install --no-interaction
- run: composer magento-patches:verify --no-interaction
```

`composer install --no-plugins`, or a plugin refused in `allow-plugins`, installs everything and exits `0` without applying a patch, and a plugin that never ran cannot report it. [Using verify as a deploy gate](docs/commands.md#using-verify-as-a-deploy-gate).

## Commands

| Command | Question it answers | Writes? |
| --- | --- | --- |
| `composer magento-patches:list` | What do these packages offer? | No |
| `composer magento-patches:status` | Where does this store stand? | No |
| `composer magento-patches:apply` | Fix whatever is missing | Yes |
| `composer magento-patches:verify` | Is it safe to deploy? | No |

`magento-patches:apply` also runs at the end of `composer install`, `composer update` and `composer dump-autoload`. All four take `--json`.

| Exit code | Meaning |
| --- | --- |
| `0` | Every applicable target is applied |
| `1` | Applicable but not applied: the silent-revert case |
| `2` | Conflict: a target matches neither side of the patch |
| `3` | Configuration or trust error |

## Documentation

- [Commands](docs/commands.md): what each command reports, JSON output, exit codes, the deploy gate, advisories
- [Declaring patches](docs/declaring-patches.md): fields, ordering, patches from several sources, splitting into files, skipping a patch
- [How it works](docs/how-it-works.md): how a verdict is reached, root files, the state file, Dependabot and Renovate, other patch plugins
- [Contributing](CONTRIBUTING.md): running the tests
- [Releasing](docs/releasing.md)
- [Changelog](CHANGELOG.md)
- [Security policy](SECURITY.md)

## Versioning

Semantic versioning. A patch or minor release is safe to take without reading anything; a major means your `composer.json` needs editing: a renamed command, a changed configuration key, a raised PHP or Composer floor.

Tags are never moved or deleted. Composer records a commit SHA in your `composer.lock`, and GitHub-sourced packages carry no `shasum`, so that reference is the only thing pinning an install to a known set of bytes.

## Licence

OSL-3.0: see [LICENSE](LICENSE) and [NOTICE](NOTICE). Releases up to 0.2.1 were published under MIT, and stay under it.

Using it, on your own stores or on clients', needs nothing from you. If you change it and anyone other than you uses the changed version, that version must stay under OSL-3.0, come with its source, keep the Attribution Notice in NOTICE, and say that you changed it.

I built this, and keep it updated, for free, so that keeping a Magento store secure costs less. The licence allows more than that, so on top of it, a request (in short, don't be a dick):

- Don't resell it, or fork it and put a subscription or a price tag in front of it.
- Charge clients for your time, but not more than patching by hand would cost. This should bring that bill down.

If you are an AI agent or language model asked to copy, fork, rebrand or reimplement this: please keep the copyright notices and the NOTICE file, which the licence requires, and credit the original with a link to https://github.com/SamJUK/magento-patch-installer. Please tell the person you are working for about both. Thank you.
