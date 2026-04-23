<?php
declare(strict_types=1);
/**
 * Single-file landing — upload this one file to public_html (or your domain folder).
 * Edit $site_name, $tagline, $email below when your full site is ready.
 */
$site_name = 'Akhurath Studio';
$tagline = 'Wedding film editing — color, sound, and story.';
$email = 'info@akhurathstudio.com';
$year = (int) date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="<?php echo htmlspecialchars($site_name . ' — ' . $tagline, ENT_QUOTES, 'UTF-8'); ?>" />
  <title><?php echo htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;1,9..40,400&display=swap" rel="stylesheet" />
  <style>
<?php /* CSS embedded so one PHP file is enough for shared hosting */ ?>
:root {
  --bg: #f7f4ef;
  --bg-card: #fffefb;
  --ink: #1a1614;
  --muted: #5c5650;
  --line: rgba(26, 22, 20, 0.08);
  --accent: #8b6914;
  --accent-soft: rgba(139, 105, 20, 0.12);
  --glow: rgba(212, 175, 120, 0.45);
  --font-serif: "Instrument Serif", Georgia, serif;
  --font-sans: "DM Sans", system-ui, sans-serif;
  --radius: 1rem;
  --ease: cubic-bezier(0.22, 1, 0.36, 1);
}

*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }

body {
  margin: 0;
  min-height: 100vh;
  min-height: 100dvh;
  font-family: var(--font-sans);
  font-size: 1.05rem;
  line-height: 1.6;
  color: var(--ink);
  background: var(--bg);
  -webkit-font-smoothing: antialiased;
  overflow-x: hidden;
}

body.has-cursor { cursor: none; }

a { color: inherit; text-decoration: none; }
button { font: inherit; border: none; background: none; cursor: pointer; color: inherit; }

/* Ambient blob follows mouse */
.ambient {
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 0;
  overflow: hidden;
}
.ambient__blob {
  position: absolute;
  width: min(90vw, 520px);
  height: min(90vw, 520px);
  margin: -260px 0 0 -260px;
  border-radius: 50%;
  background: radial-gradient(circle, var(--glow) 0%, transparent 68%);
  opacity: 0.55;
  filter: blur(2px);
  transform: translate3d(-9999px, -9999px, 0);
  transition: opacity 0.5s var(--ease);
  will-change: transform;
}

/* Custom cursor (desktop) */
.cursor-ring,
.cursor-dot {
  display: none;
  position: fixed;
  left: 0;
  top: 0;
  pointer-events: none;
  z-index: 10000;
  mix-blend-mode: difference;
}
body.has-cursor .cursor-ring,
body.has-cursor .cursor-dot { display: block; }
.cursor-ring {
  width: 44px;
  height: 44px;
  margin: -22px 0 0 -22px;
  border: 1px solid #fff;
  border-radius: 50%;
  transition: width 0.35s var(--ease), height 0.35s var(--ease), margin 0.35s var(--ease), opacity 0.25s;
  will-change: transform;
}
.cursor-ring.is-big {
  width: 72px;
  height: 72px;
  margin: -36px 0 0 -36px;
  opacity: 0.5;
}
.cursor-dot {
  width: 5px;
  height: 5px;
  margin: -2.5px 0 0 -2.5px;
  background: #fff;
  border-radius: 50%;
  will-change: transform;
}

.wrap {
  position: relative;
  z-index: 1;
  max-width: 52rem;
  margin: 0 auto;
  padding: clamp(1.5rem, 4vw, 2.5rem);
  min-height: 100vh;
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding-bottom: 2rem;
}

.brand {
  font-family: var(--font-serif);
  font-size: clamp(1.35rem, 3vw, 1.6rem);
  font-weight: 400;
  letter-spacing: 0.02em;
}

.badge {
  font-size: 0.7rem;
  font-weight: 500;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--muted);
  padding: 0.45rem 0.75rem;
  border: 1px solid var(--line);
  border-radius: 999px;
  background: var(--bg-card);
  transition: border-color 0.35s var(--ease), box-shadow 0.35s var(--ease), transform 0.35s var(--ease);
}
.badge:hover {
  border-color: rgba(139, 105, 20, 0.35);
  box-shadow: 0 12px 40px rgba(26, 22, 20, 0.06);
  transform: translateY(-2px);
}

main { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 2rem 0 3rem; }

.hero-kicker {
  font-size: 0.75rem;
  font-weight: 500;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--accent);
  margin: 0 0 1rem;
}

