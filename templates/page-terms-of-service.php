<?php
/**
 * Template Name: Terms of Service
 * Template Post Type: page
 */

get_header();
?>

<main id="primary" class="site-main kop-privacy">
  <div class="kop-privacy__container">

    <h1>Terms of Service</h1>
    <p class="kop-privacy__updated">Last updated: <?php echo date('F j, Y'); ?></p>

    <section>
      <h2>About This Site</h2>
      <p>Kids Over Profits (<strong>kidsoverprofits.com</strong>) is a volunteer-run research project documenting facilities and organizations within the Troubled Teen Industry (TTI). By accessing or using this site you agree to these terms.</p>
    </section>

    <section>
      <h2>Informational Use Only</h2>
      <p>All content on this site is provided for research and informational purposes only. It does not constitute legal, medical, therapeutic, or professional advice. We make no guarantee that information is current, complete, or error-free.</p>
    </section>

    <section>
      <h2>Submitting Data</h2>
      <p>When you submit facility data, wiki entries, news tips, or any other information through our public forms, you agree that:</p>
      <ul>
        <li>The information you submit is accurate and truthful to the best of your knowledge.</li>
        <li>You are not submitting false, fabricated, or defamatory statements about any individual or organization.</li>
        <li>You will not submit personal information about minors (full names, addresses, identifying details).</li>
        <li>You will not submit content that is harassing, threatening, or intended to harm a specific individual.</li>
        <li>Your submission may be reviewed, edited for clarity, rejected, or removed at our discretion.</li>
        <li>Once approved and published, your submitted content may remain in the public database indefinitely as part of the research record.</li>
      </ul>
    </section>

    <section>
      <h2>Content Ownership</h2>
      <p>By submitting content you grant Kids Over Profits a perpetual, royalty-free license to use, display, and publish that content as part of this research project. You retain ownership of any original writing you submit.</p>
      <p>We do not claim ownership of factual data (facility names, addresses, dates, public records). Factual data is not copyrightable.</p>
    </section>

    <section>
      <h2>Prohibited Uses</h2>
      <p>You may not use this site to:</p>
      <ul>
        <li>Scrape or harvest data for commercial purposes or bulk redistribution without permission</li>
        <li>Submit spam, malware links, or automated bot submissions</li>
        <li>Attempt to access admin areas, databases, or systems you are not authorized to use</li>
        <li>Interfere with the availability or integrity of the site</li>
        <li>Harass, intimidate, or retaliate against survivors, researchers, or contributors</li>
      </ul>
    </section>

    <section>
      <h2>Third-Party Links</h2>
      <p>This site links to external sources including news articles, government records, and advocacy organizations. We are not responsible for the content or availability of external sites.</p>
    </section>

    <section>
      <h2>Disclaimer of Warranties</h2>
      <p>This site is provided "as is" without warranties of any kind. We do not warrant that the information is accurate, complete, or up to date. Use of this site is at your own risk.</p>
    </section>

    <section>
      <h2>Limitation of Liability</h2>
      <p>Kids Over Profits and its contributors shall not be liable for any damages arising from your use of this site, reliance on its content, or inability to access the site.</p>
    </section>

    <section>
      <h2>Changes to These Terms</h2>
      <p>We may update these terms at any time. Continued use of the site after changes are posted constitutes acceptance of the updated terms.</p>
    </section>

    <section>
      <h2>Contact</h2>
      <p>Questions about these terms can be sent to: <a href="mailto:<?php echo antispambot(get_option('admin_email')); ?>"><?php echo antispambot(get_option('admin_email')); ?></a></p>
    </section>

  </div>
</main>

<?php get_footer(); ?>
