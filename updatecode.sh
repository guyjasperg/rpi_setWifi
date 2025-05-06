#!/bin/bash

# Variables
REPO_DIR="/var/www/html"  # Change this to your repository path
REPO_DIR2="/var/www/html/rpi_setWifi"  # Change this to your second repository path
USER="guyjasper"            # Change this to the desired owner
GROUP="www-data"              # Change this to the desired group

# Change ownership
echo "Changing ownership of $REPO_DIR..."
sudo chown -R $USER:$GROUP $REPO_DIR

# Navigate to the repository directory
cd $REPO_DIR2 || { echo "Directory not found: $REPO_DIR2"; exit 1; }

# Pull the latest changes from GitHub
echo "Pulling the latest changes from GitHub..."
git pull origin main  # Change 'main' to your branch if necessary

# Revert permissions (if needed)
echo "Reverting permissions..."
sudo chown -R www-data:www-data $REPO_DIR  # Example for web server permissions

echo "Update complete!"
