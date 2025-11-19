# Security Hardening Guide

## Database Security (Highest Priority)

### Limit Database Permissions
Your current user likely has ALL PRIVILEGES. Create a restricted user:

```sql
-- 1. Create separate read-only user for API queries
CREATE USER 'kidsover_api_ro'@'localhost' IDENTIFIED BY 'strong-password-here';
GRANT SELECT ON kidsover_suggestions.facilities_master TO 'kidsover_api_ro'@'localhost';

-- 2. Create limited write user for form submissions
CREATE USER 'kidsover_api_rw'@'localhost' IDENTIFIED BY 'different-strong-password';
GRANT SELECT, INSERT, UPDATE ON kidsover_suggestions.facilities_master TO 'kidsover_api_rw'@'localhost';

-- 3. Remove DELETE and DROP permissions
-- Never grant these to web-facing users

-- 4. Flush privileges
FLUSH PRIVILEGES;
```

**Benefits:** Even if SQL injection happens, attacker can't:
- Drop tables
- Delete all records
- Access other databases
- Create new users

### Separate Databases by Environment
```sql
-- Production DB (separate credentials)
kidsover_production → kidsover_prod_user (limited permissions)

-- Staging DB (separate credentials)
kidsover_staging → kidsover_staging_user (limited permissions)

-- Local DB (separate credentials)
kidsover_suggestions → kidsover_dani (can be more permissive)
```

## File System Security

### File Permissions (Critical!)
```bash
# On your hosting server, set these permissions:

# PHP files - read-only for web server
find /path/to/wordpress -type f -name "*.php" -exec chmod 644 {} \;

# Sensitive config files - owner read-only
chmod 600 wp-config.php
chmod 600 api/config.local.php
chmod 600 .env

# Directories - no write access from web
find /path/to/wordpress -type d -exec chmod 755 {} \;

# WordPress uploads directory - exception (needs write access)
chmod 755 wp-content/uploads
```

### Disable PHP Execution in Uploads
Add to `.htaccess` in `wp-content/uploads/`:
```apache
<Files *.php>
    deny from all
</Files>
```

## Application Security

### SQL Injection Prevention (Already Good!)
Your code uses PDO prepared statements ✅
```php
// GOOD (what you're doing)
$stmt = $pdo->prepare("SELECT * FROM facilities_master WHERE unique_name = ?");
$stmt->execute([$name]);

// BAD (never do this)
$result = $pdo->query("SELECT * FROM facilities_master WHERE unique_name = '$name'");
```

### Input Validation
Add to API endpoints:
```php
// Sanitize all user inputs
$unique_name = filter_var($_POST['unique_name'], FILTER_SANITIZE_STRING);

// Validate format
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $unique_name)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid unique_name format']));
}
```

### Rate Limiting
Prevent brute force attacks:
```php
// Add to API files
session_start();
$max_requests = 100; // per hour
$time_window = 3600;

if (!isset($_SESSION['api_requests'])) {
    $_SESSION['api_requests'] = ['count' => 0, 'start' => time()];
}

if (time() - $_SESSION['api_requests']['start'] > $time_window) {
    $_SESSION['api_requests'] = ['count' => 0, 'start' => time()];
}

$_SESSION['api_requests']['count']++;

if ($_SESSION['api_requests']['count'] > $max_requests) {
    http_response_code(429);
    exit(json_encode(['error' => 'Rate limit exceeded']));
}
```

## Server Configuration

### Disable Directory Listing
Add to root `.htaccess`:
```apache
Options -Indexes
```

### Hide PHP Version
In `php.ini` or `.htaccess`:
```ini
expose_php = Off
```

### Disable Dangerous PHP Functions
In `php.ini`:
```ini
disable_functions = exec,passthru,shell_exec,system,proc_open,popen
```

### Enable HTTPS Only
In `.htaccess`:
```apache
# Force HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Security headers
Header set X-Frame-Options "SAMEORIGIN"
Header set X-Content-Type-Options "nosniff"
Header set X-XSS-Protection "1; mode=block"
Header set Referrer-Policy "strict-origin-when-cross-origin"
```

## WordPress Hardening

### Disable File Editing
Add to `wp-config.php`:
```php
// Disable theme/plugin editor in WordPress admin
define('DISALLOW_FILE_EDIT', true);

// Disable theme/plugin installation
define('DISALLOW_FILE_MODS', true);
```

### Limit Login Attempts
Install plugin: **Limit Login Attempts Reloaded**

### Two-Factor Authentication
Install plugin: **Two Factor Authentication**

## Backups (Critical!)

### Automated Database Backups
```bash
# Daily backup script (run via cron)
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u backup_user -p'password' kidsover_production > /backups/db_$DATE.sql
# Keep only last 30 days
find /backups -name "db_*.sql" -mtime +30 -delete
```

### File Backups
- Use hosting provider's backup service
- Or: rsync to remote server daily
- Test restoration periodically!

## Monitoring & Detection

### Enable WordPress Security Plugin
Install: **Wordfence Security**
- Firewall
- Malware scanning
- Login security

### Monitor Error Logs
```bash
# Check regularly for suspicious patterns
grep -i "failed\|denied\|error" /var/log/apache2/error.log | tail -100
```

### File Integrity Monitoring
```bash
# Create checksum of critical files
find /var/www -type f -name "*.php" -exec md5sum {} \; > checksums.txt

# Compare weekly to detect unauthorized changes
md5sum -c checksums.txt
```

## Network Security

### Firewall Rules
Only allow necessary ports:
```bash
# Allow only HTTP, HTTPS, SSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 22/tcp
ufw default deny incoming
ufw enable
```

### SSH Hardening
Edit `/etc/ssh/sshd_config`:
```
PermitRootLogin no
PasswordAuthentication no  # Use SSH keys only
Port 2222  # Non-standard port
```

## Quick Security Checklist

- [ ] Database users have minimal required permissions
- [ ] Separate credentials for dev/staging/production
- [ ] File permissions set correctly (600 for configs, 644 for PHP)
- [ ] PHP execution disabled in uploads directory
- [ ] Directory listing disabled
- [ ] HTTPS enforced site-wide
- [ ] WordPress file editing disabled
- [ ] Two-factor authentication enabled
- [ ] Automated daily backups running
- [ ] Security plugin installed (Wordfence)
- [ ] Rate limiting on API endpoints
- [ ] All inputs validated and sanitized
- [ ] Error messages don't expose sensitive info
- [ ] Security headers configured
- [ ] SSH key authentication only (no passwords)
- [ ] Firewall configured
- [ ] Regular security updates applied

## If You Get Hacked

1. **Immediate Actions:**
   - Take site offline
   - Change all passwords (database, hosting, WordPress admin)
   - Restore from known-good backup

2. **Investigation:**
   - Check error logs for attack vector
   - Scan for malware
   - Review database for unauthorized changes

3. **Recovery:**
   - Update all software
   - Re-harden security
   - Monitor closely for re-infection
