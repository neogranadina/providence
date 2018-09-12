<?php
/* ----------------------------------------------------------------------
 * collectionHierarchyBuilderRefinery.php : 
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2018 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
 *
 * This source code is free and modifiable under the terms of 
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */
 	require_once(__CA_LIB_DIR__.'/Import/BaseRefinery.php');
 	require_once(__CA_LIB_DIR__.'/Utils/DataMigrationUtils.php');
	require_once(__CA_LIB_DIR__.'/Parsers/ExpressionParser.php');
	require_once(__CA_APP_DIR__.'/helpers/importHelpers.php');
 
	class EADCollectionHierarchyBuilderRefinery extends BaseRefinery {
		# -------------------------------------------------------
		public function __construct() {
			$this->ops_name = 'EADCollectionHierarchyBuilder';
			$this->ops_title = _t('EAD Collection hierarchy builder');
			$this->ops_description = _t('Builds a collection hierarchy from an EAD &lt;dsc&gt; block.');
			
			$this->opb_returns_multiple_values = true;
			$this->opb_supports_relationships = true;
			
			parent::__construct();
		}
		# -------------------------------------------------------
		/**
		 * Override checkStatus() to return true
		 */
		public function checkStatus() {
			return array(
				'description' => $this->getDescription(),
				'errors' => array(),
				'warnings' => array(),
				'available' => true,
			);
		}
		# -------------------------------------------------------
		/**
		 *
		 */
		public function refine(&$pa_destination_data, $pa_group, $pa_item, $pa_source_data, $pa_options=null) {
			$logger = (isset($pa_options['log']) && is_object($pa_options['log'])) ? $pa_options['log'] : null;
			$reader = caGetOption('reader', $pa_options, null);
			
			$t_mapping = caGetOption('mapping', $pa_options, null);
			if ($t_mapping) {
				if ($t_mapping->get('table_num') != Datamodel::getTableNum('ca_collections')) { 
					if ($logger) {
						$logger->logError(_t("EADCollectionHierarchyBuilder refinery may only be used in imports to ca_collections"));
					}
					return null; 
				}
			}
			if ($pa_group['destination'] !== 'ca_collections._children') {
				if ($logger) {
					$logger->logError(_t("Target CollectiveAccess element EADCollectionHierarchyBuilder must be ca_collections._collection"));
				}
				return null; 
			}
			
			$children = [];
			
			// Extract <dsc> collection list as XML fragments and convert it to a standalone document for processing
			if($reader && ($subcollection_xml = $reader->get('/archdesc/dsc')) && isset($pa_item['settings']['EADCollectionHierarchyBuilder_levels']) && is_array($level_mappings = $pa_item['settings']['EADCollectionHierarchyBuilder_levels'])) {
				$subcollections = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?><subcollections>{$subcollection_xml}</subcollections>");
				
				$l = 1;
				foreach($subcollections as $tag => $data) {
					$children[] = $this->_mapLevels($data, $l, $level_mappings);
				}
			}
			return ['_children' => $children];
		}
		# -------------------------------------------------------	
		/**
		 * Extract and map collections/sub-collections data using per-level mappings
		 *
		 * @param SimpleXMLElement $data XML data for a level in the hierarchy
		 * @param int $l Current level in hierarchy
		 * @param array $level_mappings Mappings for each collection level. Keys are level attribute values; values are dictionaries with per-field mappings.
		 *
		 * @return array Mapped values in a format ready for import
		 */
		private function _mapLevels($data, &$l, $level_mappings) {
			if(isset($level_mappings[(string)$data["level"]]) && is_array($mapping = $level_mappings[(string)$data["level"]])) { 
				$tag = 'c'.sprintf("%02d", $l);
				
				$mapped_values = ['_table' => $mapping['table'], '_type' => $mapping['type']];
				foreach(array_merge($mapping['attributes'], ['preferred_labels' => ['name' => $mapping['preferredLabel']]]) as $f => $t) {
					if(is_array($t)) {
						// container
						foreach($t as $sf => $st) {
							$xpaths = caGetTemplateTags($st);	// Extract xpath tags from string
							$values = array_map(function($v) use ($data) { $v = ltrim($v, '/'); return $data->xpath("{$v}"); }, $xpaths);	// get values for each xpath tag
				 
							foreach($xpaths as $i => $p) {	// replace tags with values
								$v = isset($values[$i][0]) ? dom_import_simplexml($values[$i][0])->nodeValue : '';
								$st = str_replace("^{$p}", $v, $st);
							}
						
							$mapped_values[$f][$sf] = $st;
						}
					} else {
						$xpaths = caGetTemplateTags($t);	// Extract xpath tags from string
						$values = array_map(function($v) use ($data) { $v = ltrim($v, '/'); return $data->xpath("{$v}"); }, $xpaths); // get values for each xpath tag
						foreach($xpaths as $i => $p) { // replace tags with values
							$v = isset($values[$i][0]) ? dom_import_simplexml($values[$i][0])->nodeValue : '';
							$t = str_replace("^{$p}", $v, $t);
						}
						$mapped_values[$f] = $t;
					}
				}
			}
			// Are there sub-collections?
			$l++;
			$tag = 'c'.sprintf("%02d", $l);
			if ($data->{$tag}) {
				foreach($data->{$tag} as $d) {
					$mapped_values['_children'][] = $this->_mapLevels($d, $l, $level_mappings);
				}
			}
			$l--;
			
			return $mapped_values;
		}
		# -------------------------------------------------------	
		/**
		 * EADCollectionHierarchyBuilder returns multiple values
		 *
		 * @return bool
		 */
		public function returnsMultipleValues() {
			return true;
		}
		# -------------------------------------------------------
	}
	
	BaseRefinery::$s_refinery_settings['EADCollectionHierarchyBuilder'] = array(	
		'EADCollectionHierarchyBuilder_levels' => array(
			'formatType' => FT_TEXT,
			'displayType' => DT_FIELD,
			'width' => 10, 'height' => 1,
			'takesLocale' => false,
			'default' => '',
			'label' => _t('Level mappings'),
			'description' => _t('Mappings for each level in the hierarchy')
		)
	);
