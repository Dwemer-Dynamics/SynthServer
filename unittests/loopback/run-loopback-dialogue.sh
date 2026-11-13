#!/bin/bash
# Opt-in, off-game loopback dialogue check: the compiled SYNTH client driver against this server's real main.php and a
# disposable PostgreSQL cluster. Run as root on Linux or WSL from any clean server/client checkout pair:
#
#   sudo bash SERVER/unittests/loopback/run-loopback-dialogue.sh CLIENT
#
# SERVER is this checkout; CLIENT is a SYNTH client checkout. Prerequisites and exit statuses: README.md here.
#
# The host step only checks tools and enters fresh private network, mount, IPC, UTS and PID namespaces. Inside, before
# any application or database write, it proves the namespaces are private and only loopback exists, then makes every
# inherited mount read-only. Everything written afterwards lives on private bounded tmpfs that disappears with the
# namespace: the git-archive exports of both HEAD commits, the PostgreSQL cluster on 127.0.0.1:5432, runtime
# conf/data/log, a no-op TTS worker and the compiled driver. /var/www, /home/dwemer and /var/lib/postgresql are hidden,
# so no deployed tree or host database can be reached. The fake provider and every server stay on loopback.
set -euo pipefail
umask 022
readonly TIMEOUT_SECONDS=600
readonly WORK=/run/synth-loopback
readonly SELF_PATH=unittests/loopback/run-loopback-dialogue.sh
readonly DRIVER_PATH=tests/loopback_dialogue/loopback_dialogue_driver.cpp
readonly CXXFLAGS="-std=c++23 -O1 -Wall -Wextra -Wpedantic -Werror -Wno-missing-field-initializers -pthread"

usage() { echo "usage: sudo bash SERVER/$SELF_PATH CLIENT" >&2; exit 64; }
help() {
    cat <<EOF
usage: sudo bash SERVER/$SELF_PATH CLIENT

SERVER is this server checkout and CLIENT is a SYNTH client checkout; the HEAD commit of each is
tested and both must be free of tracked changes. In a fresh server checkout, install the locked
PHPUnit tree once as your normal user, then run as root on Linux or in WSL (wsl -u root):

  composer install --working-dir=SERVER/unittests
  sudo bash SERVER/$SELF_PATH CLIENT

Prerequisites and exit statuses: SERVER/unittests/loopback/README.md.
EOF
}
die() { echo "REFUSED: $*" >&2; exit 78; }
gitx() {
    GIT_OPTIONAL_LOCKS=0 GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null git -c safe.directory='*' \
        -c core.autocrlf=false -c core.eol=lf -c core.fsmonitor=false -C "$1" "${@:2}"
}
ro() { findmnt -no OPTIONS -T "$1" | tr ',' '\n' | grep -qx ro; }
tree_sha256() {
    (cd "$1" && find . -path ./unittests/vendor -prune -o -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -c1-64)
}

host_step() {
    [ "$#" = 1 ] || usage
    [ "$(id -u)" = 0 ] || die "run as root (sudo, or wsl -u root)"
    local tool
    for tool in unshare findmnt mount ip git tar g++ php runuser timeout sha256sum; do
        command -v "$tool" >/dev/null || die "missing prerequisite: $tool"
    done
    local self server client ns
    self=$(realpath -e "${BASH_SOURCE[0]}")
    server=${self%/"$SELF_PATH"}
    [ "$server" != "$self" ] || die "run the script from its place in a server checkout"
    client=$(realpath -e "$1") || usage
    local -a parent=()
    for ns in net mnt ipc uts pid; do parent+=("SYNTH_LOOPBACK_PARENT_${ns^^}=$(readlink "/proc/$$/ns/$ns")"); done
    exec unshare --net --mount --ipc --uts --pid --fork --mount-proc --kill-child --propagation private \
        env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C.UTF-8 "${parent[@]}" \
        bash "$self" --isolated "$server" "$client"
}

