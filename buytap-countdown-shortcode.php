<?php
/**
 * Plugin Name: BuyTap Countdown Shortcode
 * Description: Shortcode [buytap_countdown] to show a countdown and temporarily reveal a form window using Luxon. Works with Elementor forms.
 * Version: 1.0.0
 * Author: Philip Osir
 */

if (!defined('ABSPATH')) exit;

add_action('init', function () {
  add_shortcode('buytap_countdown', 'buytap_countdown_shortcode');
});


/**
 * Shortcode: [buytap_countdown]
 *
 * Attributes:
 * - form_selector:   CSS selector for the form container to show/hide (default "#buy-form")
 * - tz:              IANA timezone (default "Africa/Nairobi")
 * - mode:            "test" or "schedule" ("test" = opens in test_wait_seconds; "schedule" = use open_times)
 * - test_wait_seconds: seconds to wait before opening (default 60; only when mode="test")
 * - show_for_seconds: how long to keep form visible once opened (default 3600 = 1 hour)
 * - open_times:      comma separated HH:MM times (24h) for opening windows (default "09:00,19:00"; used when mode="schedule")
 * - heading:         heading text above the timer (default "Next Token Sale Starts In:")
 */
function buytap_countdown_shortcode($atts = []) {
  $opts = [
  'mode'              => get_option('buytap_mode','test'),
  'test_wait_seconds' => (int) get_option('buytap_test_wait_seconds', 60),
  'show_for_seconds'  => (int) get_option('buytap_show_for_seconds', 3600),
  'open_times'        => get_option('buytap_open_times','09:00,19:00'),
  'tz'                => get_option('buytap_timezone','Africa/Nairobi'),
  'open_dates'        => get_option('buytap_open_dates',''), // optional
];

$a = shortcode_atts([
  'form_selector'     => '#buy-form',
  'tz'                => $opts['tz'],
  'mode'              => $opts['mode'],
  'test_wait_seconds' => $opts['test_wait_seconds'],
  'show_for_seconds'  => $opts['show_for_seconds'],
  'open_times'        => $opts['open_times'],
  'open_dates'        => $opts['open_dates'],
	'key_suffix'        => '',
//   'heading'           => 'Next Shares Sale Starts In:',
// 	   'heading'          => '⏳ Launch Day: 27th October : ',
  'heading'  		  => '⏳BidWave Official Launch — 18th May:',
//   'heading'           => '⏳ “Launching BuyTap on 9/9 — Get Ready!”:',
//    'heading'          => '⏳ Launch Day: 9th September :',
// 	'heading' => '🚀 Be Ready: Trading Opens 9:00 AM',
// 	'heading' => '⏳ Be Ready: Trading Opens 9:00 AM',
], $atts, 'buytap_countdown');


  // Enqueue Luxon + font once
  wp_enqueue_script(
    'luxon',
    'https://cdn.jsdelivr.net/npm/luxon@3.3.0/build/global/luxon.min.js',
    [],
    null,
    true
  );
  wp_enqueue_style(
    'buytap-countdown-oswald',
    'https://fonts.googleapis.com/css2?family=Oswald:wght@400;700&display=swap',
    [],
    null
  );

  // Unique IDs so multiple shortcodes can coexist
  $uid = 'btc_' . wp_generate_password(8, false, false);
  $wrap_id   = "custom-countdown-$uid";
  $timer_id  = "timer-$uid";
  $hours_id  = "hours-$uid";
  $mins_id   = "minutes-$uid";
  $secs_id   = "seconds-$uid";

	
	// Add this before ob_start()
	$server_now_iso = gmdate('c'); // e.g., "2025-08-26T12:34:56+00:00"
  // Data attributes for the script
  $data_attrs = sprintf(
  ' data-form-selector="%s" data-tz="%s" data-mode="%s" data-test-wait="%d" data-show-for="%d" data-open-times="%s" data-open-dates="%s" data-uid="%s" data-key-suffix="%s" data-server-now="%s" ',
  esc_attr($a['form_selector']),
  esc_attr($a['tz']),
  esc_attr($a['mode']),
  (int)$a['test_wait_seconds'],
  (int)$a['show_for_seconds'],
  esc_attr($a['open_times']),
  esc_attr($a['open_dates']),
  esc_attr($uid),
  esc_attr($a['key_suffix']),
  esc_attr($server_now_iso)
);

	// Make the REST URL available to the inline script
	wp_register_script('buytap-now-stub', false); // a dummy handle to localize against
	wp_enqueue_script('buytap-now-stub');
	wp_localize_script('buytap-now-stub', 'buytapAjax', [
	  'nowUrl' => esc_url_raw( rest_url('buytap/v1/now') ),
	]);


  ob_start(); ?>
  <div class="buytap-countdown-wrapper" <?= $data_attrs; ?>>
  <div id="<?= esc_attr($wrap_id); ?>">
    <div style="text-align:center;">
      <h2><?= esc_html($a['heading']); ?></h2>
    </div>

    <div id="<?= esc_attr($timer_id); ?>" role="timer" aria-live="polite" class="buytap-timer">
		<div class="time-block">
			<div class="time-number" id="days-<?= esc_attr($uid); ?>">00</div>
			<div class="time-label">Days</div>
		</div>
		<div class="time-block">
        <div class="time-number" id="<?= esc_attr($hours_id); ?>">00</div>
        <div class="time-label">Hours</div>
      </div>
      <div class="time-block">
        <div class="time-number" id="<?= esc_attr($mins_id); ?>">00</div>
        <div class="time-label">Minutes</div>
      </div>
      <div class="time-block">
        <div class="time-number" id="<?= esc_attr($secs_id); ?>">00</div>
        <div class="time-label">Seconds</div>
      </div>
    </div>
    </div>

    <!-- Critical pre-hide: hide the target form before JS decides -->
    <style id="bt-prehide-<?= esc_attr($uid); ?>">
      <?= esc_html($a['form_selector']); ?> { display: none !important; }
    </style>

    <style>
      /* Scoped styles (minimal bleed) */
      #<?= $wrap_id; ?> {
        text-align: center;
        padding: 20px;
        /* ↓ Add this ↓ */
        visibility: hidden; /* prevent timer flash before JS decides */

      }
      #<?= $timer_id; ?> {
        display: flex;
        justify-content: center;
        gap: 20px;
        align-items: center;
      }
      #<?= $wrap_id; ?> .time-block { text-align: center; }
      #<?= $wrap_id; ?> .time-number {
        font-size: 80px;
        font-family: 'Oswald', sans-serif;
        font-weight: bold;
        color: #00feaf; /* neon green countdown digits */
      }
      #<?= $wrap_id; ?> .time-label {
        font-size: 15px;
        margin-top: 40px;
        color: #fff;
      }
		/* Robust visibility helpers (avoid Elementor/theme conflicts) */
		.bty-hidden { display: none !important; }
		.bty-open   { display: block; }

    </style>

    <script>
