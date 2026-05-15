<?php
/**
 * Template Name: Privacy Policy
 * Template Post Type: page
 */

get_header();
?>

<main id="primary" class="site-main kop-privacy">
  <div class="kop-privacy__container">

    <h1>Privacy Policy</h1>
    <p class="kop-privacy__updated">Last updated: <?php echo date('F j, Y'); ?></p>

    <section>
      <h2>Who We Are</h2>
      <p>Kids Over Profits (<strong>kidsoverprofits.com</strong>) is a research project documenting the Troubled Teen Industry (TTI). We are not a commercial service and do not sell products or services.</p>
    </section>

    <section>
      <h2>Information We Collect</h2>

      <h3>Public Data Submissions</h3>
      <p>When you submit facility information, a wiki entry, or a news tip through our public forms, we collect:</p>
      <ul>
        <li>The data you enter in the form (facility names, locations, organizations, personnel, etc.)</li>
        <li>An optional reason or notes you include with your submission</li>
        <li>Your IP address, automatically captured to help detect spam and abuse</li>
        <li>Optionally, an email address <em>only if you choose to provide one</em></li>
      </ul>
      <p>All public submissions are held in a pending queue and reviewed by an admin before any data is published.</p>

      <h3>Automatically Collected Data</h3>
      <p>Like most websites, our server logs standard request data including IP addresses, browser type, and pages visited. These logs are used solely for security monitoring and are not shared.</p>

      <h3>Cookies</h3>
      <p>We use only the standard WordPress session cookies required for admin login. We do not use tracking cookies, advertising cookies, or analytics cookies.</p>
    </section>

    <section>
      <h2>How We Use Your Information</h2>
      <ul>
        <li><strong>IP addresses</strong> — to detect spam submissions and investigate abuse; not used to identify or track ordinary visitors</li>
        <li><strong>Submitted data</strong> — to review and, if accurate, incorporate into our public database of TTI facilities</li>
        <li><strong>Email addresses (optional)</strong> — to follow up on your submission if we have questions; never used for marketing</li>
      </ul>
    </section>

    <section>
      <h2>Third-Party Services</h2>
      <p>We use the following external services. No personally identifying information about site visitors is shared with these services.</p>
      <ul>
        <li><strong>Cloudmersive</strong> — URLs and files included in submissions are scanned for malware before being stored. Cloudmersive receives only the URL or file content, not your identity or IP address.</li>
        <li><strong>Congress.gov API</strong> — used to retrieve public legislative data. No visitor data is transmitted.</li>
      </ul>
      <p>We do not use Google Analytics, Facebook Pixel, or any other behavioral tracking or advertising service.</p>
    </section>

    <section>
      <h2>Data Retention</h2>
      <p>Submitted data is retained indefinitely for research purposes. If you submitted information and wish to request its removal, contact us at the address below.</p>
    </section>

    <section>
      <h2>Your Rights</h2>
      <p>You may request to:</p>
      <ul>
        <li>Access the data we hold about a submission you made</li>
        <li>Correct inaccurate data</li>
        <li>Delete your submission from our pending queue (before it is approved)</li>
      </ul>
      <p>We will respond to verified requests within 30 days.</p>
    </section>

    <section>
      <h2>Children's Privacy</h2>
      <p>This site is a research resource and is not directed at children under 13. We do not knowingly collect personal information from children.</p>
    </section>

    <section>
      <h2>Contact</h2>
      <p>Questions or requests regarding this policy can be sent to: <a href="mailto:<?php echo antispambot(get_option('admin_email')); ?>"><?php echo antispambot(get_option('admin_email')); ?></a></p>
    </section>

  </div>
</main>

<?php get_footer(); ?>