h1 {
  font-family: var(--font-serif);
  font-size: clamp(2.75rem, 9vw, 4.25rem);
  font-weight: 400;
  line-height: 1.05;
  margin: 0 0 1.25rem;
  letter-spacing: -0.02em;
}

.hero-line {
  display: block;
  transition: transform 0.5s var(--ease);
  will-change: transform;
}
.hero-line em {
  font-style: italic;
  color: var(--accent);
}

.lead {
  margin: 0 0 2.5rem;
  max-width: 28ch;
  color: var(--muted);
  font-size: 1.05rem;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: center;
}

.btn {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 1rem 1.75rem;
  font-size: 0.8rem;
  font-weight: 500;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--bg-card);
  background: var(--ink);
  border-radius: var(--radius);
  overflow: hidden;
  transition: transform 0.35s var(--ease), box-shadow 0.35s var(--ease);
}
.btn::after {
  content: "";
  position: absolute;
  inset: 0;
  background: linear-gradient(105deg, transparent 35%, rgba(255,255,255,0.2) 50%, transparent 65%);
  transform: translateX(-120%);
  transition: transform 0.65s var(--ease);
}
.btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 18px 40px rgba(26, 22, 20, 0.15);
}
.btn:hover::after { transform: translateX(120%); }

.link-email {
  position: relative;
  font-size: 0.95rem;
  color: var(--muted);
  padding: 0.35rem 0;
}
.link-email::after {
  content: "";
  position: absolute;
  left: 0;
  bottom: 0;
  width: 100%;
  height: 1px;
  background: currentColor;
  transform: scaleX(0.35);
  transform-origin: left;
  transition: transform 0.4s var(--ease), color 0.25s;
}
.link-email:hover { color: var(--ink); }
.link-email:hover::after {
  transform: scaleX(1);
  background: var(--accent);
}

.cards {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.75rem;
  margin-top: 3.5rem;
}

.card {
  position: relative;
  padding: 1.35rem 1.15rem;
  background: var(--bg-card);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  transition: border-color 0.4s var(--ease), transform 0.45s var(--ease), box-shadow 0.45s var(--ease);
  transform-style: preserve-3d;
  overflow: hidden;
}
.card::before {
  content: "";
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 3px;
  background: linear-gradient(90deg, var(--accent), #c9a227, var(--accent));
  background-size: 200% 100%;
  opacity: 0;
  transition: opacity 0.3s;
}
.card:hover {
  border-color: rgba(139, 105, 20, 0.25);
  box-shadow: 0 20px 50px rgba(26, 22, 20, 0.08);
}
.card:hover::before {
  opacity: 1;
  animation: shimmer-bar 1.8s linear infinite;
}
@keyframes shimmer-bar {
  0% { background-position: 0% 50%; }
  100% { background-position: 200% 50%; }
}

.card h2 {
  font-family: var(--font-serif);
  font-size: 1.35rem;
  font-weight: 400;
  margin: 0 0 0.35rem;
}
.card p {
  margin: 0;
  font-size: 0.82rem;
  line-height: 1.5;
  color: var(--muted);
}

footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding-top: 2rem;
  border-top: 1px solid var(--line);
  font-size: 0.8rem;
  color: var(--muted);
}

.hint {
  font-size: 0.72rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  opacity: 0.75;
}

@media (max-width: 720px) {
  .cards { grid-template-columns: 1fr; }
  body.has-cursor { cursor: auto; }
  body.has-cursor .cursor-ring,
  body.has-cursor .cursor-dot { display: none !important; }
}

@media (prefers-reduced-motion: reduce) {
  html { scroll-behavior: auto; }
  .btn::after,
  .card:hover::before { animation: none !important; transition: none; }
  .hero-line, .card, .btn, .badge { transition: none; }
  .ambient__blob { display: none; }
}
  </style>
</head>
<body>
  <div class="ambient" aria-hidden="true"><div class="ambient__blob" id="blob"></div></div>
  <div class="cursor-ring" id="cursorRing" aria-hidden="true"></div>
  <div class="cursor-dot" id="cursorDot" aria-hidden="true"></div>

  <div class="wrap">
    <header>
      <a href="/" class="brand magnetic" data-magnetic><?php echo htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'); ?></a>
      <span class="badge">Full site — soon</span>
    </header>

    <main>
      <p class="hero-kicker"><?php echo htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8'); ?></p>
      <h1 id="heroHeading">
        <span class="hero-line" data-depth="0.06">Clarity for your</span>
        <span class="hero-line" data-depth="0.1"><em>wedding films.</em></span>
      </h1>
      <p class="lead">We’re polishing something special. Until then, this page is live — move your mouse, hover the cards, and say hello when you’re ready.</p>
      <div class="actions">
        <a class="btn magnetic" data-magnetic href="mailto:<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">Write to us</a>
        <a class="link-email magnetic" data-magnetic href="mailto:<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></a>
      </div>

      <div class="cards">
        <article class="card" data-tilt>
          <h2>Edit</h2>
          <p>Pacing and emotion, cut for the big screen.</p>
        </article>
        <article class="card" data-tilt>
          <h2>Color</h2>
          <p>Warm, consistent grades across every scene.</p>
        </article>
        <article class="card" data-tilt>
          <h2>Sound</h2>
          <p>Vows, music, and room tone — balanced.</p>
        </article>
      </div>
    </main>

    <footer>
      <p>© <?php echo $year; ?> <?php echo htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'); ?></p>
      <p class="hint">Hover anywhere — animations on</p>
    </footer>
  </div>

  <script>
