<?php
/*
  Plugin Name: Event Post
  Plugin URI: http://event-post.com
  Description: Add calendar and/or geolocation metadata on posts. For a better experience, we recommand to use it with <a href="https://wordpress.org/plugins/shortcode-ui/" target="_blank">Shortcake (shortcode UI)</a> installed.
  Version: 4.5.2
  Author: N.O.U.S. Open Useful and Simple
  Contributors: bastho,ecolosites
  Author URI: http://avecnous.eu
  License: GPLv2
  Text Domain: event-post
  Domain Path: /languages/
  Tags: Post,posts,event,date,geolocalization,gps,widget,map,openstreetmap,EELV,calendar,agenda
 */

global $EventPost;
$EventPost = new EventPost();

$EventPost_cache=array();

/**
 * The main class where everything begins.
 *
 * Add calendar and/or geolocation metadata on posts
 */
class EventPost {
    const META_START = 'event_begin';
    const META_END = 'event_end';
    const META_COLOR = 'event_color';
    // http://codex.wordpress.org/Geodata
    const META_ADD = 'geo_address';
    const META_LAT = 'geo_latitude';
    const META_LONG = 'geo_longitude';

    public $list_id;
    public $NomDuMois;
    public $Week;
    public $settings;
    public $dateformat;

    public $version = '4.5';

    public $map_interactions;

    public $Shortcodes;

    public function __construct() {
        load_plugin_textdomain('event-post', false, 'event-post/languages');

	add_action('widgets_init', array(&$this,'init'), 1);
        add_action('save_post', array(&$this, 'save_postdata'));
        add_action('admin_menu', array(&$this, 'manage_options'));
	add_filter('dashboard_glance_items', array(&$this, 'dashboard_right_now'));

        // Scripts
        add_action( 'admin_init', array(&$this, 'editor_styles'));
        add_action('admin_enqueue_scripts', array(&$this, 'admin_head'));
        add_action('admin_print_scripts', array(&$this, 'admin_scripts'));
        add_action('wp_enqueue_scripts', array(&$this, 'load_styles'));

        // Single
        add_filter('the_content', array(&$this, 'display_single'), 9999);
        add_filter('the_title', array(&$this, 'the_title'), 9999, 2);
        add_action('the_event', array(&$this, 'print_single'));
        add_action('wp_head', array(&$this, 'single_header'));

        // Ajax
        add_action('wp_ajax_EventPostGetLatLong', array(&$this, 'GetLatLong'));
        add_action('wp_ajax_EventPostHumanDate', array(&$this, 'HumanDate'));
        add_action('wp_ajax_EventPostCalendar', array(&$this, 'ajaxcal'));
        add_action('wp_ajax_nopriv_EventPostCalendar', array(&$this, 'ajaxcal'));
        add_action('wp_ajax_EventPostCalendarDate', array(&$this, 'ajaxdate'));
        add_action('wp_ajax_nopriv_EventPostCalendarDate', array(&$this, 'ajaxdate'));

	// Calendar publishing
        add_action('wp_ajax_EventPostFeed', array(&$this, 'feed'));
        add_action('wp_ajax_nopriv_EventPostFeed', array(&$this, 'feed'));

	//
	add_filter('eventpost_list_shema',array(&$this, 'custom_shema'),10,1);

	// Admin
	add_action('admin_post_EventPostSaveSettings', array(&$this, 'save_settings'));// Settings link on the Plugins list
	add_filter('plugin_action_links_event-post/eventpost.php', array( &$this, 'settings_link' ) );
        add_filter('plugin_row_meta', array( &$this, 'row_meta' ), 1, 4);

        add_action('widgets_init', array(&$this, 'register_widgets'),1,1);

        $inc_path = plugin_dir_path(__FILE__).'inc/';
        include_once ($inc_path . 'widget.php');
        include_once ($inc_path . 'widget.cal.php');
        include_once ($inc_path . 'widget.map.php');
        include_once ($inc_path . 'multisite.php');
        include_once ($inc_path . 'shortcodes.php');
        include_once ($inc_path . 'openweathermap.php');
        include_once ($inc_path . 'children.php');

    }

    /**
     * PHP4 constructor
     */
    public function EventPost(){
        $this->__construct();
    }

    /**
     * Init all variables when WP is ready
     *
     * @action evenpost_init
     * @filter eventpost_default_list_shema
     * @filter eventpost_list_shema
     */
    public function init(){
	$this->META_START = 'event_begin';
        $this->META_END = 'event_end';
        $this->META_COLOR = 'event_color';
        // http://codex.wordpress.org/Geodata
        $this->META_ADD = 'geo_address';
        $this->META_LAT = 'geo_latitude';
        $this->META_LONG = 'geo_longitude';
        $this->list_id = 0;
        $this->NomDuMois = array('', __('Jan', 'event-post'), __('Feb', 'event-post'), __('Mar', 'event-post'), __('Apr', 'event-post'), __('May', 'event-post'), __('Jun', 'event-post'), __('Jul', 'event-post'), __('Aug', 'event-post'), __('Sept', 'event-post'), __('Oct', 'event-post'), __('Nov', 'event-post'), __('Dec', 'event-post'));
        $this->Week = array(__('Sunday', 'event-post'), __('Monday', 'event-post'), __('Tuesday', 'event-post'), __('Wednesday', 'event-post'), __('Thursday', 'event-post'), __('Friday', 'event-post'), __('Saturday', 'event-post'));

        $this->maps = $this->get_maps();
	$this->settings = $this->get_settings();

        do_action('evenpost_init', $this);

        $this->Shortcodes = new EventPost_Shortcodes();

        // Edit
        add_action('add_meta_boxes', array(&$this, 'add_custom_box'));
        foreach($this->settings['posttypes'] as $posttype){
            add_filter('manage_'.$posttype.'_posts_columns', array(&$this, 'columns_head'), 2);
            add_action('manage_'.$posttype.'_posts_custom_column', array(&$this, 'columns_content'), 10, 2);
        }


        if (!empty($this->settings['markpath']) && !empty($this->settings['markurl'])) {
            $this->markpath = ABSPATH.'/'.$this->settings['markpath'];
            $this->markurl = $this->settings['markurl'];
        } else {
            $this->markpath = plugin_dir_path(__FILE__) . 'markers/';
            $this->markurl = plugins_url('/markers/', __FILE__);
        }

        $this->dateformat = str_replace(array('yy', 'mm', 'dd'), array('Y', 'm', 'd'), __('yy-mm-dd', 'event-post'));

        $this->default_list_shema = apply_filters('eventpost_default_list_shema', array(
            'container' => '
            <%type% class="event_loop %id% %class%" id="%listid%" style="%style%" %attributes%>%list%
            </%type%><!-- .event_loop -->',
            'item' => '
                <%child% class="event_item %class%" data-color="%color%">
                    <a href="%event_link%">
                        %event_thumbnail%
                        <h5>%event_title%</h5>
                    </a>
                    %event_date%
                    %event_cat%
                    %event_location%
                    %event_excerpt%
                </%child%><!-- .event_item -->'
        ));
	$this->list_shema = apply_filters('eventpost_list_shema',$this->default_list_shema);

        $this->map_interactions=array(
            'DragRotate'=>__('Drag Rotate', 'event-post'),
            'DoubleClickZoom'=>__('Double Click Zoom', 'event-post'),
            'DragPan'=>__('Drag Pan', 'event-post'),
            'PinchRotate'=>__('Pinch Rotate', 'event-post'),
            'PinchZoom'=>__('Pinch Zoom', 'event-post'),
            'KeyboardPan'=>__('Keyboard Pan', 'event-post'),
            'KeyboardZoom'=>__('Keyboard Zoom', 'event-post'),
            'MouseWheelZoom'=>__('Mouse Wheel Zoom', 'event-post'),
            'DragZoom'=>__('Drag Zoom', 'event-post'),
        );

    }

    public function register_widgets(){
        register_widget('EventPost_List');
        register_widget('EventPost_Map');
        register_widget('EventPost_Cal');
    }

    /**
     * Usefull hexadecimal to decimal converter. Returns an array of RGB from a given hexadecimal color.
     * @param string $color
     * @return array $color($R, $G, $B)
     */
    public function hex2dec($color = '000000') {
        $tbl_color = array();
        if (!strstr('#', $color)){
            $color = '#' . $color;
	}
        $tbl_color['R'] = hexdec(substr($color, 1, 2));
        $tbl_color['G'] = hexdec(substr($color, 3, 2));
        $tbl_color['B'] = hexdec(substr($color, 5, 2));
        return $tbl_color;
    }
    /**
     * Fetch all registered image sizes
     * @global array $_wp_additional_image_sizes
     * @return array
     */
    function get_thumbnail_sizes(){
        global $_wp_additional_image_sizes;
        $sizes = array('thumbnail', 'medium', 'large', 'full');
        foreach(array_keys($_wp_additional_image_sizes) as $size){
            $sizes[]=$size;
        }
        return $sizes;
    }

    /**
     * Just for localization
     */
    private function no_use() {
        __('Add calendar and/or geolocation metadata on posts', 'event-post');
        __('Event Post', 'event-post');
    }

    /**
     * Get blog settings, load and saves default settings if needed. Can be filterred using
     *
     * `<?php add_filter('eventpost_getsettings', 'some_function'); ?>`
     *
     * @action eventpost_getsettings_action
     * @filter eventpost_getsettings
     * @return array
     */
    public function get_settings() {
        $ep_settings = get_option('ep_settings');
        $reg_settings=false;
	if(!is_array($ep_settings)){
	    $ep_settings = array();
	}
        if (!isset($ep_settings['dateformat']) || empty($ep_settings['dateformat'])) {
            $ep_settings['dateformat'] = get_option('date_format');
            $reg_settings=true;
        }
        if (!isset($ep_settings['timeformat']) || empty($ep_settings['timeformat'])) {
            $ep_settings['timeformat'] = get_option('time_format');
            $reg_settings=true;
        }
        if (!isset($ep_settings['tile']) || empty($ep_settings['tile']) || !isset($this->maps[$ep_settings['tile']])) {
            $maps = array_keys($this->maps);
            $ep_settings['tile'] = $this->maps[$maps[0]]['id'];
            $reg_settings=true;
        }
        if(!isset($ep_settings['zoom']) || !is_numeric($ep_settings['zoom'])){
            $ep_settings['zoom']=12;
            $reg_settings=true;
        }
        if (!isset($ep_settings['cache']) || !is_numeric($ep_settings['cache'])) {
            $ep_settings['cache'] = 0;
            $reg_settings=true;
        }
        if (!isset($ep_settings['export']) || empty($ep_settings['export'])) {
            $ep_settings['export'] = 'both';
            $reg_settings=true;
        }
        if (!isset($ep_settings['dateforhumans'])) {
            $ep_settings['dateforhumans'] = 1;
            $reg_settings=true;
        }
        if (!isset($ep_settings['emptylink'])) {
            $ep_settings['emptylink'] = 1;
            $reg_settings=true;
        }
        if (!isset($ep_settings['markpath'])) {
            $ep_settings['markpath'] = '';
            $reg_settings=true;
        }
        if (!isset($ep_settings['singlepos']) || empty($ep_settings['singlepos'])) {
            $ep_settings['singlepos'] = 'after';
            $reg_settings=true;
        }
	if (!isset($ep_settings['loopicons'])) {
            $ep_settings['loopicons'] = 1;
            $reg_settings=true;
        }
        if (!isset($ep_settings['adminpos']) || empty($ep_settings['adminpos'])) {
            $ep_settings['adminpos'] = 'side';
            $reg_settings=true;
        }
	if (!isset($ep_settings['container_shema']) ) {
            $ep_settings['container_shema'] = '';
            $reg_settings=true;
        }

	if (!isset($ep_settings['item_shema']) ) {
            $ep_settings['item_shema'] = '';
            $reg_settings=true;
        }

        if(!isset($ep_settings['datepicker']) || !in_array($ep_settings['datepicker'], array('simple', 'native'))){
            $ep_settings['datepicker']='simple';
            $reg_settings=true;
        }

        if(!isset($ep_settings['posttypes']) || !is_array($ep_settings['posttypes'])){
            $ep_settings['posttypes']=array('post');
            $reg_settings=true;
        }

        do_action_ref_array('eventpost_getsettings_action', array(&$ep_settings, &$reg_settings));

        //Save settings  not changed
        if($reg_settings===true){
           update_option('ep_settings', $ep_settings);
        }
        return apply_filters('eventpost_getsettings', $ep_settings);
    }

