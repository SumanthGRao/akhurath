<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? SITE_NAME;
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="description" content="<?php echo h($metaDescription ?? SITE_TAGLINE); ?>" />
  <title><?php echo h($pageTitle); ?></title>
  <?php
    $akhBrandDir = AKH_ROOT . '/assets/images/brand';
    $akhFav192 = $akhBrandDir . '/akhurath-favicon-192.png';
    $akhFav48 = $akhBrandDir . '/akhurath-favicon-48.png';
    $akhApple = $akhBrandDir . '/apple-touch-icon.png';
    if (is_file($akhFav192) && is_file($akhFav48)) {
        $v192 = (string) filemtime($akhFav192);
        $v48 = (string) filemtime($akhFav48);
        $href192 = base_path('assets/images/brand/akhurath-favicon-192.png') . '?v=' . rawurlencode($v192);
        $href48 = base_path('assets/images/brand/akhurath-favicon-48.png') . '?v=' . rawurlencode($v48);
        ?>
  <link rel="icon" type="image/png" sizes="48x48" href="<?php echo h($href48); ?>" />
  <link rel="icon" type="image/png" sizes="192x192" href="<?php echo h($href192); ?>" />
        <?php
        if (is_file($akhApple)) {
            $va = (string) filemtime($akhApple);
            $hrefApple = base_path('assets/images/brand/apple-touch-icon.png') . '?v=' . rawurlencode($va);
            ?>
  <link rel="apple-touch-icon" href="<?php echo h($hrefApple); ?>" />
            <?php
        }
        $akhHome = rtrim(akh_absolute_url(''), '/');
        $akhLogoAbs = rtrim(akh_absolute_url('assets/images/brand/akhurath-favicon-192.png'), '/');
        $akhLd = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => $akhHome . '#organization',
                    'name' => SITE_NAME,
                    'url' => $akhHome,
                    'logo' => $akhLogoAbs,
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $akhHome . '#website',
                    'url' => $akhHome,
                    'name' => SITE_NAME,
                    'publisher' => ['@id' => $akhHome . '#organization'],
                ],
            ],
        ];
        ?>
  <script type="application/ld+json"><?php echo json_encode($akhLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); ?></script>
        <?php
    }
  ?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500&family=Source+Sans+3:wght@300;400;500;600&display=swap" rel="stylesheet" />
  <?php
    $akhCssPath = defined('AKH_ROOT') ? AKH_ROOT . '/assets/css/site.css' : '';
    $akhCssVer = ($akhCssPath !== '' && is_file($akhCssPath)) ? (string) filemtime($akhCssPath) : '';
    $akhCssHref = base_path('assets/css/site.css') . ($akhCssVer !== '' ? '?v=' . rawurlencode($akhCssVer) : '');
  ?>
  <link rel="stylesheet" href="<?php echo h($akhCssHref); ?>" />
</head>
<body class="<?php echo h($bodyClass); ?>">
  <a class="skip-link" href="#main">Skip to content</a>
  <header class="site-header">
    <div class="site-header__inner">
      <a class="site-logo" href="<?php echo h(base_path('index.php')); ?>"><?php echo h(SITE_NAME); ?></a>
      <nav class="site-nav" id="site-nav" aria-label="Primary">
        <div class="site-menu site-menu--hub" data-site-menu>
          <div class="site-menu__row">
            <nav class="site-menu__slide" aria-label="Site sections">
              <ul class="site-menu__list site-menu__list--slide">
                <li><a href="<?php echo h(base_path('index.php')); ?>#about">About</a></li>
                <li><a href="<?php echo h(base_path('index.php')); ?>#services">Services</a></li>
                <li><a href="<?php echo h(base_path('index.php')); ?>#work">Work</a></li>
                <li><a href="<?php echo h(base_path('index.php')); ?>#clients">Clients</a></li>
                <li><a href="<?php echo h(base_path('contact.php')); ?>">Get in touch</a></li>
              </ul>
            </nav>
            <div class="site-menu__hubcol">
              <div class="site-menu__hubtop">
                <button
                  type="button"
                  class="site-menu__trigger"
                  id="site-menu-trigger"
                  aria-expanded="false"
                  aria-haspopup="true"
                  aria-controls="site-menu-dropdown"
                  aria-label="Open site menu"
                >
                  <span class="visually-hidden">Site menu</span>
                  <span class="site-menu__trigger-label" aria-hidden="true">Menu</span>
                  <span class="site-menu__trigger-bars" aria-hidden="true">
                    <span class="site-menu__trigger-bar"></span>
                    <span class="site-menu__trigger-bar"></span>
                    <span class="site-menu__trigger-bar"></span>
                  </span>
                </button>
              </div>
              <div class="site-menu__panel site-menu__dropdown" id="site-menu-dropdown" role="region" aria-label="Account">
                <ul class="site-menu__list site-menu__list--dropdown site-menu__list--auth">
                  <?php if (defined('AKH_ALLOW_CLIENT_REGISTRATION') && AKH_ALLOW_CLIENT_REGISTRATION): ?>
                    <li class="site-menu__dd-item">
                      <a class="site-menu__dd-link site-menu__link--muted" href="<?php echo h(base_path('customer/register.php')); ?>">Register</a>
                    </li>
                  <?php endif; ?>
                  <li class="site-menu__dd-item site-menu__dd-item--login">
                    <button
                      type="button"
                      class="site-menu__login-trigger"
                      id="site-login-trigger"
                      aria-expanded="false"
                      aria-haspopup="true"
                      aria-controls="site-login-panel"
                    >
                      <span class="site-menu__login-trigger-text">Login</span>
                      <span class="site-menu__login-chevron" aria-hidden="true"></span>
                    </button>
                    <div id="site-login-panel" class="site-login-panel" role="group" aria-label="Sign in as" aria-hidden="true">
                      <div class="site-login-panel__inner">
                        <a class="site-login-panel__link site-login-panel__link--client" href="<?php echo h(base_path('customer/login.php')); ?>">Client</a>
                        <a class="site-login-panel__link site-login-panel__link--editor" href="<?php echo h(base_path('editor/login.php')); ?>">Editor</a>
                        <a class="site-login-panel__link site-login-panel__link--admin" href="<?php echo h(base_path('admin/login.php')); ?>">Admin</a>
                      </div>
                    </div>
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </nav>
    </div>
  </header>
