#!/usr/bin/env bash
# Build an installable AuthorLift plugin zip (upload via wp-admin → Plugins → Add New → Upload).
set -euo pipefail
cd "$(dirname "$0")"
rm -f authorlift.zip
zip -rq authorlift.zip authorlift -x '*/.DS_Store' -x '__MACOSX/*'
echo "Built $(pwd)/authorlift.zip"
