<?php
/**
 * Template Name: TTI Wiki Entry Generator
 *
 * Provides a custom form interface so editors can assemble wiki entries.
 */

get_header();
?>

<div class="wiki-editor-page">
    <div class="wiki-editor-container">
        <h1>TTI Wiki Entry Generator</h1>
        <p>Fill in the fields below. For list sections, fill in the small boxes and click "Add" for each item. When finished, click the "Generate" button at the very bottom.</p>

        <!-- Import Section -->
        <div class="import-section">
            <button type="button" id="toggleImportBtn" class="toggle-import-btn">📥 Import from Reddit Markdown</button>
            <div id="importPanel" class="import-panel" style="display: none;">
                <h3>Paste Reddit Markdown to Import</h3>
                <p>Paste an existing Reddit wiki entry below and click "Import" to automatically populate the form fields.</p>
                <textarea id="importTextarea" class="import-textarea" rows="10" placeholder="Paste your Reddit markdown here..."></textarea>
                <div class="import-controls">
                    <button type="button" id="importBtn" class="import-btn">Import & Fill Form</button>
                    <button type="button" id="cancelImportBtn" class="cancel-btn">Cancel</button>
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
                <div class="field-row">
                    <div class="field-group">
                        <label for="yearFounded">Year Founded:</label>
                        <input type="text" id="yearFounded" name="yearFounded" placeholder="e.g., 1989">
                    </div>
                    <div class="field-group">
                        <label for="ageRange">Age Range:</label>
                        <input type="text" id="ageRange" name="ageRange" placeholder="e.g., 13-17">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label for="ownerName">Owner/Operator:</label>
                        <input type="text" id="ownerName" name="ownerName" placeholder="e.g., Aspen Education Group">
                    </div>
                    <div class="field-group">
                        <label for="ownerLink">Owner Wiki Link:</label>
                        <input type="text" id="ownerLink" name="ownerLink" placeholder="e.g., /r/troubledteens/wiki/...">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label for="avgStay">Avg. Stay:</label>
                        <input type="text" id="avgStay" name="avgStay" placeholder="e.g., 100 days">
                    </div>
                    <div class="field-group">
                        <label for="tuition">Tuition:</label>
                        <input type="text" id="tuition" name="tuition" placeholder="e.g., $40,000+">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label for="natsapMember">NATSAP Member?</label>
                        <select id="natsapMember" name="natsapMember">
                            <option value="">Unknown</option>
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                            <option value="former">Former Member</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label for="natsapYear">NATSAP Since:</label>
                        <input type="text" id="natsapYear" name="natsapYear" placeholder="e.g., 1999">
                    </div>
                </div>
                <label for="diagnosesList">Target Diagnoses/Behaviors (comma-separated):</label>
                <input type="text" id="diagnosesList" name="diagnosesList" placeholder="e.g., Anxiety, Depression, ADHD, ODD">
                <div class="field-row">
                    <div class="field-group flex-2">
                        <label for="mainAddress">Main Address:</label>
                        <input type="text" id="mainAddress" name="mainAddress" placeholder="e.g., 280 N 300 E, Escalante, UT 84726">
                    </div>
                    <div class="field-group">
                        <label for="addressLink">Google Maps Link:</label>
                        <input type="text" id="addressLink" name="addressLink" placeholder="https://...">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label for="accreditingBody">Accrediting Body:</label>
                        <input type="text" id="accreditingBody" name="accreditingBody" placeholder="e.g., NWAC">
                    </div>
                    <div class="field-group">
                        <label for="accreditingBodyLink">Accreditor Wiki Link:</label>
                        <input type="text" id="accreditingBodyLink" name="accreditingBodyLink" placeholder="e.g., /r/troubledteens/wiki/...">
                    </div>
                </div>
                <hr>
                <h4>Additional Locations / Campuses</h4>
                <div class="form-adder">
                    <div class="field-row">
                        <div class="field-group">
                            <label for="campusName">Campus Name:</label>
                            <input type="text" id="campusName" placeholder="e.g., Girls Campus">
                        </div>
                        <div class="field-group">
                            <label for="campusLocation">Location:</label>
                            <input type="text" id="campusLocation" placeholder="e.g., Cedar City, UT">
                        </div>
                    </div>
                    <button type="button" class="add-btn" id="addCampusBtn">Add Campus</button>
                </div>
                <div class="list-preview" id="campusListOutput"></div>
                <hr>
                <h4>Ownership Changes</h4>
                <div class="form-adder">
                    <div class="field-row">
                        <div class="field-group">
                            <label for="ownerChangeYear">Year:</label>
                            <input type="text" id="ownerChangeYear" placeholder="e.g., 2015">
                        </div>
                        <div class="field-group">
                            <label for="ownerChangePrevious">Previous Owner:</label>
                            <input type="text" id="ownerChangePrevious" placeholder="e.g., Aspen Education">
                        </div>
                        <div class="field-group">
                            <label for="ownerChangeNew">New Owner:</label>
                            <input type="text" id="ownerChangeNew" placeholder="e.g., Sequel Youth">
                        </div>
                    </div>
                    <button type="button" class="add-btn" id="addOwnerChangeBtn">Add Ownership Change</button>
                </div>
                <div class="list-preview" id="ownerChangeListOutput"></div>
            </fieldset>

            <fieldset>
                <legend>Founders and Notable Staff</legend>
                <div class="form-adder">
                    <label for="staffName">Name:</label>
                    <input type="text" id="staffName">
                    <label for="staffRole">Role:</label>
                    <input type="text" id="staffRole">
                    <label for="staffBio">Bio/Details (can include Markdown links):</label>
                    <textarea id="staffBio" rows="3" placeholder="e.g., Previously worked at [Another Program](link)..."></textarea>
                    <label for="staffPreviousRoles">Previous Roles (one per line):</label>
                    <textarea id="staffPreviousRoles" rows="2" placeholder="e.g., Clinical Director at Another Campus"></textarea>
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
                <div class="field-row">
                    <div class="field-group">
                        <label for="levelSystemType">Level/Phase System:</label>
                        <select id="levelSystemType" name="levelSystemType">
                            <option value="">None/Unknown</option>
                            <option value="level">Level System</option>
                            <option value="phase">Phase System</option>
                            <option value="point">Point System</option>
                            <option value="tier">Tier System</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label for="levelCount">Number of Levels/Phases:</label>
                        <input type="text" id="levelCount" name="levelCount" placeholder="e.g., 4">
                    </div>
                </div>
                <h4>Level/Phase Details</h4>
                <div class="form-adder">
                    <div class="field-row">
                        <div class="field-group">
                            <label for="levelName">Level Name:</label>
                            <input type="text" id="levelName" placeholder="e.g., Orientation, Level 1">
                        </div>
                        <div class="field-group">
                            <label for="levelDuration">Typical Duration:</label>
                            <input type="text" id="levelDuration" placeholder="e.g., 2 weeks">
                        </div>
                    </div>
                    <label for="levelPrivileges">Privileges Earned (comma-separated):</label>
                    <input type="text" id="levelPrivileges" placeholder="e.g., phone calls, letters, outdoor time">
                    <label for="levelRestrictions">Restrictions (comma-separated):</label>
                    <input type="text" id="levelRestrictions" placeholder="e.g., no talking, no eye contact, constant supervision">
                    <button type="button" class="add-btn" id="addLevelBtn">Add Level</button>
                </div>
                <div class="list-preview" id="levelListOutput"></div>
                <hr>
                <h4>Education</h4>
                <div class="field-row">
                    <div class="field-group">
                        <label for="educationType">Education Provided:</label>
                        <select id="educationType" name="educationType">
                            <option value="">Unknown</option>
                            <option value="accredited">Accredited On-Site School</option>
                            <option value="online">Online/Computer-Based</option>
                            <option value="packet">Packet-Based/Worksheets</option>
                            <option value="limited">Limited/Sporadic</option>
                            <option value="none">None Provided</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label for="educationAccreditor">School Accreditor:</label>
                        <input type="text" id="educationAccreditor" name="educationAccreditor" placeholder="e.g., Cognia, NWAC">
                    </div>
                </div>
                <hr>
                <h4>Therapy/Treatment</h4>
                <div class="form-adder">
                    <div class="field-row">
                        <div class="field-group">
                            <label for="therapyType">Therapy Type:</label>
                            <select id="therapyType">
                                <option value="individual">Individual Therapy</option>
                                <option value="group">Group Therapy</option>
                                <option value="family">Family Therapy</option>
                                <option value="cbt">CBT</option>
                                <option value="dbt">DBT</option>
                                <option value="emdr">EMDR</option>
                                <option value="equine">Equine Therapy</option>
                                <option value="art">Art Therapy</option>
                                <option value="wilderness">Wilderness Therapy</option>
                                <option value="attack">Attack Therapy/Confrontation</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="field-group">
                            <label for="therapyFrequency">Frequency:</label>
                            <input type="text" id="therapyFrequency" placeholder="e.g., weekly, daily">
                        </div>
                    </div>
                    <button type="button" class="add-btn" id="addTherapyBtn">Add Therapy Type</button>
                </div>
                <div class="list-preview" id="therapyListOutput"></div>
            </fieldset>

            <fieldset>
                <legend>Rules and Punishments</legend>
                <h4>Rules</h4>
                <div class="form-adder">
                    <label for="ruleName">Rule:</label>
                    <input type="text" id="ruleName" placeholder="e.g., No talking without permission">
                    <button type="button" class="add-btn" id="addRuleBtn">Add Rule</button>
                </div>
                <div class="list-preview" id="ruleListOutput"></div>
                <hr>
                <h4>Punishments</h4>
                <div class="form-adder">
                    <div class="field-row">
                        <div class="field-group">
                            <label for="punishmentName">Name:</label>
                            <input type="text" id="punishmentName" placeholder="e.g., Stone Circle">
                        </div>
                        <div class="field-group">
                            <label for="punishmentType">Type:</label>
                            <select id="punishmentType">
                                <option value="physical">Physical</option>
                                <option value="isolation">Isolation</option>
                                <option value="restriction">Restriction</option>
                                <option value="humiliation">Humiliation</option>
                                <option value="labor">Labor/Work</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <label for="punishmentAction">What happens:</label>
                    <input type="text" id="punishmentAction" placeholder="e.g., student sits motionless for hours">
                    <label for="punishmentTrigger">Triggered by:</label>
                    <input type="text" id="punishmentTrigger" placeholder="e.g., talking without permission, rule violations">
                    <button type="button" class="add-btn" id="addPunishmentBtn">Add Punishment</button>
                </div>
                <div class="list-preview" id="punishmentListOutput"></div>
            </fieldset>

            <fieldset>
                <legend>Abuse/Neglect Allegations and Lawsuits</legend>
                <label for="mainComplaints">Main Complaint Types (comma-separated):</label>
                <input type="text" id="mainComplaints" name="mainComplaints" placeholder="e.g., emotional abuse, medical neglect, LGBTQ+ harassment">
                <hr>
                <h4>Specific Allegations</h4>
                <div class="form-adder">
                    <div class="field-row">
                        <div class="field-group">
                            <label for="allegationType">Type:</label>
                            <select id="allegationType">
                                <option value="physical">Physical Abuse</option>
                                <option value="emotional">Emotional Abuse</option>
                                <option value="sexual">Sexual Abuse</option>
                                <option value="medical">Medical Neglect</option>
                                <option value="educational">Educational Neglect</option>
                                <option value="isolation">Improper Isolation</option>
                                <option value="restraint">Improper Restraints</option>
                                <option value="food">Food Deprivation</option>
                                <option value="sleep">Sleep Deprivation</option>
                                <option value="lgbtq">LGBTQ+ Discrimination</option>
                                <option value="religious">Religious Coercion</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="field-group flex-2">
                            <label for="allegationDetail">Specific Detail:</label>
                            <input type="text" id="allegationDetail" placeholder="e.g., students forced to exercise until vomiting">
                        </div>
                    </div>
                    <button type="button" class="add-btn" id="addAllegationBtn">Add Allegation</button>
                </div>
                <div class="list-preview" id="allegationListOutput"></div>
                <hr>
                <h4>Lawsuits</h4>
                <div class="form-adder">
                    <div class="field-row">
                        <div class="field-group">
                            <label for="lawsuitYear">Year Filed:</label>
                            <input type="text" id="lawsuitYear" placeholder="e.g., 2018">
                        </div>
                        <div class="field-group">
                            <label for="lawsuitPlaintiff">Plaintiff(s):</label>
                            <input type="text" id="lawsuitPlaintiff" placeholder="e.g., John Doe">
                        </div>
                        <div class="field-group">
                            <label for="lawsuitDefendant">Defendant(s):</label>
                            <input type="text" id="lawsuitDefendant" placeholder="e.g., program name, owner">
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field-group">
                            <label for="lawsuitClaims">Claims (comma-separated):</label>
                            <input type="text" id="lawsuitClaims" placeholder="e.g., negligence, assault, false imprisonment">
                        </div>
                        <div class="field-group">
                            <label for="lawsuitOutcome">Outcome:</label>
                            <select id="lawsuitOutcome">
                                <option value="">Unknown/Pending</option>
                                <option value="settled">Settled</option>
                                <option value="dismissed">Dismissed</option>
                                <option value="plaintiff">Plaintiff Won</option>
                                <option value="defendant">Defendant Won</option>
                                <option value="ongoing">Ongoing</option>
                            </select>
                        </div>
                    </div>
                    <div class="field-row">
                        <div class="field-group">
                            <label for="lawsuitAmount">Settlement/Award Amount:</label>
                            <input type="text" id="lawsuitAmount" placeholder="e.g., $500,000, undisclosed">
                        </div>
                        <div class="field-group">
                            <label for="lawsuitCourt">Court/Jurisdiction:</label>
                            <input type="text" id="lawsuitCourt" placeholder="e.g., Utah District Court">
                        </div>
                    </div>
                    <button type="button" class="add-btn" id="addLawsuitBtn">Add Lawsuit</button>
                </div>
                <div class="list-preview" id="lawsuitListOutput"></div>
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
