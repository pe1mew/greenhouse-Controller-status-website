# Deploy

Deploys `httproot/` to a test server over SSH using built-in OpenSSH (`scp`, `ssh`).
No Python, no paramiko, no PuTTY. Authentication is handled entirely by `~/.ssh/config`.

## Prerequisites

- Windows 10+ with the OpenSSH client (default on modern Windows).
- An entry in `~/.ssh/config` for the target host. Example:

  ```ssh-config
  Host Shuttle2
    HostName 192.168.20.232
    User remko
    IdentityFile C:\Users\drasv\.ssh\id_rsa
  ```

- The remote user can write to `DEPLOY_DOC_ROOT` (e.g. `/var/www/html`).
  If not, fix it once on the server:

  ```bash
  sudo chown -R remko:www-data /var/www/html
  sudo chmod -R g+rwX /var/www/html
  ```

  Or deploy to a staging dir under the user's home and `sudo rsync` it into the
  webroot — but then this script needs adjustment.

## One-time setup

0. **Create your local `httproot/config.php`** from the template. The active
   config is gitignored so the production secret never enters the repo.

   ```powershell
   Copy-Item httproot\config_template.php httproot\config.php
   ```

   Then edit `httproot/config.php` and replace `GH_SECRET_TOKEN`'s placeholder
   value with a random 32-char string. Generate one:

   ```powershell
   # Windows / PowerShell
   -join ((48..57+65..90+97..122) | Get-Random -Count 32 | %{[char]$_})
   ```
   ```bash
   # macOS / Linux
   openssl rand -hex 24
   ```

   The same value must appear on the sender side (Flask mock or ESP32).
   For the mock, add `MOCK_SECRET=<value>` to `.deploy.env`. If they ever
   drift, every controller push silently 204s and the dashboard stays empty.

   The deploy script refuses to run if `httproot/config.php` is missing or
   still contains the placeholder, so you can't accidentally ship the
   template value to production.

1. Copy `.deploy.env.example` (in the project root) to `.deploy.env` and edit
   the host alias and document root. The file is gitignored.

   ```
   DEPLOY_HOST_ALIAS=Shuttle2
   DEPLOY_DOC_ROOT=/var/www/html
   ```

2. Confirm key auth works:

   ```powershell
   ssh Shuttle2 'whoami && pwd'
   ```

   Should print the remote user without prompting for a password.

## Run a deploy

From the project root:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\deploy.ps1            # upload
powershell -ExecutionPolicy Bypass -File .\tools\deploy.ps1 -DryRun    # list only
```

The `-ExecutionPolicy Bypass` is needed because the default Windows policy
blocks running unsigned local scripts. You can also relax this once for your
user account:

```powershell
Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
```

After that, the bare `.\tools\deploy.ps1` works.

## What it does

1. Reads `.deploy.env` for the host alias and the remote document root.
2. Enumerates the top-level entries in `httproot/`.
3. SSHes once to create `assets/`, `data/`, and `log/logs/` under the document
   root if they don't exist. Idempotent.
4. `scp -r` every top-level entry in `httproot/` into the document root.
   Then SSHes back in and normalizes permissions: every directory `0755`,
   every file `0644`, with `data/`, `log/`, and `log/logs/` re-restricted
   to `2770` so Apache (`www-data`) can write status updates and log files.
5. **Does not** delete remote files. This means runtime state survives a
   re-deploy:
   - `data/status.json` — last status push from the controller / mock.
   - `log/logs/*.log` — uploaded log files.
6. Prints what was uploaded.

## What it doesn't do

- No "clean deploy" mode that removes server-side files. The source tree
  doesn't carry rotation logic, so a missing local file won't be removed
  remotely. Add `--clean` if you ever need this; for now, ssh in and
  `rm` manually.
- No remote PHP smoke test after upload. Run a quick check yourself:

  ```powershell
  curl http://192.168.20.232/view.php
  ```

  Should print `{}` if the server has never received a status push, or
  the latest payload otherwise.

## Permissions

The script handles all permissions automatically on every deploy. No
manual chmod, no first-time setup commands.

Why `-p` is **not** passed to `scp`: it would preserve the source-side
mode bits, which translate poorly from Windows. In practice an
`assets/` directory copied from a Windows checkout landed as `drwx------`
on the Linux side, and Apache returned 403 for every file inside.
Without `-p`, the server's umask applies, then the script normalizes
explicitly:

- All directories → `0755`
- All files → `0644`
- `data/`, `log/`, `log/logs/` → `2770` so Apache can write there;
  the `2` is setgid so files Apache creates inherit the `www-data` group.

## Known limitations on this test server (deferred)

The default Ubuntu Apache config sets `AllowOverride None`, which means
**all `.htaccess` files are silently ignored**. Concrete impact:

- `httproot/data/.htaccess` (`Require all denied`) does nothing.
  `http://host/controller/data/status.json` is publicly fetchable.
  Same content is already served by `view.php`, so no incremental data
  exposure on this LAN-only test server — but TR-17 / FR-36 are violated.
- `httproot/log/logs/.htaccess` (extension whitelist + `Options -Indexes`)
  also does nothing. The directory listing 403 you may see is from
  Apache's default no-index-file behavior, not from our rule. A file
  with an unusual name could be served.

To activate the rules properly when going beyond the test server, change
the global default to `AllowOverride All`:

```bash
sudo sed -i 's|AllowOverride None|AllowOverride All|' /etc/apache2/apache2.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Then re-run the verification probes from technical-spec.md § 14.1.
