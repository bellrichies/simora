#!/bin/bash
set -eu
umask 077
app_password="$(cat /run/secrets/mysql_app_password)"
[[ "$app_password" =~ ^[a-f0-9]{64}$ ]] || { echo 'Invalid app secret format' >&2; exit 1; }
client_config="$(mktemp)"
trap 'rm -f "$client_config"' EXIT
printf '[client]\nuser=root\npassword=%s\nprotocol=socket\n' "$(cat /run/secrets/mysql_root_password)" > "$client_config"
mysql --defaults-extra-file="$client_config" <<SQL
CREATE USER 'simora_app'@'%' IDENTIFIED BY '${app_password}';
GRANT SELECT, INSERT, UPDATE, DELETE ON simora_dev.* TO 'simora_app'@'%';
SQL
rm -f "$client_config"
trap - EXIT
unset app_password
