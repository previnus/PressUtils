=== WP Utility Suite ===
Requires at least: 7.0
Tested up to: 7.1.4
Requires PHP: 8.3
Stable tag: 0.2.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Site auditing, performance diagnostics, private admin tools, monitoring, and opt-in integrations.

Author: Previn Kalisetty Appadu <hi@previn.me>
Author website: https://previn.me

Step-by-step user guide: https://github.com/previnus/PressUtils/blob/v0.2.1/docs/USER-GUIDE.md

== Installation ==

1. Download wp-utility-suite-0.2.1.zip and its SHA-256 file from https://github.com/previnus/PressUtils/releases/tag/v0.2.1. Compare the archive digest with the checksum file.
2. Open Plugins → Add New Plugin → Upload Plugin, choose the named plugin ZIP, select Install Now, then activate WP Utility Suite.
3. Open Utility Suite → Overview and confirm the six module statuses and scheduler health. On multisite, use Network Admin → Utility Suite to check site setup and apply network-wide restrictions.
4. Choose the desired Module settings, save and test each feature. Local audit capture and diagnostics are available immediately. Features that send traffic or change site behavior are individually opt-in.

Use PHP 8.3 or newer, HTTPS and InnoDB. The verification matrix covers PHP 8.3, 8.4 and 8.5 on MariaDB 10.11 and includes a MySQL 8.4 job on PHP 8.5. Action Scheduler 4.2.0 is included and shares its runtime with any compatible copy already loaded. WooCommerce 11.1 or newer is optional.

== Module guide ==

* Security & Audit records privileged logins, role changes, plugin lifecycle events and suite settings changes. The filtered activity view and JSON Lines export have separate permissions. Exports cover up to 31 days and 10,000 records. Login alerts can be enabled for the site administration address.
* Performance shows overdue cron jobs, the largest autoloaded options by name and size, and optional slow HTTP timing samples. Cleanup previews freeze IDs for up to 100 expired records per supported suite table and expire in 15 minutes.
* CRM & Webhooks sends versioned content, order and stock events to explicitly approved destinations. It supports field aliases, synthetic verification, signed requests, attempt history, retry and a single eligible manual redrive.
* Admin Tools provides a dashboard widget, private technical notes and scheduled expiration for published posts and pages. Expiration moves unchanged content to Draft after rechecking the initiating user's current access.
* WooCommerce exposes opt-in order and stock events to CRM. The free-shipping notice appears on classic cart and checkout when WooCommerce offers an eligible core free-shipping rate for every package.
* Monitoring & SEO provides authenticated health, daily 404 aggregates, exact-path redirects on eligible 404s and manual same-origin link checks. Link checks inspect up to 20 eligible links from recent published content per run. Existing SEO tools continue to own search metadata.

== Webhook setup ==

Keep the keyring outside the database. Put these configuration definitions in a protected host configuration loaded by wp-config.php and substitute your values.

    define( 'WPUS_KEYRING', [ 'key-1' => getenv( 'WPUS_KEY_1' ) ] );
    define( 'WPUS_ACTIVE_KEY_ID', 'key-1' );
    define( 'WPUS_WEBHOOK_ORIGINS', [ 'https://hooks.example.com' ] );

Each key is 32 random bytes encoded in Base64. PHP needs cURL, DNS functions and libsodium. A host configured to require a WordPress HTTP proxy leaves this direct transport unavailable. Endpoints must be HTTPS on port 443 with a DNS hostname, an approved origin and a path free of query parameters and fragments.

Create a disabled destination under CRM & Webhooks. Use a randomly generated 32–128 character URL-safe signing secret. Configure the receiver with the same secret and queue a signed test. When the delivery succeeds, enable the destination and turn on event capture in the module settings. WooCommerce hooks also require their own opt-ins. Editing subscriptions, aliases, endpoint or secret invalidates verification and cancels deliveries from the old configuration.

The receiver needs the exact raw HTTP request body. Verify the timestamp within five minutes and compare the signature in constant time. The signature is lowercase hex HMAC-SHA256 of the Unix timestamp, a newline, the delivery UUID, another newline and the raw body, prefixed with v1=. The headers are X-WPUS-Timestamp, X-WPUS-Delivery-ID, X-WPUS-Key-ID and X-WPUS-Signature. The key ID is the first 16 hex characters of the signing secret's SHA-256 digest. Deduplicate the delivery UUID before applying downstream effects and retain deduplication records for at least eight days. Return 2xx for an already processed delivery.

Use a receiver or verification step with access to raw bytes before handing the parsed event to n8n or forwarding mapped fields to GoHighLevel. Keep the downstream provider credentials in the receiver. The plugin sends the signed WPUS envelope and requires the receiver to verify it.

