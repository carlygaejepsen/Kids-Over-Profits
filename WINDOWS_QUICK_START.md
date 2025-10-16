# Windows Quick Start Guide
## Kids Over Profits - Deployment for Windows Users

**Last Updated:** 2025-10-15

---

## 🚀 Quick Deploy (5 Simple Steps)

### Step 1: Create .env File

**Easiest Method - Double-click the helper script:**
```
Double-click: create-env-file.cmd
```

**Or manually with Notepad:**
```cmd
cd api
notepad .env
```
Paste this content and save:
```
DB_HOST=localhost
DB_NAME=kidsover_suggestions
DB_USER=kidsover_dani
DB_PASS=Xk4&z9!pT#vR7bN@
```

### Step 2: Run Pre-deployment Tests

Open Command Prompt in the project folder:

```cmd
REM Test CSS assets
php tests\check-css-assets.php

REM Test PHP syntax
php -l functions.php
```

Expected output:
- "All CSS asset references resolved (9 unique references)"
- "No syntax errors detected"

### Step 3: Create .env on Server

**Using cPanel File Manager:**
1. Log into cPanel at your hosting provider
2. Click "File Manager"
3. Navigate to: `/home/kidsover/public_html/wp-content/themes/child/api/`
4. Click "New File"
5. Name it: `.env`
6. Right-click the file → "Edit"
7. Paste the same content from Step 1
8. Save
9. Right-click → "Permissions" → Set to `600` (Owner: Read+Write only)

### Step 4: Deploy

```cmd
REM Make sure you're on main branch
git checkout main
git pull origin main

REM Push your changes
git push origin main
```

Wait 2-3 minutes for cPanel to deploy.

### Step 5: Test Deployment

**PowerShell (Recommended):**
```powershell
powershell -ExecutionPolicy Bypass -File tests\post-deployment-test.ps1
```

**Command Prompt (if you have curl installed):**
```cmd
tests\post-deployment-test.cmd
```

**Manual Testing (no scripts needed):**
1. Visit: https://kidsoverprofits.org
2. Visit: https://kidsoverprofits.org/data/
3. Visit: https://kidsoverprofits.org/admin-data/
4. Check browser console (F12) for errors

---

## 📁 Helpful Scripts (Already Created for You)

| Script | What It Does | How to Run |
|--------|--------------|------------|
| `create-env-file.cmd` | Creates .env file locally | Double-click it |
| `create-env-file.ps1` | Creates .env file (PowerShell) | Right-click → "Run with PowerShell" |
| `tests\post-deployment-test.ps1` | Tests live site (PowerShell) | `powershell -ExecutionPolicy Bypass -File tests\post-deployment-test.ps1` |
| `tests\post-deployment-test.cmd` | Tests live site (CMD) | `tests\post-deployment-test.cmd` |

---

## ✅ What Should Work After Deployment

- ✅ Homepage loads without errors
- ✅ Data submission form at `/data/` works
- ✅ Admin data form at `/admin-data/` works
- ✅ All CSS files load correctly
- ✅ All JavaScript files load correctly
- ✅ API endpoints return data (no 404 or 500 errors)
- ✅ Browser console shows: `[KOP] API base detected:`
- ✅ No "Loading..." stuck states

---

## 🆘 Troubleshooting

### Problem: "API endpoints returning 500 error"

**Solution:** The .env file is missing or incorrect on the server.

1. Check cPanel File Manager: `/home/kidsover/public_html/wp-content/themes/child/api/.env`
2. Verify it exists and has the correct database credentials
3. Check permissions are set to 600

### Problem: "CSS or JS files showing 404"

**Solution:** Files didn't deploy correctly.

1. Check cPanel deployment log for errors
2. Verify files exist in File Manager:
   - `/home/kidsover/public_html/wp-content/themes/child/css/`
   - `/home/kidsover/public_html/wp-content/themes/child/js/`
3. If missing, try pushing again

### Problem: "PowerShell won't run scripts"

**Solution:** Execution policy is blocking scripts.

Run PowerShell as Administrator and execute:
```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```

Or run scripts with bypass flag:
```powershell
powershell -ExecutionPolicy Bypass -File script.ps1
```

### Problem: "Command Prompt shows 'curl is not recognized'"

**Solution:** curl is not installed (needed for CMD tests).

Either:
1. Use PowerShell tests instead (recommended)
2. Install curl from: https://curl.se/windows/
3. Or just test manually in browser (see Step 5 above)

---

## 🔍 Checking for Errors

### Browser Console (F12)

**Good signs:**
- `[KOP] API endpoint resolver loaded`
- `[KOP] API base detected: https://kidsoverprofits.org/...`
- `[KOP Health Check] All systems operational ✓`

**Bad signs:**
- `404 (Not Found)` - Files missing
- `500 (Internal Server Error)` - Server configuration issue
- `Uncaught ReferenceError` - JavaScript not loading
- `Failed to load resource` - Path problems

### Network Tab (F12 → Network)

**What to check:**
- All files should show status `200` (green)
- CSS files load from `css/` folder
- JS files load from `js/` folder
- API calls to `api/data_form/` return data

---

## 📞 Need Help?

**Full Checklist:** See [PRE_DEPLOYMENT_CHECKLIST.md](PRE_DEPLOYMENT_CHECKLIST.md)

**Implementation Plan:** See [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md)

**Contact:** dani@kidsoverprofits.org

---

## 🎉 You're Ready!

Your codebase is in great shape. The only critical step is creating the `.env` file on the server.

**Total time:** ~30 minutes from start to finish

**Success rate:** High - most issues have been pre-fixed

Good luck! 🚀
