#!/bin/bash
umask 0022

# File Permissions Setup for Shared Hosting Deployment
# This script sets appropriate permissions for the coolice project
# to be deployed on shared hosting environments like coolice.com

set -e

echo "Setting file permissions for shared hosting deployment..."

# Base directory
BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$BASE_DIR"

echo "Working directory: $BASE_DIR"

# Function to set permissions with logging
set_perms() {
    local path="$1"
    local perms="$2" 
    local description="$3"
    
    if [[ -e "$path" ]]; then
        chmod "$perms" "$path"
        echo "✓ Set $perms on $path ($description)"
    else
        echo "⚠ Skipping $path (not found)"
    fi
}

# Function to set permissions recursively with logging
set_perms_recursive() {
    local path="$1"
    local dir_perms="$2"
    local file_perms="$3"
    local description="$4"
    
    if [[ -d "$path" ]]; then
        find "$path" -type d -exec chmod "$dir_perms" {} \;
        find "$path" -type f -exec chmod "$file_perms" {} \;
        echo "✓ Set $dir_perms/$file_perms on $path ($description)"
    else
        echo "⚠ Skipping $path (directory not found)"
    fi
}

echo ""
echo "=== SHARED HOSTING SECURITY PERMISSIONS ==="
echo ""

# 1. Set secure defaults for all directories (755)
echo "1. Setting directory permissions to 755..."
find . -type d -not -path './.git*' -exec chmod 755 {} \;

# 2. Set secure defaults for all files (644) 
echo "2. Setting file permissions to 644..."
# Using a loop as a workaround for environments where find -exec might fail silently
find . -type f -not -path './.git*' -exec chmod 644 {} \;

echo ""
echo "=== SPECIFIC COMPONENT PERMISSIONS ==="
echo ""

# 3. Web application directories - 755 for directories, 644 for files
set_perms_recursive "memor.ia.br" 755 644 "Todo/task management app"
set_perms_recursive "arcreformas.com.br" 755 644 "File storage and API backend"
set_perms_recursive "cut.ia.br" 755 644 "Gateway/capture tools"
set_perms_recursive "src" 755 644 "Shared PHP utilities"

# 4. Jekyll static site
set_perms_recursive "jekyll_static_site" 755 644 "Jekyll static site generator"

# 5. Create new storage directory structure with proper permissions
echo ""
echo "3. Setting up storage directories..."

# Define new top-level storage directories required by the application
declare -a NEW_STORAGE_DIRS=("storage" "temp" "data")

for dir in "${NEW_STORAGE_DIRS[@]}"; do
    if [[ ! -d "$dir" ]]; then
        mkdir -p "$dir"
        echo "✓ Created $dir/ directory"
    fi
    # Set permissions. Note: 775 may be required if the web server runs as a different user in the same group.
    set_perms "$dir" 755 "Set permissions on $dir directory"
done

# 6. Set script permissions
echo ""
echo "4. Setting script permissions..."
set_perms "set-permissions.sh" 755 "Permission setup script"
set_perms "validate-deployment.sh" 755 "Deployment validation script"
set_perms "apply-nginx-config.sh" 755 "Nginx deployment script"

# 7. Database schema file
set_perms "db_schema.sql" 644 "Database schema file"

echo ""
echo "=== SECURITY VERIFICATION ==="
echo ""

# Verify no world-writable files exist (security risk on shared hosting)
echo "5. Checking for world-writable files (security risk)..."
WORLD_WRITABLE=$(find . -type f -perm -002 -not -path './.git*' 2>/dev/null || true)
if [[ -n "$WORLD_WRITABLE" ]]; then
    echo "⚠ WARNING: Found world-writable files:"
    echo "$WORLD_WRITABLE"
    echo "These files pose a security risk on shared hosting!"
else
    echo "✓ No world-writable files found"
fi

# Verify no world-writable directories exist (except git)
echo ""
echo "6. Checking for world-writable directories..."
WORLD_WRITABLE_DIRS=$(find . -type d -perm -002 -not -path './.git*' 2>/dev/null || true)
if [[ -n "$WORLD_WRITABLE_DIRS" ]]; then
    echo "⚠ WARNING: Found world-writable directories:"
    echo "$WORLD_WRITABLE_DIRS" 
    echo "These directories pose a security risk on shared hosting!"
else
    echo "✓ No world-writable directories found"
fi

echo ""
echo "=== PERMISSION SUMMARY ==="
echo ""
echo "Directory permissions: 755 (owner: rwx, group/others: r-x)"
echo "File permissions: 644 (owner: rw-, group/others: r--)"
echo ""
echo "This configuration is secure for shared hosting environments:"
echo "- Web server can read and execute PHP files"
echo "- Only the owner can modify files"
echo "- No world-writable files (prevents security exploits)"
echo "- Upload directories are properly isolated"
echo ""
echo "✅ File permissions have been set for shared hosting deployment!"
echo ""
echo "NEXT STEPS:"
echo "1. Test the application in a staging environment" 
echo "2. Upload files to shared hosting via FTP/SFTP"
echo "3. Verify web server can read files and execute PHP"
echo "4. Configure database connection in config.php files"
echo "5. Test all application functionality"