    /**
     * Checks if HTML schemas are not empty
     * @param array $shema
     * @return array
     */
    public function custom_shema($shema){
	if(!empty($this->settings['container_shema'])){
	    $shema['container']=$this->settings['container_shema'];
	}
	if(!empty($this->settings['item_shema'])){
	    $shema['item']=$this->settings['item_shema'];
	}
	return $shema;
    }

    /**
     * Parse the maps.json file. Custom maps can be added by using the `eventpost_getsettings` filter like the following example:
     *
     * ```
     * <?php
     *  add_filter('eventpost_getsettings', 'map_function');
     *  function map_function($maps){
     *    array_push($maps, array(
     *        'name'=>'Myt custom map',
     *        'id'=>'custom_map',
     *        'urls'=>array(
     *          'http://a.customurl.org/{z}/{x}/{y}.png',
     *          'http://b.customurl.org/{z}/{x}/{y}.png',
     *          'http://c.customurl.org/{z}/{x}/{y}.png',
     *        )
     *    ));
     *    return $maps;
     *  }
     *  ?>
     * ```
     *
     * @filter eventpost_maps
     * @return array of map arrays ['name', 'id', 'urls']
     */
    public function get_maps() {
        $maps = array();
        $filename = plugin_dir_path(__FILE__) . 'maps.json';
        if (is_file($filename) && (false !== $json = json_decode(file_get_contents($filename)))) {
            // Convert objects to array to ensure retrocompatibility
            $arrays = array();
            foreach($json as $map){
                $arrays[$map->id] = (array) $map;
            }
            $maps = apply_filters('eventpost_maps', $arrays);
        }
        return $maps;
    }

    /**
     *
     * @return array
     */
    public function get_colors() {
        $colors = array();
        if (is_dir($this->markpath)) {
            $files = scandir($this->markpath);
            foreach ($files as $file) {
                if (substr($file, -4) == '.png') {
                    $colors[substr($file, 0, -4)] = $this->markurl . $file;
                }
            }
        }
        return $colors;
    }

    /**
     *
     * @param string $color
     * @return sring
     */
    public function get_marker($color) {
        if (is_file($this->markpath . $color . '.png')) {
            return $this->markurl . $color . '.png';
        }
        return plugins_url('/markers/ffffff.png', __FILE__);
    }

    /**
     * Enqueue CSS files
     */
    public function load_styles() {
        //CSS
        wp_register_style('event-post', plugins_url('/css/eventpost.min.css', __FILE__), false,  $this->version);
        wp_enqueue_style('event-post', plugins_url('/css/eventpost.min.css', __FILE__), false,  $this->version);
        wp_enqueue_style('openlayers', plugins_url('/css/openlayers.css', __FILE__), false,  $this->version);
        wp_enqueue_style('dashicons', includes_url('/css/dashicons.min.css'));
    }
    /**
     * Enqueue Editor style
     */
    public function editor_styles() {
        add_editor_style( plugins_url('/css/eventpost.min.css', __FILE__) );
    }

    /**
     * Enqueue JS files
     */
    public function load_scripts($deps = array('jquery')) {
        // JS
        wp_enqueue_script('jquery', false, false, false, true);
        wp_enqueue_script('event-post', plugins_url('/js/eventpost.min.js', __FILE__), $deps, $this->version, true);
        wp_localize_script('event-post', 'eventpost_params', array(
            'imgpath' => plugins_url('/img/', __FILE__),
            'maptiles' => $this->maps,
            'defaulttile' => $this->settings['tile'],
            'zoom' => $this->settings['zoom'],
            'ajaxurl' => admin_url() . 'admin-ajax.php',
            'map_interactions'=>$this->map_interactions,
        ));
    }
    /**
     * Enqueue JS files for maps
     */
    public function load_map_scripts() {
        // JS
        wp_enqueue_script('openlayers', plugins_url('/js/OpenLayers.js', __FILE__), false,  $this->version, true);
        $this->load_scripts(array('jquery', 'openlayers'));
        if(is_admin()){
            $this->admin_scripts(array('jquery', 'openlayers'));
        }
    }

    /**
     * Enqueue CSS files in admin
     */
    public function admin_head() {
        $page = basename($_SERVER['SCRIPT_NAME']);
        if( $page!='post-new.php' && !($page=='post.php' && filter_input(INPUT_GET, 'action')=='edit') && !($page=='options-general.php' && filter_input(INPUT_GET, 'page')=='event-settings') ){
            return;
        }
        wp_enqueue_style('openlayers', plugins_url('/css/openlayers.css', __FILE__), false,  $this->version);
        wp_enqueue_style('jquery-ui', plugins_url('/css/jquery-ui.css', __FILE__), false,  $this->version);
        wp_enqueue_style('event-post-admin', plugins_url('/css/eventpost-admin.css', __FILE__), false,  $this->version);
    }

    /**
     * Enqueue JS files in admin
     */
    public function admin_scripts($deps = array('jquery')) {
        $page = basename($_SERVER['SCRIPT_NAME']);
        if( $page!='post-new.php' && !($page=='post.php' && filter_input(INPUT_GET, 'action')=='edit') && !($page=='options-general.php' && filter_input(INPUT_GET, 'page')=='event-settings') ){
            return;
        }
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-effects-core');
        wp_enqueue_script('jquery-effects-shake');
        if(!is_array($deps)){
            $deps = array('jquery');
        }
        if($this->settings['datepicker']=='simple' || (isset($_GET['page']) && $_GET['page']=='event-settings')){
            wp_enqueue_script('jquery-ui-datepicker');
            $deps[] = 'jquery-ui-datepicker';
        }
        wp_enqueue_script('event-post-admin', plugins_url('/js/eventpost-admin.min.js', __FILE__), $deps,  $this->version, true);
        $language = get_bloginfo('language');
        if (strpos($language, '-') > -1) {
            $language = strtolower(substr($language, 0, 2));
        }
        wp_localize_script('event-post-admin', 'eventpost', array(
            'imgpath' => plugins_url('/img/', __FILE__),
            'date_choose' => '<span class="screen-reader-text">'.__('Choose', 'event-post').'</span><i class="dashicons dashicons-calendar-alt"></i>',
            'date_format' => __('yy-mm-dd', 'event-post'),
            'more_icons' => __('More icons', 'event-post'),
	    'pick_a_date'=>__('Pick a date','event-post'),
	    'use_current_location'=>__('Use my current location','event-post'),
	    'start_drag'=>__('Click to<br>drag the map<br>and change location','event-post'),
            'empty_address'=>__('Be kind to fill a non empty address :)', 'event-post'),
            'search'=>__('Type an address', 'event-post'),
	    'stop_drag'=>_x('Done','Stop allowing to drag the map', 'event-post'),
            'datepickeri18n'=>array(
                'order'=>__( '%1$s %2$s, %3$s @ %4$s:%5$s', 'event-post'),
                'day'=>__('Day', 'event-post'),
                'month'=>__('Month', 'event-post'),
                'year'=>__('Day', 'event-post'),
                'hour'=>__('Hour', 'event-post'),
                'minute'=>__('Minute', 'event-post'),
                'ok'=>__('OK', 'event-post'),
                'cancel'=>__('Cancel', 'event-post'),
                'edit'=>__('Edit', 'event-post'),
                'months'=>$this->NomDuMois,
            ),
            'META_START' => $this->META_START,
            'META_END' => $this->META_END,
            'META_ADD' => $this->META_ADD,
            'META_LAT' => $this->META_LAT,
            'META_LONG' => $this->META_LONG,
            'lang'=>$language,
            'maptiles' => $this->maps,
            'defaulttile' => $this->settings['tile']
        ));
    }

    /**
     * Add custom header meta for single events
     */
    public function single_header() {
        if (is_single()) {
	    $event = $this->retreive();
            if ($event->address != '' || ($event->lat != '' && $event->long != '')) {
                ?>
<meta name="geo.placename" content="<?php echo $event->address ?>" />
<meta name="geo.position" content="<?php echo $event->lat ?>;<?php echo $event->long ?>" />
<meta name="ICBM" content="<?php echo $event->lat ?>;<?php echo $event->long ?>" />
<meta property="place:location:latitude"  content="<?php echo $event->lat ?>" />
<meta property="place:location:longitude" content="<?php echo $event->long ?>" />
<?php
            }
            if ($event->start != '' && $event->end != '') {
                ?>
<meta name="datetime-coverage-start" content="<?php echo date('c', $event->time_start) ?>" />
<meta name="datetime-coverage-end" content="<?php echo date('c', $event->time_end) ?>" />
<?php
            }
        }
    }

    /**
     *
     * @param string $str
     * @return boolean
     */
    public function dateisvalid($str) {
        return is_string($str) && trim(str_replace(array(':', '0'), '', $str)) != '';
    }

    /**
     *
     * @param string $date
     * @param string $sep
     * @return string
     */
    public function parsedate($date, $sep = '') {
        if (!empty($date)) {
            return substr($date, 0, 10) . $sep . substr($date, 11, 8);
        } else {
            return '';
        }
    }

    /**
     *
     * @param mixed $date
     * @param string $format
     * @return type
     */
    public function human_date($date, $format = 'l j F Y') {
        if($this->settings['dateforhumans']){
            if (is_numeric($date) && date('d/m/Y', $date) == date('d/m/Y')) {
                return __('today', 'event-post');
            } elseif (is_numeric($date) && date('d/m/Y', $date) == date('d/m/Y', strtotime('+1 day'))) {
                return __('tomorrow', 'event-post');
            } elseif (is_numeric($date) && date('d/m/Y', $date) == date('d/m/Y', strtotime('-1 day'))) {
                return __('yesterday', 'event-post');
            }
        }
        return date_i18n($format, $date);
    }

