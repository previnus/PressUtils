#!/usr/bin/env bash
set -euo pipefail

fixture=$WPUS_FIXTURE
root=$fixture/wordpress
wp() { php "$fixture/wp-cli.phar" --path="$root" "$@"; }

export WPUS_SMOKE_URL=http://127.0.0.1:38142
export WPUS_SMOKE_USER=wpusadmin
export WPUS_SMOKE_PASSWORD
WPUS_SMOKE_PASSWORD=$(openssl rand -hex 24)
echo "::add-mask::$WPUS_SMOKE_PASSWORD"
wp user update "$WPUS_SMOKE_USER" --user_pass="$WPUS_SMOKE_PASSWORD" --quiet
wp option update siteurl "$WPUS_SMOKE_URL"
wp option update home "$WPUS_SMOKE_URL"
wp rewrite structure '/%postname%/' --quiet
wp plugin deactivate woocommerce
mkdir -p "$root/wp-content/mu-plugins"
cp tests/fixture-metrics.php "$root/wp-content/mu-plugins/wpus-fixture-metrics.php"
php -S 127.0.0.1:38142 -t "$root" tests/fixture-router.php > "$fixture/wordpress-server.log" 2>&1 &
server=$!
receiver=''
redis=''
resolver_mounted=0
cleanup() {
  kill "$server" 2>/dev/null || true
  if [[ -n "$receiver" ]]; then kill "$receiver" 2>/dev/null || true; fi
  if [[ -n "$redis" ]]; then kill "$redis" 2>/dev/null || true; fi
  if [[ "$resolver_mounted" == 1 ]]; then sudo umount /etc/resolv.conf; fi
}
trap cleanup EXIT
curl --fail --silent --retry 5 --retry-connrefused "$WPUS_SMOKE_URL" > /dev/null

wp plugin deactivate wp-utility-suite
python3 tests/passive-benchmark.py baseline "$fixture/uncached.json" "$WPUS_SMOKE_URL/"
wp plugin activate wp-utility-suite
if ! wp eval 'exit(ActionScheduler_DataController::is_migration_complete() ? 0 : 1);'; then
  wp action-scheduler migrate
fi
python3 tests/passive-benchmark.py uncached "$fixture/uncached.json" "$WPUS_SMOKE_URL/"

redis-server --bind 127.0.0.1 --port 16379 --save '' --appendonly no > "$fixture/redis.log" 2>&1 &
redis=$!
wp config set WP_REDIS_PORT 16379 --raw --type=constant --quiet
wp plugin install https://downloads.wordpress.org/plugin/redis-cache.3.0.0.zip --activate
wp redis enable
wp eval 'if (!wp_using_ext_object_cache() || !wp_cache_set("probe", "ready", "wpus_fixture", 60)) { WP_CLI::error("Persistent fixture cache is unavailable."); }'
wp eval 'if (wp_cache_get("probe", "wpus_fixture") !== "ready") { WP_CLI::error("Fixture cache did not persist across processes."); }'
wp plugin deactivate wp-utility-suite
python3 tests/passive-benchmark.py baseline "$fixture/warm.json" "$WPUS_SMOKE_URL/"
wp plugin activate wp-utility-suite
failed=0
python3 tests/passive-benchmark.py packaged "$fixture/warm.json" "$WPUS_SMOKE_URL/" || failed=1
python3 tests/http-smoke.py || failed=1
wp option update wpus_outbound_paused 0
python3 tests/browser-smoke.py || failed=1

# Route the public-shaped address inside the disposable runner only. Real DNS and cURL are used.
sudo ip addr add 93.184.215.20/32 dev lo
sudo sysctl -q -w net.ipv4.ip_unprivileged_port_start=443
umask 077
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=PressUtils fixture CA' \
  -keyout "$fixture/ca.key" -out "$fixture/ca.crt" 2> "$fixture/openssl.log"
openssl req -newkey rsa:2048 -nodes -subj '/CN=receiver.example.test' -keyout "$fixture/server.key" \
  -out "$fixture/server.csr" 2>> "$fixture/openssl.log"
printf 'subjectAltName=DNS:receiver.example.test\nextendedKeyUsage=serverAuth\n' > "$fixture/server.ext"
openssl x509 -req -in "$fixture/server.csr" -CA "$fixture/ca.crt" -CAkey "$fixture/ca.key" -CAcreateserial \
  -days 1 -extfile "$fixture/server.ext" -out "$fixture/server.crt"
sudo dnsmasq --no-resolv --no-hosts --bind-interfaces --listen-address=127.0.0.1 \
  --pid-file="$fixture/dnsmasq.pid" --host-record=receiver.example.test,badcert.example.test,93.184.215.20 \
  --host-record=private.example.test,127.0.0.1
printf 'nameserver 127.0.0.1\noptions timeout:1 attempts:1\n' > "$fixture/resolv.conf"
sudo mount --bind "$fixture/resolv.conf" /etc/resolv.conf
resolver_mounted=1

export WPUS_RECEIVER_KEY
WPUS_RECEIVER_KEY=$(openssl rand -hex 32)
echo "::add-mask::$WPUS_RECEIVER_KEY"
python3 tests/fixture-receiver.py "$fixture/server.crt" "$fixture/server.key" &
receiver=$!
curl --silent --retry 5 --retry-connrefused --noproxy '*' --cacert "$fixture/ca.crt" https://receiver.example.test/ > /dev/null
wp config set WPUS_WEBHOOK_ORIGINS "['https://receiver.example.test']" --raw --type=constant --quiet
php -d "curl.cainfo=$fixture/ca.crt" "$fixture/wp-cli.phar" --path="$root" eval-file "$GITHUB_WORKSPACE/tests/receiver.php" || failed=1
exit "$failed"
