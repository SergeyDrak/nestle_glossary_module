## CONTENTS OF THIS FILE


* Introduction
* Requirements
* Installation
* Configuration


## INTRODUCTION


The Nestle Glossary module replaces words in WYSIWYG texts with tooltips, using
a WYSIWYG filter. The source of the word list is a configurable taxonomy
vocabulary. The module provides a CKEditor plugin which can be used to add
tooltips by searching the configurable vocabulary.


## REQUIREMENTS


No special requirements


## INSTALLATION


Install as you would normally install a contributed Drupal module. Visit
https://www.drupal.org/docs/8/extending-drupal-8/overview for further
information.


## CONFIGURATION


The filter can be added to a WYSIWYG profile using the Text formats and
editors page on /admin/config/content/formats. When adding the filter
"Display tooltips in text" to the profile you can edit the following settings
in the configuration form:

* Source vocabulary: this is the vocabulary where you can add terms which
  are replaced in the texts, using the description as an explanation.

* Add tooltips automatically: when this option is checked the words will be
  replaced automatically.

* Limit occurrence: this option limits the number of replacements for a word.
  When set to -1 all occurrences will be replaces, when set to 1, only the
  first occurrence will be replaces, etc.

* Exclude tags: configure tags in which the words will not be replaced
  automatically. Eg. h1 h2 h3 etc.
