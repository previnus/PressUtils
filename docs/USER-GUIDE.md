# PressUtils user guide

PressUtils appears as **WP Utility Suite** in WordPress. Start with a staging site and a database backup.

## 1. Check requirements

1. In **Tools → Site Health → Info**, confirm WordPress 7.0+ and PHP 8.3+. Use HTTPS and an InnoDB database. The release was exercised with MariaDB 10.11.
2. Confirm WordPress cron or an Action Scheduler runner is available. The plugin bundles Action Scheduler and shares the active runtime with other plugins.
3. Install WooCommerce 11.1+ if you need commerce features. HTTPS link checks need PHP cURL and DNS functions. Webhooks also need libsodium and host key configuration.

## 2. Download and install

1. Open [Releases](https://github.com/previnus/PressUtils/releases). Under **Assets**, download **wp-utility-suite-0.2.0.zip** and **wp-utility-suite-0.2.0.zip.sha256**.
2. Compare the ZIP's SHA-256 digest with the first value in the checksum file.

   ~~~powershell
   Get-FileHash -Algorithm SHA256 .\wp-utility-suite-0.2.0.zip
   Get-Content .\wp-utility-suite-0.2.0.zip.sha256
   ~~~

   ~~~sh
   shasum -a 256 -c wp-utility-suite-0.2.0.zip.sha256
   ~~~

3. In wp-admin, go to **Plugins → Add New Plugin → Upload Plugin**. Select the named plugin ZIP, choose **Install Now**, and activate **WP Utility Suite**. GitHub's automatic **Source code** archives are for development.
4. Open **Utility Suite → Overview**. Review the six module tabs and check the scheduler status after its first run.
5. On multisite, upload from **Network Admin → Plugins** and use **Network Activate** if appropriate. Under **Network Admin → Utility Suite**, check setup progress and configure network-wide restrictions. These take precedence over site settings.

## 3. Choose settings and access

Start with site administrators. Grant delegated roles only the required [capabilities](../wp-utility-suite/includes/Policy.php). For example, **wpus_view_audit** allows audit review and **wpus_export_audit** is a separate permission. WooCommerce management requires **manage_woocommerce**. Changing module feature switches requires **wpus_manage_modules** as well as the relevant module capability.

Local auditing and diagnostics are available after activation. Alerts, HTTP timing, expiry, 404 tracking, redirects, link checks, webhooks and WooCommerce features start off. Enable each desired option under its tab's **Module settings** and save. Reload the tab before retrying if another operator changed the settings.

## 4. Use the modules

### Security & Audit

1. Choose which privileged logins, role changes and plugin lifecycle events to record. Set retention from 1 to 90 days.
2. Under **Audit activity**, set a UTC date range and optionally enter an event code or actor user ID. Select **Apply filters**.
3. With export permission, select **Download filtered JSON Lines**. Keep exports restricted because they can contain user identifiers. Exports are capped at 31 days and 10,000 rows.
4. For login alerts, verify the site administration email in **Settings → General** and enable the login alert setting. Test a staging login.

### Performance

1. Open **Performance** to view overdue cron jobs and the largest autoloaded options by name and size.
2. Enable slow HTTP timing if needed. Review elapsed times and response classes under **Recent slow HTTP samples**.
3. Under **Cleanup of expired Utility Suite records**, preview the expired audit and monitoring records. Review the count and expiry, then select **Apply this cleanup preview**.

### Admin Tools

1. Open **Admin Tools → Private technical notes → Add a note**. Enter a title and note. Optionally link a post or page ID, visible as the number after **post=** in its edit URL.
2. Save the note. Use its expandable row to edit or delete it. Keep credentials and customer information out of notes.
3. To use expiration, enable scheduled moves to Draft, choose allowed post types, and save. Under **Content expiration**, enter the published post or page ID and a future time in the displayed site time zone.
4. Schedule it and monitor the operation status. The worker skips content that changed, is no longer published, or can no longer be edited by the initiating user.

### CRM & Webhooks

1. Prepare a receiver on a public DNS hostname with HTTPS on port 443. Use an endpoint URL without query parameters, a fragment or embedded credentials.
2. Generate a 32-byte encryption key and an independent signing secret.

   ~~~sh
   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   ~~~

3. Put the encryption key in the PHP service's **WPUS_KEY_1** environment variable or your host's secret store. Define the keyring, active ID and approved origin in **wp-config.php** before it loads **wp-settings.php**. Substitute your receiver origin.

   ~~~php
   define('WPUS_KEYRING', ['key-1' => getenv('WPUS_KEY_1')]);
   define('WPUS_ACTIVE_KEY_ID', 'key-1');
   define('WPUS_WEBHOOK_ORIGINS', ['https://hooks.example.com']);
   ~~~

4. Open **CRM & Webhooks → Destinations → Add a destination**. Enter the full endpoint, the signing secret and the selected events. Optionally supply a JSON field alias such as **{"content_id":"post_id"}**. Save the disabled destination.
5. Configure the receiver to verify **X-WPUS-Timestamp**, **X-WPUS-Delivery-ID**, **X-WPUS-Key-ID**, and **X-WPUS-Signature**. Reject timestamps more than five minutes from current time. Compute HMAC-SHA256 over the timestamp, a newline, the delivery ID, a newline, and the exact raw request body. Compare **v1=** plus lowercase hexadecimal HMAC to the supplied signature using constant-time comparison. The key ID is the first 16 hexadecimal characters of the signing secret's SHA-256 digest.
6. Deduplicate the delivery ID atomically before side effects. Retain that record for at least eight days and return success for already processed IDs. Feed a verified event into n8n or GoHighLevel only after the receiver has validated it.
7. In WordPress, select **Queue a signed test delivery**. When it succeeds, choose **Enable delivery**, then enable event capture in the module settings. Trigger an event on staging and review its delivery history.
8. For secret rotation, prepare the receiver for the replacement, save the new secret, run another synthetic test, and enable delivery again. Credential, subscription and field-alias changes invalidate verification and cancel pending deliveries from the previous configuration.

### WooCommerce

1. Open **Utility Suite → WooCommerce** and confirm the module is available.
2. Enable order status or low-stock events as needed. Select matching events at a verified CRM destination and enable CRM capture.
3. To show the classic shipping notice, enable its option and configure a core WooCommerce free-shipping method in the applicable shipping zone.
4. Test an eligible and an ineligible staging cart. The notice appears only when WooCommerce offers a qualifying rate for every package. This notice uses classic cart and checkout templates.

### Monitoring & SEO

1. Enable 404 aggregation and request an unmatched public path. Refresh **Daily 404 aggregates** to see counts. The plugin stores keyed path hashes and strips query strings.
2. Review the host and SEO plugin's redirect rules before enabling PressUtils redirects. Add the old and new same-site paths with a leading slash. Include the site's base path if needed and match the canonical trailing slash.
3. Select 302 to test the rule, request the old URL, and verify the destination. Select 301 if the change is permanent. Clear affected page caches after changes.
4. To run a link check, enable same-origin link checks and select **Check up to 20 links from recent published content**. Refresh after the scheduler runs. The plugin probes the site's public HTTPS origin and reports aggregate results.
5. Use the displayed **Health endpoint** URL. Browser REST requests use the WordPress session and a valid **X-WP-Nonce** for **wp_rest**. For an automated HTTPS probe, use a dedicated user with **read** and **wpus_read_health** and issue an Application Password from the account's profile. Store it securely and revoke it when retired.

   ~~~sh
   curl --user pressutils-probe 'https://www.example.com/wp-json/wpus/v1/health'
   ~~~

   Supply the Application Password at the prompt. Use the endpoint shown in the panel on sites that use query-based REST routing.

## 5. Run and troubleshoot scheduled work

1. Check **Utility Suite → Overview** and **Tools → Site Health**. If jobs are stale, confirm WordPress cron is running. Action Scheduler async loopbacks use verified TLS. If the host cannot support those loopbacks, use WP-Cron or WP-CLI.
2. To process due PressUtils actions directly, run the following against the site. On multisite, add **--url** with the target site URL.

   ~~~sh
   wp action-scheduler run --group=wpus-maintenance
   ~~~

3. If CRM shows **Unavailable**, check cURL, DNS, libsodium, the keyring, the allowed origin and Action Scheduler. A host that requires the WordPress HTTP proxy leaves direct webhook transport unavailable.
4. If a delivery fails, inspect the result and HTTP status. Verify the destination again if its settings changed, then use **Redrive eligible delivery** where available.
5. To stop plugin webhooks, probes and login alerts, use **Overview** outbound pause, the network-wide stop, or set **WPUS_STOP_OUTBOUND** to **true** in host configuration. A connection already underway can finish.

## 6. Upgrade, restore and uninstall

1. Back up the database and matching encryption keyring. Pause outbound traffic before a restore or clone. Revoke inherited destinations on clones before enabling traffic.
2. Upload the newer release ZIP using the same plugin directory. Select **Replace current with uploaded** when prompted. Open **Utility Suite** as an administrator on each active site to run setup. The upgrade from 0.1.1 clears queued order bodies that lack an erasure reference. Confirm module and scheduler status before resuming outbound work.
3. Deactivate the plugin to retain data and pause delivery. After reactivation, review queued work and resume outbound explicitly.
4. To keep data during uninstall, deactivate and delete the plugin. To remove suite data, back it up and opt into the purge from **Overview** or **Network Admin → Utility Suite** before uninstalling. Automatic network purge is limited to 20 sites. For larger networks use the [per-site procedure](../wp-utility-suite/readme.txt).
5. Use WordPress **Tools → Export Personal Data** or **Tools → Erase Personal Data** for local attribution. PressUtils cancels queued order events when WooCommerce anonymizes the order. If the capture lock is busy, the scheduler retries the cleanup even while outbound traffic is paused. A `commerce.erasure_failed` audit event means both the immediate cleanup and the retry write failed. Check the site's database and scheduler before resuming outbound traffic. Review retained note text separately and coordinate privacy handling for previously delivered events with your integration operator.

## 7. Verify a release

Download the ZIP and its matching **.zip.sha256** file into the same directory. Check the digest and verify GitHub's signed build provenance against this repository. [GitHub's artifact verification instructions](https://docs.github.com/en/actions/how-tos/secure-your-work/use-artifact-attestations/use-artifact-attestations)

~~~sh
sha256sum --check wp-utility-suite-0.2.1.zip.sha256
gh attestation verify wp-utility-suite-0.2.1.zip --repo previnus/PressUtils --source-ref refs/tags/v0.2.1
~~~

The **.cdx.json** asset lists the packaged components. Replace the version in these commands with the release you downloaded. Use release tags that include attestations.

## Support

Open a [GitHub issue](https://github.com/previnus/PressUtils/issues) for ordinary bugs. Include the software versions, affected module and reproducible steps. Remove credentials, cookies, audit exports and customer data. Report vulnerabilities privately to [hi@previn.me](mailto:hi@previn.me).
