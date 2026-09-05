<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Connector;

/**
 * What a connector's peer can actually do.
 *
 * Every property here exists because some real CRM lacks it and the module has
 * to behave differently when it does. A design that assumed they were all the
 * same would push the difference out into production as a surprise.
 */
final class ConnectorCapabilities {

  /**
   * Constructs the capability set.
   *
   * @param bool $nativeIdempotency
   *   TRUE when the peer honours an idempotency key we supply. When FALSE the
   *   push worker falls back to a read-before-write plus a link-table check,
   *   which costs an extra request per write.
   * @param bool $webhooks
   *   TRUE when the peer can push changes to us. When FALSE the module relies
   *   on polling alone.
   * @param bool $softDelete
   *   TRUE when a deleted record stays readable in some archived form. When
   *   FALSE a deletion is only ever observed as an absence, and an absence is
   *   indistinguishable from a record that stopped being visible to our token,
   *   so the module must not treat it as a deletion.
   * @param bool $etags
   *   TRUE when records carry a version token, so concurrency does not have to
   *   be decided from timestamps.
   * @param int $maxPageSize
   *   The largest page the peer will return, or 0 to let the connector pick.
   * @param int $timestampGranularitySeconds
   *   How coarse the peer's modification times are. Anything above zero widens
   *   the watermark overlap, because a record modified inside the current tick
   *   may report the previous one.
   */
  public function __construct(
    public readonly bool $nativeIdempotency = FALSE,
    public readonly bool $webhooks = FALSE,
    public readonly bool $softDelete = FALSE,
    public readonly bool $etags = FALSE,
    public readonly int $maxPageSize = 0,
    public readonly int $timestampGranularitySeconds = 0,
  ) {}

  /**
   * Whether the peer's timestamps are precise enough to trust directly.
   *
   * @return bool
   *   TRUE when modification times are exact.
   */
  public function hasExactTimestamps(): bool {
    return $this->timestampGranularitySeconds === 0;
  }

}
