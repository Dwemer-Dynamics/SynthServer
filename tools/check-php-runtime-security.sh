#!/usr/bin/env bash
# Read-only PHP runtime security preflight for deploy/install/update entry points
# (PHP2Shell, CVE-2026-109618 and CVE-2026-109619).
# Passes only on a Debian-style distro where /usr/bin/php (and php on PATH) and every PHP runtime
# Apache has enabled (mod_php or PHP-FPM) are the distro's packaged phpX.Y binaries, unmodified
# against dpkg's checksums, from an installed package and with an embedded PHP version that are
# both at or above the patched floor of a supported branch, and no running Apache, PHP-FPM or PHP
# process still uses a PHP binary or extension that a package update replaced or that could not be
# verified. Unknown or custom paths are refused, never assumed safe. The one exception is a running
# process (of any name) whose executable is under /opt/chevo-runtime: it passes only when that is the
# protected chevo PHP 8.4.27 CLI or FPM, it maps that executable, and the executable and every
# extension it maps are byte-identical to the retained root-owned build outputs, each of which has
# the SHA-256 pinned when the build was reviewed. The pinned official archive must be present, and
# the build's main/php_version.h and the five ext/sodium sources the separate sodium build compiled
# must equal the archive's; other sources are not compared. Every other executable mapping must be
# a .so of an installed dpkg package matching dpkg's recorded checksum, all checked by device and
# inode. The CLI and Apache checks stay distro-only.
# It installs, restarts and changes nothing, never runs the custom PHP, and prints only versions
# and paths (never phpinfo, command lines, configuration or credentials), one
# [OK]/[INFO]/[WARN]/[FAIL] line each.
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
# The chevo source build: installed prefix, retained build outputs and the official release archive.
php_security_chevo_version=8.4.27
php_security_chevo_runtime=/opt/chevo-runtime
php_security_chevo_build=/opt/chevo-build
php_security_chevo_archive_sha256=dcf93923b0e38e0d5252b25b796520b5a47761a08b3488b6d0b5d1115764d3f0
php_security_chevo_sodium=sodium-chevo-20261105
php_security_chevo_ext=lib/php/extensions/no-debug-non-zts-20240924
# SHA-256 of the reviewed build's retained original outputs (CLI, FPM, opcache and sodium).
php_security_chevo_cli_sha256=9d1ac91a0aedea7d9dc3ea6cb2832ae267ca451b0bfb23723d96dfcf1ba06b09
php_security_chevo_fpm_sha256=0047e213eadcd5d84afab9ed43bbc787915de1b9278affae50fc3ccb524c194c
php_security_chevo_opcache_sha256=2738685cf1cc6ae356de5996dd820a234f8067a74415058d0e63ebf8e85ae00d
php_security_chevo_sodium_sha256=007322d4372590847449000e19505ccd3b6881c37b127569f34d781c498322d8

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
    [[ -x $1 ]] && php_security_trusted_root_path "$1"
}

