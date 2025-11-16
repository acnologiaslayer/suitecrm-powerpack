#!/bin/bash
# Docker Hub Publishing Guide for SuiteCRM PowerPack

echo "=========================================="
echo "SuiteCRM PowerPack - Docker Hub Publishing"
echo "=========================================="
echo ""
echo "Repository: https://github.com/mahir009/suitecrm-powerpack"
echo ""

# Step 1: Docker Hub Login
echo "STEP 1: Login to Docker Hub"
echo "----------------------------"
echo "Run: docker login"
echo "Enter your Docker Hub username and password when prompted"
echo ""
read -p "Press Enter when logged in..."

# Step 2: Build the image locally first
echo ""
echo "STEP 2: Build Docker Image Locally"
echo "-----------------------------------"
echo "Building image: mahir009/suitecrm-powerpack:latest"
docker build -t mahir009/suitecrm-powerpack:latest .
docker tag mahir009/suitecrm-powerpack:latest mahir009/suitecrm-powerpack:v1.0.0
docker tag mahir009/suitecrm-powerpack:latest mahir009/suitecrm-powerpack:1.0
docker tag mahir009/suitecrm-powerpack:latest mahir009/suitecrm-powerpack:1

echo ""
echo "✓ Image built successfully!"
echo ""

# Step 3: Test the image
echo "STEP 3: Test Docker Image (Optional)"
echo "-------------------------------------"
read -p "Do you want to test the image? (y/n): " test_choice
if [ "$test_choice" = "y" ]; then
    echo "Starting test container..."
    docker run -d --name suitecrm-powerpack-test \
        -p 8080:80 \
        -e SUITECRM_DATABASE_HOST=host.docker.internal \
        -e SUITECRM_DATABASE_NAME=suitecrm_test \
        -e SUITECRM_DATABASE_USER=test \
        -e SUITECRM_DATABASE_PASSWORD=test \
        mahir009/suitecrm-powerpack:latest
    
    echo "Test container started on http://localhost:8080"
    read -p "Press Enter to stop test container..."
    docker stop suitecrm-powerpack-test
    docker rm suitecrm-powerpack-test
fi

# Step 4: Push to Docker Hub
echo ""
echo "STEP 4: Push to Docker Hub"
echo "--------------------------"
echo "Pushing images to Docker Hub..."
echo ""

docker push mahir009/suitecrm-powerpack:latest
docker push mahir009/suitecrm-powerpack:v1.0.0
docker push mahir009/suitecrm-powerpack:1.0
docker push mahir009/suitecrm-powerpack:1

echo ""
echo "=========================================="
echo "✅ SUCCESS! Images Published to Docker Hub"
echo "=========================================="
echo ""
echo "Available tags:"
echo "  • mahir009/suitecrm-powerpack:latest"
echo "  • mahir009/suitecrm-powerpack:v1.0.0"
echo "  • mahir009/suitecrm-powerpack:1.0"
echo "  • mahir009/suitecrm-powerpack:1"
echo ""
echo "View on Docker Hub:"
echo "  https://hub.docker.com/r/mahir009/suitecrm-powerpack"
echo ""
echo "Pull with:"
echo "  docker pull mahir009/suitecrm-powerpack:latest"
echo ""
echo "GitHub Repository:"
echo "  https://github.com/mahir009/suitecrm-powerpack"
echo ""
