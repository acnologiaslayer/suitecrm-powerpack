#!/bin/bash
set -e

echo "======================================"
echo "SuiteCRM PowerPack - Docker Hub Build"
echo "======================================"
echo ""

# Configuration
DOCKER_USERNAME="${DOCKER_USERNAME:-mahir009}"
IMAGE_NAME="suitecrm-powerpack"
VERSION="${VERSION:-1.0.0}"

# Check if Docker is logged in
if ! docker info >/dev/null 2>&1; then
    echo "Error: Docker is not running or not accessible"
    exit 1
fi

# Build for multiple platforms
echo "Building multi-platform Docker image..."
echo "Image: ${DOCKER_USERNAME}/${IMAGE_NAME}"
echo "Tags: latest, v${VERSION}"
echo ""

# Create buildx builder if not exists
if ! docker buildx inspect suitecrm-builder >/dev/null 2>&1; then
    echo "Creating buildx builder..."
    docker buildx create --name suitecrm-builder --use
fi

# Build and push
docker buildx build \
    --platform linux/amd64,linux/arm64 \
    --tag ${DOCKER_USERNAME}/${IMAGE_NAME}:latest \
    --tag ${DOCKER_USERNAME}/${IMAGE_NAME}:v${VERSION} \
    --tag ${DOCKER_USERNAME}/${IMAGE_NAME}:1.0 \
    --tag ${DOCKER_USERNAME}/${IMAGE_NAME}:1 \
    --push \
    .

echo ""
echo "======================================"
echo "✅ Build and Push Complete!"
echo "======================================"
echo ""
echo "Images pushed:"
echo "  - ${DOCKER_USERNAME}/${IMAGE_NAME}:latest"
echo "  - ${DOCKER_USERNAME}/${IMAGE_NAME}:v${VERSION}"
echo "  - ${DOCKER_USERNAME}/${IMAGE_NAME}:1.0"
echo "  - ${DOCKER_USERNAME}/${IMAGE_NAME}:1"
echo ""
echo "Pull with:"
echo "  docker pull ${DOCKER_USERNAME}/${IMAGE_NAME}:latest"
echo ""
