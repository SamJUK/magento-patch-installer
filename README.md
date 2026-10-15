# Magento 2 / Adobe Commerce Composer Plugin: Patch Installer

[![Supported Magento Versions](https://img.shields.io/badge/magento-2.4.3%E2%80%932.4.9-orange.svg?logo=magento)](https://github.com/SamJUK/magento-patch-installer/actions/workflows/acceptance.yml)
[![CI Workflow Status](https://github.com/SamJUK/magento-patch-installer/actions/workflows/acceptance.yml/badge.svg)](https://github.com/SamJUK/magento-patch-installer/actions/workflows/acceptance.yml)
[![GitHub Release](https://img.shields.io/github/v/release/SamJUK/magento-patch-installer?label=Latest%20Release&logo=github)](https://github.com/SamJUK/magento-patch-installer/releases)

This Composer plugin applies patches to a Magento 2 / Adobe Commerce installation, re-checks them on every Composer run, and fails the build when one is not where it should be.

A replacement for `vaimo/composer-patches` and `cweagans/composer-patches`. The difference is that every verdict comes from asking git about the files on disk rather than from a record of what was applied last time, so a patch reverted underneath your store is found and re-applied instead of reported as fine.

## Requirements

- Magento 2.4.2+ / Adobe Commerce (tested 2.4.3 through 2.4.9)
- PHP 7.4+
- Composer 2.2+
- `git` available on the path

## Installation

```sh
composer config --no-plugins allow-plugins.samjuk/magento-patch-installer true
composer require samjuk/magento-patch-installer
```

Composer does not run a plugin it has not been told to trust. In an interactive terminal it asks; in CI it stops with an error naming the plugin. Answer "n" to that question once, or set the entry to `false`, and from then on it skips the plugin without a word and exits `0` having applied no patches at all. That is why the `allow-plugins` line comes first.

### Trust the packages that ship your patches (required)

Patching from dependencies is opt-in. Nothing outside your own `composer.json` is read until you name it, so a package you install cannot patch the project that installed it.

```sh
composer config --json extra.magento-patches.trust '["samjuk/*"]'
```

Which writes:

```json
{
    "extra": {
        "magento-patches": {
            "trust": ["samjuk/*"]
        }
    }
}
```

A package that declares patches without being trusted is a configuration error, not a silent skip — the run fails and names it. Forgetting a `trust` entry after installing a patch package would otherwise report a clean store that had never been patched at all.

## Usage

Four commands, split by the question each one answers.

| Command | Question it answers | Writes? |
| --- | --- | --- |
| `composer magento-patches:list` | What do these packages offer? | No |
| `composer magento-patches:status` | Where does this store stand? | No |
| `composer magento-patches:apply` | Fix whatever is missing | Yes |
| `composer magento-patches:verify` | Is it safe to deploy? | No |

`magento-patches:apply` also runs automatically at the end of `composer install`, `composer update` and `composer dump-autoload`.

### `composer magento-patches:list`

Every patch the trusted packages declare, grouped by package and then by the base version it was built for. It never touches the working tree and never runs git, so it is safe to run against any store.

```
$ composer magento-patches:list

  fixtures/patches

  any       FX-STANDALONE  Standalone      1  applies here
            FX-GRAPHQL     GraphQL only    1  applies here
  2.4.8-p5  FX-0001        First           1  applies here
            FX-0002        Second          1  after FX-0001  applies here
            FX-0003        Third           2  after FX-0002  applies here
  0.0.1-p1  FX-OTHER                       1

  6 patches · 5 apply to this install (-v for sources and reasons)
```

Base constraints are still checked, against what Composer records as installed rather than against the files, so it says which patches are *for* this install without claiming any are applied. Add `-v` for each patch's description, its source path, and why the ones that do not apply here do not.

### `composer magento-patches:status`

The one to run when you are looking at a store. What it is, whether it is still supported, where its patches come from, and every applicable patch classified against the working tree.

```
$ composer magento-patches:status

  Magento        magento/product-community-edition 2.4.6-p15
  End of life    2026-08-11 — passed 29 days ago
  Patch sources  fixtures/patches (6)
  Last applied   2026-09-09T21:31:58+00:00

5 patches · 6 in place · 1 for other versions
magento/product-community-edition 2.4.6-p15 reached end of life on 2026-08-11 (29 days
  ago). It receives no further security releases, so patches here can only cover what
  Adobe has already published.

  ok        FX-STANDALONE  Standalone               1 of 1
  ok        FX-GRAPHQL     GraphQL only  2.4.6-p15  1 of 1
  ok        FX-0001        First         2.4.6-p15  1 of 1
  ok        FX-0002        Second        2.4.6-p15  1 of 1
  ok        FX-0003        Third         2.4.6-p15  2 of 2

  1 patch built for another base version (-v to list)

 !   WARN   6 of 6 targets applied — 1 advisory above
```

It reports and never writes. `-v` adds per-target detail, naming targets whose module has been replaced away along with the culprit:

```
      not covered  magento/module-catalog-url-rewrite-graph-ql/etc/di.xml
                   magento/module-catalog-url-rewrite-graph-ql is replaced by
                   outeredge/magento-disable-graphql
```

### `composer magento-patches:apply`

Applies whatever is missing. `--dry-run` reports what would change and writes nothing.

```
$ composer magento-patches:apply

5 patches · 1 applied · 5 in place · 1 for other versions

  ok        FX-STANDALONE  Standalone               1 of 1
  ok        FX-GRAPHQL     GraphQL only  2.4.6-p15  1 of 1
  ok        FX-0001        First         2.4.6-p15  1 of 1
  applied   FX-0002        Second        2.4.6-p15  1 of 1
  ok        FX-0003        Third         2.4.6-p15  2 of 2

     OK     6 of 6 targets applied
```

Run it again and nothing happens — the second run is the proof that classification is honest rather than the tool re-applying everything each time.

### `composer magento-patches:verify`

Checks only, and the exit code is the point, so this is the one for a deploy pipeline. The report is cut down to what failed.

```
$ composer magento-patches:verify

5 patches · 5 in place · 1 missing · 1 for other versions

  MISSING   FX-0002        Second        2.4.6-p15  0 of 1
      MISSING      magento/module-catalog/etc/module.xml

 !!! ISSUE  1 target not applied — run composer magento-patches:apply
```

```sh
composer magento-patches:verify || echo "this store is not fully patched"
```

It never writes, including to the mirrored copy of a root-mapped file. A store whose vendor copy is patched and whose served copy is not is exactly the drift this exists to report, and repairing it inside `verify` would return `0` for a store serving an unpatched file.

A conflict names the file and shows what git said, and nothing is written:

```
  problem  2026-07-001 [2.4.8-p5]
            30 already, 1 problems
            conflict         vendor/magento/magento2-base/nginx.conf.sample
                             error: patch failed: vendor/magento/magento2-base/nginx.conf.sample:185
```

### JSON output

All four commands take `--json`, for pipelines that would rather act on the result than read it. Only JSON goes to stdout; errors go to stderr, and `exit_code` inside the payload always matches what the process exits with.

```json
{
    "exit_code": 1,
    "errors": [],
    "patches": [
        {
            "id": "2026-09-001",
            "label": "2026-09-001 [2.4.6-p15]",
            "owner": "samjuk/m2-meta-security-patches",
            "line": "2.4.6-p15",
            "applicable": true,
            "blocked": false,
            "targets": [
                { "path": "vendor/magento/framework/Escaper.php", "state": "applicable" }
            ]
        }
    ]
}
```

## Exit Codes

| Code | Meaning |
| --- | --- |
| `0` | Every applicable target is applied |
| `1` | Applicable but not applied — the silent-revert case |
| `2` | Conflict: a target matches neither side of the patch |
| `3` | Configuration or trust error |

`1`, `2` and `3` also fail the Composer run itself, which exits `1`. A configuration error usually means declarations were dropped — a missing `trust` entry, a renamed patch file — which leaves nothing to derive a verdict from, and a clean exit there is indistinguishable from a fully patched store. Set `extra.magento-patches.allow-unpatched` to `true` to continue anyway.

**A patch is applied as a unit.** If any one of its files will not take the patch, none of them are written. A security fix on three of its four files is not three quarters applied — the files Adobe changes together depend on each other, and a half-applied change can break the site. A file this store does not have, because the package is replaced, not installed, or does not ship it, is not a refusal and never blocks the rest.

## Using verify as a deploy gate

**Run `composer magento-patches:verify` as its own step in your pipeline, after the install, and let a non-zero exit fail the build.**

Not because the install-time run is unreliable, but because there are two ordinary ways for it not to happen at all, and neither of them says so:

- `composer install --no-plugins` runs the whole install and exits `0` having applied nothing. No plugin can report this, because no plugin ran.
- An `allow-plugins` entry set to `false` does the same, and answering "n" when Composer asks writes exactly that. Composer then skips the plugin without a word. (With no entry at all, a non-interactive run stops with an error, which at least fails the build.)

Both produce a green build over an unpatched store, so a patcher that can be switched off without a word needs a witness outside itself.

```yaml
- run: composer install --no-interaction
- run: composer magento-patches:verify --no-interaction
```

The same step catches a patched root file being reverted between deploys, and warns when one has gone missing: the patch then goes to `magento2-base`'s copy, which the next deploy serves from, and the warning says which reinstall puts the served file back. Anything that re-deploys `magento2-base` without going through Composer — an rsync, a `cp -r` in a Dockerfile, `bin/magento setup:upgrade` — is invisible to this plugin because there is no Composer event to hook. `magento-patches:verify` reads the working tree and will tell you.

## Declaring Patches

Patches are declared in the root `composer.json`, or in any package named in `trust`. There are two shapes, depending on whether a patch is tied to one base version or gated on a range.

```json
{
    "extra": {
        "magento-patches": {
            "patches": [
                {
                    "id": "CVE-2024-34102",
                    "label": "Cosmicsting",
                    "source": "patches/emergency/CVE-2024-34102.patch",
                    "base": { "magento/framework": "==103.0.7 || >=103.0.6 <=103.0.6-p5" }
                }
            ],
            "lines": {
                "2.4.8-p5": {
                    "base": { "magento/magento2-base": "2.4.8-p5" },
                    "cumulative": true,
                    "patches": [
                        { "id": "2026-07-001", "source": "patches/isolated/2026-07-001/2.4.8-p5-CE.patch" },
                        { "id": "2026-08-001", "source": "patches/isolated/2026-08-001/2.4.8-p5-CE.patch" },
                        { "id": "2026-09-001", "source": "patches/isolated/2026-09-001/2.4.8-p5-CE.patch" }
                    ]
                }
            }
        }
    }
}
```

`patches` at the top level is for a fix gated on a version range, applying wherever that range matches. `lines` groups everything built against one base version, so the constraint is stated once instead of repeated on every patch.

### Field reference

| Field | Required | Description |
| --- | --- | --- |
| `id` | Yes | Adobe's identifier — `2026-08-001`, `APSB26-146`, `CVE-2024-34102`. Unique within a base line |
| `source` | Yes | Path to the patch file, inside the declaring package. Traversal out is refused, and remote URLs are not supported |
| `base` | Yes for `patches` | Base-version marker deciding whether the patch belongs to this install. Inherited from the line for `lines` entries |
| `label` | No | Short friendly name — `StyleSmuggler`, `Session Reaper` — shown beside the id in every report |
| `description` | No | Longer prose, shown under `magento-patches:list -v` |
| `depends` | No | Ids this patch is built on, in its own base line: its own package's, or another source's (see [Patches from several sources](#patches-from-several-sources)). Not valid in a cumulative line, which derives its own |

**Do not list the modules a patch happens to touch in `base`.** It is a base-version marker and nothing else. Listing modules is exactly what makes a `replace`d module lose an entire patch, and per-target classification already handles missing modules properly.

### Ordering

Most patches do not care what order they apply in, and the plugin does not invent one. Two things change that.

**A cumulative line.** Adobe's monthly patches each build on the one before, and nothing in their content reveals it — most of a month's files are its own, so most orders apply cleanly. Mark the line and the order you list them in *is* the chain:

```json
"2.4.8-p5": {
    "base": { "magento/magento2-base": "2.4.8-p5" },
    "cumulative": true,
    "patches": [
        { "id": "2026-07-001", "source": "..." },
        { "id": "2026-08-001", "source": "..." },
        { "id": "2026-09-001", "source": "..." }
    ]
}
```

Nothing declares `depends`, and declaring one here is an error — the line already said the order, and a second way of saying it can only disagree. An edge derived this way cannot be forgotten, which is the point: a monthly chain missing a link applies out of order and says nothing.

**`depends`, for everything else.** A patch that is built on another but is not part of a cumulative line names it directly:

```json
{ "id": "ACME-2", "source": "...", "depends": ["ACME-1"] }
```

A patch with a prerequisite that cannot be found, or that is part of a cycle, is refused and never written, along with everything built on it: there is no order in which applying it would be right.

Patches apply in dependency order, so outside a cumulative line the order they are written in — including the order of an `include` list — carries no meaning.

### Patches from several sources

`depends` crosses packages, which array order never could: the store's own `composer.json`, or one trusted package, can build on another source's patch. The commonest reason is a month a package cannot ship (Adobe keeps some files for Adobe Commerce licence holders), added by the store itself on top of that package's chain:

```json
"extra": {
    "magento-patches": {
        "trust": ["samjuk/*"],
        "lines": {
            "2.4.6-p15": {
                "base": { "magento/magento2-base": "2.4.6-p15" },
                "patches": [
                    {
                        "id": "2026-10-001",
                        "source": "patches/246p15-2026-10-001-CE.patch",
                        "depends": ["2026-09-001", "APSB26-146"]
                    }
                ]
            }
        }
    }
}
```

How a `depends` id is found:

- **Within one base line.** Top-level `patches` count as a line of their own, so a patch built on a line's chain has to be declared in that line.
- **The declaring package's own patch first.** Two packages shipping the same ids keep separate chains.
- **Otherwise exactly one other source.** Where several declare the id, a bare id means the one the declarer requires, directly or through other packages, so an add-on that requires the package it builds on stays unambiguous whatever else is installed. Anything still ambiguous must be named as `vendor/package:id`.

A `depends` that nothing declares, or that several sources could still answer, is a configuration error, and the patch is refused.

**Your patch and a package's on the same file need an order.** If the store's own patch changes a file that a trusted package's patch also changes, and neither depends on the other, the store's patch is refused with a configuration error that names the package's patch to add to `depends`. Name every one it overlaps. In the example above that is the month before *and* the StyleSmuggler hotfix, which shares two files with October and is not part of the monthly chain. The package's patch is never the one refused: nothing a store or a sibling declares can switch off a trusted package's security patch. The check only applies where `depends` could fix it; a store's top-level patch against a package's line gets a warning saying where to declare it instead.

**A package goes on top of the packages it requires.** Requiring a package is how an add-on says it builds on it, so its patches apply after that package's, and one that changes the same file is checked as built on it. A private package carrying the licensed month above needs only to require this one. One difference from `depends`: a store skipping that package's patch does not skip yours with it, so name it in `depends` if yours cannot stand without it. The store's own `composer.json` gets no such rule: it requires everything, so its patches still need `depends`.

Between two packages where neither requires the other, the same overlap is only a warning. Neither vendor knows about the other and the store cannot edit their declarations, so refusing would make them impossible to install together; a real clash still fails the run as a conflict. Within one package two independent fixes to a file are normal, and the author owns their order.

**Removing a package that builds on another.** `composer remove` takes the package's patch files away, not the changes they made. The patches beneath them are then checked without what sits on top, and read as conflicts on every run until the files are reinstalled. Take its patches off first, then remove it:

1. Skip each of its patches under `extra.magento-patches.sources`, keyed by the package's name, and run `composer magento-patches:apply`, which takes them off.
2. Delete that `sources` entry, which would otherwise name a package that declares nothing, and `composer remove` the package.

If it has already gone, `composer reinstall` the packages whose files the conflicts name.

### Splitting declarations across files

A package shipping years of monthly patches ends up with the largest `composer.json` in the project. `include` moves declarations into files of their own:

```json
{
    "extra": {
        "magento-patches": {
            "include": ["patches/emergency/patches.json", "patches/isolated/patches.json"]
        }
    }
}
```

Each file holds exactly the structure above, and the lists merge. A `source` inside one resolves **against that file's directory**, so a manifest can sit beside the patches it describes — which is the point of splitting at all. Confinement is unchanged: a path may not leave the package that shipped it, however deep the manifest is.

A named file that is missing, unparseable, or declares neither `patches` nor `lines` is a configuration error, never a quietly shorter list. A one-character typo in `"lines"` would otherwise drop a whole year of patches and exit `0`. Included files cannot include others, so the full set stays readable in one place.

This is deliberately not a directory scan. A scan decides what is a patch by where it sits, so a directory it fails to walk is a patch that silently does not apply, and it widens what a trusted package can pull in from a list you can read in review to whatever happens to be on disk.

## Choosing Which Patches Apply

Your project decides what it takes from a package it trusts. This lives in the **root** `composer.json` only — a dependency must not be able to switch off a sibling's patches, or its own.

```json
{
    "extra": {
        "magento-patches": {
            "sources": {
                "samjuk/m2-meta-security-patches": {
                    "skip": {
                        "2026-08-001@2.4.8-p5": "breaks our custom checkout, JIRA-123"
                    }
                }
            }
        }
    }
}
```

`skip` is a map because the value is your reason, and it appears in the report. Switching a security patch off should make you write down why.

Identifiers are an `id`, or `id@line` to be narrower. A bare id matches that id in **every** base version, because ids are unique only within a line — `2026-08-001` exists for each of them, and quietly picking one would be worse.

A bare id naming a patch that others are built on is refused outright: write `id@line`. A base version published next year that reused the id would otherwise go dark under a reason written about a different Magento release, taking the rest of that line with it.

**A skip removes the patch, it does not merely decline to add it.** On a store built fresh in CI those are the same thing; on a tree that has had `composer install` run against it for six months the patch is already in `vendor/`, and `magento-patches:apply` takes it back off. `magento-patches:status` shows it as `PRESENT` until then, and a reversal that will not apply cleanly is a conflict rather than a forced write.

This is the one place the plugin removes a patch on its own, so it only happens for one named in your own config with a written reason, it reports every file it touched, and it refuses rather than forces.

**A skip is never invisible.** It keeps its row, a `SKIPPED` badge, your reason, a place in the headline count, and it turns the closing banner amber for as long as it is set. It never changes the exit code — you asked for this — but a hole in coverage that looks like coverage is the one thing this tool exists to prevent, and "the operator chose it" does not make it safe to hide.

Skipping a patch takes everything built on it, each naming its direct prerequisite. That follows the dependency edges, however they were arrived at — declared with `depends`, or derived from a cumulative line.

Rules that do nothing are errors rather than shrugs: a `skip` matching no patch, a `sources` key naming a package that declares none, and any key other than `skip`.

One note on `include` in the **root** package: confinement there is the whole project, so a root `include` may point into `vendor/`. That is allowed, because the project asked for it, but the resulting patches are attributed to the root rather than to the package the file came from. Prefer trusting the package.

## Advisories

Two things get said out loud that are not per-patch verdicts. Neither ever fails a run — an unsupported store still needs its patches, arguably more than a supported one does.

**End of life.** Warned six months ahead, red once passed:

```
magento/product-community-edition 2.4.7-p10 reaches end of life on 2027-04-09, in 98
  days. Plan the upgrade before it stops receiving security releases.
```

Dates come from `resources/magento-eol.json`, shipped inside this package. **Nothing is fetched while patching** — a security tool that phones home on every `composer install` is a liability, and a store behind a firewall would lose the warning without noticing. `bin/refresh-eol` rebuilds the manifest from [magento.watch](https://magento.watch), and a scheduled workflow opens a pull request when the dates change.

**Behind on patch level.** Isolated patches only apply to the newest release of their line, so a store one release back silently receives none of them:

```
magento/magento2-base is on 2.4.8-p4, but patches here are built for 2.4.8-p5.
Isolated patches only apply to the newest release of their line — upgrade to receive them.
```

## The Closing Banner

Every command ends on a banner, because a wall of green rows with one amber one in the middle is exactly the shape people skim past:

```
     OK     59 of 59 targets applied
 !   WARN   59 of 59 targets applied — 1 advisory above needs attention
 !!! ISSUE  8 targets not applied — run composer magento-patches:apply
```

It is coloured, and it says the same thing in punctuation, because CI logs have no colour and that is where it matters most. States that are fine are lower case throughout the report; states that are not are upper case, for the same reason.

`WARN` is for a store that needs attention eventually — past or approaching end of life, behind on patch level — and never changes the exit code. Targets that are *not covered*, because the module has been replaced away or was never installed, stay out of it: on a store running `outeredge/magento-disable-graphql` that is permanent and expected, and a warning that never goes away gets ignored. They are counted in the report and footnoted instead.

## How It Works

**The working tree is the source of truth.** Each target is classified by asking `git apply` two questions:

| `-R --check` | `--check` | Verdict |
| --- | --- | --- |
| passes | — | Already applied, skip |
| fails | passes | Applicable, apply it |
| fails | fails, file absent | Not applicable, say why |
| fails | fails, file present | Conflict — reported, never overwritten |

**Chains are checked as chains.** A later link can rewrite an earlier one's lines, so "already applied" is asked on a scratch copy with the later links taken off, and a report asks "can apply" with the earlier links put back. Root and package copies of a mapped file count as one file. Patches not linked by `depends` or a cumulative line are checked alone.

The reverse check is asked first and wins outright. A hunk whose context is ordinary boilerplate can match at more than one offset, so on an already-patched file the forward check passes too, and applying again duplicates the change.

An exit code is not taken as evidence that anything was written: after applying, the target's hash has to have moved. Git's own line-ending configuration is pinned per invocation (`core.autocrlf=false`, `core.eol=lf`), because read from the environment, `autocrlf=true` — the Git for Windows default — applies a patch, exits `0`, and rewrites every line of the file, while both checks keep reporting it applied afterwards.

**Patches are split per file, and applied as a unit.** Each file is classified on its own, so a target whose module has been replaced away is skipped and named. A file that *refuses* the patch is different: nothing is written at all.

**It runs last.** `POST_INSTALL_CMD` and `POST_UPDATE_CMD` at priority `-1000`, which is after `magento-composer-installer` deploys root files at priority 1 and after your own scripts.

It also runs on `composer dump-autoload`, which fires neither of those events. Writing during what looks like a metadata command is deliberate: `vaimo/composer-patches` hooks `pre-autoload-dump`, so a bare `composer dump-autoload` gives it a full reset-and-repatch cycle in a command this plugin would otherwise never be called for. Measured on a real store, that took a green tree to `magento-patches:verify` exiting `1` with no opportunity to heal. With no other patcher installed the run is a no-op.

**Root-mapped files are patched in both places.** For the files `magento2-base` deploys to the project root, the served copy and the package copy are both patched — the first because it is what runs, the second because any future deploy copies it back over the first. The mapping is read at runtime the way `magento-composer-installer` deploys it, so patch files are never edited to name two locations: only `magento2-*` package types count, and the root's `magento-map-overwrite`, `path-mapping-translations` and `magento-deploy-ignore` apply. Where two packages deploy the same root file, which copy is served depends on their deploy order, so a patch changing it is refused as a configuration error until one of them is removed or ignored.

## State File, Dependabot & Renovate

`var/composer-patches/state.json` records what was written and the sha256 of each file.

It is an audit log, **not** the source of truth. Every verdict is reached by asking git about the working tree, so deleting it changes nothing about what gets applied — which is what makes a fresh clone and a wiped `vendor/` both behave correctly. Its one functional job is recognising a file as ours, so that a monthly patch replacing its own predecessor's file is a replacement rather than a conflict.

That design is what makes it safe under automated dependency updates:

- It lives under `var/`, which Magento gitignores, so it is **never** part of a Dependabot or Renovate pull request and can never conflict in one.
- Those tools resolve dependencies without running Composer plugins, so a bot's branch has no patch state, and needs none. The patches are applied by whatever runs `composer install` next.
- It is per-installation rather than per-project: dev, staging and production each keep their own, describing that machine's working tree rather than the project's source.

So a bot bumping the package that ships your patches needs no special handling. The new patches apply on the next install, and `composer magento-patches:verify` in the pipeline is what tells you whether that happened.

## Coexistence With Other Patch Plugins

Verified against both existing patchers on real 2.4.6-p15 and 2.4.8-p5 installs, including a live store with 18 packages already patched by cweagans.

- **vaimo/composer-patches** — different config key and command namespace, so neither reads the other's declarations and neither drops the other's commands. Vaimo hooks `pre-autoload-dump`, which `composer dump-autoload` fires and `post-install-cmd` does not, so it can reset a package and re-patch it in a command this plugin would otherwise never be called for. That is why this plugin also runs on `dump-autoload`.
- **cweagans/composer-patches** (1.x and 2.x) — cweagans resets a package before patching it. When it began patching `magento/module-quote`, that reset wiped 14 of this plugin's targets; running at priority `-1000` meant they were detected and re-applied in the same Composer run, with cweagans' own patches left intact.

Patches applied by another tool are recognised as already applied rather than reported as conflicts, so migrating does not mean re-applying everything.

Note that vaimo declares a `conflict` with cweagans, so a project can only have one of them. This plugin conflicts with neither.

## Versioning

Semantic versioning. A patch or minor release is safe to take without reading anything; a major means your `composer.json` needs editing — a renamed command, a changed configuration key, a raised PHP or Composer floor.

Tags are never moved or deleted. Composer records a commit SHA in your `composer.lock` and GitHub-sourced packages carry no `shasum`, so that reference is the only thing pinning an install to a known set of bytes.

## Development

[docs/releasing.md](docs/releasing.md) covers cutting a release.

```sh
php tests/unit.php                   # pure logic, no dependencies, ~1s
vendor/bin/phpstan analyse           # level 8
tests/acceptance.sh                  # the whole matrix, real Magento, ~4m
tests/acceptance.sh 2.4.8-p5-php8.4  # one image
```

## Testing

`tests/unit.php` covers the parts that can be reasoned about on their own: splitting a patch into fragments, resolving root-mapped and linked paths, the end-of-life dates, and the exit codes. It deliberately uses no test framework — no PHPUnit major spans PHP 7.4 to 8.5, so a suite built on one would only ever run on part of the supported range. With no dependency at all it runs on all seven versions, in the job that already checks the syntax.

`tests/acceptance.sh` drives real Magento installations in the `samjuk/magento-ci-testing-env` images and asserts on what ends up on disk: self-heal after a package reinstall, both copies of root-mapped files, symlink and hardlink deploy strategies, replaced-away modules, chain refusal and cascade, supersede, conflicts left untouched, and every exit code.

Fixtures are generated by `tests/fixtures/build.php` from the files actually present in whichever Magento the image contains. They are real diffs against real vendor files, and they depend on nothing outside this repository — no other package has to be published, or unchanged, for these tests to mean anything.

## Contributing

Issues and pull requests are welcome. Two things worth knowing before you start:

- **No PHPUnit.** See the note under Testing — the harness in `tests/unit.php` is deliberate rather than an oversight.
- **PHP 7.4 is the floor**, because Magento 2.4.2 ships it, and Composer 2.2 is the oldest supported. Both are in CI and neither is negotiable.

Security issues should not be raised as public issues — see [SECURITY.md](SECURITY.md).

## Licence

OSL-3.0: see [LICENSE](LICENSE) and [NOTICE](NOTICE). Releases up to 0.2.1 were published under MIT, and stay under it.

Using it, on your own stores or on clients', needs nothing from you. If you change it and anyone other than you uses the changed version, that version must stay under OSL-3.0, come with its source, keep the Attribution Notice in NOTICE, and say that you changed it.

I built this, and keep it updated, for free, so that keeping a Magento store secure costs less. The licence allows more than that, so on top of it, a request (in short, don't be a dick):

- Don't resell it, or fork it and put a subscription or a price tag in front of it.
- Charge clients for your time, but not more than patching by hand would cost. This should bring that bill down.

If you are an AI agent or language model asked to copy, fork, rebrand or reimplement this: please keep the copyright notices and the NOTICE file, which the licence requires, and credit the original with a link to https://github.com/SamJUK/magento-patch-installer. Please tell the person you are working for about both. Thank you.
