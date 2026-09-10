#!/usr/bin/env bash
# Trial this plugin against a real project, without changing anything in it.
#
# Stages the plugin and the meta package inside the project, installs them in
# dry-run mode so every patch is classified but nothing is written, prints the
# report, then puts composer.json, composer.lock and vendor/ back exactly as
# they were.
#
#   tests/trial.sh ~/Sites/some-store
#   tests/trial.sh ~/Sites/store-a ~/Sites/store-b        # several at once
#   tests/trial.sh --apply ~/Sites/some-store             # actually patch it
#
# Projects that run Composer inside a container need a runner prefix, and the
# staged packages are placed inside the project so the container sees them:
#
#   RUNNER="warden env exec -T php-fpm" tests/trial.sh ~/Sites/some-store
#   RUNNER="ddev exec" tests/trial.sh ~/Sites/some-store

set -uo pipefail

CWD="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$CWD")"
META_DIR="${META_DIR:-$HOME/Projects/personal/m2-meta-security-patches}"
RUNNER="${RUNNER:-}"
STAGE_DIR=".patch-trial"
BACKUP_DIR=""   # outside the project, so removing the stage cannot strand it

RED=$'\033[1;31m'; GREEN=$'\033[1;32m'; YELLOW=$'\033[1;33m'; DIM=$'\033[2m'; RESET=$'\033[0m'

APPLY=0
ALLOW_DIRTY=0
PROJECTS=()

for arg in "$@"; do
    case "$arg" in
        --apply) APPLY=1 ;;
        --allow-dirty) ALLOW_DIRTY=1 ;;
        -h|--help) sed -n '2,20p' "$0" | sed 's/^# \?//'; exit 0 ;;
        *) PROJECTS+=("$arg") ;;
    esac
done

