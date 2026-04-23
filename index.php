<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = SITE_NAME . ' — Wedding film editing';
$metaDescription = SITE_TAGLINE;
$bodyClass = 'page-home';

$services = require AKH_ROOT . '/config/services.php';
$portfolio = require AKH_ROOT . '/config/portfolio.php';
$clientsConfig = require AKH_ROOT . '/config/clients.php';
$marquee_names = $clientsConfig['marquee_names'];
$bottom_logos = $clientsConfig['bottom_logos'];

$aboutMapEmbed = 'https://www.google.com/maps?q=' . rawurlencode('Bangalore, Karnataka, India') . '&hl=en&z=11&output=embed';

/** First existing file wins (mp4/webm preferred over mov for size + Safari). */
$heroVideoUrl = null;
foreach (['assets/video/hero-background.mp4', 'assets/video/hero-background.webm', 'assets/video/hero-background.mov'] as $heroRel) {
    if (is_file(AKH_ROOT . '/' . $heroRel)) {
        $heroVideoUrl = base_path($heroRel);
        break;
    }
}

$aboutReelMp4Disk = AKH_ROOT . '/assets/video/about-reel.mp4';
$aboutReelM4vDisk = AKH_ROOT . '/assets/video/about-reel.m4v';
$aboutReelHasVideo = is_file($aboutReelMp4Disk) || is_file($aboutReelM4vDisk);
$aboutReelMp4 = is_file($aboutReelMp4Disk) ? base_path('assets/video/about-reel.mp4') : '';
$aboutReelM4v = is_file($aboutReelM4vDisk) ? base_path('assets/video/about-reel.m4v') : '';

