# cPanel Git Deployment Guide

## CRITICAL RULES

### 1. Unix Line Endings Required
The `.cpanel.yml` file **MUST** have Unix line endings (`LF` not `CRLF`).

**Already configured:** `.gitattributes` forces Unix line endings:
```
.cpanel.yml text eol=lf
```

**Never remove this line** or deployment will break.

### 2. Never Copy .git Directory
**NEVER** use wildcards like `cp -R *` or `cp -R .` in `.cpanel.yml`.

❌ **WRONG:**
```yaml
- /bin/cp -R * $DEPLOYPATH
- /bin/cp -R . $DEPLOYPATH
```

✅ **CORRECT:** Only copy specific directories
```yaml
- /bin/cp -R api $DEPLOYPATH
- /bin/cp -R inc $DEPLOYPATH
```

### 3. Working .cpanel.yml Format
Use the exact format below (tested and working as of Feb 2026):

```yaml
---
deployment:
  tasks:
    - export DEPLOYPATH=/home/kidsover/public_html/wp-content/themes/child/
    - /bin/cp -R api $DEPLOYPATH
    - /bin/cp -R inc $DEPLOYPATH
    - /bin/cp -R css $DEPLOYPATH
    - /bin/cp -R js $DEPLOYPATH
    - /bin/cp -R templates $DEPLOYPATH
    - /bin/cp -R docs $DEPLOYPATH
    - /bin/cp functions.php $DEPLOYPATH
    - /bin/cp style.css $DEPLOYPATH
    - /bin/cp page-*.php $DEPLOYPATH
    - /bin/cp .htaccess $DEPLOYPATH
    - /bin/cp *.sql $DEPLOYPATH
```

## Deployment Workflow

1. **Make changes locally**
2. **Commit and push to GitHub:**
   ```bash
   git add .
   git commit -m "Your message"
   git push origin main
   ```
3. **In cPanel Git Version Control:**
   - Click "Manage" on Kids-Over-Profits repository
   - Click "Pull or Deploy" tab
   - Click "Update from Remote" (pulls from GitHub)
   - Click "Deploy HEAD Commit" (runs .cpanel.yml)

## Troubleshooting

### "The system cannot deploy" Error
**Cause:** Working tree is not clean or .cpanel.yml is invalid

**Fix:**
1. Check for uncommitted changes in GitHub Desktop
2. Commit and push all changes
3. In cPanel, click "Update from Remote" before deploying

### "fatal: not a git repository" Error
**Cause:** Repository corrupted (usually from manual file deletion)

**Fix:**
1. cPanel → Git Version Control
2. Delete the broken repository
3. Click "Create" and re-clone from GitHub
4. URL: `https://github.com/carlygaejepsen/Kids-Over-Profits.git`
5. Path: `/home/kidsover/repositories/kids-over-profits`

### .git Folder Copied to Live Site
**Cause:** Wildcard in .cpanel.yml copied everything

**Fix:**
1. Use the PHP cleanup script or cPanel Terminal:
   ```bash
   rm -rf /home/kidsover/public_html/wp-content/themes/child/.git
   ```
2. Fix .cpanel.yml to only copy specific directories
3. Redeploy

### Deploy Button Grayed Out
**Causes:**
- .cpanel.yml has Windows line endings (CRLF instead of LF)
- Invalid YAML syntax
- Repository not pulled from remote

**Fix:**
1. Verify .gitattributes exists with: `.cpanel.yml text eol=lf`
2. Click "Update from Remote" first
3. Check .cpanel.yml syntax matches the working format above

## Adding New Directories

When you add a new directory that should be deployed:

1. Edit `.cpanel.yml`
2. Add the directory copy command:
   ```yaml
   - /bin/cp -R newdirectory $DEPLOYPATH
   ```
3. Commit and push
4. Pull and deploy in cPanel

## Reference

- **Official docs:** https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/
- **Working commit:** `728b3114` (Dec 28, 2025 - "Rollback .cpanel.yml to stable version")
- **Git history:** `git log --oneline -- .cpanel.yml` to see all changes

## Emergency Contacts

If deployment completely breaks:
1. Check git history for last working version: `git show 728b3114:.cpanel.yml`
2. Restore it: Copy that content to .cpanel.yml
3. Commit and push
4. Re-clone repository in cPanel if needed
