<?php

declare(strict_types=1);

/** Shared join-meeting modal (WhatsApp + editor dashboards). */
?>
<dialog class="akh-meeting-modal" id="akh-meeting-join-modal" aria-labelledby="akh-meeting-join-title">
  <div class="akh-meeting-modal__inner">
    <header class="akh-meeting-modal__head">
      <p class="akh-meeting-modal__kicker">Meeting starting soon</p>
      <h2 class="akh-meeting-modal__title" id="akh-meeting-join-title">Join meeting</h2>
      <button type="button" class="akh-meeting-modal__close" id="akh-meeting-join-close" aria-label="Close">×</button>
    </header>
    <p class="akh-meeting-modal__body" id="akh-meeting-join-body"></p>
    <footer class="akh-meeting-modal__foot">
      <button type="button" class="btn btn--ghost btn--sm" id="akh-meeting-join-later">Later</button>
      <a class="btn btn--ghost btn--sm" id="akh-meeting-join-link" href="#" target="_blank" rel="noopener noreferrer" hidden>Open link</a>
      <button type="button" class="btn btn--primary btn--sm" id="akh-meeting-join-btn">Join Google Meet</button>
    </footer>
  </div>
</dialog>
