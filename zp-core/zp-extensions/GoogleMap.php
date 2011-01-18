<?php
/*
 * google_maps -- provides for placing google maps on image and album pages.
 * Automatically detects if it is on an Image or Album page
 *
 * @package plugins
 */
$plugin_description = gettext("Support for providing Google Maps (API version 3) based on EXIF latitude and longitude in the images.");
$plugin_author = 'Stephen Billard (sbillard)';
$plugin_version = '1.4.0';
$plugin_URL = "http://www.zenphoto.org/documentation/plugins/_".PLUGIN_FOLDER."---GoogleMap.php.html";
$plugin_disable = (version_compare(PHP_VERSION, '5.0.0') != 1) ? gettext('PHP version 5 or greater is required.') : false;

if ($plugin_disable) {
	setOption('zp_plugin_GoogleMap',0);
} else {
	$option_interface = 'googlemapOptions';
	if (isset($_zp_gallery_page) && $_zp_gallery_page != 'index.php') {
		zp_register_filter('theme_head','googlemap_js');
	}
}

/**
 * Output the header JS
 */
function googlemap_js() {
	require_once(dirname(__FILE__).'/GoogleMap/GoogleMap.php');
	require_once(dirname(__FILE__).'/GoogleMap/JSMin.php');
	global $MAP_OBJECT, $_zp_current_image, $_zp_current_album;
	$MAP_OBJECT = new GoogleMapAPI();
	$MAP_OBJECT->setLocale(substr(getOption('locale'),0,2));
	echo $MAP_OBJECT->getHeaderJS()."\n";
	?>
	<script type="text/javascript">
		function toggleMap(id,map,center,bds) {
			jQuery('#'+id).toggle();
			if ($('#'+id).is(':visible')) {
				map.fitBounds(bds);
				map.setCenter(center);
				setTimeout(
					function() {
						if (bds) {
							map.fitBounds(bds);
						}
						map.setCenter(center);
					}, 100);
			}
		}
	</script>
	<?php
}

/**
 * Plugin option handling class
 *
 */
class googlemapOptions {

	function googlemapOptions() {
		/* put any setup code needed here */
		setOptionDefault('gmap_width', 595);
		setOptionDefault('gmap_height', 300);
		setOptionDefault('gmap_map', 1);
		setOptionDefault('gmap_hybrid', 1);
		setOptionDefault('gmap_satellite', 1);
		setOptionDefault('gmap_terrain', 1);
		setOptionDefault('gmap_control_size', 'small');
		setOptionDefault('gmap_control', 'horizontal');
		setOptionDefault('gmap_starting_map', 'hybrid');
		setOptionDefault('gmap_zoom', 16);
		if (getOption('gmap_hide')) {
			setOptionDefault('gmap_display', 'hide');
		} else {
			setOptionDefault('gmap_display', 'show');
		}
	}

