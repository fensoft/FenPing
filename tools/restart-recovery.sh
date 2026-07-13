#!/bin/bash

UPGRADE_STATE_DIR="$(pwd)/data/state/upgrades"
BACKUP_DIR="$(pwd)/data/backups"
mkdir -p "$UPGRADE_STATE_DIR"

journal_value() {
  local journal="$1" key="$2"
  sed -n "s/^[[:space:]]*\"$key\": \"\\([^\"]*\\)\",\\{0,1\\}$/\\1/p" "$journal" | head -n1
}

update_journal_status() {
  local journal="$1" status="$2" tmp="$1.tmp"
  sed "s/\"status\": \"[^\"]*\"/\"status\": \"$status\"/" "$journal" > "$tmp"
  chmod 0600 "$tmp"
  mv "$tmp" "$journal"
}

repo_digest() {
  docker image inspect "$1" --format '{{join .RepoDigests "\n"}}' 2>/dev/null | head -n1 || true
}

resolved_compose_image() {
  docker compose config | awk '
    /^  app:$/ { in_app = 1; next }
    in_app && /^    image:/ { sub(/^    image:[[:space:]]*/, ""); print; exit }
    in_app && /^  [^ ]/ { exit }
  '
}

run_image_cli() {
  local image="$1"
  shift
  docker run --rm --env-file .env \
    -e FENPING_DATA_DIR=/var/lib/fenping \
    -e DATABASE_PATH=/var/lib/fenping/database/fenping.sqlite3 \
    -v "$(pwd)/data/database:/var/lib/fenping/database" \
    -v "$(pwd)/data/netboot:/var/lib/fenping/netboot" \
    -v "$(pwd)/data/backups:/var/lib/fenping/backups" \
    "$image" php /opt/fenping/cli.php "$@"
}
run_data_root() {
  local image="$1"
  shift
  docker run --rm -v "$(pwd)/data:/data" "$image" "$@"
}

verify_with_image() {
  local image="$1" image_id="$2" filename="$3"
  docker run --rm --env-file .env \
    -e FENPING_DATA_DIR=/var/lib/fenping \
    -e DATABASE_PATH=/tmp/fenping-verification.sqlite3 \
    -e FENPING_VERIFY_IMAGE_ID="$image_id" \
    -v "$BACKUP_DIR:/var/lib/fenping/backups" \
    "$image" php /opt/fenping/cli.php backup-verify "/var/lib/fenping/backups/$filename"
}

write_journal() {
  local journal="$1" id="$2" archive="$3" checksum="$4" previous_id="$5"
  local previous_digest="$6" previous_ref="$7" previous_tag="$8" target_id="$9"
  shift 9
  local target_digest="$1" target_ref="$2" target_tag="$3" tmp="$journal.tmp"
  umask 077
  {
    echo "{"
    echo "  \"id\": \"$id\","
    echo "  \"created_at\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\","
    echo "  \"status\": \"prepared\","
    echo "  \"archive\": \"$archive\","
    echo "  \"sha256\": \"$checksum\","
    echo "  \"previous_image_id\": \"$previous_id\","
    echo "  \"previous_digest\": \"$previous_digest\","
    echo "  \"previous_ref\": \"$previous_ref\","
    echo "  \"previous_tag\": \"$previous_tag\","
    echo "  \"target_image_id\": \"$target_id\","
    echo "  \"target_digest\": \"$target_digest\","
    echo "  \"target_ref\": \"$target_ref\","
    echo "  \"target_tag\": \"$target_tag\""
    echo "}"
  } > "$tmp"
  mv "$tmp" "$journal"
}

prune_upgrade_checkpoints() {
  local keep=0 journal archive tag
  while IFS= read -r journal; do
    keep=$((keep + 1))
    [ "$keep" -le 2 ] && continue
    archive=$(journal_value "$journal" archive)
    tag=$(journal_value "$journal" previous_tag)
    [ -n "$tag" ] && docker image rm "$tag" >/dev/null 2>&1 || true
    if [ -n "$archive" ]; then
      rm -f "$BACKUP_DIR/$archive" "$BACKUP_DIR/$archive.metadata.json"
    fi
    rm -f "$journal"
  done < <(find "$UPGRADE_STATE_DIR" -maxdepth 1 -type f -name '*.json' -print | sort -r)
}

latest_rollback_journal() {
  local journal status
  while IFS= read -r journal; do
    status=$(journal_value "$journal" status)
    case "$status" in
      active|failed-health|prepared) echo "$journal"; return 0 ;;
    esac
  done < <(find "$UPGRADE_STATE_DIR" -maxdepth 1 -type f -name '*.json' -print | sort -r)
  return 1
}

