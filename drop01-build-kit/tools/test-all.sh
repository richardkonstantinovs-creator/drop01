#!/usr/bin/env bash
# Full check of the shippable zip: build → extract into a fresh php:8.2-apache container (shared-hosting simulation)
# → install through install.php (SMTP = local Mailpit) → cron → PHP unit tests → Playwright tests → email arrived.
# Inbox: GreenMail is the store's mailbox (IMAP, plus SMTP on 3025 so the browser test can email the store); tg-mock.php stands in for the Telegram Bot API.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd); PORT=${PORT:-8092}; RUN="$ROOT/tools/cache/zipcheck"; NET=d01net
MAILPIT=public.ecr.aws/supabase/mailpit:v1.22.3
GREENMAIL=greenmail/standalone:2.1.0

./tools/build.sh
echo "→ fresh container from the zip"
docker rm -f d01zip d01mail d01imap d01tg >/dev/null 2>&1 || true
docker network create $NET >/dev/null 2>&1 || true
rm -rf "$RUN"; mkdir -p "$RUN/html"; (cd "$RUN/html" && unzip -q "$ROOT/dist/drop01-deploy.zip")
docker run -d --name d01mail --network $NET -p 8025:8025 $MAILPIT >/dev/null
docker run -d --name d01imap --network $NET -p 3025:3025 -e GREENMAIL_OPTS='-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.auth.disabled' $GREENMAIL >/dev/null
docker run -d --name d01tg --network $NET -p 8094:8081 -v "$ROOT/tools/e2e":/m:ro drop01-php php -S 0.0.0.0:8081 /m/tg-mock.php >/dev/null
docker run -d --name d01zip --network $NET -p $PORT:80 -v "$RUN":/var/www drop01-php sh -c "chown -R www-data /var/www; apache2-foreground" >/dev/null
for i in $(seq 1 30); do curl -s -o /dev/null http://localhost:$PORT/install.php && break; sleep 0.5; done

echo "→ install"
JAR=$(mktemp); B=http://localhost:$PORT
TOK=$(curl -s -c $JAR -b $JAR $B/install.php | grep -o 'name="tok" value="[a-f0-9]*' | cut -d'"' -f4)
curl -s -c $JAR -b $JAR -X POST $B/install.php --data-urlencode "tok=$TOK" --data-urlencode "store=Drop 01" --data-urlencode "name=Test Owner" \
  --data-urlencode "email=owner@example.com" --data-urlencode "password=correct-horse-1" --data-urlencode "password2=correct-horse-1" -d db=sqlite -d demo=1 \
  -d smtp_host=d01mail -d smtp_port=1025 -d smtp_from=hello@drop01.test | grep -q "Your store is installed" && echo "  installed" || { echo "✕ install failed"; exit 1; }
# Mailpit has no TLS: switch security to none for this test
docker exec -w /var/www/html d01zip php -r 'define("DROP01",1); require "app/bootstrap.php"; DB::pdo(); $s=Settings::get("smtp"); $s["secure"]="none"; Settings::set("smtp",$s);'
# Inbox: read the GreenMail mailbox; Telegram calls go to the stand-in API
for i in $(seq 1 40); do docker exec d01zip php -r '$s=@fsockopen("d01imap",3143,$e,$m,1); exit($s?0:1);' && break; sleep 0.5; done
docker exec -w /var/www/html d01zip php -r 'define("DROP01",1); require "app/bootstrap.php"; DB::pdo(); Settings::set("imap", ["enabled"=>true,"host"=>"d01imap","port"=>3143,"secure"=>"none","user"=>"hello@drop01.test","who"=>"all"]); Settings::setSecret("imap_pass","x");
  $c = require CONFIG_FILE; $c["telegram_api"] = "http://d01tg:8081"; file_put_contents(CONFIG_FILE, "<?php\nreturn " . var_export($c, true) . ";\n");'
[ "$(curl -s -o /dev/null -w '%{http_code}' $B/install.php)" = "404" ] && echo "  installer locked" || { echo "✕ installer still reachable"; exit 1; }

echo "→ deny rules"
for p in /data/config.php /app/bootstrap.php /vendor/phpmailer/PHPMailer.php /.htaccess /install.lock.php /uploads/x.php; do
  c=$(curl -s -o /dev/null -w '%{http_code}' $B$p); [ "$c" = 403 ] || [ "$c" = 404 ] || { echo "✕ $p returned $c"; exit 1; }
done; echo "  private paths blocked"

echo "→ cron"
docker exec -u www-data d01zip php /var/www/html/cron.php | sed 's/^/  /'

echo "→ PHP unit tests"
docker run --rm -v "$ROOT":/repo -w /repo drop01-php php tools/tests/run.php | tail -1

echo "→ browser tests"
BASE=$B TG_MOCK=http://localhost:8094 IMAP_SMTP=3025 node tools/e2e/run.mjs

