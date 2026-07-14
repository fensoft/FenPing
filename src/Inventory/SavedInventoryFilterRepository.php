<?php

declare(strict_types=1);

namespace FenPing\Inventory;

use FenPing\Database\DatabaseManager;
use FenPing\Host\HostMetadataNormalizer;
use FenPing\Host\HostMetadataRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final readonly class SavedInventoryFilterRepository
{
    public function __construct(
        private DatabaseManager $database,
        private HostMetadataRepository $metadata,
        private HostMetadataNormalizer $normalizer,
    ) {
    }

public function createSavedInventoryFilter(mixed $name, mixed $tags): array {
  $name = $this->normalizer->savedFilterName($name);
  $tags = $this->normalizer->tags($tags);
  if ($tags === array())
    throw new InvalidArgumentException('at least one tag is required');
  $id = $this->database->immediate(function(PDO $database) use ($name, $tags): int {
    $stmt = $database->prepare('INSERT INTO inventory_saved_filters (name) VALUES (:name)');
    $stmt->execute(array('name' => $name));
    $id = (int)$database->lastInsertId();
    $this->replaceSavedInventoryFilterTags($id, $tags);
    return $id;
  });
  $filter = $this->metadata->savedInventoryFilter($id);
  if ($filter === false)
    throw new RuntimeException('failed to load saved filter');
  return $filter;
}

public function updateSavedInventoryFilter(int $id, mixed $name, mixed $tags): array|false {
  $name = $this->normalizer->savedFilterName($name);
  $tags = $this->normalizer->tags($tags);
  if ($tags === array())
    throw new InvalidArgumentException('at least one tag is required');
  if ($this->metadata->savedInventoryFilter($id) === false)
    return false;
  $this->database->immediate(function(PDO $database) use ($id, $name, $tags): void {
    $stmt = $database->prepare('UPDATE inventory_saved_filters SET name=:name WHERE id=:id');
    $stmt->execute(array('name' => $name, 'id' => $id));
    $this->replaceSavedInventoryFilterTags($id, $tags);
  });
  return $this->metadata->savedInventoryFilter($id);
}

public function deleteSavedInventoryFilter(int $id): bool {
  $stmt = $this->database->connection()->prepare('DELETE FROM inventory_saved_filters WHERE id=:id');
  $stmt->execute(array('id' => $id));
  return $stmt->rowCount() > 0;
}

public function replaceSavedInventoryFilterTags(int $filterId, array $tags): void {
  $delete = $this->database->connection()->prepare('DELETE FROM inventory_saved_filter_tags WHERE filter_id=:filter_id');
  $delete->execute(array('filter_id' => $filterId));
  $insert = $this->database->connection()->prepare('INSERT INTO inventory_saved_filter_tags (filter_id, tag_id) VALUES (:filter_id, :tag_id)');
  foreach ($tags as $tag)
    $insert->execute(array('filter_id' => $filterId, 'tag_id' => $this->metadata->tagId($tag)));
}
}
