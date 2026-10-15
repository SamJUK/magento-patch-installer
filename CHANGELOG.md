# Changelog

Notable changes, newest first. Lines that change whether a store ends up
patched are marked **Security**, because those are the ones worth reading when
deciding whether to upgrade.

Tags are never moved or deleted. Composer records a commit SHA in
`composer.lock` and GitHub-sourced packages carry no `shasum`, so that
reference is the only thing pinning an install to a known set of bytes.

Written by hand. `bin/changelog-draft` collates the conventional commits since
the last tag into a starting point; `bin/github-release-notes` turns a section
here into the GitHub release body. See `docs/releasing.md`.

## Unreleased

Nothing yet.

## 0.3.0

### Fixed

- **Security.** A store's own patch on top of a package's chain applied first,
  beneath the months it rewrites. `depends` now resolves across packages and the
  root within a base line, and all sources are ordered together.
- **Security.** A private package's patches applied in install order. They now
  apply after those of the packages it requires.
- **Security.** A `depends` naming nothing was ignored. It is now refused, with
  everything built on it, as is every patch in a dependency cycle.
- **Security.** A patch failing partway left what it had written. Every write is
  journalled and a failure puts it all back. Taking a skipped patch off too.
- **Security.** A patch that sets a file mode read as applied once the content
  was there. The mode is now set.
- **Security.** A root file missing from the project root blocked its patch and
  everything built on it. The package copy is patched instead, with a warning.
- **Security.** A patch could be written a second time further down a file
  while `verify` passed. An apply at an offset is refused when its lines are
  already there.
- **Security.** `composer -d <dir> dump-autoload` skipped healing. It now heals
  however the command is run.
- **Security.** Any package's `extra.map` counted as a root file's deploy
  mapping, so a library could leave the served file unpatched. Only what
  `magento-composer-installer` deploys counts now.
- **Security.** A root file's other copy could get different code from the
  served one. Only a patch's file headers are retargeted now.
- Other copies are found through symlinked packages, deletions reach both
  copies, and nothing is created in a module the store does not install.
- `status --json` reports the exit code the command exits with.

### Added

- **Security.** A store's own patch overlapping a package's, with no order
  between them, is refused, naming the `depends` to add. Between two packages it
  is a warning.
- `depends` can name another package's patch as `vendor/package:id`.
- `magento-patches:list` shows prerequisites in other packages, and patches
  built on a refused one.

### Changed

- Licensed under OSL-3.0. Releases up to 0.2.1 stay under MIT.
- A `depends` id is only looked up within its own base line.

## 0.2.1 — 2026-10-09

### Fixed

- A month that edits lines an earlier month added made the earlier one read as a
  conflict, failing every Composer run after the first. Adobe's own files do this
  (`vendor/bin/patch-status`). Links of a chain are now checked beneath the later
  links and, when reporting, above the earlier ones.

## 0.2.0

### Changed

- **Breaking.** Commands renamed from `patches:*` to `magento-patches:*`.

  Composer treats `patch` as an abbreviation of `patches`, so on a store also
  running vaimo you could not tell which tool a command would reach.

  Update any scripts — they fail loudly, not silently. No aliases.

  Patching on install and update is unaffected; that runs on Composer events,
  not the command.

## 0.1.0 — 2026-09-10

### Other

- initial commit