# The same for a plain file that need not be executable (an archive, a source or a shared library).
php_security_trusted_root_path() {
    local path=$1 meta
    [[ ${path} == /* && -f ${path} && ! -L ${path} ]] || return 1
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

# Prints major:minor:inode of the file $1 resolves to, in the decimal form of /proc/PID/maps.
php_security_file_id() {
    stat -L -c '%Hd:%Ld:%i' -- "$1" 2>/dev/null
}

# Ties the retained chevo build tree to the pinned official release archive: the archive must be
# root-protected with the pinned SHA-256, and the build's main/php_version.h and the five ext/sodium
# sources the separate sodium build compiled must equal the archive's. No other source is compared.
# Cached in the caller's 'chevo_sources'.
php_security_chevo_sources() {
    local v=${php_security_chevo_version} build=${php_security_chevo_build} archive sum member local_file
    local -A expected=() actual=()
    [[ -n ${chevo_sources} ]] && { [[ ${chevo_sources} == ok ]]; return; }
    chevo_sources=bad
    archive=${build}/php-${v}.tar.xz
    if ! php_security_trusted_root_path "${archive}" || ! sum=$(sha256sum < "${archive}") \
        || [[ ${sum%% *} != "${php_security_chevo_archive_sha256}" ]]; then
        problems+=("${archive} is missing, not root-protected or not the pinned official PHP ${v} release")
        return 1
    fi
    expected[php-${v}/main/php_version.h]=${build}/php-${v}/main/php_version.h
    for member in libsodium.c libsodium_arginfo.h php_libsodium.h sodium_pwhash.c sodium_pwhash_arginfo.h; do
        expected[php-${v}/ext/sodium/${member}]=${build}/${php_security_chevo_sodium}/${member}
    done
    # One read-only pass: tar hashes each member through stdout and writes nothing to disk.
    while read -r member sum _; do
        [[ -n ${member} ]] && actual[${member}]=${sum}
    done < <(LC_ALL=C tar -xJf "${archive}" --to-command='printf "%s " "$TAR_FILENAME"; sha256sum' \
        -- "${!expected[@]}" 2>/dev/null || true)
    for member in "${!expected[@]}"; do
        local_file=${expected[${member}]}
        if ! php_security_trusted_root_path "${local_file}" || ! sum=$(sha256sum < "${local_file}") \
            || [[ ${sum%% *} != "${actual[${member}]:-none}" ]]; then
            problems+=("${local_file} is missing, not root-protected or differs from the official PHP ${v} source")
            return 1
        fi
    done
    chevo_sources=ok
}

# Verifies an installed chevo file (relative to the prefix) against its retained original build
# output: the output root-protected with the SHA-256 pinned at review, the installed file a
# root-protected byte-identical copy, unchanged while compared. The CLI and FPM executables must
# also embed exactly the pinned patched version. Records the verified identity in the caller's
# 'chevo_ids'; the result is cached in 'verified'.
php_security_verify_chevo() {
    local rel=$1 v=${php_security_chevo_version} prefix origin pin sum installed before after embedded
    local key="chevo:$1"
    [[ -n ${verified[${key}]:-} ]] && { [[ ${verified[${key}]} == ok ]]; return; }
    verified[${key}]=bad
    prefix=${php_security_chevo_runtime}/php-${v}
    case ${rel} in
        bin/php) origin=php-${v}/sapi/cli/php pin=${php_security_chevo_cli_sha256} ;;
        sbin/php-fpm) origin=php-${v}/sapi/fpm/php-fpm pin=${php_security_chevo_fpm_sha256} ;;
        "${php_security_chevo_ext}/opcache.so") origin=php-${v}/modules/opcache.so pin=${php_security_chevo_opcache_sha256} ;;
        "${php_security_chevo_ext}/sodium.so") origin=${php_security_chevo_sodium}/modules/sodium.so pin=${php_security_chevo_sodium_sha256} ;;
        *) problems+=("${prefix}/${rel} is not a file of the chevo PHP ${v} build that can be verified"); return 1 ;;
    esac
    installed=${prefix}/${rel}
    origin=${php_security_chevo_build}/${origin}
    php_security_chevo_sources || return 1
    if ! php_security_trusted_root_file "${origin}" || ! sum=$(sha256sum < "${origin}") || [[ ${sum%% *} != "${pin}" ]]; then
        problems+=("the retained build output ${origin} is missing, not root-protected or not the reviewed build")
        return 1
    fi
    if ! php_security_trusted_root_file "${installed}" || ! before=$(php_security_file_id "${installed}") \
        || ! cmp -s -- "${origin}" "${installed}" || ! after=$(php_security_file_id "${installed}") \
        || [[ ${before} != "${after}" ]]; then
        problems+=("${installed} is not a root-protected copy of the retained build output")
        return 1
    fi
    if [[ ${rel} == bin/php || ${rel} == sbin/php-fpm ]]; then
        embedded=$(LC_ALL=C grep -aoE 'X-Powered-By: PHP/[0-9]+\.[0-9]+\.[0-9]+[0-9A-Za-z.+~-]*' -- "${installed}" | sort -u) \
            || embedded=''
        embedded=${embedded//X-Powered-By: PHP\//}
        if [[ ${embedded} != "${v}" ]] || ! php_security_version_patched "${embedded}"; then
            problems+=("${installed} embeds PHP ${embedded:-(none found)}, not the patched ${v} release")
            return 1
        fi
    fi
    chevo_ids[${rel}]=${before}
    verified[${key}]=ok
}

# Looks up which packages dpkg records for the system libraries in 'libs', in one dpkg-query call.
# Merged-/usr systems record /lib/... for files the kernel reports as /usr/lib/..., so both
# spellings are asked; the caller's 'lib_owner' gets "package[:arch] recorded-path" per library.
php_security_lib_owners() {
    local path top line
    local -A asked=()
    for path in "$@"; do
        [[ ${path} =~ ^/[A-Za-z0-9._+/-]+$ ]] || continue
        asked[${path}]=${path}
        top=${path#/usr/}
        top=/${top%%/*}
        if [[ ${path} == /usr/* && -L ${top} && $(readlink -f -- "${top}" 2>/dev/null) == "/usr${top}" ]]; then
            asked[${path#/usr}]=${path}
        fi
    done
    (( ${#asked[@]} > 0 )) || return 0
    while IFS= read -r line; do
        [[ ${line} =~ ^([a-z0-9][a-z0-9.+-]*)(:[a-z0-9]+)?:\ (/.*)$ && -n ${asked[${BASH_REMATCH[3]}]:-} ]] || continue
        lib_owner[${asked[${BASH_REMATCH[3]}]}]="${BASH_REMATCH[1]}${BASH_REMATCH[2]} ${BASH_REMATCH[3]}"
    done < <(dpkg-query -S -- "${!asked[@]}" 2>/dev/null || true)
}

# Succeeds when package $1 is installed and its dpkg status and md5sums are root-protected. The
# md5sums path is cached in 'verified' as pkg:$1.
php_security_system_package() {
    local key="pkg:$1" sums
    if [[ -z ${verified[${key}]:-} ]]; then
        verified[${key}]=bad
        if php_security_package_version "$1" >/dev/null && php_security_trusted_root_path "${php_security_dpkg_info%/*}/status" \
            && sums=$(php_security_md5sums_file "$1") && php_security_trusted_root_path "${sums}"; then
            verified[${key}]=${sums}
        fi
    fi
    [[ ${verified[${key}]} != bad ]]
}

# Succeeds when a mapping of $1 with identity $2 is a protected, non-PHP .so or versioned .so
# directly in /usr/lib or /usr/lib64 or under /usr/lib/<multiarch triplet>, recorded by an installed
# dpkg package whose checksum it matches, unchanged while hashed. Cached in 'verified'.
php_security_verify_system_lib() {
    local path=$1 id=$2 rest key="lib:$1" package recorded sums expected sum before after
    if [[ -z ${verified[${key}]:-} ]]; then
        verified[${key}]=bad
        rest=${path#/usr/lib/}
        package=${lib_owner[${path}]:-}
        recorded=${package#* }
        package=${package%% *}
        if [[ ${path} =~ ^/usr/lib(64)?/[A-Za-z0-9._+-]+$ \
            || ( ${rest%%/*} =~ ^[a-z0-9_]+-linux-gnu[a-z0-9_]*$ && ${path} =~ ^/usr/lib/[A-Za-z0-9._+/-]+$ ) ]] \
            && [[ ${path##*/} =~ ^[A-Za-z0-9_+-][A-Za-z0-9._+-]*\.so(\.[0-9]+)*$ ]] \
            && [[ ${path} != *'/.'* && ${path,,} != *php* && -n ${package} && ${package} != *php* ]] \
            && php_security_trusted_root_path "${path}" && [[ $(readlink -f -- "${path}" 2>/dev/null) == "${path}" ]] \
            && php_security_system_package "${package}" && sums=${verified[pkg:${package}]} \
            && expected=$(awk -v f="${recorded#/}" '$2 == f { print $1 }' "${sums}") && [[ ${expected} =~ ^[0-9a-f]{32}$ ]] \
            && before=$(php_security_file_id "${path}") && sum=$(md5sum < "${path}") && [[ ${sum%% *} == "${expected}" ]] \
            && after=$(php_security_file_id "${path}") && [[ ${before} == "${after}" ]]; then
            verified[${key}]=${before}
        fi
    fi
    [[ ${verified[${key}]} != bad && ${verified[${key}]} == "${id}" ]]
}

