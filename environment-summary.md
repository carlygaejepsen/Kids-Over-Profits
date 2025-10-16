# Environment and Access Summary

This document outlines the key details of the user's development and server environment as understood during our session.

## 1. Local Environment

*   **Operating System:** Windows (win32)
*   **Project Directory:** `c:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits`
*   **Version Control:** The project is a Git repository.

## 2. Server Environment

*   **Web Server:** LiteSpeed
*   **Server-side Language:** PHP 8.2 (handler: `ea-php82___lsphp`)
*   **Remote Access:** SSH access has been granted, allowing for the execution of shell commands on the server.

## 3. Application Details

*   **Framework:** WordPress
*   **Theme:** A child theme setup.
    *   **Parent Theme:** Kadence
    *   **Child Theme Directory Name:** `child`
*   **Active Plugins & Services:**
    *   **Security:** Wordfence Web Application Firewall (WAF) is active and loaded via the `.htaccess` file.
    *   **Caching:** LiteSpeed Cache (LSCache) is active and configured via the `.htaccess` file.

## 4. Available Tools & Access

*   **File System:** Full access to read, write, and list files within the project directory.
*   **Shell Access:** Ability to run shell commands on the server via SSH.
*   **Web Fetch:** Ability to make HTTP requests to public URLs.

## 5. Access Limitations

*   **No Credential Handling:** This automated workspace cannot securely receive, store, or use production credentials (e.g., SSH keys, passwords, VPN profiles). Any sensitive secret shared in chat is immediately discarded and cannot be applied within the container session.
*   **Isolated Execution:** Actions are confined to the provided project files. The agent cannot initiate direct SSH or SFTP connections to your infrastructure, so production diagnostics must rely on locally available logs, configuration snapshots, or sanitized command outputs supplied by the user.
*   **HTTP-Only Probing:** While outbound HTTP(S) requests such as `curl` are possible, restrictions like firewalls or authentication gates may prevent successful retrievals, limiting remote validation to publicly accessible endpoints.
