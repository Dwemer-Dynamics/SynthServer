#!/usr/bin/env bash
# Read-only PHP runtime security preflight for deploy/install/update entry points
# (PHP2Shell, CVE-2026-109618 and CVE-2026-109619).
# Passes only on a Debian-style distro where /usr/bin/php (and php on PATH) and every PHP runtime
# Apache has enabled (mod_php or PHP-FPM) are the distro's packaged phpX.Y binaries, unmodified
# against dpkg's checksums, from an installed package and with an embedded PHP version that are
# both at or above the patched floor of a supported branch, and no running Apache, PHP-FPM or PHP
# process still uses a PHP binary or extension that a package update replaced or that could not be
# verified. Unknown or custom paths are refused, never assumed safe. It installs, restarts and
# changes nothing, and prints only versions and paths (never phpinfo, command lines, configuration
# or credentials), one [OK]/[INFO]/[WARN]/[FAIL] line each.
# Usage: bash check-php-runtime-security.sh   (run as root so running processes can be checked)
set -euo pipefail

# Patched floor for each supported branch. Every other branch, older or newer, is refused.
php_security_floors=(8.2.35 8.3.36 8.4.27 8.5.12)
php_security_apache_dir=/etc/apache2
php_security_proc_dir=/proc
php_security_debian_file=/etc/debian_version
php_security_dpkg_info=/var/lib/dpkg/info
php_security_cli=/usr/bin/php
php_security_bin_dir=/usr/bin
php_security_sbin_dir=/usr/sbin
php_security_module_dir=/usr/lib/apache2/modules
php_security_core_check=/usr/local/bin/ensure_php_security
php_security_uid=${EUID}