    /**
     *
     * @param timestamp $time_start
     * @param timestamp $time_end
     * @return string
     */
    public function delta_date($time_start, $time_end){
        if(!$time_start || !$time_end){
            return;
        }

        //Display dates
        $dates="\t\t\t\t".'<div class="event_date" data-start="' . $this->human_date($time_start) . '" data-end="' . $this->human_date($time_end) . '">';
        if (date('d/m/Y', $time_start) == date('d/m/Y', $time_end)) { // same day
            $dates.= "\n\t\t\t\t\t\t\t".'<time itemprop="dtstart" datetime="' . date_i18n('c', $time_start) . '">'
                    . '<span class="date date-single">' . $this->human_date($time_end, $this->settings['dateformat']) . "</span>";
            if (date('H:i', $time_start) != date('H:i', $time_end) && date('H:i', $time_start) != '00:00' && date('H:i', $time_end) != '00:00') {
                $dates.='   <span class="linking_word linking_word-from">' . __('from', 'event-post') . '</span>
                            <span class="time time-start">' . date_i18n($this->settings['timeformat'], $time_start) . '</span>
                            <span class="linking_word linking_word-t">' . __('to', 'event-post') . '</span>
                            <span class="time time-end">' . date_i18n($this->settings['timeformat'], $time_end) . '</span>';
            }
            elseif (date('H:i', $time_start) != '00:00') {
                $dates.='   <span class="linking_word">' . __('at', 'event-post') . '</span>
                            <time class="time time-single" itemprop="dtstart" datetime="' . date_i18n('c', $time_start) . '">' . date_i18n($this->settings['timeformat'], $time_start) . '</time>';
            }
            $dates.="\n\t\t\t\t\t\t\t".'</time>';
        } else { // not same day
            $dates.= '
                <span class="linking_word linking_word-from">' . __('from', 'event-post') . '</span>
                <time class="date date-start" itemprop="dtstart" datetime="' . date('c', $time_start) . '">' . $this->human_date($time_start, $this->settings['dateformat']);
            if (date('H:i:s', $time_start) != '00:00:00' || date('H:i:s', $time_end) != '00:00:00'){
              $dates.= ', ' . date_i18n($this->settings['timeformat'], $time_start);
            }
            $dates.='</time>
                <span class="linking_word linking_word-to">' . __('to', 'event-post') . '</span>
                <time class="date date-end" itemprop="dtend" datetime="' . date('c', $time_end) . '">' . $this->human_date($time_end, $this->settings['dateformat']);
            if (date('H:i:s', $time_start) != '00:00:00' || date('H:i:s', $time_end) != '00:00:00') {
              $dates.=  ', ' . date_i18n($this->settings['timeformat'], $time_end);
            }
            $dates.='</time>';
        }
        $dates.="\n\t\t\t\t\t\t".'</div><!-- .event_date -->';
        return $dates;
    }

    /**
     *
     * @param WP_Post object $post
     * @param mixed $links
     * @return string
     */
    public function print_date($post = null, $links = 'deprecated', $context='') {
        $dates = '';
        $event = $this->retreive($post);
        if ($event->start != '' && $event->end != '') {

            $dates.=$this->delta_date($event->time_start, $event->time_end);

            $timezone_string = get_option('timezone_string');
            $gmt_offset = $gmt = $this->get_gmt_offset();

            if (!is_admin() && $event->time_end > current_time('timestamp') && (
                    $this->settings['export'] == 'both' ||
                    ($this->settings['export'] == 'single' && is_single() ) ||
                    ($this->settings['export'] == 'list' && !is_single() )
                    )) {

                // Export event
                $title = urlencode($post->post_title);
                $address = urlencode($post->address);
                $url = urlencode($post->guid);
                $allday = ($post->time_start && $post->time_end && date('H:i:s', $post->time_start) == '00:00:00' && date('H:i:s', $post->time_end) == '00:00:00');
                $d_s = date("Ymd", $event->time_start) . ($allday ? ''  : 'T' . date("His", $event->time_start));
                $d_e = date("Ymd", $event->time_end) . ($allday ? ''  : 'T' . date("His", $event->time_end));
                $uid = $post->ID . '-' . $post->blog_id;

                // format de date ICS
                $ics_url = add_query_arg(array(
                    't'=>$title,
                    'u'=>$uid,
                    'sd'=>$d_s,
                    'ed'=>$d_e,
                    'a'=>$address,
                    'd'=>$url,
                    'tz'=>$timezone_string,
                    'gmt'=>urlencode($gmt_offset)
                ), plugins_url('export/ics.php', __FILE__));

                // format de date Google cal
                //$google_url = 'https://www.google.com/calendar/event?action=TEMPLATE&amp;text=' . $title . '&amp;dates=' . $d_s . 'Z/' . $d_e . 'Z&amp;details=' . $url . '&amp;ctz='.$timezone_string.'&amp;location=' . $address . '&amp;trp=false&amp;sprop=&amp;sprop=name';
                $google_url = add_query_arg(array(
                    'action'=>'TEMPLATE',
                    'trp'=>'false',
                    'sprop'=>'name',
                    'text'=>$title,
                    'dates'=>$d_s.'Z/'.$d_e.'Z',
                    'location'=>$address,
                    'details'=>$url,
                    'ctz'=>$timezone_string,
                    'gmt'=> urlencode($gmt_offset)
                ), 'https://www.google.com/calendar/event');

                // format de date VCS
                $vcs_url = add_query_arg(array(
                    't'=>$title,
                    'u'=>$uid,
                    'sd'=>$d_s,
                    'ed'=>$d_e,
                    'a'=>$address,
                    'd'=>$url,
                    'tz'=>$timezone_string,
                    'gmt'=>$gmt_offset
                ), plugins_url('export/vcs.php', __FILE__));
                $dates.='
                        <span class="eventpost-date-export">
                            <a href="' . $ics_url . '" class="event_link ics" target="_blank" title="' . __('Download ICS file', 'event-post') . '">ical</a>
                            <a href="' . $google_url . '" class="event_link gcal" target="_blank" title="' . __('Add to Google calendar', 'event-post') . '">Google</a>
                            <a href="' . $vcs_url . '" class="event_link vcs" target="_blank" title="' . __('Add to Outlook', 'event-post') . '">outlook</a>
                            <i class="dashicons-before dashicons-calendar"></i>
                        </span>';
            }
        }
        return apply_filters('eventpost_printdate', $dates);
    }

    /**
     *
     * @param WP_Post object $post
     * @return string
     */
    public function print_location($post=null, $context='') {
        $location = '';
        if ($post == null)
            $post = get_post();
        elseif (is_numeric($post)) {
            $post = get_post($post);
        }
        if (!isset($post->start)) {
            $post = $this->retreive($post);
        }
        $address = $post->address;
        $lat = $post->lat;
        $long = $post->long;
        $color = $post->color;

        if ($address != '' || ($lat != '' && $long != '')) {
            $location.="\t\t\t\t".'<address';
            if ($lat != '' && $long != '') {
                $location.=' data-id="' . $post->ID . '" data-latitude="' . $lat . '" data-longitude="' . $long . '" data-marker="' . $this->get_marker($color) . '" ';
            }
            $location.=' itemprop="adr">'
                    . "\n\t\t\t\t\t\t\t".'<span>'
                    . "\n".$address
                    . "\n\t\t\t\t\t\t\t". '</span>';
            if ($context=='single' && $lat != '' && $long != '') {
                $location.="\n\t\t\t\t\t\t\t".'<a class="event_link gps dashicons-before dashicons-location-alt" href="https://www.openstreetmap.org/?lat=' . $lat.='&amp;lon=' . $long.='&amp;zoom=13" target="_blank"  itemprop="geo">' . __('Map', 'event-post') . '</a>';
            }
            $location.="\n\t\t\t\t\t\t".'</address>';
        }

        return apply_filters('eventpost_printlocation', $location);
    }

    /**
     *
     * @param WP_Post object $post
     * @return string
     */
    public function print_categories($post=null, $context='') {
        if ($post == null)
            $post = get_post();
        elseif (is_numeric($post)) {
            $post = get_post($post);
        }
        if (!isset($post->start)) {
            $post = $this->retreive($post);
        }
        $cats = '';
        $categories = $post->categories;
        if ($categories) {
            $cats.="\t\t\t\t".'<span class="event_category"';
            $color = $post->color;
            if ($color != '') {
                $cats.=' style="color:#' . $color . '"';
            }
            $cats.='>';
            foreach ($categories as $category) {
                $cats .= $category->name . ' ';
            }
            $cats.='</span>';
        }
        return $cats;
    }

    /**
     * Generate, return or output date event datas
     * @param WP_Post object $post
     * @param string $class
     * @filter eventpost_get_single
     * @return string
     */
    public function get_single($post = null, $class = '', $context='') {
        if ($post == null) {
            $post = $this->retreive();
        }
        $datas_date = $this->print_date($post, null, $context);
        $datas_cat = $this->print_categories($post, $context);
        $datas_loc = $this->print_location($post, $context);
        if ($datas_date != '' || $datas_loc != '') {
            $rgb = $this->hex2dec($post->color);
            return '<div class="event_data ' . $class . '" style="border-left-color:#' . $post->color . ';background:rgba(' . $rgb['R'] . ',' . $rgb['G'] . ',' . $rgb['B'] . ',0.1)" itemscope itemtype="http://microformats.org/profile/hcard">'
                    . apply_filters('eventpost_get_single', $datas_date . $datas_cat . $datas_loc, $post)
                    . '</div>';
        }
        return '';
    }

    /**
     * Displays dates of a gieven post
     * @param WP_Post object $post
     * @param string $class
     * @return string
     */
    public function get_singledate($post = null, $class = '', $context='') {
        return '<div class="event_data event_date ' . $class . '" itemscope itemtype="http://microformats.org/profile/hcard">' . "\n\t\t".$this->print_date($post, null, $context) . "\n\t\t\t\t\t".'</div><!-- .event_date -->';
    }

    /**
     * Displays coloured terms of a given post
     * @param WP_Post object $post
     * @param string $class
     * @return string
     */
    public function get_singlecat($post = null, $class = '', $context='') {
        return '<div class="event_data event_category ' . $class . '" itemscope itemtype="http://microformats.org/profile/hcard">' . "\n\t\t".$this->print_categories($post, $context) . "\n\t\t\t\t\t".'</div><!-- .event_category -->';
    }

    /**
     * Displays location of a given post
     * @param WP_Post object $post
     * @param string $class
     * @return string
     */
    public function get_singleloc($post = null, $class = '', $context='') {
        return '<div class="event_data event_location ' . $class . '" itemscope itemtype="http://microformats.org/profile/hcard">' . "\n\t\t".$this->print_location($post, $context) . "\n\t\t\t\t\t".'</div><!-- .event_location -->';
    }

