#!/usr/bin/env bash
# Shared helpers to find a PHP CLI binary that has the PDO SQLite extension.
# Sourced by iptv-manager and iptv-manager-service.

php_has_pdo_sqlite() {
    local candidate=${1:-}
    [ -n "$candidate" ] || return 1
    [ -x "$candidate" ] || return 1
    "$candidate" -m 2>/dev/null | grep -qi '^pdo_sqlite$'
}

php_version_of() {
    local candidate=${1:-}
    [ -n "$candidate" ] || return 1
    "$candidate" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null
}

php_candidate_list() {
    local candidate resolved

    if [ -n "${PHP_BIN:-}" ]; then
        printf '%s\n' "$PHP_BIN"
        case "$PHP_BIN" in
            /*) ;;
            *) resolved=$(command -v "$PHP_BIN" 2>/dev/null || true); [ -n "$resolved" ] && printf '%s\n' "$resolved" ;;
        esac
    fi

    resolved=$(command -v php 2>/dev/null || true)
    [ -n "$resolved" ] && printf '%s\n' "$resolved"

    for candidate in \
        /usr/bin/php[0-9]* \
        /usr/bin/php8* \
        /usr/local/bin/php[0-9]* \
        /usr/local/bin/php \
        /etc/alternatives/php \
        /opt/php*/bin/php \
        /usr/local/php*/bin/php \
        /snap/bin/php
    do
        [ -x "$candidate" ] && [ ! -d "$candidate" ] && printf '%s\n' "$candidate"
    done
}

php_resolve_bin() {
    local candidate
    while IFS= read -r candidate; do
        [ -n "$candidate" ] || continue
        if php_has_pdo_sqlite "$candidate"; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done < <(php_candidate_list)
    return 1
}

php_resolve_bin_notice() {
    local preferred=${PHP_BIN:-} resolved
    resolved=$(php_resolve_bin) || return 1
    if [ -n "$preferred" ] && [ "$preferred" != "$resolved" ] && ! php_has_pdo_sqlite "$preferred"; then
        echo "Notice: $preferred has no pdo_sqlite extension — using $resolved instead." >&2
    fi
    printf '%s\n' "$resolved"
}

php_install_sqlite_extension() {
    local version package

    [ "$(id -u)" -eq 0 ] || return 1
    command -v apt-get >/dev/null 2>&1 || return 1

    version=$(php_version_of "$(command -v php 2>/dev/null || true)" || true)
    [ -n "$version" ] || return 1
    package="php$version-sqlite3"

    echo "Installing $package ..." >&2
    if ! DEBIAN_FRONTEND=noninteractive apt-get install -y "$package" >/dev/null 2>&1; then
        echo "Refreshing the package lists ..." >&2
        DEBIAN_FRONTEND=noninteractive apt-get update >/dev/null 2>&1 || return 1
        DEBIAN_FRONTEND=noninteractive apt-get install -y "$package" >/dev/null 2>&1 || return 1
    fi
    return 0
}

php_ensure_sqlite() {
    php_resolve_bin >/dev/null 2>&1 && return 0
    php_install_sqlite_extension || return 1
    php_resolve_bin >/dev/null 2>&1
}

php_report_missing_sqlite() {
    local candidate version package seen=" "

    echo "Error: PHP must have the PDO SQLite extension enabled." >&2
    while IFS= read -r candidate; do
        [ -n "$candidate" ] || continue
        case "$seen" in *" $candidate "*) continue ;; esac
        seen="$seen$candidate "
        version=$(php_version_of "$candidate" || true)
        [ -n "$version" ] || continue
        if php_has_pdo_sqlite "$candidate"; then
            echo "  $candidate (PHP $version) has pdo_sqlite" >&2
        else
            echo "  $candidate (PHP $version) has no pdo_sqlite" >&2
        fi
    done < <(php_candidate_list)

    package="php-sqlite3"
    version=$(php_version_of "$(command -v php 2>/dev/null || true)" || true)
    [ -n "$version" ] && package="php$version-sqlite3"
    echo "  Install it with: sudo apt install $package" >&2
    echo "  Or select another PHP binary with PHP_BIN=/path/to/php in .env" >&2
}