	function getOptionsSupported() {
		$MapTypes =  array(); // order matters here because the first allowed map is selected if the 'gmap_starting_map' is not allowed
		if (getOption('gmap_map')) $MapTypes[gettext('Map')] = 'map';
		if (getOption('gmap_hybrid')) $MapTypes[gettext('Hybrid')] = 'hybrid';
		if (getOption('gmap_satellite')) $MapTypes[gettext('Satellite')] = 'satellite';
		if (getOption('gmap_terrain')) $MapTypes[gettext('Terrain')] = 'terain';

		$defaultmap = getOption('gmap_starting_map');
		if (array_search($defaultmap, $MapTypes) === false) { // the starting map is not allowed, pick a new one
			$temp = $MapTypes;
			$defaultmap = array_shift($temp);
			setOption('gmap_starting_map', $defaultmap);
		}

		return array(	gettext('Map dimensions—width') => array('key' => 'gmap_width', 'type' => OPTION_TYPE_TEXTBOX,
																	'order'=>6,
																	'desc' => gettext('The default width of the map.')),
									gettext('Map dimensions—height') => array('key' => 'gmap_height', 'type' => OPTION_TYPE_TEXTBOX,
																	'order'=>6.5,
																	'desc' => gettext('The default height of the map.')),
									gettext('Initial Zoom') => array('key' => 'gmap_zoom', 'type' => OPTION_TYPE_TEXTBOX,
																	'order'=>7,
																	'desc' => gettext('The inital zoom of the map.')),
									gettext('Allowed maps') => array('key' => 'gmap_allowed_maps', 'type' => OPTION_TYPE_CHECKBOX_ARRAY,
																	'order'=>1,
																	'checkboxes' => array(gettext('Map') => 'gmap_map', gettext('Satellite') => 'gmap_satillite' ,gettext('Hybrid') => 'gmap_hybrid' ,gettext('Terrain') => 'gmap_terrain'),
																	'desc' => gettext('Select the map types that are allowed.')),
									gettext('Map control size') => array('key' => 'gmap_control_size', 'type' => OPTION_TYPE_RADIO,'buttons' => array(gettext('Small') => 'small',gettext('Large') => 'large'),
																	'order'=>4,
																	'desc' => gettext('Use buttons or list for the map type selector.')),
									gettext('Map controls') => array('key' => 'gmap_control', 'type' => OPTION_TYPE_RADIO,'buttons' => array(gettext('None') => 'none',gettext('Dropdown') => 'dropdown',gettext('Horizontal') => 'horizontal'),
																	'order'=>3,
																	'desc' => gettext('Select the kind of map controls.')),
									gettext('Initial map display selection') => array('key' => 'gmap_starting_map', 'type' => OPTION_TYPE_SELECTOR, 'selections' => $MapTypes,
																	'order'=>2,
																	'desc' => gettext('Select the initial type of map to display.')),
									gettext('Map display') => array('key' => 'gmap_display', 'type' => OPTION_TYPE_SELECTOR,
																	'selections' => array(gettext('show')=>'show', gettext('hide')=>'hide',gettext('colorbox')=>'colorbox'),
																	'order'=>2.5,
																	'desc' => gettext('Select <em>hide</em> to initially hide the map. Select <em>colorbox</em> for the map to display in a colorbox. Select <em>show</em> and the map will display when the page loads.'))
									);
	}

	function handleOption($option, $currentValue) {
	}
}

/**
 * Returns true if the current image has EXIF location data
 *
 * @return bool
 */
function hasMapData() {
	$exif = getImageMetaData(NULL, false);
	if(!empty($exif['EXIFGPSLatitude']) && !empty($exif['EXIFGPSLongitude'])){
		return true;
	}
	return false;
}

/**
 * Adds a geoPoint after first insuring it uses periods for the decimal separator
 *
 * @param object $MAP_OBJECT
 * @param string $lat Latitude of the point
 * @param string $long Longitude of the point
 * @param string $title point title
 * @param string $desc point description
 */
function addPoint($MAP_OBJECT, $lat, $long, $title, $desc) {
	$MAP_OBJECT->addMarkerByCoords(str_replace(',', '.', $long), str_replace(',', '.', $lat), $title, $desc);
}

/**
 * Gathers the data for an image
 * @param image $obj
 * @param object $MAP_OBJECT
 */
function getImageGeodata($obj,$MAP_OBJECT) {
	$exif = $obj->getMetaData();
	if(!empty($exif['EXIFGPSLatitude']) && !empty($exif['EXIFGPSLongitude'])){
		$lat = $exif['EXIFGPSLatitude'];
		$long = $exif['EXIFGPSLongitude'];
		if($exif['EXIFGPSLatitudeRef'] == 'S') {
			$lat = '-' . $lat;
		}
		if($exif['EXIFGPSLongitudeRef'] == 'W') {
			$long = '-' . $long;
		}
		$desc = $obj->getDesc();
		$title = $obj->getTitle();
		if (empty($desc)) {
			$desc = $title;
		}
		addPoint($MAP_OBJECT, $lat, $long, $title, '<p align=center >' . $desc."</p>");
		return true;
	}
	return false;
}

/**
 * Gathers the data for an album
 * @param album $obj
 * @param object $MAP_OBJECT
 */
