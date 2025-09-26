#!/bin/bash

set -euo pipefail

PROJECT_DIR="/app"

cd "$PROJECT_DIR"

# Ensure Composer dependencies are installed
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

# Install Node dependencies if needed
if [ ! -d node_modules ] || [ ! -f node_modules/.yarn-integrity ]; then
    yarn install --frozen-lockfile || yarn install
fi

# Build frontend assets if the manifest is missing
if [ ! -f public/build/manifest.json ]; then
    yarn dev
fi

exec "$@"
