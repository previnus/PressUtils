# PressUtils 0.1.1 release validation

The first public package is a staging prerelease. The source review began on the local 0.1.0 snapshot. The 0.1.1 tag includes fixes and regression coverage. No review or test suite can establish that a plugin is free of every bug or vulnerability.

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
- The GitHub Actions workflow lints PHP, builds the distributable and runs WordPress integration tests against the installed ZIP on two WordPress versions. [Actions runs](https://github.com/previnus/PressUtils/actions/workflows/verify.yml)
- The local fixtures exercise administrator and restricted-user access, nonce and ETag rejection, encrypted and signed webhook requests, retry and redrive, multisite behavior, shipping eligibility and the uninstall policy. DNS and cURL are simulated for delivery regressions. [Fixture instructions](../tests/README.md)

## Open limits

- An HTTP request already in progress can finish after a pause. System DNS can block longer than the subsequent cURL timeout. The worker uses a separate lock for CRM, and a stalled DNS lookup still delays that worker.
- Routine concurrent webhook captures can be dropped if the site CRM lock is busy. Watch the dropped-capture count in Overview. Receiver-side deduplication is required because retries can follow an uncertain result.
- A delegated notes viewer can infer row IDs at pagination boundaries even when linked note contents and post identities are restricted. Grant notes access only to appropriate staff.
- Public clients can skew anonymous 404 counts or exhaust the daily recording budget. Use host-level traffic limits where appropriate. Saturated totals are incomplete.
- Commerce payloads contain operational order IDs. The receiver can correlate them with customer records. This preview's WordPress privacy callbacks redact local attribution but do not cancel queued commerce payloads by customer or erase data already delivered.
- Complete REST writes, Checkout Blocks shipping support, live provider acceptance, load tests, and broader WordPress and database compatibility coverage remain outside this release.
