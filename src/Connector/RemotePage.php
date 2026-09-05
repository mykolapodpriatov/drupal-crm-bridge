<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Connector;

/**
 * One page of a delta read.
 */
final class RemotePage {

  /**
   * Constructs the page.
   *
   * @param list<\Drupal\crm_bridge\Connector\RemoteRecord> $records
   *   The records on this page.
   * @param list<string> $deletedIds
   *   Remote IDs the peer reports as deleted. Only a connector whose
   *   capabilities include soft delete can populate this: on a peer that hard
   *   deletes, a removed record and one that stopped being visible to our
   *   token look identical, and guessing between them is how a sync deletes
   *   live customer data.
   * @param string $cursor
   *   The cursor for the next page, empty when this was the last one.
   */
  public function __construct(
    public readonly array $records = [],
    public readonly array $deletedIds = [],
    public readonly string $cursor = '',
  ) {}

  /**
   * Whether another page follows.
   *
   * @return bool
   *   TRUE when there is more to read.
   */
  public function hasMore(): bool {
    return $this->cursor !== '';
  }

}
