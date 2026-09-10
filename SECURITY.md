# Security policy

## Reporting a vulnerability in this plugin

Report privately through GitHub:
[**Report a vulnerability**](https://github.com/SamJUK/magento-patch-installer/security/advisories/new).

Please do not open a public issue for anything that would let someone attack a
store running this plugin.

This is a single-maintainer project. You can expect an acknowledgement within
**7 days**. I do not run a bug bounty and cannot commit to a fixed disclosure
date, but I will tell you what is happening and credit you in the advisory
unless you would rather I did not.

## What is in scope

This plugin decides whether a patch is applied and writes it to disk. In scope
is anything that makes it:

- report a store as patched when the patch is not on disk, or exit `0` over an
  unapplied security patch;
- apply, revert or skip a patch that the root `composer.json` did not ask for —
  in particular anything letting a package that is not in
  `extra.magento-patches.trust` influence the outcome;
- write outside the project directory, or execute anything from a patch file;
- fail in a way that leaves a target half-written.

## What is not

**This plugin does not vet what a patch contains.** It applies the patches it
is given by packages the project has explicitly chosen to trust. A
vulnerability in the code a patch introduces belongs to whoever wrote the
patch — for Adobe's security patches, that is Adobe.

Vulnerabilities in Magento itself go to
[Adobe's disclosure programme](https://helpx.adobe.com/security/alertus.html),
not here.

A trusted package behaving badly is not in scope on its own: trust is granted
deliberately, in the root `composer.json`, and a trusted package is expected to
be able to patch the project. A trusted package reaching *another* package's
patches, or reaching outside the project, is in scope.

## Supported versions

The latest release. This is a security tool with a single maintainer; fixes go
forward, and there are no backport branches.
