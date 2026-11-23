# Security recovery guide

`index.php` was replaced with code that downloads a script from another server and runs it. Whoever did this could read and change everything in the hosting account.

The code in GitHub is clean. The change was made **inside the hosting account**, so deploying alone will not keep the attacker out. Do every section, in order.

🟦 ACCOUNT = other websites · 🟩 ADMIN = our system · 🟧 CPANEL = hosting

## What protects the site now

| Protection | What it does |
|---|---|
| Site lock | Everyone sees "Temporarily closed" until you unlock it |
| `index.php` blocked | It never runs, whatever is written into it. Login is `signin.php` |
| Script list | Only our own pages can run. An uploaded script shows "Access denied" |
| Guard | Every 5 minutes it restores changed files and moves unknown scripts to `cs_guard/quarantine` |

> Someone who still has your cPanel password can switch all of this off. That is why section 2 matters most.

## 1. Lock the site (5 min)

1. 🟧 cPanel → **Git™ Version Control** → **Manage** → **Pull or Deploy** → **Update from Remote** → **Deploy HEAD Commit**.
2. 🟧 cPanel → **Cron Jobs** → **Common Settings** → **Once Per Five Minutes** → paste into **Command** → **Add New Cron Job**:
   `/usr/local/bin/php -n /home/conscioussol/cs_guard/guard.php >> /home/conscioussol/cs_guard/cron.log 2>&1`

**Test:** open the site. It must say **Temporarily closed**.

## 2. Throw the attacker out (20 min)

Virus-scan your own computer first. A stolen saved password is a common way in.

1. 🟧 Change the **cPanel password**. Turn on **Two-Factor Authentication**.
2. 🟧 **FTP Accounts**: delete every account you do not use. Change the password on the rest.
3. 🟧 **SSH Access** → **Manage SSH Keys**: delete keys you did not make.
4. 🟧 **Manage API Tokens**: revoke every token you did not make.
5. 🟧 **Cron Jobs**: delete any job you did not make. Keep the guard.
6. 🟧 **Email Accounts** and **Forwarders**: remove any you did not make. Change the mailbox passwords.
7. 🟦 Change the hosting company (billing) and GitHub passwords. Turn on two-factor for both.
8. 🟦 Ask the host: *"Our account was hacked. Please kill all our running PHP processes, run a malware scan, and send us the cPanel, FTP and web access logs around the time index.php was last changed."*

**Test:** log out of cPanel. The old password must not work.

## 3. Find what is still hidden (10 min)

1. 🟧 **Cron Jobs** → add this once, set to run in a few minutes:
   `/usr/local/bin/php -n /home/conscioussol/cs_guard/guard.php --scan > /dev/null 2>&1`
2. 🟧 **File Manager** → `cs_guard` → open `scan-report.txt`.
3. Delete the scan cron job.
4. Look at **MALWARE CODE FOUND IN**, **CRON JOBS**, **RUNNING PROCESSES** and **FOLDERS WITH AN INDEX PAGE**. An old WordPress or test site in the same account is often the real way in. Remove it or update it.

> The scan knows common malware. "0 found" does not prove the account is clean. If files keep changing after section 2, ask the host to reset the account, then deploy again.

**Test:** run the scan again. **MALWARE CODE FOUND IN** should show 0 (`.trash` counts, so empty it).

## 4. Block this malware's download (5 min)

1. 🟧 **MultiPHP INI Editor** → **Basic Mode** → choose the domain.
2. Set **allow_url_fopen** and **allow_url_include** to **Off** → **Apply**.

**Test:** the site still opens for you in section 5.

> Do not edit `.htaccess` by hand, and do not use **IP Blocker**, **Redirects** or **Directory Privacy** on this site. The guard removes those changes.

## 5. Change the secrets (15 min)

The attacker could read `config/db.php`, and the old login PINs were public on GitHub.

1. 🟧 **MySQL® Databases** → **Current Users** → **Change Password**. Put the new one in `public_html/config/db.php`, then set that file's **Permissions** to `0600`.
2. 🟧 Let only yourself in: search "what is my IP". **File Manager** → `cs_guard/allow` → **+ File** → name it exactly your IP address, e.g. `41.10.20.30` (or your IPv6 address if the site still says closed).
3. 🟩 Open `/signin.php`, log in → **Users**. Change **every** PIN. Deactivate users you do not know.
4. 🟩 Check invoices, payslips, users and **Settings** for changes. Check the bank details on invoices carefully.

**Test:** the old PINs must fail.

> Invoices and payslips hold personal information. Under POPIA you may have to tell the Information Regulator and the people affected. Ask your information officer or an attorney.

## 6. Get alerts (2 min)

🟧 **File Manager** → `cs_guard` → **+ File** → `alert_email` → **Edit** → type one email address → **Save Changes**. Every change is also written to `cs_guard/guard.log`.

**Test:** add an empty `test.php` to `public_html`. Within 5 minutes it moves to `cs_guard/quarantine` and an email arrives.

## 7. Open the site again

Only when sections 2 to 5 are done and `guard.log` has been quiet for 24 hours.

- **Open:** 🟧 **File Manager** → `cs_guard` → **+ File** → `UNLOCKED`.
- **Close again at any time:** delete `UNLOCKED`.

**Test:** open the site on your phone with Wi-Fi off. The login page must show.
