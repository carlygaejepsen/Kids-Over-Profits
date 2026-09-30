<?php
/**
 * Template Name: Indian Boarding Schools and Residential Schools
 * Description: A short page on the Indian boarding schools of the United
 * States and the Indian residential schools of Canada: why they are not the
 * same as the troubled teen industry, where the two histories touch, and,
 * first of all, where to learn from the survivors and Nations whose history
 * it is.
 *
 * This is not Kids Over Profits' story. The page says so before anything
 * else, and most of it is directions to Indigenous-led organizations. It
 * carries no survivor testimony, no photographs of children and no retelling
 * of anyone's account; those belong where survivors tell them.
 *
 * Every figure is from a government, a court, the TRC/NCTR, NABS or NICWA,
 * and linked where it is used. Change a figure only with its source.
 * Created as a draft (kop_tool_page_specs()) so it is read before it is
 * public; the History hub links it by slug, which shows only once published.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_ibs_css = get_stylesheet_directory() . '/css/indian-boarding-schools.css';
if (file_exists($kop_ibs_css)) {
    wp_enqueue_style(
        'kop-indian-boarding-schools',
        get_stylesheet_directory_uri() . '/css/indian-boarding-schools.css',
        array('kop-colors'),
        filemtime($kop_ibs_css)
    );
}

get_header();
?>

<div class="kop-ibs-page">

    <header class="kop-ibs-header">
        <h1 class="kop-ibs-title"><?php the_title(); ?></h1>
        <?php if (has_excerpt()) : ?>
            <p class="kop-ibs-summary"><?php echo esc_html(get_the_excerpt()); ?></p>
        <?php endif; ?>
    </header>

    <div class="kop-ibs-note" role="note">
        <p><strong>Content note:</strong> this page discusses the forced removal of Indigenous children from their families, abuse, and the deaths of children.
        <a href="#kop-ibs-support">Support lines are listed below.</a></p>
    </div>

    <?php
    while (have_posts()) :
        the_post();
        $kop_ibs_content = trim(get_the_content());
        if ($kop_ibs_content !== '') :
            ?>
            <div class="kop-ibs-editor-content"><?php the_content(); ?></div>
            <?php
        endif;
    endwhile;
    ?>

    <section class="kop-ibs-statement" aria-labelledby="kop-ibs-not-ours">
        <h2 id="kop-ibs-not-ours">This is not our story to tell</h2>
        <p>Kids Over Profits documents the troubled teen industry. We are not an Indigenous organization, and we do not speak for the survivors of Indian boarding schools and residential schools, for their families, or for their Nations. This history belongs to them. They have been telling it for generations, often while governments and churches denied it.</p>
        <p>We made this page because the two histories are sometimes set side by side, and our readers deserve honesty about where they meet and where they do not. Most of what we can offer is directions. The people and organizations below are the experts. Please learn from them first.</p>
    </section>

    <section aria-labelledby="kop-ibs-learn">
        <h2 id="kop-ibs-learn">Learn from the people whose history this is</h2>

        <h3>In the United States</h3>
        <ul class="kop-ibs-orgs">
            <li class="kop-ibs-org">
                <a class="kop-ibs-org-name" href="https://boardingschoolhealing.org/">National Native American Boarding School Healing Coalition (NABS)</a>
                <p>A national coalition working for truth, justice and healing for boarding school survivors and descendants. Its research includes a list of the <a href="https://boardingschoolhealing.org/list-of-indian-boarding-schools/">Indian boarding schools it has identified</a>.</p>
            </li>
            <li class="kop-ibs-org">
                <a class="kop-ibs-org-name" href="https://www.nicwa.org/">National Indian Child Welfare Association (NICWA)</a>
                <p>Works for the safety and wellbeing of Native children and families today, including protecting the Indian Child Welfare Act.</p>
            </li>
            <li class="kop-ibs-org">
                <span class="kop-ibs-org-name">Tribal Nations themselves</span>
                <p>Many Nations carry out their own boarding school research, language revitalization and healing work. Look first to the Nation whose history you are learning about.</p>
            </li>
        </ul>

        <h3>In Canada</h3>
        <ul class="kop-ibs-orgs">
            <li class="kop-ibs-org">
                <a class="kop-ibs-org-name" href="https://nctr.ca/">National Centre for Truth and Reconciliation (NCTR)</a>
                <p>Holds the records and statements gathered by the Truth and Reconciliation Commission, the Commission's <a href="https://nctr.ca/records/reports/">final reports</a>, and the National Student Memorial Register of children who never came home.</p>
            </li>
            <li class="kop-ibs-org">
                <a class="kop-ibs-org-name" href="https://www.irsss.ca/">Indian Residential School Survivors Society (IRSSS)</a>
                <p>Support for residential school survivors and their families.</p>
            </li>
            <li class="kop-ibs-org">
                <a class="kop-ibs-org-name" href="https://legacyofhope.ca/">Legacy of Hope Foundation</a>
                <p>Indigenous-led. Education about residential schools and their effects across generations.</p>
            </li>
            <li class="kop-ibs-org">
                <a class="kop-ibs-org-name" href="https://orangeshirtday.org/">Orange Shirt Society</a>
                <p>Founded around survivor Phyllis Webstad's story. Orange Shirt Day, September 30, is also Canada's National Day for Truth and Reconciliation.</p>
            </li>
        </ul>

        <h3>The official records</h3>
        <p>The United States Department of the Interior's <a href="https://www.bia.gov/service/federal-indian-boarding-school-initiative">Federal Indian Boarding School Initiative</a> published its investigation in two volumes (2022 and 2024) and recorded survivors' testimony on the Road to Healing tour. Canada's Truth and Reconciliation Commission issued its final report and <a href="https://nctr.ca/wp-content/uploads/2021/01/Calls_to_Action_English2.pdf">94 Calls to Action</a> in 2015.</p>
    </section>

    <section class="kop-ibs-support" id="kop-ibs-support" aria-labelledby="kop-ibs-support-title">
        <h2 id="kop-ibs-support-title">If you need support</h2>
        <ul>
            <li><strong>National Indian Residential School Crisis Line</strong> (Canada), 24 hours, for former students and their families: <a class="kop-ibs-phone" href="tel:+18669254419">1-866-925-4419</a></li>
            <li><strong>Hope for Wellness Help Line</strong> (Canada), 24 hours, for First Nations, Inuit and Métis people, in English and French and on request in Cree, Ojibway and Inuktitut: <a class="kop-ibs-phone" href="tel:+18552423310">1-855-242-3310</a> or <a href="https://www.hopeforwellness.ca/">chat online</a></li>
            <li><strong>988 Suicide and Crisis Lifeline</strong> (United States), 24 hours: call or text <a class="kop-ibs-phone" href="tel:988">988</a></li>
            <li><strong>StrongHearts Native Helpline</strong> (United States), 24 hours, for Native Americans and Alaska Natives facing domestic or sexual violence: <a class="kop-ibs-phone" href="tel:+18447628483">1-844-762-8483</a></li>
            <li>NABS keeps a list of <a href="https://boardingschoolhealing.org/healing-informed-resources-for-self-care/">healing-informed resources</a> for boarding school survivors and descendants.</li>
        </ul>
    </section>

    <section aria-labelledby="kop-ibs-genocide">
        <h2 id="kop-ibs-genocide">A genocide, not an industry</h2>
        <p>For more than a century and a half, the United States and Canada took Indigenous children from their families, by law and with public money, so that Indigenous peoples would stop existing as peoples.</p>
        <p>In the United States, federal policy began with the Civilization Fund Act of 1819. The Interior Department's investigation counted <a href="https://www.doi.gov/pressreleases/secretary-haaland-announces-major-milestones-federal-indian-boarding-school">417 federal Indian boarding schools across 37 states or then-territories between 1819 and 1969</a>, and found that at least 973 American Indian, Alaska Native and Native Hawaiian children died while attending them. It called that figure a minimum. A Washington Post investigation later documented <a href="https://www.kjzz.org/tribal-natural-resources/2024-12-24/initiative-cited-973-indian-boarding-school-deaths-washington-post-found-3-times-that-many">more than three times as many deaths</a>. NABS has identified <a href="https://boardingschoolhealing.org/list-of-indian-boarding-schools/">more than 500 schools</a> once church-run schools are counted.</p>
        <p>In Canada, <a href="https://www.rcaanc-cirnac.gc.ca/eng/1100100015606/1581724359507">139 schools are recognized</a> under the Indian Residential Schools Settlement Agreement. About 150,000 First Nations, Inuit and Métis children were taken to them. The last federally run school, Gordon's in Saskatchewan, closed in 1996.</p>
        <p>The aim was stated openly. In 1892 Richard Henry Pratt, who founded the Carlisle Indian Industrial School, said that "all the Indian there is in the race should be dead. <a href="https://teachingamericanhistory.org/document/the-advantages-of-mingling-indians-with-whites/">Kill the Indian in him, and save the man.</a>"</p>
        <p>The <a href="https://www.un.org/en/genocide-prevention/definition">Genocide Convention</a> defines genocide as certain acts "committed with intent to destroy, in whole or in part, a national, ethnical, racial or religious group, as such". One of those acts is "forcibly transferring children of the group to another group." Canada's Truth and Reconciliation Commission described the residential schools as <a href="https://nctr.ca/about/truth-and-reconciliation-commission-of-canada/">cultural genocide</a>. In 2022 Canada's House of Commons unanimously called on the government to <a href="https://www.cbc.ca/news/politics/house-motion-recognize-genocide-1.6632450">recognize them as genocide</a> under that convention. In 2024 the President of the United States <a href="https://bidenwhitehouse.archives.gov/briefing-room/speeches-remarks/2024/10/25/remarks-by-president-biden-on-the-biden-harris-administrations-record-of-delivering-for-tribal-communities-including-keeping-his-promise-to-make-this-historic-visit-to-indian-country-lavee/">formally apologized</a> for the federal boarding school system.</p>

        <h3>Why this is different from the troubled teen industry</h3>
        <ul>
            <li><strong>Who was targeted.</strong> Children were taken because they were Indigenous, not because of anything they did or anything their parents feared. The target was the group itself: its languages, ceremonies and families. The Interior Department's own investigation describes <a href="https://www.doi.gov/pressreleases/department-interior-releases-investigative-report-outlines-next-steps-federal-indian">"twin goals of cultural assimilation and territorial dispossession"</a> behind the schools. Troubled teen programs harm children one at a time, for money and control. That harm is real, but it is not an attempt to destroy a people.</li>
            <li><strong>Who did it.</strong> The schools were government policy, carried out with churches and paid for by the state. The troubled teen industry is a private industry that governments fail to regulate, and sometimes pay for.</li>
            <li><strong>What was lost.</strong> Beyond the harm done to each child, whole Nations lost languages many are still working to bring back, along with ceremonies, knowledge and family ties, across generations. That loss belongs to communities, not only to the people who were sent away.</li>
            <li><strong>Who had a choice.</strong> Attendance was often compulsory, and families who resisted could be punished. Indigenous families were not customers being sold a service. They were the people the policy was aimed at.</li>
        </ul>
        <p>So we do not call the troubled teen industry a genocide. We do not describe troubled teen programs as "the new residential schools." We do not borrow this history's images, numbers or grief to make our own case.</p>
    </section>

    <section aria-labelledby="kop-ibs-touch">
        <h2 id="kop-ibs-touch">Where the histories touch</h2>
        <p>Naming a point of overlap is not a claim that the two are equal. These are the places where the histories meet, and each one is small beside the difference above.</p>

        <h3>Some of the same methods</h3>
        <p>Some things done to children in the boarding schools are also documented in troubled teen programs: sending a child to a distant institution and cutting off contact with family, controlling what children may say and in which language, and presenting unpaid labor as training or discipline. Carlisle's "outing system" placed students with white families as servants and farm workers. And in both, the institutions answered to no one the child could reach. The methods overlap. The purpose did not.</p>

        <h3>Native children are still taken from their families</h3>
        <p>When Congress passed the Indian Child Welfare Act in 1978, studies had found that <a href="https://www.law.cornell.edu/supremecourt/text/490/30">25 to 35 percent of all Indian children</a> had been separated from their families and placed in adoptive homes, foster care or institutions. The Supreme Court upheld the Act in <em>Haaland v. Brackeen</em> (2023). Native children are still in state foster care at <a href="https://www.nicwa.org/wp-content/uploads/2026/04/NICWA_Disproportionality-Child-Welfare-Fact-Sheet_2026.pdf">3.75 times their share of the population</a>, and tribal youth are <a href="https://www.sentencingproject.org/fact-sheet/disparities-in-tribal-youth-incarceration/">3.8 times as likely</a> as white youth to be held in juvenile facilities. The Indian Health Service notes that Native youth have often been <a href="https://www.ihs.gov/yrtc/">sent out of state</a> to treatment facilities that do not meet their cultural needs.</p>
        <p>We have not found reliable national figures on how many Native young people are sent to private troubled teen programs. If you know of research, please tell us.</p>

        <h3>Ceremonies taken as program tools</h3>
        <p>Some wilderness and residential programs borrow Indigenous ceremonies and imagery, such as sweat lodges, "vision quests," medicine wheels and talking sticks, and use them as tools for changing behavior. Outdoor and adventure therapy practitioners have <a href="https://www.outdoored.com/articles/stealing-wisdom-cultural-appropriation-and-misrepresentation-within-adventure-therapy-and/">criticized this from within their own field</a>. Our <a href="<?php echo esc_url(home_url('/glossary/')); ?>">glossary</a> records examples of this borrowing in troubled teen programs.</p>

        <h3>Federal boarding schools that are still open</h3>
        <p>The Bureau of Indian Education still runs <a href="https://www.bie.edu/topic-page/reservation-residential-schools">four off-reservation residential schools</a>: Chemawa, Flandreau, Riverside and Sherman. They serve Native students today. They are not troubled teen programs and are not in our directory. Questions about them are for their students, alumni and the Nations they serve.</p>
    </section>

    <section aria-labelledby="kop-ibs-wrong">
        <h2 id="kop-ibs-wrong">Tell us if we got this wrong</h2>
        <p>If you are Indigenous, a survivor or a descendant, and something on this page is wrong or does harm, please <a href="<?php echo esc_url(home_url('/contact/')); ?>">tell us</a>. We will change it.</p>
    </section>

    <p class="kop-ibs-updated">Figures checked against their sources on September 30, 2026.</p>

</div>

<?php
get_footer();
