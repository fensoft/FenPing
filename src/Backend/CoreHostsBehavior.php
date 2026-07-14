<?php

declare(strict_types=1);

namespace FenPing\Backend;

use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use RuntimeException;
use Throwable;

trait CoreHostsBehavior
{
public function addCategory($ip, $name) {
  $stmt = $this->getDb()->prepare("INSERT INTO `range` (ip_begin,`type`) VALUES (:ip, :name)");
  $stmt->execute(array("ip" => $this->normalizeCategoryIp($ip), "name" => $name));
}

public function renameCategory($category, $name) {
  $category = trim((string)$category);
  if ($category == "")
    throw new InvalidArgumentException("category ip is required");

  $name = trim((string)$name);
  if ($name == "")
    throw new InvalidArgumentException("category name is required");

  $normalized = $this->normalizeCategoryIp($category);
  $short = str_replace($this->config->network . ".", "", $normalized);
  $exists = $this->getDb()->prepare("SELECT COUNT(*) FROM `range` WHERE ip_begin=:ip OR ip_begin=:normalized OR ip_begin=:short");
  $exists->execute(array("ip" => $category, "normalized" => $normalized, "short" => $short));
  if ((int)$exists->fetchColumn() < 1)
    return 0;

  $stmt = $this->getDb()->prepare("UPDATE `range` SET `type`=:name WHERE ip_begin=:ip OR ip_begin=:normalized OR ip_begin=:short");
  $stmt->execute(array("name" => $name, "ip" => $category, "normalized" => $normalized, "short" => $short));
  return 1;
}

public function delCategory($category) {
  $normalized = $this->normalizeCategoryIp($category);
  $short = str_replace($this->config->network . ".", "", $normalized);
  $stmt = $this->getDb()->prepare("DELETE FROM `range` WHERE ip_begin=:ip OR ip_begin=:normalized OR ip_begin=:short");
  $stmt->execute(array("ip" => $category, "normalized" => $normalized, "short" => $short));
}

public function getIp($ip) {
  $stmt = $this->getDb()->prepare("select * from ips where ip=:ip");
  $stmt->execute(array("ip" => $ip));
  $host = $stmt->fetch(PDO::FETCH_ASSOC);
  return $host === false ? false : $this->normalizeManagedHostMetadata($host);
}

public function getMac($mac) {
  $stmt = $this->getDb()->prepare("select * from ips where lower(mac)=:mac");
  $stmt->execute(array("mac" => $mac));
  $host = $stmt->fetch(PDO::FETCH_ASSOC);
  return $host === false ? false : $this->normalizeManagedHostMetadata($host);
}

public function getId($id) {
  $stmt = $this->getDb()->prepare("select * from ips where id=:id");
  $stmt->execute(array("id" => $id));
  $host = $stmt->fetch(PDO::FETCH_ASSOC);
  return $host === false ? false : $this->normalizeManagedHostMetadata($host);
}

public function create($ip, $mac) {
  $stmt = $this->getDb()->prepare("INSERT INTO ips (mac, ip, scan_profile, scan_interval_hours) VALUES (:mac, :ip, :scan_profile, :scan_interval_hours)");
  $stmt->execute(array(
    "mac" => $mac,
    "ip" => $ip,
    "scan_profile" => self::SCAN_MANAGED_DEFAULT_PROFILE,
    "scan_interval_hours" => self::SCAN_MANAGED_DEFAULT_INTERVAL_HOURS
  ));
  return $this->getDb()->lastInsertId();
}

public function edit($id, $ip, $mac, $name, $repeater, $important, $web, $router, $dns, $netbootImageId = null, $scanProfile = self::SCAN_MANAGED_DEFAULT_PROFILE, $scanIntervalHours = self::SCAN_MANAGED_DEFAULT_INTERVAL_HOURS, $notes = '', $location = '', $owner = '', $model = '', $icon = null, array $tags = array(), string $displayName = '') {
  $stmt = $this->getDb()->prepare("
    UPDATE ips SET
      name=:name, mac=:mac, ip=:ip, repeater=:repeater, important=:important,
      web=:web, router=:router, dns=:dns, netboot_image_id=:netboot_image_id,
      scan_profile=:scan_profile, scan_interval_hours=:scan_interval_hours,
      display_name=:display_name, notes=:notes, location=:location,
      owner=:owner, model=:model, icon=:icon
    WHERE id=:id
  ");
  $stmt->execute(array(
    "name" => $name, "mac" => $mac, "ip" => $ip,
    "repeater" => $repeater != "1" ? null : "1",
    "important" => $important != "1" ? null : "1",
    "web" => $web != "1" ? null : "1",
    "router" => $router == "" ? null : $router,
    "dns" => $dns == "" ? null : $dns,
    "netboot_image_id" => $netbootImageId,
    "scan_profile" => $scanProfile,
    "scan_interval_hours" => $scanIntervalHours,
    "display_name" => $displayName === '' ? null : $displayName,
    "notes" => $notes === '' ? null : $notes,
    "location" => $location === '' ? null : $location,
    "owner" => $owner === '' ? null : $owner,
    "model" => $model === '' ? null : $model,
    "icon" => $icon,
    "id" => $id
  ));
  $this->replaceHostTags((int)$id, $tags);
  return $stmt->rowCount();
}

public function del($id) {
  $stmt = $this->getDb()->prepare("DELETE FROM ips WHERE id=:id");
  $stmt->execute(array("id" => $id));
  return $stmt->rowCount();
}
}