rollback_upgrade() {
  local journal
  journal=$(latest_rollback_journal) || {
    echo "no recoverable upgrade checkpoint found" >&2
    return 1
  }
  local id archive previous_tag previous_id current_id rescue work displaced
  id=$(journal_value "$journal" id)
  archive=$(journal_value "$journal" archive)
  previous_tag=$(journal_value "$journal" previous_tag)
  previous_id=$(journal_value "$journal" previous_image_id)
  [ -f "$BACKUP_DIR/$archive" ] || { echo "rollback archive is missing: $archive" >&2; return 1; }

  if ! docker image inspect "$previous_tag" >/dev/null 2>&1; then
    local digest
    digest=$(journal_value "$journal" previous_digest)
    [ -n "$digest" ] || { echo "previous image is unavailable and has no recorded repository digest" >&2; return 1; }
    docker pull "$digest"
    docker image tag "$digest" "$previous_tag"
  fi
  [ "$(docker image inspect "$previous_tag" --format '{{.Id}}')" = "$previous_id" ] || {
    echo "recorded rollback tag does not match the previous image digest" >&2
    return 1
  }

  current_id=$(docker inspect fenping --format '{{.Image}}' 2>/dev/null || true)
  [ -n "$current_id" ] || current_id=$(journal_value "$journal" target_image_id)
  local current_tag="fenping-rollback-current:$id"
  docker image tag "$current_id" "$current_tag"
  if docker inspect fenping >/dev/null 2>&1; then
    docker stop --time 30 fenping >/dev/null 2>&1 || true
    docker rm fenping >/dev/null 2>&1 || true
  fi

  rescue="fenping-rollback-rescue-$(date -u +%Y%m%d-%H%M%S).tgz"
  if ! run_image_cli "$current_tag" backup "/var/lib/fenping/backups/$rescue" \
      || ! verify_with_image "$current_tag" "$current_id" "$rescue"; then
    echo "rollback rescue backup failed; live data was not replaced" >&2
    export FENPING_IMAGE=fenping-rollback-current FENPING_VERSION="$id"
    docker compose up -d --remove-orphans --pull never || true
    return 1
  fi

  work="$(pwd)/data/state/rollback-work-$id"
  displaced="$(pwd)/data/state/rollback-displaced-$id"
  run_data_root "$previous_tag" rm -rf "/data/state/rollback-work-$id" "/data/state/rollback-displaced-$id"
  mkdir -p "$work/database" "$work/netboot" "$displaced"
  if ! (
  docker run --rm --env-file .env \
    -e FENPING_DATA_DIR=/restore \
    -e DATABASE_PATH=/restore/database/fenping.sqlite3 \
    -v "$work:/restore" \
    -v "$BACKUP_DIR:/backups:ro" \
    "$previous_tag" php -r \
    'require "/opt/fenping/vendor/autoload.php"; $app=FenPing\Application::fromEnvironment("/opt/fenping"); $app->backend()->backupRestoreArchive($argv[1]);' \
    "/backups/$archive" &&
  docker run --rm --env-file .env \
    -e FENPING_DATA_DIR=/restore \
    -e DATABASE_PATH=/restore/database/fenping.sqlite3 \
    -v "$work:/restore" \
    "$previous_tag" php /opt/fenping/cli.php database &&
  docker run --rm \
    -v "$work:/restore" \
    "$previous_tag" chown -R www-data:www-data /restore/database /restore/netboot
  ); then
    echo "rollback checkpoint could not be staged; restarting the current image without replacing live data" >&2
    export FENPING_IMAGE=fenping-rollback-current FENPING_VERSION="$id"
    docker compose up -d --remove-orphans --pull never || true
    run_data_root "$previous_tag" rm -rf "/data/state/rollback-work-$id" "/data/state/rollback-displaced-$id"
    return 1
  fi

  run_data_root "$previous_tag" mv /data/database "/data/state/rollback-displaced-$id/database"
  run_data_root "$previous_tag" mv /data/netboot "/data/state/rollback-displaced-$id/netboot"
  run_data_root "$previous_tag" mv "/data/state/rollback-work-$id/database" /data/database
  run_data_root "$previous_tag" mv "/data/state/rollback-work-$id/netboot" /data/netboot

  export FENPING_IMAGE
  FENPING_IMAGE=$(printf '%s' "$previous_tag" | cut -d: -f1)
  export FENPING_VERSION
  FENPING_VERSION=$(printf '%s' "$previous_tag" | cut -d: -f2)
  if docker compose up -d --remove-orphans --pull never && wait_for_fenping; then
    update_journal_status "$journal" rolled-back
    run_data_root "$previous_tag" rm -rf "/data/state/rollback-displaced-$id" "/data/state/rollback-work-$id"
    docker image rm "$current_tag" >/dev/null 2>&1 || true
    echo "rollback complete; post-upgrade state saved to data/backups/$rescue"
    return 0
  fi

  echo "rollback image failed health checks; restoring the displaced post-upgrade state" >&2
  docker stop --time 30 fenping >/dev/null 2>&1 || true
  docker rm fenping >/dev/null 2>&1 || true
  run_data_root "$previous_tag" rm -rf /data/database /data/netboot
  run_data_root "$previous_tag" mv "/data/state/rollback-displaced-$id/database" /data/database
  run_data_root "$previous_tag" mv "/data/state/rollback-displaced-$id/netboot" /data/netboot
  export FENPING_IMAGE=fenping-rollback-current FENPING_VERSION="$id"
  docker compose up -d --remove-orphans --pull never || true
  return 1
}

