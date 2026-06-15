<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Privacy Policy — ' . SITE_NAME;
$metaDescription = 'Privacy Policy for ' . SITE_NAME . ' website, client portal, editor tools, and Meta (Facebook/Instagram) integrations.';
$bodyClass = 'page-legal';

$contactEmail = defined('LEADS_EMAIL') && LEADS_EMAIL !== '' ? LEADS_EMAIL : (defined('CONTACT_EMAIL') ? CONTACT_EMAIL : 'info@akhurathstudio.com');
$policyUrl = rtrim(akh_absolute_url('privacy-policy'), '/');
$effectiveDate = '22 May 2026';

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="legal-main">
    <article class="legal-shell legal-prose">
      <header class="legal-head">
        <h1 class="legal-title">Privacy Policy</h1>
        <p class="legal-meta">Effective date: <?php echo h($effectiveDate); ?> · Last updated: <?php echo h($effectiveDate); ?></p>
        <p class="legal-lead"><?php echo h(SITE_NAME); ?> (“we”, “us”, “our”) operates <?php echo h(rtrim(akh_absolute_url(''), '/')); ?> and related online services, including client and editor portals. This Privacy Policy explains how we collect, use, store, and share personal information when you use our website and applications.</p>
      </header>

      <section id="who-we-are" aria-labelledby="who-we-are-h">
        <h2 id="who-we-are-h">1. Who we are</h2>
        <p><strong><?php echo h(SITE_NAME); ?></strong> provides wedding film editing and post-production services. For privacy-related questions or requests, contact us at <a href="mailto:<?php echo h($contactEmail); ?>"><?php echo h($contactEmail); ?></a>.</p>
      </section>

      <section id="scope" aria-labelledby="scope-h">
        <h2 id="scope-h">2. What this policy covers</h2>
        <p>This policy applies to:</p>
        <ul>
          <li>Our public website (including contact and packages pages)</li>
          <li>The <strong>client portal</strong>, where customers register, submit editing tasks, and track project status</li>
          <li>The <strong>editor portal</strong>, where staff sign in, manage tasks, record attendance, and request leave</li>
          <li>The <strong>admin console</strong>, used internally by <?php echo h(SITE_NAME); ?> to manage clients, editors, tasks, and attendance</li>
          <li>Any <?php echo h(SITE_NAME); ?> application that connects to Meta platforms (Facebook or Instagram), including apps submitted for review on Meta for Developers</li>
        </ul>
      </section>

      <section id="information-we-collect" aria-labelledby="information-we-collect-h">
        <h2 id="information-we-collect-h">3. Information we collect</h2>

        <h3>3.1 Information you provide directly</h3>
        <ul>
          <li><strong>Contact enquiries:</strong> name, company, phone number, email address, and project details submitted through our contact form.</li>
          <li><strong>Client accounts:</strong> username, email address, and password (stored as a secure one-way hash, not plain text).</li>
          <li><strong>Editing tasks:</strong> project titles, delivery preferences, notes, reference links, file or media references you supply, and messages exchanged on task threads.</li>
          <li><strong>Editor and admin accounts:</strong> usernames and passwords (hashed), leave requests, and internal work updates.</li>
        </ul>

        <h3>3.2 Information collected automatically</h3>
        <ul>
          <li><strong>Session and authentication data:</strong> we use HTTP cookies and server-side sessions so you can stay signed in to portals. Session identifiers are tied to your account while you are logged in.</li>
          <li><strong>Network and security data:</strong> for editor access controls, we may process IP addresses and related network information to verify that sign-in occurs from an authorised office network.</li>
          <li><strong>Attendance records:</strong> when editors use clock-in and clock-out features, we record timestamps and related attendance events for payroll and operations.</li>
          <li><strong>Server logs:</strong> our hosting provider may log standard technical data such as browser type, requested pages, and error reports for security and reliability.</li>
        </ul>

        <h3>3.3 Information from Meta (Facebook / Instagram)</h3>
        <p>If you connect a Facebook or Instagram account to a <?php echo h(SITE_NAME); ?> application, or if our services interact with Meta APIs on your behalf, we may receive information permitted by Meta and by the permissions you grant, which may include:</p>
        <ul>
          <li>Basic profile information (such as name, username, and profile identifier)</li>
          <li>Instagram or Facebook account identifiers needed to link your account to our service</li>
          <li>Media, content metadata, or insights that you authorise us to access for the stated purpose of the app (for example, managing or referencing Instagram reels or related content for editing workflows)</li>
          <li>Access tokens issued by Meta to maintain the connection until you revoke access</li>
        </ul>
        <p>We only request permissions that are necessary for the features you use. We do not sell Meta Platform Data.</p>
      </section>

      <section id="how-we-use" aria-labelledby="how-we-use-h">
        <h2 id="how-we-use-h">4. How we use information</h2>
        <p>We use personal information to:</p>
        <ul>
          <li>Respond to enquiries and communicate about projects</li>
          <li>Provide client, editor, and admin portal functionality</li>
          <li>Assign, track, and deliver editing work</li>
          <li>Send service-related email notifications (for example, task updates, registration confirmations, or attendance alerts) when email is enabled</li>
          <li>Operate attendance, leave, and internal studio tools</li>
          <li>Enforce office-only editor access and protect accounts from abuse</li>
          <li>Comply with law and resolve disputes</li>
          <li>Improve reliability and security of our services</li>
        </ul>
        <p>Where Meta Platform Data is involved, we use it only to provide the app features you authorise and as described at the time of connection.</p>
      </section>

      <section id="legal-bases" aria-labelledby="legal-bases-h">
        <h2 id="legal-bases-h">5. Legal bases (where applicable)</h2>
        <p>Depending on your location, we process personal information based on one or more of the following: your consent (for example, when you submit a form or connect a social account), performance of a contract or steps before entering a contract (providing editing services), our legitimate interests in operating and securing our business (such as fraud prevention and office access controls), and compliance with legal obligations.</p>
      </section>

      <section id="sharing" aria-labelledby="sharing-h">
        <h2 id="sharing-h">6. How we share information</h2>
        <p>We do not sell your personal information. We may share information only in these situations:</p>
        <ul>
          <li><strong>Service providers:</strong> hosting, email delivery (SMTP), and infrastructure partners that process data on our instructions to run the website and portals.</li>
          <li><strong>Meta Platforms:</strong> when you use features that interact with Facebook or Instagram, data may be transmitted to or received from Meta as part of that integration, subject to Meta’s terms and your app permissions.</li>
          <li><strong>Within <?php echo h(SITE_NAME); ?>:</strong> authorised admins and assigned editors may access task and account information needed to perform work.</li>
          <li><strong>Legal requirements:</strong> if required by law, court order, or to protect rights, safety, and security.</li>
          <li><strong>Business transfers:</strong> in connection with a merger, acquisition, or sale of assets, subject to appropriate safeguards.</li>
        </ul>
      </section>

      <section id="retention" aria-labelledby="retention-h">
        <h2 id="retention-h">7. Data retention</h2>
        <p>We keep information for as long as needed to provide services, meet legal and accounting requirements, and resolve disputes. Contact enquiries and task records are retained while relevant to active or historical projects unless you ask us to delete information that we are not required to keep. Account data is kept while your account is active and for a reasonable period afterward. Meta access tokens are retained only while the integration is active or until you disconnect the app or we no longer need them for the authorised purpose.</p>
      </section>

      <section id="security" aria-labelledby="security-h">
        <h2 id="security-h">8. Security</h2>
        <p>We use reasonable technical and organisational measures to protect personal information, including password hashing, access controls, HTTPS in production, and restricted admin access. No method of transmission or storage is completely secure; we cannot guarantee absolute security.</p>
      </section>

      <section id="your-rights" aria-labelledby="your-rights-h">
        <h2 id="your-rights-h">9. Your choices and rights</h2>
        <p>Depending on applicable law, you may have the right to access, correct, delete, or restrict certain processing of your personal information, or to withdraw consent where processing is consent-based.</p>
        <ul>
          <li><strong>Account information:</strong> contact us to update or delete client account details where permitted.</li>
          <li><strong>Meta connections:</strong> you can remove <?php echo h(SITE_NAME); ?> app access at any time in your Facebook or Instagram account settings (Settings → Apps and Websites / Business integrations). Revoking access stops further data collection through that connection.</li>
          <li><strong>Marketing:</strong> we do not send unsolicited marketing email from portal activity; contact-form replies are service-related.</li>
        </ul>
        <p>To exercise privacy rights, email <a href="mailto:<?php echo h($contactEmail); ?>"><?php echo h($contactEmail); ?></a>. We may need to verify your identity before responding.</p>
      </section>

      <section id="cookies" aria-labelledby="cookies-h">
        <h2 id="cookies-h">10. Cookies and similar technologies</h2>
        <p>Our portals use essential session cookies to maintain login state and protect forms (for example, CSRF tokens). These cookies are necessary for the service to function. We do not use third-party advertising cookies on the core portals described in this policy.</p>
      </section>

      <section id="children" aria-labelledby="children-h">
        <h2 id="children-h">11. Children’s privacy</h2>
        <p>Our services are intended for business and professional use and are not directed to children under 13 (or the minimum age required in your country). We do not knowingly collect personal information from children. If you believe a child has provided us data, contact us and we will take appropriate steps to delete it.</p>
      </section>

      <section id="international" aria-labelledby="international-h">
        <h2 id="international-h">12. International transfers</h2>
        <p><?php echo h(SITE_NAME); ?> is based in India. Your information may be processed on servers located in India or other countries where our hosting or service providers operate. We take steps to ensure appropriate safeguards when data is transferred internationally.</p>
      </section>

      <section id="third-party-links" aria-labelledby="third-party-links-h">
        <h2 id="third-party-links-h">13. Third-party links and platforms</h2>
        <p>Our website may link to third-party sites (for example, WhatsApp or social profiles). Their privacy practices are governed by their own policies. When you use Meta login or Instagram features, Meta’s Privacy Policy and Terms also apply: <a href="https://www.facebook.com/privacy/policy/" rel="noopener noreferrer">Meta Privacy Policy</a>.</p>
      </section>

      <section id="changes" aria-labelledby="changes-h">
        <h2 id="changes-h">14. Changes to this policy</h2>
        <p>We may update this Privacy Policy from time to time. The “Last updated” date at the top will change when we do. Material changes may be communicated through the website or by email where appropriate. Continued use of our services after an update means you accept the revised policy.</p>
      </section>

      <section id="contact" aria-labelledby="contact-h">
        <h2 id="contact-h">15. Contact us</h2>
        <p>If you have questions about this Privacy Policy or our data practices, contact:</p>
        <p>
          <strong><?php echo h(SITE_NAME); ?></strong><br />
          Email: <a href="mailto:<?php echo h($contactEmail); ?>"><?php echo h($contactEmail); ?></a><br />
          Privacy Policy URL: <a href="<?php echo h($policyUrl); ?>"><?php echo h($policyUrl); ?></a>
        </p>
      </section>

      <footer class="legal-foot">
        <p><a class="text-link" href="<?php echo h(base_path('index.php')); ?>">← Back to home</a> · <a class="text-link" href="<?php echo h(base_path('contact.php')); ?>">Contact</a></p>
      </footer>
    </article>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