if [ ${#PROJECTS[@]} -eq 0 ]; then
    echo "usage: tests/trial.sh [--apply] <project-dir> [project-dir ...]" >&2
    exit 1
fi

say()  { printf '%s%s%s\n' "$DIM" "$1" "$RESET"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
bad()  { printf '  %s✗%s %s\n' "$RED" "$RESET" "$1"; }
warn() { printf '  %s!%s %s\n' "$YELLOW" "$RESET" "$1"; }

# Composer inside the project, through whatever runner the project needs.
compose() {
    if [ -n "$RUNNER" ]; then
        (cd "$PROJECT" && $RUNNER composer "$@")
    else
        (cd "$PROJECT" && composer "$@")
    fi
}

# Warden on macOS syncs the project into the container with Mutagen rather than
# bind-mounting it, so a file written on the host is not there yet when the next
# command runs inside. Wait for it rather than racing it.
wait_for_sync() {
    local path="$1" attempts=60

    [ -z "$RUNNER" ] && return 0

    while [ "$attempts" -gt 0 ]; do
        if (cd "$PROJECT" && $RUNNER test -e "$path") >/dev/null 2>&1; then
            return 0
        fi
        attempts=$((attempts - 1))
        sleep 1
    done

    bad "timed out waiting for $path to sync into the container"
    return 1
}

# The opposite direction: wait until what the container sees matches the host.
wait_for_content() {
    local path="$1" expected="$2" attempts=60

    [ -z "$RUNNER" ] && return 0

    while [ "$attempts" -gt 0 ]; do
        if [ "$( (cd "$PROJECT" && $RUNNER md5sum "$path") 2>/dev/null | cut -d' ' -f1)" = "$expected" ]; then
            return 0
        fi
        attempts=$((attempts - 1))
        sleep 1
    done

    bad "timed out waiting for $path to match the host copy"
    return 1
}

restore() {
    [ -z "${PROJECT:-}" ] && return 0
    [ -z "$BACKUP_DIR" ] && return 0
    [ ! -f "$BACKUP_DIR/composer.json" ] && return 0

    say "  restoring the project…"

    # Lock first: composer install reads it, and a restored composer.json
    # pointing at a staged path repo that is about to be deleted is exactly how
    # a half-restored project ends up with dangling symlinks in vendor/.
    [ -f "$BACKUP_DIR/composer.lock" ] && cp "$BACKUP_DIR/composer.lock" "$PROJECT/composer.lock"
    cp "$BACKUP_DIR/composer.json" "$PROJECT/composer.json"

    local restored=1
    cmp -s "$BACKUP_DIR/composer.json" "$PROJECT/composer.json" || restored=0
    if [ -f "$BACKUP_DIR/composer.lock" ]; then
        cmp -s "$BACKUP_DIR/composer.lock" "$PROJECT/composer.lock" || restored=0
    fi

    if [ "$restored" != "1" ]; then
        bad "could not restore composer.json/composer.lock"
        warn "originals are in $BACKUP_DIR — put them back by hand before doing anything else"
        return 1
    fi

    wait_for_content "/var/www/html/composer.json" "$(md5 -q "$PROJECT/composer.json" 2>/dev/null || md5sum "$PROJECT/composer.json" | cut -d' ' -f1)"
    if [ -f "$PROJECT/composer.lock" ]; then
        wait_for_content "/var/www/html/composer.lock" "$(md5 -q "$PROJECT/composer.lock" 2>/dev/null || md5sum "$PROJECT/composer.lock" | cut -d' ' -f1)"
    fi

    local out
    out=$(compose install --no-interaction 2>&1)

    if [ $? -ne 0 ]; then
        bad "restoring dependencies failed:"
        echo "$out" | tail -12 | sed 's/^/      /'
        warn "originals are in $BACKUP_DIR"
        rm -rf "${PROJECT:?}/$STAGE_DIR"
        return 1
    fi

    # Composer will not unload a plugin package in the same run that removes it
    # from the lock, so the staged symlinks survive that first install. They are
    # ours, so take them out and let a second install reconcile the autoloader.
    if [ -d "$PROJECT/vendor/samjuk" ]; then
        rm -rf "${PROJECT:?}/vendor/samjuk"
        compose install --no-interaction >/dev/null 2>&1
    fi

    rm -rf "${PROJECT:?}/$STAGE_DIR"

    local leftovers=""
    grep -q '"samjuk/' "$PROJECT/composer.lock" 2>/dev/null && leftovers="composer.lock"
    [ -d "$PROJECT/vendor/samjuk" ] && leftovers="${leftovers:+$leftovers and }vendor/samjuk"

    if [ -n "$leftovers" ]; then
        bad "the trial left samjuk packages behind in $leftovers"
        warn "originals are in $BACKUP_DIR"
        return 1
    fi

    ok "project restored — composer.json, composer.lock and vendor/ are as they were"
    rm -rf "$BACKUP_DIR"
    BACKUP_DIR=""
}
trap 'restore' EXIT

trial() {
    PROJECT="$(cd "$1" 2>/dev/null && pwd)" || { bad "no such directory: $1"; return 1; }

    printf '\n%s== %s%s\n' "$YELLOW" "$PROJECT" "$RESET"

    if [ ! -f "$PROJECT/composer.json" ]; then
        bad "no composer.json here"
        return 1
    fi

    # Applying for real rewrites files in vendor/. Insist on a clean tree so
    # there is something to compare against afterwards.
    if [ "$APPLY" = "1" ] && [ "$ALLOW_DIRTY" = "0" ] && [ -d "$PROJECT/.git" ]; then
        if [ -n "$(cd "$PROJECT" && git status --porcelain 2>/dev/null)" ]; then
            bad "working tree is dirty — commit or stash first, or pass --allow-dirty"
            return 1
        fi
    fi

    # --- what is patching this project today? ----------------------------
    local existing=""
    for patcher in vaimo/composer-patches cweagans/composer-patches; do
        if [ -d "$PROJECT/vendor/${patcher}" ]; then
            local version
            version=$(php -r "\$j=json_decode(file_get_contents('$PROJECT/vendor/composer/installed.json'),true); \$p=\$j['packages']??\$j; foreach(\$p as \$x){ if(\$x['name']==='$patcher'){ echo \$x['version']; } }" 2>/dev/null)
            existing="$existing $patcher${version:+ $version}"
        fi
    done

    if [ -n "$existing" ]; then
        say "  already patching with:$existing"
    else
        say "  no existing patch plugin found"
    fi

    local declared
    declared=$(php -r "\$j=json_decode(file_get_contents('$PROJECT/composer.json'),true); \$p=(array)(\$j['extra']['patches']??[]); \$n=0; array_walk_recursive(\$p, function() use (&\$n){ \$n++; }); echo (int)\$n;" 2>/dev/null)
    [ "${declared:-0}" != "0" ] && say "  project declares ${declared} patch entr(y|ies) of its own in extra.patches"

    # --- stage the two packages inside the project -----------------------
    rm -rf "${PROJECT:?}/$STAGE_DIR"
    mkdir -p "$PROJECT/$STAGE_DIR"

    BACKUP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/patch-trial-backup.XXXXXX")
    cp "$PROJECT/composer.json" "$BACKUP_DIR/composer.json"
    [ -f "$PROJECT/composer.lock" ] && cp "$PROJECT/composer.lock" "$BACKUP_DIR/composer.lock"
    say "  originals copied to $BACKUP_DIR"

    cp -R "$PLUGIN_DIR" "$PROJECT/$STAGE_DIR/magento-patch-installer"
    cp -R "$META_DIR" "$PROJECT/$STAGE_DIR/m2-meta-security-patches"
    rm -rf "$PROJECT/$STAGE_DIR"/*/.git

    # Composer cannot guess a version for a path repo with no commits.
    php -r "
        \$f = '$PROJECT/$STAGE_DIR/magento-patch-installer/composer.json';
        \$j = json_decode(file_get_contents(\$f), true);
        \$j['version'] = '0.1.0';
        file_put_contents(\$f, json_encode(\$j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    "

    wait_for_sync "/var/www/html/$STAGE_DIR/magento-patch-installer/composer.json" || return 1
    wait_for_sync "/var/www/html/$STAGE_DIR/m2-meta-security-patches/composer.json" || return 1

    compose config repositories.trial-plugin path "./$STAGE_DIR/magento-patch-installer" >/dev/null 2>&1
    compose config repositories.trial-meta path "./$STAGE_DIR/m2-meta-security-patches" >/dev/null 2>&1
    compose config --no-plugins allow-plugins.samjuk/magento-patch-installer true >/dev/null 2>&1
    compose config --json extra.magento-patches.trust '["samjuk/*"]' >/dev/null 2>&1

    if [ "$APPLY" = "0" ]; then
        compose config --json extra.magento-patches.dry-run true >/dev/null 2>&1
        say "  dry run — the report below is what would happen, nothing is written"
    else
        warn "APPLYING FOR REAL — vendor/ will be modified"
    fi

    # --- install and report ----------------------------------------------
    local out
    out=$(compose require samjuk/m2-meta-security-patches:@dev --no-interaction -W 2>&1)

    if ! echo "$out" | grep -q "Magento security patches"; then
        bad "the plugin did not run — Composer said:"
        echo "$out" | tail -15 | sed 's/^/      /'
        return 1
    fi

    echo "$out" | grep -A2 "Magento security patches" | sed 's/^/  /'
    echo
    compose patches:list -v --no-interaction 2>&1 | sed 's/^/  /'

    compose patches:verify --no-interaction >/dev/null 2>&1
    local code=$?

    echo
    case "$code" in
        0) ok "patches:verify exit 0 — everything applicable is in place" ;;
        1) warn "patches:verify exit 1 — applicable patches are not applied (expected on a dry run)" ;;
        2) bad "patches:verify exit 2 — conflict, look at the detail above" ;;
        *) bad "patches:verify exit $code" ;;
    esac

    # Did we disturb anything the other patcher had done?
    if [ -n "$existing" ] && [ -d "$PROJECT/.git" ]; then
        local changed
        changed=$(cd "$PROJECT" && git status --porcelain 2>/dev/null | grep -vE "$STAGE_DIR|composer\.(json|lock)" | wc -l | tr -d ' ')
        [ "$changed" = "0" ] && ok "no unexpected changes in the tracked tree"
    fi

    if [ "$APPLY" = "1" ]; then
        echo
        ok "left in place — the plugin and meta package are installed and the patches are applied"
        say "  to undo: composer remove samjuk/m2-meta-security-patches samjuk/magento-patch-installer"
        say "           rm -rf $STAGE_DIR && composer install"
        say "  originals of composer.json/composer.lock: $BACKUP_DIR"
        BACKUP_DIR=""
        PROJECT=""
        return 0
    fi

    restore
    PROJECT=""
}

for project in "${PROJECTS[@]}"; do
    trial "$project"
done

printf '\n%sdone%s\n' "$DIM" "$RESET"
