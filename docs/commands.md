# Commands

Four commands, split by the question each one answers.

| Command | Question it answers | Writes? |
| --- | --- | --- |
| `composer magento-patches:list` | What do these packages offer? | No |
| `composer magento-patches:status` | Where does this store stand? | No |
| `composer magento-patches:apply` | Fix whatever is missing | Yes |
| `composer magento-patches:verify` | Is it safe to deploy? | No |

`magento-patches:apply` also runs automatically at the end of `composer install`, `composer update` and `composer dump-autoload`.

## `composer magento-patches:list`

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

## `composer magento-patches:status`

The one to run when you are looking at a store: what it is, whether it is still supported, where its patches come from, and every applicable patch classified against the working tree.

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

It reports and never writes. `-v` adds per-target detail, and names the package that replaced a target's module away:

```
      not covered  magento/module-catalog-url-rewrite-graph-ql/etc/di.xml
                   magento/module-catalog-url-rewrite-graph-ql is replaced by
                   outeredge/magento-disable-graphql
```

## `composer magento-patches:apply`

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

Run it again and nothing happens. The second run is the proof that classification is honest, rather than the tool re-applying everything each time.

## `composer magento-patches:verify`

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

It never writes, including to the other copy of a root-mapped file. A store whose package copy is patched and whose served copy is not is exactly the drift this exists to report, and repairing it inside `verify` would return `0` for a store serving an unpatched file.

A conflict names the file and shows what git said, and nothing is written:

```
  problem  2026-07-001 [2.4.8-p5]
            30 already, 1 problems
            conflict         vendor/magento/magento2-base/nginx.conf.sample
                             error: patch failed: vendor/magento/magento2-base/nginx.conf.sample:185
```

## JSON output

All four commands take `--json`, for pipelines that would rather act on the result than read it. Only JSON goes to stdout and errors go to stderr. `exit_code` inside the payload always matches what the process exits with.

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

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Every applicable target is applied |
| `1` | Applicable but not applied: the silent-revert case |
| `2` | Conflict: a target matches neither side of the patch |
| `3` | Configuration or trust error |

`1`, `2` and `3` also fail the Composer run itself, which exits `1`. A configuration error usually means declarations were dropped (a missing `trust` entry, a renamed patch file), which leaves nothing to derive a verdict from, and a clean exit there would look exactly like a fully patched store. Set `extra.magento-patches.allow-unpatched` to `true` to continue anyway.

**A patch is applied as a unit.** If any one of its files will not take the patch, none of them are written. A security fix on three of its four files is not three quarters applied: the files Adobe changes together depend on each other, and a half-applied change can break the site. A file this store does not have, because the package is replaced, not installed, or does not ship it, is not a refusal and never blocks the rest.

## Using verify as a deploy gate

**Run `composer magento-patches:verify` as its own step in your pipeline, after the install, and let a non-zero exit fail the build.**

The install-time run is not unreliable. There are two ordinary ways for it not to happen at all, and neither of them says so:

- `composer install --no-plugins` runs the whole install and exits `0` having applied nothing. No plugin can report this, because no plugin ran.
- An `allow-plugins` entry set to `false` does the same, and answering "n" when Composer asks writes exactly that. Composer then skips the plugin without a word. (With no entry at all, a non-interactive run stops with an error, which at least fails the build.)

Both produce a green build over an unpatched store, so a patcher that can be switched off without a word needs a witness outside itself.

```yaml
- run: composer install --no-interaction
- run: composer magento-patches:verify --no-interaction
```

The same step catches a patched root file being reverted between deploys, and warns when one has gone missing: the patch then goes to `magento2-base`'s copy, which the next deploy serves from, and the warning says which reinstall puts the served file back. Anything that re-deploys `magento2-base` without going through Composer (an rsync, a `cp -r` in a Dockerfile, `bin/magento setup:upgrade`) is invisible to this plugin, because there is no Composer event to hook. `magento-patches:verify` reads the working tree and will tell you.

## Advisories

Two things get said out loud that are not per-patch verdicts. Neither ever fails a run: an unsupported store still needs its patches, arguably more than a supported one does.

**End of life.** Warned six months ahead, red once passed:

```
magento/product-community-edition 2.4.7-p10 reaches end of life on 2027-04-09, in 98
  days. Plan the upgrade before it stops receiving security releases.
```

Dates come from `resources/magento-eol.json`, shipped inside this package. **Nothing is fetched while patching.** A security tool that phones home on every `composer install` is a liability, and a store behind a firewall would lose the warning without noticing. `bin/refresh-eol` rebuilds the manifest from [magento.watch](https://magento.watch), and a scheduled workflow opens a pull request when the dates change.

**Behind on patch level.** Isolated patches only apply to the newest release of their line, so a store one release back silently receives none of them:

```
magento/magento2-base is on 2.4.8-p4, but patches here are built for 2.4.8-p5.
Isolated patches only apply to the newest release of their line — upgrade to receive them.
```

## The closing banner

Every command ends on a banner, because a wall of green rows with one amber one in the middle is exactly the shape people skim past:

```
     OK     59 of 59 targets applied
 !   WARN   59 of 59 targets applied — 1 advisory above needs attention
 !!! ISSUE  8 targets not applied — run composer magento-patches:apply
```

It is coloured, and it says the same thing in punctuation, because CI logs have no colour and that is where it matters most. States that are fine are lower case throughout the report; states that are not are upper case, for the same reason.

`WARN` is for a store that needs attention eventually (past or approaching end of life, behind on patch level) and never changes the exit code. Targets that are *not covered*, because the module has been replaced away or was never installed, stay out of it: on a store running `outeredge/magento-disable-graphql` that is permanent and expected, and a warning that never goes away gets ignored. They are counted in the report and footnoted instead.