echo "→ Telegram webhook"
SECRET=$(docker exec -w /var/www/html d01zip php -r 'define("DROP01",1); require "app/bootstrap.php"; DB::pdo(); echo Settings::secret("telegram_hook");')
UPD='{"update_id":77,"message":{"message_id":77,"chat":{"id":9100,"type":"private"},"from":{"id":9100,"first_name":"Webhook"},"text":"Hello from the webhook"}}'
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'X-Telegram-Bot-Api-Secret-Token: wrong' -d "$UPD" $B/api/telegram/hook)" = 403 ] || { echo "✕ webhook accepted a wrong secret"; exit 1; }
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Telegram-Bot-Api-Secret-Token: $SECRET" -d "$UPD" $B/api/telegram/hook)" = 200 ] || { echo "✕ webhook refused the right secret"; exit 1; }
docker exec -w /var/www/html d01zip php -r 'define("DROP01",1); require "app/bootstrap.php"; DB::pdo(); exit(DB::val("SELECT COUNT(*) FROM conversation_messages WHERE body = ?", ["Hello from the webhook"]) ? 0 : 1);' \
  && echo "  ✓ wrong secret refused, update with the secret lands in the Inbox" || { echo "✕ webhook message not in the Inbox"; exit 1; }

echo "→ marketing emails (cron)"
# the left bag from the browser test is aged past the hour, then cron sends the one reminder
docker exec -w /var/www/html d01zip php -r 'define("DROP01",1); require "app/bootstrap.php"; DB::pdo(); DB::q("UPDATE carts SET updated_at = ? WHERE consent = 1 AND ordered_at IS NULL", [DB::now(-7200)]);'
docker exec -u www-data d01zip php /var/www/html/cron.php | grep -E "abandoned|back_in_stock|emails" | sed 's/^/  /'

echo "→ emails"
sleep 1
N=$(curl -s http://localhost:8025/api/v1/messages | python3 -c 'import sys,json; m=json.load(sys.stdin)["messages"]; print(sum(1 for x in m if "gift code" in x["Subject"]))')
[ "$N" -ge 1 ] && echo "  ✓ $N gift-code emails delivered to the test inbox (http://localhost:8025)" || { echo "✕ no gift email"; exit 1; }
O=$(curl -s http://localhost:8025/api/v1/messages | python3 -c 'import sys,json; m=json.load(sys.stdin)["messages"]; print(sum(1 for x in m if "confirmed" in x["Subject"] and x.get("Attachments",0) >= 1))')
[ "$O" -ge 1 ] && echo "  ✓ order confirmation with invoice PDF delivered" || { echo "✕ no order confirmation email"; exit 1; }
D=$(curl -s "http://localhost:8025/api/v1/messages?limit=500" | python3 -c 'import sys,json; m=json.load(sys.stdin)["messages"]; s=[x["Subject"] for x in m]; print(sum("on its way" in x for x in s), sum(" delivered " in x for x in s), sum("We missed you" in x for x in s), sum("New delivery time" in x for x in s))')
read -r OUT DEL MISS NEW <<< "$D"
[ "$OUT" -ge 10 ] && [ "$DEL" -ge 1 ] && [ "$MISS" -ge 1 ] && [ "$NEW" -ge 1 ] && echo "  ✓ delivery emails: $OUT on its way, $DEL delivered, $MISS missed you, $NEW new time" || { echo "✕ delivery emails missing ($D)"; exit 1; }
M=$(curl -s "http://localhost:8025/api/v1/messages?limit=500" | python3 -c 'import sys,json; m=json.load(sys.stdin)["messages"]; s=[x["Subject"] for x in m]; print(sum(x.startswith("[Test] E2E broadcast") for x in s), sum(x.startswith("E2E broadcast") for x in s), sum("left something in your bag" in x for x in s), sum(" is back " in x for x in s))')
read -r BT BC AB BIS <<< "$M"
[ "$BT" -ge 1 ] && [ "$BC" -ge 1 ] && [ "$AB" -ge 1 ] && [ "$BIS" -ge 1 ] && echo "  ✓ marketing emails: $BT broadcast test, $BC broadcast, $AB left-bag reminder, $BIS back in stock" || { echo "✕ marketing emails missing ($M)"; exit 1; }
R=$(curl -s "http://localhost:8025/api/v1/messages?limit=500" | python3 -c 'import sys,json; m=json.load(sys.stdin)["messages"]; print(sum(x["Subject"] == "Re: Where is my order?" for x in m))')
[ "$R" -ge 1 ] && echo "  ✓ Inbox email reply delivered (Re: Where is my order?)" || { echo "✕ Inbox email reply missing"; exit 1; }
python3 - <<'PY' || exit 1
import json, struct, urllib.request
get = lambda p: urllib.request.urlopen("http://localhost:8025/api/v1/" + p).read()
msgs = json.loads(get("messages?limit=500"))["messages"]
off = [x for x in msgs if " backup " in x["Subject"] and x.get("Attachments", 0) >= 1]
if not off: print("✕ no off-site backup email"); raise SystemExit(1)
m = json.loads(get("message/" + off[0]["ID"]))
z = get("message/%s/part/%s" % (m["ID"], m["Attachments"][0]["PartID"]))
flags, method = struct.unpack("<HH", z[6:10])
if z[:4] != b"PK\x03\x04" or not flags & 1 or method != 99: print("✕ the off-site copy is not an AES-encrypted zip"); raise SystemExit(1)
print("  ✓ off-site copy emailed as an AES-256 encrypted zip (%d KB)" % (len(z) // 1024))
bag = [x for x in msgs if "left something in your bag" in x["Subject"]]
t = json.loads(get("message/" + bag[0]["ID"]))["Text"] if bag else ""
if "localhost:8092/?bag=" not in t: print("✕ the reminder sent by cron links to the wrong address"); raise SystemExit(1)
print("  ✓ emails sent by cron link to the store's own address")
PY
echo "✓ all checks passed"
