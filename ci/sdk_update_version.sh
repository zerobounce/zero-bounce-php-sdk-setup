#!/bin/bash
# Bump version for PHP SDK release.
# Usage: sdk_update_version.sh <major|minor|patch> [auto_increase: true|false]
# Reads current version from latest git tag (vX.Y.Z). Outputs new version to stdout.

set -e

level_change=${1:?Usage: sdk_update_version.sh <major|minor|patch> [auto_increase]}
auto_increase=${2:-true}

# Get latest tag (e.g. v1.2.0); default to v1.0.0 if no tags exist
version_name=$(git describe --tags --abbrev=0 --match 'v*' 2>/dev/null || echo "v1.0.0")
# Strip leading 'v'
version_name=${version_name#v}

major=$(echo "$version_name" | awk -F. '{print $1}')
minor=$(echo "$version_name" | awk -F. '{print $2}')
patch=$(echo "$version_name" | awk -F'[.+]' '{print $3}')

# Ensure numeric
major=${major:-0}
minor=${minor:-0}
patch=${patch:-0}

echo "Current version: $version_name" >&2

case "$level_change" in
  major)
    major=$((major + 1))
    minor=0
    patch=0
    ;;
  minor)
    minor=$((minor + 1))
    patch=0
    ;;
  patch)
    patch=$((patch + 1))
    ;;
  NONE|none)
    echo "$version_name"
    exit 0
    ;;
  *)
    echo "Level must be major, minor, or patch" >&2
    exit 1
    ;;
esac

if [ "$auto_increase" = "true" ]; then
  if [ "$patch" -gt 9 ]; then
    patch=0
    minor=$((minor + 1))
  fi
  if [ "$minor" -gt 9 ]; then
    minor=0
    major=$((major + 1))
  fi
fi

new_version="$major.$minor.$patch"
echo "New version: $new_version" >&2
echo "$new_version"
