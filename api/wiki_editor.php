<?php
/**
 * Wiki Editor generator markup partial.
 *
 * Loaded by page-wiki-editor.php so WordPress can enqueue assets.
 */

get_header();
?>

<div class="wiki-editor-page">
    <div class="wiki-editor-container">
        <h1>TTI Wiki Entry Generator</h1>
        <p>Fill in the fields below. For list sections, fill in the small boxes and click "Add" for each item. When finished, click the "Generate" button at the very bottom.</p>

        <!-- Import Panel -->
        <div class="import-section">
            <button type="button" id="toggleImportBtn" class="toggle-import-btn">📥 Import from Reddit Markdown</button>
            <div id="importPanel" class="import-panel" style="display: none;">
                <h3>Import Existing Reddit Markdown</h3>
                <p>Paste your existing Reddit wiki markdown below to populate the form fields automatically.</p>
                <textarea id="importTextarea" class="import-textarea" placeholder="Paste your Reddit markdown here..."></textarea>
                <div class="import-controls">
                    <button type="button" id="cancelImportBtn" class="cancel-btn">Cancel</button>
                    <button type="button" id="importBtn" class="import-btn">Import</button>
                </div>
            </div>
        </div>

        <form id="wikiForm">
            <fieldset>
                <legend>Basic Information</legend>
                <label for="programName">Program Name:</label>
                <input type="text" id="programName" name="programName" placeholder="e.g., Turn-About Ranch">

                <label for="yearsActive">Years Active:</label>
                <input type="text" id="yearsActive" name="yearsActive" placeholder="e.g., 1989-present">

                <label for="cityState">City, ST:</label>
                <input type="text" id="cityState" name="cityState" placeholder="e.g., Escalante, UT">

                <label for="programType">Program Type:</label>
                <input type="text" id="programType" name="programType" placeholder="e.g., Residential Treatment Center">
            </fieldset>

            <fieldset>
                <legend>History and Background Information</legend>
                <label for="yearFounded">Year Founded:</label>
                <input type="text" id="yearFounded" name="yearFounded" placeholder="e.g., 1989">
                <label for="ownerName">Original/Current Owner:</label>
                <input type="text" id="ownerName" name="ownerName" placeholder="e.g., Aspen Education Group">
                <label for="ownerLink">Owner Wiki Link (if any):</label>
                <input type="text" id="ownerLink" name="ownerLink" placeholder="e.g., /r/troubledteens/wiki/index/aspeneducation">
                <label for="ageRange">Age Range:</label>
                <input type="text" id="ageRange" name="ageRange" placeholder="e.g., 13-17">
                <label for="diagnosesList">Diagnoses/Behaviors (comma-separated):</label>
                <input type="text" id="diagnosesList" name="diagnosesList" placeholder="e.g., Abuse, Adjustment Disorder, Anxiety...">
                <label for="avgStay">Average Length of Stay:</label>
                <input type="text" id="avgStay" name="avgStay" placeholder="e.g., 100 days">
                <label for="tuition">Tuition:</label>
                <input type="text" id="tuition" name="tuition" placeholder="e.g., more than $40,000">
                <label for="natsapStatus">NATSAP Status:</label>
                <input type="text" id="natsapStatus" name="natsapStatus" placeholder="e.g., has been a NATSAP member since 1999">
                <label for="mainAddress">Main Office Address:</label>
                <input type="text" id="mainAddress" name="mainAddress" placeholder="e.g., 280 N 300 E, Escalante, UT 84726">
                <label for="addressLink">Google Maps Link for Address:</label>
                <input type="text" id="addressLink" name="addressLink" placeholder="e.g., https://www.google.com/maps/...">
                <label for="accreditingBody">Accrediting Body:</label>
                <input type="text" id="accreditingBody" name="accreditingBody" placeholder="e.g., Northwest Accreditation Commission (NWAC)">
                <label for="accreditingBodyLink">Accrediting Body Wiki Link (if any):</label>
                <input type="text" id="accreditingBodyLink" name="accreditingBodyLink" placeholder="e.g., /r/troubledteens/wiki/index/naas">
                <label for="historyMisc">Other History/Background Details (Paragraphs):</label>
                <textarea id="historyMisc" name="historyMisc" rows="6" placeholder="Add any other info here, like details on other campuses, program philosophy, changes in ownership, etc."></textarea>
            </fieldset>

            <fieldset>
                <legend>Founders and Notable Staff</legend>
                <div class="form-adder">
                    <label for="staffName">Name:</label>
                    <input type="text" id="staffName">
                    <label for="staffRole">Role:</label>
                    <input type="text" id="staffRole">
                    <label for="staffPreviousRoles">Previous roles at other facilities (one per line):</label>
                    <textarea id="staffPreviousRoles" rows="3" placeholder="e.g., Program Director at SunHawk Academy&#10;Counselor at Spring Mountain Treatment Center"></textarea>
                    <label for="staffBio">Bio/Details (can include Markdown links):</label>
                    <textarea id="staffBio" rows="3" placeholder="e.g., Previously worked at [Another Program](link)..."></textarea>
                    <label class="checkbox-inline">
                        <input type="checkbox" id="staffIsFormer">
                        <span>Mark as former/previous staff member</span>
                    </label>
                    <button type="button" class="add-btn" id="addStaffBtn">Add Staff Member</button>
                </div>
                <div class="list-preview" id="staffListOutput"></div>
            </fieldset>

            <fieldset>
                <legend>Program Structure</legend>
                <label for="levelSystemDesc">Level System Description:</label>
                <input type="text" id="levelSystemDesc" name="levelSystemDesc" placeholder="e.g., a level-system consisting of four levels">
                <div class="form-adder">
                    <label for="levelName">Level Name:</label>
                    <input type="text" id="levelName">
                    <label for="levelDesc">Level Description:</label>
                    <textarea id="levelDesc" rows="3" placeholder="e.g., Conditions of this level, rules, requirements to advance."></textarea>
                    <button type="button" class="add-btn" id="addLevelBtn">Add Level</button>
                </div>
                <div class="list-preview" id="levelListOutput"></div>
                <label for="structureMisc">Other Structure Details (e.g., Education):</label>
                <textarea id="structureMisc" name="structureMisc" rows="4" placeholder="e.g., Additionally, survivors have reported that the educational component..."></textarea>
            </fieldset>

            <fieldset>
                <legend>Rules and Punishments</legend>
                <label for="rulesList">Rules (one rule per line):</label>
                <textarea id="rulesList" name="rulesList" rows="6" placeholder="e.g., No swearing, foul or abusive language"></textarea>
                <hr>
                <h4>Punishments</h4>
                <div class="form-adder">
                    <label for="punishmentName">Punishment Name/Type:</label>
                    <input type="text" id="punishmentName" placeholder="e.g., Stone Circle, Code of Silence, Loss of Privileges">
                    <label for="punishmentDesc">Description:</label>
                    <textarea id="punishmentDesc" rows="3" placeholder="e.g., Students are required to sit in a circle of stones for hours without speaking or moving..."></textarea>
                    <button type="button" class="add-btn" id="addPunishmentBtn">Add Punishment</button>
                </div>
                <div class="list-preview" id="punishmentListOutput"></div>
                <label for="punishmentsMisc">Additional Punishment Details (optional):</label>
                <textarea id="punishmentsMisc" name="punishmentsMisc" rows="3" placeholder="Any other relevant information about the punishment system..."></textarea>
            </fieldset>

            <fieldset>
                <legend>Abuse/Neglect Allegations and Lawsuits</legend>
                <label for="mainComplaints">Main Complaints (comma-separated):</label>
                <input type="text" id="mainComplaints" name="mainComplaints" placeholder="e.g., emotional abuse, medical neglect, LGBTQ+ harassment">
                <label for="otherAllegationsList">Other Allegations (one allegation per line):</label>
                <textarea id="otherAllegationsList" name="otherAllegationsList" rows="5" placeholder="e.g., deprivation of sleep"></textarea>
                <hr>
                <h4>Lawsuits</h4>
                <div class="form-adder">
                    <label for="lawsuitYear">Year:</label>
                    <input type="text" id="lawsuitYear" placeholder="e.g., 2018">
                    <label for="lawsuitPlaintiff">Plaintiff(s):</label>
                    <input type="text" id="lawsuitPlaintiff" placeholder="e.g., John Doe, Jane Smith">
                    <label for="lawsuitAllegations">Allegations:</label>
                    <textarea id="lawsuitAllegations" rows="3" placeholder="e.g., physical abuse, false imprisonment, negligence"></textarea>
                    <label for="lawsuitOutcome">Outcome (optional):</label>
                    <input type="text" id="lawsuitOutcome" placeholder="e.g., settled out of court, case dismissed, awarded $X">
                    <label for="lawsuitDetails">Additional Details (optional):</label>
                    <textarea id="lawsuitDetails" rows="2" placeholder="e.g., The lawsuit also named the parent company..."></textarea>
                    <button type="button" class="add-btn" id="addLawsuitBtn">Add Lawsuit</button>
                </div>
                <div class="list-preview" id="lawsuitListOutput"></div>
                <label for="lawsuitsMisc">Additional Lawsuit Information (optional):</label>
                <textarea id="lawsuitsMisc" name="lawsuitsMisc" rows="3" placeholder="Any other relevant information about lawsuits or legal actions..."></textarea>
            </fieldset>

            <fieldset>
                <legend>In the Media &amp; News</legend>
                <label for="mediaInfo">General Media (one event per line):</label>
                <textarea id="mediaInfo" name="mediaInfo" rows="5" placeholder="e.g., 2006: Season 2 of Brat Camp..."></textarea>
                <hr>
                <div class="form-adder">
                    <h4>Add a News Article</h4>
                    <label for="articleTitle">Article Title:</label>
                    <input type="text" id="articleTitle">
                    <label for="articleUrl">Article URL:</label>
                    <input type="text" id="articleUrl">
                    <label for="articleSource">Source (optional):</label>
                    <input type="text" id="articleSource" placeholder="e.g., Deseret News">
                    <label for="articleDate">Date (optional):</label>
                    <input type="text" id="articleDate" placeholder="e.g., 8/27/1994">
                    <button type="button" class="add-btn" id="addArticleBtn">Add Article</button>
                </div>
                <div class="list-preview" id="articleListOutput"></div>
            </fieldset>

            <fieldset>
                <legend>Survivor Testimonies</legend>
                <div class="form-adder">
                    <label for="testimonyDate">Date (optional):</label>
                    <input type="text" id="testimonyDate" placeholder="e.g., May 2022">
                    <label for="testimonyType">Type:</label>
                    <select id="testimonyType">
                        <option value="SURVIVOR">SURVIVOR</option>
                        <option value="PARENT">PARENT</option>
                        <option value="STAFF">STAFF</option>
                    </select>
                    <label for="testimonyQuote">Quote or Description:</label>
                    <textarea id="testimonyQuote" rows="3" placeholder="e.g., 'This place thrives...' or 'Reddit Post by u/...'"></textarea>
                    <label for="testimonySource">Source Name:</label>
                    <input type="text" id="testimonySource" placeholder="e.g., Brooke (Google Reviews) or Reddit">
                    <label for="testimonyUrl">Source URL:</label>
                    <input type="text" id="testimonyUrl">
                    <button type="button" class="add-btn" id="addTestimonyBtn">Add Testimony</button>
                </div>
                <div class="list-preview" id="testimonyListOutput"></div>
            </fieldset>

            <fieldset>
                <legend>Related Media (Links)</legend>
                <div class="form-adder">
                    <label for="mediaTitle">Link Title:</label>
                    <input type="text" id="mediaTitle" placeholder="e.g., Turn-About Ranch Website Homepage">
                    <label for="mediaUrl">Link URL:</label>
                    <input type="text" id="mediaUrl">
                    <button type="button" class="add-btn" id="addMediaBtn">Add Media Link</button>
                </div>
                <div class="list-preview" id="mediaListOutput"></div>
            </fieldset>

            <button type="button" id="generateBtn">Generate Wiki Code</button>
        </form>

        <h2>Generated Wiki Code</h2>
        <p>Copy the text below and paste it directly into the Reddit wiki editor.</p>
        <textarea id="outputCode" readonly></textarea>
        <div class="output-actions">
            <button type="button" id="convertPastBtn">Convert to Past Tense</button>
            <button type="button" id="copyBtn">Copy to Clipboard</button>
        </div>
    </div>
</div>

<?php
get_footer();
