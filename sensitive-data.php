<?php
// ICE v1.0.1
/*
  Template for sensitive-data.php.

  Copy this file to sensitive-data.php and fill in real values.
  sensitive-data.php is gitignored — it will never be committed, and it is
  only read from disk by index.php after the correct unlock word has been
  submitted, so index.php never ships with, or leaks, real personal data
  by default.

  Phone numbers should be in a format that works after "tel:" is prepended,
  e.g. full international format like "+441234567890".
*/
return [
    'address' => '[Street Address, Town, Postcode]',
    'primaryPhone' => '+44[number]',
    'secondaryPhone' => '+44[number]',
    'nokPhone' => '+44[number]',
    'gpPhone' => '+44[number]',
    'nhsNumber' => '[NHS number / policy number]',
];
