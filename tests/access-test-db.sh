#!/usr/bin/env bash
set -eu
umask 077
action="$1"
database="$2"
[[ "$database" =~ ^simora_access_test_[a-f0-9]{16}$ ]] || exit 2
client_config="$(mktemp)"
trap 'rm -f "$client_config"' EXIT
printf '[client]\nuser=root\npassword=%s\nprotocol=socket\n' "$(cat /run/secrets/mysql_root_password)" > "$client_config"
if [ "$action" = create ]; then
    mysql --defaults-extra-file="$client_config" <<SQL
CREATE DATABASE \`$database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON \`$database\`.* TO 'simora_migrator'@'%';
GRANT SELECT,INSERT,UPDATE,DELETE ON \`$database\`.* TO 'simora_app'@'%';
SQL
elif [ "$action" = drop ]; then
    mysql --defaults-extra-file="$client_config" <<SQL
DROP DATABASE \`$database\`;
REVOKE ALL PRIVILEGES ON \`$database\`.* FROM 'simora_migrator'@'%';
REVOKE ALL PRIVILEGES ON \`$database\`.* FROM 'simora_app'@'%';
SQL
else
    exit 2
fi
