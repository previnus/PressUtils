# Local verification

Use a disposable WordPress installation with WP Utility Suite active, the local environment type and automatic WordPress cron disabled during the tests. The tests write fixture posts, users, settings and records. Use WP-CLI with the URL of the site being tested.

~~~sh
wp eval-file tests/integration.php
~~~

For webhook and WooCommerce coverage, configure a temporary keyring and approve https://example.com. The integration fixture substitutes DNS and cURL functions inside the test process. It captures the outbound request without opening a connection. Run wp eval-file tests/commerce.php with WooCommerce active, once in HPOS mode with compatibility sync disabled and once in legacy mode.

For the HTTP smoke test, serve the disposable site at http://127.0.0.1:38142, enable pretty permalinks and provide the local administrator credentials using WPUS_SMOKE_USER and WPUS_SMOKE_PASSWORD. WPUS_SMOKE_URL overrides the base URL.

~~~sh
python tests/http-smoke.py
~~~

Run python tools/package.py from the workspace to rebuild the installable ZIP and checksum.
