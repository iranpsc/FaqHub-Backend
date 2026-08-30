#!/bin/sh
# Point Composer packagist to an Iranian mirror.
set -eu

MIRROR="${COMPOSER_MIRROR:-https://package-mirror.liara.ir/repository/composer/}"

composer config --global repos.packagist composer "${MIRROR}"
