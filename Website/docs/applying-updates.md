# Applying repository updates to the live WordPress theme

The files in this repository mirror the structure of the `child` theme that runs on kidsoverprofits.org. Each time you need to move a change from git into production, copy the matching file from this repo into the `wp-content/themes/child/` directory on the server (or into the theme folder inside a local WordPress install).

## Where the important pieces live

| Repository path | WordPress path | Notes |
| --- | --- | --- |
| `Website/functions.php` | `wp-content/themes/child/functions.php` | Theme bootstrap, hooks, and helper functions. |
| `Website/js/facility-form.v3.js` | `wp-content/themes/child/js/facility-form.v3.js` | Main facility data entry application logic. |
| `Website/js/app-logic.js` | `wp-content/themes/child/js/app-logic.js` | Shared widgets (notes, organizer, collapsibles) used by multiple admin pages. |
| `Website/admin-data.html` | `wp-content/themes/child/admin-data.html` | Admin-facing version of the facility data tool. |
| `Website/data.html` | `wp-content/themes/child/data.html` | Public-facing data entry page. |

All other asset folders (`css/`, `php/`, etc.) map directly under the same `wp-content/themes/child/` directory.

## Moving a change into place

1. **Open the theme directory**
   * Via SFTP or SSH: navigate to `<wordpress-root>/wp-content/themes/child/`.
   * Via the WordPress dashboard: go to **Appearance → Theme File Editor**, then select the *child* theme.
2. **Locate the matching file** in this repository and open it in your editor.
3. **Overwrite the file in WordPress** with the updated contents from git. When using the Theme File Editor, paste the entire file contents and click **Update File**. When using SFTP/SSH, upload the file so it replaces the existing version.
4. **Clear caches** if you have a caching plugin or CDN. Otherwise, force-refresh the browser (Ctrl/Cmd+Shift+R) to pull the new JavaScript.
5. **Verify the change** by reloading `admin-data.html` or `data.html` in the browser and checking the console for errors.

## Verifying you have the latest code

Each file in this repository begins with the same folder structure you will see on the server. If the path in git is `Website/js/facility-form.v3.js`, you should find the live file at `wp-content/themes/child/js/facility-form.v3.js`. Matching the folders is the easiest way to make sure you are looking at the right place.

If a page is crashing before you can open browser dev tools, grab the file directly from the server and compare it with the copy in git. Any difference means the live file has not been updated yet.