# Succeeds when a PHP_VERSION or Debian package version is a patched release of a supported
# branch. Accepts an optional epoch and a numeric distribution revision (8.2.35-1~deb12u1);
# refuses pre-releases such as 8.5.12RC1 or 8.4.27-dev.
php_security_version_patched() {
    local version=${1#*:} floor
    [[ $1 =~ ^([0-9]+:)?[0-9]+\.[0-9]+\.[0-9]+(-[0-9][0-9A-Za-z.+~-]*)?$ ]] || return 1
    [[ $version =~ ^([0-9]+)\.([0-9]+)\.([0-9]+) ]] || return 1
    local major=$((10#${BASH_REMATCH[1]})) minor=$((10#${BASH_REMATCH[2]})) patch=$((10#${BASH_REMATCH[3]}))
    for floor in "${php_security_floors[@]}"; do
        if [[ ${floor%.*} == "${major}.${minor}" ]]; then
            (( patch >= 10#${floor##*.} ))
            return
        fi
    done
    return 1
}

php_security_package_version() {
    local status
    status=$(dpkg-query -W -f='${Status}\t${Version}' "$1" 2>/dev/null) || return 1
    [[ ${status%%$'\t'*} == 'install ok installed' ]] || return 1
    printf '%s' "${status#*$'\t'}"
}

# One console line, printable ASCII only and short enough for project.ps1's console protocol.
php_security_say() {
    printf '[%s] %s\n' "$1" "$(printf '%s' "$2" | LC_ALL=C tr -c '\040-\176' '?' | cut -c1-400)"
}

php_security_relay() {
    local line
    while IFS= read -r line || [[ -n ${line} ]]; do
        [[ -n ${line} ]] && php_security_say "$1" "${line}" >&2
    done
    return 0
}

# Succeeds when $1 is a plain non-symlink root-owned executable and it and every parent folder
# are root-owned, not symlinks and not group- or world-writable.
php_security_trusted_root_file() {
    local path=$1 meta
    [[ ${path} == /* && -f ${path} && ! -L ${path} && -x ${path} ]] || return 1
    while :; do
        [[ ! -L ${path} ]] && meta=$(stat -c '%u %a' -- "${path}" 2>/dev/null) || return 1
        [[ ${meta%% *} == 0 ]] && (( (8#${meta#* } & 8#022) == 0 )) || return 1
        [[ ${path} == / ]] && return 0
        path=$(dirname -- "${path}")
    done
}

php_security_md5sums_file() {
    local file
    for file in "${php_security_dpkg_info}/$1.md5sums" "${php_security_dpkg_info}/$1":*.md5sums; do
        if [[ -f ${file} && ! -L ${file} ]]; then
            printf '%s' "${file}"
            return 0
        fi
    done
    return 1
}

# Verifies that $1 is a plain file of package $2 (branch $3) that matches dpkg's recorded checksum,
# that the package is installed at a patched version, and that the PHP version embedded in the
# binary (its X-Powered-By header) is a patched release of the same branch. Results are cached in
# the caller's 'verified' array; failures are added to the caller's 'problems'.
php_security_verify_binary() {
    local path=$1 package=$2 branch=$3 owner version sums expected sum embedded
    [[ -n ${verified[${path}]:-} ]] && { [[ ${verified[${path}]} == ok ]]; return; }
    verified[${path}]=bad
    branches[${branch}]=1
    if [[ -L ${path} || ! -f ${path} || $(readlink -f -- "${path}" 2>/dev/null) != "${path}" ]]; then
        problems+=("${path} is not a plain file of ${package}")
        return 1
    fi
    owner=$(dpkg-query -S -- "${path}" 2>/dev/null) || owner=''
    if [[ ! ${owner} =~ ^([a-z0-9][a-z0-9.+-]*)(:[a-z0-9]+)?:\ (.*)$ || ${BASH_REMATCH[1]} != "${package}" \
        || ${BASH_REMATCH[3]} != "${path}" ]]; then
        problems+=("${path} is not a file dpkg records for ${package}")
        return 1
    fi
    if ! version=$(php_security_package_version "${package}"); then
        problems+=("${package} is not installed")
        return 1
    fi
    if [[ ${version#*:} != "${branch}".* ]] || ! php_security_version_patched "${version}"; then
        problems+=("${package} ${version} is not patched")
        return 1
    fi
    if ! sums=$(php_security_md5sums_file "${package}") \
        || ! expected=$(awk -v f="${path#/}" '$2 == f { print $1 }' "${sums}") \
        || [[ ! ${expected} =~ ^[0-9a-f]{32}$ ]] || ! sum=$(md5sum < "${path}") || [[ ${sum%% *} != "${expected}" ]]; then
        problems+=("${path} does not match the checksum dpkg recorded for ${package} ${version}")
        return 1
    fi
    embedded=$(LC_ALL=C grep -aoE 'X-Powered-By: PHP/[0-9]+\.[0-9]+\.[0-9]+[0-9A-Za-z.+~-]*' -- "${path}" | sort -u) \
        || embedded=''
    embedded=${embedded//X-Powered-By: PHP\//}
    if [[ ! ${embedded} =~ ^[^[:space:]]+$ || ${embedded} != "${branch}".* ]] || ! php_security_version_patched "${embedded}"; then
        problems+=("${path} embeds PHP ${embedded:-(none found)}, not a patched ${branch} release")
        return 1
    fi
    verified[${path}]=ok
    checked+=("${package} ${version} (PHP ${embedded})")
}

# Verifies a PHP binary a running process uses, by its packaged path. Anything else is unknown.
php_security_verify_mapped() {
    local path=$1 module_re="^${php_security_module_dir}/libphp([0-9]+\.[0-9]+)\.so$"
    if [[ ${path} =~ ${module_re} ]]; then
        php_security_verify_binary "${path}" "libapache2-mod-php${BASH_REMATCH[1]}" "${BASH_REMATCH[1]}"
    elif [[ ${path} =~ ^${php_security_sbin_dir}/php-fpm([0-9]+\.[0-9]+)$ ]]; then
        php_security_verify_binary "${path}" "php${BASH_REMATCH[1]}-fpm" "${BASH_REMATCH[1]}"
    elif [[ ${path} =~ ^${php_security_bin_dir}/php([0-9]+\.[0-9]+)$ ]]; then
        php_security_verify_binary "${path}" "php${BASH_REMATCH[1]}-cli" "${BASH_REMATCH[1]}"
    else
        problems+=("a running process uses ${path}, which is not a distro PHP binary that can be verified")
        return 1
    fi
}

# A process that exited (or is a zombie, which maps nothing) between two reads.
php_security_process_gone() {
    local state
    [[ -e $1 ]] || return 0
    state=$(awk '/^State:/ { print $2; exit }' "$1/status" 2>/dev/null) || state=''
    [[ ${state} == Z || ${state} == X ]]
}

php_security_main() {
    local problems=() checked=() version file name branch pid comm exe maps mapped line keyword module rest content
    local cli_real path_php cli_branch='' handlers=0
    local -A verified=() branches=()
    local stale_re='(/libphp[^/[:space:]]*\.so|/php-fpm[0-9.]*|/php[0-9]+\.[0-9]+|/usr/lib/php/[0-9]+/[^/[:space:]]+\.so) \(deleted\)$'
    local module_re="^${php_security_module_dir}/libphp([0-9]+\.[0-9]+)\.so$"

    if [[ ! -f ${php_security_debian_file} ]] || ! command -v dpkg-query >/dev/null || ! command -v md5sum >/dev/null; then
        problems+=('this is not a supported Debian-style distro with dpkg, so its PHP binaries cannot be verified')
    else
        # Workers run /usr/bin/php, so it must be a packaged phpX.Y binary and php on PATH the same file.
        cli_real=$(readlink -f -- "${php_security_cli}" 2>/dev/null) || cli_real=''
        if [[ ! ${cli_real} =~ ^${php_security_bin_dir}/php([0-9]+\.[0-9]+)$ ]]; then
            problems+=("${php_security_cli} does not resolve to a distro ${php_security_bin_dir}/phpX.Y binary")
        else
            branch=${BASH_REMATCH[1]}
            if php_security_verify_binary "${cli_real}" "php${branch}-cli" "${branch}"; then
                if version=$("${cli_real}" -n -r 'echo PHP_VERSION;' 2>/dev/null) && [[ ${version} == "${branch}".* ]] \
                    && php_security_version_patched "${version}"; then
                    cli_branch=${branch}
                    checked+=("CLI ${version}")
                else
                    problems+=("${cli_real} did not report a patched ${branch} PHP_VERSION")
                fi
            fi
        fi
        path_php=$(command -v php 2>/dev/null) || path_php=''
        if [[ -n ${cli_real} ]] && [[ -z ${path_php} || $(readlink -f -- "${path_php}" 2>/dev/null) != "${cli_real}" ]]; then
            problems+=("php on PATH (${path_php:-missing}) is not ${php_security_cli}; remove the shadowing binary or fix PATH")
        fi

        # Apache's PHP is a separate package from the CLI: every LoadModule of PHP in mods-enabled must
        # be the packaged libphpX.Y.so of a matching phpX.Y.load, and every enabled PHP-FPM its package.
        if [[ -d ${php_security_apache_dir} ]]; then
            for file in "${php_security_apache_dir}"/mods-enabled/*.load; do
                [[ -e ${file} || -L ${file} ]] || continue
                name=${file##*/}
                if ! content=$(cat -- "${file}" 2>/dev/null); then
                    problems+=("could not read ${file}")
                    continue
                fi
                while IFS= read -r line; do
                    read -r keyword module mapped rest <<< "${line}" || true
                    [[ ${keyword,,} == loadmodule ]] || continue
                    mapped=${mapped#\"}
                    mapped=${mapped%\"}
                    [[ ${module,,} == php* || ${mapped} == *libphp* ]] || continue
                    handlers=$((handlers + 1))
                    if [[ ${mapped} =~ ${module_re} && ${name} == "php${BASH_REMATCH[1]}.load" && -z ${rest} ]]; then
                        php_security_verify_binary "${mapped}" "libapache2-mod-php${BASH_REMATCH[1]}" "${BASH_REMATCH[1]}" || true
                    else
                        problems+=("${name} loads PHP from ${mapped:-nothing}, not the packaged ${php_security_module_dir}/libphpX.Y.so of a matching phpX.Y.load")
                    fi
                done <<< "${content}"
            done
            for file in "${php_security_apache_dir}"/conf-enabled/php*-fpm.conf; do
                [[ -e ${file} || -L ${file} ]] || continue
                handlers=$((handlers + 1))
                name=${file##*/}
                if [[ ${name} =~ ^php([0-9]+\.[0-9]+)-fpm\.conf$ ]]; then
                    php_security_verify_binary "${php_security_sbin_dir}/php-fpm${BASH_REMATCH[1]}" \
                        "php${BASH_REMATCH[1]}-fpm" "${BASH_REMATCH[1]}" || true
                else
                    problems+=("Apache enables an unrecognized PHP runtime (${name})")
                fi
            done
            (( handlers > 0 )) || problems+=("no PHP runtime enabled in ${php_security_apache_dir} could be identified")
        fi
    fi

    # A package update does not reach processes that were already running. Each Apache, PHP-FPM and
    # PHP process may only map verified PHP binaries; one whose files root cannot read is refused.
    if [[ ${php_security_uid} -eq 0 ]]; then
        for pid in "${php_security_proc_dir}"/[0-9]*; do
            [[ ${pid##*/} =~ ^[0-9]+$ ]] || continue
            if ! comm=$(cat -- "${pid}/comm" 2>/dev/null); then
                php_security_process_gone "${pid}" || problems+=("could not read process ${pid##*/}")
                continue
            fi
            case ${comm} in apache2|httpd|php|php[0-9]*|php-fpm*) ;; *) continue ;; esac
            php_security_process_gone "${pid}" && continue
            if ! exe=$(readlink -- "${pid}/exe" 2>/dev/null) || ! maps=$(cat -- "${pid}/maps" 2>/dev/null) || [[ -z ${maps} ]]; then
                php_security_process_gone "${pid}" \
                    || problems+=("could not read which binaries running ${comm} process ${pid##*/} uses")
                continue
            fi
            if [[ ${exe} == *' (deleted)' ]] || grep -Eq "${stale_re}" <<< "${maps}"; then
                problems+=("running ${comm} process ${pid##*/} still uses a replaced PHP binary or extension")
                continue
            fi
            while IFS= read -r mapped; do
                [[ -n ${mapped} ]] && { php_security_verify_mapped "${mapped}" || true; }
            done < <({ [[ ${comm} == apache2 || ${comm} == httpd ]] || printf '%s\n' "${exe}"
                grep -oE '/[^[:space:]]*(libphp[^/[:space:]]*\.so|/php-fpm[0-9]+\.[0-9]+|/php[0-9]+\.[0-9]+)$' <<< "${maps}" || true
                } | sort -u)
        done
    else
        php_security_say WARN 'PHP security preflight: not root, so running Apache/PHP-FPM/PHP processes were not checked.' >&2
    fi

    # DwemerDistro Core's read-only check adds Debian-specific evidence on PHP 8.2 when installed.
    # Its absence never blocks a runtime that already passed the checks above, and it runs only when
    # it and its folders are root-owned and not writable by anyone else.
    if (( ${#problems[@]} == 0 )) && [[ ${cli_branch} == 8.2 ]] && [[ -e ${php_security_core_check} || -L ${php_security_core_check} ]]; then
        if ! php_security_trusted_root_file "${php_security_core_check}"; then
            php_security_say WARN "Skipped ${php_security_core_check}: not a plain root-owned executable in root-only folders." >&2
        elif ! "${php_security_core_check}" --check < /dev/null 2>&1 | php_security_relay INFO; then
            problems+=("${php_security_core_check} --check reported an unpatched runtime")
        fi
    fi

    if (( ${#problems[@]} > 0 )); then
        for line in "${problems[@]}"; do
            php_security_say FAIL "PHP security preflight failed: ${line}." >&2
        done
        php_security_say INFO "Patched floors (CVE-2026-109618/109619): ${php_security_floors[*]}; other branches and unverifiable binaries are refused." >&2
        php_security_say INFO "Update only the distro's PHP packages, keeping existing configuration: DwemerDistro launcher Update Distro, or in the distro run sudo apt-get update and then:" >&2
        (( ${#branches[@]} > 0 )) || branches[X.Y]=1
        for branch in "${!branches[@]}"; do
            php_security_say INFO "sudo apt-get install --only-upgrade -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \$(dpkg-query -W -f='\${binary:Package} ' 'php${branch}*' libapache2-mod-php${branch})" >&2
        done
        php_security_say INFO "Stop if apt proposes removing packages. Then restart Apache, PHP-FPM and any PHP workers so they load the patched PHP, and run this again." >&2
        php_security_say INFO 'Nothing was installed or changed; configuration, data and databases are untouched.' >&2
        return 1
    fi
    local IFS=,
    php_security_say OK "PHP security preflight passed (${checked[*]})."
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    php_security_main "$@"
fi
