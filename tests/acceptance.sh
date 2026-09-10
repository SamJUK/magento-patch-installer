#!/usr/bin/env bash
# Acceptance tests for samjuk/magento-patch-installer.
#
# Runs the plugin against real Magento installations in the project's CI images
# and asserts on observable behaviour: what ends up on disk, and what the exit
# codes say about it.
#
#   tests/acceptance.sh                 # every image in the default matrix
#   tests/acceptance.sh 2.4.8-p5-php8.4 # one image
#   KEEP=1 tests/acceptance.sh ...      # leave the container running afterwards

set -uo pipefail

CWD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$CWD")"
IMAGE_REPO="samjuk/magento-ci-testing-env"

DEFAULT_MATRIX=(
    "2.4.9-php8.5"
    "2.4.8-p5-php8.4"
    "2.4.7-p10-php8.3"
    "2.4.6-p15-php8.2"
    "2.4.3-php7.4"
)

RED=$'\033[1;31m'; GREEN=$'\033[1;32m'; YELLOW=$'\033[1;33m'; DIM=$'\033[2m'; RESET=$'\033[0m'

PASS=0
FAIL=0
CONTAINER=""

cleanup() {
    if [ -n "$CONTAINER" ] && [ "${KEEP:-0}" != "1" ]; then
        docker rm -f "$CONTAINER" >/dev/null 2>&1
    fi
}
trap cleanup EXIT

run() {
    docker exec "$CONTAINER" sh -c "$1" 2>&1
}

# check <name> <expected> <actual>
check() {
    if [ "$2" = "$3" ]; then
        printf '    %s✓%s %s\n' "$GREEN" "$RESET" "$1"
        PASS=$((PASS + 1))
    else
        printf '    %s✗%s %s  %sexpected [%s] got [%s]%s\n' "$RED" "$RESET" "$1" "$DIM" "$2" "$3" "$RESET"
        FAIL=$((FAIL + 1))
    fi
}

# checkne <name> <not-expected> <actual>
checkne() {
    if [ "$2" != "$3" ]; then
        printf '    %s✓%s %s %s(%s)%s\n' "$GREEN" "$RESET" "$1" "$DIM" "$3" "$RESET"
        PASS=$((PASS + 1))
    else
        printf '    %s✗%s %s  %sshould not be [%s]%s\n' "$RED" "$RESET" "$1" "$DIM" "$2" "$RESET"
        FAIL=$((FAIL + 1))
    fi
}

scenario() {
    printf '  %s%s%s\n' "$YELLOW" "$1" "$RESET"
}

# Number of targets the last run reported in a given column.
counted() {
    run "cd /var/www/html && composer $1 --no-interaction 2>&1 | grep -oE '[0-9]+ $2' | head -1 | grep -oE '^[0-9]+'"
}

verify_code() {
    run "cd /var/www/html && composer patches:verify --no-interaction >/dev/null 2>&1; echo \$?"
}

setup() {
    local image="$1"

    CONTAINER="mpi-test-$(echo "$image" | tr '.' '-')"
    docker rm -f "$CONTAINER" >/dev/null 2>&1

    # A container left Dead by an out-of-memory kill holds its name, and its
    # removal is asynchronous — docker answers "already in progress" and clears
    # it a few seconds later. Failing immediately reads as "the image will not
    # start", which sends you looking in entirely the wrong place.
    local waited=0
    while docker ps -a --format '{{.Names}}' | grep -qx "$CONTAINER"; do
        if [ "$waited" -ge 30 ]; then
            printf '    %s✗%s %s will not go away — %sdocker rm -f %s%s\n' \
                "$RED" "$RESET" "$CONTAINER" "$DIM" "$CONTAINER" "$RESET"
            return 1
        fi

        docker rm -f "$CONTAINER" >/dev/null 2>&1
        waited=$((waited + 2))
        sleep 2
    done

    local started
    if ! started=$(docker run -d --platform linux/amd64 --name "$CONTAINER" \
        -v "$PLUGIN_DIR:/plugin:ro" \
        "$IMAGE_REPO:$image" 2>&1); then
        printf '    %s✗%s docker run failed  %s%s%s\n' "$RED" "$RESET" "$DIM" "$started" "$RESET"
        return 1
    fi

    sleep 3

    # Composer cannot guess a version for a path repo with no commits, so the
    # test copy carries one explicitly. Nothing in the real package needs it.
    run "cp -r /plugin /plugin-test && chmod -R u+w /plugin-test
         php -r '\$f=\"/plugin-test/composer.json\"; \$j=json_decode(file_get_contents(\$f),true); \$j[\"version\"]=\"0.1.0\"; file_put_contents(\$f, json_encode(\$j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));'
         mkdir -p /var/www/html/tests-fixtures
         php /plugin-test/tests/fixtures/build.php /var/www/html /fixture-pkg
         cd /var/www/html
         composer config repositories.plugin path /plugin-test
         composer config repositories.fixtures path /fixture-pkg
         composer config --no-plugins allow-plugins.samjuk/magento-patch-installer true
         composer config extra.magento-patches.trust --json '[\"samjuk/*\",\"fixtures/*\"]'" >/dev/null
}

# ---------------------------------------------------------------- scenarios --

s_first_install() {
    scenario "S1  first-ever require applies patches"

    local out applied
    out=$(run "cd /var/www/html && composer require fixtures/patches:^1.0 --no-interaction -W 2>&1")
    applied=$(echo "$out" | grep -oE '[0-9]+ applied' | head -1 | grep -oE '^[0-9]+')

    checkne "targets applied" "0" "${applied:-0}"

    # A first install that patches nothing is the one failure worth seeing in
    # full — everything after it inherits the wrong starting state.
    if [ "${applied:-0}" = "0" ]; then
        echo "$out" | tail -20 | sed 's/^/        /'
    fi

    check "verify exits clean" "0" "$(verify_code)"
}

