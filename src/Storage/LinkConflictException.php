<?php

declare(strict_types=1);

namespace Drupal\crm_bridge\Storage;

/**
 * Thrown when a CRM record is already linked to a different Drupal entity.
 *
 * This is not a failure to retry. It means two Drupal entities both believe
 * they are the same CRM record, which is a matching problem a person has to
 * look at: overwriting the link would leave one of the two entities silently
 * unsynced, with nothing anywhere reporting it.
 */
class LinkConflictException extends \RuntimeException {}
