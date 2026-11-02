# Declaring patches

Patches are declared in the root `composer.json`, or in any package named in `trust`. There are two shapes, depending on whether a patch is tied to one base version or gated on a range.

```json
{
    "extra": {
        "magento-patches": {
            "patches": [
                {
                    "id": "CVE-2024-34102",
                    "label": "CosmicSting",
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

## Field reference

| Field | Required | Description |
| --- | --- | --- |
| `id` | Yes | Adobe's identifier: `2026-08-001`, `APSB26-146`, `CVE-2024-34102`. Unique within a base line |
| `source` | Yes | Path to the patch file, inside the declaring package. Traversal out is refused, and remote URLs are not supported |
| `base` | Yes for `patches` | Base-version marker deciding whether the patch belongs to this install. Inherited from the line for `lines` entries |
| `label` | No | Short friendly name (`StyleSmuggler`, `Session Reaper`), shown beside the id in every report |
| `description` | No | Longer prose, shown under `magento-patches:list -v` |
| `depends` | No | Ids this patch is built on, in its own base line: its own package's, or another source's (see [Patches from several sources](#patches-from-several-sources)). Not valid in a cumulative line, which derives its own |

**Do not list the modules a patch happens to touch in `base`.** It is a base-version marker and nothing else. Listing modules is exactly what makes a `replace`d module lose an entire patch, and per-target classification already handles missing modules properly.

## Ordering

Most patches do not care what order they apply in, and the plugin does not invent one. Two things change that.

**A cumulative line.** Adobe's monthly patches each build on the one before, and nothing in their content reveals it: most of a month's files are its own, so most orders apply cleanly. Mark the line, and the order you list them in *is* the chain:

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

Nothing declares `depends`, and declaring one here is an error: the line already said the order, and a second way of saying it can only disagree. An edge derived this way cannot be forgotten, which is the point. A monthly chain missing a link applies out of order and says nothing.

**`depends`, for everything else.** A patch that is built on another but is not part of a cumulative line names it directly:

```json
{ "id": "ACME-2", "source": "...", "depends": ["ACME-1"] }
```

A patch with a prerequisite that cannot be found, or that is part of a cycle, is refused and never written, along with everything built on it: there is no order in which applying it would be right.

Patches apply in dependency order, so outside a cumulative line the order they are written in (including the order of an `include` list) carries no meaning.

## Patches from several sources

`depends` crosses packages, which array order never could: the store's own `composer.json`, or one trusted package, can build on another source's patch. The commonest reason is a month a package cannot ship (Adobe keeps some files for Adobe Commerce licence holders), added by the store itself on top of that package's chain:

```json
"extra": {
    "magento-patches": {
        "trust": ["samjuk/m2-meta-security-patches"],
        "lines": {
            "2.4.6-p15": {
                "base": { "magento/magento2-base": "2.4.6-p15" },
                "patches": [
                    {
                        "id": "2026-10-001",
                        "source": "patches/246p15-2026-10-001-CE.diff",
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

## Splitting declarations across files

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

Each file holds exactly the structure above, and the lists merge. A `source` inside one resolves **against that file's directory**, so a manifest can sit beside the patches it describes, which is the point of splitting at all. Confinement is unchanged: a path may not leave the package that shipped it, however deep the manifest is.

A named file that is missing, unparseable, or declares neither `patches` nor `lines` is a configuration error, never a quietly shorter list. A one-character typo in `"lines"` would otherwise drop a whole year of patches and exit `0`. Included files cannot include others, so the full set stays readable in one place.

This is deliberately not a directory scan. A scan decides what is a patch by where it sits, so a directory it fails to walk is a patch that silently does not apply, and it widens what a trusted package can pull in from a list you can read in review to whatever happens to be on disk.

One note on `include` in the **root** package: confinement there is the whole project, so a root `include` may point into `vendor/`. That is allowed, because the project asked for it, but the resulting patches are attributed to the root rather than to the package the file came from. Prefer trusting the package.

## Choosing which patches apply

Your project decides what it takes from a package it trusts. This lives in the **root** `composer.json` only: a dependency must not be able to switch off a sibling's patches, or its own.

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

Identifiers are an `id`, or `id@line` to be narrower. A bare id matches that id in **every** base version, because ids are unique only within a line. `2026-08-001` exists for each of them, and quietly picking one would be worse.

A bare id naming a patch that others are built on is refused outright: write `id@line`. A base version published next year that reused the id would otherwise go dark under a reason written about a different Magento release, taking the rest of that line with it.

**A skip removes the patch, it does not merely decline to add it.** On a store built fresh in CI those are the same thing; on a tree that has had `composer install` run against it for six months, the patch is already in `vendor/`, and `magento-patches:apply` takes it back off. `magento-patches:status` shows it as `PRESENT` until then, and a reversal that will not apply cleanly is a conflict rather than a forced write.

This is the one place the plugin removes a patch on its own, so it only happens for one named in your own config with a written reason, it reports every file it touched, and it refuses rather than forces.

**A skip is never invisible.** It keeps its row, a `SKIPPED` badge, your reason, a place in the headline count, and it turns the closing banner amber for as long as it is set. It never changes the exit code (you asked for this), but a hole in coverage that looks like coverage is the one thing this tool exists to prevent, and "the operator chose it" does not make it safe to hide.

Skipping a patch takes everything built on it, each naming its direct prerequisite. That follows the dependency edges, however they were arrived at: declared with `depends`, or derived from a cumulative line.

Rules that do nothing are errors rather than shrugs: a `skip` matching no patch, a `sources` key naming a package that declares none, and any key other than `skip`.