# Checks one running process of the chevo build, from the maps text $4 read once. The maps must
# include an executable mapping of the verified executable, and every executable mapping must be the
# verified build (by device and inode, not path) or a verified system library; executable anonymous
# memory, deleted files and other custom files are refused.
php_security_check_chevo_process() {
    local pid=$1 comm=$2 exe=$3 maps=$4 prefix rel exe_rel id addr perms offset dev inode path exe_mapped=0
    local who="running ${comm} process ${pid##*/}" pending=()
    local -A exec_paths=()
    prefix=${php_security_chevo_runtime}/php-${php_security_chevo_version}
    chevo_seen=1
    rel=${exe#"${prefix}/"}
    if [[ ${exe} != "${prefix}/"* || ( ${rel} != bin/php && ${rel} != sbin/php-fpm ) ]]; then
        problems+=("${who} runs ${exe}, which is not the verified chevo PHP ${php_security_chevo_version} build")
        return 1
    fi
    exe_rel=${rel}
    php_security_verify_chevo "${rel}" || return 1
    if ! id=$(php_security_file_id "${pid}/exe"); then
        php_security_process_gone "${pid}" || problems+=("could not identify the executable of ${who}")
        return 1
    fi
    if [[ ${id} != "${chevo_ids[${rel}]}" ]]; then
        problems+=("${who} executes a different file than the verified ${exe}")
        return 1
    fi
    while read -r addr perms offset dev inode path; do
        [[ ${perms} == *x* && ${path} == /* && ${path} != "${php_security_chevo_runtime}/"* ]] && exec_paths[${path}]=1
    done <<< "${maps}"
    for path in "${!exec_paths[@]}"; do
        [[ ${path} == *' (deleted)' || -n ${verified[lib:${path}]:-} ]] || pending+=("${path}")
    done
    php_security_lib_owners "${pending[@]}"
    while read -r addr perms offset dev inode path; do
        [[ -n ${addr} ]] || continue
        if [[ ${path} == *' (deleted)' ]]; then
            [[ ${path} == '/dev/zero (deleted)' && ${perms} != *x* ]] && continue
            problems+=("${who} maps a deleted file (${path% (deleted)})")
            return 1
        fi
        if [[ ${path} != /* ]]; then
            [[ ${perms} != *x* || ${path} == '[vdso]' || ${path} == '[vsyscall]' ]] && continue
            problems+=("${who} has executable memory that cannot be verified (${path:-anonymous})")
            return 1
        fi
        [[ ${inode} =~ ^[0-9]+$ && ${dev} =~ ^([0-9a-f]+):([0-9a-f]+)$ ]] || {
            problems+=("${who} has an unreadable mapping"); return 1; }
        id=$((16#${BASH_REMATCH[1]})):$((16#${BASH_REMATCH[2]})):${inode}
        if [[ ${path} == "${php_security_chevo_runtime}/"* ]]; then
            rel=${path#"${prefix}/"}
            if [[ ${path} != "${prefix}/"* ]] || ! php_security_verify_chevo "${rel}"; then
                problems+=("${who} maps ${path}, which is not a verified file of the chevo build")
                return 1
            fi
            if [[ ${id} != "${chevo_ids[${rel}]}" ]]; then
                problems+=("${who} maps a different file than the verified ${path}")
                return 1
            fi
            [[ ${rel} == "${exe_rel}" && ${perms} == *x* ]] && exe_mapped=1
        elif [[ -n ${exec_paths[${path}]:-} ]]; then
            if ! php_security_verify_system_lib "${path}" "${id}"; then
                problems+=("${who} maps ${path}, which is not a protected dpkg-owned system library with that identity and the checksum its installed package records")
                return 1
            fi
        fi
    done <<< "${maps}"
    if (( exe_mapped == 0 )); then
        php_security_process_gone "${pid}" || problems+=("${who} has no executable mapping of its verified executable ${exe}")
        return 1
    fi
    chevo_processes=$((chevo_processes + 1))
}

php_security_main() {
    local problems=() checked=() version file name branch pid comm exe maps mapped line keyword module rest content
    local cli_real path_php cli_branch='' handlers=0 chevo_sources='' chevo_seen=0 chevo_processes=0
    local -A verified=() branches=() chevo_ids=() lib_owner=()
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
    # A process whose executable is under the chevo prefix, whatever its name, is checked mapping by
    # mapping instead.
    if [[ ${php_security_uid} -eq 0 ]]; then
        for pid in "${php_security_proc_dir}"/[0-9]*; do
            [[ ${pid##*/} =~ ^[0-9]+$ ]] || continue
            if ! comm=$(cat -- "${pid}/comm" 2>/dev/null); then
                php_security_process_gone "${pid}" || problems+=("could not read process ${pid##*/}")
                continue
            fi
            # Any other name is only skipped once its executable is known not to be the chevo build's.
            case ${comm} in
                apache2|httpd|php|php[0-9]*|php-fpm*) ;;
                *) exe=$(readlink -- "${pid}/exe" 2>/dev/null) && [[ ${exe} == "${php_security_chevo_runtime}/"* ]] || continue ;;
            esac
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
            if [[ ${exe} == "${php_security_chevo_runtime}/"* ]]; then
                php_security_check_chevo_process "${pid}" "${comm}" "${exe}" "${maps}" || true
                continue
            fi
            if [[ ${maps} == *" ${php_security_chevo_runtime}/"* ]]; then
                chevo_seen=1
                problems+=("running ${comm} process ${pid##*/} maps files of the chevo PHP build into ${exe}")
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
        (( chevo_seen == 0 )) || php_security_say INFO "apt does not update the chevo PHP ${php_security_chevo_version} build; its running processes pass only while they use exactly its retained root-owned build outputs and system libraries." >&2
        php_security_say INFO 'Nothing was installed or changed; configuration, data and databases are untouched.' >&2
        return 1
    fi
    (( chevo_processes == 0 )) \
        || checked+=("chevo PHP ${php_security_chevo_version} in ${chevo_processes} running processes, matching its retained build")
    local IFS=,
    php_security_say OK "PHP security preflight passed (${checked[*]})."
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    php_security_main "$@"
fi
