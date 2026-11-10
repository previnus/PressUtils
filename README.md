# PressUtils

PressUtils is an installable WordPress utility plugin for local auditing, performance diagnostics, private admin notes, content expiration, monitoring, and optional signed webhooks and WooCommerce events. It appears as **WP Utility Suite** in WordPress.

[Download the plugin ZIP](https://github.com/previnus/PressUtils/releases) · [Step-by-step user guide](docs/USER-GUIDE.md) · [Release validation](docs/RELEASE-VALIDATION.md)

## Quick start

1. On a staging site with WordPress 7.0+, PHP 8.3+, HTTPS and InnoDB, download the versioned **wp-utility-suite** ZIP from the release assets.
2. In wp-admin, go to **Plugins → Add New Plugin → Upload Plugin**, select the ZIP and activate **WP Utility Suite**.
3. Open **Utility Suite → Overview**. Review the six panels and the scheduler status. Enable outbound and site-changing features individually.

WooCommerce 11.1+ is optional. The classic shipping notice uses WooCommerce's current rates. Webhooks need cURL, libsodium, a host-provided keyring, an approved HTTPS origin and a receiver that verifies signatures.

## Source and support

The installable source is in [wp-utility-suite](wp-utility-suite). [Verification instructions](tests/README.md) describe the integration and acceptance fixtures. Running **python tools/package.py** from the repository root builds the ZIP, SHA-256 checksum, and CycloneDX software bill of materials.

**Author** [Previn Kalisetty Appadu](https://previn.me) · [hi@previn.me](mailto:hi@previn.me)

Licensed under [GPL-3.0-or-later](LICENSE). Use [GitHub Issues](https://github.com/previnus/PressUtils/issues) for ordinary bugs and follow the [security reporting policy](SECURITY.md) for vulnerabilities.
