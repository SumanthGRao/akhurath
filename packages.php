<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$cssPath = __DIR__ . '/assets/css/packages.css';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
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
      <p class="page-packages__subtitle">Choose a tier, then unlock your studio rate when you are ready.</p>
    </header>

    <div class="page-packages__grid" id="packages-grid">
      <div class="page-packages__card-wrap">
        <article class="page-packages__card" data-package="standard">
          <h2 class="page-packages__tier">Standard</h2>
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
          <h2 class="page-packages__tier">Premium</h2>
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
          <h2 class="page-packages__tier">Elite</h2>
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

    <p class="page-packages__footer">
      <a href="https://www.akhurathstudio.com/">www.akhurathstudio.com</a>
    </p>
  </main>

  <script>
    (function () {
      var root = document.body;
      var grid = document.getElementById('packages-grid');
      if (!grid) return;

      var cards = Array.prototype.slice.call(grid.querySelectorAll('.page-packages__card'));
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

      grid.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest && e.target.closest('[data-offer-btn]');
        if (!btn || btn.disabled) return;
        var card = btn.closest('.page-packages__card');
        if (card) revealOfferForCard(card);
      });

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runIntro);
      } else {
        runIntro();
      }
    })();
  </script>
</body>
</html>
