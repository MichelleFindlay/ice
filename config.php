<?php
// ICE v1.0.1
/*
  Template for config.php.

  Copy this file to config.php and fill in real values.
  config.php is gitignored — same treatment as sensitive-data.php and
  profile-data.php, but for a different reason: this file holds the unlock
  word itself, so unlike the data files (which hold content this page
  eventually shows), this one must never be committed or the page's one
  privacy control is readable by anyone with access to the repo.
*/
return [
    // The word that unlocks the sensitive fields (address, phone numbers,
    // NHS/policy number). See the "UNLOCK WORD" comment near the top of
    // index.php for how this check works.
    'unlockWord' => 'reveal',

    // The URL this page is deployed at, e.g. 'https://ice.example.com/'.
    // Used for the canonical link tag in <head>, and available if you
    // later add things like a QR code linking to this page.
    'siteUrl' => 'https://example.com/',
];
