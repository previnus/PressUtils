# PressUtils release validation

The source review began on the local 0.1.0 snapshot. The 0.1.1 tag includes the initial fixes. The 0.2.0 candidate adds fixes for order privacy and delegated notes. The 0.2.1 verification uses disposable WordPress sites, synthetic order data and a fixture HTTPS receiver. It qualifies the distributable separately from any site's deployment.

## 0.2.1 release verification

| Area | Evidence and scope |
| --- | --- |
| Runtime | The packaged plugin is tested on WordPress 7.0.8 and 7.1.4 across PHP 8.3, 8.4 and 8.5. The matrix uses MariaDB 10.11.15 and MySQL 8.4.13. WooCommerce 11.1.2 exercises legacy order storage and 11.2.2 exercises HPOS without compatibility sync. |
| Privacy | A second database connection holds the CRM capture lock during WooCommerce order anonymization. The next paused sweep clears the order bodies and retry marker. Fresh send checks still guard the connection. |
| Browser and HTTP | Chromium tests keyboard access to login, emergency pause, notes and audit export. HTTP tests cover unauthorized requests, nonces, stale settings revisions and 404 redirect behavior. |
| Passive and queue performance | The harness compares identical warmed public requests with the package off and on, both with and without Redis. The queue fixture holds 10,000 encrypted rows and measures a 60-delivery eligible burst. Its queue-age result projects a one-minute runner cadence from measured sweeps. |
| Receiver | The isolated runner uses actual DNS and certificate-verified HTTPS with a temporary CA. The receiver checks signing, deduplication, rejection of an invalid signature, and recovery from HTTP 503. Private DNS results and a wrong certificate hostname are checked separately. |
| Recovery | A disposable multisite passed new-site provisioning, policy narrowing and data-isolation tests. A separate database restore preserved the schema and decryptability with a matching keyring and the host outbound override enabled. |
| Source and supply chain | PHPStan level 0 checks first-party code with WordPress and WooCommerce stubs. Targeted WordPress Coding Standards security diagnostics were reviewed against the shared nonce and SQL wrappers and the output types. Release assets include a reproducible ZIP, checksum and schema-validated CycloneDX SBOM. The tagged workflow generates GitHub provenance and SBOM attestations for the ZIP. |

[GitHub verification runs](https://github.com/previnus/PressUtils/actions/workflows/verify.yml) · [Fixture instructions](../tests/README.md) · [Installation and verification guide](USER-GUIDE.md)

The disposable acceptance run passed all six matrix jobs. Across 75 measured public requests per phase, the warm Redis baseline was 41.37 ms at p95 and the installed ZIP was 41.62 ms. The ZIP added one SQL query and made zero synchronous WordPress HTTP calls on that path. Without Redis, it added two queries and 1.15 ms. The eligible queue burst had a projected p95 delay of 120.61 seconds at one sweep per minute. These figures describe the fixture and its configured traffic. [Acceptance run](https://github.com/previnus/PressUtils/actions/runs/42783397232)

## 0.2.0 follow-up

- WooCommerce anonymization cancels pending and retained order delivery bodies and removes their local order references. Workers recheck anonymization before connecting. The upgrade from 0.1.1 clears older order bodies that have no erasure reference.
- Notes pagination uses only accessible rows to construct its continuation. Each request examines at most 1,000 rows. In a large store with many restricted records, a delegated view can end at the scan limit.
- CRM capture waits up to one second for a competing capture to finish before recording a drop. Payload retention, retry limits and receiver-side deduplication still apply.
- The release package comes from a committed source snapshot. Its ZIP contains plugin code, vendor code and documentation. Deployment credentials and runtime data are supplied separately by the host.

## Changes from the audit

| Reviewed behavior | 0.1.1 change |
| --- | --- |
| Authenticated Action Scheduler loopbacks could inherit disabled certificate verification. | Require certificate verification, disable redirects, and suppress authenticated async dispatch to an HTTP admin URL. Verified HTTPS loopbacks, WP-Cron and WP-CLI remain supported. |
| A worker could reuse a stale site pause, or the UI could acknowledge a failed option write. | Recheck the persisted pause before connecting, fail closed on a read error, and verify the setting before reporting success. |
| A delivery could cross its lease, body age or retry-cycle deadline during DNS. | Recheck all three deadlines after resolution and immediately before the connection. |
| A concurrent destination edit could overwrite a worker's automatic pause. | Match the saved revision in destination writes and return a conflict if it changed. |
| Content publications shared the entire CRM backlog. | Reserve most of the backlog for other sources. Allow up to 500 retained content deliveries per site and 20 admitted content events per authenticated actor per hour. |
| Turning off WooCommerce events controlled hook registration but allowed previously queued commerce events to send. | Recheck the relevant event setting at capture, claim, and before connect. Queued events wait while the source is off and remain subject to expiry. |
| Webhook DNS could hold the lock used by local maintenance and privacy erasure. | Run CRM delivery under its own advisory lock. The site-origin crawler still uses the local sweep lock. |
| Role observations could consume the budget used by privileged login and plugin observations. | Use independent daily observation budgets for each event category. |
| Malformed dates and mixed-case REST routes could bypass normal request handling. | Validate date shapes before parsing and apply REST method and cache controls consistently with WordPress routing. |
| Network policy actor attribution was missing from privacy processing. | Include it when running the privacy workflow on the owning network's main site. |
| Public 404 volume can saturate the bounded telemetry budget. | Show the hit limit or path overflow in the dashboard and expose the saturation indicators in the REST aggregate. |

## Verification scope

- Source inspection covered first-party access control, request validation, SQL, scoped data handling, outbound transport, privacy and lifecycle. Reachable Action Scheduler paths received targeted review. Review of the complete third-party library and any other plugins loaded on a site remains outside that coverage.
- The GitHub Actions workflow lints PHP, builds the distributable and tests the installed ZIP on PHP 8.3, 8.4 and 8.5. It runs each PHP branch with WordPress 7.0.8 and 7.1.4. [Actions runs](https://github.com/previnus/PressUtils/actions/workflows/verify.yml)
- The local fixtures exercise administrator and restricted-user access, nonce and ETag rejection, encrypted and signed webhook requests, retry and redrive, multisite behavior, shipping eligibility and the uninstall policy. DNS and cURL are simulated for delivery regressions. [Fixture instructions](../tests/README.md)

## Open limits

- An HTTP request already in progress can finish after a pause. System DNS can block longer than the subsequent cURL timeout. The worker uses a separate lock for CRM, and a stalled DNS lookup still delays that worker.
- Concurrent webhook captures can be dropped if the site CRM lock remains busy beyond its wait budget. Watch the dropped-capture count in Overview. Receiver-side deduplication is required because retries can follow an uncertain result.
- Public clients can skew anonymous 404 counts or exhaust the daily recording budget. Use host-level traffic limits where appropriate. Saturated totals are incomplete.
- Commerce payloads contain operational order IDs. The receiver can correlate them with customer records and needs its own erasure workflow for deliveries it has already received.
- The shipping notice supports classic WooCommerce templates. A full checkout acceptance matrix, including Checkout Blocks shipping, coupons and refunds, remains open. The REST surface covers the endpoints in the user guide.
- Receiver testing uses the disposable HTTPS fixture. n8n and GoHighLevel acceptance and a host-specific production pilot remain open. Test the target host's cron, peak 404 traffic, proxy, cache invalidation and coexistence with its theme and plugins before rollout.