    /**
     * Uses `the_content` filter to add event details before or after the content of the current post
     * @param string $content
     * @return string
     */
    public function display_single($content) {
        if (is_page() || !is_single() || is_home() || !in_the_loop() || !is_main_query()){
            return $content;
        }

        $post = $this->retreive();
        $eventbar = apply_filters('eventpost_contentbar', $this->get_single($post, 'event_single', 'single'), $post);
        if($this->settings['singlepos']=='before'){
            $content=$eventbar.$content;
        }
        elseif($this->settings['singlepos']=='after'){
            $content.=$eventbar;
        }
        $this->load_map_scripts();
        return $content;
    }

    /**
     * Outputs events details (dates, geoloc, terms) of given post
     * @param WP_Post object $post
     * @echoes string
     * @return void
     */
    public function print_single($post = null) {
        echo $this->get_single($post);
    }

    /**
     * Alter the post title in order to add icons if needed
     * @param string $title
     * @return string
     */
    public function the_title($title, $post_id){
	if(!in_the_loop() || !$this->settings['loopicons']){
	    return $title;
	}
        $icons_ = array(
            // Emojis
            1=>array('🗓', '🗺'),
            // Dashicons
            2=>array('<span class="dashicons dashicons-calendar"></span>', '<span class="dashicons dashicons-location"></span>'),
        );

	$event = $this->retreive($post_id);
	if(!empty($event->start)){
	   $title .= ' '.$icons_[$this->settings['loopicons']][0];
	}
	if(!empty($event->lat) && !empty($event->long)){
	   $title .= ' '.$icons_[$this->settings['loopicons']][1];
	}
	return $title;
    }


    /**
     * Return an HTML list of events
     *
     * @filter eventpost_params($defaults, 'list_events')
     * @filter eventpost_listevents
     * @filter eventpost_item_scheme_entities
     * @filter eventpost_item_scheme_values
     *
     * @param array $atts
     * @param string $id
     * @param string $context
     * @return string
     */
    public function list_events($atts, $id = 'event_list', $context='') {
	$ep_settings = $this->settings;
        $defaults = array(
            'nb' => 0,
            'type' => 'div',
            'future' => true,
            'past' => false,
            'geo' => 0,
            'width' => '',
            'height' => '',
            'zoom' => '',
            'tile' => $ep_settings['tile'],
            'title' => '',
            'before_title' => '<h3>',
            'after_title' => '</h3>',
            'cat' => '',
            'tag' => '',
            'events' => '',
            'style' => '',
            'thumbnail' => '',
            'thumbnail_size' => '',
            'excerpt' => '',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'class' => '',
            'container_schema' => $this->list_shema['container'],
            'item_schema' => $this->list_shema['item'],
                        );
        // Map UI options
        foreach($this->map_interactions as $int_key=>$int_name){
            $defaults[$int_key]=true;
        }
        $atts = shortcode_atts(apply_filters('eventpost_params', $defaults, 'list_events'), $atts);

        extract($atts);
        if (!is_array($events)) {
            $events = $this->get_events($atts);
        }
        $ret = '';
        $this->list_id++;
        if (sizeof($events) > 0) {
            if (!empty($title)) {
                $ret.= html_entity_decode($before_title) . $title . html_entity_decode($after_title);
            }

            $child = ($type == 'ol' || $type == 'ul') ? 'li' : 'div';

            $list = '';

	    if($id=='event_geolist'){
		$this->load_map_scripts();
                $list.=sprintf('<%1$s class="event_geolist_icon_loader"><p><span class="dashicons dashicons-location-alt"></span></p><p class="screen-reader-text">'.__('An events map', 'event-post').'</p></%1$s>', $type);
	    }

            foreach ($events as $event) {
                $class_item = ($event->time_end >= current_time('timestamp')) ? 'event_future' : 'event_past';
                if ($ep_settings['emptylink'] == 0 && empty($event->post_content)) {
                    $event->permalink = '#' . $id . $this->list_id;
                }
                elseif(empty($ob->permalink)){
                    $event->permalink=$event->guid;
                }
                $list.=str_replace(
                        apply_filters('eventpost_item_scheme_entities', array(
                    '%child%',
                    '%class%',
                    '%color%',
                    '%event_link%',
                    '%event_thumbnail%',
                    '%event_title%',
                    '%event_date%',
                    '%event_cat%',
                    '%event_location%',
                    '%event_excerpt%'
                        )), apply_filters('eventpost_item_scheme_values', array(
                    $child,
                    $class_item,
                    $event->color,
                    $event->permalink,
                    $thumbnail == true ? '<span class="event_thumbnail_wrap">' . get_the_post_thumbnail($event->root_ID, !empty($thumbnail_size) ? $thumbnail_size : 'thumbnail', array('class' => 'attachment-thumbnail wp-post-image event_thumbnail')) . '</span>' : '',
                    $event->post_title,
                    $this->get_singledate($event, '', $context),
                    $this->get_singlecat($event, '', $context),
                    $this->get_singleloc($event, '', $context),
                    $excerpt == true && $event->post_excerpt!='' ? '<span class="event_exerpt">'.$event->post_excerpt.'</span>' : '',
                    ), $event), $item_schema
                );
            }
            $attributes = '';
            if($id == 'event_geolist'){
                $attributes = 'data-tile="'.$tile.'" data-width="'.$width.'" data-height="'.$height.'" data-zoom="'.$zoom.'" data-disabled-interactions="';
                foreach($this->map_interactions as $int_key=>$int_name){
                    $attributes.=$atts[$int_key]==false ? $int_key.', ' : '';
                }
                $attributes.='"';
            }
            $ret.=str_replace(
                    array(
                '%type%',
                '%id%',
                '%class%',
                '%listid%',
                '%style%',
                '%attributes%',
                '%list%'
                    ), array(
                $type,
                $id,
                $class,
                $id . $this->list_id,
                (!empty($width) ? 'width:' . $width . ';' : '') . (!empty($height) ? 'height:' . $height . ';' : '') . $style,
                $attributes,
                $list
                    ), $container_schema
            );
        }
        elseif(filter_input(INPUT_POST, 'action')=='bulk_do_shortcode'){
            return '<div class="event_geolist_icon_loader"><p><span class="dashicons dashicons-calendar"></span></p><p class="screen-reader-text">'.__('An empty list of events', 'event-post').'</p></div>';
        }
        return apply_filters('eventpost_listevents', $ret, $id.$this->list_id, $atts, $events, $context);
    }

    /**
     * get_events
     * @param array $atts
     * @filter eventpost_params
     * @filter eventpost_get_items
     * @return array of post_ids wich are events
     */
    public function get_events($atts) {
        $requete = (shortcode_atts(apply_filters('eventpost_params', array(
                    'nb' => 5,
                    'future' => true,
                    'past' => false,
                    'geo' => 0,
                    'cat' => '',
                    'tag' => '',
                    'date' => '',
                    'orderby' => 'meta_value',
                    'orderbykey' => $this->META_START,
                    'order' => 'ASC',
                    'tax_name' => '',
                    'tax_term' => '',
                    'post_type'=> $this->settings['posttypes']
                    ), 'get_events'), $atts));
        extract($requete);
        wp_reset_query();

        $arg = array(
            'post_type' => $post_type,
            'posts_per_page' => $nb,
            'meta_key' => $orderbykey,
            'orderby' => $orderby,
            'order' => $order
        );

        if($tax_name=='category'){
            $tax_name='';
            $cat=$tax_term;
        }
        elseif($tax_name=='post-tag'){
            $tax_name='';
            $tag=$tax_term;
        }

        // CUSTOM TAXONOMY
        if ($tax_name != '' && $tax_term != '') {
            $arg[$tax_name] = $tax_term;
        }
        // CAT
        if ($cat != '') {
            if (preg_match('/[a-zA-Z]/i', $cat)) {
                $arg['category_name'] = $cat;
            } else {
                $arg['cat'] = $cat;
            }
        }
        // TAG
        if ($tag != '') {
            $arg['tag'] = $tag;
        }
        // DATES
        $meta_query = array(
            array(
                'key' => $this->META_END,
                'value' => '',
                'compare' => '!='
            ),
            array(
                'key' => $this->META_END,
                'value' => '0:0:00 0:',
                'compare' => '!='
            ),
            array(
                'key' => $this->META_END,
                'value' => ':00',
                'compare' => '!='
            ),
            array(
                'key' => $this->META_START,
                'value' => '',
                'compare' => '!='
            ),
            array(
                'key' => $this->META_START,
                'value' => '0:0:00 0:',
                'compare' => '!='
            )
        );
        if ($future == 0 && $past == 0) {
            $meta_query = array();
            $arg['meta_key'] = null;
            $arg['orderby'] = null;
            $arg['order'] = null;
        } elseif ($future == 1 && $past == 0) {
            $meta_query[] = array(
                'key' => $this->META_END,
                'value' => current_time('mysql'),
                'compare' => '>=',
                    //'type'=>'DATETIME'
            );
        } elseif ($future == 0 && $past == 1) {
            $meta_query[] = array(
                'key' => $this->META_END,
                'value' => current_time('mysql'),
                'compare' => '<=',
                    //'type'=>'DATETIME'
            );
        }
        if ($date != '') {
            $date = date('Y-m-d', $date);

            $meta_query = array(
                array(
                    'key' => $this->META_END,
                    'value' => $date . ' 00:00:00',
                    'compare' => '>=',
                    'type' => 'DATETIME'
                ),
                array(
                    'key' => $this->META_START,
                    'value' => $date . ' 23:59:59',
                    'compare' => '<=',
                    'type' => 'DATETIME'
                )
            );
        }
        // GEO
        if ($geo == 1) {
            $meta_query[] = array(
                'key' => $this->META_LAT,
                'value' => '',
                'compare' => '!='
            );
            $meta_query[] = array(
                'key' => $this->META_LONG,
                'value' => '',
                'compare' => '!='
            );
            $arg['meta_key'] = $this->META_LAT;
            $arg['orderby'] = 'meta_value';
            $arg['order'] = 'DESC';
        }

        $arg['meta_query'] = $meta_query;

        $query_md5 = 'eventpost_' . md5(var_export($requete, true));
        // Check if cache is activated
        if ($this->settings['cache'] == 1 && false !== ( $cached_events = get_transient($query_md5) )) {
            return apply_filters('eventpost_get_items', is_array($cached_events) ? $cached_events : array(), $requete, $arg);
        }

        $events = apply_filters('eventpost_get', '', $requete, $arg);
        if ('' === $events) {
            global $wpdb;
            $query = new WP_Query($arg);
            $events = $wpdb->get_col($query->request);
            foreach ($events as $k => $post) {
		$event = $this->retreive($post);
                $events[$k] = $event;
            }
        }
        if ($this->settings['cache'] == 1){
            set_transient($query_md5, $events, 5 * MINUTE_IN_SECONDS);
	}
        return apply_filters('eventpost_get_items', $events, $requete, $arg);
    }

