# Payment and venue-transfer audit

Use existing dashboard fonts and blue/neutral theme tokens in light and dark mode. Keep money in aligned, tabular figures, with currencies separate. The transfer dialog has two steps: choose the business/history, then review concrete changes and confirm. Show a short before/after money table and keep individual records and exclusions in expandable details. Preserve mode is the default.

Verify provider collections by payment method independently from booking channel. Preserve frozen business ownership for historical ledger access without granting access to the venue's current bookings. Review and commit must use identical reconciliation rules; excluded payments must retain their original attribution. Bind previews to sibling payments and internal allocations so a stale review cannot change money records.

Tests use the disposable local database and labelled QA records in the Railway workspace. No real venue is transferred and no gateway charge is made.
