# Standalone Nginx Hosting Deployment Guide

This guide provides step-by-step instructions for deploying this multi-site project to a shared hosting environment that uses a **standalone Nginx web server**, such as CooliceHost with DirectAdmin.

## Architecture Overview

This project is designed for a modern, high-performance hosting stack:
- **Web Server:** Standalone Nginx (no Apache, no .htaccess).
- **Backend:** PHP-FPM for processing PHP scripts.
- **Control Panel:** DirectAdmin (or similar with SSH/Terminal access).

All URL rewriting and request routing is handled directly by Nginx configuration, not `.htaccess` files.

## Prerequisites

- Shared hosting account with **standalone Nginx** and PHP 7.4+ support.
- SSH or Terminal access for running scripts.
- MySQL database access.
- Domain names configured (e.g., memor.ia.br, arcreformas.com.br, cut.ia.br).

## File Permission Requirements

### Security-First Approach for Shared Hosting

The project has been configured with security-first permissions suitable for shared hosting:

- **Directories: 755** (owner: read/write/execute, others: read/execute only)
- **Files: 644** (owner: read/write, others: read only)

### Automated Permission Setting

Use the included script to set all permissions correctly before deployment:

```bash
./set-permissions.sh
```

This script ensures all files and directories have secure permissions and that deployment scripts are executable.

## Deployment Steps

### 1. Prepare Local Files

```bash
# Clone repository
git clone https://github.com/ib-bsb-br/coolice.git
cd coolice

# Set proper permissions
./set-permissions.sh
```

### 2. Database Setup

1.  Create a MySQL database on your hosting account (e.g., `coolice_main`).
2.  Import the database schema:
    ```bash
    mysql -u YOUR_USERNAME -p YOUR_DB_NAME < db_schema.sql
    ```
3.  Note your database credentials for the next step.

### 3. Configure Application Settings

Edit the API backend configuration file at `arcreformas.com.br/api/config.php` with your database credentials:

```php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');

// ... other settings
```

### 4. Configure Web Server (Nginx)

This project requires a custom Nginx configuration to handle API request routing.

1.  **Edit the Deployment Script:**
    Open the `apply-nginx-config.sh` script. You **must** update the `DESTINATION_PATH` variable to the correct location for custom Nginx include files for your `arcreformas.com.br` domain. The script contains comments with common examples for DirectAdmin.

2.  **Run the Deployment Script:**
    Execute the script from your terminal on the server. This will copy the required Nginx rules to the location you specified.
    ```bash
    ./apply-nginx-config.sh
    ```

3.  **Reload Nginx:**
    Log in to your DirectAdmin control panel and find the option to restart or reload Nginx to apply the new configuration.

### 5. Upload Project Files

Upload the application directories to their corresponding `public_html` (or equivalent) web root directories on your server.

#### Example Directory Structure on Server:
```
/home/ibbsbbry/domains/
├── arcreformas.com.br/
│   ├── public_html/  <-- Upload arcreformas.com.br contents here
│   └── storage_arcreformas/
├── cut.ia.br/
│   └── public_html/  <-- Upload cut.ia.br contents here
└── memor.ia.br/
    └── public_html/  <-- Upload memor.ia.br contents here
```

#### Example Upload Command (using rsync):
```bash
# Using rsync (upload each domain separately to correct locations)
rsync -avz --exclude='.git' --exclude='storage_arcreformas/' ./arcreformas.com.br/ username@server:/home/ibbsbbry/domains/arcreformas.com.br/public_html/
rsync -avz --exclude='.git' ./cut.ia.br/ username@server:/home/ibbsbbry/domains/cut.ia.br/public_html/
rsync -avz --exclude='.git' ./memor.ia.br/ username@server:/home/ibbsbbry/domains/memor.ia.br/public_html/
rsync -avz --exclude='.git' ./src/ username@server:/home/ibbsbbry/domains/src/
```
**Note:** The `jekyll_static_site` directory is managed and deployed separately and should not be uploaded with this project.

### 6. Set Up Domain Mapping

Ensure your domains are mapped to the correct `public_html` directories in your hosting control panel.

### 7. Test Deployment

1.  **Test main sites:**
    -   https://memor.ia.br
    -   https://cut.ia.br

2.  **Test API endpoints:**
    -   `https://arcreformas.com.br/api/tasks/public`
    -   `https://arcreformas.com.br/api/files`
    These should now work correctly without a `.htaccess` file.

3.  **Test file upload functionality.**

### 8. Security Verification

After deployment, run these checks from your server's terminal to verify security settings:

```bash
# Check that no files are world-writable
find /home/ibbsbbry/domains/ -type f -perm -002

# Verify that the Nginx configuration was copied (use the path from the script)
ls -la /path/to/your/directadmin/custom/nginx/path/arcreformas.com.br.conf

# Check storage directory isolation
ls -la /home/ibbsbbry/domains/arcreformas.com.br/storage_arcreformas/
```

## Maintenance

### Updating Code

The process remains the same. Pull changes locally, run `./set-permissions.sh`, and `rsync` the updated files to the server.

### Common Issues & Solutions

-   **Permission Problems:** Re-run `./set-permissions.sh`.
-   **502/504 Gateway Errors:** This often points to an issue with PHP-FPM or the Nginx configuration. Check your server's Nginx error logs.
-   **404 Not Found on API endpoints:** This means the custom Nginx rules are not being applied correctly. Double-check the path in `apply-nginx-config.sh` and ensure you reloaded Nginx.

## Security Best Practices

✅ **Implemented:**
- Secure file permissions (644/755).
- Standalone Nginx architecture (no .htaccess vulnerabilities).
- Input validation and prepared statements.
- CORS origin restrictions.

⚠️ **Additional Recommendations:**
- Enable HTTPS for all domains.
- Use environment variables for sensitive data.
- Implement rate limiting on API endpoints.
- Regular security updates and monitoring.

---

**✅ This deployment guide ensures your project is securely configured for a standalone Nginx hosting environment.**