    /**
     *
     * @param object $event
     * @return object
     */
    public function retreive($event = null) {
        global $EventPost_cache;
	$ob = get_post($event);
        if($ob->start){
            return $ob;
        }
        if(isset($EventPost_cache[$ob->ID])){
            return $EventPost_cache[$ob->ID];
        }
        $ob->start = get_post_meta($ob->ID, $this->META_START, true);
        $ob->end = get_post_meta($ob->ID, $this->META_END, true);
        if (!$this->dateisvalid($ob->start)){
            $ob->start = '';
	}
        if (!$this->dateisvalid($ob->end)){
            $ob->end = '';
        }
        $ob->root_ID = $ob->ID;
        $ob->time_start = !empty($ob->start) ? strtotime($ob->start) : '';
        $ob->time_end = !empty($ob->end) ? strtotime($ob->end) : '';
        $ob->address = get_post_meta($ob->ID, $this->META_ADD, true);
        $ob->lat = get_post_meta($ob->ID, $this->META_LAT, true);
        $ob->long = get_post_meta($ob->ID, $this->META_LONG, true);
        $ob->color = get_post_meta($ob->ID, $this->META_COLOR, true);
        $ob->categories = get_the_category($ob->ID);
        $ob->permalink = get_permalink($ob->ID);
        $ob->blog_id = get_current_blog_id();
        if ($ob->color == ''){
            $ob->color = '000000';
	}

        $EventPost_cache[$ob->ID] = apply_filters('eventpost_retreive', $ob);
        return $EventPost_cache[$ob->ID];
    }

    /**
     *
     * @param mixte $_term
     * @param string $taxonomy
     * @param string $post_type
     */
    public function retreive_term($_term=null, $taxonomy='category', $post_type='post') {
        $term = get_term($_term, $taxonomy);

        if(!$term){
            return $term;
        }

        $term->start = $term->end = $term->time_start = $term->time_end = Null;

        $request = array(
            'post_type'=>$post_type,
            'tax_name'=>$term->taxonomy,
            'tax_term'=>$term->slug,
            'future'=>true,
            'past'=>true,
            'nb'=>-1,
            'order'=>'ASC'
        );

        $events = $this->get_events($request);

        $term->events_count = count($events);
        if($term->events_count){
            $term->start = $events[0]->start;
            $term->time_start = $events[0]->time_start;
            $term->end = $events[$term->events_count-1]->end;
            $term->time_end = $events[$term->events_count-1]->time_end;
        }

        $request['order']='DESC';
        $request['nb']=1;
        $request['orderbykey']=$this->META_END;
        $events = $this->get_events($request);
        if(count($events)){
            $term->end = $events[0]->end;
            $term->time_end = $events[0]->time_end;
        }

        return $term;

    }

    /** ADMIN ISSUES * */

    /**
     * add custom boxes in posts edit page
     */
    public function add_custom_box() {
        foreach($this->settings['posttypes'] as $posttype){
            add_meta_box('event_post_date', __('Event date', 'event-post'), array(&$this, 'inner_custom_box_date'), $posttype, apply_filters('eventpost_add_custom_box_position', $this->settings['adminpos'], $posttype), 'core');
            add_meta_box('event_post_loc', __('Location', 'event-post'), array(&$this, 'inner_custom_box_loc'), $posttype, apply_filters('eventpost_add_custom_box_position', $this->settings['adminpos'], $posttype), 'core');
            do_action('eventpost_add_custom_box', $posttype);
        }
        if(!function_exists('shortcode_ui_register_for_shortcode')){
	    add_meta_box('event_post_sc_edit', __('Events Shortcode editor', 'event-post'), array(&$this, 'inner_custom_box_edit'), 'page');
	}
    }
    /**
     * display the date custom box
     */
    public function inner_custom_box_date() {
        wp_nonce_field(plugin_basename(__FILE__), 'eventpost_nonce');
        $post_id = get_the_ID();
        $event = $this->retreive($post_id);
        $start_date = $event->start;
        $end_date = $event->end;
        $eventcolor = $event->color;

        $language = get_bloginfo('language');
        if (strpos($language, '-') > -1) {
            $language = strtolower(substr($language, 0, 2));
        }
        $colors = $this->get_colors();
        ?>

        <div class="eventpost-misc-pub-section">
            <p>
                <label>
                    <input type="checkbox" id="event-post-date-all-day" <?php checked( $event->time_start && $event->time_end && date('H:i:s', $event->time_start) == '00:00:00' && date('H:i:s', $event->time_end) == '00:00:00', true, true); ?>>
                    <?php _e('All day event', 'event-post') ?>
                </label>
            </p>
            <p>
                <span class="dashicons dashicons-calendar eventpost-edit-icon"></span>
                <label for="<?php echo $this->META_START; ?>_date">
                        <?php _e('Begin:', 'event-post') ?>
                        <span id="<?php echo $this->META_START; ?>_date_human" class="human_date">
                                <?php
                            if ($event->time_start != '') {
                                    echo $this->human_date($event->time_start) . (date('H:i', $event->time_start)=='00:00'?'':date(' H:i', $event->time_start));
                                }
                                else{
                                    _e('Pick a date','event-post');
                                }
                                ?>
                            </span>
                    <input type="<?php echo ($this->settings['datepicker']=='browser'?'datetime':''); ?>" class="eventpost-datepicker-<?php echo $this->settings['datepicker']; ?>" data-lang="<?php echo $language; ?>" value="<?php echo substr($start_date,0,16) ?>" name="<?php echo $this->META_START; ?>" id="<?php echo $this->META_START; ?>_date"/>
                </label>
            </p>
            <p>
                <span class="dashicons dashicons-calendar eventpost-edit-icon"></span>
                <label for="<?php echo $this->META_END; ?>_date">
                        <?php _e('End:', 'event-post') ?>
                        <span id="<?php echo $this->META_END; ?>_date_human" class="human_date">
                                <?php
                                if ($event->time_start != '') {
                                    echo $this->human_date($event->time_end) . (date('H:i', $event->time_end)=='00:00'?'':date(' H:i', $event->time_end));
                                }
                                else{
                                    _e('Pick a date','event-post');
                                }
                                ?>
                            </span>
                    <input type="<?php echo ($this->settings['datepicker']=='browser'?'datetime':''); ?>" class="eventpost-datepicker-<?php echo $this->settings['datepicker']; ?>" data-lang="<?php echo $language; ?>"  value ="<?php echo substr($end_date,0,16) ?>" name="<?php echo $this->META_END; ?>" id="<?php echo $this->META_END; ?>_date"/>
                </label>
            </p>
            </div>
        <?php if (sizeof($colors) > 0): ?>
            <div class="eventpost-misc-pub-section event-color-section">
                <span class="screen-reader-text"><?php _e('Color:', 'event-post'); ?></span><?php echo $key_color; ?>
                <p>
                    <img src="<?php echo $this->markurl.$eventcolor.'.png'; ?>" id="eventpost-color-preview" data-url="<?php echo $this->markurl; ?>">
                    <span id="eventpost-color-dropdown">
            <?php foreach ($colors as $color => $file): ?>
                        <label style="background:#<?php echo $color ?>" for="<?php echo $this->META_COLOR; ?><?php echo $color ?>" title="<?php echo $file; ?>">
                            <img src="<?php echo $this->markurl.$color.'.png'; ?>">
                            <input type="radio" value ="<?php echo $color ?>" name="<?php echo $this->META_COLOR; ?>" id="<?php echo $this->META_COLOR; ?><?php echo $color ?>" <?php checked($eventcolor, $color, true); ?>/>
                        </label>
            <?php endforeach; ?>
                    </span>
                </p>
            </div>
        <?php endif; ?>
        <?php
        do_action ('eventpost_custom_box_date', $event);
    }
    /**
     * displays the location custom box
     */
    public function inner_custom_box_loc($post) {
        $event = $this->retreive($post);
        ?>

        <div class="eventpost-misc-pub-section">
            <label for="<?php echo $this->META_ADD; ?>">
        <?php _e('Address, as it will be displayed:', 'event-post') ?>
                <textarea name="<?php echo $this->META_ADD; ?>" id="<?php echo $this->META_ADD; ?>" class="widefat"><?php echo $event->address; ?></textarea>
            </label>
        </div>

        <div id="event_address_searchwrap">
            <span class="dashicons dashicons-location eventpost-edit-icon"></span>
            <?php _e('GPS coordinates:', 'event-post') ?>
            <a id="event_address_search" title="<?php _e('Search or fill exact coordinates', 'event-post') ?>">
                <?php _e('Search / Edit', 'event-post') ?>
            </a>

            <div class="misc-pub-section" id="event_address_coords">
                <p>
                    <span id="eventaddress_result"></span>
                </p>
                <label for="<?php echo $this->META_LAT; ?>">
            <?php _e('Latitude:', 'event-post') ?>
                    <input type="text" value ="<?php echo $event->lat; ?>" name="<?php echo $this->META_LAT; ?>" id="<?php echo $this->META_LAT; ?>" class="widefat"/>
                </label>

                <label for="<?php echo $this->META_LONG; ?>">
            <?php _e('Longitude:', 'event-post') ?>
                    <input type="text" value ="<?php echo $event->long; ?>" name="<?php echo $this->META_LONG; ?>" id="<?php echo $this->META_LONG; ?>" class="widefat"/>
                </label>
                <p>
                    <a id="event_address_unsearch" class="button button-small">
                        <span class="dashicons dashicons-yes"></span>
                        <?php _e('Done', 'event-post') ?>
                    </a>
                </p>
            </div>
        </div>

        <div class="eventpost-misc-pub-section">
            <div id="event-post-map-preview" data-marker="<?php echo $this->get_marker($event->color); ?>"></div>
        </div>
        <?php
        do_action ('eventpost_custom_box_loc', $event);
        $this->load_map_scripts();
    }

