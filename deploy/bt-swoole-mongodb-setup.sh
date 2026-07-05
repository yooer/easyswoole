#!/usr/bin/env bash
#
# 一键部署脚本：Ubuntu 24.04 + 宝塔(aaPanel) + PHP 环境下
# 安装/编译 Swoole、MongoDB 扩展，并做 ulimit / 内核参数优化。
#
# 使用场景：服务器已经装好宝塔面板和至少一个 PHP 版本，GitHub 访问受限，
# 走 pecl.php.net 源码包 phpize 编译的方式安装扩展。
#
# 用法：
#   sudo bash bt-swoole-mongodb-setup.sh
#
# 可选环境变量（不设置则自动探测/使用默认值）：
#   PHP_VERSION      宝塔 PHP 版本号，如 83（对应 /www/server/php/83）。
#                     不设置时，如果只探测到一个已安装版本会自动使用；
#                     探测到多个版本会交互式列出让你选择。
#   BT_PHP_BASE       宝塔 PHP 安装根目录，默认 /www/server/php
#   SWOOLE_VERSION    强制指定 Swoole 版本号，覆盖脚本内置的版本映射表
#   MONGODB_VERSION   强制指定 mongodb 扩展版本号，覆盖脚本内置默认值
#   BUILD_DIR         源码下载/编译目录，默认 /root/ext-build
#   SKIP_KERNEL_TUNE  设为 1 则跳过 ulimit / sysctl 内核参数调整
#   FORCE_REBUILD     设为 1 则即使扩展已装也强制重新下载编译安装
#
# 脚本会做的事：
#   1. 探测/选择宝塔 PHP 版本，定位 phpize / php-config / php.ini
#   2. 安装编译依赖（build-essential、libssl-dev 等）
#   3. 按 PHP 版本自动匹配 Swoole / MongoDB 扩展版本并源码编译安装
#   4. 写入 php.ini 的 extension 配置，重启 php-fpm
#   5. 写入 /etc/security/limits.conf 和 /etc/sysctl.conf 的优化参数（幂等，重复执行不会重复追加）
#   6. 最后做一次自检，输出扩展加载情况和关键内核参数当前值
#
# 注意：
#   - limits.conf 的 nofile/core 修改需要重新登录或重启系统才会对新会话生效，
#     脚本里的自检看到的是当前 shell 的旧值，不代表没生效。
#   - php-fpm 重启脚本路径按宝塔的常见约定尝试，如果你的面板路径不同，
#     脚本会提示你手动去宝塔面板重启对应 PHP。

set -euo pipefail

# ------------------------- 基础工具函数 -------------------------

C_INFO='\033[36m'
C_OK='\033[32m'
C_WARN='\033[33m'
C_ERR='\033[31m'
C_RESET='\033[0m'

log_info() { echo -e "${C_INFO}[INFO]${C_RESET} $*"; }
log_ok()   { echo -e "${C_OK}[OK]${C_RESET} $*"; }
log_warn() { echo -e "${C_WARN}[WARN]${C_RESET} $*"; }
log_err()  { echo -e "${C_ERR}[ERROR]${C_RESET} $*" >&2; }

step() {
    echo
    echo -e "${C_INFO}==== $* ====${C_RESET}"
}

require_root() {
    if [[ ${EUID} -ne 0 ]]; then
        log_err "请用 root 权限运行本脚本（sudo bash $0）"
        exit 1
    fi
}

# ------------------------- 参数 / 默认值 -------------------------

BT_PHP_BASE="${BT_PHP_BASE:-/www/server/php}"
BUILD_DIR="${BUILD_DIR:-/root/ext-build}"
SKIP_KERNEL_TUNE="${SKIP_KERNEL_TUNE:-0}"
FORCE_REBUILD="${FORCE_REBUILD:-0}"

