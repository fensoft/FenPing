#!/bin/bash
set -euo pipefail

if [ "$#" -gt 1 ]; then
  echo "Usage: $0 [version]" >&2
  exit 2
fi

IMAGE=${FENPING_IMAGE:-fensoft/fenping}
VERSION=${1:-${FENPING_VERSION:-1.7}}
PLATFORMS=linux/arm64,linux/amd64,linux/arm/v7
BUILDER=${BUILDX_BUILDER:-fenping-multiarch}
PUBLISH_LATEST=${PUBLISH_LATEST:-1}

if [[ ! "$IMAGE" =~ ^[a-z0-9]+([._-][a-z0-9]+)*/[a-z0-9]+([._-][a-z0-9]+)*$ ]]; then
  echo "invalid Docker Hub image: $IMAGE" >&2
  exit 2
fi
if [[ ! "$VERSION" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]]; then
  echo "invalid image version: $VERSION" >&2
  exit 2
fi

echo "installing binfmt emulators"
docker run --privileged --rm tonistiigi/binfmt --install all

if ! docker buildx inspect "$BUILDER" >/dev/null 2>&1; then
  docker buildx create --name "$BUILDER" --driver docker-container --use >/dev/null
else
  docker buildx use "$BUILDER"
  docker buildx stop "$BUILDER" >/dev/null
fi
docker buildx inspect --bootstrap >/dev/null

SUPPORTED=$(docker buildx inspect | sed -n 's/^Platforms:[[:space:]]*//p')
SUPPORTED=${SUPPORTED//[[:space:]]/}
SUPPORTED=${SUPPORTED//\*/}
IFS=',' read -ra REQUESTED <<< "$PLATFORMS"
for platform in "${REQUESTED[@]}"; do
  platform=${platform//[[:space:]]/}
  if [ -z "$platform" ] || [[ ",$SUPPORTED," != *",$platform,"* ]]; then
    echo "builder does not support $platform" >&2
    echo "available platforms: $SUPPORTED" >&2
    echo "binfmt installation did not enable every requested platform" >&2
    exit 1
  fi
done

TAGS=(--tag "$IMAGE:$VERSION")
if [ "$PUBLISH_LATEST" = "1" ]; then
  TAGS+=(--tag "$IMAGE:latest")
fi

echo "publishing $IMAGE:$VERSION for $PLATFORMS"
docker buildx build \
  --platform "$PLATFORMS" \
  --pull \
  --push \
  --provenance=mode=max \
  --sbom=true \
  "${TAGS[@]}" \
  .

docker buildx imagetools inspect "$IMAGE:$VERSION"