s_idempotent() {
    scenario "S2  running again changes nothing"

    local applied
    applied=$(counted "install" "applied")

    check "nothing re-applied" "0" "${applied:-0}"
    check "verify exits clean" "0" "$(verify_code)"
}

s_reinstall_base() {
    scenario "S3  reinstall magento2-base self-heals"

    run "cd /var/www/html && composer reinstall magento/magento2-base --no-interaction" >/dev/null
    check "verify exits clean" "0" "$(verify_code)"

    # Where the 2026-08 patch applies, the root copy must come back patched.
    local root vendor
    root=$(run "grep -o '1\.13\.[0-9]' /var/www/html/lib/web/underscore.js 2>/dev/null | head -1")
    vendor=$(run "grep -o '1\.13\.[0-9]' /var/www/html/vendor/magento/magento2-base/lib/web/underscore.js 2>/dev/null | head -1")

    if [ -n "$root" ]; then
        check "root and package copies agree" "$vendor" "$root"
    fi
}

s_chain() {
    scenario "S4  a patch is refused when what it is built on is missing"

    local chained
    chained=$(run "cd /var/www/html && composer patches:list --no-interaction 2>/dev/null | grep -cE 'FX-000[0-9]'")

    if [ "${chained:-0}" -lt 3 ]; then
        printf '    %s-%s S4 skipped (no chain fixture)\n' "$DIM" "$RESET"
        return
    fi

    checkne "the catalogue says what each is built on" "0" \
        "$(run "cd /var/www/html && composer patches:list --no-interaction 2>&1 | grep -c 'after FX-0001'")"

    # Break the prerequisite's context so it cannot apply. Everything built on
    # it is then refused by name, rather than attempted and failing three
    # patches later for a reason nobody can trace back. FX-0001 patches
    # Escaper.php — see tests/fixtures/build.php.
    run "cp /var/www/html/vendor/magento/framework/Escaper.php /tmp/chain.orig" >/dev/null
    run "sed -i '\$ i\\// context broken' /var/www/html/vendor/magento/framework/Escaper.php" >/dev/null

    local out
    out=$(run "cd /var/www/html && composer patches:apply --no-interaction 2>&1")
    # Both links, not just the next one: blocking has to be transitive, or the
    # third patch applies to a tree that never received the first.
    check "both later patches are blocked, not just the next one" "2" "$(echo "$out" | grep -c BLOCKED)"
    checkne "and the block names what it needed" "0" "$(echo "$out" | grep -c 'requires FX-0001')"
    checkne "FX-0003 is named too" "0" "$(echo "$out" | grep -c 'FX-0003')"

    # Read-only mode classifies every patch on its own merits; only an actual
    # apply refuses to run a link whose prerequisite failed.
    check "listing does not pre-emptively block" "0" \
        "$(run "cd /var/www/html && composer patches:list --no-interaction 2>&1 | grep -c BLOCKED")"

    run "cp /tmp/chain.orig /var/www/html/vendor/magento/framework/Escaper.php" >/dev/null
    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    check "and recover once it is fixed" "0" "$(verify_code)"
}

s_state_and_json() {
    scenario "S5  state file and --json output"

    check "state file written" "yes" \
        "$(run "test -f /var/www/html/var/composer-patches/state.json && echo yes")"
    checkne "records target hashes" "0" \
        "$(run "grep -c sha256 /var/www/html/var/composer-patches/state.json")"

    local parsed
    parsed=$(run "cd /var/www/html && composer patches:verify --json --no-interaction 2>/dev/null | php -r '\$d = json_decode(stream_get_contents(STDIN), true); echo isset(\$d[\"patches\"], \$d[\"exit_code\"]) ? \"ok\" : \"bad\";'")
    # Nothing but JSON on stdout. Stripping everything before the first `{` here
    # is what hid a config error being printed into the middle of the payload.
    check "json has the expected shape" "ok" "$parsed"
}

