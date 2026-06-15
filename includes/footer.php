<?php

declare(strict_types=1);

$year = (int) date('Y');
?>
  <footer class="site-footer">
    <div class="site-footer__inner">
      <div>
        <strong><?php echo h(SITE_NAME); ?></strong>
        <p class="site-footer__tag"><?php echo h(SITE_TAGLINE); ?></p>
      </div>
      <div class="site-footer__links">
        <a href="<?php echo h(base_path('index.php')); ?>">Home</a>
        <a href="<?php echo h(base_path('contact.php')); ?>">Contact</a>
        <a href="<?php echo h(base_path('privacy-policy')); ?>">Privacy</a>
        <a href="<?php echo h(base_path('customer/login.php')); ?>">Client login</a>
        <?php if (defined('AKH_ALLOW_CLIENT_REGISTRATION') && AKH_ALLOW_CLIENT_REGISTRATION): ?>
          <a href="<?php echo h(base_path('customer/register.php')); ?>">Client register</a>
        <?php endif; ?>
        <a href="<?php echo h(base_path('editor/login.php')); ?>">Editor login</a>
      </div>
      <p class="site-footer__copy">© <?php echo $year; ?> <?php echo h(SITE_NAME); ?> · All rights reserved</p>
    </div>
  </footer>
  <script src="<?php echo h(base_path('assets/js/site.js')); ?>" defer></script>
</body>
</html>