(function () {
  // --- Storage wrapper: never crash if localStorage is blocked (Safari/Private/etc.)
  const storage = (() => {
    try {
      const t = '__bt_ls_check__';
      window.localStorage.setItem(t, '1');
      window.localStorage.removeItem(t);
      return window.localStorage;
    } catch (e) {
      return {
        getItem: () => null,
        setItem: () => {},
        removeItem: () => {}
      };
    }
  })();

	
  // We’ll run the logic whether DOMContentLoaded already fired or not
  function buytapBoot() {
    if (!window.luxon) return;
    const DateTime = window.luxon.DateTime;

    const root = document.querySelector('[data-uid="<?= esc_js($uid); ?>"]');
    if (!root) return;

    // --- Read config
    const tz           = root.getAttribute('data-tz') || 'Africa/Nairobi';
    const mode         = root.getAttribute('data-mode') || 'test';
    const testWait     = Number(root.getAttribute('data-test-wait')) || 60;
    const showFor      = Number(root.getAttribute('data-show-for'))  || 3600;
    const openTimes    = (root.getAttribute('data-open-times') || '09:00,19:00')
                          .split(',').map(s => s.trim()).filter(Boolean);

    const openDatesRaw = (root.getAttribute('data-open-dates') || '').trim();
    const openDates    = openDatesRaw
      ? openDatesRaw.split(/\n|,/).map(s => s.trim()).filter(Boolean)
      : [];

    // --- Server-anchored time (prevents user clock shenanigans)
    const serverNowISO = root.getAttribute('data-server-now');
    let baseServerUTC  = serverNowISO
      ? DateTime.fromISO(serverNowISO, { zone: 'utc' })
      : DateTime.utc();

    const perfStart = performance.now();
    function nowUTC() {
      const elapsedMs = performance.now() - perfStart;
      return baseServerUTC.plus({ milliseconds: elapsedMs });
    }
    function nowInZone() {
      return nowUTC().setZone(tz);
    }

    // --- Elements
    const wrapEl    = document.getElementById('<?= esc_js($wrap_id); ?>');
    const daysEl    = document.getElementById('days-<?= esc_js($uid); ?>');
    const hoursEl   = document.getElementById('<?= esc_js($hours_id); ?>');
    const minutesEl = document.getElementById('<?= esc_js($mins_id); ?>');
    const secondsEl = document.getElementById('<?= esc_js($secs_id); ?>');

    // Show/hide helpers with classes (robust against theme CSS)
    function setShown(el, shown) {
      if (!el) return;
      el.classList.toggle('bty-hidden', !shown);
      el.classList.toggle('bty-open',   shown);
    }

    // Form handling
    const formSelector = root.getAttribute('data-form-selector') || '#buy-form';
    const formEl = document.querySelector(formSelector);

    function lockForm() {
      if (!formEl) return;
      formEl.querySelectorAll('input, select, textarea, button').forEach(el => {
        el.disabled = true;
        el.classList.add('buytap-disabled');
      });
    }
    function unlockForm() {
      if (!formEl) return;
      formEl.querySelectorAll('input, select, textarea, button').forEach(el => {
        el.disabled = false;
        el.classList.remove('buytap-disabled');
      });
    }

    // Initial state to prevent flicker
    setShown(formEl, false);
    setShown(wrapEl, true);

    // --- Keys (per-instance + cross-tab)
    const selectorKey = (formSelector || '#buy-form').replace(/[^a-z0-9_-]/gi, '_');
    const pageKey     = (location.pathname || '/').replace(/[^a-z0-9_-]/gi, '_');
    const keySuffix   = (root.getAttribute('data-key-suffix') || '').replace(/[^a-z0-9_-]/gi, '_');
    const LS_KEY      = `buyFormHideAt_${selectorKey}_${pageKey}${keySuffix ? '_' + keySuffix : ''}`;

    const G_KEY  = 'buytapSale_hideAt';
    const G_STAT = 'buytapSale_status';
    const G_NEXT = 'buytapSale_nextOpenMs';
    const G_CFG  = 'buytapSale_cfgHash';

    function hash32(s){
      let h = 0;
      for (let i=0;i<s.length;i++){ h = ((h<<5)-h) + s.charCodeAt(i); h |= 0; }
      return String(h);
    }
    const cfgString = JSON.stringify({
      tz, mode, testWait, showFor,
      openTimes,
      openDates
    });
    const cfgHash = hash32(cfgString);

    // Attach storage listener once
    if (!window.buytapStorageListenerAttached) {
      window.addEventListener('storage', function (e) {
        try {
          if (e.key === G_KEY) {
            const v = Number(storage.getItem(G_KEY));
            openUntilMs = Number.isFinite(v) ? v : null;
            requestAnimationFrame(updateCountdown);
          }
          if (e.key === G_NEXT || e.key === G_CFG) {
            if (storage.getItem(G_CFG) === cfgHash) {
              const n = Number(storage.getItem(G_NEXT));
              if (Number.isFinite(n)) {
                targetTime = DateTime.fromMillis(n, { zone: 'utc' }).setZone(tz);
                requestAnimationFrame(updateCountdown);
              }
            }
          }
        } catch (_) {/* ignore */}
      });
      window.buytapStorageListenerAttached = true;
    }

    // --- Scheduling helpers (same logic you had, kept intact)
    function parseExplicitDate(entry, tz, fallbackTimeHHMM) {
      const hasTime = /\d{2}:\d{2}$/.test(entry);
      let dt;
      if (hasTime) {
        const iso = entry.replace(' ', 'T');
        dt = DateTime.fromISO(iso, { zone: tz });
      } else {
        const [hh, mm] = (fallbackTimeHHMM || '09:00').split(':').map(Number);
        dt = DateTime.fromISO(entry, { zone: tz }).set({ hour: hh, minute: mm, second: 0, millisecond: 0 });
      }
      return dt && dt.isValid ? dt : null;
    }
    function nextExplicitOpen() {
      if (!openDates.length) return null;
      const now = nowInZone();
      let next = null;
      for (const entry of openDates) {
        const dt = parseExplicitDate(entry, tz, openTimes[0] || '09:00');
        if (!dt) continue;
        if (dt > now && (!next || dt < next)) next = dt;
      }
      return next;
    }
    function nextScheduledOpen() {
      const now = nowInZone();
      const today = now.startOf('day');
      let next = null;
      for (let t of openTimes) {
        const [hh, mm] = t.split(':').map(Number);
        const cand = today.set({ hour: hh, minute: mm, second: 0, millisecond: 0 });
        if (cand > now && (!next || cand < next)) next = cand;
      }
      if (!next) {
        const [hh, mm] = (openTimes[0] || '09:00').split(':').map(Number);
        next = today.plus({ days: 1 }).set({ hour: hh, minute: mm, second: 0, millisecond: 0 });
      }
      return next;
    }
    function getNextDropTimeLocal() {
      if (mode === 'schedule') {
        const explicit = nextExplicitOpen();
        if (explicit) return explicit;
        return nextScheduledOpen();
      }
      return nowInZone().plus({ seconds: testWait });
    }

    function loadOrPublishNextTarget() {
      const storedCfg  = storage.getItem(G_CFG);
      const storedNext = Number(storage.getItem(G_NEXT));
      if (storedCfg === cfgHash && Number.isFinite(storedNext)) {
        const candidate = DateTime.fromMillis(storedNext, { zone: 'utc' }).setZone(tz);
        if (candidate > nowInZone()) return candidate; // still future
      }
      const localTarget = getNextDropTimeLocal();
      storage.setItem(G_CFG, cfgHash);
      storage.setItem(G_NEXT, String(localTarget.toUTC().toMillis()));
      return localTarget;
    }

    let targetTime = loadOrPublishNextTarget();
	let openUntilMs = null; // UTC ms deadline for hiding
	let formOpen = false;

	// 🔥 Detect if we are currently inside an active open window (even on refresh/new login)
	function getLastScheduledOpen() {
	  const now = nowInZone();
	  const today = now.startOf('day');
	  let last = null;

	  for (let t of openTimes) {
		const [hh, mm] = t.split(':').map(Number);
		const cand = today.set({ hour: hh, minute: mm, second: 0, millisecond: 0 });
		if (cand <= now && (!last || cand > last)) last = cand;
	  }

	  // If none today, fallback to yesterday’s last
	  if (!last) {
		const [hh, mm] = (openTimes[openTimes.length - 1] || '09:00').split(':').map(Number);
		last = today.minus({ days: 1 }).set({ hour: hh, minute: mm, second: 0, millisecond: 0 });
	  }
	  return last;
	}

	const lastOpen = getLastScheduledOpen();
	const openWindowStart = lastOpen.toUTC().toMillis();
	const openWindowEnd   = openWindowStart + (showFor * 1000);
	const nowMs = nowUTC().toMillis();

	if (nowMs >= openWindowStart && nowMs < openWindowEnd) {
	  // ✅ We are inside the current window
	  openUntilMs = openWindowEnd;
	  setShown(formEl, true);
	  unlockForm();
	  setShown(wrapEl, false);

	  storage.setItem(LS_KEY, String(openUntilMs));
	  storage.setItem(LS_KEY + "_status", "open");
	  storage.setItem(G_KEY, String(openUntilMs));
	  storage.setItem(G_STAT, "open");

	  console.log("Boot detected active window — reopening form", {
		now: new Date(nowMs).toISOString(),
		openWindowStart: new Date(openWindowStart).toISOString(),
		openWindowEnd: new Date(openWindowEnd).toISOString()
	  });
	}



	let closeSyncTimer = null; // 🔒 AJAX close sync handle

	  
	  async function syncCloseWithServer() {
		  try {
			if (!openUntilMs) return; // nothing to check
			const url = (window.buytapAjax && buytapAjax.nowUrl) ? buytapAjax.nowUrl : null;
			if (!url) return;

			const res = await fetch(url, { cache: 'no-store' });
			if (!res.ok) return;
			const data = await res.json();

			// 🔥 Always use UTC timestamp from server
			const serverUtcNowMs = (Number(data.utc) || 0) * 1000;

			// 🔎 Debug log
			console.log("Server sync check", {
			  serverNow: new Date(serverUtcNowMs).toISOString(),
			  hideAt: new Date(openUntilMs).toISOString(),
			  diffSeconds: Math.round((openUntilMs - serverUtcNowMs) / 1000)
			});

			// 🔥 Compare in UTC, with 2s safety buffer
			if (serverUtcNowMs >= (openUntilMs - 2000)) {
			  // Force close immediately, and broadcast via storage
			  storage.removeItem(G_KEY);
			  storage.removeItem(G_STAT);
			  storage.removeItem(LS_KEY);
			  storage.removeItem(LS_KEY + "_status");
			  resetToCountdown();
			}
		  } catch (e) {
			console.warn("Sync error", e);
		  }
		}


	  
   function showFormThenHideLater() {
	  if (formOpen) return;
	  formOpen = true;

	  setShown(formEl, true);
	  unlockForm();
	  setShown(wrapEl, false);

	  // 🔥 FIX: make hideAt UTC explicit (no timezone drift)
	  const hideAtUTC = nowUTC().plus({ seconds: showFor }).toUTC();
	  openUntilMs = hideAtUTC.toMillis();

	  // Save globally
	  storage.setItem(LS_KEY, String(openUntilMs));
	  storage.setItem(LS_KEY + "_status", "open");
	  storage.setItem(G_KEY, String(openUntilMs));
	  storage.setItem(G_STAT, "open");

	  // 🔥 FIX: add 2s buffer so timer never cuts short
	  setTimeout(resetToCountdown, (showFor * 1000) + 2000);

	  // 🔎 DEBUG log (can remove later)
	  console.log("Form opened", {
		now: nowUTC().toISO(),
		hideAt: hideAtUTC.toISO(),
		seconds: showFor
	  });

	  // Start sync with server every 3s
	  if (!closeSyncTimer) {
		closeSyncTimer = setInterval(syncCloseWithServer, 3000);
	  }
	  if (!window._btCloseSyncBound) {
		window.addEventListener('focus', syncCloseWithServer);
		document.addEventListener('visibilitychange', () => {
		  if (document.visibilityState === 'visible') syncCloseWithServer();
		});
		window._btCloseSyncBound = true;
	  }
	}


    function resetToCountdown() {
		if (closeSyncTimer) {
		  clearInterval(closeSyncTimer);
		  closeSyncTimer = null;
		}
      formOpen = false;
      setShown(formEl, false);
      lockForm();
      setShown(wrapEl, true);
		
	  const prehide = document.getElementById('bt-prehide-<?= esc_js($uid); ?>');
      if (prehide) prehide.remove();
      if (wrapEl) wrapEl.style.visibility = 'visible';

      storage.removeItem(LS_KEY);
      storage.removeItem(LS_KEY + "_status");
      storage.removeItem(G_KEY);
      storage.removeItem(G_STAT);

      openUntilMs = null;
      targetTime = loadOrPublishNextTarget();
      requestAnimationFrame(updateCountdown);
    }

    function updateCountdown() {
      const now = nowInZone();

      // Adopt global next target if present
      if (storage.getItem(G_CFG) === cfgHash) {
        const globNext = Number(storage.getItem(G_NEXT));
        if (Number.isFinite(globNext)) {
          const candidate = DateTime.fromMillis(globNext, { zone: 'utc' }).setZone(tz);
          if (candidate.toMillis() !== targetTime.toMillis()) {
            targetTime = candidate;
          }
        }
      }

      // Keep form open if global/local deadline is in the future
      const gVal    = Number(storage.getItem(G_KEY));
      const gStatus = storage.getItem(G_STAT);
      if (Number.isFinite(gVal) && gStatus === "open") {
        openUntilMs = gVal;
      }

      const nowUTCms = nowUTC().toMillis();
      if (openUntilMs && nowUTCms < openUntilMs) {
        setShown(formEl, true);
        setShown(wrapEl, false);
        requestAnimationFrame(updateCountdown);
        return;
      }
      // 🔥 Use UTC + 2s buffer to avoid early close
		if (openUntilMs && nowUTCms >= (openUntilMs - 2000)) {
			console.log("Countdown loop closing", {
			  now: new Date(nowUTCms).toISOString(),
			  hideAt: new Date(openUntilMs).toISOString(),
			  diff: (openUntilMs - nowUTCms) / 1000
			});

			storage.removeItem(G_KEY);
			storage.removeItem(G_STAT);
			storage.removeItem(LS_KEY);
			storage.removeItem(LS_KEY + "_status");
			openUntilMs = null;
		}


      // Respect local instance persisted state
      const storedVal    = Number(storage.getItem(LS_KEY));
      const storedStatus = storage.getItem(LS_KEY + "_status");
      if (Number.isFinite(storedVal) && storedStatus === "open") {
        openUntilMs = storedVal;
        if (nowUTCms < openUntilMs) {
          setShown(formEl, true);
          setShown(wrapEl, false);
          requestAnimationFrame(updateCountdown);
          return;
        } else {
          storage.removeItem(LS_KEY);
          storage.removeItem(LS_KEY + "_status");
          openUntilMs = null;
        }
      }

      // Render countdown
      const diff = targetTime.diff(now, ['days','hours','minutes','seconds']).toObject();
      const d = String(Math.max(0, Math.floor(diff.days    || 0))).padStart(2,'0');
      const h = String(Math.max(0, Math.floor(diff.hours   || 0))).padStart(2,'0');
      const m = String(Math.max(0, Math.floor(diff.minutes || 0))).padStart(2,'0');
      const s = String(Math.max(0, Math.floor(diff.seconds || 0))).padStart(2,'0');

      if (daysEl) {
        if (parseInt(d, 10) > 0) {
          daysEl.textContent = d;
          daysEl.parentElement.style.display = "block";
        } else {
          daysEl.parentElement.style.display = "none";
        }
      }
      if (hoursEl)   hoursEl.textContent   = h;
      if (minutesEl) minutesEl.textContent = m;
      if (secondsEl) secondsEl.textContent = s;

      // Time reached -> open window
      if (
        (diff.days || 0)    <= 0 &&
        (diff.hours || 0)   <= 0 &&
        (diff.minutes || 0) <= 0 &&
        (diff.seconds || 0) <= 0
      ) {
        showFormThenHideLater();
        return;
      }

      requestAnimationFrame(updateCountdown);
    }

    // Initial visibility (avoid flicker if LS says "open")
    const nowStartUTCms    = nowUTC().toMillis();
    const storedHideAt     = Number(storage.getItem(LS_KEY));
    const storedStatusInit = storage.getItem(LS_KEY + "_status");
    if (Number.isFinite(storedHideAt) && storedStatusInit === "open" && nowStartUTCms < storedHideAt) {
      setShown(formEl, true);
      unlockForm();
      setShown(wrapEl, false);
      openUntilMs = storedHideAt;
		
	  const prehide = document.getElementById('bt-prehide-<?= esc_js($uid); ?>');
	  if (prehide) prehide.remove();
	  if (wrapEl) wrapEl.style.visibility = 'visible';
    } else {
      setShown(formEl, false);
      lockForm();
      setShown(wrapEl, true);
		
	  const prehide = document.getElementById('bt-prehide-<?= esc_js($uid); ?>');
	  if (prehide) prehide.remove();
	  if (wrapEl) wrapEl.style.visibility = 'visible';
    }

    // Adopt any pre-published global target immediately
    if (storage.getItem(G_CFG) === cfgHash) {
      const n = Number(storage.getItem(G_NEXT));
      if (Number.isFinite(n)) {
        targetTime = DateTime.fromMillis(n, { zone:'utc' }).setZone(tz);
      }
    }

    // Start loop
    requestAnimationFrame(updateCountdown);

    // Drift guard: if laptop sleeps for long, nudge base time every 10 min
    setInterval(() => { baseServerUTC = DateTime.utc(); }, 10 * 60 * 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', buytapBoot, { once: true });
  } else {
    buytapBoot();
  }
})();
</script>

  </div>
  <?php
  return ob_get_clean();
}
