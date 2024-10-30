=== Plugin Name ===
Contributors: vobuks
Donate link: 
https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BT9VZD6AMGU8J&item_name=iasCountries
Tags: countries, drop-down list, translation, English, German, French, Spanish, Italian, Russian, Deutsch, français, español, italiano, русский, по-русски, Länder, pays, países, paesi, страны, Übersetzung, traduction, traducción, traduzione, перевод
Requires at least: 2.9
Tested up to: 4.0
Stable tag: 1.0.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Manage country lists: translate, customize, export, select, dropdown,  group by region and parse url query to country code and name in any language.

== Description ==

Plugin provides set of methods to output array of countries or dropdown list in multiple languages, detect country by its url compatible name, select countries by region or build a custom selection of countries. These methods can be used for any plugin or application of yours. Entire process of country translation happens inside WordPress without the need of external tools. This plugin is multisite compatible. All country translations and lists can be customized within individual blogs. All translations are stored in WordPress database and queried directly from the database. Plugin is eco-friendly: leaves no traces after uninstall.

= Details =

* 250 countries in 6 built-in languages: English, German, French, Spanish, Italian, Russian.
* Add translation into any language.
* Customize built-in translation.
* Each country is provided with two-letter ISO code, url compatible name (slug) and region tag.
* Create drop-down list of countries in any available language.
* Export any translated set of countries to xml format, save on your computer, keep it as backup or use for any other application.
* Create a custom selection of countries.
* Group countries by region.
* Get country name and code from url query.
* Set default language of countries from built-in languages.

Read plugin's manual for more details.

== Installation ==

1. Upload ias-countries folder to the /wp-content/plugins/ directory
2. Activate the plugin through the 'Plugins' menu in WordPress

Plugin appears in "Tools" menu under "ias Countries" submenu.

== Frequently Asked Questions ==

= Parameters =

Plugin methods use following parameters.

**Language**

* two-letter ISO code (ISO 639-1): 'en' for English, 'de' for German etc. Translation doesn't consider language regional specifics.

**Country code**

* two-letter ISO code (ISO 3166-1 alpha-2): 'AT' for Austria, 'RU' for Russia etc.

**Region**

* *'africa'* for Africa, 
* *'antarctica'* for Antarctica, 
* *'asiapacific'* for Asia with Pacific Islands including Australia, 
* *'europe'* for Europe, 
* *'northamerica'* for North America, 
* *'southamerica'* for South America

Following countries are part of both Asia and Europe: Armenia, Azerbaijan, Georgia, Kazakhstan, Russia, Turkey.

Read plugin's manual for more details.

== Screenshots ==

1. Translate: add translation of countries
2. Customize: customize built-in translation
3. Selections: create country list based on selection
4. Export: export available translation as xml file
5. Settings: set default language 

== Changelog ==

= 1.0.1 =

* Improved creation of database tables
* Improved creation and removing of region terms in database
* Improved page navigation in admin area
* Improved form for removing translations in admin area

= 1.0.2 =

* Slight improvement in handling of xml