    /**
     * display custombox containing shortcode wizard
     */
    public function inner_custom_box_edit() {
        $ep_settings = $this->settings;
        ?>
        <?php do_action('before_eventpost_generator'); ?>
        <div class="all">
            <p>
                <label for="ep_sce_type"><?php _e('Type :', 'event-post'); ?>
                    <select  id="ep_sce_type">
                        <option value='list'><?php _e('List', 'event-post') ?></option>
                        <option value='map'><?php _e('Map', 'event-post') ?></option>
                    </select>
                </label>
            </p>

            <p>
                <label for="ep_sce_nb"><?php _e('Number of posts', 'event-post'); ?>
                    <input id="ep_sce_nb" type="number" value="5" data-att="nb"/>
                    <a class="button" id="ep_sce_nball"><?php _e('All', 'event-post'); ?></a>
                </label>
            </p>

            <p>
                <label for="ep_sce_cat"><?php _e('Only in :', 'event-post'); ?>
                    <select  id="ep_sce_cat" data-att="cat">
                        <option value=''><?php _e('All', 'event-post') ?></option>
                        <?php
                        $cats = get_categories();
                        foreach ($cats as $cat) {
                            ?>
                            <option value="<?php echo $cat->slug; ?>" <?php
                        if ($cat->slug == $eventpost_cat) {
                            echo'selected';
                        }
                        ?>><?php echo $cat->cat_name; ?></option>
        <?php } ?>
                    </select>
                </label>
            </p>

            <p>
                <label for="ep_sce_future"><?php _e('Future events:', 'event-post'); ?>
                    <select  id="ep_sce_future" data-att="future">
                        <option value='1'><?php _e('Yes', 'event-post') ?></option>
                        <option value='0'><?php _e('No', 'event-post') ?></option>
                    </select>
                </label>
                <label for="ep_sce_past"><?php _e('Past events:', 'event-post'); ?>
                    <select  id="ep_sce_past" data-att="past">
                        <option value='0'><?php _e('No', 'event-post') ?></option>
                        <option value='1'><?php _e('Yes', 'event-post') ?></option>
                    </select>
                </label>
            </p>

            <div id="ep_sce_listonly" class="list">
                <p>
                    <label for="ep_sce_geo"><?php _e('Only geotagged events:', 'event-post'); ?>
                        <select  id="ep_sce_geo" data-att="geo">
                            <option value='0'><?php _e('No', 'event-post') ?></option>
                            <option value='1'><?php _e('Yes', 'event-post') ?></option>
                        </select>
                    </label>
                </p>
            </div>
            <div id="ep_sce_maponly" class="map">
                <p>
                    <label for="ep_sce_tile"><?php _e('Map background', 'event-post'); ?>
                        <select id="ep_sce_tile" data-att="tile">
        <?php foreach ($this->maps as $map): ?>
                            <option value="<?php
                                if ($ep_settings['tile'] != $map['id']) {
                                    echo $map['id'];
                                }
                                ?>" <?php selected($ep_settings['tile'], $map['id'], true); ?>>
                                <?php echo $map['name']; ?><?php
                                if ($ep_settings['tile'] == $map['id']) {
                                    echo' (default)';
                                }
                                ?>
                            </option>
        <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <label for="ep_sce_width"><?php _e('Width', 'event-post'); ?>
                        <input id="ep_sce_width" type="text" value="500px" data-att="width"/>
                    </label>
                </p>
                <p>
                    <label for="ep_sce_height"><?php _e('Height', 'event-post'); ?>
                        <input id="ep_sce_height" type="text" value="500px" data-att="height"/>
                    </label>
                </p>
            </div>
        <?php do_action('after_eventpost_generator'); ?>
            <div id="ep_sce_shortcode">[event_list]</div>
            <a class="button" id="ep_sce_submit"><?php _e('Insert shortcode', 'event-post'); ?></a>
        </div>
        <script>
            var IEbof = false;
        </script>
        <!--[if lt IE 9]>
        <script>IEbof=true;</script>
        <![endif]-->
        <?php
    }

    /**
     * When the post is saved, saves our custom data
     * @param int $post_id
     * @return void
     */
    public function save_postdata($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE){
            return;
	}

        if (!wp_verify_nonce(filter_input(INPUT_POST, 'eventpost_nonce', FILTER_SANITIZE_STRING), plugin_basename(__FILE__))){
            return;
	}

        // Clean color or no color
        if (false !== $color = filter_input(INPUT_POST, $this->META_COLOR, FILTER_SANITIZE_STRING)) {
            update_post_meta($post_id, $this->META_COLOR, $color);
        }
        // Clean date or no date
	if ((false !== $start = filter_input(INPUT_POST, $this->META_START, FILTER_SANITIZE_STRING)) &&
	    (false !== $end = filter_input(INPUT_POST, $this->META_END, FILTER_SANITIZE_STRING)) &&
	    '' != $start &&
	    '' != $end) {
	    update_post_meta($post_id, $this->META_START, substr($start,0,16).':00');
	    update_post_meta($post_id, $this->META_END, substr($end,0,16).':00');
        }
	else {
	    delete_post_meta($post_id, $this->META_START);
	    delete_post_meta($post_id, $this->META_END);
	}