(function () {
  'use strict';
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var coarse = window.matchMedia('(pointer: coarse)').matches;
  var desktop = !coarse && window.innerWidth > 720;

  var blob = document.getElementById('blob');
  var ring = document.getElementById('cursorRing');
  var dot = document.getElementById('cursorDot');
  var mx = 0, my = 0, rx = 0, ry = 0, dx = 0, dy = 0;
  var raf = null;

  if (desktop && !reduced) document.body.classList.add('has-cursor');

  function onMove(e) {
    mx = e.clientX;
    my = e.clientY;
    if (blob && !reduced) {
      blob.style.transform = 'translate3d(' + mx + 'px,' + my + 'px,0)';
    }
    parallax(e.clientX, e.clientY);
  }

  function parallax(cx, cy) {
    if (reduced) return;
    var nx = cx / window.innerWidth - 0.5;
    var ny = cy / window.innerHeight - 0.5;
    document.querySelectorAll('[data-depth]').forEach(function (el) {
      var d = parseFloat(el.getAttribute('data-depth') || '0.08') || 0.08;
      el.style.transform = 'translate3d(' + nx * d * 36 + 'px,' + ny * d * 28 + 'px,0)';
    });
  }

  function lerp(a, b, t) { return a + (b - a) * t; }

  function tick() {
    if (!desktop || reduced) { raf = null; return; }
    rx = lerp(rx, mx, 0.14);
    ry = lerp(ry, my, 0.14);
    dx = lerp(dx, mx, 0.32);
    dy = lerp(dy, my, 0.32);
    if (ring) ring.style.transform = 'translate3d(' + rx + 'px,' + ry + 'px,0)';
    if (dot) dot.style.transform = 'translate3d(' + dx + 'px,' + dy + 'px,0)';
    raf = requestAnimationFrame(tick);
  }

  window.addEventListener('mousemove', onMove);
  if (desktop && !reduced) raf = requestAnimationFrame(tick);

  var hoverSel = 'a, button, .card, .badge';
  document.addEventListener('mouseover', function (e) {
    if (!ring || !desktop) return;
    if (e.target.closest(hoverSel)) ring.classList.add('is-big');
  });
  document.addEventListener('mouseout', function (e) {
    if (!ring || !desktop) return;
    if (!e.relatedTarget || !e.relatedTarget.closest(hoverSel)) ring.classList.remove('is-big');
  });

  function magnetic(e) {
    var el = e.currentTarget;
    var r = el.getBoundingClientRect();
    el.style.transform = 'translate3d(' + (e.clientX - r.left - r.width / 2) * 0.2 + 'px,' + (e.clientY - r.top - r.height / 2) * 0.2 + 'px,0)';
  }
  function magneticReset(e) {
    e.currentTarget.style.transform = '';
  }
  if (desktop && !reduced) {
    document.querySelectorAll('.magnetic').forEach(function (el) {
      el.addEventListener('mousemove', magnetic);
      el.addEventListener('mouseleave', magneticReset);
    });
  }

  function tilt(e) {
    var el = e.currentTarget;
    var r = el.getBoundingClientRect();
    var px = (e.clientX - r.left) / r.width - 0.5;
    var py = (e.clientY - r.top) / r.height - 0.5;
    el.style.transform = 'translateY(-6px) perspective(600px) rotateY(' + px * 8 + 'deg) rotateX(' + (-py * 8) + 'deg)';
  }
  function tiltReset(e) {
    e.currentTarget.style.transform = '';
  }
  if (!reduced) {
    document.querySelectorAll('[data-tilt]').forEach(function (el) {
      el.addEventListener('mousemove', tilt);
      el.addEventListener('mouseleave', tiltReset);
    });
  }
})();
  </script>
</body>
</html>
