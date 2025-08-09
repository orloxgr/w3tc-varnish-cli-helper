W3TC Varnish CLI Helper
Author: Byron Iniotakis
License: GPL-2.0-or-later

A helper plugin for W3 Total Cache that replaces its default HTTP PURGE requests with direct Varnish CLI commands (BAN or PURGE).
This allows you to purge Varnish caches even when the Varnish admin port is not exposed to HTTP, without modifying your VCL.

✨ Features
Works together with W3 Total Cache — it does nothing if W3TC’s “Enable reverse proxy caching via Varnish” is off.

Stores Varnish CLI settings in the W3TC “Reverse Proxy” section:

Varnish server(s) host:port

Control key

CLI method (BAN or PURGE)

Timeout

Debug purging toggle

Test Connection button to validate CLI access from the admin.

Hooks into:

Empty All Caches

Dashboard “Flush Varnish” button

Dashboard “Flush Post” button

w3tc_flush_all and w3tc_flush_url (including WP-Cron triggered purges)

Debug logging to wp-content/uploads/w3tc-varnish-cli.log when enabled.

Supports multiple varnish servers (one per line).

📦 Installation
Ensure W3 Total Cache is installed and activated.

Upload this plugin folder to /wp-content/plugins/w3tc-varnish-cli-helper/.

Activate W3TC Varnish CLI Helper from the WordPress Plugins screen.

Go to Performance → General Settings → Reverse Proxy in W3TC.

Fill in:

Varnish servers (e.g. 127.0.0.1:6082)

Varnish Control Key

Choose CLI method (BAN recommended)

Enable Debug purging if you want logs.

Save settings and use Test Connection to verify.

🛠 Usage
Once configured:

Any cache purge triggered by W3TC (manual or automatic) will run via Varnish CLI.

Logs (if enabled) will show:

Manual dashboard flushes

Post-specific purges

Automatic/WP-Cron purges

Example log entry:

yaml
Αντιγραφή
Επεξεργασία
[2025-08-09 04:00:15] DASHBOARD: w3tc_flush_varnish detected
[2025-08-09 04:00:15] CMD: ban req.http.host == "example.com" && req.url ~ ".*" @ 127.0.0.1:6082 :: STATUS 200 ::
[2025-08-09 04:00:15] EVENT CLI BAN @ 127.0.0.1:6082 ALL :: OK :: BAN OK
🔍 Notes
The plugin does not modify your VCL — ensure your Varnish configuration allows CLI access from your WordPress server.

For security, restrict CLI access in Varnish secret file and ACLs.

BAN is recommended over PURGE unless you specifically need PURGE behavior.

📄 License
This project is licensed under the GPL-2.0-or-later license.
