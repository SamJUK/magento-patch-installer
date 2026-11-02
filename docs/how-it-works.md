# How it works

## The working tree is the source of truth

Each target is classified by asking `git apply` two questions:

| `-R --check` | `--check` | Verdict |
| --- | --- | --- |
| passes | (not asked) | Already applied, skip |
| fails | passes | Applicable, apply it |
| fails | fails, file absent | Not applicable, say why |
| fails | fails, file present | Conflict: reported, never overwritten |

**Chains are checked as chains.** A later link can rewrite an earlier one's lines, so "already applied" is asked on a scratch copy with the later links taken off, and a report asks "can apply" with the earlier links put back. Root and package copies of a mapped file count as one file. Patches not linked by `depends` or a cumulative line are checked alone.

The reverse check is asked first and wins outright. A hunk whose context is ordinary boilerplate can match at more than one offset, so on an already-patched file the forward check passes too, and applying again duplicates the change. For the same reason, a forward apply that lands at an offset is refused when the lines it adds are already in the file between where the hunk was made for and where git would put them.

**One case git's checks cannot settle.** A patch whose changed lines, with their context, are identical to another place in the same file (two loops alike, one already escaped). The reverse check finds them there and calls the patch applied while the place it was made for is not, and skipping it takes the other place's lines off. Text alone cannot tell the two apart, so this is a known limitation rather than a guess either way. The offset guard meets the same thing from the other side: on a file that has grown since the patch was made, an identical block already fixed between the two places refuses a correct patch (loudly, never silently), and a second copy whose lines just before the change have since been rewritten is not recognised.

An exit code is not taken as evidence that anything was written: after applying, the target's hash has to have moved. Git's own line-ending configuration is pinned per invocation (`core.autocrlf=false`, `core.eol=lf`). Read from the environment, `autocrlf=true`, the Git for Windows default, applies a patch, exits `0` and rewrites every line of the file, while both checks keep reporting it applied afterwards.

## Patches are split per file, and applied as a unit

Each file is classified on its own, so a target whose module has been replaced away is skipped and named. A file that *refuses* the patch is different: nothing is written at all. Every write is journalled first, and a failure part way through puts back everything the patch wrote, contents and modes included.

## It runs last

`POST_INSTALL_CMD` and `POST_UPDATE_CMD` at priority `-1000`, which is after `magento-composer-installer` deploys root files at priority 1 and after your own scripts.

It also runs on `composer dump-autoload`, which fires neither of those events. Writing during what looks like a metadata command is deliberate: `vaimo/composer-patches` hooks `pre-autoload-dump`, so a bare `composer dump-autoload` gives it a full reset-and-repatch cycle in a command this plugin would otherwise never be called for. Measured on a real store, that took a green tree to `magento-patches:verify` exiting `1` with no opportunity to heal. With no other patcher installed the run is a no-op.

## Root-mapped files are patched in both places

For the files `magento2-base` deploys to the project root, the served copy and the package copy are both patched: the first because it is what runs, the second because any future deploy copies it back over the first. The mapping is read at runtime the way `magento-composer-installer` deploys it, so patch files are never edited to name two locations. Only `magento2-*` package types count, and the root's `magento-map-overwrite`, `path-mapping-translations` and `magento-deploy-ignore` apply. Where two packages deploy the same root file, which copy is served depends on their deploy order, so a patch changing it is refused as a configuration error until one of them is removed or ignored.

## The state file, Dependabot and Renovate

`var/composer-patches/state.json` records what was written and the sha256 of each file.

It is an audit log, **not** the source of truth. Every verdict is reached by asking git about the working tree, so deleting it changes nothing about what gets applied, which is what makes a fresh clone and a wiped `vendor/` both behave correctly. Its one functional job is recognising a file as ours, so that a monthly patch replacing its own predecessor's file is a replacement rather than a conflict.

That design is what makes it safe under automated dependency updates:

- It lives under `var/`, which Magento gitignores, so it is **never** part of a Dependabot or Renovate pull request and can never conflict in one.
- Those tools resolve dependencies without running Composer plugins, so a bot's branch has no patch state, and needs none. The patches are applied by whatever runs `composer install` next.
- It is per installation rather than per project: dev, staging and production each keep their own, describing that machine's working tree rather than the project's source.

So a bot bumping the package that ships your patches needs no special handling. The new patches apply on the next install, and `composer magento-patches:verify` in the pipeline is what tells you whether that happened.

## Other patch plugins

Verified against both existing patchers on real 2.4.6-p15 and 2.4.8-p5 installs, including a live store with 18 packages already patched by cweagans.

- **vaimo/composer-patches.** A different config key and command namespace, so neither reads the other's declarations and neither drops the other's commands. Vaimo hooks `pre-autoload-dump`, which `composer dump-autoload` fires and `post-install-cmd` does not, so it can reset a package and re-patch it in a command this plugin would otherwise never be called for. That is why this plugin also runs on `dump-autoload`.
- **cweagans/composer-patches** (1.x and 2.x). cweagans resets a package before patching it. When it began patching `magento/module-quote`, that reset wiped 14 of this plugin's targets; running at priority `-1000` meant they were detected and re-applied in the same Composer run, with cweagans' own patches left intact.

Patches applied by another tool are recognised as already applied rather than reported as conflicts, so migrating does not mean re-applying everything.

vaimo declares a `conflict` with cweagans, so a project can only have one of them. This plugin conflicts with neither.