isolated_step() {
    local server=$1 client=$2 ns var
    # 1. Private namespaces, only loopback, no shared propagation: checked before anything is mounted or written.
    [ "$$" = 1 ] || die "not PID 1 of a private PID namespace"
    for ns in net mnt ipc uts pid; do
        var=SYNTH_LOOPBACK_PARENT_${ns^^}
        [[ ${!var:-} =~ ^$ns:\[[0-9]+\]$ ]] || die "missing parent $ns namespace; start without --isolated"
        [ "$(readlink "/proc/self/ns/$ns")" != "${!var}" ] || die "$ns namespace is not private"
        [ "$(readlink "/proc/1/ns/$ns")" = "$(readlink "/proc/self/ns/$ns")" ] || die "/proc is not this PID namespace's"
    done
    [ "$(ip -o link show | awk -F': ' '{print $2}' | tr '\n' ' ')" = "lo " ] || die "network namespace has more than lo"
    if findmnt -rno PROPAGATION | grep -q shared; then die "a shared mount remains"; fi
    ip link set lo up

    # 2. Every inherited mount becomes read-only in this namespace, so no later step can write a host file.
    local target
    while read -r target; do
        target=$(printf '%b' "$target")
        case $target in /proc|/proc/*|/dev|/dev/*) continue ;; esac
        ro "$target" || mount -o remount,bind,ro "$target" || die "cannot make $target read-only"
    done < <(findmnt -rno TARGET)
    ro "$server" && ro "$client" || die "checkouts are not read-only"

    # 3. Exact HEAD exports of both clean checkouts into private tmpfs (taken before /tmp is replaced).
    mount -t tmpfs -o size=2g,mode=0755 tmpfs /run
    mkdir -p "$WORK"/{src,client,bin,pg,home,hosts}
    local repo commit
    for repo in "$server" "$client"; do
        commit=$(gitx "$repo" rev-parse --verify 'HEAD^{commit}') || die "$repo is not a git checkout readable by this git"
        [ -z "$(gitx "$repo" status --porcelain --untracked-files=no)" ] || die "$repo has uncommitted tracked changes"
        echo "SOURCE $repo commit=$commit tree=$(gitx "$repo" rev-parse 'HEAD^{tree}')"
    done
    [ "$(gitx "$server" hash-object --no-filters "$SELF_PATH")" = "$(gitx "$server" rev-parse "HEAD:$SELF_PATH")" ] \
        || die "this runner differs from the server HEAD"
    gitx "$server" archive --format=tar HEAD | tar -x -C "$WORK/src" --no-same-owner --no-same-permissions
    gitx "$client" archive --format=tar HEAD src tests/loopback_dialogue | tar -x -C "$WORK/client" --no-same-owner --no-same-permissions \
        || die "client HEAD lacks src/ or tests/loopback_dialogue/"
    [ -f "$WORK/client/$DRIVER_PATH" ] || die "client HEAD lacks $DRIVER_PATH"

    # 4. The locked PHPUnit vendor tree, bound read-only and checked against the exported composer.lock.
    local vendor=$server/unittests/vendor
    [ -f "$vendor/autoload.php" ] && [ -f "$vendor/composer/installed.json" ] \
        || die "run 'composer install' in SERVER/unittests first"
    mkdir "$WORK/src/unittests/vendor"
    mount --bind "$vendor" "$WORK/src/unittests/vendor"; mount -o remount,bind,ro "$WORK/src/unittests/vendor"
    php -r '
        $packages=static function(array $list): array {$out=[];
            foreach ($list as $p) $out[$p["name"]]=[$p["version"],$p["dist"]["reference"]??null];ksort($out);return $out;};
        $lock=json_decode(file_get_contents($argv[1]),true);$installed=json_decode(file_get_contents($argv[2]),true);
        exit($packages([...$lock["packages"],...$lock["packages-dev"]])===$packages($installed["packages"]??$installed)?0:1);' \
        "$WORK/src/unittests/composer.lock" "$WORK/src/unittests/vendor/composer/installed.json" \
        || die "SERVER/unittests/vendor does not match composer.lock; run 'composer install' there"

    # 5. Hide host state, then the read-only server tree with private writable runtime directories.
    mount -t tmpfs -o size=2g,mode=1777 tmpfs /tmp
    mount -t tmpfs -o size=256m,mode=1777 tmpfs /var/tmp
    mount -t tmpfs -o size=256m,mode=0755 tmpfs /var/log
    mount -t tmpfs -o size=512m,mode=1777 tmpfs /dev/shm
    for target in /var/www /home/dwemer /var/lib/postgresql; do
        [ ! -d "$target" ] || mount -t tmpfs -o size=1m,mode=0755,ro tmpfs "$target"
    done
    printf '127.0.0.1 localhost\n' > "$WORK/hosts/hosts"; mount --bind "$WORK/hosts/hosts" /etc/hosts
    local root=$WORK/server dir
    mkdir "$root" "$WORK/src/data/tmp"
    printf '<?php exit(0);\n' > "$WORK/hosts/tts_worker.php"
    local source_before; source_before=$(tree_sha256 "$WORK/src")
    mount --rbind "$WORK/src" "$root"; mount -o remount,bind,ro "$root"
    for dir in conf data log ui/tmp; do
        mount -t tmpfs -o size=256m,mode=0755 tmpfs "$root/$dir"; cp -a "$WORK/src/$dir/." "$root/$dir/"; chown -R nobody "$root/$dir"
    done
    mount --bind "$WORK/hosts/tts_worker.php" "$root/service/tts_worker.php"
    ro "$root/main.php" && ro "$root/unittests/vendor/autoload.php" || die "server tree is not read-only"

    # 6. Build the driver from the client export as an unprivileged user.
    chown nobody "$WORK/bin" "$WORK/home"
    echo "TOOLS $(g++ --version | head -1); PHP $(php -r 'echo PHP_VERSION;'); $(unshare --version)"
    runuser -u nobody -- g++ $CXXFLAGS -I"$WORK/client/src" "$WORK/client/$DRIVER_PATH" -o "$WORK/bin/loopback_dialogue_driver" \
        || die "client driver build failed"
    echo "DRIVER sha256=$(sha256sum < "$WORK/bin/loopback_dialogue_driver" | cut -c1-64) flags=$CXXFLAGS"

    # 7. Disposable PostgreSQL with pgvector on namespace loopback only.
    local version="" candidate pgbin
    for candidate in $(ls /usr/lib/postgresql 2>/dev/null | sort -rn); do
        if [ -x "/usr/lib/postgresql/$candidate/bin/initdb" ] && [ -f "/usr/share/postgresql/$candidate/extension/vector.control" ]; then
            version=$candidate; break
        fi
    done
    [ -n "$version" ] || die "no PostgreSQL server with pgvector under /usr/lib/postgresql"
    pgbin=/usr/lib/postgresql/$version/bin
    chown postgres "$WORK/pg"; chmod 0700 "$WORK/pg"
    trap "runuser -u postgres -- '$pgbin/pg_ctl' -D '$WORK/pg/data' -m fast stop >/dev/null 2>&1 || true" EXIT
    runuser -u postgres -- "$pgbin/initdb" -D "$WORK/pg/data" --auth-local=peer --auth-host=scram-sha-256 \
        --encoding=UTF8 --no-sync >/dev/null
    runuser -u postgres -- "$pgbin/pg_ctl" -D "$WORK/pg/data" -l "$WORK/pg/server.log" -w -t 30 \
        -o "-c listen_addresses=127.0.0.1 -p 5432 -k $WORK/pg -c fsync=off" start >/dev/null || die "PostgreSQL did not start"
    runuser -u postgres -- "$pgbin/psql" -X -q -h "$WORK/pg" -d postgres -v ON_ERROR_STOP=1 \
        -c "CREATE ROLE dwemer LOGIN SUPERUSER PASSWORD 'dwemer'" >/dev/null
    echo "POSTGRESQL $version $(runuser -u postgres -- "$pgbin/psql" -X -tA -h "$WORK/pg" -d postgres -c 'SHOW server_version')"

    # 8. The PHPUnit check, unprivileged, with a minimal environment and a hard time limit.
    local status=0
    (cd "$root/unittests" && runuser -u nobody -- env -i PATH=/usr/bin:/bin LANG=C.UTF-8 HOME="$WORK/home" \
        SYNTH_LOOPBACK_DRIVER="$WORK/bin/loopback_dialogue_driver" \
        timeout -k 10 "$TIMEOUT_SECONDS" php vendor/phpunit/phpunit/phpunit -c phpunit.xml --colors=never \
        --fail-on-skipped --fail-on-empty-test-suite loopback/LoopbackDialogueTest.php) || status=$?
    [ "$status" != 124 ] || echo "TIMEOUT after $TIMEOUT_SECONDS s" >&2
    if [ "$(tree_sha256 "$WORK/src")" != "$source_before" ]; then echo "SOURCE changed during the run" >&2; [ "$status" != 0 ] || status=79; fi
    echo "EXIT=$status"
    exit "$status"
}

main() {
    case ${1:-} in
        -h|--help) help; exit 0 ;;
        --isolated) shift; [ "$#" = 2 ] || usage; isolated_step "$@" ;;
        *) host_step "$@" ;;
    esac
}
main "$@"
