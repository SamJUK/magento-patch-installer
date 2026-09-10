# Releasing

Pushing a signed tag is the release. Packagist updates itself from its own
webhook within seconds, so nothing here publishes anything and there is no
registry token in CI.

## Cutting a release

```sh
bin/release            # recommend a version from the commits
bin/release 1.2.0      # or name it yourself
```

The script refuses rather than asks wherever it can: a dirty tree, a branch
that is not `master`, a `master` that has diverged from origin, a tag that
already exists, a changelog section that already exists, or a commit CI has
not passed. It warns when the only acceptance evidence is the smoke job rather
than the five-image matrix.

Then it recommends a version from the conventional commits, opens the draft
notes in `$EDITOR`, prints exactly what will be published, and asks once.
Nothing is committed, tagged or pushed before that answer.

After it, the changelog entry is written and committed, the tag is signed, and
both are pushed. The `Release` workflow builds the release page from the tag.

## Doing it by hand

Worth knowing for the run where something goes sideways.

1. **Draft the changelog entry.**

   ```sh
   bin/changelog-draft
   ```

   Prints a section grouped from the conventional commits since the last tag.
   It is a draft: move anything that changes whether a store ends up patched
   into `### Security`, and rewrite the rest for someone deciding whether to
   upgrade a production store rather than someone reading the diff.

   Paste it into `CHANGELOG.md` under a heading of this shape, because
   `bin/github-release-notes` matches on the version:

   ```
   ## 1.2.0 — 2026-09-10
   ```

2. **Check CI is green on `master`**, including the five-image acceptance
   matrix. Apply the `run-tests` label to the pull request, or run the
   `Acceptance` workflow manually — the smoke job alone is not enough for a
   release.

3. **Commit the changelog, then tag it.**

   ```sh
   git tag -s v1.2.0 -m 'v1.2.0'
   git push origin v1.2.0
   ```

4. **The `Release` workflow does the rest**: runs the unit suite, validates
   `composer.json`, builds the notes from the changelog section plus install
   instructions, and creates the GitHub release. It fails if the changelog has
   no section for the version, which is the one thing that must not be found
   out after a tag exists.

5. **Confirm Packagist has it** at
   [packagist.org/packages/samjuk/magento-patch-installer](https://packagist.org/packages/samjuk/magento-patch-installer).
   It should appear within seconds; if it has not, the GitHub webhook is
   missing.

## Rules

**Never move or delete a tag.** Composer records a commit SHA in the consumer's
`composer.lock`, and GitHub-sourced packages carry an empty `shasum`, so that
reference is the only thing pinning an install to a known set of bytes. Moving
a tag changes what a pinned lock file resolves to. A mistake in a release is
fixed by another release.

**Version by what a store has to do about it.** A patch or minor should be safe
to take without reading anything. A major means a project's `composer.json`
needs editing — a renamed command, a changed configuration key, a raised PHP or
Composer floor.

**Mind the caret on `0.x`.** `^1.0` accepts `1.1`, but `^0.1` does *not* accept
`0.2` — a caret constraint on a `0.x` pins the minor. Anything depending on this
package, `samjuk/m2-meta-security-patches` included, needs its constraint
widened by hand on every `0.x` minor.

## Signing

Tags are signed with SSH rather than GPG:

```gitconfig
[user]
    signingkey = ~/.ssh/id_ed25519.pub
[gpg]
    format = ssh
[tag]
    gpgsign = true
```

The public key has to be uploaded to GitHub a second time as a **Signing Key** —
an authentication key of the same value does not make signatures verify.

Nothing in Composer or Packagist checks signatures, so this is not a security
control. It is a Verified badge on a tool asking to be trusted with a store's
patches, and it costs one flag. `bin/release` refuses to tag rather than
quietly falling back to an unsigned one, because a dim line saying "no signing
key" scrolls past and the release ships unsigned.

Local verification needs `gpg.ssh.allowedSignersFile`; without it
`git verify-tag` reports an error about that file rather than about the
signature, which is confusing but harmless. GitHub verifies server-side and
needs none of it.

## Why the notes are not generated from commit subjects

They could be — the history is strictly conventional commits, and
`bin/changelog-draft` reads it. But a release page is read by one person asking
one question: *do I need to deploy this today?* A commit subject answers a
different question, and a generated list buries the one line that matters under
the nine that do not. The draft script does the collating; a person does the
part that needs judgement.
