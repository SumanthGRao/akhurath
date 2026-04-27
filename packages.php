<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

/** Internal list rates for custom builder (server recalculates; never shown per line on the page). */
$customRates = [
    'teaser' => ['label' => 'Cinematic teaser', 'inr' => 5000],
    'highlights_5' => ['label' => '5 min highlights film', 'inr' => 10000],
    'highlights_7' => ['label' => '7 min highlights film', 'inr' => 15000],
    'reel' => ['label' => 'Instagram / social reel', 'inr' => 1200],
    'traditional' => ['label' => 'Traditional video (up to 1 hr)', 'inr' => 5000],
];
$customRateKeys = array_keys($customRates);

$customErrors = [];
$customSent = isset($_GET['custom_sent']) && (string) $_GET['custom_sent'] === '1';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['form_id'] ?? '') === 'packages_custom') {
    $hp = trim((string) ($_POST['website'] ?? ''));
    if ($hp !== '') {
        $customErrors[] = 'Something went wrong. Please try again.';
    }

    $name = trim((string) ($_POST['cust_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['cust_email'] ?? '')));
    $phone = trim((string) ($_POST['cust_phone'] ?? ''));
    $notes = trim((string) ($_POST['cust_notes'] ?? ''));
    $estimateOk = (string) ($_POST['estimate_confirmed'] ?? '') === '1';

    if ($name === '' || mb_strlen($name) > 200) {
        $customErrors[] = 'Please enter your name (max 200 characters).';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
        $customErrors[] = 'Please enter a valid email address.';
    }
    if ($phone === '' || mb_strlen($phone) > 40) {
        $customErrors[] = 'Please enter your phone number.';
    } elseif (!preg_match('/^[0-9+\s().-]{7,40}$/u', $phone)) {
        $customErrors[] = 'Please enter a valid phone number.';
    }
    if (mb_strlen($notes) > 2000) {
        $customErrors[] = 'Notes are too long (max 2000 characters).';
    }
    if (!$estimateOk) {
        $customErrors[] = 'Please use “Show my estimate” first, then send your request.';
    }

    $qtyByKey = [];
    foreach ($customRateKeys as $key) {
        $q = (int) ($_POST['qty_' . $key] ?? 0);
        if ($q < 0) {
            $q = 0;
        }
        if ($q > 50) {
            $q = 50;
        }
        $qtyByKey[$key] = $q;
    }

    $lines = [];
    $totalInr = 0;
    foreach ($customRates as $key => $meta) {
        $q = $qtyByKey[$key];
        if ($q <= 0) {
            continue;
        }
        $inr = (int) $meta['inr'];
        $line = $q * $inr;
        $totalInr += $line;
        $lines[] = [
            'label' => (string) $meta['label'],
            'qty' => $q,
            'unit_inr' => $inr,
            'line_inr' => $line,
        ];
    }
    if ($totalInr <= 0) {
        $customErrors[] = 'Select at least one service with quantity greater than zero.';
    }

    if ($customErrors === []) {
        $submittedAt = gmdate('c');
        $dataDir = AKH_ROOT . '/data';
        $outDir = $dataDir . '/custom-package-enquiries';
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0755, true);
        }
        $block = str_repeat('=', 72) . "\n"
            . 'Submitted (UTC): ' . $submittedAt . "\n"
            . 'Name: ' . $name . "\n"
            . 'Email: ' . $email . "\n"
            . 'Phone: ' . $phone . "\n"
            . 'Reference list total (INR): ' . (string) $totalInr . "\n\n";
        foreach ($lines as $row) {
            $block .= $row['label'] . ' × ' . (string) $row['qty'] . ' = ₹' . (string) $row['line_inr'] . "\n";
        }
        $block .= "\nNotes:\n" . ($notes !== '' ? $notes : '—') . "\n";
        $written = false;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $suffix = bin2hex(random_bytes(4));
            $filePath = $outDir . '/' . gmdate('Y-m-d_His') . '_' . $suffix . '.txt';
            if (is_file($filePath)) {
                continue;
            }
            $written = @file_put_contents($filePath, $block, LOCK_EX) !== false;
            if ($written) {
                break;
            }
        }
        if (!$written) {
            $customErrors[] = 'We could not save your request. Please try again or email us directly.';
        } else {
            require_once AKH_ROOT . '/includes/site-notify-mail.php';
            akh_site_mail_custom_package_studio($name, $email, $phone, $notes, $lines, $totalInr);
            header('Location: ' . base_path('packages.php') . '?custom_sent=1', true, 303);
            exit;
        }
    }
}

