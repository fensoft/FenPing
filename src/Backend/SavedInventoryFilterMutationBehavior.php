<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use PDO;
use RuntimeException;

trait SavedInventoryFilterMutationBehavior
{
public function createSavedInventoryFilter(mixed $name, mixed $tags): array {
  $name = $this->normalizeSavedFilterName($name);
  $tags = $this->normalizeHostTags($tags);
  if ($tags === array())
    throw new InvalidArgumentException('at least one tag is required');
  $id = $this->database->immediate(function(PDO $database) use ($name, $tags): int {
    $stmt = $database->prepare('INSERT INTO inventory_saved_filters (name) VALUES (:name)');
    $stmt->execute(array('name' => $name));
    $id = (int)$database->lastInsertId();
    $this->replaceSavedInventoryFilterTags($id, $tags);
    return $id;
  });
  $filter = $this->savedInventoryFilter($id);
  if ($filter === false)
    throw new RuntimeException('failed to load saved filter');
  return $filter;
}

public function updateSavedInventoryFilter(int $id, mixed $name, mixed $tags): array|false {
  $name = $this->normalizeSavedFilterName($name);
  $tags = $this->normalizeHostTags($tags);
  if ($tags === array())
    throw new InvalidArgumentException('at least one tag is required');
  if ($this->savedInventoryFilter($id) === false)
    return false;
  $this->database->immediate(function(PDO $database) use ($id, $name, $tags): void {
    $stmt = $database->prepare('UPDATE inventory_saved_filters SET name=:name WHERE id=:id');
    $stmt->execute(array('name' => $name, 'id' => $id));
    $this->replaceSavedInventoryFilterTags($id, $tags);
  });
  return $this->savedInventoryFilter($id);
}

public function deleteSavedInventoryFilter(int $id): bool {
  $stmt = $this->db()->prepare('DELETE FROM inventory_saved_filters WHERE id=:id');
  $stmt->execute(array('id' => $id));
  return $stmt->rowCount() > 0;
}

public function replaceSavedInventoryFilterTags(int $filterId, array $tags): void {
  $delete = $this->db()->prepare('DELETE FROM inventory_saved_filter_tags WHERE filter_id=:filter_id');
  $delete->execute(array('filter_id' => $filterId));
  $insert = $this->db()->prepare('INSERT INTO inventory_saved_filter_tags (filter_id, tag_id) VALUES (:filter_id, :tag_id)');
  foreach ($tags as $tag)
    $insert->execute(array('filter_id' => $filterId, 'tag_id' => $this->tagId($tag)));
}
}