function getAlbumGeodata($obj,$MAP_OBJECT){
	$result = false;
	$images = $obj->getImages(0);
	foreach ($images as $an_image) {
		$image = newImage($obj, $an_image);
		$exif = $image->getMetaData();
		if(!empty($exif['EXIFGPSLatitude']) && !empty($exif['EXIFGPSLongitude'])){
			$result = true;
			$lat = $exif['EXIFGPSLatitude'];
			$long = $exif['EXIFGPSLongitude'];
			if($exif['EXIFGPSLatitudeRef'] == 'S') {
				$lat = '-' . $lat;
			}
			if($exif['EXIFGPSLongitudeRef'] == 'W') {
				$long = '-' . $long;
			}
			$infoHTML = '<a href="' . html_encode($image->getImageLink()) . '"><img src="' .
				html_encode($image->getThumb()) . '" alt="' . $image->getDesc() . '" ' .
				'style=" margin-left: 30%; margin-right: 10%; border: 0px; " /></a><p align=center >' . $image->getDesc()."</p>";
			addPoint($MAP_OBJECT, $lat, $long, $image->getTitle(), $infoHTML);
		}
	}
	return $result;
}

/**
 * Output the google map
 *
 * @param string $text text for the "toggle" link that shows/hides the map. Set empty to omit
 * @param string $id used to set the IDs for the toggle href element ($id_toggle) and the map element ($id_data)
 * @param string $hide initial map state: "hide", "show", or "colrobox"
 * @param object $obj optional image/album object. Pass string for generic map and use callback to set points
 * @param function $callback optional callback function to set map options.
 */
