#!/bin/bash

# Deployment Readiness Validation Script
# Checks if the coolice project is ready for deployment after refactoring.

set -e
# Temporarily disable exit on error for find commands that might access restricted directories
set +e

echo "=== COOLICE DEPLOYMENT READINESS CHECK (POST-REFACTOR) ==="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Counters
PASSED=0
FAILED=0
WARNINGS=0

# Check function
check() {
    local test_name="$1"
    local condition="$2"
    local details="$3"
    
    echo -n "Checking $test_name... "
    
    if eval "$condition"; then
        echo -e "${GREEN}✓ PASS${NC}"
        if [[ -n "$details" ]]; then
            echo "  └─ $details"
        fi
        ((PASSED++))
    else
        echo -e "${RED}✗ FAIL${NC}"
        if [[ -n "$details" ]]; then
            echo "  └─ $details"
        fi
        ((FAILED++))
    fi
}

# Warning function
warn() {
    local test_name="$1" 
    local message="$2"
    
    echo -e "${YELLOW}⚠ WARNING${NC}: $test_name"
    echo "  └─ $message"
    ((WARNINGS++))
}

# Base directory
BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$BASE_DIR"

echo "Working directory: $BASE_DIR"
echo ""

# 1. File Permissions
echo "=== FILE PERMISSIONS ==="
check "Key directories are 755" \
      "[[ \$(find . -maxdepth 1 -type d \( -name 'arcreformas.com.br' -o -name 'src' \) -perm 755 | wc -l) -eq 2 ]]" \
      "Application and source directories have proper read/execute permissions."

check "PHP files are 644" \
      "all_ok=true; for f in \$(find . -name '*.php' -not -path './.git*'); do if [[ \$(stat -c \"%a\" \"\$f\") != \"644\" ]]; then all_ok=false; break; fi; done; $all_ok" \
      "PHP files are readable but not world-writable."

check "No world-writable files" \
      "[[ -z \$(find . -type f -perm -002 -not -path './.git*' 2>/dev/null) ]]" \
      "No security risk from world-writable files."

echo ""

# 2. Project Structure
echo "=== PROJECT STRUCTURE ==="
check "Main application directories exist" \
      "[[ -d 'arcreformas.com.br' && -d 'src' ]]" \
      "All required application components are present."

check "New storage directories exist" \
      "[[ -d 'storage' && -d 'data' && -d 'temp' ]]" \
      "Top-level storage, data, and temp directories created."

check "Core system class exists" \
      "[[ -f 'src/PKMSystem.php' ]]" \
      "Central PKMSystem class is available."

check "Obsolete common.php removed" \
      "[[ ! -f 'src/common.php' ]]" \
      "Old helper file has been correctly removed."

check "Database schema exists" \
      "[[ -f 'db_schema.sql' ]]" \
      "Database structure definition is available."

echo ""

# 3. Configuration Files
echo "=== CONFIGURATION ==="
check "Central configuration exists" \
      "[[ -f 'src/config.php' ]]" \
      "Central configuration file is present."

check "Obsolete API config removed" \
      "[[ ! -f 'arcreformas.com.br/api/config.php' ]]" \
      "Old, insecure config file has been correctly removed."

check "Nginx configuration file exists" \
      "[[ -f 'arcreformas.com.br.nginx.conf' ]]" \
      "Custom Nginx rules for standalone server are present."

# New check for environment variable usage
if [[ -f 'src/config.php' ]]; then
    if ! grep -q "getenv" src/config.php; then
        warn "Configuration not using environment variables" \
             "src/config.php should use getenv() for all secrets to be secure."
    else
        check "Configuration uses getenv()" \
              "true" \
              "src/config.php correctly uses getenv() for secure configuration."
    fi
fi


echo ""

# 4. Web Files
echo "=== WEB ASSETS ==="
check "API entry point exists" \
      "[[ -f 'arcreformas.com.br/api/index.php' ]]" \
      "Main API router is available."

echo ""

# 5. Security Checks
echo "=== SECURITY VERIFICATION ==="
check "No executable PHP files" \
      "[[ -z \$(find arcreformas.com.br -name '*.php' -perm -111 2>/dev/null || true) ]]" \
      "PHP files are not executable (security best practice)."

check "Storage directories have safe permissions" \
      "storage_ok=true; for d in storage data temp; do if [[ \$(stat -c '%a' \"\$d\") != '755' && \$(stat -c '%a' \"\$d\") != '775' ]]; then storage_ok=false; break; fi; done; \$storage_ok" \
      "Storage directories have safe permissions (755 or 775)."

check "Deployment guide exists" \
      "[[ -f 'DEPLOYMENT.md' ]]" \
      "Deployment documentation is available."

echo ""

# Summary
echo "=== DEPLOYMENT READINESS SUMMARY ==="
echo ""
echo -e "Tests passed: ${GREEN}$PASSED${NC}"
echo -e "Tests failed: ${RED}$FAILED${NC}"  
echo -e "Warnings: ${YELLOW}$WARNINGS${NC}"
echo ""

if [[ $FAILED -eq 0 ]]; then
    echo -e "${GREEN}✅ DEPLOYMENT READY${NC}"
    echo ""
    echo "Your coolice project is ready for deployment!"
    echo ""
    echo "Next steps:"
    echo "1. Ensure you have set the required environment variables on your server."
    echo "2. Run 'deploy_coolice.sh' on your server or upload files manually."
    echo "3. Run 'set-permissions.sh' on your server."
    echo "4. Follow any remaining steps in DEPLOYMENT.md"
    
    exit 0
else
    echo -e "${RED}❌ DEPLOYMENT NOT READY${NC}"
    echo ""
    echo "Please fix the failed checks above before deploying."
    echo "Run './set-permissions.sh' to fix permission issues if needed."
    echo ""
    
    exit 1
fi