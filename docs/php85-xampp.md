# PHP 8.5 with the XAMPP control panel

VenPosTPV keeps XAMPP's bundled PHP 8.1.25 for PrestaShop and every other
XAMPP site. This project alone invokes a project-local, generated PHP 8.5.12
CGI runtime through Apache's existing `mod_actions` and `mod_cgi` modules. The
`.runtime/` directory is intentionally ignored; build it locally rather than
expecting it in a checkout.

## Start

1. Build the local runtime once, if `.runtime/php-8.5.12/` is absent:

   ```bash
   bin/build-php85.sh
   ```

   Re-run the same command after editing `config/runtime/php85.ini`; when the
   pinned binary and extensions already verify, it refreshes only the rendered
   runtime INI files and does not rebuild PHP.

   If Composer dependencies must be installed, use this isolated PHP rather
   than XAMPP's PHP 8.1:

   ```bash
   env -u LD_LIBRARY_PATH -u PHPRC -u PHP_INI_SCAN_DIR \
     .runtime/php-8.5.12/bin/php composer.phar install
   ```

2. Start or restart **Apache** from the XAMPP control panel. No separate PHP
   service, system-PHP switch, or XAMPP configuration edit is required.
3. Open the TPV login URL (`http://localhost/tpv/app/login`). Apache runs
   `public/php85.cgi` for each request reaching `public/index.php`; it does
   **not** change XAMPP's global PHP runtime.

The wrapper clears `LD_LIBRARY_PATH`, `PHPRC`, and `PHP_INI_SCAN_DIR` before it
starts the native CGI binary, then explicitly selects the native runtime INI.
The isolated runtime deliberately preserves these observed XAMPP defaults:
`date.timezone=Europe/Berlin`, `upload_max_filesize=40M`,
`post_max_size=40M`, and `memory_limit=512M`. It does not clone the remaining
XAMPP INI settings.
It accepts only Apache's internal Action hand-off for the canonical
`public/index.php` target. A direct request to `php85.cgi`, including query
strings or path info, is rejected and cannot select another PHP file or INI.

## Checks

Run these checks from the project root before enabling or after changing the
wrapper:

```bash
bash tests/Functional/php85-runtime-contract.sh
bash tests/Functional/php85-cgi-boundary.sh
```

The second check exercises direct CGI and forged Action metadata rejections.
It also verifies that routing remains a fixed front-controller execution; the
request path is retained only for Symfony routing, including the `/tpv` base
path and its existing session-cookie behavior. There is intentionally no public
PHP version or `phpinfo()` endpoint.

Apache runs as `daemon`. It must be able to write the Symfony log directory
`var/log/` and the PHP error-log directory `var/log/php85/`; it already has only
the separately approved `var/cache/dev` access. If either log path is not
writable, do not broaden permissions or use `chmod 777`. Ask an administrator
for the minimum ACL granting `daemon` write and traverse access to those exact
directories only.

## Roll back

To return TPV to XAMPP's PHP 8.1 handler, restore `public/.htaccess` to:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
```

Then restart Apache from the XAMPP control panel. Do not delete the isolated
runtime or alter `/opt/lampp` configuration as part of rollback.

> **Current dependency warning:** the installed Composer dependencies require
> PHP 8.4 or newer. Restoring only this handler will send TPV back to XAMPP PHP
> 8.1.25 and make the application unavailable; it is a configuration rollback,
> not a working-runtime rollback. Restore a PHP-8.1-compatible dependency set
> separately if that outcome is required.
