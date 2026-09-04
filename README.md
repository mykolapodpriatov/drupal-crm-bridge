# drupal-crm-bridge

**Queue-backed, idempotent sync between Drupal 11 entities and a CRM.**

Most Drupal-to-CRM code is a `hook_entity_update()` that fires a `POST` inline in
the request. It works until the CRM is slow, and then editors wait on it; until
the CRM returns `429`, and then the change is silently lost; until the CRM's
webhook writes the record back and the two systems ping-pong forever.

`crm_bridge` is a Drupal 11 module that does the boring, correct version:
entity changes go on a queue, writes carry idempotency keys and origin tags,
inbound webhooks are signature-verified and replay-protected, conflicts follow a
policy you configure instead of whichever request landed last, and everything
that fails ends up in a dead-letter queue you can inspect and replay from Drush.

Status: **early, in active development.** See [ROADMAP](#roadmap).

## What it gives you

- **Mapping as configuration.** A `crm_bridge_mapping` config entity binds an
  entity type and bundle to a remote CRM object, field by field, with a
  direction and a conflict policy. Exportable, reviewable, deployable.
- **Nothing blocking in the request path.** Entity hooks enqueue; queue workers
  do the HTTP. Editors never wait on the CRM.
- **Echo suppression.** Every outbound write is origin-tagged and logged
  briefly, so the webhook it causes is recognised as our own and dropped.
- **Idempotent writes.** A deterministic key per (entity, revision, target) means
  a retried queue item cannot create a duplicate contact.
- **Drift auditing.** `drush crm-bridge:audit` diffs Drupal against the CRM and
  reports what disagrees, without writing anything.
- **Pluggable connectors.** `CrmConnector` plugins, with HubSpot and Twenty in
  v1 and an in-memory fake for tests.

## Requirements

- Drupal 10.3 or 11
- PHP 8.3+

## Roadmap

v1 scope, follow-up work and known non-goals are tracked in
[GitHub issues](https://github.com/mykolapodpriatov/drupal-crm-bridge/issues).

## License

MIT
