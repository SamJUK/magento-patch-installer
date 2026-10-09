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