function printGoogleMap($text=NULL, $id=NULL, $hide=NULL, $obj=NULL, $callback=NULL) {
	global $_zp_current_album,$_zp_current_image;
	if (is_null($obj)) {
		if (is_null($_zp_current_image)) {
			$obj = $_zp_current_album;
		} else {
			$obj = $_zp_current_image;
		}
	}
	if (is_null($obj)) {
		$MAP_OBJECT = NULL;
		return false;
	}
	if (is_object($obj)) {
		$type = $obj->table;
		$typeid = $obj->getID();
	} else {
		$type = $obj;
		$typeid = '';
	}
	$MAP_OBJECT = new GoogleMapAPI($type.$typeid);
	$MAP_OBJECT->_minify_js = defined('RELEASE');
	$MAP_OBJECT->setZoomLevel(getOption('gmap_zoom'));
	$MAP_OBJECT->setWidth(getOption('gmap_width'));
	$MAP_OBJECT->setHeight(getOption('gmap_height'));
	$mapcontrol = getOption('gmap_control');
	if ($mapcontrol=='none') {
		$MAP_OBJECT->disableTypeControls();
	} else {
		$MAP_OBJECT->enableMapControls();
		$MAP_OBJECT->setTypeControlsStyle($mapcontrol);
	}
	$MAP_OBJECT->setControlSize(getOption('gmap_control_size'));
	$MAP_OBJECT->setMapType(getOption('gmap_starting_map'));
	switch ($type) {
		case 'images':
			if (getImageGeodata($obj,$MAP_OBJECT)) {
				break;
			}
			$MAP_OBJECT = NULL;
			return false;
		case 'albums':
			if (getAlbumGeodata($obj,$MAP_OBJECT)) {
				break;
			}
			$MAP_OBJECT = NULL;
			return false;
		default:
			break;
	}
	$type = $type.$typeid.'_';
	if (is_null($id)) {
		$id = $type.'googlemap';
	}
	$id_toggle = $id.'_toggle';
	$id_data = $id.'_data';
	if (is_null($text)) {
		$text = gettext('Google Map');
	}
	if (is_null($hide)) {
		$hide = getOption('gmap_display');
	}
	if (!is_string($hide)) {
		if ($hide) {
			$hide = 'hide';
		} else {
			$hide = 'show';
		}
	}

	if (!is_null($callback)) {
		call_user_func($callback,$MAP_OBJECT);
	}
	if (empty($text)) {
		$hide = 'hide';
	}

	echo $MAP_OBJECT->getMapJS();
	switch ($hide) {
		case 'colorbox':
			$w = str_replace('px','',$MAP_OBJECT->width)+20;
			$h = str_replace('px','',$MAP_OBJECT->height)+20;
			?>
			<a href="<?php echo WEBPATH.'/'.ZENFOLDER.'/'.PLUGIN_FOLDER.'/GoogleMap/m.php?mapobject='.html_encode(serialize($MAP_OBJECT)) ?>" title="<?php echo $text; ?>" class="google_map">
				<?php echo $text; ?>
			</a>
			<script type="text/javascript">
				// <!-- <![CDATA[
				$(document).ready(function(){
					$(".google_map").colorbox({iframe:true, innerWidth:'<?php echo $w; ?>px', innerHeight:'<?php echo $h; ?>px'});
				});
				// ]]> -->
			</script>
			<?php
			break;
		case 'hide':
			?>
				<script type="text/javascript">
					//<!--
					<?php
					if($MAP_OBJECT->zoom_encompass && (count($MAP_OBJECT->_markers) > 1 || count($MAP_OBJECT->_polylines) >= 1 || count($MAP_OBJECT->_overlays) >= 1)) {
						?>
						var bnds<?php echo $MAP_OBJECT->map_id; ?>=new google.maps.LatLngBounds(new google.maps.LatLng(<?php echo $MAP_OBJECT->_min_lat.','.$MAP_OBJECT->_min_lon; ?>), new google.maps.LatLng(<?php echo $MAP_OBJECT->_max_lat.','.$MAP_OBJECT->_max_lon; ?>));
						<?php
					} else {
						?>
						var bnds<?php echo $MAP_OBJECT->map_id; ?>=null;
						<?php
					}
					?>
					//-->
				</script>
				<a id="<?php echo $id_toggle; ?>" href="javascript:toggleMap('<?php echo $id_data; ?>',map<?php echo $MAP_OBJECT->map_id; ?>,new google.maps.LatLng(<?php echo $MAP_OBJECT->center_lat; ?>,<?php echo $MAP_OBJECT->center_lon; ?>),bnds<?php echo $MAP_OBJECT->map_id; ?>);" title="<?php  echo gettext('Display or hide the Google Map.'); ?>">
					<?php echo $text; ?>
				</a>
			<div id="<?php echo $id_data; ?>" style="display:none">
				<?php
				echo $MAP_OBJECT->printOnLoad();
				echo $MAP_OBJECT->printMap();
				?>
			</div>
			<?php
			break;
		case 'show':
			?>
			<script type="text/javascript">
				//<!--
				<?php
				if($MAP_OBJECT->zoom_encompass && (count($MAP_OBJECT->_markers) > 1 || count($MAP_OBJECT->_polylines) >= 1 || count($MAP_OBJECT->_overlays) >= 1)) {
					?>
					var bnds<?php echo $MAP_OBJECT->map_id; ?>=new google.maps.LatLngBounds(new google.maps.LatLng(<?php echo $MAP_OBJECT->_min_lat.','.$MAP_OBJECT->_min_lon; ?>), new google.maps.LatLng(<?php echo $MAP_OBJECT->_max_lat.','.$MAP_OBJECT->_max_lon; ?>));
					<?php
				} else {
					?>
					var bnds<?php echo $MAP_OBJECT->map_id; ?>=null;
					<?php
				}
				?>
				//-->
			</script>
			<a id="<?php echo $id_toggle; ?>" href="javascript:toggleMap('<?php echo $id_data; ?>',map<?php echo $MAP_OBJECT->map_id; ?>,new google.maps.LatLng(<?php echo $MAP_OBJECT->center_lat; ?>,<?php echo $MAP_OBJECT->center_lon; ?>),bnds<?php echo $MAP_OBJECT->map_id; ?>);" title="<?php  echo gettext('Display or hide the Google Map.'); ?>">
				<?php echo $text; ?>
			</a>
			<div id="<?php echo $id_data; ?>">
				<?php
				echo $MAP_OBJECT->printOnLoad();
				echo $MAP_OBJECT->printMap();
				?>
			</div>
			<?php
			break;
	}
}

?>