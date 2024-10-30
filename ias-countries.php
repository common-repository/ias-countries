<?php 
/*
Plugin Name: ias Countries
Plugin URI: http://wordpress.org/plugins/ias-countries/
Description: Manage country lists, translate, customize, export, select, dropdown, group by region and parse url query to country code and name in any language.
Author: ias vobuks
Version: 1.0.2
Text Domain: ias-countries
Domain Path: /lang
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/
	
if(!class_exists('iasCountries')){
	
	if (function_exists('add_action')){
		// registration hooks
		if(is_admin()){
			register_activation_hook(   __FILE__, array( 'iasCountries', 'activate' ) );
			// @todo if class exists, and is of this plugin; check with option's verification variable and proceed accordingly
			register_uninstall_hook( __FILE__, array( 'iasCountries', 'uninstall' ) );
		}
		
		add_action( 'plugins_loaded', array( 'iasCountries', 'init' ) );
	}
	
	/**
	 * plugin works with db
	 * built-in definitions are kept in xml files (/assets/xml/...), which can be used to restore database to predefined state
	 * built-in (predefined) set of country translations (stored in xml file) must include all countries, no empty field is permitted
	 * @todo: $float = is_rtl() ? 'right' : 'left';
	 */
	class iasCountries{
		// plugin version 			
		private static $_version = '1.0.1';
		
		// plugin options
		private static $_options;
		// option name where plugin options are saved (wp_options)
		private static $_option_name = 'ias_countries';
		
		// each selection has it's own entry in wp_options, name starts with the following and has an id suffix (selection found by it's id)
		private static $_slct_prefix = 'ias_cntrs_slct_';
		
				
		// admin area
		private static $_minParent = 'tools.php';
		private static $_minPage = 'ias_countries';
		
		private static $_prefix = 'ias_cntrs_';
		
		// taxonomy under which regions are stored in database
		private static $_taxonomy = 'ias_cntrs_region'; 
		
		// region slugs (must correspond to regions in default.xml)
		private static $_regions = array(
			'africa', 
			'antarctica', 
			'asiapacific', 
			'europe', 
			'northamerica', 
			'southamerica'
		);
		
		// table for country base information
		private static $_table_base = '';
		
		// table for country translations
		private static $_table_trans = '';
		
		// admin area messages
		private static $_notices = array();
		
		// feedback
		private static $_author_email = 'ias@vobuks.com';
		
		
		public static function init(){
			// session needed in admin area only
			if(is_admin() && !session_id()) session_start();
			
			// define constants
			self::constants();
			
			// set options
			self::setOptions();
			
			// init tables 
			self::init_tables();
			
			// load text domain
			load_plugin_textdomain('ias-countries', false, IAS_COUNTRIES_PLUGIN_DIR . '/lang/');
			
			// add plugin admin menu	
			add_action( 'admin_menu', array(__CLASS__, 'adminMenu') );	
			
			// works as form action
			add_action('init', array(__CLASS__, 'handleRequest'));
			
			// display notices
			self::checkNotices();
			add_action( 'admin_notices', array(__CLASS__, 'displayNotices') );
			
		}
		
		
		/**
		 * plugin constants
		 */
		private static function constants(){
			define( 'IAS_COUNTRIES_PLUGIN_DIR', basename( dirname(__FILE__) ) );
			define( 'IAS_COUNTRIES_PLUGIN_URL', untrailingslashit( plugin_dir_url(__FILE__) ) );
			define( 'IAS_COUNTRIES_PLUGIN_ABS', untrailingslashit( plugin_dir_path(__FILE__) ) );
			// temp directory
			define( 'IAS_COUNTRIES_PLUGIN_DIR_TEMP', IAS_COUNTRIES_PLUGIN_ABS . '\tmp' );
		}
		
		
		/**
		 * initiate options
		 */
		private static function setOptions(){
			$options = get_option(self::$_option_name);
				
			if($options){
				self::$_options = $options;
			}
			else{
				$default = self::getDefaultOptions();
				add_option(self::$_option_name, $default);
				self::$_options = $default;
			}
		}
		
		
		/**
		 * get options
		 * @todo decide whether to fallback empty options with defaults here (database options must be initiated before) 
		 */		
		private static function getOptions(){
			return self::$_options ? self::$_options : self::getDefaultOptions();
		}
		
		
		/**
		 * @param array $options key => value
		 * @return boolean
		 */
		private static function updateOptions($options){
			
			if(!is_null(self::$_options) && !empty($options)){
				
				foreach($options as $key => $value){
					self::$_options[$key] = $value;
				}
							
				// update db options
				return update_option(self::$_option_name, self::$_options);
				
			}
			
			return false;
		}
		
		
		/**
		 * one option entry for all plugin options (excl. 'selections' array)
		 * settings to enable working with plugin on activation
		 * customized translation saved to trans table
		 */
		private static function getDefaultOptions(){
			return array(
				'trans_defined' => array('de','en','es','fr','it','ru'), // built-in translations (predefined)
				'lang_country' => 'en', // default language of country name
				'lang_language' => '', // language of language names (if empty, use term in its own language)
				'per_page' => 25, // input items (country) per page
				'db_version' => 1000 // version of database 
			);
		}
		
		
		/**
		 * changes to static options should happen after static options are initiated and before next page load
		 * don't update if static options are not initiated (this can be changed, check what's best)
		 * @return boolean
		 */
		private static function updateOption($key, $value){
			
			if(!is_null(self::$_options)){
				self::$_options[$key] = $value;
				// update db options
				return update_option(self::$_option_name, self::$_options);
			}
			
			return false;
		}
		
		
		/**
		 * get option by its name in plugin options array
		 */
		private static function getOption($name){
			return isset(self::$_options[$name]) ? self::$_options[$name] : false;
		}
		
		
		/*============ LANGUAGES ===========*/
		/**
		 * list of declared languages (codes are defined and ready to use in database)
		 * list is saved under assets/xml/languages.xml
		 * list with translated language labels has a suffix at the end of the file in the same folder
		 * for preparing a custom translation of countries, corresponding language must be defined 
		 * @uses apply_filters() Calls 'ias_cntrs_languages_list' hook to define custom language (not predefined in xml), e.g. $languages['ho'] = 'Hiri Motu'; use ksort($languages) to sort final list
		 * @param string lang code - translate language labels into given language (if null set labels in original language)
		 * @return (array) code=>language, or false if file not found or any error while creating array
		 */
		public static function getLanguages($lang = null){
			$file = 'languages';
			$langfile = $lang ? $file.'-'.$lang : $file;
			
			if(!$languages = self::getXMLObject($langfile)){
				if(!$languages = self::getXMLObject($file))
					return false;
			}
			
			// filter list of languages
			return apply_filters('ias_cntrs_languages_list', (array)$languages);
		}
		
		
		/**
		 * @param string $code = target language (language iso code)
		 * @param string $lang (language iso code) = language of output (full name of language in the given $lang language); if no $lang given, name of code language is in native (code) language (de = Deutsch, ru = Русский)
		 * @return string language label translated into desired language
		 * 
		 */
		public static function getLanguageByCode($code, $lang = null){
			$langs = self::getLanguages($lang);
			return isset($langs->$code) ? $langs->$code : '';
		}
		
		
		/*=========== COUNTRY SELECTIONS ==========*/
		
		/**
		 * selections are saved into a separate option
		 */
		private static function getSelection($id){
			return get_option(self::$_slct_prefix . $id);
		}
		
		/**
		 * selections are saved into a separate option
		 */
		private static function updateSelection($id, $value){
			return update_option(self::$_slct_prefix . $id, $value);
		}
		
		/**
		 * selections are saved into a separate option
		 */
		private static function deleteSelection($id){
			return delete_option(self::$_slct_prefix . $id);
		}
		
		
		/*=================== DB ===============*/
		
		/**
		 * get countries among enabled languages with all concerned information (code, slug, region)
		 * if language is null -> use country_base terms
		 * fallback: if single lang is given as array, result is code => array(country_base, trans_country), this is single fallback
		 * fallback: if multiple languages or single lang as string given, replace empty trans_country by country_base
		 * orderby: string 'code'/'name'; code - country_code, name - trans_country (not null first); 
		 * orderby: if not specified:
			* no lang given: country_base
			* multiple langs: country_code
			* single fallback: country_base
			* default: trans_country (not null first)
		 * @param array $args
		 * @return array code => country_base (BASE)
		 * @return array code => country result (STANDARD)
		 * @return array code => array(country_base, trans_country) (SINGLE FALLBACK)
		 */
		private static function getDBCountries($args = array()){
			// tables
			$table_base = self::$_table_base;
			$table_trans = self::$_table_trans;
			$select = '';
			$join = '';
			$where = '';
			$vars = array(); // variables for sql placeholders
			// accepted alias for table fields (region data is not taken from plugin tables)
			$cols = array(
				'code' => 'country_code',
				'lang' => 'trans_lang',
				'base' => 'country_base',
				'slug' => 'country_slug'
			);
			$single_fallback = false; // array(country_base, trans_country) for one language only
			$region_field = false; // if region information needed (saved separately in term tables)
			
			$defaults = array(
				'lang' => null, // string/array; null - coutry_base; string - trans_country; array(one lang) = each code presented as array with base as fallback code => array(country_base, trans_country), fallback set to true automatically; array(multiple langs) - results in array where each code contains array of translations 'en' => 'Germany', 'de'=> 'Deutschland'...
				'index' => 'code', // string 'code'/'slug'; result is associated with this index in sorted array
				'fields' => null, // string/array for extra data fields only ('slug', 'region'); trans_country fields are defined automatically according to lang argument; 
				'slug' => null, // (filter) string (one item) / array, has priority over other filters, filter by country slug (include countries with this slug)
				'include' => null,// (filter) string (one item)/array of country codes to include
				'exclude' => null, // (filter) string (one item) / array of country codes to exclude
				'selection' => null, // (filter) integer custom selection id
				'region' => null, // (filter) (join only) string/array filter by region (africa, antarctica, asiapacific, europe, northamerica, southamerica)
				'custom' => false, // (filter)(join only) boolean, limit to customized values only (trans_custom = 1 in db)
				'incl_empty' => true, // (filter)(join only) include empty country names (no translation available for current language), no effect on country_base result, this option is effective when only one language is selected
				'orderby' => null, // string 'code'/'name'
				'order' => 'ASC', // string 'ASC'/'DESC' 
				'offset' => 0, // page*perPage
				'page' => null, // if page given, ignore offset and calculate it according to the page number
				'per_page' => null, // accept also -1 as all (convert it to null), default per page value = self::getOption('per_page')
				'fallback' => false, // (join only) boolean replace with base term empty country name in current translation
			);
					
			$args = wp_parse_args($args, $defaults);
			extract($args);
			
			// validate index (must be among defined cols)
			$index = in_array($index, array_keys($cols)) ? $index : $defaults['index'];
			
			// prepare orderby: allow alias 'code'/'name'
			if($orderby && !in_array($orderby, array('code', 'name'))) $orderby = null;
			
			// prepare fields
			if($fields){
				$field_cols = array();
				foreach( (array) $fields as $field){
					// handle region field in region join
					if($field == 'region') {
						$region_field = true;
						continue; 
					}
					if(!isset($cols[$field])) continue; 
					$field_cols[$field] = $cols[$field];
				}
				// empty array if no validated col names
				$fields = $field_cols;
			}
			
			// prepare langs
			if($lang == 'all'){
				// 'all' languages (don't validate )
				if(!$lang = self::getDBCountryLangs()) 
					$lang = null; // get 'country_base' 
			}
			else if($lang){
				// validate langs before defining sorting fields and creating sql
				if(is_array($lang)){
					$start = count($lang);
					$lang = array_filter($lang, array(__CLASS__, 'validCode'));
					$end = count($lang);
					if( $end !== $start && $end === 1 ){ 
						$lang = reset($lang); // fallback array was not opted
					}
				}
				else{
					$lang = self::validCode($lang);
				}
				
				// no valid lang given and fallback is not wanted
				if(!$lang && !$fallback)
					return $lang;
			}
						
			// validated langs or null lang for country_base
			// if region field is required, keep $fields as associative array
			$count_langs = 0;
			if($lang){
				if(is_array($lang)){
					$count_langs = count($lang);
					// lang as array
					if($count_langs === 1){
						// array(base, trans) is possible when no other fields added to the query
						if($fields){
							// add to fields
							$fields[$lang[0]] = 'trans_country';
						}
						else{
							// array(base, trans)
							$fields = array('trans_country');
							// this data output is single fallback
							$single_fallback = true;
						}
						
						$fallback = true;
					}
					else{
						// multiple lang fields for data sorting and sql select are defined during join block
						$fields = $fields ? $fields : array();
					}
				}
				else{
					$count_langs = 1;
					// single lang
					if($fields || $region_field){
						// add to fields
						$fields[$lang] = 'trans_country';
					}
					else{
						// overwrite empty array (or null)
						$fields = 'trans_country';
					}
					$lang = (array) $lang; // after type of sorting defined, convert lang to array type (for building sql query)
				}
			}
			else{
				// (no lang given) use country base
				if($fields || $region_field){
					$l = self::getOption('lang_country');
					// add to fields
					$fields[$l] = 'country_base';
				}
				else{
					// overwrite empty array (or null)
					$fields = 'country_base';
				}
			}
						
			// fallback: db doesn't store empty values for custom translations
			if($custom) $fallback = false; 
			
			// include empty trans_country fields if fallback is activated
			if($fallback) $incl_empty = true;
					
			// per page
			if($per_page == -1){
				$per_page = null;
			}
			
			// without per_page var, page has no value
			if($page && $per_page){
				$offset	= ((int) $page - 1) * (int) $per_page;
			}
			
			$offset = intval($offset) < 0 ? 0 : (int) $offset;

			
			global $wpdb;			
			// table_base is a skeleton for all joined tables
						
			/*------------ select ------------*/
			
			// fields argument is never empty
			if(is_array($fields)){
				foreach($fields as $key => $field){
					$select .= ', '.$field;
				}
			}
			else{
				$select .= ", $fields";
			}

			// add contry_base if fallback true
			if($fallback){
				if(strpos($select, 'country_base') === false)
					$select .= ", country_base"; // avoid duplicates
			}
			
			/*----------- join ------------*/
			
			// $lang is an array
			if($lang){
				// all trans tables are joined with an alias "tr$i" 
				// (trans table needed when $lang is given)
				$ph = "'%s'";
				$i = 1;
				
				// join for each additional language
				foreach($lang as $l){
					// trans table alias
					$trans = "tr$i";
					
					// single language case defined above
					if($count_langs > 1){
						// lang field for sorting
						$fields[$l] = $l;
						$select .= ", $trans.trans_country AS {$l}";
					}
					
					$join .= " LEFT JOIN $table_trans AS $trans ON $table_base.country_code = $trans.country_code AND $trans.trans_lang = $ph";
							
					// placeholder vars
					$vars[] = $l;
					$i++;
				}
			}
			
			// get region from wp terms 
			if($region || $region_field){
				$taxonomy = self::$_taxonomy;
				// relationship
				$join .= " LEFT JOIN $wpdb->term_relationships AS tr ON tr.object_id = $table_base.country_id AND term_taxonomy_id IN (SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE taxonomy = '$taxonomy')";
				// taxonomy
				$join .= " LEFT JOIN $wpdb->term_taxonomy AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id";
				// region term
				$join .= " LEFT JOIN $wpdb->terms AS terms ON terms.term_id = tt.term_id";
				
				// collect region information under 'region' key, if region is among 'fields' arguments
				if($region_field){
					$select .= ", terms.slug AS region";
					// add region field for data sorting
					$fields = (array)$fields;
					$fields['region'] = 'region';
				}
			}
			
			/*-------------- where -------------*/
			
			if($custom && $lang){
				// custom makes sense only if 'lang' argument is set (country_base has no custom values)
				$where .= $where == '' ? " WHERE " : " AND ";
				$where .= "trans_custom = 1";			
			}
						
			// where filter (in priority order)
			if($slug){
				$filter_vars = (array) $slug; // convert string to array
				$filter = 'slug';
				$col = $cols[$filter];
			}
			else if($include) {
				$filter_vars = (array) $include;
				$filter = 'include';
			}
			else if($exclude){	
				$filter_vars = (array) $exclude;
				$filter = 'exclude';
			}
			else if($selection){
				$filter_vars = self::getSelection($selection);
				// selection not defined
				if(!$filter_vars) return array(); 
				$filter = 'include';
			}
			else if($region){
				$filter_vars = (array) $region; // convert string to array
				$filter = 'region';
				$col = 'terms.slug'; // read region from wp terms
			}
			
			if(isset($filter)){
				$ph = '';
				$filter_count = count($filter_vars); 
				for($i=0; $i < $filter_count; $i++){
					// validate
					if(!$var = self::validateDBArg($filter_vars[$i], $filter)) continue;
					
					if($ph != '') $ph .= ", ";
					$ph .= "'%s'";
					
					$vars[] = $var;
				}
				
				// filtered cols are country_code, country_slug, country_region
				if(!isset($col)){
					$col =  $table_base.'.country_code';
				}
								
				$not = $filter == 'exclude' ? 'NOT' : '';
				$where .= $where == '' ? " WHERE " : " AND ";
				$where .= "$col $not IN ($ph)";
			}
			
			// include empty, effective for one language selection
			if(!$incl_empty && $count_langs === 1){
				$where .= $where == '' ? " WHERE " : " AND ";
				$where .= "trans_country IS NOT NULL";
			}
			
			// order
			if($orderby){
				if($orderby == 'code'){
					$orderby = $table_base.'.country_code';	
				}
				else if($orderby == 'name'){
					if($lang){
						if($single_fallback || $count_langs == 1){
							// available translation is at the top
							$orderby = 'ISNULL(trans_country) ASC, trans_country';
						}
						else{
							// in case of multiple languages default order is country code
							$orderby = $table_base.'.country_code';	
						}
					}
					else{
						$orderby = 'country_base';
					}
				}
			}
			else{
				if(!$lang){
					$orderby = 'country_base';
				}
				else if($count_langs > 1){
					$orderby = $table_base.'.country_code';
				}
				else{
					if($single_fallback){
						$orderby = 'country_base'; // order single fallback by country base (if orderby 'name' is given in arguments - order by trans_country as normal)
					}
					else{
						// available translation is at the top
						$orderby = 'ISNULL(trans_country) ASC, trans_country';
					}
				}
			}
			
					
			// QUERY
			
			// avoid ambiguity of country_code (any index incl slug will appear with primary table name as prefix)
			$index_field = isset($cols[$index]) ? $table_base.'.'.$cols[$index] : $table_base.'.country_code';
			
			$qry = "SELECT $index_field AS $index{$select}
					FROM $table_base {$join} {$where}
					ORDER BY $orderby $order";
			
			// order fallback country_base items
			if($fallback){
				$qry .= ", country_base ASC";		
			}
			
			if($per_page){
				$qry .= " LIMIT %d";
				$vars[] = $per_page;
			}
			if($offset !== 0){
				$qry .= " OFFSET %d";
				$vars[] = $offset;
			}
			
			//echo $qry;
			
			// prepare if variables were given
			if(!empty($vars)){
				array_unshift($vars, $qry);
				$data = $wpdb->get_results(call_user_func_array(array($wpdb, 'prepare'), $vars));
			}
			else{
				$data = $wpdb->get_results($qry);
			}
			
			if(!$data) return $data;
			
			// sort data
			return self::sortDBCountries($data, $fields, $index, $fallback);
		
		}
		
		
		/**
		 * general validation method for language and country codes
		 * compatible with array_filter method
		 */
		private static function validCode($input){
			$input = trim($input);
			
			if(strlen($input) != 2) return false;
			
			return $input;
			
		}
		
		
		/*
		 * @param string $input argument given
		 * @param string $type validation type (lang, include, exclude, region)
		 * @return valid string or false on failure
		 */
		private static function validateDBArg($input, $type){
			$input = trim($input);
			
			if(in_array($type, array('lang', 'include', 'exclude'))){
				if(strlen($input) != 2) return false;
			}
			
			return $input;
		}
		
		
		/**
		 * sort countries as array code/slug against string/array data
		 * @param array $data - result of database query
		 * @param string/array $fields - column name (e.g. trans_country, country_base),
		 * accept data for defined fields only
		 * array $fields contains 'key' (in sorted result) => property (in source data)
		 * array $fields has numeric keys (non associative) only in case of single fallback format (for the moment); 
		 * if fallback true, string/multiple languages: replace empty trans_country field with country_base fields; single fallback: code => array(base, trans)
		 * @param string $index = index in sorted array result
		 * @param boolean $fallback, if data result includes fallback
		 */
		private static function sortDBCountries($data, $fields, $index = 'country_code', $fallback = false){
			$sorted = array();
			
			if(empty($data)) return $sorted;
			if(is_array($fields)){
				if(is_numeric(key($fields))){
					// non associative array of fields
					foreach((array) $data as $c){
						// add country_base on top of the array, if fallback true
						if($fallback){
							$sorted[$c->$index][] = !is_null($c->country_base) ? $c->country_base : '';
						}
						foreach($fields as $field){
							$sorted[$c->$index][] = !is_null($c->$field) ? $c->$field : '';
						}
					}
				}
				else{
					foreach((array) $data as $c){
						foreach($fields as $key => $field){
							if($key == 'region'){
								// collect region data into an array
								$sorted[$c->$index][$key][] = $c->$field;
								continue;
							}
							
							// fallback
							if(!$c->$field){
								// fallback is meant for trans_country fields only, in case of multiple languages key = field (alias of trans_country is language code, e.g. en = en)
								$sorted[$c->$index][$key] = in_array($field, array($key, 'trans_country')) && $fallback && !is_null($c->country_base) ? $c->country_base : ''; 
								continue;
							}
							
							// otherwise as standard string data
							$sorted[$c->$index][$key] = $c->$field;
							
						}
					}
				}
			}
			else{
				foreach((array) $data as $c){
					// fallback
					if(is_null($c->$fields)){
						$sorted[$c->$index] = $fields == 'trans_country' && $fallback && !is_null($c->country_base) ? $c->country_base : ''; // fallback is meant for trans_country fields only
						continue;
					}
					
					$sorted[$c->$index] = $c->$fields;
				}
			}
			
			return $sorted;
		}
		
		
		/**
		 * get all country codes as array
		 */
		private static function getDBCountryCodes(){
			global $wpdb;
			$table_base = self::$_table_base;
			$qry = "SELECT country_code
				FROM $table_base 
				ORDER BY country_code";
			return $wpdb->get_col($qry);
		}
		
		
		/**
		 * get total number of distince countries defined in database (table_base)
		 * @return integer / false if db failed
		 */
		private static function getDBCountryCount(){
			global $wpdb;
			$table_base = self::$_table_base;
			$count = $wpdb->get_var("SELECT COUNT(DISTINCT(country_code)) FROM $table_base");
			 return $count ? (int) $count : $count;
		}
		
		
		/**
		 * default language is always included into database in table_base as country_base
		 */
		private static function getDBCountryBase($page = null, $per_page = null){
			$args = array(
				'page' => $page,
				'per_page' => $per_page
			);
			
			return self::getDBCountries($args);
		}
		
		
		/**
		 * for languages with custom translation (some terms may missing)
		 * SINGLE FALLBACK output
		 * orderby: country_base (nothing set)
		 * orderby: alternatives 
					'code' - country_code, 
					'name' - trans_country (available first)
		 * @return array code => array(baseLabel, transLabel)
		 */
		private static function getDBCountryTranslationSet($lang, $page = null, $per_page = null){
			$args = array(
				'lang' => array($lang),
				'page' => $page,
				'per_page' => $per_page			
			);
			
			return self::getDBCountries($args);
		}
		
		
		/**
		 * get languages available in database
		 * these translation sets can be exported
		 * @return array of language codes
		 * @return null, if no translation available
		 */
		private static function getDBCountryLangs(){
			global $wpdb;
			$table_trans = self::$_table_trans;
			$languages = $wpdb->get_col("SELECT DISTINCT(trans_lang) FROM $table_trans");
			return !$languages ? null : $languages;
		}
		
		
		/**
		 * get languages for customized translation available in database
		 * @return array of language codes
		 * @return null, if no translation available
		 * @since 1.0.1.
		 */
		private static function getDBCustomCountryLangs(){
			global $wpdb;
			$table_trans = self::$_table_trans;
			$languages = $wpdb->get_col("SELECT DISTINCT(trans_lang) FROM $table_trans WHERE trans_custom = 1");
			return !$languages ? null : $languages;
		}
		
		
		/**
		 * get country codes with custom translation for the given lang
		 */
		private static function getDBCustomTransCodes($lang){
			global $wpdb;
			$table_base = self::$_table_base;
			$table_trans = self::$_table_trans;
			$qry = "SELECT country_code
					FROM $table_trans AS codes
					WHERE trans_custom = 1 AND trans_lang = '%s'";
			return $wpdb->get_col($wpdb->prepare($qry, $lang));
		}
		
		
		/**
		 * check if given country has translation for a given language
		 * @return boolean false if negative or string country's translation if positive
		 */ 
		private static function hasTrans($code, $lang){
			global $wpdb;
			$table_base = self::$_table_base;
			$table_trans = self::$_table_trans;
			$qry = "SELECT trans_country 
					FROM $table_trans
					WHERE country_code = '%s' AND trans_lang = '%s'";
			
			return $wpdb->get_var($wpdb->prepare($qry, $code, $lang));		
		}
				
		
		/**
		 * STANDARD output
		 * get country names among enabled languages
		 * @param string $lang - two character lang iso code
		 * @param boolean $custom, filter out custom entries (if true, show only modified translations, trans_custom = 1, created/modified by user)
		 * @return array 'country_code' => 'country_label'
		 */
		private static function getDBCountryLables($lang, $custom = false, $fallback = false, $page =  null, $per_page = null){
			$args = array(
				'lang' => $lang,
				'custom' => $custom,
				'fallback' => $fallback,
				'page' => $page,
				'per_page' => $per_page,
				'incl_empty' => false
			);
			return self::getDBCountries($args);
		}
				
		
		/*======== XML =========*/
		
		/*------- SAVE XML ---------*/
		
		/**
		 * @param string $parent data identifier (used as parent tag in xml)
		 * @param $file file name only without extension; if false, don't save, output xml code
		 */
		private static function saveToXML($data, $parent, $file = false, $lang = null){
			
			$dom = new DOMDocument('1.0',  'UTF-8');
			
			// parent
			$parent = $dom->appendChild($dom->createElement($parent));
			
			if(!is_null($lang)){
				$parent->setAttribute('xml:id', $lang);
			}
			
			self::convertToXML($data, $parent, $dom);
		
			$dom->preserveWhiteSpace = false;
			$dom->formatOutput = true;
		
			// output ready xml code only
			if(!$file){
				return $dom->saveXML();
			}
			
			$file = IAS_COUNTRIES_PLUGIN_ABS . '/assets/xml/'.$file.'.xml';		
			$dom->save($file);
		}
		
		
		/**
		 * convert array to xml format
		 * @param string/array $data - data to be converted
		 * @param string $parent parent tag
		 * @param DOM Object $dom
		 */
		private static function convertToXML($data, $parent, DOMDocument $dom){
			if(is_array($data)){
				foreach($data as $key => $value) {
					if(is_array($value)) {
						if(!is_numeric($key)){
							$subnode = $dom->createElement("$key");
						}
						else{
							$subnode = $dom->createElement("item$key");
						}
						$parent->appendChild($subnode);
						self::convertToXML($value, $subnode, $dom);
					}
					else {
						$child = $dom->createElement("$key",htmlspecialchars("$value"));
						$parent->appendChild($child);
					}
				}
			}
			else{
				// add text string without tags
				$parent->appendChild($dom->createTextNode($data));
			}
		}
		
		/*------- READ/IMPORT XML ---------*/
		
		/**
		 * @param string $basename = file base name only without extension
		 */
		private static function getXMLObject($basename){
			$file = IAS_COUNTRIES_PLUGIN_ABS . '/assets/xml/'.$basename.'.xml';
			return self::parseXML($file);
		}
		
		
		/**
		 * get content of xml file as object
		 * @param string $file full path to the file
		 */
		private static function parseXML($file){
			if(!file_exists($file)) return false;
			return simplexml_load_file($file); 
		}
		
			
		/**
		 * get translations list according to available xml files
		 * read content of '/assets/xml/' folder
		 * get suffixes from searched files
		 * @param string $file - file name without translation suffix and file extension (languages, countries)
		 * @return array of language codes
		 */
		private static function getXMLAvailableTranslations($file){
			$languages = array();
		
			if ($handle = opendir(IAS_COUNTRIES_PLUGIN_ABS .'/assets/xml/')) {
				while (false !== ($entry = readdir($handle))) {
					if(strpos($entry, $file.'-') === false) continue;
					$languages[] = str_replace($file.'-', '', basename($entry, '.xml'));
				}

				closedir($handle);
			}
					
			return $languages;		
		}
		
		
		/**
		 * get languages of predefined country sets
		 * option trans_defined (no custom translations accepted) and available files (not corrupted after plugin installation)
		 * @todo if xml file for predefined language is lost but still available in database it is considered as custom and will be edited in 'Translate' area
		 */
		private static function getXMLBuiltInLangs(){
			$available = self::getXMLAvailableTranslations('countries'); // currently available
			$predefined = self::getOption('trans_defined'); // available on plugin installation
			
			return array_intersect($predefined, $available);
		}
		
		
		/**
		 * get predefined translation for a given country code in a given language
		 * @return string country or empty string on failure
		 */
		private static function getXMLCountryPredefined($code, $lang){
			$countries = self::getXMLObject('countries-'.$lang);
			return isset($countries->$code) ? (string)$countries->$code : '';
		}
		
		
		/**
		 * get predefined country list in desired language from XML file (ordered by country names)
		 * use as default country names (left column of translation and customization)
		 * @return array of countries code => name, false if nothing
		 */
		private static function getXMLCountriesPredefined($lang, $page = null, $perPage = null){
			$countries = self::getXMLObject('countries-'.$lang);
			
			if(is_null($page) || is_null($perPage)){
				return (array) $countries;
			}
			
			return array_slice((array)$countries, ((int) $page - 1)*$perPage, (int) $perPage);
		
		}
		
		
		/**
		 * set country_base in wp_ias_countries_base table of database to default language, which was defined by user
		 * default language can be selected out of built-in (predefined) translation sets of countries
		 * predefined XML file used (countries-{lang}.xml)
		 * @todo before updating check that XML file is not corrupted and all countries are defined (compare with db)
		 */
		private static function setCountryBase($lang){
			$countries = (array)self::getXMLCountriesPredefined($lang);
			
			if(empty($countries)) return false;
			
			$func = function($base, $code){
				global $wpdb;
				$wpdb->update(
					self::$_table_base,
					array('country_base' => $base),
					array('country_code' => $code),
					array('%s'),
					array('%s')
				);
			};
			
			array_walk($countries, $func);
			
		}
		
		
		/*-------------- import --------------*/
		/**
		 * verify that imported file is in prescribed format
		 * otherwise return false
		 * only predefined country codes are permitted
		 * @param string $file full path to the file
		 * @return sorted array or array with 'error' key on failure
		 */
		private static function readXMLImport($file){
			$import = (array) self::parseXML($file);
			return self::sortImport($import);
		}
		
		
		/**
		 * as txt or csv file
		 * format accepted
		 * BO:Bonaire
		 * BO;Bonaire
		 * http://social.technet.microsoft.com/wiki/contents/articles/10305.how-to-convert-format-excel-to-csv-with-semicolon-delimited.aspx
		 * @todo: add error handling
		 */
		private static function readCSVImport($file){
			$countries = array();
			if (!empty($file) && ($handle = fopen($file, "r")) !== false) {
									
				while (($data = fgets($handle, 4096)) !== false) {
					// detetect used delimiter
					$delimiter = preg_match( '/[;|:]/', $data, $match);
					
					if(empty($delimiter)) continue; // check all lines
					
					$data = explode($match[0], $data);
					$countries[trim($data[0])] = isset($data[1]) ? trim($data[1]) : '';
				}
				
				fclose($handle);
			}
			
			return self::sortImport($countries);
		}
		
		
		/**
		 * @param array $import data array, code -> country
		 * @todo: improve sanitizing (Åland is removed)
		 */
		private static function sortImport($import){
			$error = array(
				'error' => __('Imported file is empty or its content is not of supported format.', 'ias-countries')
			);
			
			if(empty($import)) return $error;
			
			// predefined set of codes allowed
			$codes = self::getDBCountryCodes();
			
			$sorted = array();
			// sanitize import
			foreach($import as $code => $label){
				if(!is_string($code) || !in_array($code, $codes) || !is_string($label)) continue;
				
				$sorted[$code] = $label;
			}
			
			if(empty($sorted))	
				return $error;
						
			return $sorted;
		}
		
		/*======= ADMIN AREA PAGES =======*/
		
		/**
		 * admin area tabs: translate, customize, selections, export, settings, manual
		 */
		// add admin page under 'Tools'
		public static function adminMenu(){
			$tools_page = add_management_page( __('ias Countries', 'ias-countries'), __('ias Countries', 'ias-countries'), 'manage_options', self::$_minPage, array(__CLASS__, 'toolsPage'));
			
			// load list style
			add_action( 'admin_print_styles-' . $tools_page, array(__CLASS__, 'toolsStyle') );		
		}

		public static function toolsStyle(){
			wp_enqueue_style( 'ias-countries-min', IAS_COUNTRIES_PLUGIN_URL . '/assets/css/style-admin.css' );
		}
		
			
		/**
		 * tools page for managing plugin
		 */
		public static function toolsPage(){
			
			if(!function_exists('current_user_can') || !current_user_can('manage_options')){
				die(__('You have no permission to edit this page.', 'ias-countries'));
			}
			?>
			
			<div class="wrap">
						
				<!-- tabs -->
				<?php 
					
					$tabs = self::toolsPageTabs();
					
					$active_tab = isset( $_GET[ 'tab' ] ) ? $_GET[ 'tab' ] : key($tabs); 
					
				?>
				<h2><?php _e('ias Countries', 'ias-countries'); ?></h2>			
				<h2 class="nav-tab-wrapper">
					<?php 
					foreach($tabs as $tab => $v){
						$active_class = $active_tab == $tab ? 'nav-tab-active' : '';
						$path = self::$_minParent .'?page='.self::$_minPage.'&tab='.$tab;
						
						echo '<a href="'.admin_url($path).'" class="nav-tab '. $active_class .'">'.$v[0].'</a>';
					}
					?>
				</h2>
				
				<?php call_user_func($tabs[$active_tab][1]); ?>
			
			</div>
			
			<?php
			
		}
		
		
		/**
		 * each tab array has label and page content method
		 */
		private static function toolsPageTabs(){
			return array(
				'translate' => array( __('Translate', 'ias-countries'), array(__CLASS__, 'minPageTranslate')),
				'customize' => array( __('Customize', 'ias-countries'), array(__CLASS__, 'minPageCustomize')),
				'selections' => array( __('Selections', 'ias-countries'), array(__CLASS__, 'minPageSelections')),
				'export' => array( __('Export', 'ias-countries'), array(__CLASS__, 'minPageExport')),
				'settings' => array( __('Settings', 'ias-countries'), array( __CLASS__, 'minPageSettings')),
				'manual' => array( __('Manual', 'ias-countries'), array( __CLASS__, 'minPageManual') )
			);
		}
		
		
		/**
		 * translate countries into non predefined languages
		 */
		private static function minPageTranslate(){
			// total country count 
			$count = isset($_GET['lang']) && $_GET['lang'] != '0' ? self::getDBCountryCount() : 0;
			$perPage = self::getOption('per_page');
			$pages = (int) ceil($count/$perPage);
			?>
			<div id="translate">
				<h3><?php _e('Translate countries', 'ias-countries'); ?></h3>
				
				<div id="col-container">
					<div id="col-left">
						<div class="col-wrap">
								
						<!-- top table navigation -->
						<?php self::tableNav('top', $count, $perPage); ?>
						
						<?php
						if($count !== 0):
							$lang = $_GET['lang'];
							// paged for countries page
							$page = self::getCurrentPage($pages);
							// array code => array(base, translation)
							$trans = self::getDBCountryTranslationSet($lang, $page, $perPage);
							$i = 0;
						?>				
							<!-- input form -->
							<form method="post">
								
								<?php self::createPostRef('translate'); ?>
								
								<!-- set url query for reference and fallback redirect -->
								<input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>">
								<input type="hidden" name="paged" value="<?php echo esc_attr($page); ?>">
											
								<!-- two columns table: base language set, custom translation -->
								<table class="wp-list-table widefat fixed">
									<thead>
										<th width="24"><?php _e('ISO', 'ias-countries'); ?></th>
										<th width="30%"><?php _e('Country', 'ias-countries'); ?></th>
										<th><?php _e('Translation', 'ias-countries'); ?></th>
									</thead>
									<tbody>
									<?php if(!empty($trans)): ?>
										<?php foreach($trans as $code=>$t): $i++;?>
											<tr <?php echo $i & 1 ? 'class="alternate"' : ''; ?>>
												<td width="24"><?php echo $code; ?></td>
												<td width="30%"><?php echo $t[0]; ?></td>
												<td><input type="text" name="countries[<?php echo esc_attr($code); ?>]" value="<?php echo esc_attr($t[1]); ?>" class="widefat"></td>
											</tr>
									<?php endforeach; ?>
									<?php endif; ?>
									</tbody>
								</table>
								<br class="clear">						
								<!-- submit button -->
								<p class="submit">
									<input id="submit" class="button button-primary" type="submit" value="<?php _e('Save Translation', 'ias-countries')?>" name="submit">
								</p>
							</form>
						
						<?php endif; ?>
						
						</div>
					</div>
					
					<div id="col-right">
						<div class="col-wrap">
						<?php 
						if($count !== 0):
							self::importForm();
							self::removeForm(array_keys($trans));
						endif;
						?>
						</div>
					</div>
				
				</div>
			</div>
		<?php		
		}
		
		
		/**
		 * customize predefined translation
		 */
		private static function minPageCustomize(){
			// total country count 
			$count = isset($_GET['lang']) && $_GET['lang'] != '0' ? self::getDBCountryCount() : 0;
			$perPage = self::getOption('per_page');
			$pages = (int) ceil($count/$perPage);
			?>
			<div id="customize">
				<h3><?php _e('Customize built-in translation', 'ias-countries'); ?></h3>
				
				<div id="col-container">
					<div id="col-left">
						<div class="col-wrap">
						
						<!-- top table navigation -->
						<?php self::tableNav('top', $count, $perPage); ?>
						
						<!-- submit export -->
						<?php if($count !== 0):
							$lang = $_GET['lang'];
							// paged for countries page
							$page = self::getCurrentPage($pages);
							// select: list of languages having predefined translations
							
							// predefined set of countries in $lang
							$countries = self::getXMLCountriesPredefined($lang, $page, $perPage);
							// available customizations from db
							$custom = self::getDBCountryLables($lang, true);
							$i = 0;
							?>
							
							<!-- input form -->
							<form method="post">
								
								<?php self::createPostRef('customize'); ?>
								<!-- set url query for reference and fallback redirect -->
								<input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>">
								<input type="hidden" name="paged" value="<?php echo esc_attr($page); ?>">
								
								<!-- two columns table: predefined, modified translation -->
								<table class="wp-list-table widefat fixed">
									<thead>
										<th width="24"><?php _e('ISO', 'ias-countries'); ?></th>
										<th width="30%"><?php _e('Country', 'ias-countries'); ?></th>
										<th><?php _e('Customization', 'ias-countries'); ?></th>
									</thead>
									<tbody>
									<?php if(!empty($countries)): ?>
										<?php foreach($countries as $code=>$label): $i++;
											$customLabel = isset($custom[$code]) ? $custom[$code] : '';
											?>
											<tr <?php echo $i & 1 ? 'class="alternate"' : ''; ?>>
												<td width="24"><?php echo $code; ?></td>
												<td width="30%"> <?php echo $label; ?></td>
												<td><input type="text" name="countries[<?php echo esc_attr($code); ?>]" value="<?php echo esc_attr($customLabel); ?>" class="widefat"></td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
									</tbody>
								</table>
								<br class="clear">
								<!-- submit button -->
								<p class="submit">
									<input id="submit" class="button button-primary" type="submit" value="<?php _e('Update Translation', 'ias-countries'); ?>" name="submit">
								</p>
							</form>
							
						<?php endif; ?>
						
						</div>
					</div>
					
					<div id="col-right">
						<div class="col-wrap">
						<?php 
						if($count !== 0):
							self::importForm();
							self::removeForm(array_keys($countries));
						endif;
						?>
						</div>
					</div>
				
				</div>
			</div>	
		
		<?php 
		}
		
		
		/**
		 * selection of first id is default
		 * tableNav: select id, page navigation
		 * sidebar: select all/none; all/non on page
		 */
		private static function minPageSelections(){
			$tab = isset($_GET['tab']) ? $_GET['tab'] : 'selections';
			// selection id
			$set = isset($_GET['set']) ? $_GET['set'] : 1;
									
			$i = 0;
			$cols = 2;
			
			$count = self::getDBCountryCount();
			$perPage = $cols*self::getOption('per_page');
			$pages = (int) ceil($count/$perPage);
			
			// paged for countries page
			$page = self::getCurrentPage($pages);
			
			$base = self::getDBCountryBase($page, $perPage);
			
			$col_count = ceil(count($base)/$cols);
			
			if(!$selection = self::getSelection($set)){
				$selection = array();
			}
			$onpage = self::onPageCountriesEncode(array_keys($base));
			?>
			
			<div id="selections">
			<h3><?php echo __('Selection #', 'ias-countries').''.$set; ?></h3>
			
			<?php if(!empty($base)):?>
				<div id="col-container">
					<?php self::tableNav('top', $count, $perPage); ?>
					<form method="post">
						<?php self::createPostRef($tab); ?>
						<input type="hidden" name="set" value="<?php echo esc_attr($set); ?>">
						<input type="hidden" name="paged" value="<?php echo esc_attr($page); ?>">
						<div id="col-left">
							<div class="col-wrap">
								<div class="list-wrap">
									<ul>
										<?php foreach($base as $code=>$label): $i++; ?>
											<li>
												<label><input id="" type="checkbox" <?php checked(in_array($code, $selection), 1, true); ?> name=checked[<?php echo esc_attr($code); ?>] value=1><?php echo $label; ?></label>
											</li>
											<?php 
												if($i%$col_count == 0) echo '</ul><ul>'; ?>
										<?php endforeach; ?>
									</ul>
									<br class="clear">
								</div>
								<p class="submit">
									<input id="submit" class="button button-primary" type="submit" value="<?php _e('Save Selection', 'ias-countries'); ?>" name="submit">
								</p>		
							</div><!-- end col-wrap -->
						</div><!-- end col-left -->
								
						<div id="col-right">
							<div class="col-wrap">
								<!-- select all/none on entire list -->
								<div id="select-set">
									<p><?php _e('Select entire list of countries', 'ias-countries'); ?></p>
									<p>
										<input id="select-all" type="submit" class="button" name="all" value="<?php _e('All', 'ias-countries')?>">
										<input id="select-none" type="submit" class="button" name="none" value="<?php _e('None', 'ias-countries'); ?>">
									</p>
								</div>
								
								<!-- select all/none on this page -->
								<div id="select-page">
									<p><?php _e('Select countries on this page only', 'ias-countries') ?></p>
									<p>
										<input type="hidden" name="countries" value="<?php echo esc_attr($onpage); ?>">
										<input id="select-all-on-page" type="submit" class="button" name="all_on_page" value="<?php _e('All on page', 'ias-countries')?>">
										<input id="select-none-on-page" type="submit" class="button" name="none_on_page" value="<?php _e('None on page', 'ias-countries'); ?>">
									</p>
								</div>
							</div><!-- end col-wrap -->
						</div><!-- end col-right -->
					</form>
				</div> <!-- end col-container -->	
					
			<?php endif; ?>
			</div>
		<?php
		}
		
		
		/**
		 * export available translation to xml file
		 * built in languages are exported in customized version
		 */
		private static function minPageExport(){
			// get available languages for export
			if(!$langs = self::getDBCountryLangs()) $langs = array(self::getOption('lang_country'));
			// select field args
			$args = array(
				'include' => $langs
			);
			?>
			
			<h3><?php _e('Export country lists', 'ias-countries'); ?></h3>
			<p class="description"><?php _e('Export country lists from available translations.', 'ias-countries'); ?></p>
			
			<form method="post">
				<?php 
					self::createPostRef('export');
					echo self::langSelectField($args);	
				?>
				<p class="submit">
					<input id="submit" class="button button-primary" type="submit" value="<?php _e('Export Translation', 'ias-countries'); ?>" name="submit">
				</p>
			</form>
		<?php
		}
		
		
		/**
		 * manage settings
		 */
		private static function minPageSettings(){
			
			// default (base) language (out of predefined country translations, will be embedded into table_base on setting update)
			$lang_country = self::getOption('lang_country');
			$lang_country_select = array(
				'name' => 'options[lang_country]',
				'id' => 'ias-cntrs-select-lang-country',
				'value' => $lang_country,
				'include' => self::getXMLBuiltInLangs()
			);
			// list languages in the following language (create list from the available files languages-{lang}.xml)
			$lang_language = self::getOption('lang_language');
			$lang_language_select = array(
				'name' => 'options[lang_language]',
				'value' => $lang_language,
				'include' => self::getXMLAvailableTranslations('languages')
			);
				
			?>
			<h3><?php __('Settings', 'ias-countries'); ?></h3>
			
			<form method="post">
				<?php self::createPostRef('settings'); ?>
				
				<table class="form-table">
					<tbody>
						<tr valign="top">
							<th scope="row">
								<label <?php echo isset($lang_country_select['id']) ? 'for="'.esc_attr($lang_country_select['id']).'"' : '';   ?>><?php _e('Countries: default language', 'ias-countries'); ?></label>
							</th>
							<td>
								<?php echo self::langSelectField($lang_country_select); ?>
								<p class="description"><?php _e('Default language for country list is selected from built-in translations only. It is also used as fallback in case of missing translation for a country name.', 'ias-countries'); ?></p>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label <?php echo isset($lang_country_select['id']) ? 'for="'.esc_attr($lang_country_select['id']).'"' : '';   ?>><?php _e('Languages: default language', 'ias_countries'); ?></label>
							</th>
							<td>
							<?php echo self::langSelectField($lang_language_select); ?>
							<p class="description"><?php _e('Select language in which language names to be listed. If nothing selected, language is listed according to its name in the corresponding language.', 'ias-countries'); ?></p>
							</td>
						</tr>
					<tbody>
				</table>
				<p class="submit">
					<input id="submit" class="button button-primary" type="submit" value="<?php _e('Save Settings', 'ias-countries'); ?>" name="submit">
				</p>		
			</form>
		
			<?php
		}
		
		
		/**
		 * manual for plugin
		 */
		private static function minPageManual(){
							
			$locale = self::_settings_locale();
			$langs = self::getLanguages();
			
			$file = IAS_COUNTRIES_PLUGIN_ABS."/assets/help/help-{$locale}.html";
			
			if (!file_exists($file)){
				$file = IAS_COUNTRIES_PLUGIN_ABS.'/assets/help/help.html';
			}
			
			$text = false;
			if(function_exists('file_get_contents')){
				$text = @file_get_contents($file);
			} else {
				$text = @file($file);
				if($text !== false){
					$text = implode("", $text);
				}
			}
			if($text === false){
				$url_download = '<a href="http://wordpress.org/plugins/ias-countries/" target="_blank">';
				$url_install = '<a href="http://wordpress.org/plugins/ias-countries/installation/" target="_blank">';
				$anchor_end = '</a>';
				$text = '<p style="margin-top:2em;">' . sprintf (__('The documentation files are missing! Try %1$sdownloading%2$s and %3$sre-installing%2$s this plugin.', 'ias-countries' ), $url_download, $anchor_end, $url_install) . '</p>';
			}
			?>
			<div id="manual">
				<div id="col-left">
					<div class="col-wrap">
					<?php echo $text;
					?>
					</div>
				</div>
				<div id="col-right">
					<div class="col-wrap">
						<!-- Paypal donate -->
						<div id="ias-cntrs-paypal">
							<h4> <?php _e('If you found this plugin useful, consider to support it.', 'ias-countries'); ?> </h4>
							<div>
								<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_top">
									<input type="hidden" name="cmd" value="_s-xclick">
									<input type="hidden" name="hosted_button_id" value="BT9VZD6AMGU8J">
									<input type="hidden" name="item_name" value="Support iasCountries">
									<input type="image" src="https://www.paypalobjects.com/<?php echo urlencode($locale); ?>/i/btn/btn_donate_LG.gif" border="0" name="submit" alt="PayPal – The safer, easier way to pay online.">
									<img alt="" border="0" src="https://www.paypalobjects.com/<?php echo urlencode($locale); ?>/i/scr/pixel.gif" width="1" height="1">
								</form>
							</div>
						</div>
						<!-- Feedback -->
						<div id="ias-cntrs-feedback">
							<h3>Feedback</h3>
							<p class="description"><?php _e('Send your translations or correction notes.','ias-countries'); ?></p>
							<div>
								<form method="post" name="feedback" enctype="multipart/form-data">
									<?php self::createPostRef('feedback'); ?>
									<p>
										<label for="name"><?php _e('Your name', 'ias-countries'); ?></label>
										<input id="name" type="text" name="name" value="">
									</p>
									<p>
										<label for="email"><?php _e('Your email', 'ias-countries'); ?></label>
										<input id="email" type="text" name="email" value="">
									</p>
									<p>
										<label for="message"><?php _e('Message', 'ias-countries'); ?></label>
										<textarea id="message" name="message"></textarea>
										<span class="description">
										<?php echo __('Write in following languages', 'ias-countries') . ': ' . 
											( !empty($langs['en']) ? $langs['en'] : __('English', 'ias-countries') ) . ', ' . 
											( !empty($langs['de']) ? $langs['de'] : __('German', 'ias-countries') ). ', ' . 
											( !empty($langs['fr']) ? $langs['fr'] : __('French', 'ias-countries') ) . ', ' . 
											( !empty($langs['es']) ? $langs['es'] : __('Spanish', 'ias-countries') ) . ', ' . 
											( !empty($langs['it']) ? $langs['it'] : __('Italian', 'ias-countries') ) . ', ' . 
											( !empty($langs['ru']) ? $langs['ru'] : __('Russian', 'ias-countries') ); ?>
										</span>
									</p>
									<p>
										<label for="attachment"><?php _e('Attachment', 'ias-countries'); ?></label>
										<input id="attachment" type="file" value="Browse" name="attachment">
										<span class="description"><?php _e('Only one file accepted. Multiple files to be sent as a zipped archive.','ias-countries'); ?></span>
									</p>
									
									<!-- submit button -->
									<p class="submit">
										<input id="submit" class="button button-primary" type="submit" value="<?php _e('Send'); ?>" name="submit">
									</p>
									
								</form>
							</div>
						</div>
					</div>
				</div>
			</div>
			
			<?php
			
		}
		
		
		/**
		 * get admin area locale
		 */
		private static function _settings_locale(){
			//$lang_locales and ICL_LANGUAGE_CODE are defined in the WPML plugin (http://wpml.org/)
			global $lang_locales;
			if (defined('ICL_LANGUAGE_CODE') && isset($lang_locales[ICL_LANGUAGE_CODE])){
				$locale = $lang_locales[ICL_LANGUAGE_CODE];
			} else {
				$locale = get_locale();
			}
			return $locale;
		}
		
		
		/*-------- page parts -----------*/
				
		/**
		 * import translation (or customization for built-in translation)
		 */
		private static function importForm($header = null){
			$bytes = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
			$size = size_format( $bytes );
			$upload_dir = wp_upload_dir();
			$lang = isset($_GET['lang']) ? $_GET['lang'] : 0;
			$page = isset($_GET['paged']) ? $_GET['paged'] : 1;
			$tab = isset($_GET['tab']) ? $_GET['tab'] : 'translate'; 
			if(!$header){
				$header = $tab == 'translate' 
					? __('Import translation', 'ias-countries')
					: __('Import customization', 'ias-countries'); 
			}
			?>
		
			<div>
				<h3><?php echo $header; ?></h3>
				<?php
				
				if ( ! empty( $upload_dir['error'] ) ) :
					?><div class="error"><p><?php _e('Before you can upload your import file, you will need to fix the following error:'); ?></p>
					<p><strong><?php echo $upload_dir['error']; ?></strong></p></div><?php
				else :
					?><p class="description"> 
					<?php 
					_e( 'Choose a (.xml, .csv, .txt) file to upload, then click Upload file and import.', 'ias-countries' ); 
					echo ' '; 
					_e( 'See manual for supported formats.', 'ias-countries' ); 
					?>
					</p>
					
					<?php if($tab == 'customize'): ?>
						<p> <?php _e( 'Only terms distinct from the built-in translation will be imported.', 'ias-countries' ); ?></p>
					<?php endif;?>
				
					<form enctype="multipart/form-data" id="import-upload-form" method="post" class="wp-upload-form">
						<?php self::createPostRef('import'); ?>
						<input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>">
						<input type="hidden" name="paged" value="<?php echo esc_attr($page); ?>">
						<input type="hidden" name="max_file_size" value="<?php echo $bytes; ?>" />
						<p>
							<label for="upload"><?php _e( 'Choose a file from your computer:' ); ?></label> (<?php printf( __('Maximum size: %s' ), $size ); ?>)
							<input type="file" id="upload" name="import" size="25" />
						</p>
						<p class="submit">
							<input id="submit" class="button" type="submit" value="<?php _e('Upload file and import'); ?>" name="submit">
						</p>
					</form>
				<?php endif; ?>
		
			</div>
		
			<?php
		}
		
		
		/**
		 * form to submit removing of translation
		 * @param array $countries codes of countries viewed on the current page
		 */ 
		private static function removeForm($countries = array()){
			$lang = isset($_GET['lang']) ? $_GET['lang'] : 0;
			$page = isset($_GET['paged']) ? $_GET['paged'] : 1;
			$tab = isset($_GET['tab']) ? $_GET['tab'] : 'translate';
			$onpage = self::onPageCountriesEncode($countries);
			// enable remove buttons
			$att_all = '';
			$att_page = '';
			$custom_all = self::getDBCustomCountryLangs();
			
			if(!$custom_all || !in_array($lang, $custom_all)){
				// nothing to remove
				$att_all = $att_page = 'disabled="disabled"';
			}
			else{
				// available translations on page
				$custom_page = self::getDBCountries(array('lang' => $lang, 'include' => $countries, 'custom' => true));
				if(empty($custom_page)) $att_page = 'disabled="disabled"';
			}
			
			// labels
			if($tab == 'translate'){
				$header = __('Remove translation', 'ias-countries');
				$description = __('This will remove translation into selected language from the database.', 'ias-countries');$button_page_note = __('Remove translation on this page.', 'ias-countries');
				$button_all_note = __('Remove translation for entire list of countries.', 'ias-countries');
				
			}
			else if($tab == 'customize'){
				$header = __('Remove customization', 'ias-countries');
				$description = __('This will remove your customization and restore translation into selected language to the built-in state.', 'ias-countries');
				$button_page_note = __('Remove customization on this page.', 'ias-countries');
				$button_all_note = __('Remove customization for entire list of countries.', 'ias-countries');
			}
			$button_page = __('Remove on page', 'ias-countries');
			$button_all = __('Remove All', 'ias-countries');
			
		?>
			<div>
				<h3><?php echo $header; ?></h3>
				<p class="description"><?php echo $description; ?></p>
				
				<!-- entire translation -->
				<form id="remove-form" method="post">
					<?php self::createPostRef('remove'); ?>
					<input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>">
					<input type="hidden" name="paged" value="<?php echo esc_attr($page); ?>">
					<!-- on this page only: handled with javascript -->
					<div id="remove-on-page">
						<p><?php echo $button_page_note; ?></p>
						<input type="hidden" name="countries" value="<?php echo esc_attr($onpage); ?>">
						<p class="submit">
							<input id="onpage" class="button" type="submit" <?php echo $att_page; ?> value="<?php echo $button_page; ?>" name="onpage">
						</p>
					</div>
					<div id="remove-all">
						<p><?php echo $button_all_note; ?></p>
						<p class="submit">
							<input id="all" class="button" type="submit" <?php echo $att_all; ?> value="<?php echo $button_all; ?>" name="all">
						</p>
					</div>
				</form>
			</div>
		<?php
		}
		
		
		/*
		 * hexadecimal encoding of county list viewed on the page
		 */
		private static function onPageCountriesEncode($countries){
			if(empty($countries) || !is_array($countries)) return ''; // fill value input with empty string
			return bin2hex(implode(':', $countries ));
		}
		
		
		/*
		 * parse hexadecimal encoding of county list viewed on the page
		 */
		private static function onPageCountriesDecode($var){
			if(empty($var) || !ctype_xdigit($var)) return false;
			return explode(':', self::hex2bins($var));
		}
		
		
		/**
		 * Decodes a hexadecimally encoded binary string
		 */
		private static function hex2bins($hex_string) {
			
			if(function_exists('hex2bin')){
				return hex2bin($hex_string);
			}
			
			$pos = 0;
			$result = '';
			$divider = " \t\n\r";
			
			while ($pos < strlen($hex_string)) {
			  if (strpos($divider, $hex_string{$pos}) !== FALSE) {
				$pos++;
			  } 
			  else {
				$code = hexdec(substr($hex_string, $pos, 2));
				$pos = $pos + 2;
				$result .= chr($code); 
			  }
			}
		
			return $result;
		}
		
		
		/**
		 * @param $where top, bottom
		 * @param $count number of items listed
		 * @param $perPage number of items per page
		 */
		private static function tableNav($where, $count = null, $perPage = null){
			// current query vars
			$vars = self::parseCurrentQuery();
			$tab = isset($vars['tab']) ? $vars['tab'] : key(self::toolsPageTabs()); // first tab is default
			?>
			
			<div class="tablenav <?php echo $where?>">
				<form method="get">
				<?php 
					// preserve current query
					foreach($vars as $k => $v){
						if(in_array($k, array('lang', 'paged'))) continue; // part of lang and page navigation
						echo '<input type="hidden" name="'.$k.'" value="'.$v.'" >';
					}
				?>
				<!-- language selector -->
				<?php 
					if($where == 'top' && in_array($tab, array('translate', 'customize'))){
						echo self::langFilter($tab); 
					}
				?>
				
				<!-- country selection -->
				<?php 
					if($where == 'top' && $tab == 'selections' ){
						echo self::selectionFilter();
					}
				?>
				
				<!-- page navigation -->
				<?php self::pageNav($vars, $count, $perPage); ?>
				</form>
			</div>
			
			<?php
		}
		
		
		/**
		 * choose selection id from dropdown menu
		 * get id of your selection of countries
		 * id is a range from 1-8 (8 selections is max)
		 * default id is 1, page default view is selection list with id 1, 
		 * if no selections stored in database, no countries are selected by default
		 * empty selections are not kept in database
		 */
		private static function selectionFilter(){
			$set = isset($_GET['set']) ? $_GET['set'] : 1;
			
			$output = '';
			$output .= '<div class="alignleft">';
				$output .= '<select id="ias-countries-selections-id" name="set">';
				for($i=1; $i<=8; $i++){
					$output .= '<option value="'.$i.'" '.selected($i, $set, false).'>'.$i.'</option>';
				}
				$output .= '</select>';
				// go button
				$output .= '<input type="submit" value="'.__('Go', 'ias-countries').'" class="button">';
			$output .= '</div>';
			return $output;
		}
		
		
		/**
		 * filter language, page number, use get form method
		 * @param $vars = current query vars
		 */
		private static function langFilter($tab){
			$lang = isset($_GET['lang']) ? $_GET['lang'] : '0';
			$args = array(
				'value' => $lang
			);
			switch($tab){
				case 'translate':
					// exclude built-in (predefined languages)
					$args['exclude'] = self::getXMLBuiltInLangs();
					break;
				case 'customize':
					// for built-in (predefined) only
					$args['include'] = self::getXMLBuiltInLangs();
					break;
			}
			$output = '';
			
			$output .= '<div class="alignleft">';
				// language selector
				$output .= self::langSelectField($args);
				
				// go button
				$output .= '<input type="submit" value="'.__('Go', 'ias-countries').'" class="button">';
			$output .= '</div>';
			return $output;
		}
		
		
		/**
		 * @param array $args:
		 * name input field name
		 * value input field value
		 * lang string/array: string language of language names, false keep original language name
		 * include array of languages 
		 * exclude array of languages
		 
		 */
		private static function langSelectField($args = array()){
			// set default values for missing
			$defaults = array(
				'name' => 'lang',
				'id' => '',
				'value' => 0,
				'lang' => self::getOption('lang_language'),
				'include' => null,
				'exclude' => null
			);
			$args = wp_parse_args($args, $defaults);
			extract($args);
			$output = '';
			$output .= '<select '.($id ? 'id="'.esc_attr($id).'"' : '').' name="'.$name.'">';
				$output .= '<option value="0" '.selected($value, 0, false).'>'. esc_attr__('Select Language', 'ias-countries') .'</option>';
				$printed = array();
				foreach(self::getLanguages($lang) as $code => $lang){
					
					// filter
					if(!is_null($include) && is_array($include)){
						if(!in_array($code, $include)) continue;
					}
					else if(!is_null($exclude) && is_array($exclude)){
						// include has priority (if include not empty, this block is omitted)
						if(in_array($code, $exclude)) continue;					
					}
					
					// avoid duplicates (e.g. 'zh' same langugae code for three versions of Chinese)
					if(in_array($code, $printed)) continue;
					
					$output .= '<option value="'.esc_attr($code).'" '.selected($value, $code, false).'>'.$lang.'</option>';
					
					array_push($printed, $code);
				}
			$output .= '</select>';
			return $output;
		}
			
		
		/**
		 * page navigation
		 */
		private static function pageNav($vars, $count = 0, $perPage = null){
			if(empty($count) || is_null($perPage)) return '';
			
			$pages = (int) ceil($count/$perPage);
			
			// if paged input is more than number of available pages display first page
			$page = self::getCurrentPage($pages);
			
			$navClass = $pages === 1 ? ' one-page' : '';
			$firstLink = self::pageNavLink(1, $vars);
			$lastLink = self::pageNavLink($pages, $vars);
			$prevLink = $page > 1 ? self::pageNavLink($page - 1, $vars) : $firstLink;
			$nextLink = $page < $pages ? self::pageNavLink($page + 1, $vars) : $lastLink;
			
			?>
			
			<div class="tablenav-pages<?php echo $navClass; ?>"><span class="displaying-num"><?php _e('Countries', 'ias-countries'); ?>: <?php echo $count; ?></span>
			
				<span class="pagination-links">
				<!-- first page -->
				<a href="<?php echo $firstLink; ?>" title="<?php _e('Go to the first page'); ?>" class="first-page <?php if($page == 1) echo 'disabled'; ?>">«</a>
				<!-- prev -->
				<a href="<?php echo $prevLink; ?>" title="<?php _e('Go to the previous page');?>" class="prev-page <?php if($page == 1) echo 'disabled'; ?>">‹</a>
				
				<!-- page input -->
				<span class="paging-input">
					<input type="text" size="1" value="<?php echo $page; ?>" name="paged" title="<?php _e('Current page'); ?>" class="current-page"> of <span class="total-pages"><?php echo $pages; ?></span>
				</span>
				
				<!-- next -->
				<a href="<?php echo $nextLink; ?>" title="<?php _e('Go to the next page'); ?>" class="next-page <?php if($page == $pages) echo 'disabled'; ?>">›</a>
				<!-- last page -->
				<a href="<?php echo $lastLink; ?>" title="<?php _e('Go to the last page'); ?>" class="last-page <?php if($page == $pages) echo 'disabled'; ?>">»</a>
				</span>
			
			</div>

		<?php
		}
		
		/*
		 * if 'paged' input is more than number of available pages display first page
		 * @param int $pages - total number of pages
		 * @since 1.0.1
		 */
		private static function getCurrentPage($pages){
			return isset($_GET['paged']) && (int) $_GET['paged'] <= $pages ? (int) $_GET['paged'] : 1;
		}
		
		/**
		 * links for page navigation
		 */
		private static function pageNavLink($page, $vars){
			$vars['paged'] = $page;
			return admin_url(self::$_minParent.'?'.http_build_query($vars));
		}
			
		
		/**
		 * @return array of current query vars
		 */
		private static function parseCurrentQuery(){
			$output = array();
			$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
			parse_str($query, $output);
			return $output;
		}
		
		/*-------- form actions ----------*/
		
		/**
		 * handle form submit
		 * include url query vars as hidden input fields to the parent form, to create manually a redirection if wp referrer fails
		 */
		public static function handleRequest(){
			$nonce = self::$_prefix . 'nonce';
			if(!isset($_POST[$nonce])) return;
			
			if(!wp_verify_nonce($_POST[$nonce], IAS_COUNTRIES_PLUGIN_ABS )) return;
			
			$redirect = $_POST['_wp_http_referer'];
			
			// form reference not set, return
			if(!isset($_POST['action'])) {
				wp_redirect($redirect);
				exit;
			}
			
			// we have all what's needed, proceed
			$action = $_POST['action'];
			$error = array();
			$success = __('Process completed successfully!', 'ias-countries');
			
			switch($action){
				case 'translate':
				case 'customize':
					if(!isset($_POST['lang']) || $_POST['lang'] == '0' ) {
						wp_redirect($redirect);
						exit;
					}
					if(empty($_POST['countries'])) {
						wp_redirect($redirect);
						exit;
					}
					
					$lang =  $_POST['lang'];
					
					$built = $action == 'customize'; 
					
					// save translation
					self::saveCountryTranslation($lang, $_POST['countries'], $built); // customize - true (customize built in); translate - false (not built in translation)
								
					break;
				
				case 'selections':
					if(!isset($_POST['set'])) {
						wp_redirect($redirect);
						exit;
					}
					$set = $_POST['set'];
				
					if(isset($_POST['none'])){
						self::saveCountrySelectionAll($set, 0);
					}
					else if(isset($_POST['all'])){
						self::saveCountrySelectionAll($set, 1);
					}	
					else if(isset($_POST['none_on_page'])){
						$checked = array();
						$countries = isset($_POST['countries']) ? self::onPageCountriesDecode($_POST['countries']) : '';
						self::saveCountrySelection($set, $countries, $checked);
					}
					else if(isset($_POST['all_on_page'])){
						// countries on this page
						$countries = isset($_POST['countries']) ? self::onPageCountriesDecode($_POST['countries']) : '';
						// get a list of all countries listed on this page (value not important, essential is to have the array keys)
						$checked = $countries ? array_flip($countries) : array();
						// provide non associative array of countries handled on this page
						self::saveCountrySelection($set,$countries, $checked);
					}
					else{
						// countries on this page
						$countries = isset($_POST['countries']) ? self::onPageCountriesDecode($_POST['countries']) : '';
						// provide associative array of checked countries (country code as array index)
						$checked = isset($_POST['checked']) ? $_POST['checked'] : array();
						// provide non associative array of countries handled on this page
						self::saveCountrySelection($set, $countries, $checked);
					}
					break;
				case 'export':
					self::exportCountries();
					$success = ''; // don't display success message
					$redirect = false;
					break;
				case 'import':
					$imported = self::importCountries();
					if(!empty($imported['error'] )){	
						$error = $imported['error'];
					}
					break;
				case 'remove':
					if(!isset($_POST['lang']) || $_POST['lang'] == '0' ) {
						wp_redirect($redirect);
						exit;
					}
					$lang =  $_POST['lang']; 
					$countries = array();
					
					if(isset($_POST['onpage'])){
						// remove on page only
						$countries = isset($_POST['countries']) ? self::onPageCountriesDecode($_POST['countries']) : '';
						// false if failure to decode; empty string if countries are not set
						if(!$countries) {
							// if left empty, entire set will be removed
							wp_redirect($redirect);
							exit;
						} 
					}
					
					// if $countries is empty array, entire translation set is removed/restored
					self::removeCustomTranslation($lang, $countries);
					break;
				case 'settings':
					self::saveSettings();
					break;
				case 'feedback':
					$feedback = self::sendFeedback();
					if(!empty($feedback['error'] )){	
						$error = $feedback['error'];
					}
					break;
			}
			
			// check for success of result
			if($error){
				self::setSession('error', $error); 
			}
			else if($success){
				self::setSession('updated',  $success); 
			}
			
			//die(var_dump($redirect));
			if($redirect){
				wp_redirect($redirect);
			}
			exit;
			
		}
		
		
		/**
		 * remove entire translation for not built-in set of country terms
		 * restore entire translation to predefined state for built in set
		 * items on page removed with javascript and changes to be saved to databse with 'save' button
		 * @param array $countries array of country codes
		 * @todo: back up to custom-countries-{lang}.xml ?
		 */
		private static function removeCustomTranslation($lang, $countries = null){
			$all = !$countries; // handle all
			$codes = !$countries ? self::getDBCountryCodes() : $countries; // if not set remove all countries
			
			if(empty($codes)) return false;
			
			$built = in_array($lang, self::getXMLBuiltInLangs());
			
			if($built){
				$predefined = self::getXMLCountriesPredefined($lang);
				
				// update customized only
				$customized = self::getDBCustomTransCodes($lang);
				foreach((array) $customized as $code){
					if(!$all && !in_array($code, $codes)) continue;
					$restore = $predefined[$code];
					self::restoreTranslation($lang, $code, $restore);
				}
			}
			else{
				global $wpdb;
				$table_trans = self::$_table_trans;
				if($all){
					$qry = "DELETE FROM $table_trans WHERE trans_lang = '%s'";
				}
				else{
					$table_base = self::$_table_base;
					$qry = "DELETE FROM $table_trans WHERE trans_lang = '%s' AND country_code IN ('".implode("', '", $codes)."')";
				}
				$wpdb->query($wpdb->prepare($qry, $lang));
			}
		}
		
		
		/**
		 * @param $restore - restore value (predefined values found outside, to avoid reading of the entire xml file for single item)
		 */
		private static function restoreTranslation($lang, $code, $restore){
			global $wpdb;
			$table_trans = self::$_table_trans;
			$wpdb->query($wpdb->prepare("UPDATE $table_trans SET trans_country = '%s', trans_custom = 0 WHERE country_code = '%s' AND trans_lang = '%s'", $restore, $code, $lang));
		}
			
			
		/**
		 * imported file => $_FILES['import']
		 * upload to a plugin's temp folder (don't mess with user's uploads)
		 * delete after file is handled
		 */
		private static function importCountries(){
			if(!isset($_POST['lang']) || $_POST['lang'] == '0' ){ 
				return array('error' => __('Please select language and repeat.', 'ias-countries'));
				
			}
			
			$lang = $_POST['lang'];
			
			if ( !isset($_FILES['import']) ) {
				return array('error' => __( 'File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your php.ini or by post_max_size being defined as smaller than upload_max_filesize in php.ini.' ));
			}
			
			if(!$filepath = self::uploadFile('import')){
				return array('error' => __( 'File upload failed.'));
			}
						
			// save imported data
			if(isset($_FILES['import']['type']) && strpos($_FILES['import']['type'], 'xml') !== false){
				$countries = self::readXMLImport($filepath);
			}
			else {
				$countries = self::readCSVImport($filepath);
			}
						
			if(isset($countries['error'])){
				if(file_exists($filepath)){
					unlink($filepath);
				}
				
				return $countries;
			}
			
			$built = in_array($lang, self::getXMLBuiltInLangs());
			self::saveCountryTranslation($lang, $countries, $built);
			
			// remove imported file after being processed
			if(file_exists($filepath)){
				unlink($filepath);
			}
			
		}
			
		
		/**
		 * save settings
		 */
		private static function saveSettings(){
			$options = isset($_POST['options']) ? $_POST['options'] : array();
			
			if($options['lang_country'] != self::getOption('lang_country')){
				// update country_base in db
				self::setCountryBase($options['lang_country']);
			}
			
			self::updateOptions($options);
		}
		
		
		/**
		 * split countries by pages and handle each section separately
		 * option update doesn't touch other countries in the option array
		 * @param integer $id 
		 * @param non-associative array $countries - set of countries to be updated (define limits where select/unselect)
		 * @param associative array $checked - countries checked in the considered set of countries (array code => 1)
		 */
		private static function saveCountrySelection($id, $countries, $checked){
			// if set of countries not defined, update not possible
			if(!$countries) return false;
			
			$new = array();
			$old = self::getSelection($id);
			$checked_codes = array_keys($checked);
			
			if(!empty($old)){
				foreach($old as $code){
					if($in = in_array($code, $checked_codes) || !in_array($code, $countries)){
						
						// keep old values untouched if not in this section and add checked
						$new[] = $code;
						
						// remove this code from checked to mark it as being handled
						if($in){
							unset($checked[$code]);
						}
					}
				}
				// merge remained checked countries into new selection
				$new = array_merge($new, array_keys($checked));
			}
			else{
				if(!empty($checked)){
					$new = array_keys($checked);
				}
			}
			//die(var_dump($new));
			if(!empty($new)){
				self::updateSelection($id, $new);
			}
			else{
				self::deleteSelection($id);
			}
		}
		
		
		/**
		 * @param int boolean $all 1 - select all, 0 - select none (remove option)
		 */
		private static function saveCountrySelectionAll($id, $all){
			if($all == 1){
				$selection = self::getDBCountryCodes();
				self::updateSelection($id, $selection);
			}
			else if($all == 0){
				self::deleteSelection($id);
			}
		}
		
		
		/**
		 * @param string $lang 
		 * @param array $countries: code => country (list of countries to be updated/inserted)
		 * @param boolean $built true if working with built in set of countries (e.g. tab = 'customize'), and false if working with set of countries translated by user (e.g. tab = 'translate')  
		 * custom set: entry to be removed if input is empty
		 * built-in set: customized entry of a predefined term to be restored to its built-in state (get it from xml), if xml corrupted keep it
		 * @todo what if predefined xml for this language is corrupted
		 * @todo: if needed keep track of customized fields in assets/xml/custom-countries-{lang}.xml
		 * @todo: add error notifications
		 */
		private static function saveCountryTranslation($lang, $countries, $built){
			// get all predefined for restoration purpose (to avoid reading it every time built in translation to be restored)
			$predefined = $built ? self::getXMLCountriesPredefined($lang) : array();
			
			// save to database
			if(empty($countries) || empty($lang)) return false;
			
			global $wpdb;
			$table_trans = self::$_table_trans;		
			foreach($countries as $code => $country){
				$country = sanitize_text_field($country);
								
				if($dbCountry = self::hasTrans($code, $lang)){
					
					if($country == ''){
						if($built){
							$restore = isset($predefined[$code]) ? $predefined[$code] : '';
							// restore to predefined 
							if($restore){
								self::restoreTranslation($lang, $code, $restore);
							}
						}
						else{
							// remove empty input
							$wpdb->query($wpdb->prepare("DELETE FROM $table_trans WHERE country_code = '%s' AND trans_lang = '%s'", $code, $lang));
						}
					}
					else if($dbCountry != $country){
						$wpdb->query($wpdb->prepare("UPDATE $table_trans SET trans_country = '%s', trans_custom = 1 WHERE country_code = '%s' AND trans_lang = '%s'", $country, $code, $lang));
					}
				}
				else{
					// don't insert empty input
					if($country == '') continue;
					
					$wpdb->query($wpdb->prepare("INSERT INTO $table_trans (country_code, trans_country, trans_lang, trans_custom) VALUES ('%s', '%s','%s',1)", $code, $country, $lang));
				}
			}
		}
				
		
		/**
		 * add process reference to identify form type and booking id
		 * @param string $action process step
		 * echo result, wp 3.1 has bug returning wp_referer_field
		 */
		private static function createPostRef($action){
			$nonce = self::$_prefix . 'nonce';
	
			// nonce for ias booking form
			wp_nonce_field(IAS_COUNTRIES_PLUGIN_ABS, $nonce, true, true);
						
			// action - how to save form
			echo '<input type="hidden" name="action" value="'.esc_attr($action).'">';
					
		}
		
		
		/**
		 * add process reference to identify form type and booking id
		 * @param string $url to which nonce to be added
		 * @param string $action - query var defeninig the action this link is meant for, used as salt for nonce
		 */
		private static function createUrlRef($url, $action){
			return wp_nonce_url($url, $action, 'ias_nonce');
		}
		
		
		/**
		 * countries are exported in one language
		 * xml format: <code>label</code>
		 * don't redirect
		 */
		private static function exportCountries(){
			if($_SERVER['REQUEST_METHOD'] != 'POST') return;
			
			$lang = isset($_POST['lang']) && $_POST['lang'] != '0' ? $_POST['lang'] : 'en';
			
			// download headers
			header('Content-type: text/xml');
			header('Content-Disposition: attachment; filename="countries-'.$lang.'.xml"');
			
			$data = self::getDBcountryLables($lang);
			// create xml without saving, offer to save on export
			echo self::saveToXML($data, 'countries', false, $lang);
		}
		
		
		/**
		 * send feedback to plugin's author
		 */
		private static function sendFeedback(){
			$notsent = __('Feedback not sent.', 'ias-countries');
			
			// feedback to author
			$to = self::$_author_email;
			
			// from: not empty name
			$name = sanitize_text_field($_POST['name']);
			
			// from: validate email
			$email = !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL) ? '' : $_POST['email'];
			
			$message = strip_tags($_POST['message']);
									
			if(!$name || !$email || !$message) return array('error' => $notsent . ' ' . __('Required fields are not complete.', 'ias-countries'));			
			
			// headers
			$from = $name . ' <' . $email . '>'; 
			$headers = "From: $from"; 			
			
			// attachment
			$attachment = '';
			
			if($_FILES['attachment']['tmp_name'] && !$attachment = self::uploadFile('attachment')){
				return array('error' => $notsent . ' ' . __('File attachment failed.', 'ias-countries'));
			}
			
			$sent = wp_mail($to, 'ias Countries Feedback', $message, $headers, $attachment);
			
			// delete attachment file after process finished	
			if($attachment != '' && file_exists($attachment)){
				unlink($attachment);
			}
			
			if(!$sent) return array( 'error' => $notsent . ' ' . __('Please make sure email service is configured correctly.', 'ias-countries'));
						
			return true;
		}
		
		
		/** 
		 * upload file to the plugin's temporary folder
		 * @param $key = key in $_FILES array
		 */
		private static function uploadFile($key){
			if(!isset($_FILES[$key])) return false;
			if($_FILES[$key]['error'] > 0) return false;
			
			$tmp_name = $_FILES[$key]['tmp_name'];
					
			if (!file($tmp_name)) return false; 
			
			$filename = $_FILES[$key]['name']; 
			
			// create directory
			if (!file_exists(IAS_COUNTRIES_PLUGIN_DIR_TEMP)) {
				mkdir(IAS_COUNTRIES_PLUGIN_DIR_TEMP, 0777, true);
			}
			
			$filepath = IAS_COUNTRIES_PLUGIN_DIR_TEMP . '\\' . $filename;
			
			if(!move_uploaded_file($tmp_name, $filepath)) return false;
						
			return $filepath;
		}
		
		/*============ NOTICES ==============*/
		
		/**
		 * @todo: improve key for session (check for uniqueness)
		 */
		private static function checkNotices(){
			if(!session_id()) session_start();
			
			$error = self::getSession('error');
			
			if(!empty($error)){
				self::$_notices['error'] = $error;
				self::clearSession('error'); // don't display it again on next page reload
			}
			$updated = self::getSession('updated');
			if(!empty($updated)){
				self::$_notices['updated'] = $updated;
				self::clearSession('updated'); // don't display it again on next page reload
			}
		}
		
		
		/**
		 * set palugin notes in global session variable
		 */
		private static function setSession($key, $data){
			// make sure data is of array type
			$data = (array)$data;
			// every new entry will overwrite (decide whether to add)
			if(isset($_SESSION['ias_countries'][$key])) {
				$_SESSION['ias_countries'][$key] = array_merge($_SESSION['ias_countries'][$key], $data);
			}
			else{
				$_SESSION['ias_countries'][$key] = $data;
			}
		}
		
		
		/**
		 *
		 * @return empty array if empty
		 */
		private static function getSession($key){
			return isset($_SESSION['ias_countries'][$key]) ? array_filter($_SESSION['ias_countries'][$key]) : array();
		}
		
		
		/**
		 * clear session before saving new data to it
		 */
		private static function clearSession($key){
			if(isset($_SESSION['ias_countries'][$key])){
				unset($_SESSION['ias_countries'][$key]);
			}
		}
		
		
		/**
		 * display messages in admin area
		 */
		public static function displayNotices(){
			global $hook_suffix;
			$parent = explode('.', self::$_minParent);
			$hook = preg_replace('/[\.\-]/', '_', $parent[0].'_page_'.self::$_minPage);
			if($hook_suffix != $hook) return;
			
			if(!empty(self::$_notices)){
				foreach(self::$_notices as $key => $notice){
					echo '<div class="'.$key.'">';
					if(is_array($notice)){
						foreach($notice as $note){
							echo '<p>'.$note.'</p>';
						}
					}
					else{
						echo '<p>'.$notice.'</p>';
					}
					echo '</div>';
				}
			}
		}
		
		
		/*========= PLUGIN ACTIVATION HOOKS ========*/
		
		public static function activate(){
			if ( ! current_user_can( 'activate_plugins' ) )
				return;
			$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
			check_admin_referer( "activate-plugin_{$plugin}" );
			
			if (function_exists('is_multisite') && is_multisite() && isset($_GET['networkwide']) && ($_GET['networkwide'] == 1)){
				$sites = wp_get_sites();
				if($sites){
					foreach($sites as $site){
						$blog_id = $site['blog_id'];
						switch_to_blog($blog_id);
						self::activateTasks();						
					}
				}
				restore_current_blog();
				// restore options for the current blog (although restored automatically on the next page load)
				self::setOptions();
			}
			else{
				self::activateTasks();
			}	
				
		}
		
		
		private static function activateTasks(){
			// define constants
			self::constants();
			// set options
			self::setOptions();
			// start tables	
			self::init_tables();
		}
		
		
		public static function uninstall(){
			if ( ! current_user_can( 'delete_plugins' ) )
				return;
			
			if (function_exists('is_multisite') && is_multisite()) {
				$sites = wp_get_sites();
				if($sites){
					foreach($sites as $site){
						$blog_id = $site['blog_id'];
						switch_to_blog($blog_id);
						self::uninstallTasks();						
					}
				}
				restore_current_blog();
			}
			else{
				self::uninstallTasks();
			}
			
		}
		
		
		private static function uninstallTasks(){
			global $wpdb;
			
			// delete all selection options
			$key = self::$_slct_prefix;
			$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '$key%'");
			
			// delete tables
			$options = get_option(self::$_option_name);
			if(!empty($options['tables'])){
				foreach((array) $options['tables'] as $table){
					$wpdb->query("DROP TABLE IF EXISTS $table");
				}
			}
			
			// delete region terms
			self::removeRegionTerms();			
						
			// delete options
			delete_option(self::$_option_name);
		}
		
		/**
		 * remove region terms from database
		 * taxonomy is unique for ias-countries plugin, no other term_relationships using same region terms are touched
		 * @since 1.0.1
		 */
		public static function removeRegionTerms(){
			global $wpdb;
			$regions = "'".implode("', '", self::$_regions)."'";
			
			// wp_term_relationships
			$wpdb->query($wpdb->prepare("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id IN (SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE taxonomy = %s)", self::$_taxonomy));
			
			// wp_term_taxonomy
			$wpdb->query($wpdb->prepare("DELETE FROM $wpdb->term_taxonomy WHERE taxonomy = %s", self::$_taxonomy));
			
			// wp_terms: if no taxonomy assigned to term_id delete it
			$wpdb->query("DELETE FROM $wpdb->terms WHERE slug IN ($regions) AND term_id NOT IN (SELECT term_id FROM $wpdb->term_taxonomy)");
		}
		
		
		/**
		 * calculate table names according to wp prefix and plugin prefix
		 * check if tables with those names are available
		 * create tables if not
		 * if wp_ prefix changed, tables won't be found (new tables will be created by default, to preserve old tables, user must change table wp prefix of old tables)
		 * @todo improve checking of table names
		 */
		private static function init_tables(){
			// improve, require only if table missing
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			global $wpdb;
			global $charset_collate;
						
			// check if table names already set in options 
			if(!$tables = self::getOption('tables')) $tables = array(
				'base' => '',
				'trans' => ''
			);
			
			// create tables
			$create = array();
			
			// find out which tables must be created and set static variables for table names
			foreach((array)$tables as $key => $table){
				if($table == ''){
					// table has never been initiated
					$create[$key] = $tables[$key] = $table = self::setTableName($key);
				}
				else {
					if(!$wpdb->get_var("SHOW TABLES LIKE '$table'")){
						// table is set in options, but was later removed manually from db
						$create[$key] = $table;
					}
				}
				
				switch($key){
					case 'base':
						self::$_table_base = $table;
						break;
					case 'trans':
						self::$_table_trans = $table;
						break;
				}
			}

			
			// if not available in db, create new ones 
			if(isset($create['base']) && $create['base'] != ''){
				
				$table_base = self::$_table_base;
												
				$sql = "CREATE TABLE IF NOT EXISTS $table_base ( 
					country_id INT(3) unsigned NOT NULL AUTO_INCREMENT,
					country_code CHAR(2) NOT NULL,
					country_slug VARCHAR(255) NOT NULL,
					country_base VARCHAR(255) NOT NULL,
					PRIMARY KEY (country_id),
					INDEX (country_code),
					INDEX (country_slug)
					) $charset_collate";
				
				dbDelta($sql);
				
				// fill table with default country data
				self::tableBaseFill();
			}
			
			if(isset($create['trans']) && $create['trans'] != ''){
				
				$table_trans = self::$_table_trans;
												
				$sql = "CREATE TABLE IF NOT EXISTS $table_trans ( 
					trans_id INT(11) unsigned NOT NULL AUTO_INCREMENT, 
					country_code CHAR(2) NOT NULL, 
					trans_country VARCHAR(255) NOT NULL,
					trans_lang CHAR(2) NOT NULL, 
					trans_custom TINYINT(1) UNSIGNED NOT NULL,
					PRIMARY KEY (trans_id),
					INDEX (country_code),
					INDEX (trans_lang)
					) $charset_collate";
				
				dbDelta($sql);
								
				// fill table with translations
				self::tableTransFill();
			}
			
			// update 'tables' option, if at least one table was created
			if(!empty($create)) self::updateOption('tables', $tables);
					
		}
		
		
		/**
		 * @param string $slug table id slug (base/trans)
		 * @return string unique table name
		 */
		private static function setTableName($slug){
			global $wpdb;
			$prefix = self::$_prefix;
			// existing table might have same name but originate from another plugin
			// add incremental suffix until table name is unique
			$exist = true;
			$suffix = '';
			$i = 0;
			while($exist){
				$name = $wpdb->prefix . $prefix . $slug . $suffix;
				$exist = $wpdb->get_var("SHOW TABLES LIKE '$name'");
				
				// add increment to suffix
				$suffix = strpos($suffix, '_') !== false ? '' : '_';
				$suffix .= ++$i;
			}
			
			return $name;
		}
		
		
		/**
		 * set region terms
		 */
		private static function setRegionTerms(){
			global $wpdb;
			$terms = array();
			$regions = self::$_regions;
			
			foreach($regions as $region){
				// term_id (this region term might already be registered)
				$term_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM $wpdb->terms WHERE slug = %s LIMIT 1", $region));
				
				if(!$term_id){
					$wpdb->insert(
						$wpdb->terms,
						array(
							'name' => $region,
							'slug' => $region),
						array('%s', '%s')
					);
					$term_id = $wpdb->insert_id;
				}
				
				$terms[$region]['term_id'] = $term_id;
				
				// term taxonomy id
				$term_taxonomy_id = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = %d AND taxonomy = %s LIMIT 1", $term_id, self::$_taxonomy));
				
				if(!$term_taxonomy_id){
					$wpdb->insert(
						$wpdb->term_taxonomy,
						array(
							'term_id' => $term_id,
							'taxonomy' => self::$_taxonomy 
						),
						array('%d', '%s')
					);
					$term_taxonomy_id = $wpdb->insert_id;
				}
				$terms[$region]['term_taxonomy_id'] = $term_taxonomy_id;
			}
			
			return $terms;
		}
		
		
		/**
		 * fill table_base
		 */
		private static function tableBaseFill(){
			global $wpdb;
			$table_base = self::$_table_base;
						
			// default lang
			$lang = self::getOption('lang_country');
			// set of countries
			$set = self::getXMLCountriesPredefined($lang);
			// base attributes
			$atts = self::getXMLObject('default');
			
			if(!empty($set) && !empty($atts)){
				$base_qry = "INSERT INTO $table_base (country_id, country_code, country_slug, country_base) VALUES ";
				$term_relationships = array();
				
				$i = 0;
				foreach($atts as $code => $base){
					$i++;
					$code = esc_sql($code);
					$slug = esc_sql($base->slug);
					$country = esc_sql($set[$code]);
					
					// query for table base
					$base_qry .= $i !== 1 ? "," : "";
					$base_qry .= " ($i, '$code', '$slug', '$country')";
									
					// region relationships (consider cases with multiple regions for same country)
					$term_relationships[$i] = (array) $base->region;
				}
				
				// set region terms
				$terms = self::setRegionTerms();
				
				// remove term relationships(if available), which are based on previous object_ids (not correct any more)		
				$wpdb->query($wpdb->prepare("DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id IN (SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE taxonomy = %s)", self::$_taxonomy));
			
				$term_qry = "INSERT INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES ";
				$j = 0;
				
				foreach($term_relationships as $id => $reg){
					foreach($reg as $r){
						$region = esc_sql($terms[$r]['term_taxonomy_id']);
						$term_qry .= $j !== 0 ? "," : "";
						$term_qry .= " ($id, $region)";
						$j++;
					}
				}
				
				$wpdb->query($term_qry);
				
				return $wpdb->query($base_qry);
			}
			
			return false;
		}
		
		
		/**
		 * fill table_trans
		 */
		private static function tableTransFill(){
			global $wpdb;
			$table_trans = self::$_table_trans;
			$built = self::getXMLBuiltInLangs();
			if($built){
				$qry = "INSERT INTO $table_trans (country_code, trans_country, trans_lang, trans_custom) VALUES ";
				$i = 0;
				foreach((array) $built as $lang){
					$set = self::getXMLCountriesPredefined($lang);
					$lang = esc_sql($lang);
					foreach($set as $code => $country){
						$code = esc_sql($code);
						$country = esc_sql($country);
						$qry .= $i !== 0 ? "," : "";
						$qry .= " ('$code', '$country', '$lang', 0)";
						$i++;
					}
				}
				return $wpdb->query($qry);
			}
			return false;
		}
				
		/*======== PUBLIC GET COUNTRIES ==========*/
		
		/**
		 * get country code for exactly given country name
		 * searched among customized translation (table_trans)
		 * @param string $country - country name in given language
		 * @param string $lang - language iso code; if lang not given, get country code from built in translation of default language
		 * @return string country code or false if nothing found
		 */
		public static function get_country_code($country, $lang = null){
			global $wpdb;
			if(!$lang) {
				$table = self::$_table_base;
				$code = $wpdb->get_var($wpdb->prepare("SELECT country_code FROM $table WHERE country_base = '%s' LIMIT 1", $country));
			}
			else{
				$table = self::$_table_trans;
				$code = $wpdb->get_var($wpdb->prepare("SELECT country_code FROM $table WHERE trans_country = '%s' AND trans_lang = '%s' LIMIT 1", $country, $lang));
			}
			
			return $code;
		}
		
		
		/**
		 * find all matches of country code by giving country name (or part of it)
		 * search all available translations
		 * lang can make the search faster and limit results to one
		 * @return array with all matches similar to query, sorted by country code (array key) and with country_base term added to each country code
		 */
		public static function search_country_code($country, $lang = null){
			global $wpdb;
			$table_base = self::$_table_base;
			$table_trans = self::$_table_trans;
			
			$country = like_escape($country);
			
			$qry = "SELECT base.country_code, trans.country_code, country_base, trans_country, trans_lang FROM $table_base AS base
				LEFT JOIN $table_trans AS trans ON trans.country_code = base.country_code AND trans_country LIKE '%$country%'"; // condition to avoid including translations that don't match (don't make them appear only because country_base matches)
			
			if(!is_null($lang)){
				$lang = esc_sql($lang);
				$qry .= " AND trans_lang = '$lang'";
				$vars[] = $lang;
			}
			
			$qry .= " WHERE trans_country LIKE '%$country%' OR country_base LIKE '%$country%'";
			
			$data = $wpdb->get_results($qry);
						
			if(empty($data)) return $data;
			
			$sorted = array();
			
			foreach($data as $c){
				// repeated items with each data set
				if(!isset($sorted[$c->country_code]['code'])){
					$sorted[$c->country_code]['code'] = $c->country_code;
					$sorted[$c->country_code]['base'] = $c->country_base;
				}
				$sorted[$c->country_code][$c->trans_lang] = $c->trans_country;
			}
			
			return $sorted;
		
		}
		
		
		/*
		 * get country slug 
		 * @return string url compatible name associated with the country, false/null on failure
		 */
		public static function get_country_slug($code){
			global $wpdb;
			$table_base = self::$_table_base;
			$qry = "SELECT country_slug FROM $table_base WHERE country_code = '%s'";
			return $wpdb->get_var($wpdb->prepare($qry, $code));
		}
		
		
		/**
		 * get country name by its iso code
		 * if lang = null, get built in translation of default lang
		 * @param string $code: country code
		 * @param string $lang: language code
		 * @return string country name or empty string if not defined
		 */
		public static function get_country_by_code($code, $lang = null){
			// accept string only
			if(!is_string($code)) return false;
					
			$args = array(
				'lang' => $lang,
				'include' => $code
			);
			$country = self::getDBCountries($args);
			return isset($country[$code]) ? $country[$code] : '';
		}
		
		
		/**
		 * get country name by its slug (url compatible name)
		 * if lang = null, get built-in translation of default lang
		 * @param string $slug: country slug
		 * @param string $lang: language code
		 * @return array(country code, country name) or null if nothing / false if error
		 */
		public static function get_country_by_slug($slug, $lang = null){
			
			// accept string only
			if(!is_string($slug)) return false;
			
			global $wpdb;
			$table_base = self::$_table_base;
			$table_trans = self::$_table_trans;
			
			if(!$lang) {
				$country = $wpdb->get_row($wpdb->prepare("SELECT country_code AS code, country_base AS name FROM $table_base WHERE country_slug = '%s' LIMIT 1", $slug), ARRAY_N);
			}
			else{
				$country = $wpdb->get_row($wpdb->prepare("SELECT country_code AS code, trans_country AS name FROM $table_trans WHERE country_code = (SELECT country_code FROM $table_base WHERE country_slug = '%s' LIMIT 1) AND trans_lang = '%s' LIMIT 1", $slug, $lang), ARRAY_N);
			}
			
			return $country;
		}
		
		
		/*
		 * get countries of the given region
		 * @param string/array $region
		 * @return array country code => country name
		 */
		public static function get_countries_by_region($region, $lang = null){
			$args = array(
				'lang' => $lang,
				'region' => $region,
				'orderby' => 'name'
			);
			
			return self::getDBCountries($args);
		}
		
		
		/**
		 * get countries of the given selection
		 * if lang = null, get default lang
		 */
		public static function get_selection($selection_id, $lang = null){
			$args = array(
				'lang' => $lang,
				'selection' => $selection_id
			);
			
			return self::getDBCountries($args);
		}
		
		
		/**
		 * get all enabled translations for the given set of countries
		 * if include is null, get translations for all countries
		 * @return array code => array( 'en' => 'Germany', 'de' => 'Deutschland', ...)
		 */
		public static function get_translations($include = null){
			$args = array(
				'lang' => 'all',
				'include' => $include
			);
			
			return self::getDBCountries($args);
		}
		
		
		/**
		 * get only translated terms for the given language
		 * don't substitute with default language
		 * for people who don't want to bother creating selections 
		 * user gets only those countries in his list which he set translation for
		 * built-in country lists will provide the entire list of countries (all countries are translated)
		 * @return array code => country
		 */
		public static function get_translated_countries($lang){
			if(!$lang) return false;
			
			$args = array(
				'lang' => $lang,
				'incl_empty' => false
			);
			
			return self::getDBCountries($args);
		}
		
		
		/**
		 * get countries according to given arguments 
		 * @see iasCountries::getDBCountries for description of arguments
		 * @param array $args
		 * lang: string/array of languages, if not given 
		 * include: string/array of country codes to include (string for one term only)
		 * exclude: string/array of country codes to exclude (string for one term only)
		 * selection: int selection id
		 * region: string/array of regions, (string for one term only) 
		 * fallback: boolean (use built in terms of default language as substitute)
		 * incl_empty: boolean, keep empty values or filter out
		 * orderby: string code/name (only one parameter allowed)
		 * order: ASC/DESC
		 */
		public static function get_countries($args = array()){
			// accepted arguments
			$cargs = array(
				'lang' => null,
				'include' => null,
				'exclude' => null,
				'selection' => null,
				'region' => null,
				'orderby' => 'name',
				'order' => 'ASC',
				'fallback' => false,
				'incl_empty' => true
			);
			
			// get required country arguments (not accepted arguments are excluded)
			array_walk($cargs, function(&$var, $key, $args){if(isset($args[$key])) $var = $args[$key]; }, $args);
			
			return self::getDBCountries($cargs);
			
		}
		
		
		/**
		 * if no fallback, no empty values
		 * @param string $header - option at the head if nothing selected (if empty string given, dropdown starts immediately with countries)
		 * @param boolean $echo - echo (true) or return (false)
		 * @param array $args 
			 * $args = array(
					// input field specific
					'name' => 'country',
					'id' => '',
					'class' => '',
					'selected' => array(),
					'multiple' => false,
					'top' => array() // countries to be shown on top of the list
					// country specific (see above)
					'lang' => string only, default null,
					'include' => null,
					'exclude' => null,
					'selection' => null,
					'region' => null,
					'orderby' => 'name',
					'order' => 'ASC',
					'fallback' => false
				);
		 * selected: selected value
		 * multiple: attribute multiple
		 * top: array of country codes to be moved to the top of the country list, e.g. array('DE', 'AT')
		 */
		public static function countries_dropdown($header = null,  $args = array()){
			if(!is_string($args['lang'])) return false;
			
			// input field specific args
			$input = array(
				'name' => 'country',
				'id' => '',
				'class' => '',
				'selected' => array(),
				'multiple' => false,
				'top' => array()
				
			);
					
			// get required input field arguments 
			array_walk($input, function(&$var, $key, $args){if(isset($args[$key])) $var = $args[$key]; }, $args);
			extract($input);		
			
			// country list specific args
			$cargs = array(
				'lang' => null,
				'include' => null,
				'exclude' => null,
				'selection' => null,
				'region' => null,
				'orderby' => 'name',
				'order' => 'ASC',
				'fallback' => false
			);
			
			// get required country arguments 
			array_walk($cargs, function(&$var, $key, $args){if(isset($args[$key])) $var = $args[$key]; }, $args);
						
			
			// add protected arguments (no empty countries if fallback is turned off)
			if(!$cargs['fallback']) {
				$cargs['incl_empty'] = false;
			}
			
			$countries = self::getDBCountries($cargs);
						
			$output = '';
			if ($countries) {
				
				// validate top
				$valTop = array();
				if($top){
					foreach($top as $t){
						if(in_array($t, array_keys($countries)))
							$valTop[$t] = ''; // empty value is overwritten by $countries
					}
				}
				
				$countries = array_merge($valTop, $countries);
								
				$output .= '<select name="' . $name . ($multiple? '[]" multiple="multiple" ': '" ')
					. ($id ? 'id="' . $id . '" ' : '') . ($class ? 'class="' . $class . '" ' : '') .'>';
				// dropdown header
				if($header) $output .= '<option value="">' . $header . '</option>';
				
				foreach ($countries as $key => $label) {
					$output .= '<option value="' . $key. '"'. (in_array($key, $selected) ? ' selected="selected" ' : '') .'>' . $label . '</option>';
				}
				$output .= '</select>';
			}
			
			return $output;
		}
		
	} // end of class
	
	/*============ PUBLIC NICE NAME METHODS ============*/
	
	/**
	 * @see iasCountries::get_country_code($country, $lang)
	 */
	if(!function_exists('ias_cntrs_get_country_code')){
		function ias_cntrs_get_country_code($country, $lang = null){
			return iasCountries::get_country_code($country, $lang);
		}
	}
	
	/**
	 * @see iasCountries::search_country_code($country, $lang)
	 */
	if(!function_exists('ias_cntrs_search_country_code')){
		function ias_cntrs_search_country_code($country, $lang = null){
			return iasCountries::search_country_code($country, $lang);
		}
	}
	
	/**
	 * @see iasCountries::get_country_slug($code)
	 */
	if(!function_exists('ias_cntrs_get_country_slug')){
		function ias_cntrs_get_country_slug($code){
			return iasCountries::get_country_slug($code);
		}
	}
	
	/**
	 * @see iasCountries::get_country_by_code($code, $lang)
	 */
	if(!function_exists('ias_cntrs_get_country_by_code')){
		function ias_cntrs_get_country_by_code($code, $lang = null){
			return iasCountries::get_country_by_code($code, $lang);
		}
	}
	
	/**
	 * @see iasCountries::get_country_by_slug($slug, $lang)
	 */
	if(!function_exists('ias_cntrs_get_country_by_slug')){
		function ias_cntrs_get_country_by_slug($slug, $lang = null){
			return iasCountries::get_country_by_slug($slug, $lang);
		}
	}
	
	/**
	 * @see iasCountries::get_countries_by_region($region, $lang = null)
	 */
	if(!function_exists('ias_cntrs_get_countries_by_region')){
		function ias_cntrs_get_countries_by_region($region, $lang = null){
			return iasCountries::get_countries_by_region($region, $lang);
		}
	}
	
	/**
	 * @see iasCountries::get_selection($selection_id, $lang = null)
	 */
	if(!function_exists('ias_cntrs_get_selection')){
		function ias_cntrs_get_selection($selection_id, $lang = null){
			return iasCountries::get_selection($selection_id, $lang);
		}
	}
	
	/**
	 * @see iasCountries::get_translations($include = null)
	 */
	if(!function_exists('ias_cntrs_get_translations')){
		function ias_cntrs_get_translations($include = null){
			return iasCountries::get_translations($include);
		}
	}
	
	/**
	 * @see iasCountries::get_translated_countries($lang)
	 */
	if(!function_exists('ias_cntrs_get_translated_countries')){
		function ias_cntrs_get_translated_countries($lang){
			return iasCountries::get_translated_countries($lang);
		}
	}
	
	/**
	 * @see iasCountries::get_countries($args = array())
	 */
	if(!function_exists('ias_cntrs_get_countries')){
		function ias_cntrs_get_countries($args = array()){
			return iasCountries::get_countries($args);
		}
	}
	
	/**
	 * @see iasCountries::countries_dropdown($header = null,  $args = array())
	 */
	if(!function_exists('ias_cntrs_get_dropdown')){
		function ias_cntrs_get_dropdown($header = null, $args = array()){
			return iasCountries::countries_dropdown($header, $args);
		}
	}
	
	/**
	 * @see iasCountries::countries_dropdown($header = null,  $args = array())
	 */
	if(!function_exists('ias_cntrs_dropdown')){
		function ias_cntrs_dropdown($header = null, $args = array()){
			echo iasCountries::countries_dropdown($header, $args);
		}
	}
}
?>