$cssPath = __DIR__ . '/assets/css/packages.css';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
$customRatesJson = json_encode(
    $customRates,
    JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
);
$customReopenAfterError = $customErrors !== [] && (string) ($_POST['estimate_confirmed'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Services &amp; Pricing — Wedding video editing | <?php echo h(SITE_NAME); ?></title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500&family=Source+Sans+3:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?php echo h(base_path('assets/css/packages.css')); ?>?v=<?php echo h($cssVer); ?>">
</head>
<body class="page-packages">
  <div class="page-packages__bg" aria-hidden="true"></div>
  <div class="page-packages__grain" aria-hidden="true"></div>
  <main class="page-packages__main">
    <header class="page-packages__head">
      <p class="page-packages__kicker">Services &amp; Pricing</p>
      <h1 class="page-packages__title">Wedding video editing</h1>
      <p class="page-packages__subtitle">Choose a tier and unlock your rate, or build a custom list below — we will quote package discounts on request.</p>
    </header>

    <div class="page-packages__grid" id="packages-grid">
      <div class="page-packages__card-wrap">
        <article class="page-packages__card" data-package="standard">
          <h2 class="page-packages__tier page-packages__tier--standard">Standard</h2>
          <ul class="page-packages__list">
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>1 Cinematic teaser</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>1 Cinematic film (up to 5 min)</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>1 Traditional video (up to 1 hr)</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>1 free re-work</span>
            </li>
            <li class="page-packages__row page-packages__row--excluded" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
              <span>Instagram reel</span>
            </li>
            <li class="page-packages__row page-packages__row--excluded" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
              <span>Customer assistance</span>
            </li>
            <li class="page-packages__row page-packages__row--excluded" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
              <span>Cloud storage</span>
            </li>
          </ul>
          <div class="page-packages__price-block" data-price-block aria-live="polite">
            <span class="page-packages__rate-hint">List rate</span>
            <div class="page-packages__price-old" data-price-old>
              <span class="page-packages__price-old-inner">
                ₹ 20K
                <span class="page-packages__strike" data-strike aria-hidden="true"></span>
              </span>
            </div>
            <div class="page-packages__price-new" data-price-new>₹ 14K</div>
          </div>
          <div class="page-packages__cta-stack">
            <button type="button" class="page-packages__cta page-packages__cta--offer" data-offer-btn aria-expanded="false">Get offer</button>
            <a class="page-packages__cta page-packages__cta--contact" data-contact-cta href="<?php echo h(base_path('contact.php')); ?>">Contact us</a>
          </div>
        </article>
      </div>

      <div class="page-packages__card-wrap">
        <article class="page-packages__card" data-package="premium">
          <h2 class="page-packages__tier page-packages__tier--premium">Premium</h2>
          <ul class="page-packages__list">
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>2 Cinematic teasers</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>2 Cinematic films (up to 5 min each)</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>2 Traditional videos (up to 1 hr each)</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>2 free re-works</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>2 Instagram reels</span>
            </li>
            <li class="page-packages__row page-packages__row--excluded" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
              <span>Customer assistance</span>
            </li>
            <li class="page-packages__row page-packages__row--excluded" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
              <span>Cloud storage</span>
            </li>
          </ul>
          <div class="page-packages__price-block" data-price-block aria-live="polite">
            <span class="page-packages__rate-hint">List rate</span>
            <div class="page-packages__price-old" data-price-old>
              <span class="page-packages__price-old-inner">
                ₹ 32K
                <span class="page-packages__strike" data-strike aria-hidden="true"></span>
              </span>
            </div>
            <div class="page-packages__price-new" data-price-new>₹ 25K</div>
          </div>
          <div class="page-packages__cta-stack">
            <button type="button" class="page-packages__cta page-packages__cta--offer" data-offer-btn aria-expanded="false">Get offer</button>
            <a class="page-packages__cta page-packages__cta--contact" data-contact-cta href="<?php echo h(base_path('contact.php')); ?>">Contact us</a>
          </div>
        </article>
      </div>

      <div class="page-packages__card-wrap">
        <article class="page-packages__card" data-package="elite">
          <h2 class="page-packages__tier page-packages__tier--elite">Elite</h2>
          <ul class="page-packages__list">
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>2 Cinematic teasers</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>2 Cinematic films (up to 7 min each)</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>3 Traditional videos (up to 1 hr each)</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>3 free re-works</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>3 Instagram reels</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>Customer assistance</span>
            </li>
            <li class="page-packages__row" data-line>
              <svg class="page-packages__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
              <span>Cloud storage</span>
            </li>
          </ul>
          <div class="page-packages__price-block" data-price-block aria-live="polite">
            <span class="page-packages__rate-hint">List rate</span>
            <div class="page-packages__price-old" data-price-old>
              <span class="page-packages__price-old-inner">
                ₹ 45K
                <span class="page-packages__strike" data-strike aria-hidden="true"></span>
              </span>
            </div>
            <div class="page-packages__price-new" data-price-new>₹ 35K</div>
          </div>
          <div class="page-packages__cta-stack">
            <button type="button" class="page-packages__cta page-packages__cta--offer" data-offer-btn aria-expanded="false">Get offer</button>
            <a class="page-packages__cta page-packages__cta--contact" data-contact-cta href="<?php echo h(base_path('contact.php')); ?>">Contact us</a>
          </div>
        </article>
      </div>
    </div>

    <section class="page-packages__custom" id="build-your-own" aria-labelledby="custom-heading">
      <h2 class="page-packages__custom-title" id="custom-heading">Build your own</h2>
      <p class="page-packages__custom-lead">Pick the deliverables you need. We do not show a running total — when you are done, reveal one reference total, then send us the list for package pricing and discounts.</p>

      <?php if ($customSent): ?>
        <p class="page-packages__banner page-packages__banner--ok" role="status">Thank you — we received your custom build. We will get back to you soon.</p>
      <?php elseif ($customErrors !== []): ?>
        <div class="page-packages__banner page-packages__banner--err" role="alert">
          <?php foreach ($customErrors as $err): ?>
            <p><?php echo h($err); ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form class="page-packages__custom-form" method="post" action="<?php echo h(base_path('packages.php')); ?>#build-your-own" id="custom-package-form" autocomplete="on">
        <input type="hidden" name="form_id" value="packages_custom">
        <input type="hidden" name="estimate_confirmed" id="estimate_confirmed" value="<?php echo h((string) ($_POST['estimate_confirmed'] ?? ($customReopenAfterError ? '1' : '0'))); ?>">
        <p class="page-packages__hp" aria-hidden="true">
          <label>Leave blank <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>
        </p>

        <div class="page-packages__custom-grid">
          <?php foreach ($customRates as $key => $meta): ?>
            <label class="page-packages__custom-row">
              <span class="page-packages__custom-label"><?php echo h($meta['label']); ?></span>
              <input
                class="page-packages__custom-qty"
                type="number"
                name="qty_<?php echo h($key); ?>"
                id="qty_<?php echo h($key); ?>"
                min="0"
                max="50"
                step="1"
                value="<?php echo h((string) (int) ($_POST['qty_' . $key] ?? 0)); ?>"
                inputmode="numeric"
                data-custom-key="<?php echo h($key); ?>"
              >
            </label>
          <?php endforeach; ?>
        </div>

        <div class="page-packages__custom-actions">
          <button type="button" class="page-packages__btn page-packages__btn--secondary" id="custom-show-estimate">Show my estimate</button>
        </div>

        <div class="page-packages__custom-reveal" id="custom-reveal"<?php echo $customReopenAfterError ? '' : ' hidden'; ?>>
          <p class="page-packages__custom-total-label">Reference list build</p>
          <p class="page-packages__custom-total" id="custom-total-display" aria-live="polite">₹ 0</p>
          <p class="page-packages__custom-total-note">Indicative total from list rates — we often bundle and discount. Tell us how to reach you.</p>

          <div class="page-packages__custom-contact">
            <label class="page-packages__field">
              <span>Name <span class="page-packages__req">*</span></span>
              <input type="text" name="cust_name" required maxlength="200" value="<?php echo h((string) ($_POST['cust_name'] ?? '')); ?>">
            </label>
            <label class="page-packages__field">
              <span>Email <span class="page-packages__req">*</span></span>
              <input type="email" name="cust_email" required maxlength="120" value="<?php echo h((string) ($_POST['cust_email'] ?? '')); ?>">
            </label>
            <label class="page-packages__field">
              <span>Phone <span class="page-packages__req">*</span></span>
              <input type="tel" name="cust_phone" required maxlength="40" value="<?php echo h((string) ($_POST['cust_phone'] ?? '')); ?>">
            </label>
            <label class="page-packages__field">
              <span>Notes <span class="page-packages__opt">(optional)</span></span>
              <textarea name="cust_notes" rows="4" maxlength="2000" placeholder="Wedding date, delivery timeline, references…"><?php echo h((string) ($_POST['cust_notes'] ?? '')); ?></textarea>
            </label>
            <button type="submit" class="page-packages__btn page-packages__btn--primary">Request discount — send to studio</button>
          </div>
        </div>
      </form>
    </section>

    <script type="application/json" id="custom-rates-json"><?php echo $customRatesJson; ?></script>

    <p class="page-packages__footer">
      <a href="https://www.akhurathstudio.com/">www.akhurathstudio.com</a>
    </p>
  </main>

  <script>
    (function () {
      var root = document.body;
      var grid = document.getElementById('packages-grid');
      var cards = grid ? Array.prototype.slice.call(grid.querySelectorAll('.page-packages__card')) : [];
      var lineStepMs = 88;
      var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

      function maxLines() {
        var m = 0;
        cards.forEach(function (c) {
          var n = c.querySelectorAll('[data-line]').length;
          if (n > m) m = n;
        });
        return m;
      }

      function revealLinesForRow(rowIndex) {
        cards.forEach(function (card) {
          var lines = card.querySelectorAll('[data-line]');
          var el = lines[rowIndex];
          if (el) el.classList.add('page-packages__row--in');
        });
      }

      function runIntro() {
        if (reduced) {
          root.classList.add('page-packages--cards-in');
          cards.forEach(function (card) {
            card.querySelectorAll('[data-line]').forEach(function (el) {
              el.classList.add('page-packages__row--in');
            });
          });
          return;
        }

        root.classList.add('page-packages--cards-in');
        var rows = maxLines();
        var t = 280;
        for (var r = 0; r < rows; r++) {
          (function (row) {
            setTimeout(function () {
              revealLinesForRow(row);
            }, t + row * lineStepMs);
          })(r);
        }
      }

      function revealOfferForCard(card) {
        if (card.classList.contains('page-packages__card--offer-done')) return;
        if (card.classList.contains('page-packages__card--revealing')) return;

        var strike = card.querySelector('[data-strike]');
        var newEl = card.querySelector('[data-price-new]');
        var hint = card.querySelector('.page-packages__rate-hint');
        var offerBtn = card.querySelector('[data-offer-btn]');
        var contact = card.querySelector('[data-contact-cta]');

        card.classList.add('page-packages__card--revealing');
        if (offerBtn) offerBtn.setAttribute('aria-expanded', 'true');
        if (hint) {
          hint.textContent = 'Your rate';
          hint.classList.add('page-packages__rate-hint--live');
        }

        function finish() {
          card.classList.remove('page-packages__card--revealing');
          card.classList.add('page-packages__card--offer-done');
          if (!reduced) card.classList.add('page-packages__card--offer-pop');
          if (offerBtn) offerBtn.disabled = true;
          if (contact) {
            contact.classList.add('page-packages__cta--visible');
            try {
              contact.focus({ preventScroll: true });
            } catch (err) {
              contact.focus();
            }
          }
          setTimeout(function () {
            card.classList.remove('page-packages__card--offer-pop');
          }, 600);
        }

        if (reduced) {
          if (strike) {
            strike.classList.add('page-packages__strike--draw');
            strike.style.transform = 'scaleX(1)';
          }
          if (newEl) newEl.classList.add('page-packages__price-new--show');
          finish();
          return;
        }

        setTimeout(function () {
          if (strike) strike.classList.add('page-packages__strike--draw');
        }, 120);

        setTimeout(function () {
          if (newEl) newEl.classList.add('page-packages__price-new--show');
        }, 520);

        setTimeout(finish, 1100);
      }

      if (grid) {
        grid.addEventListener('click', function (e) {
          var btn = e.target && e.target.closest && e.target.closest('[data-offer-btn]');
          if (!btn || btn.disabled) return;
          var card = btn.closest('.page-packages__card');
          if (card) revealOfferForCard(card);
        });
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runIntro);
      } else {
        runIntro();
      }

      var jsonEl = document.getElementById('custom-rates-json');
      var revealEl = document.getElementById('custom-reveal');
      var totalEl = document.getElementById('custom-total-display');
      var showBtn = document.getElementById('custom-show-estimate');
      var estFlag = document.getElementById('estimate_confirmed');
      var customForm = document.getElementById('custom-package-form');

      function parseRates() {
        if (!jsonEl || !jsonEl.textContent) return null;
        try {
          return JSON.parse(jsonEl.textContent);
        } catch (e) {
          return null;
        }
      }

      function sumCustomSelection(rates) {
        var total = 0;
        Object.keys(rates).forEach(function (key) {
          var inp = document.getElementById('qty_' + key);
          if (!inp) return;
          var q = parseInt(String(inp.value), 10);
          if (isNaN(q) || q < 0) q = 0;
          if (q > 50) q = 50;
          var row = rates[key];
          if (row && typeof row.inr === 'number') total += q * row.inr;
        });
        return total;
      }

      function formatInr(n) {
        return '₹ ' + n.toLocaleString('en-IN');
      }

      function wireCustomBuilder() {
        if (!showBtn || !revealEl || !totalEl || !estFlag || !customForm) return;
        var rates = parseRates();
        if (!rates) return;

        function applyTotal() {
          var t = sumCustomSelection(rates);
          totalEl.textContent = formatInr(t);
        }

        showBtn.addEventListener('click', function () {
          var t = sumCustomSelection(rates);
          if (t <= 0) {
            window.alert('Please add at least one service (quantity 1 or more) before showing your estimate.');
            return;
          }
          applyTotal();
          revealEl.hidden = false;
          estFlag.value = '1';
        });

        if (!revealEl.hidden) {
          applyTotal();
        }
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireCustomBuilder);
      } else {
        wireCustomBuilder();
      }
    })();
  </script>
</body>
</html>
