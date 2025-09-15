#!/bin/bash
set -euo pipefail

echo "--- Nginx Configuration Deployment Script ---"

# --- Configuration ---

# !!! USER ACTION REQUIRED !!!
# Please replace this placeholder with the correct full path for the
# custom Nginx include file for the specific domain you are targeting.
# This path is specific to your server setup. Common examples include:
# /usr/local/directadmin/data/users/ibbsbbry/domains/arcreformas.com.br.custom
# /usr/local/directadmin/data/users/ibbsbbry/domains/arcreformas.com.br.cust_nginx.conf
readonly CUSTOM_CONFIG_DEST_PATH="/replace/with/your/directadmin/custom/nginx/path/for/domain.conf"

# --- Script Logic ---

# Check if a domain argument was provided
if [[ -z "$1" ]]; then
  echo -e "\033[0;31mERROR: No domain name provided.\033[0m"
  echo "Usage: ./apply-nginx-config.sh <domain.name>"
  echo "Example: ./apply-nginx-config.sh arcreformas.com.br"
  exit 1
fi

DOMAIN_NAME="$1"
SOURCE_CONFIG="${DOMAIN_NAME}.nginx.conf"

# Check if the user has configured the destination path
if [[ "$CUSTOM_CONFIG_DEST_PATH" == "/replace/with/your/directadmin/custom/nginx/path/for/domain.conf" ]]; then
  echo -e "\033[0;31mERROR: Please edit this script and set the correct CUSTOM_CONFIG_DEST_PATH before running.\033[0m"
  exit 1
fi

# Check if the source config file exists in the repository root
if [[ ! -f "$SOURCE_CONFIG" ]]; then
  echo -e "\033[0;31mERROR: Source configuration file '$SOURCE_CONFIG' not found in the repository root.\033[0m"
  exit 1
fi

echo "This script will copy '$SOURCE_CONFIG' to '$CUSTOM_CONFIG_DEST_PATH'."
read -r -p "Are you sure you want to proceed? (y/n): " confirmation
if [[ "$confirmation" != "y" ]]; then
    echo "Operation cancelled."
    exit 0
fi

echo "Copying '$SOURCE_CONFIG' to '$CUSTOM_CONFIG_DEST_PATH'..."
cp -v "$SOURCE_CONFIG" "$CUSTOM_CONFIG_DEST_PATH"

echo -e "\033[0;32m✓ Configuration file copied successfully.\033[0m"
echo "To apply the changes, you may need to ask your hosting provider to reload Nginx"
echo "or trigger a reload through your control panel (e.g., DirectAdmin)."