        // Clean location or no location
	if ((false !== $lat = filter_input(INPUT_POST, $this->META_LAT, FILTER_SANITIZE_STRING)) &&
	    (false !== $long = filter_input(INPUT_POST, $this->META_LONG, FILTER_SANITIZE_STRING)) &&
	    '' != $lat &&
	    '' != $long) {
	    update_post_meta($post_id, $this->META_ADD, filter_input(INPUT_POST, $this->META_ADD, FILTER_SANITIZE_STRING));
	    update_post_meta($post_id, $this->META_LAT, $lat);
	    update_post_meta($post_id, $this->META_LONG, $long);
	}
	else {
	    delete_post_meta($post_id, $this->META_ADD);
	    delete_post_meta($post_id, $this->META_LAT);
	    delete_post_meta($post_id, $this->META_LONG);
	}
    }

    /**
     *
     * @param string $date
     * @param string $cat
     * @param boolean $display
     * @return boolean
     */
    public function display_caldate($date, $cat = '', $display = false, $colored=true, $thumbnail='') {
        $events = $this->get_events(array('nb' => -1, 'date' => $date, 'cat' => $cat, 'retreive' => true));
        $nb = count($events);
        if ($display) {
            if ($nb > 0) {
                $ret.='<ul>';
                foreach ($events as $event) {
		    if ($this->settings['emptylink'] == 0 && empty($event->post_content)) {
			$event->guid = '#';
		    }
                    $ret.='<li>'
                            . '<a href="' . $event->permalink . '" title="'.esc_attr(sprintf(__('View event: %s', 'event-post'), $event->post_title)).'">'
                            . '<h4>' . $event->post_title . '</h4>'
                            .$this->get_single($event)
                            . (!empty($thumbnail) ? '<span class="event_thumbnail_wrap">' . get_the_post_thumbnail($event->ID, $thumbnail) . '</span>' : '')
                            .'</a>'
                            . '</li>';
                }
                $ret.='</ul>';
                return $ret;
            }
            return'';
        } else {
            return $nb > 0 ? '<button data-date="' . date('Y-m-d', $date) . '" class="eventpost_cal_link"'.($colored?' style="background-color:#'.$events[0]->color.'"':'').' title="'.esc_attr(sprintf(_n('View %1$d event at date %2$s', 'View %1$d events at date %2$s', $nb, 'event-post'), $nb, $this->human_date($date, $this->settings['dateformat']))).'">' . date('j', $date) . '</button>' : date('j', $date);
        }
    }


    /**
     * @param array $atts
     * @filter eventpost_params
     * @return string
     */
    public function calendar($atts) {
        extract(shortcode_atts(apply_filters('eventpost_params', array(
            'date' => date('Y-n'),
            'cat' => '',
            'mondayfirst' => 0, //1 : weeks starts on monday
            'datepicker' => 1,
            'colored' => 1,
            'thumbnail'=>'',
            ), 'calendar'), $atts));

        $annee = substr($date, 0, 4);
        $mois = substr($date, 5);

        $time = mktime(0, 0, 0, $mois, 1, $annee);

        $prev_year = strtotime('-1 Year', $time);
        $next_year = strtotime('+1 Year', $time);
        $prev_month = strtotime('-1 Month', $time);
        $next_month = strtotime('+1 Month', $time);

        $JourMax = date("t", $time);
        $NoJour = -date("w", $time);
        if ($mondayfirst == 0) {
            $NoJour +=1;
        } else {
            $NoJour +=2;
            $this->Week[] = array_shift($this->Week);
        }
        if ($NoJour > 0 && $mondayfirst == 1) {
            $NoJour -=7;
        }
        $ret = '<table class="event-post-calendar-table">'
                . '<caption class="screen-reader-text">'
                . __('A calendar of events', 'event-post')
                . '</caption>';
        $ret.='<thead><tr><th colspan="7">';
        if ($datepicker == 1) {
            $ret.='<div>';
            $ret.='<button data-date="' . date('Y-n', $prev_year) . '" tabindex="0" title="'.sprintf(__('Switch to %s', 'event-post'), date('Y', $prev_year)).'" class="eventpost_cal_bt">&lt;&lt;</button> ';
            $ret.=$annee;
            $ret.='<button data-date="' . date('Y-n', $next_year) . '" title="'.sprintf(__('Switch to %s', 'event-post'), date('Y', $next_year)).'" class="eventpost_cal_bt">&gt;&gt;</button> ';
            $ret.='<button data-date="' . date('Y-n', $prev_month) . '" title="'.sprintf(__('Switch to %s', 'event-post'), date_i18n('F Y', $prev_month)).'" class="eventpost_cal_bt">&lt;</button> ';
            $ret.=$this->NomDuMois[abs($mois)];
            $ret.='<button data-date="' . date('Y-n', $next_month) . '" title="'.sprintf(__('Switch to %s', 'event-post'), date_i18n('F Y', $next_month)).'" class="eventpost_cal_bt">&gt;</button> ';
            $ret.='<button data-date="' . date('Y-n') . '" class="eventpost_cal_bt">' . __('Today', 'event-post') . '</button>';
            $ret.='</div>';
        }
        $ret.='</th></tr><tr class="event_post_cal_days">';
        for ($w = 0; $w < 7; $w++) {
            $ret.='<th scope="col">' . strtoupper(substr($this->Week[$w], 0, 1)) . '</th>';
        }
        $ret.='</tr>';
        $ret.='</thead>';

        $ret.='<tbody>';
        $sqldate = date('Y-m', $time);
        $cejour = date('Y-m-d');
        for ($semaine = 0; $semaine <= 5; $semaine++) {   // 6 semaines par mois
            $ret.='<tr>';
            for ($journee = 0; $journee <= 6; $journee++) { // 7 jours par semaine
                if ($NoJour > 0 && $NoJour <= $JourMax) { // si le jour est valide a afficher
                    $td = '<td class="event_post_day">';
                    if ($sqldate . '-' . ($NoJour<10?'0':'').$NoJour == $cejour) {
                        $td = '<td class="event_post_day_now">';
                    }
                    $ret.=$td;

                    $ret.= $this->display_caldate(mktime(0, 0, 0, $mois, $NoJour, $annee), $cat, false, $colored, $thumbnail);
                    $ret.='</td>';
                } else {
                    $ret.='<td></td>';
                }
                $NoJour ++;
            }
            $ret.='</tr>';
        }
        $ret.='</tbody></table>';
        return $ret;
    }

    /**
     * echoes the content of the calendar in ajax context
     */
    public function ajaxcal() {
        echo $this->calendar(array(
            'date' => esc_attr(FILTER_INPUT(INPUT_GET, 'date')),
            'cat' => esc_attr(FILTER_INPUT(INPUT_GET, 'cat')),
            'mondayfirst' => esc_attr(FILTER_INPUT(INPUT_GET, 'mf')),
            'datepicker' => esc_attr(FILTER_INPUT(INPUT_GET, 'dp')),
            'colored' => esc_attr(FILTER_INPUT(INPUT_GET, 'color')),
            'thumbnail' => esc_attr(FILTER_INPUT(INPUT_GET, 'thumbnail')),
        ));
        exit();
    }

    /**
     * echoes the date of the calendar in ajax context
     */
    public function ajaxdate() {
        echo $this->display_caldate(strtotime(esc_attr(FILTER_INPUT(INPUT_GET, 'date'))), esc_attr(FILTER_INPUT(INPUT_GET, 'cat')), true, esc_attr(FILTER_INPUT(INPUT_GET, 'color')), esc_attr(FILTER_INPUT(INPUT_GET, 'thumbnail')));
        exit();
    }

    /**
     * echoes a date in ajax context
     */
    public function HumanDate() {
        if (isset($_REQUEST['date']) && !empty($_REQUEST['date'])) {
            $date = strtotime($_REQUEST['date']);
            echo $this->human_date($date, $this->settings['dateformat']).(date('H:i', $date)=='00:00' ? '' : ' '. date($this->settings['timeformat'], $date));
            exit();
        }
    }

    /**
     * AJAX Get lat long from address
     */
    public function GetLatLong() {
        if (isset($_REQUEST['q']) && !empty($_REQUEST['q'])) {
            // verifier le cache
            $q = $_REQUEST['q'];
            header('Content-Type: application/json');
            $transient_name = 'eventpost_osquery_' . $q;
            $val = get_transient($transient_name);
            if (false === $val || empty($val)) {
                $language = get_bloginfo('language');
                if (strpos($language, '-') > -1) {
                    $language = strtolower(substr($language, 0, 2));
                }
                $val = file_get_contents('http://nominatim.openstreetmap.org/search?q=' . urlencode($q) . '&format=json&accept-language=' . $language);
                set_transient($transient_name, $val, 30 * DAY_IN_SECONDS);
            }
            echo $val;
            exit();
        }
    }

    /**
     * alters columns
     * @param array $defaults
     * @return array
     * @filter eventpost_columns_head
     */
    public function columns_head($defaults) {
        $defaults['event'] = __('Event', 'event-post');
        $defaults['location'] = __('Location', 'event-post');
        return apply_filters('eventpost_columns_head', $defaults);
    }

    /**
     * echoes content of a row in a given column
     * @param string $column_name
     * @param int $post_id
     * @action eventpost_columns_content
     */
    public function columns_content($column_name, $post_id) {
        if ($column_name == 'location') {
            $lat = get_post_meta($post_id, $this->META_LAT, true);
            $lon = get_post_meta($post_id, $this->META_LONG, true);

            if (!empty($lat) && !empty($lon)) {
                add_thickbox();
                $color = get_post_meta($post_id, $this->META_COLOR, true);
                if ($color == ''){
                    $color = '777777';
		}
                echo    '<a href="https://www.openstreetmap.org/export/embed.html?bbox='.($lon-0.005).'%2C'.($lat-0.005).'%2C'.($lon+0.005).'%2C'.($lat+0.005).'&TB_iframe=true&width=600&height=550" class="thickbox" target="_blank">'
                        . '<i class="dashicons dashicons-location" style="color:#'.$color.';"></i>'
                        . '<span class="screen-reader-text">'.__('View on a map', 'event-post').'</span>'
                        . get_post_meta($post_id, $this->META_ADD, true)
                        . '</a> ';
            }
        }
        if ($column_name == 'event') {
            echo $this->print_date($post_id, false);
        }
        do_action('eventpost_columns_content', $column_name, $post_id);
    }

    /** ADMIN PAGES **/

    /**
     *  Settings link on the plugins page
     */
    public function settings_link( $links ) {
            $settings_link = '<a href="options-general.php?page=event-settings">' . __( 'Settings', 'event-post' ) . '</a>';
            // place it before other links
            array_unshift( $links, $settings_link );
            return $links;
    }
    /**
     *
     * @param type $plugin_meta
     * @param type $plugin_file
     * @param type $plugin_data
     * @param type $status
     * @return type
     */
    public function row_meta($plugin_meta, $plugin_file, $plugin_data, $status){
        if($plugin_file=='event-post/eventpost.php'){
            $plugin_link = '<a href="http://event-post.com" target="_blank">' . __( 'Plugin site', 'event-post' ) . '</a>';
            $review_link = '<a href="https://wordpress.org/support/plugin/event-post/reviews/#new-post" target="_blank">' . __( 'Give a note', 'event-post' ) . '</a>';
            array_push($plugin_meta, $plugin_link, $review_link);
        }
        return $plugin_meta;
    }
    /**
     * save settings end redirect
     * @return void
     */
    public function save_settings(){
	if (!current_user_can('manage_options')){
	    return;
	}
	if (!wp_verify_nonce(\filter_input(INPUT_POST,'ep_nonce_settings',FILTER_SANITIZE_STRING), 'ep_nonce_settings')) {
	    wp_die(__('Security error', 'event-post'));
	}

	$valid_post = array(
	    'ep_settings'=>array(
		'filter' => FILTER_SANITIZE_STRING,
		'flags'  => FILTER_REQUIRE_ARRAY
	    )
	);

	foreach ($this->settings as $item_name=>$item_value){
	    $valid_post['ep_settings'][$item_name] = FILTER_SANITIZE_STRING;
	}

        $post_types=(array) $_POST['ep_settings']['posttypes'];
        $posttypes = get_post_types();
        foreach($post_types as $posttype){
            if(!in_array($posttype, $posttypes)){
                unset($post_types[$posttype]);
            }
        }

	if (false !== $settings = \filter_input_array(INPUT_POST,$valid_post)) {
	    $settings['ep_settings']['container_shema']=stripslashes($_POST['ep_settings']['container_shema']);
	    $settings['ep_settings']['item_shema']=  stripslashes($_POST['ep_settings']['item_shema']);
	    $settings['ep_settings']['posttypes']=  array_values($post_types);
	    update_option('ep_settings', $settings['ep_settings']);
	}
	wp_redirect('options-general.php?page=event-settings&confirm=options_saved');
	exit;
    }
    /**
     * adds menu items
     */
    public function manage_options() {
        add_options_page(__('Events  settings', 'event-post'), __('Events', 'event-post'), 'manage_options', 'event-settings', array(&$this, 'manage_settings'));
    }
    /**
     * adds items to the native "right now" dashboard widget
     * @param array $elements
     * @return array
     */
    public function dashboard_right_now($elements){
        $nb_date = count($this->get_events(array('future'=>1, 'past'=>1, 'nb'=>-1)));
        $nb_geo = count($this->get_events(array('future'=>1, 'past'=>1, 'geo'=>1, 'nb'=>-1)));
        if($nb_date){
            array_push($elements, '<i class="dashicons dashicons-calendar"></i> <i href="edit.php?post_type=post">'.sprintf(__('%d Events','event-post'), $nb_date)."</i>");
        }
        if($nb_geo){
            array_push($elements, '<i class="dashicons dashicons-location"></i> <i href="edit.php?post_type=post">'.sprintf(__('%d Geolocalized events','event-post'), $nb_geo)."</i>");
        }
        return $elements;
    }

    /**
     * output content of the setting page
     */
    public function manage_settings() {
        if ('options_saved'===\filter_input(INPUT_GET,'confirm',FILTER_SANITIZE_STRING)) { ?>
            <div class="updated"><p><strong><?php _e('Event settings saved !', 'event-post') ?></strong></p></div>
            <?php
        }
        $ep_settings = $this->settings;
        ?>
        <div class="wrap">
            <div class="icon32" id="icon-options-general"><br></div>
            <h1><?php _e('Event settings', 'event-post'); ?></h1>
            <form name="form1" method="post" action="admin-post.php">
		<input type="hidden" name="action" value="EventPostSaveSettings">
		<?php wp_nonce_field('ep_nonce_settings','ep_nonce_settings') ?>
                <h2><?php _e('Global settings', 'event-post'); ?></h2>
                <table class="form-table" id="eventpost-settings-table-global">
                    <tbody>
                        <tr>
                            <th>
                                <label for="ep_emptylink">
                                    <?php _e('Print link for empty posts', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <select name="ep_settings[emptylink]" id="ep_emptylink">
                                    <option value="1" <?php selected($ep_settings['emptylink'], 1, true);?>>
					<?php _e('Link all posts', 'event-post'); ?>
				    </option>
                                    <option value="0" <?php selected($ep_settings['emptylink'], 0, true);?>>
					<?php _e('Do not link posts with empty content', 'event-post'); ?>
				    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <label for="ep_singlepos">
                                    <?php _e('Event bar position for single posts', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <select name="ep_settings[singlepos]" id="ep_singlepos">
                                    <option value="before" <?php selected($ep_settings['singlepos'], 'before', true); ?>>
					<?php _e('Before the content', 'event-post'); ?>
				    </option>
                                    <option value="after" <?php selected($ep_settings['singlepos'], 'after', true); ?>>
					<?php _e('After the content', 'event-post'); ?>
				    </option>
                                    <option value="none" <?php selected($ep_settings['singlepos'], 'none', true); ?>>
					<?php _e('Not displayed', 'event-post'); ?>
				    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <label for="ep_loopicons">
                                    <?php _e('Add icons for events in the loop', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <select name="ep_settings[loopicons]" id="ep_loopicons">
                                    <option value="1" <?php selected($ep_settings['loopicons'],'1', true) ?>>
					<?php _e('Emojis', 'event-post'); ?></option>
                                    <option value="0" <?php selected($ep_settings['loopicons'],'0', true) ?>>
				        <?php _e('Hide', 'event-post'); ?></option>
                                    <option value="2" <?php selected($ep_settings['loopicons'],'2', true) ?>>
				        <?php _e('Icons', 'event-post'); ?></option>
                                </select>
                            </td>
                        </tr>
			<tr>
                            <th>
                                <label for="ep_adminpos">
                                    <?php _e('Position of event details boxes', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <select name="ep_settings[adminpos]" id="ep_adminpos">
                                    <option value="side" <?php selected($ep_settings['adminpos'],'side', true) ?>>
					<?php _e('Side', 'event-post'); ?></option>
                                    <option value="normal" <?php selected($ep_settings['adminpos'],'normal', true) ?>>
				        <?php _e('Under the text', 'event-post'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table><!-- #eventpost-settings-table-global -->

                <h2><?php _e('Date settings', 'event-post'); ?></h2>
                <table class="form-table" id="eventpost-settings-table-event">
                    <tbody>
                        <tr>
                            <th>
                                <label for="ep_dateformat">
                                    <?php _e('Date format', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="ep_settings[dateformat]" id="ep_dateformat" value="<?php echo $ep_settings['dateformat']; ?>"  size="10">
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <label for="ep_timeformatformat">
                                    <?php _e('Time format', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="ep_settings[timeformat]" id="ep_dateformat" value="<?php echo $ep_settings['timeformat']; ?>"  size="10">
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <label for="ep_dateexport">
                                    <?php _e('Show export buttons on:', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <select name="ep_settings[export]" id="ep_dateexport">
                                    <option value="list" <?php selected($ep_settings['export'], 'list', true);?>>
					<?php _e('List only', 'event-post') ?>
				    </option>
                                    <option value="single" <?php selected($ep_settings['export'], 'single', true);?>>
					<?php _e('Single only', 'event-post') ?>
				    </option>
                                    <option value="both" <?php selected($ep_settings['export'], 'both', true);?>>
					<?php _e('Both', 'event-post') ?>
				    </option>
                                    <option value="none" <?php selected($ep_settings['export'], 'none', true);?>>
					<?php _e('None', 'event-post') ?>
				    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <label for="ep_dateforhumans">
                                    <?php _e('Relative human dates:', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <select name="ep_settings[dateforhumans]" id="ep_dateforhumans">
                                    <option value="1" <?php selected($ep_settings['dateforhumans'], '1', true);?>>
					<?php _e('Yes', 'event-post') ?>
				    </option>
                                    <option value="0" <?php selected($ep_settings['dateforhumans'], '0', true);?>>
					<?php _e('No', 'event-post') ?>
				    </option>
                                </select>
                                <p class="description"><?php _e('Replace absolute dates by "today", "yesterday", and "tomorrow".', 'event-post') ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table><!-- #eventpost-settings-table-event -->

                <h2><?php _e('List settings', 'event-post'); ?></h2>
                <table class="form-table" id="eventpost-settings-table-list">
                    <tbody>
                        <tr>
                            <th>
                                <label for="ep_container_shema">
                                    <?php _e('Container shema', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <textarea class="widefat" name="ep_settings[container_shema]" id="ep_container_shema"><?php echo $ep_settings['container_shema']; ?></textarea>
                                <p><?php _e('default:','event-post') ?></p>
                                <code><?php echo htmlentities($this->default_list_shema['container']) ?></code>
                            </td>
                        </tr>
			<tr>
                            <th>
                                <label for="ep_item_shema">
                                    <?php _e('Item shema', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <textarea class="widefat" name="ep_settings[item_shema]" id="ep_item_shema"><?php echo $ep_settings['item_shema']; ?></textarea>
                                <p><?php _e('default:','event-post') ?></p>
                                <code><?php echo htmlentities($this->default_list_shema['item']) ?></code>
                            </td>
                        </tr>
                    </tbody>
                </table><!-- #eventpost-settings-table-list -->

                <h2><?php _e('Map settings', 'event-post'); ?></h2>
                <table class="form-table" id="eventpost-settings-table-map">
                    <tbody>
                        <tr>
                            <th>
                                <label for="ep_tile">
                                    <?php _e('Map background', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <select name="ep_settings[tile]" id="ep_tile">
                                <?php foreach ($this->maps as $map): ?>
                                    <option value="<?php echo $map['id']; ?>" <?php selected($ep_settings['tile'], $map['id'], true); ?>>
                                        <?php echo $map['name']; ?>
                                        <?php echo (isset($map['urls_retina']) ? __('(Retina support)', 'event-post') : ''); ?>
                                    </option>
				<?php endforeach; ?>
                                </select></td>
                        </tr>
                        <tr>
                            <th>
                                <label for="ep_zoom">
                                    <?php _e('Default zoom', 'event-post') ?>
                                </label>
                            </th>
                            <td>
                                <input id="ep_zoom" name="ep_settings[zoom]" value="<?php echo $ep_settings['zoom']; ?>" type="number" min="3" max="18" size="3"/>
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <?php _e('Makers custom directory (leave empty for default settings)', 'event-post') ?>
                            </th>
                            <td>
                                <label for="ep_markpath">
                                    <?php _e('Root path:', 'event-post') ?><br>
                                    ABSPATH/<input name="ep_settings[markpath]" id="ep_markpath" value="<?php echo $this->settings['markpath'] ?>" class="widefat">
                                </label><br>
                                <label for="ep_markurl">
                                    <?php _e('URL:', 'event-post') ?><br>
                                    <input name="ep_settings[markurl]" id="ep_markurl" value="<?php echo $this->settings['markurl'] ?>" class="widefat">
                                </label>
                            </td>
                        </tr>
                        </tbody>
                </table><!-- #eventpost-settings-table-map -->

                <h2><?php _e('Admin UI settings', 'event-post'); ?></h2>
                <table class="form-table" id="eventpost-settings-table-admin">
                    <tbody>
                        <tr>
                            <th>
                                <label for="ep_posttypes">
                                    <?php _e('Wich post types can be events ?', 'event-post') ?>
                                </label>
                            </th>
                            <td><?php $posttypes = apply_filters('eventpost_get_post_types', get_post_types(array(), 'objects')); ?>
                                <?php foreach($posttypes as $type=>$posttype):  ?>
                                <p>
                                    <label>
                                        <input type="checkbox" name="ep_settings[posttypes][<?php echo $posttype->name; ?>]" value="<?php echo $posttype->name; ?>" <?php checked(in_array($posttype->name, $ep_settings['posttypes']),true, true) ?>>
					<?php echo $posttype->labels->name; ?>
                                    </label>
                                </p>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <?php _e('Datepicker style', 'event-post') ?>
                                <?php $now = current_time('mysql'); ?>
                                <?php $human_date = $this->human_date(current_time('timestamp')) .' '. date($this->settings['timeformat'], current_time('timestamp')); ?>
                            </th>
                            <td>
                                <div>
                                    <label>
                                        <input type="radio" name="ep_settings[datepicker]" id="ep_datepicker_simple" value="simple" <?php checked($ep_settings['datepicker'],'simple', true) ?>>
                                        <?php _e('Simple', 'event-post'); ?>
                                    </label>
                                    <p>
                                        <span id="eventpost_simple_date_human" class="human_date">
                                             <?php echo $human_date; ?>
                                        </span>
                                        <input type="text" class="eventpost-datepicker-simple" id="eventpost_simple_date" value="<?php echo $now; ?>">
                                    </p>
                                </div>
                                <div>
                                    <label>
                                        <input type="radio" name="ep_settings[datepicker]" id="ep_datepicker_native" value="native" <?php checked($ep_settings['datepicker'],'native', true) ?>>
                                        <?php _e('Native WordPress style', 'event-post'); ?>
                                    </label>
                                    <p>
                                        <span id="eventpost_native_date_human" class="human_date">
                                             <?php echo $human_date; ?>
                                        </span>
                                        <input type="text" class="eventpost-datepicker-native" id="eventpost_native_date" value="<?php echo $now; ?>">
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table><!-- #eventpost-settings-table-admin -->

                <h2><?php _e('Performances settings', 'event-post'); ?></h2>
                <table class="form-table" id="eventpost-settings-table-perfs">
                    <tbody>
                        <tr>
                            <th>
                                <?php _e('Use cache', 'event-post') ?>
                            </th>
                            <td>
                                <label for="ep_cache">
                                    <input type="checkbox" name="ep_settings[cache]" id="ep_cache" <?php if($ep_settings['cache']=='1'){ echo'checked';} ?> value="1">
                                    <?php _e('Use cache for results','event-post')?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table><!-- #eventpost-settings-table-perfs -->

                <?php do_action('eventpost_settings_form', $ep_settings); ?>

                <p class="submit">
                    <input type="submit" value="<?php _e('Apply settings', 'event-post'); ?>" class="button button-primary" id="submit" name="submit">
                </p>

            </form>
        </div>
        <?php
        do_action('eventpost_after_settings_form');
    }

    /*
     * feed
     * generate ICS or VCS files from a category
     */

    /**
     *
     * @param timestamp $timestamp
     * @return string
     */
    public function ics_date($timestamp){
	return date("Ymd",$timestamp).'T'.date("His",$timestamp);
    }

    public function get_gmt_offset(){
        $gmt_offset = get_option('gmt_offset ');
        $codegmt = 0;
        if ($gmt_offset != 0 && substr($gmt_offset, 0, 1) != '-' && substr($gmt_offset, 0, 1) != '+') {
            $codegmt = $gmt_offset * -1;
            $gmt_offset = '+' . $gmt_offset;
        }
        if(abs($gmt_offset < 10)){
            $gmt_offset = substr($gmt_offset, 0, 1).'0'.substr($gmt_offset, 1);
        }
        return $gmt_offset;
    }

    /**
     * outputs an ICS document
     */
    public function feed(){
	if(false !== $cat=\filter_input(INPUT_GET, 'cat',FILTER_SANITIZE_STRING)){
	    $vtz = get_option('timezone_string');
            $gmt = $this->get_gmt_offset();
	    date_default_timezone_set($vtz);
            $separator = "\n";

	    header("content-type:text/x-icalendar");
	    header("Pragma: public");
	    header("Expires: 0");
	    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
	    header("Cache-Control: public");
	    header("Content-Disposition: attachment; filename=". str_replace('+','-',urlencode(get_option('blogname').'-'.$cat)).".ics;" );

            $props = array();

            // General
            $props[] =  'BEGIN:VCALENDAR';
            $props[] =  'PRODID://WordPress//Event-Post V'. file_get_contents(('VERSION')).'//EN';
            $props[] =  'VERSION:2.0';

            // Timezone
            if(!empty($vtz)){
                array_push($props,
                    'BEGIN:VTIMEZONE',
                    'TZID:'.$vtz,
                    'BEGIN:DAYLIGHT',
                    'TZOFFSETFROM:+0100',
                    'TZOFFSETTO:'.($gmt).'00',
                    'DTSTART:19700329T020000',
                    'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3',
                    'END:DAYLIGHT',
                    'BEGIN:STANDARD',
                    'TZOFFSETFROM:'.($gmt).'00',
                    'TZOFFSETTO:+0100',
                    'TZNAME:CET',
                    'DTSTART:19701025T030000',
                    'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10',
                    'END:STANDARD',
                    'END:VTIMEZONE'
                );
            }

            // Events
            $events=$this->get_events(array('cat'=>$cat,'nb'=>-1));
	    foreach ($events as $event) {
                array_push($props,
                    'BEGIN:VEVENT',
                    'CREATED:'.$this->ics_date(strtotime($event->post_date)).'Z',
                    'LAST-MODIFIED:'.$this->ics_date(strtotime($event->post_modified)).'Z',
                    'SUMMARY:'.$event->post_title,
                    'UID:'.md5(site_url()."_eventpost_".$event->ID),
                    'LOCATION:'.str_replace(',','\,',$event->address),
                    'DTSTART'.(!empty($vtz)?';TZID='.$vtz:'').':'.$this->ics_date($event->time_start).(!empty($vtz)?'':'Z'),
                    'DTEND'.(!empty($vtz)?';TZID='.$vtz:'').':'.$this->ics_date($event->time_end).(!empty($vtz)?'':'Z'),
                    'DESCRIPTION:'.$event->guid,
                    'END:VEVENT'
                );
            }

            // End
            $props[] =  'END:VCALENDAR';

            echo implode($separator, $props);
            exit;
	}
    }
}