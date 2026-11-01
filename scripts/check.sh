#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
find src tests e2e -name '*.php' -type f -print | sort | while IFS= read -r file; do
    php -l "$file"
done
php tests/run.php
