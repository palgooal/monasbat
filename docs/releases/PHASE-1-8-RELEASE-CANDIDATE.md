# Phases 1–8 Release Candidate

Date: 2026-09-26  
Status: **P9-A release preparation; not deployed**

## Scope

This release candidate packages the reviewed local implementation for:

- durable Catalog Activation History and provider/order identity;
- idempotent manual and Salla activation saga;
- durable event creation identity and event binding;
- event-end lifecycle reconciliation;
- aggregate Salla Plus eligibility;
- trusted refund/cancel tombstones;
- durable Salla membership desired-state convergence and removal snapshots;
- default-off destructive `not_member` removal with the H8-01 polling fix;
- the Catalog quota invariant that counts `publish`, `draft`, `pending`, and
  archived/private events owned by the current activation cycle.

## Included production files

### New files

- `wp-content/plugins/pgevents-core/assets/js/manual-package-activation-operation.js`
- `wp-content/plugins/pgevents-core/includes/class-pge-catalog-activation-schema.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-catalog-activation-repository.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-catalog-activation-service.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-catalog-event-binding-repository.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-catalog-event-binding-service.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-catalog-event-lifecycle-service.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-catalog-provider-origin-repository.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-catalog-order-revocation-repository.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-catalog-order-revocation-service.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-salla-not-member-removal-feature.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-salla-plus-eligibility-resolver.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-dec-plus-mig-01-backfill.php`
- `wp-content/plugins/pgevents-core/tools/dec-plus-mig-01-backfill.php`

### Modified files

- `wp-content/plugins/pgevents-core/pgevents-core.php`
- `wp-content/plugins/pgevents-core/includes/class-mon-events-users.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-salla-customer-groups-service.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-salla-membership-sync-store.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-salla-membership-sync-worker.php`
- `wp-content/plugins/pgevents-core/includes/class-pge-salla-sync-schema.php`
- `wp-content/plugins/pgevents-core/includes/class-salla-handler.php`
- `wp-content/plugins/pgevents-core/includes/event-factory.php`
- `wp-content/plugins/pgevents-core/includes/manual-package-activation-admin.php`
- `wp-content/plugins/pgevents-core/includes/manual-package-activation-ajax.php`
- `wp-content/plugins/pgevents-core/includes/metaboxes.php`
- `wp-content/plugins/pgevents-core/templates/dashboard-create.php`
- `wp-content/themes/pgevents-pro/page-create-event.php`

## Schema inventory

### Catalog Activation History

- version option: `pge_catalog_activation_schema_version`
- required version: `1.1.0`
- tables:
  - `{$wpdb->prefix}pge_catalog_activations`
  - `{$wpdb->prefix}pge_catalog_activation_events`
  - `{$wpdb->prefix}pge_catalog_activation_origins`
  - `{$wpdb->prefix}pge_catalog_order_revocations`

### Salla membership synchronization

- version option: `pge_salla_sync_schema_version`
- required version: `1.1.0`
- table: `{$wpdb->prefix}pge_salla_membership_sync`
- removal snapshot columns:
  - `removal_snapshot_groups LONGTEXT NULL`
  - `removal_snapshot_revision BIGINT UNSIGNED NULL`
  - `removal_snapshot_state VARCHAR(20) NULL`

Schema installation and verification are Production Stage P9-C. They have not
been executed by P9-A.

## Verified test baseline

The final independent Phase 8 re-gate established:

```text
PHP Phase 1–8: 1124/1124 PASS
JavaScript:       17/17 PASS
Total:          1141/1141 PASS
Critical: 0
High: 0
```

P9-A must reproduce this baseline and additionally run the relevant quota and
schema suites, PHP lint, JavaScript syntax checks, and `git diff --check`.

## Feature safety

The sole destructive-removal option is:

```text
pge_salla_not_member_removal_enabled
```

Only integer `1`, string `'1'`, and boolean `true` enable removal. A missing
option is disabled. No installer, activation hook, migration, or default writes
an enabled value.

## Phase 9 decisions

- `DEC-P9-DEPLOY-01`: deploy as atomically as the verified Production layout
  permits, with maintenance, cron pause, and a schema gate. A release-directory
  and symlink switch is preferred only if Production inspection proves it fits.
- Code deployment, verified pre-launch activation backfill, and destructive
  removal enablement are independent operations with explicit gates.
- The approved backfill is limited to the single fully verified user/order
  identity recorded in `docs/integrations/SALLA.md`.
- The existing `_mon_credit_cycle_id` is the historical activation identity;
  backfill must not generate a new cycle or rewrite existing User Meta credits.
- Any separately authorized aggregate membership projection may occur only
  after the durable backfill commit and does not authorize destructive removal;
  the hard-scoped backfill tool itself does not request that projection.
- The one-off backfill tool is hard-scoped to `DEC-PLUS-MIG-01`, has no automatic
  hook, and requires an explicit CLI confirmation argument. Its presence in the
  release does not execute or authorize the Production backfill.

## Operational prerequisites

Before Production deployment:

1. create one reviewed commit and immutable release identifier;
2. take verified filesystem and database backups;
3. inspect the actual hosting layout before selecting the atomic switch method;
4. enter maintenance and pause WP-Cron/external schedulers;
5. deploy all production files as one release;
6. run the schema installer once, verify exact contracts, then prove idempotency;
7. confirm the removal flag is missing/disabled before restoring traffic;
8. complete the Production read-only state audit before authorizing backfill;
9. authorize backfill separately;
10. authorize controlled removal separately after dry-run and Go/No-Go review.

## Explicitly not executed

P9-A performs none of the following:

- Production access or deployment;
- Production or local schema migration;
- verified activation backfill;
- feature enablement;
- real Salla HTTP;
- Git staging, commit, push, or tag creation.