$bottom_logo_items = [];
foreach ($bottom_logos as $bl) {
    $src = client_logo_src_for_entry($bl);
    $bottom_logo_items[] = [
        'name' => (string) ($bl['name'] ?? ''),
        'src' => $src,
        'img_class' => trim((string) ($bl['img_class'] ?? '')),
        'has_image' => $src !== null,
    ];
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main">
    <section class="hero hero--video<?php echo $heroVideoUrl !== null ? ' hero--has-video' : ' hero--no-video'; ?>">
      <div class="hero__media" aria-hidden="true">
        <video
          class="hero__video"
          muted
          playsinline
          loop
          preload="none"
          <?php if ($heroVideoUrl !== null): ?>data-hero-src="<?php echo h($heroVideoUrl); ?>"<?php endif; ?>
        ></video>
        <div class="video-busy<?php echo $heroVideoUrl === null ? ' is-done' : ''; ?>" data-video-busy aria-hidden="true">
          <span class="video-busy__ring"></span>
        </div>
        <div class="hero__scrim"></div>
      </div>
      <div class="hero__inner">
        <p class="hero__eyebrow"><?php echo h(SITE_TAGLINE); ?></p>
        <h1 class="hero__title">
          <span class="hero__line">Wedding films,</span>
          <span class="hero__line hero__line--accent">edited with care.</span>
        </h1>
        <p class="hero__lead">
          <span class="hero__lead-line">You capture the day; we sculpt the story — edit, grade, and sound.</span>
          <span class="hero__lead-line">For wedding studios and filmmakers worldwide.</span>
        </p>
        <div class="hero__actions">
          <a class="btn btn--primary" href="<?php echo h(base_path('contact.php')); ?>">Get in touch</a>
          <a class="btn btn--ghost" href="<?php echo h(base_path('index.php')); ?>#work">View work</a>
        </div>
      </div>
    </section>

    <section class="band band--accent" aria-hidden="true">
      <div class="marquee">
        <div class="marquee__inner">
          <?php for ($m = 0; $m < 2; $m++): ?>
            <?php foreach ($marquee_names as $name): ?>
              <span><?php echo h($name); ?></span><span class="marquee__dot">·</span>
            <?php endforeach; ?>
          <?php endfor; ?>
        </div>
      </div>
    </section>

    <section id="about" class="section about-section" aria-labelledby="about-heading">
      <div class="about-hero-split">
        <div class="about-hero-split__copy">
          <header class="about-section__intro">
            <h2 id="about-heading" class="section__kicker">About</h2>
            <p class="section__big">A post-production studio for <em>wedding films</em> — built in Bangalore, trusted by teams across India and beyond.</p>
          </header>

          <div class="about-story section__body">
            <p>Our journey began in <strong>2024</strong> in <strong>Bangalore, Karnataka</strong>, as a small edit room obsessed with story rhythm and skin-tone consistency. Word travelled quickly among wedding filmmakers: we don’t just cut footage — we sculpt narrative arcs, polish dialogue and ambience, and deliver grades that feel intentional frame to frame.</p>
            <p>Since then we’ve partnered with <strong>20+</strong> studios and brands, and shipped work on <strong>over a thousand</strong> individual films and deliverables — teasers, highlights, reels, traditional cuts, and long-form features. Every project taught us something; every client sharpened our process.</p>
            <p>Whether you’re down the road in Bengaluru or briefing us from another time zone, you get the same craft: organised timelines, honest communication, and pictures that honour the day your couples lived.</p>
          </div>
        </div>
        <aside class="about-hero-split__reel" aria-label="Studio showreel">
          <div class="about-reel-frame<?php echo $aboutReelHasVideo ? '' : ' about-reel-frame--static'; ?>">
            <?php if ($aboutReelHasVideo): ?>
              <video
                class="about-reel__video"
                muted
                playsinline
                webkit-playsinline
                autoplay
                loop
                preload="metadata"
                width="400"
                height="711"
              >
                <?php if ($aboutReelMp4 !== ''): ?>
                  <source src="<?php echo h($aboutReelMp4); ?>#t=0.001" type="video/mp4" />
                <?php endif; ?>
                <?php if ($aboutReelM4v !== ''): ?>
                  <source src="<?php echo h($aboutReelM4v); ?>#t=0.001" type="video/mp4" />
                <?php endif; ?>
              </video>
              <div class="video-busy video-busy--compact" data-video-busy aria-hidden="true">
                <span class="video-busy__ring"></span>
              </div>
            <?php else: ?>
              <div class="about-reel-placeholder" aria-hidden="true">
                <span class="about-reel-placeholder__glyph" aria-hidden="true"></span>
              </div>
            <?php endif; ?>
            <p class="about-reel__label" aria-hidden="true">Reel</p>
          </div>
        </aside>
      </div>

      <div class="about-stats" aria-label="Studio milestones">
        <article class="about-stat-showpiece about-stat-showpiece--founder" tabindex="0">
          <span class="about-stat-showpiece__shine" aria-hidden="true"></span>
          <div class="about-stat-showpiece__body">
            <div class="about-stat-showpiece__icon" aria-hidden="true">
              <svg viewBox="0 0 64 64" width="40" height="40" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="32" cy="32" r="26" stroke="currentColor" stroke-width="1.5" opacity="0.4"/><path d="M32 14v6M32 44v6M14 32h6M44 32h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </div>
            <p class="about-stat-showpiece__value" aria-label="Founded twenty twenty-four">2024</p>
            <p class="about-stat-showpiece__ribbon"><span>Founded</span></p>
          </div>
        </article>
        <article class="about-stat-showpiece about-stat-showpiece--clients" tabindex="0">
          <span class="about-stat-showpiece__shine" aria-hidden="true"></span>
          <div class="about-stat-showpiece__body">
            <div class="about-stat-showpiece__icon" aria-hidden="true">
              <svg viewBox="0 0 64 64" width="40" height="40" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M24 28c0-4 2.5-7 8-7s8 3 8 7v2c0 2-1 3-2.5 3h-11c-1.5 0-2.5-1-2.5-3v-2z" stroke="currentColor" stroke-width="1.5"/><path d="M16 50c0-5.5 5.5-9 16-9s16 3.5 16 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity="0.65"/><circle cx="46" cy="24" r="5" stroke="currentColor" stroke-width="1.5"/></svg>
            </div>
            <p class="about-stat-showpiece__value" aria-label="Over 20 studio partners">
              <span class="about-count" data-count-up="20" data-count-suffix="+" aria-hidden="true">0</span>
            </p>
            <p class="about-stat-showpiece__ribbon"><span>Studio partners</span></p>
          </div>
        </article>
        <article class="about-stat-showpiece about-stat-showpiece--projects" tabindex="0">
          <span class="about-stat-showpiece__shine" aria-hidden="true"></span>
          <div class="about-stat-showpiece__body">
            <div class="about-stat-showpiece__icon" aria-hidden="true">
              <svg viewBox="0 0 64 64" width="40" height="40" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="12" y="20" width="40" height="26" rx="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M20 33l6-5v10l-6-5zm16-6h12v14H36V27z" fill="currentColor" opacity="0.35"/></svg>
            </div>
            <p class="about-stat-showpiece__value" aria-label="Over one thousand projects and deliverables">
              <span class="about-count" data-count-up="1000" data-count-suffix="+" data-count-format="comma" aria-hidden="true">0</span>
            </p>
            <p class="about-stat-showpiece__ribbon"><span>Projects &amp; deliverables</span></p>
          </div>
        </article>
      </div>

      <div class="about-loc">
        <div class="about-loc__mapwrap">
          <iframe
            class="about-loc__map"
            title="Map — Bangalore, Karnataka, India"
            src="<?php echo h($aboutMapEmbed); ?>"
            width="600"
            height="450"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            allowfullscreen
          ></iframe>
        </div>
        <div class="about-loc__content">
          <h3 class="about-loc__title">Bangalore, Karnataka</h3>
          <p class="about-loc__text">Our base is in India’s garden city — ideal for local handoffs and long edit days — while our pipeline stays built for filmmakers anywhere on the map.</p>
          <p class="about-loc__link"><a class="text-link" href="https://www.google.com/maps/search/?api=1&amp;query=<?php echo rawurlencode('Bangalore, Karnataka, India'); ?>" target="_blank" rel="noopener noreferrer">Open in Google Maps</a></p>
        </div>
      </div>
    </section>

    <section id="services" class="section section--dark">
      <div class="section__head">
        <h2 class="section__title">Services</h2>
      </div>
      <div class="service-grid">
        <?php foreach ($services as $svc): ?>
          <?php
          $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($svc['slug'] ?? '')));
            $href = base_path('contact.php') . ($slug !== '' ? '?topic=' . rawurlencode($slug) : '');
            $detail = trim((string) ($svc['detail'] ?? ''));
            ?>
          <div class="service-card-wrap">
            <a class="service-card service-card--glass" href="<?php echo h($href); ?>">
              <span class="service-card__mark" aria-hidden="true"><span class="service-card__glyph">✧</span></span>
              <h3><?php echo h($svc['title'] ?? ''); ?></h3>
              <p class="service-card__lead"><?php echo h($svc['text'] ?? ''); ?></p>
              <?php if ($detail !== ''): ?>
                <div class="service-card__detail">
                  <div class="service-card__detail-inner">
                    <p class="service-card__detail-text"><?php echo h($detail); ?></p>
                  </div>
                </div>
              <?php endif; ?>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section id="work" class="section">
      <div class="section__head">
        <h2 class="section__title">Selected work</h2>
      </div>
      <div class="work-grid">
        <?php
        $workIndex = 0;
        foreach ($portfolio as $item):
            $id = trim((string) ($item['youtube_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $title = (string) ($item['title'] ?? 'Project');
            $thumb = 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/maxresdefault.jpg';
            $watch = 'https://www.youtube.com/watch?v=' . rawurlencode($id);
            $embed = 'https://www.youtube.com/embed/' . rawurlencode($id) . '?rel=0';
            $desc = trim((string) ($item['description'] ?? ''));
            ?>
          <article class="work-card" style="--reveal: <?php echo (int) $workIndex; ?>">
            <div class="work-card__viewport">
              <button type="button" class="work-card__thumb" aria-label="Play <?php echo h($title); ?> — inline in this card">
                <img src="<?php echo h($thumb); ?>" alt="<?php echo h($title); ?> — video thumbnail" loading="<?php echo $workIndex < 2 ? 'eager' : 'lazy'; ?>" width="1280" height="720" decoding="async" onerror="this.onerror=null;this.src=<?php echo json_encode('https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg'); ?>" />
                <span class="work-card__shine" aria-hidden="true"></span>
                <span class="work-card__play" aria-hidden="true"></span>
              </button>
              <div class="work-card__embed" hidden aria-hidden="true">
                <iframe title="<?php echo h($title); ?>" src="" data-src="<?php echo h($embed); ?>" loading="lazy" allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"></iframe>
              </div>
              <button type="button" class="work-card__close" hidden aria-label="Stop and close video">×</button>
            </div>
            <div class="work-card__body">
              <h3><?php echo h($title); ?></h3>
              <p class="work-card__credit"><?php echo h($item['credit'] ?? ''); ?></p>
              <?php if ($desc !== ''): ?>
                <p class="work-card__desc"><?php echo h($desc); ?></p>
              <?php endif; ?>
              <p class="work-card__external"><a href="<?php echo h($watch); ?>" target="_blank" rel="noopener noreferrer">Open on YouTube</a></p>
            </div>
          </article>
        <?php
            ++$workIndex;
        endforeach; ?>
      </div>
    </section>

    <section id="clients" class="section section--muted">
      <div class="section__head">
        <h2 class="section__title">Studios &amp; partners</h2>
      </div>
      <?php if ($bottom_logo_items !== []): ?>
        <?php
        $logoGridClass = count($bottom_logo_items) === 5
          ? 'client-list client-list--logos client-list--featured-five'
          : 'client-list client-list--logos client-list--logos-flow';
        ?>
        <ul class="<?php echo h($logoGridClass); ?>">
          <?php foreach ($bottom_logo_items as $item): ?>
            <li>
              <?php if (($item['has_image'] ?? false) && ($item['src'] ?? '') !== ''): ?>
                <?php
                $logoClass = 'client-list__logo';
                if (($item['img_class'] ?? '') !== '') {
                    $logoClass .= ' ' . $item['img_class'];
                }
                ?>
                <img
                  class="<?php echo h($logoClass); ?>"
                  src="<?php echo h((string) $item['src']); ?>"
                  alt="<?php echo h($item['name']); ?>"
                  width="300"
                  height="200"
                  loading="lazy"
                  decoding="async"
                />
              <?php else: ?>
                <span class="client-list__nameplate"><?php echo h($item['name']); ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="section cta-block">
      <h2 class="section__title">Tell us about your film</h2>
      <p class="cta-block__text">Share your timeline, deliverables, and references — we’ll respond with availability and next steps.</p>
      <a class="btn btn--primary" href="<?php echo h(base_path('contact.php')); ?>">Get in touch</a>
    </section>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