# Declares a patch that creates a file, so supersede has something to replace.
fixture_new_file() {
    local rel="$1" name="$2" content="$3"

    run "cd /var/www/html
         printf '%s\n' '--- /dev/null' '+++ b/$rel' '@@ -0,0 +1 @@' '+$content' > tests-fixtures/$name.patch
         php -r '
             \$f = \"composer.json\";
             \$j = json_decode(file_get_contents(\$f), true);
             \$j[\"extra\"][\"magento-patches\"][\"patches\"][] = [
                 \"id\" => \"$name\",
                 \"source\" => \"tests-fixtures/$name.patch\",
             ];
             file_put_contents(\$f, json_encode(\$j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
         '" >/dev/null
}

s_supersede() {
    scenario "S6  a regenerated file is superseded, a hand-written one is not"

    local target="tests-fixtures/created.txt"

    fixture_new_file "$target" "supersedea" "alpha"
    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    check "first patch creates the file" "alpha" "$(run "cat /var/www/html/$target 2>&1")"

    fixture_new_file "$target" "supersedeb" "beta"
    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    check "later patch supersedes it" "beta" "$(run "cat /var/www/html/$target 2>&1")"
    check "verify exits clean" "0" "$(verify_code)"

    local applied
    applied=$(counted "patches:apply" "applied")
    check "and does not flip back on the next run" "0" "${applied:-0}"
    check "content still the later one" "beta" "$(run "cat /var/www/html/$target 2>&1")"

    run "echo hand-written > /var/www/html/$target" >/dev/null
    check "an unrecognised file conflicts" "2" "$(verify_code)"
    check "and is not overwritten" "hand-written" "$(run "cat /var/www/html/$target 2>&1")"

    run "rm -f /var/www/html/$target && cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    check "removing it lets the patch land again" "0" "$(verify_code)"
}

s_unapplied_detected() {
    scenario "S7  a hand-reverted target is caught and healed"

    local file
    file=/var/www/html/vendor/magento/framework/Escaper.php

    if [ "$(run "grep -c 'chain-one' $file")" = "0" ]; then
        printf '    %s-%s S7 skipped (fixture marker not found)\n' "$DIM" "$RESET"
        return
    fi

    # The fixture adds exactly one line at the end; removing it is a clean
    # revert, which must read as unapplied rather than as a conflict.
    run "sed -i '\$ d' $file" >/dev/null
    check "verify reports unapplied" "1" "$(verify_code)"

    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    check "apply heals it" "0" "$(verify_code)"
}

s_conflict() {
    scenario "S8  a broken context line conflicts, and nothing is overwritten"

    local file="vendor/magento/module-catalog/etc/module.xml"

    if [ "$(run "test -f /var/www/html/$file && echo yes")" != "yes" ]; then
        printf '    %s-%s S8 skipped (fixture target missing)\n' "$DIM" "$RESET"
        return
    fi

    # Insert a line just before the fixture's added line: the hunk's trailing
    # context no longer matches, and neither direction applies.
    run "cp /var/www/html/$file /tmp/s5.orig && sed -i '\$ i\\<!-- context broken -->' /var/www/html/$file" >/dev/null
    check "verify reports a conflict" "2" "$(verify_code)"

    local before after
    before=$(run "md5sum /var/www/html/$file | cut -d' ' -f1")
    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    after=$(run "md5sum /var/www/html/$file | cut -d' ' -f1")
    check "file left untouched" "$before" "$after"

    run "cp /tmp/s5.orig /var/www/html/$file" >/dev/null
    check "verify clean once restored" "0" "$(verify_code)"
}

# Picks the Nth file that exists both at the project root and inside
# magento2-base, so link-strategy scenarios have a real target on both sides.
mapped_file() {
    run "cd /var/www/html
         taken=\$(cat /fixture-pkg/root-target.txt 2>/dev/null)
         n=0
         for f in lib/web/*.js lib/web/mage/*.js pub/errors/*.php; do
             [ -f \"\$f\" ] || continue
             [ -f \"vendor/magento/magento2-base/\$f\" ] || continue
             [ \"\$f\" = \"\$taken\" ] && continue
             n=\$((n + 1))
             if [ \"\$n\" -eq $1 ]; then echo \"\$f\"; break; fi
         done"
}

# Builds a patch against a file that magento2-base deploys to the project root,
# declares it in the root package, and returns the relative target path.
fixture_for() {
    local rel="$1" name="$2"

    run "cd /var/www/html
         cp '$rel' /tmp/$name.a
         cp '$rel' /tmp/$name.b
         printf '\n/* patch-probe-$name */\n' >> /tmp/$name.b
         diff -u /tmp/$name.a /tmp/$name.b | sed '1s|.*|--- a/$rel|; 2s|.*|+++ b/$rel|' > tests-fixtures/$name.patch
         php -r '
             \$f = \"composer.json\";
             \$j = json_decode(file_get_contents(\$f), true);
             \$j[\"extra\"][\"magento-patches\"][\"patches\"][] = [
                 \"id\" => \"$name\",
                 \"source\" => \"tests-fixtures/$name.patch\",
             ];
             file_put_contents(\$f, json_encode(\$j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
         '" >/dev/null
}

s_mapped_directory() {
    scenario "S9  a target under a mapped directory patches both copies"

    local rel
    rel=$(run "cd /var/www/html
               taken=\$(cat /fixture-pkg/root-target.txt 2>/dev/null)
               for f in lib/web/mage/*.js; do
                   [ \"\$f\" = \"\$taken\" ] && continue
                   [ -f \"\$f\" ] && [ -f \"vendor/magento/magento2-base/\$f\" ] && echo \"\$f\" && break
               done")

    if [ -z "$rel" ]; then
        printf '    %s-%s S9 skipped (no mapped lib/web/mage file)\n' "$DIM" "$RESET"
        return
    fi

    fixture_for "$rel" "mappeddir"
    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null

    check "root copy patched" "1" "$(run "grep -c 'patch-probe-mappeddir' /var/www/html/$rel")"
    check "package copy patched" "1" "$(run "grep -c 'patch-probe-mappeddir' /var/www/html/vendor/magento/magento2-base/$rel")"
    check "verify exits clean" "0" "$(verify_code)"
}

s_symlink_strategy() {
    scenario "S10 symlink deploy strategy"

    local rel
    rel=$(mapped_file 1)
    if [ -z "$rel" ]; then
        printf '    %s-%s S10 skipped (no root-mapped file found)\n' "$DIM" "$RESET"
        return
    fi

    fixture_for "$rel" "symlinked"

    # Recreate what the symlink deploy strategy leaves behind.
    # Absolute target: a relative symlink would resolve against the link's own
    # directory, not the project root.
    run "cd /var/www/html && rm -f '$rel' && ln -s '/var/www/html/vendor/magento/magento2-base/$rel' '$rel'" >/dev/null
    check "root path is a symlink" "yes" "$(run "test -L /var/www/html/$rel && echo yes")"

    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null

    check "content reaches the root path" "1" "$(run "grep -c 'patch-probe-symlinked' /var/www/html/$rel")"
    check "symlink not replaced by a file" "yes" "$(run "test -L /var/www/html/$rel && echo yes")"
    check "verify exits clean" "0" "$(verify_code)"

    run "cd /var/www/html && rm -f '$rel' && cp 'vendor/magento/magento2-base/$rel' '$rel'" >/dev/null
}

s_hardlink_strategy() {
    scenario "S11 link (hardlink) deploy strategy"

    local rel
    rel=$(mapped_file 2)
    if [ -z "$rel" ]; then
        printf '    %s-%s S11 skipped (no second root-mapped file)\n' "$DIM" "$RESET"
        return
    fi

    fixture_for "$rel" "hardlinked"

    run "cd /var/www/html && rm -f '$rel' && ln 'vendor/magento/magento2-base/$rel' '$rel'" >/dev/null
    check "starts as one inode" "$(run "stat -c %i /var/www/html/vendor/magento/magento2-base/$rel")" \
                                "$(run "stat -c %i /var/www/html/$rel")"

    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null

    check "root copy patched" "1" "$(run "grep -c 'patch-probe-hardlinked' /var/www/html/$rel")"
    check "package copy patched" "1" "$(run "grep -c 'patch-probe-hardlinked' /var/www/html/vendor/magento/magento2-base/$rel")"
    check "verify exits clean" "0" "$(verify_code)"
}

s_file_without_package() {
    scenario "S12 a file present without its composer package is still patched"

    run "mkdir -p /var/www/html/vendor/magento/module-not-a-package
         printf '<?php\n// line one\n// line two\n' > /var/www/html/vendor/magento/module-not-a-package/File.php" >/dev/null

    fixture_for "vendor/magento/module-not-a-package/File.php" "orphan"
    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null

    check "patched despite no package" "1" \
        "$(run "grep -c 'patch-probe-orphan' /var/www/html/vendor/magento/module-not-a-package/File.php")"
}

s_replaced_package() {
    scenario "S14 a replaced-away module is named, not silently skipped"

    run "cd /var/www/html && composer require outeredge/magento-disable-graphql --no-interaction -W" >/dev/null

    local listed
    listed=$(run "cd /var/www/html && composer patches:status -v --no-interaction 2>&1 | grep -c 'replaced by outeredge/magento-disable-graphql'")

    if [ "$listed" = "0" ]; then
        printf '    %s-%s S14 no graphql targets on this version\n' "$DIM" "$RESET"
    else
        checkne "replacer named in the report" "0" "$listed"
    fi

    check "verify still exits clean" "0" "$(verify_code)"
}

s_stale_advisory() {
    scenario "S16 a store behind on patch level is told so"

    local installed next
    installed=$(run "cd /var/www/html && composer show magento/magento2-base --no-interaction 2>/dev/null | grep -E '^versions' | grep -oE '[0-9]+\.[0-9]+\.[0-9]+(-p[0-9]+)?' | head -1")

    if [ -z "$installed" ]; then
        printf '    %s-%s S16 skipped (no magento2-base version)\n' "$DIM" "$RESET"
        return
    fi

    case "$installed" in
        *-p*) next="${installed%-p*}-p$(( ${installed##*-p} + 1 ))" ;;
        *)    next="$installed-p1" ;;
    esac

    run "cd /var/www/html
         cp composer.json /tmp/s16.json
         printf '%s\n' '--- /dev/null' '+++ b/tests-fixtures/stale.txt' '@@ -0,0 +1 @@' '+stale' > tests-fixtures/stale.patch
         php -r '
             \$f = \"composer.json\";
             \$j = json_decode(file_get_contents(\$f), true);
             \$j[\"extra\"][\"magento-patches\"][\"lines\"][\"$next\"] = [
                 \"base\" => [\"magento/magento2-base\" => \"$next\"],
                 \"patches\" => [[\"id\" => \"future-001\", \"source\" => \"tests-fixtures/stale.patch\"]],
             ];
             file_put_contents(\$f, json_encode(\$j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
         '" >/dev/null

    # Advisories are wrapped, so match against the flattened text — otherwise
    # the assertion passes or fails depending on where a version string of a
    # given length happens to push the line break.
    local out
    out=$(run "cd /var/www/html && composer patches:status --no-interaction 2>&1" | tr '\n' ' ' | tr -s ' ')

    checkne "advisory names the newer release" "0" "$(echo "$out" | grep -c "built for $next")"
    checkne "and says why it matters" "0" "$(echo "$out" | grep -c 'upgrade to receive them')"

    run "cp /tmp/s16.json /var/www/html/composer.json" >/dev/null
    check "verify still clean once withdrawn" "0" "$(verify_code)"
}

s_reported_problems() {
    scenario "S18 configuration problems are reported, not swallowed"

    run "cd /var/www/html && cp composer.json /tmp/s18.json" >/dev/null

    # A declaration whose patch file does not exist must not vanish silently:
    # a store reporting "no patches declared" looks exactly like a patched one.
    run "cd /var/www/html && php -r '
        \$j = json_decode(file_get_contents(\"composer.json\"), true);
        \$j[\"extra\"][\"magento-patches\"][\"patches\"][] = [
            \"id\" => \"MISSING-FILE\",
            \"source\" => \"tests-fixtures/not-here.patch\",
        ];
        file_put_contents(\"composer.json\", json_encode(\$j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    '" >/dev/null

    local out
    out=$(run "cd /var/www/html && composer patches:verify --no-interaction 2>&1")
    checkne "the broken declaration is named" "0" "$(echo "$out" | grep -c 'points at a missing file')"
    check "and verify exits with a config error" "3" \
        "$(run "cd /var/www/html && composer patches:verify --no-interaction >/dev/null 2>&1; echo \$?")"

    run "cd /var/www/html && cp /tmp/s18.json composer.json" >/dev/null

    # Forgetting the trust entry must not look like a clean store either.
    run "cd /var/www/html && composer config --json extra.magento-patches.trust '[]'" >/dev/null
    out=$(run "cd /var/www/html && composer patches:verify --no-interaction 2>&1")
    checkne "an untrusted patch package is named" "0" "$(echo "$out" | grep -c 'is not trusted')"

    run "cd /var/www/html && cp /tmp/s18.json composer.json" >/dev/null

    # A file that exists but is not a diff — truncated by a bad rsync, copied
    # with the wrong mode, or a source pointing at a README. "Nobody can say
    # whether this applies" is not the same as "not for this version", and only
    # one of those exits 0.
    run "cd /var/www/html && : > tests-fixtures/empty.patch && php -r '
        \$j = json_decode(file_get_contents(\"composer.json\"), true);
        \$j[\"extra\"][\"magento-patches\"][\"patches\"][] = [
            \"id\" => \"EMPTY-FILE\",
            \"source\" => \"tests-fixtures/empty.patch\",
        ];
        file_put_contents(\"composer.json\", json_encode(\$j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    '" >/dev/null

    out=$(run "cd /var/www/html && composer patches:verify --no-interaction 2>&1")
    checkne "an unreadable patch file is named" "0" "$(echo "$out" | grep -c 'could not be read as a diff')"
    check "and does not pass as a clean store" "3" \
        "$(run "cd /var/www/html && composer patches:verify --no-interaction >/dev/null 2>&1; echo \$?")"

    run "cd /var/www/html && cp /tmp/s18.json composer.json" >/dev/null
    check "clean again once restored" "0" "$(verify_code)"
}

s_dry_run() {
    scenario "S19 dry-run writes nothing, even from patches:apply"

    local target="vendor/magento/framework/Escaper.php"
    run "cd /var/www/html && cp composer.json /tmp/s19.json && composer config --json extra.magento-patches.dry-run true" >/dev/null
    run "sed -i '\$ d' /var/www/html/$target" >/dev/null

    local before after out
    before=$(run "md5sum /var/www/html/$target | cut -d' ' -f1")
    out=$(run "cd /var/www/html && composer patches:apply --no-interaction 2>&1")
    after=$(run "md5sum /var/www/html/$target | cut -d' ' -f1")

    check "patches:apply left the file alone" "$before" "$after"
    checkne "and said so" "0" "$(echo "$out" | grep -c 'dry run')"

    run "cd /var/www/html && cp /tmp/s19.json composer.json" >/dev/null
    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    check "and applies once dry-run is off" "0" "$(verify_code)"
}

s_read_only() {
    scenario "S20 list and verify never write, and mirror drift is reported"

    local rel
    rel=$(mapped_file 3)
    if [ -z "$rel" ]; then
        printf '    %s-%s S20 skipped (no root-mapped file found)\n' "$DIM" "$RESET"
        return
    fi

    local pkg="vendor/magento/magento2-base/$rel"

    fixture_for "$rel" "readonly"
    run "cp /var/www/html/$pkg /tmp/s20.pristine" >/dev/null
    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null

    # Both copies are patched. Put the package copy back exactly as it shipped,
    # so the served file is right and its seed is not. That is drift, and the
    # read-only commands have to say so rather than quietly repair it — healing
    # it inside verify returns 0 for a store that is out of step.
    run "cp /tmp/s20.pristine /var/www/html/$pkg" >/dev/null

    local before after
    before=$(run "md5sum /var/www/html/$pkg | cut -d' ' -f1")

    run "cd /var/www/html && composer patches:status -v --no-interaction" >/dev/null
    after=$(run "md5sum /var/www/html/$pkg | cut -d' ' -f1")
    check "patches:status wrote nothing" "$before" "$after"

    local code
    code=$(run "cd /var/www/html && composer patches:verify --no-interaction >/dev/null 2>&1; echo \$?")
    after=$(run "md5sum /var/www/html/$pkg | cut -d' ' -f1")
    check "patches:verify wrote nothing" "$before" "$after"
    check "and reported the drift instead of healing it" "1" "$code"

    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    check "apply puts the package copy back" "1" "$(run "grep -c 'patch-probe-readonly' /var/www/html/$pkg")"
    check "and verify is clean again" "0" "$(verify_code)"

    # The dry-run guard has to hold on a root-mapped target too: S19 uses a
    # plain vendor file, which never reaches the mirroring code at all.
    run "cd /var/www/html && cp composer.json /tmp/s20.json && composer config --json extra.magento-patches.dry-run true" >/dev/null
    run "cp /tmp/s20.pristine /var/www/html/$pkg" >/dev/null
    before=$(run "md5sum /var/www/html/$pkg | cut -d' ' -f1")
    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    after=$(run "md5sum /var/www/html/$pkg | cut -d' ' -f1")
    check "dry-run leaves the mirrored copy alone" "$before" "$after"

    run "cd /var/www/html && cp /tmp/s20.json composer.json && composer patches:apply --no-interaction" >/dev/null
    check "and the store is left clean for what follows" "0" "$(verify_code)"
}

s_config_error_fails_install() {
    scenario "S21 a configuration error fails composer install"

    run "cd /var/www/html && cp composer.json /tmp/s21.json" >/dev/null

    # Drop the trust entry. Every declaration goes with it, so there are no
    # results to derive an exit code from — and "no results" must not read as
    # "nothing wrong".
    run "cd /var/www/html && composer config --json extra.magento-patches.trust '[]'" >/dev/null

    local out code
    out=$(run "cd /var/www/html && composer install --no-interaction 2>&1; echo \"exit=\$?\"")
    code=$(echo "$out" | grep -oE 'exit=[0-9]+$' | cut -d= -f2)

    checkne "composer install fails" "0" "$code"
    checkne "and names the untrusted package" "0" "$(echo "$out" | grep -c 'is not trusted')"
    check "without claiming there is nothing to do" "0" "$(echo "$out" | grep -c 'No patches declared')"

    run "cd /var/www/html && cp /tmp/s21.json composer.json" >/dev/null
    out=$(run "cd /var/www/html && composer install --no-interaction 2>&1; echo \"exit=\$?\"")
    code=$(echo "$out" | grep -oE 'exit=[0-9]+$' | cut -d= -f2)
    check "and succeeds again once trust is restored" "0" "$code"
}

s_json_is_json() {
    scenario "S22 --json stays parseable, and carries the config exit code"

    run "cd /var/www/html && cp composer.json /tmp/s22.json" >/dev/null
    run "cd /var/www/html && php -r '
        \$j = json_decode(file_get_contents(\"composer.json\"), true);
        \$j[\"extra\"][\"magento-patches\"][\"patches\"][] = [
            \"id\" => \"BAD-CONSTRAINT\",
            \"source\" => \"tests-fixtures/not-here.patch\",
        ];
        file_put_contents(\"composer.json\", json_encode(\$j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    '" >/dev/null

    check "stdout is still valid json" "ok" \
        "$(run "cd /var/www/html && composer patches:verify --json --no-interaction 2>/dev/null | php -r 'echo json_decode(stream_get_contents(STDIN), true) === null ? \"bad\" : \"ok\";'")"
    check "the error went to stderr" "1" \
        "$(run "cd /var/www/html && composer patches:verify --json --no-interaction 2>&1 >/dev/null | grep -c 'points at a missing file'")"
    check "and --json exits 3 like the plain form" "3" \
        "$(run "cd /var/www/html && composer patches:verify --json --no-interaction >/dev/null 2>&1; echo \$?")"
    check "even for patches:list" "3" \
        "$(run "cd /var/www/html && composer patches:list --json --no-interaction >/dev/null 2>&1; echo \$?")"
    # The body is what automation reads. It saying 0 while the process says 3 is
    # worse than the field not being there.
    check "and the body agrees with the process" "3" \
        "$(run "cd /var/www/html && composer patches:verify --json --no-interaction 2>/dev/null | php -r 'echo json_decode(stream_get_contents(STDIN), true)[\"exit_code\"];\"\";'")"

    # An unparseable constraint from a trusted package is a config error, not a
    # VersionParser stack trace that fails composer install for every consumer.
    run "cd /var/www/html && cp /tmp/s22.json composer.json && php -r '
        \$j = json_decode(file_get_contents(\"composer.json\"), true);
        \$j[\"extra\"][\"magento-patches\"][\"patches\"][] = [
            \"id\" => \"BAD-RANGE\",
            \"source\" => \"tests-fixtures/not-here.patch\",
            \"base\" => [\"magento/framework\" => \">=1.0.0 <\"],
        ];
        file_put_contents(\"composer.json\", json_encode(\$j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    '" >/dev/null

    local out
    out=$(run "cd /var/www/html && composer patches:list --no-interaction 2>&1")
    check "no stack trace escapes" "0" "$(echo "$out" | grep -c 'VersionParser')"
    checkne "the bad constraint is named" "0" "$(echo "$out" | grep -c 'unparseable base constraint')"

    run "cd /var/www/html && cp /tmp/s22.json composer.json" >/dev/null
    check "clean again once restored" "0" "$(verify_code)"
}

s_catalogue() {
    scenario "S24 patches:list is the catalogue, and never touches the working tree"

    local out
    out=$(run "cd /var/www/html && composer patches:list --no-interaction 2>&1")
    checkne "declares what the trusted package offers" "0" "$(echo "$out" | grep -c 'fixtures/patches')"
    checkne "and says how many apply here" "0" "$(echo "$out" | grep -c 'apply to this install')"
    check "without classifying the working tree" "0" "$(echo "$out" | grep -cE 'in place|MISSING')"

    # The real test of the split: the catalogue must not change when the working
    # tree does. If it did, it would just be patches:status with fewer columns.
    # (Moving vendor/ away would prove it harder and also delete the plugin.)
    local target="vendor/magento/framework/Escaper.php"
    local before after
    before=$(run "cd /var/www/html && composer patches:list --no-interaction 2>/dev/null")

    run "sed -i '\$ d' /var/www/html/$target" >/dev/null
    after=$(run "cd /var/www/html && composer patches:list --no-interaction 2>/dev/null")

    check "unchanged by a target going missing" "$before" "$after"
    check "while status notices immediately" "1" "$(verify_code)"

    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    check "the store is unharmed" "0" "$(verify_code)"
}

s_selection() {
    scenario "S25 a project can switch a patch off, and is never allowed to forget"

    run "cd /var/www/html && cp composer.json /tmp/s25.json" >/dev/null

    # FX-0002 is deliberately the middle link of the three-patch chain the
    # fixture builds, so the cascade has something to take down and the
    # assertion does not depend on which id happens to be listed first.
    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null

    # The file the fixture's middle link patches, and the comment it leaves —
    # both from tests/fixtures/build.php, which writes "/* <name> */".
    local marker="chain-two"
    local target="vendor/magento/module-catalog/etc/module.xml"

    # FX-0003, the link after the one being switched off. The cascade is only
    # half done if the report names it but the patch stays on disk.
    local cascaded="chain-three"
    local cascaded_target="vendor/magento/module-customer/etc/module.xml"

    check "the fixture marker is really there to begin with" "1" \
        "$(run "grep -c '$marker' /var/www/html/$target")"
    check "and so is the one after it" "1" \
        "$(run "grep -c '$cascaded' /var/www/html/$cascaded_target")"

    # Scoped to the base line: a bare id naming a chain member is refused, so a
    # line published later that reuses the id cannot go dark under a reason
    # written about a different Magento version.
    local base
    base=$(run "cd /var/www/html && composer patches:list --no-interaction 2>/dev/null | grep -B20 'FX-0002' | grep -oE '2\.4\.[0-9]+(-p[0-9]+)?' | head -1")

    run "cd /var/www/html && composer config --json extra.magento-patches.sources \
        '{\"fixtures/patches\": {\"skip\": {\"FX-0002@$base\": \"breaks our checkout, JIRA-123\"}}}'" >/dev/null

    checkne "a scoped skip is accepted" "3" \
        "$(run "cd /var/www/html && composer patches:status --no-interaction >/dev/null 2>&1; echo \$?")"

    # And the unscoped form is refused, because the cascade multiplies it.
    run "cd /var/www/html && composer config --json extra.magento-patches.sources \
        '{\"fixtures/patches\": {\"skip\": {\"FX-0002\": \"unscoped\"}}}'" >/dev/null
    check "an unscoped chain skip is a config error" "3" \
        "$(run "cd /var/www/html && composer patches:status --no-interaction >/dev/null 2>&1; echo \$?")"

    run "cd /var/www/html && composer config --json extra.magento-patches.sources \
        '{\"fixtures/patches\": {\"skip\": {\"FX-0002@$base\": \"breaks our checkout, JIRA-123\"}}}'" >/dev/null

    local out
    out=$(run "cd /var/www/html && composer patches:status --no-interaction 2>&1")
    checkne "the skipped patch keeps its row" "0" "$(echo "$out" | grep -c SKIPPED)"
    checkne "and carries the reason given" "0" "$(echo "$out" | grep -c 'breaks our checkout')"
    checkne "the banner says so" "0" "$(echo "$out" | grep -c 'switched off by this project')"
    check "but it is not a failure" "0" \
        "$(run "cd /var/www/html && composer patches:status --no-interaction >/dev/null 2>&1; echo \$?")"

    # A skipped link takes the rest of its chain with it, by name.
    checkne "FX-0003 goes with it" "0" "$(echo "$out" | grep -c 'FX-0003')"
    checkne "and says which patch took it down" "0" "$(echo "$out" | grep -c 'depends on FX-0002')"

    # The assertion the rest of S25 cannot make: a patch marked skipped must not
    # reach the working tree. Without this, an implementation that marked it and
    # applied it anyway would pass every check above.
    run "sed -i '/$marker/d' /var/www/html/$target" >/dev/null
    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    check "and apply does not put it back" "0" "$(run "grep -c '$marker' /var/www/html/$target")"

    # The stronger promise: "skipped" has to mean the patch is not there, not
    # merely that it was not applied on top. A long-lived tree already carries
    # it, and only this takes it back off.
    run "cd /var/www/html && cp /tmp/s25.json composer.json && composer patches:apply --no-interaction" >/dev/null
    check "the patch is back for the next part" "1" "$(run "grep -c '$marker' /var/www/html/$target")"

    run "cd /var/www/html && composer config --json extra.magento-patches.sources \
        '{\"fixtures/patches\": {\"skip\": {\"FX-0002@$base\": \"breaks our checkout, JIRA-123\"}}}'" >/dev/null

    out=$(run "cd /var/www/html && composer patches:status --no-interaction 2>&1")
    checkne "status says it is still present" "0" "$(echo "$out" | grep -c PRESENT)"
    check "and status changed nothing" "1" "$(run "grep -c '$marker' /var/www/html/$target")"

    out=$(run "cd /var/www/html && composer patches:apply --no-interaction 2>&1")
    check "apply removes a patch the project switched off" "0" \
        "$(run "grep -c '$marker' /var/www/html/$target")"
    checkne "and says which file it took it out of" "0" "$(echo "$out" | grep -c removed)"

    # Everything above this line is satisfied by an implementation that reverts
    # only the patch named in the config and leaves the rest of the chain on
    # disk — stacked on a prerequisite that has just been taken away.
    check "and takes the rest of the chain off disk with it" "0" \
        "$(run "grep -c '$cascaded' /var/www/html/$cascaded_target")"

    # And a typo must not quietly switch nothing off.
    run "cd /var/www/html && composer config --json extra.magento-patches.sources \
        '{\"fixtures/patches\": {\"skip\": {\"NOPE-1\": \"typo\"}}}'" >/dev/null
    check "a skip matching nothing is a config error" "3" \
        "$(run "cd /var/www/html && composer patches:status --no-interaction >/dev/null 2>&1; echo \$?")"

    # The skip removed the patch from disk, so putting the config back is not
    # enough on its own — the next apply is what restores it. That asymmetry is
    # the whole point of the feature, so assert it rather than paper over it.
    run "cd /var/www/html && cp /tmp/s25.json composer.json" >/dev/null
    check "still missing until something applies it" "1" "$(verify_code)"

    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    check "clean again once applied" "0" "$(verify_code)"
    check "and the cascaded patch came back too" "1" \
        "$(run "grep -c '$cascaded' /var/www/html/$cascaded_target")"
}

s_include() {
    scenario "S26 patches can be declared in files beside the patches themselves"

    run "cd /var/www/html && cp composer.json /tmp/s26.json" >/dev/null

    # Move one declaration out of composer.json and into a manifest that sits in
    # the same directory as its patch, with a source relative to that file.
    run "cd /var/www/html && php -r '
        \$j = json_decode(file_get_contents(\"composer.json\"), true);
        \$patches = \$j[\"extra\"][\"magento-patches\"][\"patches\"] ?? [];
        \$moved = array_pop(\$patches);
        \$j[\"extra\"][\"magento-patches\"][\"patches\"] = \$patches;
        \$j[\"extra\"][\"magento-patches\"][\"include\"] = [\"tests-fixtures/moved.json\"];
        file_put_contents(\"composer.json\", json_encode(\$j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        \$moved[\"source\"] = basename(\$moved[\"source\"]);
        file_put_contents(\"tests-fixtures/moved.json\", json_encode([\"patches\" => [\$moved]]));
        file_put_contents(\"/tmp/s26-id.txt\", \$moved[\"id\"]);
    '" >/dev/null

    local moved out
    moved=$(run "cd /var/www/html && cat /tmp/s26-id.txt")

    out=$(run "cd /var/www/html && composer patches:list --no-interaction 2>&1")
    check "no configuration errors" "0" "$(echo "$out" | grep -c 'Patch configuration')"
    # The assertion that matters: the moved declaration has to still be there.
    # Without it this passes even if include drops the file on the floor, because
    # the patch is already applied from an earlier scenario and an undeclared
    # patch produces neither an error nor a verify failure.
    checkne "the moved patch is still declared" "0" "$(echo "$out" | grep -c "$moved")"
    check "the store is still fully patched" "0" "$(verify_code)"

    # A named file that is not there is an error, never a shorter list.
    run "cd /var/www/html && composer config --json extra.magento-patches.include '[\"tests-fixtures/gone.json\"]'" >/dev/null
    out=$(run "cd /var/www/html && composer patches:status --no-interaction 2>&1")
    checkne "a missing include is named" "0" "$(echo "$out" | grep -c 'gone.json')"
    check "and is a config error" "3" \
        "$(run "cd /var/www/html && composer patches:status --no-interaction >/dev/null 2>&1; echo \$?")"

    run "cd /var/www/html && cp /tmp/s26.json composer.json" >/dev/null
    check "clean again once restored" "0" "$(verify_code)"
}

s_banner() {
    scenario "S23 the banner says OK, WARN or ISSUE, without needing colour"

    local out advisories
    out=$(run "cd /var/www/html && composer patches:verify --no-interaction 2>&1")

    check "a clean store does not shout" "0" "$(echo "$out" | grep -c '!!! ISSUE')"
    checkne "and says everything landed" "0" "$(echo "$out" | grep -c 'targets applied')"

    # Which clean band it is depends on the release, not on the patches: an
    # out-of-support store says WARN with the advisory, an in-support one says
    # OK. Asserting OK unconditionally passed only until 2.4.6 reached end of
    # life on 2026-08-11, at which point two images in the matrix started
    # failing a test about the banner for a reason that had nothing to do with
    # the banner. The exit code is 0 either way — that is what S17 checks.
    advisories=$(echo "$out" | grep -c 'end of life')

    if [ "$advisories" = "0" ]; then
        checkne "a supported store says OK" "0" "$(echo "$out" | grep -c 'OK')"
    else
        checkne "an out-of-support store says WARN" "0" "$(echo "$out" | grep -c 'WARN')"
        checkne "and points at the advisory" "0" "$(echo "$out" | grep -c 'advisory above')"
    fi

    run "sed -i '\$ d' /var/www/html/vendor/magento/framework/Escaper.php" >/dev/null

    out=$(run "cd /var/www/html && composer patches:verify --no-interaction 2>&1")
    checkne "an unpatched store shouts in punctuation" "0" "$(echo "$out" | grep -c '!!! ISSUE')"
    checkne "and says what to run" "0" "$(echo "$out" | grep -c 'composer patches:apply')"

    run "cd /var/www/html && composer patches:apply --no-interaction" >/dev/null
    out=$(run "cd /var/www/html && composer patches:verify --no-interaction 2>&1")
    check "quiet again once applied" "0" "$(echo "$out" | grep -c '!!! ISSUE')"
}

s_fresh_vendor() {
    scenario "S17 rm -rf vendor && composer install"

    run "cd /var/www/html && rm -rf vendor && composer install --no-interaction" >/dev/null
    check "verify exits clean" "0" "$(verify_code)"

    local applied
    applied=$(counted "patches:status" "in place")
    checkne "targets in place" "0" "${applied:-0}"
}

s_coexistence() {
    scenario "S13 coexists with vaimo/composer-patches"

    local ours theirs
    ours=$(run "cd /var/www/html && composer list --no-interaction 2>/dev/null | grep -c 'patches:verify'")
    theirs=$(run "cd /var/www/html && composer list --no-interaction 2>/dev/null | grep -c 'patch:redo'")

    check "our commands registered" "1" "$ours"
    if [ "$theirs" != "0" ]; then
        check "vaimo keeps its own commands" "1" "$theirs"
    fi
}

s_dump_autoload() {
    scenario "S27 composer dump-autoload is not a way to lose patches"

    # vaimo hooks PRE_AUTOLOAD_DUMP, which composer dump-autoload fires; it does
    # not fire post-install-cmd, where we used to be the only listener. So a
    # bare dump-autoload gave vaimo a full reset-and-repatch cycle while we
    # never ran. Measured before the fix: a green store, one dump-autoload with
    # vaimo configured, and verify went from 0 to 1 with no chance to heal.
    check "green before" "0" "$(verify_code)"

    local out
    out=$(run "cd /var/www/html && composer dump-autoload -o --no-interaction 2>&1")
    checkne "we run on dump-autoload" "0" "$(echo "$out" | grep -c 'Magento security patches')"
    check "and the store is still patched" "0" "$(verify_code)"

    # Break a target, then dump-autoload: the point is that this event now heals
    # rather than merely reporting.
    run "sed -i '$ d' /var/www/html/vendor/magento/framework/Escaper.php" >/dev/null
    check "a broken target is noticed" "1" "$(verify_code)"

    run "cd /var/www/html && composer dump-autoload -o --no-interaction" >/dev/null
    check "dump-autoload puts it back" "0" "$(verify_code)"

    # And the event must not double-report during an ordinary install, where
    # it fires before post-install-cmd and before the root files are deployed.
    local reports
    reports=$(run "cd /var/www/html && composer install --no-interaction 2>&1 | grep -c 'Magento security patches'")
    check "install still reports exactly once" "1" "$reports"
}

# --------------------------------------------------------------------- main --

run_image() {
    local image="$1"
    printf '\n%s== %s%s\n' "$YELLOW" "$image" "$RESET"

    if ! setup "$image"; then
        printf '    %s✗%s could not start %s\n' "$RED" "$RESET" "$image"
        FAIL=$((FAIL + 1))
        return
    fi

    printf '    %sphp %s / composer %s%s\n' "$DIM" \
        "$(run "php -r 'echo PHP_VERSION;'")" \
        "$(run "composer --version 2>/dev/null | cut -d' ' -f3")" "$RESET"

    s_first_install
    s_idempotent
    s_reinstall_base
    s_chain
    s_state_and_json
    s_supersede
    s_unapplied_detected
    s_conflict
    s_mapped_directory
    s_symlink_strategy
    s_hardlink_strategy
    s_file_without_package
    s_coexistence
    s_replaced_package
    s_stale_advisory
    s_reported_problems
    s_dry_run
    s_read_only
    s_config_error_fails_install
    s_json_is_json
    s_catalogue
    s_selection
    s_include
    s_banner
    s_dump_autoload
    s_fresh_vendor

    cleanup
    CONTAINER=""
}

MATRIX=("$@")
[ ${#MATRIX[@]} -eq 0 ] && MATRIX=("${DEFAULT_MATRIX[@]}")

for image in "${MATRIX[@]}"; do
    run_image "$image"
done

printf '\n%s%d passed%s, %s%d failed%s\n' "$GREEN" "$PASS" "$RESET" \
    "$( [ "$FAIL" -gt 0 ] && echo "$RED" || echo "$DIM" )" "$FAIL" "$RESET"

[ "$FAIL" -eq 0 ]
