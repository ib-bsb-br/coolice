# Shared Hosting Deployment Guide for coolice.com

This guide provides step-by-step instructions for deploying the coolice project to shared hosting environments with proper security permissions.

## Prerequisites

- Shared hosting account with PHP 7.4+ support
- MySQL database access  
- FTP/SFTP access to hosting account
- Domain names configured (coolice.com, memor.ia.br, arcreformas.com.br, cut.ia.br)

## File Permission Requirements

### Security-First Approach for Shared Hosting

The project has been configured with security-first permissions suitable for shared hosting:

- **Directories: 755** (owner: read/write/execute, others: read/execute only)
- **Files: 644** (owner: read/write, others: read only)
- **No world-writable files/folders** (prevents security exploits)

### Automated Permission Setting

Use the included script to set all permissions correctly:

```bash
./set-permissions.sh
```

This script:
- ✅ Sets secure directory permissions (755)
- ✅ Sets secure file permissions (644)
- ✅ Creates storage directories with proper isolation
- ✅ Verifies no world-writable files exist
- ✅ Provides security verification report

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

1. Create MySQL databases on your hosting account:
   - `coolice_main` (for task/file data)
   
2. Import the database schema:
   ```sql
   mysql -u username -p coolice_main < db_schema.sql
   ```

3. Note your database credentials for configuration.

### 3. Configure Application Settings

#### For arcreformas.com.br (API Backend):
Edit `arcreformas/api/config.php`:

```php
// Database configuration
define('DB_HOST', 'localhost');           // Usually localhost on shared hosting
define('DB_NAME', 'your_db_name');       // e.g., coolice_main
define('DB_USER', 'your_db_user');       // Your database username
define('DB_PASS', 'your_db_password');   // Your database password

// File storage (points to shared storage directory)
define('UPLOAD_DIR', __DIR__ . '/../shared/storage_arcreformas/');
define('FILE_PUBLIC_URL', 'https://arcreformas.com.br/files/');

// CORS origins (restrict in production)
define('ALLOWED_ORIGINS', 'https://memor.ia.br,https://cut.ia.br,https://coolice.com');
```

### 4. Upload Files via FTP/SFTP

#### Directory Structure on Server:
```
public_html/
├── coolice.com/          # Main site (root domain)
├── memor.ia.br/          # Todo/task management
├── arcreformas.com.br/   # File storage & API 
├── cut.ia.br/            # Gateway/tools
└── shared/
    ├── src/              # Common PHP utilities  
    └── storage_arcreformas/ # File uploads
```

#### Upload Command Examples:
```bash
# Using rsync (upload each domain separately to correct locations)
rsync -avz --exclude='.git' coolice.com/ memor.ia.br/ arcreformas.com.br/ cut.ia.br/ shared/ username@server:/public_html/
rsync -avz --exclude='.git' ./arcreformas/ username@server:/public_html/arcreformas.com.br/
rsync -avz --exclude='.git' ./cut.ia.br/ username@server:/public_html/cut.ia.br/
rsync -avz --exclude='.git' ./src/ username@server:/public_html/shared/src/

# Upload Jekyll static site to main domain (if using Jekyll for coolice.com)
rsync -avz --exclude='.git' ./jekyll_static_site/_site/ username@server:/public_html/coolice.com/

# Create and upload storage directory
rsync -avz --exclude='.git' ./storage_arcreformas/ username@server:/public_html/shared/storage_arcreformas/

# Or via FTP client (FileZilla, WinSCP, etc.) - upload each directory to its corresponding location
# Note: Verify permissions are preserved during upload
```

### 5. Set Up Domain Mapping

Configure your hosting control panel to map domains:
- `coolice.com` → `/public_html/coolice.com/`
- `memor.ia.br` → `/public_html/memor.ia.br/`  
- `arcreformas.com.br` → `/public_html/arcreformas.com.br/`
- `cut.ia.br` → `/public_html/cut.ia.br/`

### 6. Test Deployment

1. **Test main sites:**
   - https://coolice.com
   - https://memor.ia.br
   - https://cut.ia.br

2. **Test API endpoints:**
   - https://arcreformas.com.br/api/tasks/public
   - https://arcreformas.com.br/api/files

3. **Test file upload functionality:**
   - Verify storage directory is writable by web server
   - Test file upload through the interface

### 7. Security Verification

After deployment, verify security settings:

```bash
# Check no world-writable files exist
find public_html/ -type f -perm -002

# Verify .htaccess files are in place
find public_html/ -name ".htaccess" -ls

# Check storage directory isolation
ls -la public_html/shared/storage_arcreformas/
```

## Maintenance

### Updating Code

1. **Pull latest changes locally:**
   ```bash
   git pull origin main
   ./set-permissions.sh
   ```

2. **Upload changed files:**
   ```bash
   rsync -avz --exclude='.git' ./ username@server:/public_html/
   ```

### Backup Strategy

- **Database:** Regular MySQL dumps
- **Files:** Backup storage_arcreformas/ directory  
- **Code:** Git repository serves as code backup

### Monitoring

- Monitor error logs in hosting control panel
- Set up uptime monitoring for all domains
- Check storage usage for file uploads

## Common Issues & Solutions

### Permission Problems
```bash
# Re-run permission script
./set-permissions.sh

# Check web server error logs
tail -f /path/to/error.log
```

### Database Connection Issues
- Verify database credentials in config.php
- Check if hosting provider has specific connection requirements
- Test database connection independently

### File Upload Issues  
- Verify storage directory exists and is writable
- Check PHP upload limits in hosting control panel
- Review server error logs for permission errors

## Security Best Practices

✅ **Implemented:**
- Secure file permissions (644/755)
- No world-writable files
- Input validation and prepared statements
- CORS origin restrictions

⚠️ **Additional Recommendations:**
- Enable HTTPS for all domains
- Use environment variables for sensitive data
- Implement rate limiting on API endpoints
- Regular security updates and monitoring

## Support

For issues specific to this deployment:
1. Check hosting provider documentation
2. Review server error logs
3. Verify all configuration files are properly set
4. Test in staging environment first

---

**✅ This deployment guide ensures your coolice project is securely configured for shared hosting environments while maintaining full functionality.**