== Operations ==

* Keep a regular Action Scheduler runner. With WP-CLI and the site URL set correctly, run the wpus-maintenance group using wp action-scheduler run --group=wpus-maintenance. On multisite, run WordPress cron on the network's main site for bounded provisioning and run the queue in each active site's context.
* Use the lifecycle control on Overview to pause site outbound traffic. The WPUS_STOP_OUTBOUND host constant overrides site preferences. WPUS_DISABLED_MODULES can contain module IDs to pause across the installation. Deactivation pauses outbound traffic and retains data. Review delivery history and resume explicitly after reactivation.
* Before upgrading from 0.1.1, back up the database and keyring and pause outbound traffic. After installing the replacement ZIP, open Utility Suite on each active site to run setup. An administrator with WP-CLI can run Wpus\Lifecycle::provision() in each site context. Existing queued order bodies without an erasure reference are canceled and cleared during the upgrade.
* Before rotating a signing secret, configure the receiver to accept the replacement. Save it, run a new synthetic test, then enable delivery again. Keep the encryption keyring and database backups aligned. Old encryption keys remain necessary for any records sealed under them. Saving replacement endpoint and signing values encrypts them under the active key.
* On a staging clone or database restore, set WPUS_STOP_OUTBOUND first. Review the site identity and revoke inherited destinations before re-enabling traffic on a clone. Restore the matching keyring on the original site if previously queued payloads are still eligible.
* Audit and notes are local to each site. WordPress privacy tools can export attributable records and redact author and actor IDs. When WooCommerce anonymizes an order, PressUtils cancels its retained deliveries. Review note text separately and apply the receiver's privacy workflow for data already delivered.
* Uninstall retains Utility Suite data by default. The site or network purge preference opts in to deletion during uninstall. Automatic network purge is bounded to 20 sites. On a larger network, back up first, network-deactivate the plugin and purge each intended site explicitly with WP-CLI in that site's URL context before removing the files. The callable is Wpus\Lifecycle::purge() after loading wp-utility-suite/includes/autoload.php. Remove any manually delegated user capabilities as part of that workflow.
* Redirect rules apply after WordPress canonical handling. Review overlaps with host and SEO redirects, then clear affected page caches. Cached 404 responses can bypass recording. Content expiration rechecks the post immediately before saving, so coordinate it with any other workflow that changes publication state.

== REST API ==

The current namespace is wpus/v1. Browser requests need the WordPress logged-in session cookie, X-WP-Nonce for wp_rest and the appropriate capability. GET /health also accepts an HTTPS WordPress Application Password issued to an account with wpus_read_health. Private responses use no-store.

GET routes include /modules, /settings/{module}, /audit/events, /notes, /performance/diagnostics, /monitoring/404s, /redirects, /webhooks/destinations, /webhooks/deliveries, /webhooks/deliveries/{uuid} and /operations/{uuid}. PATCH /settings/{module} requires its current ETag in If-Match. Other mutations use the nonce-protected wp-admin forms.

== Release scope ==

This is a release candidate. WooCommerce shipping uses classic templates. Before production use, validate the deployment's HTTPS loopback, scheduler, DNS, receiver and traffic profile. See the project release validation for the coverage and outstanding checks.

== Bundled code ==

Action Scheduler is bundled from the upstream 4.2.0 tag. Its production runtime files and notices are under vendor/action-scheduler. The upstream source ZIP is https://codeload.github.com/woocommerce/action-scheduler/zip/refs/tags/4.2.0 with SHA-256 ab3574206071902aa81e9a7ba50a72997ac377798a18431da4ae962d573ef643.

== Changelog ==

= 0.2.1 =

* Retry WooCommerce order erasure when the capture lock is busy, including while outbound delivery is paused.
* Process up to 20 webhook deliveries per sweep and stop starting new attempts after 12 seconds.

= 0.2.0 =
* Cancel retained order webhooks on WooCommerce erasure and recheck anonymization before sending.
* Upgrade the outbox schema and clear queued order bodies without erasure references.
* Derive delegated notes cursors from visible records.
* Test packaged WordPress and WooCommerce flows across PHP 8.3, 8.4 and 8.5.

= 0.1.1 =
* Require certificate verification for Action Scheduler async loopbacks and block authenticated HTTP loopbacks.
* Recheck persisted outbound pauses and delivery deadlines before opening connections.
* Enforce destination revisions, isolate CRM delivery from local maintenance locks, and add limits for publish-event intake.
* Reject malformed dates and apply REST policy consistently to mixed-case route names.

= 0.1.0 =
* Initial implementation of the six module panels and their core workflows.
* Bundled upstream Action Scheduler 4.2.0.
