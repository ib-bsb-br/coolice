#!/bin/bash
set -e

echo "--- Nginx Configuration Deployment Script ---"

SOURCE_CONFIG="arcreformas.com.br.nginx.conf"

# !!! USER ACTION REQUIRED !!!
# Please replace this placeholder with the correct path for DirectAdmin custom Nginx includes.
# This path is specific to your server setup. Common examples include:
# /usr/local/directadmin/data/users/ibbsbbry/domains/arcreformas.com.br.custom
# /usr/local/directadmin/data/users/ibbsbbry/domains/arcreformas.com.br.cust_nginx.conf
# Consult your hosting provider or DirectAdmin documentation if unsure.
DESTINATION_PATH="/replace/with/your/directadmin/custom/nginx/path/arcreformas.com.br.conf"

if [[ "$DESTINATION_PATH" == "/replace/with/your/directadmin/custom/nginx/path/arcreformas.com.br.conf" ]]; then
  echo -e "\033[0;31mERROR: Please edit this script and set the correct DESTINATION_PATH before running.\033[0m"
  exit 1
elif [[ ! -f "$SOURCE_CONFIG" ]]; then

  echo -e "\033[0;31mERROR: Source configuration file '$SOURCE_CONFIG' not found.\033[0m"
  exit 1
fi

echo "Copying '$SOURCE_CONFIG' to '$DESTINATION_PATH'..."
cp -v "$SOURCE_CONFIG" "$DESTINATION_PATH"

echo -e "\033[0;32m✓ Configuration file copied successfully.\033[0m"
echo "Please reload Nginx through your DirectAdmin panel to apply the changes."
