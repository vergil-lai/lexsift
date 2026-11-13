#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
: "${GH_TOKEN:?Configure the FFI_MIRROR_TOKEN repository secret with access to create and push vergil-lai/lexsift-ffi}"
tag=${1:?Usage: publish-ffi.sh vX.Y.Z}
version=$(sed -n 's/^version = "\([^"]*\)"/\1/p' Cargo.toml | head -1)
test "$tag" = "v$version"
test -f target/dist/ffi-mirror/composer.json
source_sha=$(git rev-parse HEAD)
mirror=vergil-lai/lexsift-ffi
if ! gh repo view "$mirror" >/dev/null 2>&1; then
  gh repo create "$mirror" --public --description 'Generated FFI distribution of vergil-lai/lexsift; development happens in the source repository.'
fi
gh auth setup-git
task_tmp=$(mktemp -d)
trap 'rm -rf "$task_tmp"' EXIT HUP INT TERM
gh repo clone "$mirror" "$task_tmp/mirror"
stage=$PWD/target/dist/ffi-mirror
cd "$task_tmp/mirror"
git checkout -B main
git config user.name 'github-actions[bot]'
git config user.email '41898282+github-actions[bot]@users.noreply.github.com'
git rm -r --ignore-unmatch .
cp -R "$stage/." .
git add --all
if git show-ref --verify --quiet "refs/tags/$tag"; then
  # Retries may finish creating a Release, but never replace an existing tag.
  git diff --cached --exit-code "$tag" -- .
else
  git commit --allow-empty -m "Release $tag from lexsift $source_sha"
  git tag "$tag"
  git push --atomic origin HEAD:main "refs/tags/$tag"
fi
if ! gh release view "$tag" --repo "$mirror" >/dev/null 2>&1; then
  gh release create "$tag" --repo "$mirror" --verify-tag --title "$tag" \
    --notes "Generated from https://github.com/vergil-lai/lexsift/commit/$source_sha. Do not edit this mirror manually."
fi