# Swoole / MongoDB 版本映射表：按 PHP 主.次版本号匹配。
# 下面数据是 2026-07-05 上网核实 pecl.php.net / GitHub release 后的结果，不保证以后还是最新：
# 如果你的 PHP 版本不在表里，或者想用更新的扩展版本，
# 用 SWOOLE_VERSION / MONGODB_VERSION 环境变量强制指定即可，
# 建议先去 https://pecl.php.net/package/swoole 和
# https://pecl.php.net/package/mongodb 确认对应 PHP 版本的最新可用版本号。
#
# 重要（本项目强相关，不是泛泛的兼容性提示）：
# Swoole 6.0.0 起彻底移除了 Swoole\Coroutine\MySQL / Swoole\Coroutine\Redis /
# Swoole\Coroutine\PostgreSQL 协程客户端（没有编译开关能找回来），
# 而本项目依赖的 easyswoole/mysqli（ORM 底层）和 easyswoole/redis-pool
# 都是基于 Swoole\Coroutine\MySQL / Swoole\Coroutine\Redis 实现的。
# 也就是说装 Swoole 6.x 会直接把本项目的数据库 ORM 和 Redis 连接池弄坏。
# 所以本项目必须锁定在 Swoole 5.x 分支（5.1.8 是 5.1.x 目前最新的补丁版），
# 直到 Swoole 6.0 才第一次支持 PHP 8.4，而 5.x 分支不支持 PHP 8.4 ——
# 这意味着本项目暂时不能升级到 PHP 8.4，只能停留在 PHP 8.0-8.3。
# 如果非要上 8.4，必须先把 easyswoole/mysqli、easyswoole/redis-pool 换成
# 基于 PDO/mysqli + phpredis 配合 Runtime::enableCoroutine 的方案，这是一次单独的、
# 有破坏性的迁移，不是这个部署脚本能顺手做的事，所以脚本默认直接拒绝给 PHP 8.4 装 Swoole。
# 本项目最低支持 PHP 8.0，不再考虑 PHP 7.x。
declare -A SWOOLE_VERSION_MAP=(
    [8.0]="5.1.8"
    [8.1]="5.1.8"
    [8.2]="5.1.8"
    [8.3]="5.1.8"
    # 8.4 故意不在表里：见上面的说明，需要显式 SWOOLE_VERSION 覆盖并自行承担风险
)
declare -A MONGODB_VERSION_MAP=(
    # PHP 8.0：mongodb 扩展 2.x 系列已经要求 PHP >= 8.1，只能用 1.x 系列的最新版
    [8.0]="1.21.5"
    # PHP 8.1+：用当前维护中的 2.x 系列最新版（2.3.3 支持 PHP 8.1 - 8.99.99）
    [8.1]="2.3.3"
    [8.2]="2.3.3"
    [8.3]="2.3.3"
    [8.4]="2.3.3"
)

# ------------------------- 1. 探测宝塔 PHP 版本 -------------------------

