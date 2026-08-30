<?php
/*
  Template for profile-data.php.

  This holds all the NON-sensitive content shown openly on the page — name,
  DOB, medical conditions, allergies, medications, care team, history, and
  so on. It's split out from index.php so a future upgrade to the page
  template (index.php) never overwrites your filled-in content, and the
  template and your content can be edited independently.

  Copy this file to profile-data.php and fill in real values.
  profile-data.php is gitignored — same treatment as sensitive-data.php —
  so your real name and medical details are never committed to git, even
  though the live page shows them without the "reveal" gate.

  Sensitive fields (home address, phone numbers, NHS/policy number) do NOT
  live here — see sensitive-data.example.php for those.
*/
return [
    // ---- Identity ----
    'fullName' => '[Full Legal Name]',
    'preferredName' => '[Preferred Name]',
    'pronouns' => '[Pronouns, e.g. she/her]',
    'initials' => '[II]', // shown in the photo circle if photo.png is missing/fails to load
    'dob' => '[DD Month YYYY]',
    'language' => '[Preferred/Primary Language]',

    // ---- Critical medical ----
    'majorConditions' => '[e.g. Type 1 diabetes, epilepsy]',
    'allergies' => '[Allergen] → [Reaction, e.g. anaphylaxis] · Trigger: [trigger]',
    'currentMedsSummary' => '[Medication] [dose] — see full list below',
    'bloodType' => '[e.g. O+]',
    'implantedDevices' => '[e.g. Insulin pump, pacemaker — none if not applicable]',
    'mriFlags' => '[e.g. Metal implant in left hip — NOT MRI safe / MRI safe]',
    'resuscitationStatus' => '[e.g. Full resuscitation / DNR — advance directive held by [who/where]]',

    // ---- Emergency contacts (names/relationships only — phone numbers are sensitive) ----
    'primaryContactName' => '[Name]',
    'primaryContactRel' => '[Relationship]',
    'secondaryContactName' => '[Name]',
    'secondaryContactRel' => '[Relationship]',
    'nokName' => '[Name]',
    'nokRel' => '[Relationship] · Holds power of attorney for health: [Yes/No]',

    // ---- Medical care team (phone numbers are sensitive) ----
    'gpName' => '[Name]',
    'gpPractice' => '[Practice]',
    'specialists' => '[e.g. Dr [Name], Cardiologist, [Practice/Hospital]]',
    'hospital' => '[Hospital name]',
    'pharmacy' => '[Pharmacy name, location]',

    // ---- Medical history ----
    'surgeries' => '[Procedure] — [Date]',
    'chronicConditions' => '[Condition] — diagnosed [Date]',
    'hospitalisations' => '[Reason] — [Date/Hospital]',
    'immunisationNotes' => '[e.g. Tetanus booster [Date]; no known rabies exposure]',

    // ---- Medication detail ----
    // Add/remove rows freely — the table on the page loops over this list.
    'medications' => [
        ['name' => '[Medication]', 'dose' => '[Dose]', 'frequency' => '[Frequency]'],
        ['name' => '[Medication]', 'dose' => '[Dose]', 'frequency' => '[Frequency]'],
    ],
    'stoppedMeds' => '[Medication] — stopped [Date], [reason if relevant]',
    'drugAllergies' => '[Drug] — [Reaction]',
    'drugIntolerances' => '[Drug] — [Effect, e.g. nausea]',

    // ---- Practical / logistical (NHS/policy number is sensitive) ----
    'organDonorStatus' => '[e.g. Registered organ donor — NHS Organ Donor Register]',
    'advanceDirectiveLocation' => 'Held by [who], location: [where — e.g. GP practice, solicitor]',
    'communicationNeeds' => '[e.g. None / Deaf — uses BSL / Non-verbal / Autistic — prefers written communication / Needs interpreter: [language]]',
    'serviceAnimal' => '[e.g. None / Yes — [dog\'s name], must stay with owner]',

    // ---- Situational ----
    // These only apply some of the time. If a field doesn't apply to you,
    // either leave it blank ('') or delete the line entirely — an empty or
    // missing value means that row just won't show up on the page.
    'pregnancyStatus' => '[Not pregnant / Pregnant — due date [Date]]',
    'dietaryReligious' => '[e.g. Jehovah\'s Witness — refuses blood products / None]',
    'recentTravel' => '[Country/region] — returned [Date], [relevant exposure if any]',
    'dependents' => '[e.g. Two children (ages), collected by [contact] / Dog needs walking — key with neighbour [contact]]',
];
