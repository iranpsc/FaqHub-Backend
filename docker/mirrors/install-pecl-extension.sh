#!/bin/sh
# Install PECL extensions from GitHub when pecl.php.net is unreachable.
set -eu

extension="$1"
version="$2"

case "${extension}" in
    redis)
        repo="phpredis/phpredis"
        enable_name="redis"
        ;;
    xdebug)
        repo="xdebug/xdebug"
        enable_name="xdebug"
        ;;
    *)
        echo "unsupported extension: ${extension}" >&2
        exit 1
        ;;
esac

workdir="/tmp/pecl-${extension}"
archive="${workdir}.tar.gz"
url="https://github.com/${repo}/archive/refs/tags/${version}.tar.gz"

mkdir -p "${workdir}"
curl -fsSL "${url}" -o "${archive}"
tar xzf "${archive}" -C "${workdir}" --strip-components=1

cd "${workdir}"
phpize
./configure
make -j"$(nproc)"
make install
docker-php-ext-enable "${enable_name}"

rm -rf "${workdir}" "${archive}"