detect_php_versions() {
    local versions=()
    if [[ -d "${BT_PHP_BASE}" ]]; then
        for dir in "${BT_PHP_BASE}"/*/; do
            local v
            v="$(basename "${dir}")"
            [[ "${v}" =~ ^[0-9]+$ ]] || continue
            [[ -x "${dir}/bin/php-config" ]] && versions+=("${v}")
        done
    fi
    echo "${versions[@]}"
}

select_php_version() {
    local versions
    read -r -a versions <<< "$(detect_php_versions)"

    if [[ -n "${PHP_VERSION:-}" ]]; then
        log_info "使用环境变量指定的 PHP_VERSION=${PHP_VERSION}"
        return
    fi

    if [[ ${#versions[@]} -eq 0 ]]; then
        log_err "在 ${BT_PHP_BASE} 下没探测到任何宝塔 PHP（找不到 bin/php-config），请确认宝塔已安装 PHP，或用 BT_PHP_BASE 指定路径"
        exit 1
    fi

    if [[ ${#versions[@]} -eq 1 ]]; then
        PHP_VERSION="${versions[0]}"
        log_info "探测到唯一 PHP 版本：${PHP_VERSION}"
        return
    fi

    echo "探测到多个宝塔 PHP 版本，请选择要处理的一个："
    select v in "${versions[@]}"; do
        if [[ -n "${v:-}" ]]; then
            PHP_VERSION="${v}"
            break
        fi
        log_warn "无效选择，重新输入"
    done
}

resolve_php_paths() {
    PHP_DIR="${BT_PHP_BASE}/${PHP_VERSION}"
    PHP_BIN="${PHP_DIR}/bin/php"
    PHP_CONFIG="${PHP_DIR}/bin/php-config"
    PHPIZE="${PHP_DIR}/bin/phpize"

    for f in "${PHP_BIN}" "${PHP_CONFIG}" "${PHPIZE}"; do
        [[ -x "${f}" ]] || { log_err "找不到可执行文件：${f}，请确认 PHP_VERSION/BT_PHP_BASE 是否正确"; exit 1; }
    done

    PHP_INI="$("${PHP_BIN}" --ini | awk -F': ' '/Loaded Configuration File/{print $2}')"
    if [[ -z "${PHP_INI}" || "${PHP_INI}" == "(none)" ]]; then
        PHP_INI="${PHP_DIR}/etc/php.ini"
        log_warn "没能从 php --ini 拿到已加载的 php.ini，回退使用默认路径：${PHP_INI}"
    fi

    EXT_DIR="$("${PHP_BIN}" -r 'echo ini_get("extension_dir");')"

    # PHP_VERSION 形如 83，转成 8.3 这种带点号的短版本号，用于版本映射表查找
    PHP_DOTTED="${PHP_VERSION:0:1}.${PHP_VERSION:1}"

    log_info "PHP 目录: ${PHP_DIR}"
    log_info "php.ini: ${PHP_INI}"
    log_info "extension_dir: ${EXT_DIR}"
}

# ------------------------- 2. 安装编译依赖 -------------------------

install_build_deps() {
    step "安装编译依赖"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y \
        build-essential autoconf automake libtool pkg-config re2c \
        libssl-dev zlib1g-dev libcurl4-openssl-dev libpcre3-dev \
        libsqlite3-dev libsasl2-dev git wget
    log_ok "编译依赖安装完成"
}

# ------------------------- 3. 编译安装扩展（通用函数） -------------------------

extension_loaded() {
    local ext_name="$1"
    "${PHP_BIN}" -m 2>/dev/null | grep -qi "^${ext_name}$"
}

build_and_install_ext() {
    local ext_name="$1"    # swoole / mongodb
    local ext_version="$2"
    local configure_extra="$3"

    if [[ "${FORCE_REBUILD}" != "1" ]] && extension_loaded "${ext_name}"; then
        log_ok "${ext_name} 扩展已加载，跳过编译（如需强制重装，设置 FORCE_REBUILD=1）"
        return
    fi

    step "编译安装 ${ext_name}-${ext_version}"

    mkdir -p "${BUILD_DIR}"
    local tarball="${ext_name}-${ext_version}.tgz"
    local src_dir="${BUILD_DIR}/${ext_name}-${ext_version}"

    if [[ ! -f "${BUILD_DIR}/${tarball}" ]]; then
        log_info "下载 https://pecl.php.net/get/${tarball}"
        wget -O "${BUILD_DIR}/${tarball}" "https://pecl.php.net/get/${tarball}"
    fi

    rm -rf "${src_dir}"
    tar -zxf "${BUILD_DIR}/${tarball}" -C "${BUILD_DIR}"

    (
        cd "${src_dir}"
        "${PHPIZE}"
        # shellcheck disable=SC2086
        ./configure --with-php-config="${PHP_CONFIG}" ${configure_extra}
        make -j"$(nproc)"
        make install
    )

    log_ok "${ext_name}-${ext_version} 编译安装完成"
}

configure_php_ini() {
    local ext_name="$1"

    if grep -qE "^\s*extension\s*=\s*${ext_name}\.so" "${PHP_INI}" 2>/dev/null; then
        log_info "php.ini 已包含 ${ext_name}.so 配置，跳过"
        return
    fi

    cp -n "${PHP_INI}" "${PHP_INI}.bak.$(date +%Y%m%d%H%M%S)" || true
    echo "extension=${ext_name}.so" >> "${PHP_INI}"
    log_ok "已写入 ${PHP_INI}：extension=${ext_name}.so"
}

restart_php_fpm() {
    step "重启 php-fpm(${PHP_VERSION})"

    local candidates=(
        "/etc/init.d/php-fpm-${PHP_VERSION}"
        "${PHP_DIR}/sbin/php-fpm"
    )

    for svc in "${candidates[@]}"; do
        if [[ -x "${svc}" ]]; then
            if [[ "${svc}" == *"init.d"* ]]; then
                "${svc}" restart && { log_ok "已通过 ${svc} restart 重启 php-fpm"; return; }
            else
                "${svc}" -t >/dev/null 2>&1 || true
                pkill -f "php-fpm: master process.*${PHP_VERSION}" 2>/dev/null || true
                "${svc}" && { log_ok "已通过 ${svc} 启动 php-fpm"; return; }
            fi
        fi
    done

    log_warn "没能自动定位 php-fpm-${PHP_VERSION} 的重启方式，请手动去宝塔面板重启对应 PHP 版本使扩展生效"
}

# ------------------------- 4. 内核参数 / ulimit 优化 -------------------------

MARKER_BEGIN="# >>> bt-swoole-mongodb-setup.sh managed block >>>"
MARKER_END="# <<< bt-swoole-mongodb-setup.sh managed block <<<"

append_block_if_absent() {
    local file="$1"
    local content="$2"

    if grep -qF "${MARKER_BEGIN}" "${file}" 2>/dev/null; then
        log_info "${file} 已包含本脚本写入的配置块，跳过（如需更新请手动编辑或先删除对应块）"
        return
    fi

    cp -n "${file}" "${file}.bak.$(date +%Y%m%d%H%M%S)" || true
    {
        echo ""
        echo "${MARKER_BEGIN}"
        echo "${content}"
        echo "${MARKER_END}"
    } >> "${file}"
    log_ok "已写入 ${file}"
}

tune_limits_conf() {
    step "写入 /etc/security/limits.conf"
    append_block_if_absent /etc/security/limits.conf "$(cat <<'EOF'
* soft nofile 262140
* hard nofile 262140
root soft nofile 262140
root hard nofile 262140
* soft core unlimited
* hard core unlimited
root soft core unlimited
root hard core unlimited
EOF
)"
    log_warn "limits.conf 的改动需要重新登录/重启系统后，新会话才会生效"
}

tune_sysctl_conf() {
    step "写入 /etc/sysctl.conf 并执行 sysctl -p"
    append_block_if_absent /etc/sysctl.conf "$(cat <<'EOF'
# Unix Socket 优化
net.unix.max_dgram_qlen = 100

# 内存缓冲区优化
net.core.wmem_max = 16777216
net.core.rmem_max = 16777216
net.core.wmem_default = 8388608
net.core.rmem_default = 8388608
net.ipv4.tcp_mem = 379008 505344 758016
net.ipv4.tcp_wmem = 4096 16384 4194304
net.ipv4.tcp_rmem = 4096 87380 4194304

# TCP 连接优化
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 81920
net.ipv4.tcp_synack_retries = 3
net.ipv4.tcp_syn_retries = 3
net.ipv4.tcp_fin_timeout = 30
net.ipv4.tcp_keepalive_time = 300
net.ipv4.ip_local_port_range = 20000 65000
net.ipv4.tcp_max_tw_buckets = 200000
net.ipv4.route.max_size = 5242880

# 消息队列优化
kernel.msgmnb = 4203520
kernel.msgmni = 64
kernel.msgmax = 8192
EOF
)"
    sysctl -p
    log_ok "sysctl -p 已执行"
}

# ------------------------- 5. 自检 -------------------------

self_check() {
    step "自检"

    echo "--- PHP 扩展加载情况 ---"
    "${PHP_BIN}" -v
    if extension_loaded swoole; then
        log_ok "swoole 扩展已加载"
        "${PHP_BIN}" --ri swoole | head -n 5 || true
    else
        log_err "swoole 扩展未加载"
    fi
    if extension_loaded mongodb; then
        log_ok "mongodb 扩展已加载"
        "${PHP_BIN}" --ri mongodb | head -n 5 || true
    else
        log_err "mongodb 扩展未加载"
    fi

    if [[ "${SKIP_KERNEL_TUNE}" != "1" ]]; then
        echo
        echo "--- 关键内核参数当前值 ---"
        echo -n "net.unix.max_dgram_qlen  = "; cat /proc/sys/net/unix/max_dgram_qlen
        echo -n "net.ipv4.tcp_mem         = "; cat /proc/sys/net/ipv4/tcp_mem
        echo -n "net.ipv4.tcp_tw_reuse    = "; cat /proc/sys/net/ipv4/tcp_tw_reuse
        echo
        echo "--- 当前 shell 的 ulimit（旧会话看到的是修改前的值，重新登录后再确认）---"
        ulimit -n
        ulimit -c
    fi
}

# ------------------------- 主流程 -------------------------

main() {
    require_root
    select_php_version
    resolve_php_paths

    local swoole_ver="${SWOOLE_VERSION:-${SWOOLE_VERSION_MAP[${PHP_DOTTED}]:-}}"
    local mongodb_ver="${MONGODB_VERSION:-${MONGODB_VERSION_MAP[${PHP_DOTTED}]:-}}"

    if [[ -z "${swoole_ver}" && "${PHP_DOTTED}" == "8.4" ]]; then
        log_err "PHP 8.4 默认不装 Swoole：Swoole 6.0+ 才支持 PHP 8.4，但 6.0+ 彻底移除了"
        log_err "Swoole\\Coroutine\\MySQL / Swoole\\Coroutine\\Redis，会直接弄坏本项目依赖的"
        log_err "easyswoole/mysqli（ORM）和 easyswoole/redis-pool。"
        log_err "确认要冒这个风险的话，自己评估过后用 SWOOLE_VERSION=6.x.x 环境变量强制指定再跑。"
        exit 1
    fi
    if [[ -z "${swoole_ver}" ]]; then
        log_err "版本映射表里没有 PHP ${PHP_DOTTED} 对应的 Swoole 版本，请用 SWOOLE_VERSION 环境变量手动指定"
        exit 1
    fi
    if [[ -z "${mongodb_ver}" ]]; then
        log_err "版本映射表里没有 PHP ${PHP_DOTTED} 对应的 mongodb 扩展版本，请用 MONGODB_VERSION 环境变量手动指定"
        exit 1
    fi

    log_info "PHP ${PHP_DOTTED} -> Swoole ${swoole_ver} / mongodb ${mongodb_ver}"

    install_build_deps

    build_and_install_ext swoole "${swoole_ver}" "--enable-openssl --enable-sockets"
    configure_php_ini swoole

    build_and_install_ext mongodb "${mongodb_ver}" ""
    configure_php_ini mongodb

    restart_php_fpm

    if [[ "${SKIP_KERNEL_TUNE}" != "1" ]]; then
        tune_limits_conf
        tune_sysctl_conf
    else
        log_warn "SKIP_KERNEL_TUNE=1，跳过 ulimit / 内核参数优化"
    fi

    self_check

    step "全部完成"
    log_ok "PHP ${PHP_DOTTED}（${PHP_DIR}）的 Swoole / MongoDB 扩展安装、内核参数优化已处理完毕"
    log_warn "记得重新登录 SSH 或重启服务器，让 limits.conf 的 nofile 改动对新会话生效"
}

main "$@"
