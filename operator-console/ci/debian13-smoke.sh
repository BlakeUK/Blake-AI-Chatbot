#!/usr/bin/env bash
# Runs inside a clean Debian 13 (trixie) container: installs the built .deb, checks its libraries, starts it under a virtual screen
# and checks it opened its window. Writes a screenshot and the app's log to /out.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq /deb/*.deb xvfb xauth dbus-x11 imagemagick libgl1-mesa-dri x11-utils fonts-dejavu-core ca-certificates >/dev/null
echo "== installed on $(. /etc/os-release; echo "$PRETTY_NAME")"
dpkg -s blake-uk-operator-console | grep -E '^(Package|Version|Architecture|Depends):' | cut -c1-400
BIN=$(dpkg -L blake-uk-operator-console | grep -E '^/usr/bin/' | head -1)
echo "binary: $BIN"
missing=$(ldd "$BIN" | grep 'not found' || true)
if [ -n "$missing" ]; then echo "MISSING LIBRARIES:"; echo "$missing"; exit 1; fi
echo "all the libraries it needs are present on Debian 13"

export WEBKIT_DISABLE_SANDBOX_THIS_IS_DANGEROUS=1 WEBKIT_DISABLE_COMPOSITING_MODE=1 LIBGL_ALWAYS_SOFTWARE=1 GDK_BACKEND=x11
cat > /tmp/session.sh <<'INNER'
#!/usr/bin/env bash
"$1" > /out/app.log 2>&1 &
pid=$!
for i in $(seq 1 30); do
  sleep 1
  kill -0 "$pid" 2>/dev/null || { echo "THE APP EXITED AFTER $i SECONDS"; cat /out/app.log; exit 1; }
  if xwininfo -root -tree 2>/dev/null | grep -q "Blake UK Support Desk"; then found=$i; break; fi
done
[ -n "${found:-}" ] || { echo "THE APP RAN BUT NEVER OPENED ITS WINDOW"; cat /out/app.log; exit 1; }
sleep 6
kill -0 "$pid" 2>/dev/null || { echo "THE APP EXITED AFTER OPENING ITS WINDOW"; cat /out/app.log; exit 1; }
import -window root /out/screen.png || true
echo "the window 'Blake UK Support Desk' opened after ${found} seconds and the app was still running 6 seconds later"
kill "$pid" 2>/dev/null || true
INNER
chmod +x /tmp/session.sh
xvfb-run -a -s "-screen 0 1300x850x24" dbus-run-session -- /tmp/session.sh "$BIN"
echo "== the app's own log"; head -20 /out/app.log || true
