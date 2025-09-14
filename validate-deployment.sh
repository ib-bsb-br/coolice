#!/bin/bash

# Deployment Readiness Validation Script
# Checks if the coolice project is ready for shared hosting deployment

set -e

# Temporarily disable exit on error for find commands that might access restricted directories
set +e

echo "=== COOLICE DEPLOYMENT READINESS CHECK ==="
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
check "Directory permissions are 755" \
      "[[ \$(find . -maxdepth 1 -type d \( -name 'memor.ia.br' -o -name 'arcreformas.com.br' -o -name 'cut.ia.br' -o -name 'src' -o -name 'jekyll_static_site' \) -perm 755 | wc -l) -eq \$(find . -maxdepth 1 -type d \( -name 'memor.ia.br' -o -name 'arcreformas.com.br' -o -name 'cut.ia.br' -o -name 'src' -o -name 'jekyll_static_site' \) | wc -l) ]]" \
      "All application directories have proper read/execute permissions"

check "PHP files are 644" \
      "all_ok=true; for f in \$(find . -name '*.php' -not -path './.git*'); do if [[ \$(stat -c \"%a\" \"\$f\") != \"644\" ]]; then all_ok=false; break; fi; done; \$all_ok" \
      "PHP files are readable but not world-writable (checked with stat)"

check "No world-writable files" \
      "[[ -z \$(find . -type f -perm -002 -not -path './.git*' 2>/dev/null) ]]" \
      "No security risk from world-writable files"

echo ""

# 2. Project Structure
echo "=== PROJECT STRUCTURE ==="
check "Main application directories exist" \
      "[[ -d 'memor.ia.br' && -d 'arcreformas.com.br' && -d 'cut.ia.br' && -d 'src' ]]" \
      "All required application components present"

check "Storage directory exists" \
      "[[ -d 'arcreformas.com.br/storage_arcreformas' ]]" \
      "File upload storage directory created"

check "Common utilities exist" \
      "[[ -f 'src/common.php' ]]" \
      "Shared PHP utilities available"

check "Database schema exists" \
      "[[ -f 'db_schema.sql' ]]" \
      "Database structure definition available"

echo ""

# 3. Configuration Files
echo "=== CONFIGURATION ==="
check "API configuration exists" \
      "[[ -f 'arcreformas.com.br/api/config.php' ]]" \
      "Backend API configuration file present"

check "Nginx configuration file exists" \
      "[[ -f 'arcreformas.com.br.nginx.conf' ]]" \
      "Custom Nginx rules for standalone server are present"

check "Nginx deployment script exists and is executable" \
      "[[ -f 'apply-nginx-config.sh' && -x 'apply-nginx-config.sh' ]]" \
      "Script to apply Nginx configuration is ready"

# Check for placeholder values in config
if [[ -f 'arcreformas.com.br/api/config.php' ]]; then
    if grep -q "your_database_name" arcreformas.com.br/api/config.php; then
        warn "Database configuration contains placeholders" \
             "Edit arcreformas.com.br/api/config.php with actual database credentials"
    fi
    
    if grep -q "your_github_personal_access_token_here" arcreformas.com.br/api/config.php; then
        warn "GitHub token not configured" \
             "Set GITHUB_TOKEN environment variable or edit config.php for publishing features"
    fi
fi

echo ""

# 4. Web Files
echo "=== WEB ASSETS ==="
check "Main application entry points exist" \
      "[[ -f 'memor.ia.br/index.php' && -f 'arcreformas.com.br/index.html' && -f 'cut.ia.br/index.php' ]]" \
      "All web application entry points available"

check "Styling files exist" \
      "[[ -f 'memor.ia.br/style.css' && -f 'arcreformas.com.br/style.css' && -f 'cut.ia.br/style.css' ]]" \
      "CSS styling files present"

check "API endpoints exist" \
      "[[ -f 'arcreformas.com.br/api/index.php' && -f 'arcreformas.com.br/api/tasks.php' && -f 'arcreformas.com.br/api/files.php' ]]" \
      "Backend API endpoints available"

echo ""

# 5. Security Checks
echo "=== SECURITY VERIFICATION ==="
check "No executable web files" \
      "[[ -z \$(find memor.ia.br arcreformas.com.br cut.ia.br -name '*.php' -perm -111 2>/dev/null || true) ]]" \
      "PHP files are not executable (security best practice)"

check "Storage directory isolated" \
      "[[ -d 'arcreformas.com.br/storage_arcreformas' && \$(stat -c '%a' arcreformas.com.br/storage_arcreformas) == '755' ]]" \
      "Upload storage has safe permissions"

check "Deployment guide exists" \
      "[[ -f 'DEPLOYMENT.md' ]]" \
      "Deployment documentation available"

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
    echo "Your coolice project is ready for shared hosting deployment!"
    echo ""
    echo "Next steps:"
    echo "1. Review and address any warnings above"
    echo "2. Configure database credentials in arcreformas.com.br/api/config.php"
    echo "3. Upload files to your shared hosting account"
    echo "4. Follow the steps in DEPLOYMENT.md"
    echo "5. Test all functionality after deployment"
    
    exit 0
else
    echo -e "${RED}❌ DEPLOYMENT NOT READY${NC}"
    echo ""
    echo "Please fix the failed checks above before deploying."
    echo "Run './set-permissions.sh' to fix permission issues."
    echo ""
    
    exit 1
fi