run_restart() {
  local mode="$1"
  if [ "$mode" = "rollback" ]; then
    rollback_upgrade
    return
  fi
  if [ "$mode" = "demo" ]; then build_demo_backup; fi

  local id previous_id="" previous_ref="" previous_digest="" previous_tag="" previous_running="false"
  local target_ref target_id target_digest target_tag archive checksum journal=""
  id="$(date -u +%Y%m%d-%H%M%S)-$$"
  if docker inspect fenping >/dev/null 2>&1; then
    if [ "$(docker inspect fenping --format '{{.State.Running}}')" = "true" ]; then
      previous_running="true"
    elif [ "$mode" != "dev" ]; then
      docker start fenping >/dev/null
      wait_for_fenping
      previous_running="true"
    else
      echo "existing FenPing container is stopped; dev build will replace it without reviving obsolete code"
    fi
    previous_id=$(docker inspect fenping --format '{{.Image}}')
    previous_ref=$(docker inspect fenping --format '{{.Config.Image}}')
    previous_digest=$(repo_digest "$previous_id")
    previous_tag="fenping-rollback:$id"
    docker image tag "$previous_id" "$previous_tag"
  fi

  if [ "$mode" = "dev" ]; then export FENPING_VERSION=dev; fi
  docker compose config --quiet
  if [ "$mode" = "dev" ]; then
    target_ref=$(resolved_compose_image)
    [ -n "$target_ref" ] || { echo "could not resolve the app image from docker-compose.yml" >&2; return 1; }
    echo "building $target_ref for the current platform"
    docker build --pull --tag "$target_ref" .
  else
    docker compose pull app
    target_ref=$(resolved_compose_image)
  fi
  target_id=$(docker image inspect "$target_ref" --format '{{.Id}}')
  target_digest=$(repo_digest "$target_id")
  target_tag="fenping-upgrade:$id"
  docker image tag "$target_id" "$target_tag"

  if [ -n "$previous_id" ]; then
    archive="fenping-pre-upgrade-$(date -u +%Y%m%d-%H%M%S).tgz"
    if [ "$previous_running" = "true" ]; then
      docker exec fenping php /opt/fenping/cli.php backup "/var/lib/fenping/backups/$archive"
    else
      run_image_cli "$target_tag" backup "/var/lib/fenping/backups/$archive"
    fi
    verify_with_image "$target_tag" "$target_id" "$archive"
    checksum=$(docker run --rm -v "$BACKUP_DIR:/backups:ro" "$target_tag" sha256sum "/backups/$archive" | awk '{print $1}')
    [ -n "$checksum" ] || { echo "failed to record the verified backup checksum" >&2; return 1; }
    journal="$UPGRADE_STATE_DIR/$id.json"
    write_journal "$journal" "$id" "$archive" "$checksum" "$previous_id" "$previous_digest" "$previous_ref" "$previous_tag" "$target_id" "$target_digest" "$target_ref" "$target_tag"
  fi

  if docker inspect fenping >/dev/null 2>&1; then
    docker stop --time 30 fenping >/dev/null 2>&1 || true
    docker rm fenping >/dev/null 2>&1 || true
  fi
  export FENPING_IMAGE
  FENPING_IMAGE=$(printf '%s' "$target_tag" | cut -d: -f1)
  export FENPING_VERSION
  FENPING_VERSION=$(printf '%s' "$target_tag" | cut -d: -f2)
  docker compose up -d --remove-orphans --pull never
  docker compose ps
  if ! wait_for_fenping; then
    [ -n "$journal" ] && update_journal_status "$journal" failed-health
    echo "upgrade failed health checks; run ./restart.sh rollback to restore the previous checkpoint" >&2
    return 1
  fi
  [ -n "$journal" ] && update_journal_status "$journal" active
  prune_upgrade_checkpoints

  if [ "$mode" = "demo" ]; then
    docker exec fenping sh -c 'install -m 0600 /dev/null /etc/crontabs/root'
    local before_demo="fenping-before-demo-$(date -u +%Y%m%d-%H%M%S).tgz"
    docker exec fenping php /opt/fenping/cli.php backup "/var/lib/fenping/backups/$before_demo"
    docker exec fenping php /opt/fenping/cli.php restore /var/lib/fenping/backups/fenping-demo.tgz
    wait_for_fenping
    echo "demo restored; previous state saved to data/backups/$before_demo"
  fi
}
