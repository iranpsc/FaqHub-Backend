#!/bin/sh
# Replace default Alpine CDN with ArvanCloud mirror (Iran).
set -eu

MIRROR="${ALPINE_MIRROR:-https://mirror.arvancloud.ir/alpine}"

if [ -f /etc/apk/repositories ]; then
    sed -i "s|https://dl-cdn.alpinelinux.org/alpine|${MIRROR}|g" /etc/apk/repositories
fi
