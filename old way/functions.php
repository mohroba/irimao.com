<?php
/**
 * Astra-Child Theme functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Astra-Child
 * @since 1.0.0
 */

/**
 * Define Constants
 */
define( 'CHILD_THEME_ASTRA_CHILD_VERSION', '1.0.0' );

/**
 * Enqueue styles
 */
function child_enqueue_styles() {

	wp_enqueue_style( 'astra-child-theme-css', get_stylesheet_directory_uri() . '/style.css', array('astra-theme-css'), CHILD_THEME_ASTRA_CHILD_VERSION, 'all' );

}

add_action( 'wp_enqueue_scripts', 'child_enqueue_styles', 15 );

// Display User IP in WordPress
function get_the_user_ip() {
	if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
	//check ip from share internet
	$ip = $_SERVER['HTTP_CLIENT_IP'];
	} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		//to check ip is pass from proxy
		$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
	} else {
		$ip = $_SERVER['REMOTE_ADDR'];
	}
	return apply_filters( 'wpb_get_ip', $ip );
}
add_filter('widget_text', 'do_shortcode');
add_shortcode('show_ip', 'get_the_user_ip');


// ======================= CUSTOM LOGIN/REGISTER/DASHBOARD ====================================

function crm_get_city_map() : array {
	// Static cache so we calculate the map only once per request.
	static $cache = null;
	if ( $cache !== null ) {
		return $cache;
	}

	/* ---------- load JSON data ---------- */
	$prov_path = get_stylesheet_directory() . '/provinces.json';
	$city_path = get_stylesheet_directory() . '/cities.json';

	if ( ! file_exists( $prov_path ) || ! file_exists( $city_path ) ) {
		// Missing data files – return an empty map so callers don’t break.
		return $cache = [];
	}

	$provinces = json_decode( file_get_contents( $prov_path ), true ) ?: [];
	$cities    = json_decode( file_get_contents( $city_path ),  true ) ?: [];

	/* ---------- match JSON province IDs to WC province codes ---------- */
	$wc_states = ( new WC_Countries() )->get_states( 'IR' );  // IR-01 => 'آذربایجان شرقی' …
	$prov_code = [];                                          // province_id → IR-xx

	foreach ( $provinces as $p ) {
		foreach ( $wc_states as $code => $name ) {
			if ( $name === $p['name'] ) {
				$prov_code[ $p['id'] ] = $code;
				break;
			}
		}
	}

	/* ---------- assemble the final city map ---------- */
	$map = [];

	foreach ( $cities as $c ) {
		$code = $prov_code[ $c['province_id'] ] ?? null;
		if ( $code ) {
			$map[ $code ][] = $c['name'];
		}
	}

	return $cache = $map;
}

function crm_get_cities_for_province( string $province_code ) : array {
	$map = crm_get_city_map();
	return $map[ $province_code ] ?? [];
}

function crm_get_confirmed_coaches() : array {
    $users = get_users( [
        'role'       => 'coach',
        'meta_key'   => 'identity_verified_professional',
        'meta_value' => 'approved',
        'fields'     => [ 'ID', 'display_name' ],
    ] );
    $out = [ '' => '— انتخاب مربی —' ];
    foreach ( $users as $u ) {
        $out[ $u->ID ] = $u->display_name;
    }
    return $out;
}


/* helper: check if the user has populated all mandatory basic fields */
function crm_user_has_basic_info( $user_id = 0 ){
    return true;
    $user_id = $user_id ?: get_current_user_id();
    $needed  = [ 'national_id','first_name_fa','last_name_fa','gender',
                 'father_name','birth_date','birth_province','birth_city',
                 'marital_status','education_status','military_status',
                 'residence_province','residence_city','postal_code',
                 'residence_address','billing_phone','billing_email' ];
    foreach ( $needed as $k ){
        if ( '' === get_user_meta( $user_id, $k, true ) ) return false;
    }
    return true;
}


add_action( 'woocommerce_created_customer', 'set_national_id_and_wc_names', 10, 1 );
function set_national_id_and_wc_names( $user_id ) {
    // Pull and unserialize the Digits data
    $raw = get_user_meta( $user_id, 'digits_form_data', true );
    if ( ! $raw ) return;
    $data = maybe_unserialize( $raw );
    if ( ! is_array( $data ) ) return;

    $national_id = '';
    $first_name  = '';
    $last_name   = '';

    foreach ( $data as $entry ) {
        if ( ! is_array( $entry ) || empty( $entry['label'] ) || empty( $entry['meta_key'] ) ) {
            continue;
        }
        $label   = trim( $entry['label'] );
        $metaKey = $entry['meta_key'];
        $value   = get_user_meta( $user_id, $metaKey, true );

        // National ID
        if ( $label === 'کدملی' ) {
            $national_id = $value;
        }

        // First name: either label is "نام" or meta key begins with "first_name_"
        if ( $label === 'نام' || preg_match('/^first_name_/', $metaKey) ) {
            $first_name = $value;
        }

        // Last name: either label is "نامخانوادگی" or meta key begins with "last_name_"
        if ( $label === 'نامخانوادگی' || preg_match('/^last_name_/', $metaKey) ) {
            $last_name = $value;
        }
    }

    // 1) Save national ID and set as login
    if ( $national_id ) {
        update_user_meta( $user_id, 'national_id', sanitize_text_field( $national_id ) );
        global $wpdb;
        $wpdb->update(
            $wpdb->users,
            [ 'user_login' => sanitize_user( $national_id, true ) ],
            [ 'ID'         => $user_id ]
        );
    
        // Build a full name
        $full_name = trim( $first_name . ' ' . $last_name );
        if ( $full_name ) {
            // Update WP user fields: display_name, nickname and nicename (slug)
            wp_update_user( [
                'ID'            => $user_id,
                'display_name'  => $full_name,
                'nickname'      => $full_name,
                'user_nicename' => sanitize_title( $full_name ),
            ] );
        }
    
        clean_user_cache( $user_id );
    }


    // 2) Populate billing & core name fields, plus your Farsi meta
    if ( $first_name ) {
        $san = sanitize_text_field( $first_name );
        update_user_meta( $user_id, 'billing_first_name',  $san );
        update_user_meta( $user_id, 'first_name',          $san );
        update_user_meta( $user_id, 'first_name_fa',       $san );
    }
    if ( $last_name ) {
        $san = sanitize_text_field( $last_name );
        update_user_meta( $user_id, 'billing_last_name',   $san );
        update_user_meta( $user_id, 'last_name',           $san );
        update_user_meta( $user_id, 'last_name_fa',        $san );
    }
    
}


// 1) Register the endpoint
add_action( 'init', 'myacc_register_basic_info_endpoint' );
function myacc_register_basic_info_endpoint() {
    add_rewrite_endpoint( 'edit-basic-info', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint( 'identity-professional', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint('self-declare', EP_PAGES|EP_ROOT);
    add_rewrite_endpoint('style-committe', EP_PAGES|EP_ROOT);
    add_rewrite_endpoint('self-declarations-list', EP_PAGES|EP_ROOT);
    add_rewrite_endpoint('smartcard-issue', EP_ROOT|EP_PAGES);
    add_rewrite_endpoint('wallet', EP_ROOT|EP_PAGES);
    add_rewrite_endpoint( 'club-register', EP_PAGES | EP_ROOT );
    add_rewrite_endpoint( 'course-list', EP_PAGES | EP_ROOT );
    add_rewrite_endpoint( 'course-details', EP_PAGES | EP_ROOT );
    add_rewrite_endpoint( 'user-course-list', EP_PAGES | EP_ROOT );
    add_rewrite_endpoint( 'competitions-list', EP_PAGES | EP_ROOT );
    add_rewrite_endpoint( 'user-competitions-list', EP_PAGES | EP_ROOT );
    add_rewrite_endpoint( 'competition-details', EP_PAGES | EP_ROOT );
    add_rewrite_endpoint( 'my-rankings', EP_ROOT | EP_PAGES );
}

// 2) Add to My Account menu
add_filter( 'woocommerce_account_menu_items', 'myacc_add_basic_info_item' );
function myacc_add_basic_info_item( $items ) {
    $items['edit-basic-info'] = __( 'Edit Basic Info', 'crm-plugin' );
    return $items;
}

// 3) Render the form
// 0) Enqueue Persian date‐picker & city‐cascade script
add_action( 'wp_enqueue_scripts', 'myacc_enqueue_assets' );
function myacc_enqueue_assets() {
  	$dir = get_stylesheet_directory_uri().'/assets';
    if ( ! is_account_page() ) return;

    // Jalali datepicker
    wp_enqueue_style(  'persian-datepicker-css',"$dir/css/jalalidatepicker.min.css" );
    wp_enqueue_script( 'persian-datepicker-js',"$dir/js/jalalidatepicker.min.js",[ 'jquery' ], null, false );
       
       
    wp_enqueue_script(
        'sweetalert2',
        "$dir/js/sweetalert2.all.min.js",
        [], null, true
    );
    
    
     wp_enqueue_style( 'select2-css',
      "$dir/css/select2.min.css"
    );
    wp_enqueue_script( 'select2-js',
      "$dir/js/select2.min.js",
      [ 'jquery' ], null, true
    );
    
  $prov_path = get_stylesheet_directory() . '/provinces.json';
    $city_path = get_stylesheet_directory() . '/cities.json';
    $provs     = file_exists($prov_path) ? json_decode(file_get_contents($prov_path), true) : [];
    $cities    = file_exists($city_path) ? json_decode(file_get_contents($city_path), true) : [];
    $wc_states = (new WC_Countries())->get_states('IR'); // e.g. IR-01 => 'آذربایجان شرقی'
    // map province_id → code
    $prov_code = [];
    foreach( $provs as $p ) {
        foreach( $wc_states as $code => $name ) {
            if ( $name === $p['name'] ) {
                $prov_code[ $p['id'] ] = $code;
                break;
            }
        }
    }
    // assemble cityMap
    $city_map = [];
    foreach( $cities as $c ) {
        $code = $prov_code[ $c['province_id'] ] ?? null;
        if ( $code ) {
            $city_map[ $code ][] = $c['name'];
        }
    }

// 	wp_enqueue_script(
// 		'edit-basic-info-js',
// 		get_stylesheet_directory_uri() . '/edit-basic-info.js',
// 		[ 'jquery' ], null, true
// 	);
	
    wp_enqueue_style(
        'my-account-css',
        get_stylesheet_directory_uri() . '/my-account.css',
        [], null
    );

	wp_localize_script( 'edit-basic-info-js', 'CBIF_CITIES', $city_map );

    wp_add_inline_script( 'sweetalert2', "
      jQuery(function($){
        $('form.needs-swal').on('submit', function(){
          Swal.fire({
            title: 'در حال ارسال...',
            html: 'لطفاً صبر کنید',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => { Swal.showLoading(); }
          });
        });
      });
    " );

}

// 1) Render the form
add_action( 'woocommerce_account_edit-basic-info_endpoint', 'myacc_basic_info_form' );
function myacc_basic_info_form() {
    if ( ! is_user_logged_in() ) {
        echo '<p style="text-align:center;color:#c00;">'.__( 'لطفاً وارد شوید.', 'crm-plugin' ).'</p>';
        return;
    }
    $uid      = get_current_user_id();
    $user     = wp_get_current_user();
    $get_meta = fn($k)=> esc_attr( get_user_meta($uid,$k,true) );
    // WC’s Iran provinces
    $provinces = (new WC_Countries())->get_states('IR');

    // Global notice
    echo '<div style="
            background:#ffe8e8;
            border:1px solid #f5c6cb;
            color:#721c24;
            padding:15px;
            border-radius:4px;
            margin-bottom:20px;self-declare
        ">'
        . __( 'شما فقط یکبار اجازه ورود و بروزرسانی اطلاعات پایه را دارید، پس در تکمیل اطلاعات پایه، دقت کافی را داشته باشید. پس از ثبت اطلاعات، تغییر یا بروزرسانی اطلاعات فقط با هماهنگی کمیته آموزش سبک امکان‌پذیر خواهد بود.', 'crm-plugin' )
        . '</div>';
        
    
        echo '<div style="
            background:#ffe8e8;
            border:1px solid #f5c6cb;
            color:#721c24;
            padding:15px;
            border-radius:4px;
            margin-bottom:20px;
        ">'
        . __( 'مسئولیت هرگونه مغایرت اطلاعات وارد شده در این صفحه با فایل‌ها و مستندات آپلود شده در سیستم، کاملا بعهده کاربر بوده و در صورت مشاهده مغایرت، این امر تخلف شمرده شده و احتمال مسدود شدن حساب کاربری وجود خواهد داشت.', 'crm-plugin' )
        . '</div>';

    // Start form
    echo '<form method="post" id="id-form"  class="needs-swal" style="margin:auto;">';

    // Grid & field styling
    echo '<style>
       #id-form{
            max-width: 95%;
            margin: auto;
            background-color: #dedede52;
            font-family: vaziri;
            padding: 10px;
       }
      .cbif-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
      .cbif-row { margin-bottom:10px; display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
      .cbif-field { display:flex; flex-direction:column; }
      .cbif-field label { font-weight:600; margin-bottom:6px; text-align:right; color:#333; }
      .cbif-field label .required { color:#d00; margin-left:4px; }
      .cbif-field input,
      .cbif-field select,
      .cbif-field textarea {
        padding:8px; border:1px solid #ccc; border-radius:4px; background:#fff; font-size:14px;
        width: 100%;
      }
      .cbif-wide { grid-column:span 3; }
      .cbif-submit { grid-column:3/4; text-align:left; margin-top:10px; }
      .cbif-submit button {
        background:#20a8d8; color:#fff; border:none; padding:10px 20px;
        border-radius:4px; cursor:pointer; font-size:15px;
      }
    </style>';

    // Helper to render a field
    $field = function($key, $label, $type='text', $required=true, $opts=[]) use($get_meta) {
      $req = $required ? '<span class="required">*</span>' : '';
      $val = $get_meta($key);
      echo '<div class="cbif-field">';
      echo "<label for='{$key}'>{$label}{$req}</label>";
      if ( $type === 'textarea' ) {
        echo "<textarea id='{$key}' name='{$key}'>".esc_textarea($val)."</textarea>";
      } elseif ( $type === 'select' ) {
        echo "<select id='{$key}' name='{$key}'>";
        foreach( $opts as $v=>$t ) {
          $sel = $val === $v ? ' selected' : '';
          echo "<option value='".esc_attr($v)."'{$sel}>".esc_html($t)."</option>";
        }
        echo "</select>";
      } else {
        $attr = $type!=='date'? "value='".esc_attr($val)."'" : '';
        echo "<input type='{$type}' id='{$key}' name='{$key}' {$attr}>";
      }
      echo '</div>';
    };

    // Build rows
    echo '<div class="cbif-grid">';

      // Row 1: Mobile / National ID (readonly) / Gender
      echo '<div class="cbif-field">';
        echo '<label for="national_id">'.__('کد ملی','crm-plugin').'<span class="required">*</span></label>';
        echo '<input type="text" id="national_id" name="national_id" value="'.esc_attr($get_meta('national_id')).'" disabled>';
      echo '</div>';
      echo '<div class="cbif-field">';
        echo '<label for="gender">'.__('جنسیت','crm-plugin').'<span class="required">*</span></label>';
        echo '<select id="gender" name="gender">
                <option value="">— انتخاب کنید —</option>
                <option value="male"'.($get_meta('gender')==='male'?' selected':'').'>مرد</option>
                <option value="female"'.($get_meta('gender')==='female'?' selected':'').'>زن</option>
              </select>';
      echo '</div>';

      // Row 2: First/Last FA / First/Last EN
      echo '<div class="cbif-field">';
        echo '<label for="first_name_fa">'.__('نام (فارسی)','crm-plugin').'<span class="required">*</span></label>';
        echo '<input type="text" id="first_name_fa" name="first_name_fa" value="'.esc_attr($get_meta('first_name_fa')).'">';
      echo '</div>';
      echo '<div class="cbif-field">';
        echo '<label for="last_name_fa">'.__('نام خانوادگی (فارسی)','crm-plugin').'<span class="required">*</span></label>';
        echo '<input type="text" id="last_name_fa" name="last_name_fa" value="'.esc_attr($get_meta('last_name_fa')).'">';
      echo '</div>';
      echo '<div class="cbif-field">';
        echo '<label for="first_name_en">'.__('نام به انگلیسی','crm-plugin').'<span class=""></span></label>';
        echo '<input type="text" id="first_name_en" name="first_name_en" value="'.esc_attr($get_meta('first_name_en')).'">';
      echo '</div>';
      echo '<div class="cbif-field">';
        echo '<label for="last_name_en">'.__('نام خانوادگی به انگلیسی','crm-plugin').'<span class=""></span></label>';
        echo '<input type="text" id="last_name_en" name="last_name_en" value="'.esc_attr($get_meta('last_name_en')).'">';
      echo '</div>';

      // Row 3: Father / Birth Date / Birth Province→City
      echo '<div class="cbif-field">';
      echo '<label for="father_name">'.__('نام پدر','crm-plugin').'<span class="required">*</span></label>';
      echo '<input type="text" id="father_name" name="father_name" value="'.esc_attr($get_meta('father_name')).'">';
      echo '</div>';
      echo '<div class="cbif-field">';
      echo '<label for="birth_date">'.__('تاریخ تولد','crm-plugin').'<span class="required">*</span></label>';
      echo '<input type="text" id="birth_date" name="birth_date" class="persian-date" data-jdp data-jdp-only-date value="'.esc_attr($get_meta('birth_date')).'">';
      echo '</div>';
      echo '<div class="cbif-field">';
      echo '<label>'.__('استان/شهرستان تولد','crm-plugin').'<span class="required">*</span></label>';
        // get saved values
          $birth_province = $get_meta('birth_province');
          $birth_city     = $get_meta('birth_city');
        
          // render province select as before
          echo '<select id="birth_province" class="crm-select2" name="birth_province">';
          echo '<option value="">— انتخاب کنید —</option>';
          foreach ( $provinces as $code => $name ) {
              $sel = ( $birth_province === $code ) ? ' selected' : '';
              echo "<option value='" . esc_attr( $code ) . "'{$sel}>" . esc_html( $name ) . "</option>";
          }
          echo '</select>';
        
          // now dynamically build the city select server‐side
          $cities_for_prov = crm_get_cities_for_province($birth_province) ?? [];
        
          echo '<select id="birth_city" name="birth_city" class="crm-select2" style="margin-top:8px;"'
               . ( empty( $cities_for_prov ) ? ' disabled' : '' ) . '>';
          if ( empty( $cities_for_prov ) ) {
              echo '<option>— ابتدا استان را انتخاب کنید —</option>';
          } else {
              echo '<option value="">— انتخاب کنید —</option>';
              foreach ( $cities_for_prov as $city ) {
                  $sel = ( $birth_city === $city ) ? ' selected' : '';
                  printf(
                    "<option value='%s'%s>%s</option>",
                    esc_attr( $city ),
                    $sel,
                    esc_html( $city )
                  );
              }
          }
          echo '</select>';
      echo '</div>';

      // Row 4: Marital / Education / Military
      echo '<div class="cbif-field">';
        echo '<label for="marital_status">'.__('وضعیت تأهل','crm-plugin').'<span class="required">*</span></label>';
        echo '<select id="marital_status" name="marital_status">
                <option value="">— انتخاب کنید —</option>
                <option value="single"'.($get_meta('marital_status')==='single'?' selected':'').'>مجرد</option>
                <option value="married"'.($get_meta('marital_status')==='married'?' selected':'').'>متأهل</option>
              </select>';
      echo '</div>';
        echo '<div class="cbif-field">';
          echo '<label for="education_status">'.__('وضعیت تحصیلی','crm-plugin').'<span class="required">*</span></label>';
          echo '<select id="education_status" name="education_status" style="font-size:14px;" class="form-control text-right rtl">';
            echo '<option value="">— انتخاب کنید —</option>';
            echo '<option value="student_primary"     '.($get_meta('education_status')==='student_primary'?' selected':'').'>محصل - ابتدایی</option>';
            echo '<option value="student_highschool"  '.($get_meta('education_status')==='student_highschool'?' selected':'').'>محصل - دبیرستان</option>';
            echo '<option value="diploma"             '.($get_meta('education_status')==='diploma'?' selected':'').'>دیپلم</option>';
            echo '<option value="associate"           '.($get_meta('education_status')==='associate'?' selected':'').'>کاردانی</option>';
            echo '<option value="bachelor"           '.($get_meta('education_status')==='bachelor'?' selected':'').'>کارشناسی</option>';
            echo '<option value="master"              '.($get_meta('education_status')==='master'?' selected':'').'>کارشناسی ارشد</option>';
            echo '<option value="phd"                 '.($get_meta('education_status')==='phd'?' selected':'').'>دکتری و بالاتر</option>';
          echo '</select>';
        echo '</div>';
        echo '<div class="cbif-field">';
          echo '<label for="military_status">'.__('وضعیت خدمت وظیفه','crm-plugin').'<span class="required">*</span></label>';
          echo '<select id="military_status" name="military_status" style="font-size:14px;" class="form-control text-right rtl">';
            // placeholder
            echo '<option value="">— انتخاب کنید —</option>';
            // پایان خدمت
            echo '<option value="completed"     '.($get_meta('military_status')==='completed'?' selected':'').'>پایان خدمت</option>';
            // معافیت
            echo '<option value="exempt"        '.($get_meta('military_status')==='exempt'   ?' selected':'').'>معافیت</option>';
            // معافیت پزشکی
            echo '<option value="exempt_medical"       '.($get_meta('military_status')==='exempt_medical'?' selected':'').'>معافیت پزشکی</option>';
            // معافیت غیر پزشکی
            echo '<option value="exempt_non_medical"   '.($get_meta('military_status')==='exempt_non_medical'?' selected':'').'>معافیت غیر پزشکی</option>';
            // معافیت رهبری
            echo '<option value="exempt_leadership"    '.($get_meta('military_status')==='exempt_leadership'?' selected':'').'>معافیت رهبری</option>';
            // انجام نداده
            echo '<option value="not_performed" '.($get_meta('military_status')==='not_performed'?' selected':'').'>انجام نداده</option>';
            // اشتغال به تحصیل
            echo '<option value="studying"      '.($get_meta('military_status')==='studying'?' selected':'').'>اشتغال به تحصیل</option>';
            // اشتغال به خدمت – طلبه
            echo '<option value="seminarian"    '.($get_meta('military_status')==='seminarian'?' selected':'').'>اشتغال به خدمت – طلبه</option>';
          echo '</select>';
        echo '</div>';


      // Row 5: Residence Province→City / Postal / Village
      echo '<div class="cbif-field">';
        echo '<label>'.__('استان/شهرستان اقامت','crm-plugin').'<span class="required">*</span></label>';
         // get saved values
          $res_province = $get_meta('residence_province');
          $res_city     = $get_meta('residence_city');
        
          // province select
          echo '<select id="residence_province" class="crm-select2" name="residence_province">';
          echo '<option value="">— انتخاب کنید —</option>';
          foreach ( $provinces as $code => $name ) {
              $sel = ( $res_province === $code ) ? ' selected' : '';
              echo "<option value='" . esc_attr( $code ) . "'{$sel}>" . esc_html( $name ) . "</option>";
          }
          echo '</select>';
        
          // server-side city select
          $cities_for_res = crm_get_cities_for_province( $res_province ) ?? [];
        
          echo '<select id="residence_city" name="residence_city" class="crm-select2" style="margin-top:8px;"'
               . ( empty( $cities_for_res ) ? ' disabled' : '' ) . '>';
          if ( empty( $cities_for_res ) ) {
              echo '<option>— ابتدا استان را انتخاب کنید —</option>';
          } else {
              echo '<option value="">— انتخاب کنید —</option>';
              foreach ( $cities_for_res as $city ) {
                  $sel = ( $res_city === $city ) ? ' selected' : '';
                  printf(
                    "<option value='%s'%s>%s</option>",
                    esc_attr( $city ),
                    $sel,
                    esc_html( $city )
                  );
              }
          }
          echo '</select>';
      echo '</div>';
      echo '<div class="cbif-field">';
        echo '<label for="postal_code">'.__('کد پستی','crm-plugin').'</label>';
        echo '<input type="text" id="postal_code" name="postal_code" value="'.esc_attr($get_meta('postal_code')).'">';
      echo '</div>';

      // Row 6: Address (wide)
      echo '<div class="cbif-field cbif-wide">';
        echo '<label for="residence_address">'.__('آدرس محل سکونت','crm-plugin').'<span class="required">*</span></label>';
        echo '<textarea id="residence_address" name="residence_address">'.esc_textarea($get_meta('residence_address')).'</textarea>';
      echo '</div>';

      // Row 7: Email (wide)
       echo '<div class="cbif-field">';
        echo '<label for="billing_phone">'.__('موبایل','crm-plugin').'<span class="required">*</span></label>';
        echo '<input type="tel" id="billing_phone" name="billing_phone" value="'.esc_attr($get_meta('billing_phone')).'" pattern="9[0-9]{9}">';
      echo '</div>';
      echo '<div class="cbif-field">';
        echo '<label for="billing_email">'.__('ایمیل','crm-plugin').'</label>';
        echo '<input type="email" id="billing_email" name="billing_email" value="'.esc_attr($user->user_email).'">';
      echo '</div>';
      
    $field( 'iban',        'شماره شبا (بدون IR)',   'text',  false  );
    $field( 'card_number', 'شماره کارت',  'text',  false  );
    
    $confirmed_coaches = crm_get_confirmed_coaches();       // helper #2
    $field( 'coach_id', 'انتخاب مربی', 'select', false, $confirmed_coaches );
    
    $clubs = get_users( [
        'role'      => 'club',
        'meta_key'  => 'club_name',
        'orderby'   => 'meta_value',
        'order'     => 'ASC',
        'fields'    => [ 'ID' ],   // we only need the IDs
    ] );
    $club_opts = [ '' => '— انتخاب باشگاه —' ];
    foreach ( $clubs as $u ) {
        $club_opts[ $u->ID ] = get_user_meta( $u->ID, 'club_name', true );
    }
    $field( 'club_id', 'انتخاب باشگاه', 'select', false, $club_opts );

      echo '<br>';
      // Submit button
      echo '<div class="cbif-submit">';
        wp_nonce_field( 'save_basic_info', 'save_basic_info_nonce' );
        echo '<button type="submit" name="save_basic_info">'.__( 'ذخیره اطلاعات','crm-plugin').'</button>';
      echo '</div>';

    echo '</div>'; // .cbif-grid
    echo '</form>';

    // Inline JS
    ?>
    <?php
}


// 2) Save handler (with basic validation)
add_action( 'template_redirect', 'myacc_handle_basic_info_save' );
function myacc_handle_basic_info_save() {
    if ( ! isset( $_POST['save_basic_info'] ) || ! is_user_logged_in() ) return;
    if ( ! wp_verify_nonce( $_POST['save_basic_info_nonce'], 'save_basic_info' ) ) return;

    $uid = get_current_user_id();
    $errors = [];

    // Sanitization map: meta_key => callback
    $map = [
        'billing_phone'     => 'sanitize_text_field',
        'national_id'       => 'sanitize_text_field',
        'first_name_fa'     => 'sanitize_text_field',
        'last_name_fa'      => 'sanitize_text_field',
        'first_name_en'     => 'sanitize_text_field',
        'last_name_en'      => 'sanitize_text_field',
        'gender'            => 'sanitize_text_field',
        'father_name'       => 'sanitize_text_field',
        'birth_date'        => 'sanitize_text_field',
        'birth_province'    => 'sanitize_text_field',
        'birth_city'        => 'sanitize_text_field',
        'marital_status'    => 'sanitize_text_field',
        'education_status'  => 'sanitize_text_field',
        'military_status'   => 'sanitize_text_field',
        'residence_province'=> 'sanitize_text_field',
        'residence_city'    => 'sanitize_text_field',
        'postal_code'       => 'sanitize_text_field',
        'village'           => 'sanitize_text_field',
        'residence_address' => 'sanitize_textarea_field',
        'billing_email'     => 'sanitize_email',
        'iban'        => 'sanitize_text_field',
        'card_number' => 'sanitize_text_field',
        'coach_id'    => 'intval',
        'club_id'     => 'intval',
    ];

    foreach ( $map as $key => $san_cb ) {
      if ( ! isset( $_POST[ $key ] ) ) continue;
      $val = call_user_func( $san_cb, $_POST[ $key ] );
      // simple required check
        $is_required = in_array( $key, [
            'billing_phone','national_id','first_name_fa','last_name_fa','gender','father_name',
            'birth_date','birth_province','birth_city','marital_status','education_status',
            'residence_province','residence_city','residence_address'
        ], true );
        
        // military_status is required only for men
        if ( $key === 'military_status' && $gender === 'male' ) {
            $is_required = true;
        }
        
        if ( $is_required && $val === '' ) {
            $errors[] = sprintf( __( 'فیلد «%s» اجباری است.', 'crm-plugin' ), $key );
            continue;
        }

        if (!empty($val) && $key === 'iban' && ! preg_match( '/^[0-9]{24}$/', $val ) )
             $errors[] = 'شماره شبا نامعتبر است.';
        if (!empty($val) && $key === 'card_number' && ! preg_match( '/^[0-9]{16}$/', $val ) )
            $errors[] = 'شماره کارت نامعتبر است.';
    
      // handle email & phone
      if (!empty($val) && 'billing_email' === $key ) {
        wp_update_user([ 'ID'=> $uid, 'user_email'=> $val ]);
        update_user_meta( $uid, 'billing_email', $val );
      } else {
        update_user_meta( $uid, $key, $val );
      }
    }

    if ( $errors ) {
      foreach ( $errors as $e ) wc_add_notice( $e, 'error' );
    } else {
      wc_add_notice( __( 'اطلاعات با موفقیت ذخیره شد.', 'crm-plugin' ), 'success' );
      wp_redirect( wc_get_account_endpoint_url( 'edit-basic-info' ) );
      exit;
    }
}

// 1) Register endpoint (if not already)
add_action( 'woocommerce_account_identity-professional_endpoint', 'crm_identity_professional_endpoint_content' );
function crm_identity_professional_endpoint_content() {
    echo do_shortcode( '[crm_identity_professional]' );
}


// 2) Shortcode
add_shortcode( 'crm_identity_professional', 'crm_identity_verification_professional_shortcode' );
function crm_identity_verification_professional_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '<p>' . __( 'لطفاً وارد شوید تا بتوانید اطلاعات را ثبت اطلاعات کنید.', 'crm-plugin' ) . '</p>';
    }
    
    
    $user_id         = get_current_user_id();
    $verified        = get_user_meta( $user_id, 'identity_verified_professional', true );
    $rejection       = get_user_meta( $user_id, 'identity_rejection_reason_professional', true );
    // exact order & labels
    $fields = [
        'personal_photo'          => __( 'تصویر پرسنلی',               'crm-plugin' ),
        'birth_certificate'       => __( 'تصویر شناسنامه',            'crm-plugin' ),
        'national_id_card'        => __( 'تصویر کارت ملی',                 'crm-plugin' ),
        'education_certificate'   => __( 'تصویر آخرین مدرک تحصیلی',   'crm-plugin' ),
        'military_service_status'    => __( 'تصویر کارت پایان خدمت/معافیت/اشتغال به تحصیل',  'crm-plugin' ),
    ];
    
              
      if ( ! crm_user_has_basic_info( $user_id ) ) {
        return '<div class="notice-warning">' . __( 'ابتدا اطلاعات پایه را تکمیل کنید. سپس پس از تایید مدیر نسبت به تکمیل این فرم اقدام نمایید.', 'crm-plugin' ) . '</div>';
      }
      

    // collect per-field messages
    $msgs = [];

    // handle individual form submissions
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
        foreach ( $fields as $field => $label ) {
            if ( isset( $_POST[ 'submit_' . $field ] ) ) {
                // nonce
                if ( ! isset( $_POST[ 'crm_' . $field . '_nonce' ] ) ||
                     ! wp_verify_nonce( $_POST[ 'crm_' . $field . '_nonce' ], 'crm_' . $field . '_action' ) ) {
                    $msgs[ $field ] = [ 'error' => __( 'اعتبارسنجی فرم ناموفق بود. لطفاً مجدداً تلاش کنید.', 'crm-plugin' ) ];
                }
                // file required
                elseif ( empty( $_FILES[ $field ]['name'] ) ) {
                    $msgs[ $field ] = [ 'error' => __( 'فایل الزامی است.', 'crm-plugin' ) ];
                }
                else {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    // custom upload dir per user
                    add_filter( 'upload_dir', function( $dirs ) use ( $user_id ) {
                        $sub = '/identity_verification/user_' . $user_id;
                        $dirs['subdir'] = $sub;
                        $dirs['path']   = $dirs['basedir'] . $sub;
                        $dirs['url']    = $dirs['baseurl'] . $sub;
                        if ( ! file_exists( $dirs['path'] ) ) {
                            wp_mkdir_p( $dirs['path'] );
                        }
                        return $dirs;
                    } );
                    $up = wp_handle_upload( $_FILES[ $field ], [ 'test_form' => false ] );
                    remove_all_filters( 'upload_dir' );

                    if ( isset( $up['url'] ) && empty( $up['error'] ) ) {
                        update_user_meta( $user_id, $field, $up['url'] );
                        update_user_meta( $user_id, 'identity_verified_professional', 'pending' );
                        delete_user_meta( $user_id, 'identity_rejection_reason_professional' );
                        $msgs[ $field ] = [ 'success' => __( 'فایل با موفقیت بارگذاری شد و در انتظار بررسی است.', 'crm-plugin' ) ];
                    } else {
                        $msgs[ $field ] = [ 'error'   => __( 'خطا در بارگذاری فایل. لطفاً مجدداً تلاش کنید.', 'crm-plugin' ) ];
                    }
                }
            }
        }
    }

    ob_start(); ?>
    <div class="crm-identity-verification-form">
      <style>
        *{
            font-family: "vaziri", Sans-serif !important;
        }
        .crm-identity-verification-form {
          direction: rtl;
          text-align: right;
          padding: 20px 0;
          max-width: 95%;
          background: white;
            padding: 10px;
        }
        .crm-identity-verification-form .notice-error,
        .crm-identity-verification-form .notice-success,
        .crm-identity-verification-form .notice-warning {
          padding: 10px;
          border-radius: 10px;
          margin-bottom: 10px;
        }
        .crm-identity-verification-form .notice-error   { background: #ff00008f; }
        .crm-identity-verification-form .notice-success { background: #00ffaa8f; }
        .crm-identity-verification-form .notice-warning { background: #ff92008f; }

        .upload-item {
          display: flex;
          align-items: center;
          border-top: 1px solid #e5e5e5;
          padding: 15px 0;
        }
        .upload-thumbnail {
          flex: 0 0 140px;
          margin-left: 10px;
          text-align:center;
        }
        .upload-thumbnail img {
          max-width: 100%;
          border-radius: 10px;
          box-shadow: -1px 1px 5px 4px #0000000d;
        }
        .upload-input {
          flex: 1;
        }
        .upload-input label {
          display: block;
          font-weight: 700;
          margin-bottom: 6px;
        }
        .custom-file-wrapper {
          position: relative;
          display: inline-block;
        }
        .custom-file-wrapper input[type=file] {
          opacity: 0;
          position: absolute;
          left: 0; top: 0;
          width: 100%; height: 100%;
          cursor: pointer;
        }
        .custom-file-btn {
          display: inline-block;
          padding: 6px 14px;
          background: #007bff;
          color: #fff;
          border: none;
          border-radius: 4px;
          cursor: pointer;
          font-size: 14px;
        }
        .file-name {
          display: inline-block;
          margin: 0 10px;
          font-size: 13px;
          color: #333;
          vertical-align: middle;
        }
        .upload-notice {
          font-size: 12px;
          color: #666;
          line-height: 1.4;
          margin: 6px 0;
        }
        .upload-error {
          color: #d00;
          margin-top: 4px;
          font-size: 12px;
        }
        .button-submit {
          background: transparent;
          border: 1px solid #007bff;
          color: #007bff;
          padding: 6px 16px;
          border-radius: 6px;
          cursor: pointer;
          margin-right: 10px;
          font-size: 14px;
        }
        .form-note {
          margin: 20px 0 10px;
          color: #d00;
          font-size: 13px;
          line-height: 1.5;
        }
        .form-disclaimer {
          margin: 10px 0;
          color: #999;
          font-size: 12px;
          line-height: 1.4;
        }
      </style>

      <script>
      document.addEventListener('DOMContentLoaded', function(){
        var noFile = '<?php echo esc_js( __( 'فایلی انتخاب نشده', 'crm-plugin' ) ); ?>';
        document.querySelectorAll('.custom-file-wrapper').forEach(function(wrap){
          var input = wrap.querySelector('input[type=file]');
          var btn   = wrap.querySelector('.custom-file-btn');
          var name  = wrap.querySelector('.file-name');
          btn.addEventListener('click', function(){ input.click(); });
          input.addEventListener('change', function(){
            name.textContent = input.files.length
              ? input.files[0].name
              : noFile;
          });
        });
      });
      </script>

      <?php
      // top status

      
      if ( $verified === 'approved' ) {
          echo '<div class="notice-success">' . __( 'هویت شما مورد تایید است و نمی‌توانید تغییرات ایجاد کنید.', 'crm-plugin' ) . '</div>';
      } elseif ( $verified === 'disapproved' && $rejection ) {
          echo '<div class="notice-error">' . __( 'دلیل رد هویت:', 'crm-plugin' ) . ' ' . esc_html( $rejection ) . '</div>';
      } elseif ( $verified === 'pending' ) {
          echo '<div class="notice-warning">' . __( 'اطلاعات هویتی شما در حال بررسی است.', 'crm-plugin' ) . '</div>';
      }

      // each field as its own form
      foreach ( $fields as $field => $label ) :
          $url = get_user_meta( $user_id, $field, true );
          $has = $url ? esc_url( $url ) : '';
      ?>
        <form class="upload-item needs-swal" method="post" enctype="multipart/form-data" action="<?php echo esc_url( add_query_arg( null, null ) ); ?>">
                  <div class="upload-input">
            <label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label>
            <div class="custom-file-wrapper">
              <button type="button" class="custom-file-btn"><?php _e( 'انتخاب فایل', 'crm-plugin' ); ?></button>
              <input type="file"
                     name="<?php echo esc_attr( $field ); ?>"
                     id="<?php echo esc_attr( $field ); ?>"
                     accept=".jpg,.jpeg,.png,.pdf">
            </div>
            <span class="file-name"><?php _e( 'فایلی انتخاب نشده', 'crm-plugin' ); ?></span>
            <?php if ( $field === 'personal_photo' ) : ?>
    <div class="upload-notice">
      <p>تصویر حتما باید با پس زمینه سفید گرفته شده باشد.</p>
      <p>عکس پرسنلی حتما بصورت تمام رخ باشد.</p>
      <p>رعایت شئونات و عرف اسلام و جامعه در عکس‌ها الزامی می‌باشد.</p>
      <p>لطفا حتما، از گرفتن عکس از روی تصویر فیزیکی (مانند عکس شناسنامه، کارت ملی و غیره) و ارسال آن اجتناب نمائید. یا بصورت مستقیم اقدام به عکس برداری از چهره خود نمائید و یا عکس پرسنلی خود را اسکن کرده و فایل آن را آپلود فرمائید.</p>
      <p>حداکثر حجم فایل‌ها، یک مگابایت می‌باشد.</p>
      <p>فایل‌هایی با پسوندهای jpg، jpeg و pdf مجاز می‌باشد.</p>
    </div>
  <?php else: ?>
    <div class="upload-notice">
      <p>حداکثر حجم فایل 1 مگابایت است.</p>
      <p>فرمت‌های مجاز: jpg، jpeg، png، pdf.</p>
    </div>
  <?php endif; ?>
            <?php if ( isset( $msgs[ $field ] ) ) :
                $type = isset( $msgs[ $field ]['error'] ) ? 'upload-error' : 'upload-success';
                $text = reset( $msgs[ $field ] );
            ?>
              <p class="<?php echo $type; ?>"><?php echo esc_html( $text ); ?></p>
            <?php endif; ?>
          </div>
          <div class="upload-thumbnail">
            <?php if ( $has ): ?>
              <a href="<?php echo $has; ?>" target="_blank"><img src="<?php echo $has; ?>" alt=""></a>
            <?php else: ?>
              <img src="https://irimao.com/wp-content/plugins/Shahkar/user_page/dashboard/assets/images/profile.jpg" alt="">
            <?php endif; ?>
                <div style="margin-top:10px;">
                      <?php wp_nonce_field( 'crm_' . $field . '_action', 'crm_' . $field . '_nonce' ); ?>
                      <button type="submit" name="submit_<?php echo esc_attr( $field ); ?>" class="button-submit">
                        <?php _e( 'ثبت اطلاعات', 'crm-plugin' ); ?>
                      </button>
                </div>
          </div>
        </form>
      <?php endforeach; ?>

      <div class="form-note">
        <?php _e( 'لطفاً پس از بارگذاری هر فایل، دکمه ثبت اطلاعات را بزنید تا فایل شما ثبت شود.', 'crm-plugin' ); ?>
      </div>
      <div class="form-disclaimer">
        <?php _e('مسئولیت هرگونه مغایرت اطلاعات وارد شده در این صفحه با فایل‌ها و مستندات آپلود شده در سیستم، کاملا بعهده کاربر بوده و در صورت مشاهده مغایرت، این امر تخلف شمرده شده و احتمال مسدود شدن حساب کاربری وجود خواهد داشت.', 'crm-plugin' ); ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

// === 1) Register endpoint & add to My Account menu ===
add_action('init', function(){
  add_rewrite_endpoint('self-declare', EP_PAGES|EP_ROOT);
});
add_filter('woocommerce_account_menu_items', function($items){
  $items = array_slice($items,0,1,true)
         + ['self-declare'=>'خوداظهاری']
         + array_slice($items,1,null,true);
  return $items;
});
add_action('woocommerce_account_self-declare_endpoint', function(){
  echo do_shortcode('[crm_self_declaration]');
});

// === 2) Register CPT for storing submissions ===
add_action('init', function(){
  register_post_type('self_declaration', [
    'label'           => 'خوداظهاری‌ها',
    'public'          => false,
    'show_ui'         => false,
    'capability_type' => 'post',
    'supports'        => ['title'],
  ]);

    register_post_type( 'club_application', [
        'label'   => 'درخواست ثبت باشگاه',
        'public'  => false,
        'show_ui' => false,
        'supports'=> [ 'title' ],
    ]);
});

// === 3) Shortcode for form + list ===
add_shortcode('crm_self_declaration','crm_self_declaration_handler');
function crm_self_declaration_handler(){
  if(!is_user_logged_in()){
    return '<p style="text-align:center;color:#c00;">'.__('لطفاً وارد شوید.','crm-plugin').'</p>';
  }

  $user_id = get_current_user_id();
  $out = '';

  if ( ! function_exists('crm_user_has_basic_info') || ! crm_user_has_basic_info( $user_id ) ) {
    return '<div class="notice-warning">ابتدا اطلاعات پایه را تکمیل و تأیید شده باید باشد.</div>';
  }

  // — Option lists —
  $coursetypes = [
    1  => 'فنی',
    2  => 'داوری',
    3  => 'مربیگری',
    4  => 'قهرمانی',
    6  => 'بازآموزی',
    7  => 'دوره آموزشی',
    10 => 'کارورزی (عملی)',
    13 => 'استاژ فنی',
    14 => 'تربیت مدرس داوری',
  ];

  // Degrees per course type
  $degreeOptions = [
    // 1: فنی
    1 => [
      '10'=>'دان ۱','11'=>'دان ۲','12'=>'دان ۳','13'=>'دان ۴','14'=>'دان ۵','15'=>'دان ۶','16'=>'دان ۷','17'=>'دان ۸','18'=>'دان ۹','19'=>'دان ۱۰',
      '20'=>'رده ۱','21'=>'رده ۲','22'=>'رده ۳','23'=>'رده ۴','24'=>'رده ۵','25'=>'رده ۶','26'=>'رده ۷','27'=>'رده ۸','28'=>'رده ۹','29'=>'رده ۱۰',
      '30'=>'خان ۸','31'=>'خان ۹','32'=>'خان ۱۰','33'=>'خان ۱۱','34'=>'خان ۱۲','35'=>'خان ۱۳','36'=>'خان ۱۴','37'=>'خان ۱۵','38'=>'خان ۱۶','39'=>'خان ۱۷',
      '40'=>'آمباس ۸','41'=>'آمباس ۹','42'=>'آمباس ۱۰','43'=>'آمباس ۱۱','44'=>'آمباس ۱۲','45'=>'آمباس ۱۳','46'=>'آمباس ۱۴','47'=>'آمباس ۱۵','48'=>'آمباس ۱۶','49'=>'آمباس ۱۷',
      '50'=>'کروما ۱','51'=>'کروما ۲','52'=>'کروما ۳','53'=>'کروما ۴','54'=>'کروما ۵','55'=>'کروما ۶','56'=>'کروما ۷','57'=>'کروما ۸','58'=>'کروما ۹','59'=>'کروما ۱۰',
      '61'=>'آبی با دو خط','62'=>'آبی با سه خط','63'=>'آبی با چهار خط','64'=>'بنفش با یک خط','65'=>'بنفش با دو خط','66'=>'بنفش با سه و چهار خط','67'=>'قهوه ای با یک خط','68'=>'قهوه ای با دو تا چهارخط','69'=>'کمربند مشکی','70'=>'مشکی با باند قرمز',
      '74'=>'گام ۱','75'=>'گام ۲','76'=>'گام ۳','77'=>'گام ۴','78'=>'گام ۵','79'=>'گام ۶','80'=>'گام ۷','81'=>'گام ۸','82'=>'گام ۹','83'=>'گام ۱۰',
      '84'=>'کن دو ۱','85'=>'کن دو ۲','86'=>'کن دو ۳','87'=>'کن دو ۴','88'=>'کن دو ۵','89'=>'کن دو ۶','90'=>'کن دو ۷','91'=>'کن دو ۸','92'=>'کن دو ۹','93'=>'کن دو ۱۰',
      '94'=>'ای آی دو ۱','95'=>'ای آی دو ۲','96'=>'ای آی دو ۳','97'=>'ای آی دو ۴','98'=>'ای آی دو ۵','99'=>'ای آی دو ۶','100'=>'ای آی دو ۷','101'=>'ای آی دو ۸','102'=>'ای آی دو ۹','103'=>'ای آی دو ۱۰',
      '121'=>'سربند ۱','123'=>'سربند ۳','124'=>'سربند ۴','125'=>'سربند ۵','126'=>'سربند ۶','127'=>'سربند ۷','128'=>'سربند ۸','129'=>'سربند ۹','130'=>'سربند ۱۰',
      '134'=>'ستاره تک','135'=>'ستاره دو','136'=>'ستاره سه','137'=>'سربند ۲',
      '138'=>'ستاره چهار','139'=>'ستاره پنج','140'=>'ستاره شش','141'=>'ستاره هفت','142'=>'ستاره هشت','143'=>'ستاره نه','144'=>'ستاره ده',
      '152'=>'(ستاره) تک','153'=>'(ستاره) دو','154'=>'(ستاره) سه','155'=>'(ستاره) چهار','156'=>'(ستاره) پنج',
      '157'=>'سطح ۱','158'=>'سطح ۲','159'=>'سطح ۳','160'=>'سطح ۴','161'=>'سطح ۵','162'=>'سطح ۶','163'=>'سطح ۷','164'=>'سطح ۸','165'=>'سطح ۹','166'=>'سطح ۱۰',
      '168'=>'((ستاره)) تک','169'=>'((ستاره)) دو','170'=>'((ستاره)) سه','171'=>'((ستاره)) چهار','172'=>'((ستاره)) پنج',
    ],
    // 2: داوری
    2 => [
      '2'=>'درجه ۳','3'=>'درجه ۲','4'=>'درجه ۱','110'=>'درجه ملی کیک بوکسینگ'
    ],
    // 3: مربیگری
    3 => [
      '111'=>'درجه ۳','112'=>'درجه ۲','113'=>'درجه ۱'
    ],
    // 4: قهرمانی
    4 => [
      '116'=>'مقام اول','117'=>'مقام دوم','118'=>'مقام سوم','119'=>'مقام سوم مشترک'
    ],
    // 6: بازآموزی
    6 => [
      '107'=>'درجه سبکی - داوری','109'=>'درجه  هیاًت -  ملی کیک بوکسینگ','176'=>'درجه درجه فدراسیون - ملی کیک بوکسیبنگ'
    ],
    // 7: دوره آموزشی → no degrees (hide field)
    7 => [],
    // 10: کارورزی (عملی)
    10 => [
      '104'=>'درجه ۳','105'=>'درجه ۲','106'=>'درجه ۱'
    ],
    // 13: استاژ فنی
    13 => [
      '175'=>'درجه استاژ فنی '
    ],
    
    14 => [
      '180'=>'درجه مدرس داوری ',
      '181'=>'درجه دانش افزایی تربیت مدرس',
    ],
  ];

  $branches = (class_exists('WC_Countries') ? (new WC_Countries())->get_states('IR') : []);

  // — Handle form POST —
  if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['sd_check'])){

    // Nonce (optional but recommended)
    if ( ! isset($_POST['_sd_nonce']) || ! wp_verify_nonce($_POST['_sd_nonce'], 'crm_self_declaration') ) {
      return '<div class="woocommerce-error">امنیت فرم معتبر نیست. لطفاً دوباره تلاش کنید.</div>';
    }

    $errs = [];

    $selectedType   = isset($_POST['coursetype']) ? (int) $_POST['coursetype'] : 0;
    $selectedDegree = isset($_POST['degree']) ? (string) $_POST['degree'] : '';
    $degreeList     = $degreeOptions[$selectedType] ?? [];
    $degreeIsRequired = !empty($degreeList);

    // required base fields (degree handled separately)
    foreach(['coursetype','hokm_number','getdate','exam_date'] as $f){
      if(empty($_POST[$f])) $errs[] = "فیلد «{$f}» اجباری است.";
    }

    if ($degreeIsRequired && $selectedDegree === '') {
      $errs[] = "انتخاب «درجه دوره» اجباری است.";
    }
    if ($degreeIsRequired && $selectedDegree !== '' && !array_key_exists($selectedDegree, $degreeList)) {
      $errs[] = 'درجه انتخاب‌شده با نوع حکم هم‌خوانی ندارد.';
    }

    if (empty($_FILES['course_imageurl']['name'])){
      $errs[] = 'فایل حکم/مدرک الزامی است.';
    }

    // Upload
    if(empty($errs)){
      require_once ABSPATH.'wp-admin/includes/file.php';
      $up = wp_handle_upload($_FILES['course_imageurl'],['test_form'=>false]);
      if(isset($up['error'])) $errs[] = 'خطا در آپلود: '.$up['error'];
    }

    // Insert post
    if(empty($errs)){
      $pid = wp_insert_post([
        'post_type'   => 'self_declaration',
        'post_title'  => "خوداظهاری #{$user_id}-".time(),
        'post_status' => 'pending',
        'post_author' => $user_id
      ]);

      if($pid){
        // Save all posted values
        foreach([
          'coursetype','degree','hokm_number','getdate','exam_date','theory_date',
          'boards','styles','workshop_title','workshop_presenttype','workshop_hours',
          'age_group','age_group_etc','vazn','style_mosabeghat'
        ] as $k){
          if(isset($_POST[$k])){
            update_post_meta($pid,$k,sanitize_text_field($_POST[$k]));
          }
        }

        if (!empty($up['url'])) {
          update_post_meta($pid,'image_url',esc_url_raw($up['url']));
        }
        update_post_meta($pid,'submitted_at',current_time('mysql'));

        // Redirect to avoid resubmit
        wp_safe_redirect( wc_get_endpoint_url('self-declare','',wc_get_page_permalink('myaccount')) );
        exit;
      }
    } else {
      foreach($errs as $e){
        $out .= '<div class="woocommerce-error">'.esc_html($e).'</div>';
      }
    }
  }

  // — Enqueue inline CSS/JS & render form —
  ob_start(); ?>

  <style>
    *{ font-family:'vaziri' !important; }
    .sd-container{background:#fff;border:1px solid #e0e0e0;padding:20px;margin:30px auto;font-family:Vazir,droid;font-size:14px;direction:rtl;}
    .sd-header{border-radius:5px;background:#060097;color:#fff;padding:10px;font-size:18px;font-weight:600;}
    .sd-instruction{margin:15px 0;color:#444;line-height:1.6;margin-bottom:20px !important;}
    .sd-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;}
    .sd-field{display:flex;flex-direction:column;}
    .sd-field label{font-weight:500;margin-bottom:6px;}
    .sd-field input,.sd-field select,.sd-field textarea{padding:8px;border:1px solid #ccc !important;border-color:#bdbdbd !important;border-radius:4px;background:#fafafa !important;font-size:14px;}
    .sd-field .helper-note{margin-top:4px;font-size:12px;background:#e6f7ff;color:#0050b3;padding:4px 6px;border-radius:3px;}
    .sd-divider{border:0;border-top:1px solid #ddd;margin:20px 0;}
    .sd-submit{text-align:left;}
    .sd-submit button{background:#1890ff;color:#fff;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:15px;}
    .sd-warning{color:#ff4d4f;font-size:13px;margin-top:12px;line-height:1.4;}
    .sd-disclaimer{color:#888;font-size:12px;margin-top:8px;line-height:1.5;}
  </style>

  <script>
    jQuery(function($){
      // Show/hide optional sections if they exist
      $('#coursetype').on('change',function(){
        $('#kargah').toggle(this.value==3);
        $('#ghahremani').toggle(this.value==4);
      }).trigger('change');

      // Degrees map from PHP
      const DEGREE_MAP = <?= wp_json_encode($degreeOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

      const $type   = $('#coursetype');
      const $degree = $('#degree'); // select[name="degree"]
      const preselected = '<?= esc_js($_POST['degree'] ?? '') ?>';

      function fillDegrees(){
        const t = $type.val();
        const list = (DEGREE_MAP && DEGREE_MAP[t]) ? DEGREE_MAP[t] : {};

        $degree.empty();

        if (!list || Object.keys(list).length === 0) {
          $degree.append(new Option('— نوع حکم را انتخاب کنید —',''));
          $degree.prop('required', false);
          //$('#degree_field').hide();
        } else {
          $('#degree_field').show();
          $degree.prop('required', true);
          $degree.append(new Option('— انتخاب کنید —',''));
          Object.entries(list).forEach(([val,label])=>{
            $degree.append(new Option(label, val));
          });
          if (preselected && (preselected in list)) {
            $degree.val(preselected);
          }
        }

        if ($.fn.select2) {
          $degree.trigger('change.select2');
        }
      }

      $type.on('change', fillDegrees);
      fillDegrees();
    });
  </script>

  <div class="sd-container">
    <div class="sd-header">خوداظهاری</div>
    <p class="sd-instruction">
      چنانچه احکام و مدارکی دارید که در لیست احکام ثبت شده شما نمایش داده نشده است، از طریق فرم زیر آن‌ها را اظهار نموده تا توسط کمیته آموزش سبک بررسی شده و در صورت تایید، در سیستم برای شما ثبت شود.
    </p>

    <form class="needs-swal" method="POST" enctype="multipart/form-data">
      <?php wp_nonce_field('crm_self_declaration','_sd_nonce'); ?>
      <input type="hidden" name="sd_check" value="1">

      <div class="sd-grid">
        <!-- نوع حکم -->
        <div class="sd-field">
          <label>نوع حکم<span style="color:#d00">*</span></label>
          <select class="crm-select2" id="coursetype" name="coursetype" required>
            <option value="">— انتخاب کنید —</option>
            <?php foreach($coursetypes as $v=>$l): ?>
              <option value="<?=esc_attr($v)?>" <?=selected($_POST['coursetype']??'',$v,false)?>><?=$l?></option>
            <?php endforeach;?>
          </select>
        </div>

        <!-- درجه دوره (dynamic) -->
        <div class="sd-field" id="degree_field">
          <label>درجه دوره<span style="color:#d00">*</span></label>
          <select class="crm-select2" name="degree" id="degree">
            <option value="">— ابتدا نوع حکم را انتخاب کنید —</option>
            <?php
              $selectedType   = isset($_POST['coursetype']) ? (int) $_POST['coursetype'] : 0;
              $selectedDegree = $_POST['degree'] ?? '';
              $degreeList     = $degreeOptions[$selectedType] ?? [];
              foreach($degreeList as $val=>$label):
            ?>
              <option value="<?=esc_attr($val)?>" <?=selected($selectedDegree, (string)$val, false)?>><?=$label?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- شماره حکم -->
        <div class="sd-field">
          <label>شماره حکم<span style="color:#d00">*</span></label>
          <input type="text" name="hokm_number" required value="<?=esc_attr($_POST['hokm_number']??'')?>">
        </div>

        <!-- تاریخ اخذ حکم -->
        <div class="sd-field">
          <label>تاریخ اخذ حکم<span style="color:#d00">*</span></label>
          <input type="text" name="getdate" class="persian-date" data-jdp-only-date data-jdp required value="<?=esc_attr($_POST['getdate']??'')?>">
        </div>

        <!-- تاریخ دوره/آزمون -->
        <div class="sd-field">
          <label>تاریخ دوره/آزمون عملی<span style="color:#d00">*</span></label>
          <input type="text" name="exam_date" class="persian-date" data-jdp-only-date data-jdp required value="<?=esc_attr($_POST['exam_date']??'')?>">
          <div class="helper-note">در ثبت قهرمانی، تاریخ مسابقه نهایی ثبت شود.</div>
        </div>

        <!-- تاریخ تئوری -->
        <div class="sd-field">
          <label>تاریخ تئوری</label>
          <input type="text" name="theory_date" class="persian-date" data-jdp-only-date data-jdp value="<?=esc_attr($_POST['theory_date']??'')?>">
          <div class="helper-note">این فیلد فقط برای دوره های مربیگری تکمیل شود.</div>
        </div>

        <!-- انتخاب تصویر -->
        <div class="sd-field">
          <label>انتخاب تصویر حکم/مدرک<span style="color:#d00">*</span></label>
          <input type="file" name="course_imageurl" accept=".jpg,.jpeg,.png,.pdf" required>
          <div class="helper-note">حداکثر حجم 2MB. فرمت‌های jpg,jpeg,png,pdf.</div>
        </div>

        <!-- هیئت -->
        <div class="sd-field">
          <label>هیئت</label>
          <select class="crm-select2" name="boards">
            <option value="">— انتخاب کنید —</option>
            <?php foreach($branches as $c=>$n): ?>
              <option value="<?=esc_attr($c)?>" <?=selected($_POST['boards']??'',$c,false)?>><?=$n?></option>
            <?php endforeach;?>
          </select>
        </div>
      </div>

      <hr class="sd-divider">

      <div class="sd-submit">
        <button type="submit">ثبت و ارسال</button>
      </div>

      <p class="sd-warning">
        - حداکثر حجم فایل تصویر حکم/مدرک نباید از 2 مگابایت بیشتر باشد.<br>
        - فایل‌هایی با پسوند jpg, jpeg, png و pdf قابل قبول هستند.
      </p>
      <p class="sd-disclaimer">
        در صورتی که تاریخ حکم شما قبل از ۱۳۹۹/۰۶/۰۱ بوده و فاقد QRCode می‌باشد،
        بعد از تأیید فدراسیون، برای ثبت و جدیدسازی احکام باید مبلغ ۳۷۰۰۰ تومان
        پرداخت گردد.
      </p>
    </form>
  </div>

  <?php
  $out .= ob_get_clean();

  // — List past submissions —
  $q = new WP_Query([
    'post_type'      => 'self_declaration',
    'author'         => $user_id,
    'posts_per_page' => -1,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'post_status'    => 'any',
  ]);

  if($q->have_posts()){
    $out .= '<div class="sd-container"><div class="sd-header" style="margin-bottom:20px">در خواست های خود اظهاری من</div>';
    $out .= '<table class="shop_table" style="text-align:center">';
    $out .= '<thead><tr>'
          .'<th>#</th><th>نوع</th><th>درجه</th><th>شماره</th>'
          .'<th>تاریخ اخذ</th><th>تاریخ آزمون</th><th>هیئت</th>'
          .'<th>وضعیت</th><th>اقدام</th>'
          .'</tr></thead><tbody>';
    $i=1;
    while($q->have_posts()): $q->the_post();
      $pid = get_the_ID();
      $st  = get_post_status($pid);
      $lbl = $st==='publish'
        ?'<span style="color:green">تأیید شده</span>'
        :($st==='pending'
          ?'<span style="color:orange">در حال بررسی</span>'
          :'<span style="color:red">عدم تأیید</span>');

      $ct = (int) get_post_meta($pid,'coursetype',true);
      $dg = (string) get_post_meta($pid,'degree',true);
      $dgLabel = isset($degreeOptions[$ct][$dg]) ? $degreeOptions[$ct][$dg] : '—';

      $out .= '<tr>';
      $out .= "<td>{$i}</td>";
      $out .= '<td>'.esc_html(isset($coursetypes[$ct]) ? $coursetypes[$ct] : '—').'</td>';
      $out .= '<td>'.esc_html($dgLabel).'</td>';
      $out .= '<td>'.esc_html(get_post_meta($pid,'hokm_number',true)).'</td>';
      $out .= '<td>'.esc_html(get_post_meta($pid,'getdate',true)).'</td>';
      $out .= '<td>'.esc_html(get_post_meta($pid,'exam_date',true)).'</td>';
      $boardKey = (string) get_post_meta($pid,'boards',true);
      $out .= '<td>'.esc_html(isset($branches[$boardKey]) ? $branches[$boardKey] : '—').'</td>';
      $out .= "<td>{$lbl}</td>";
      $image_url = esc_url( get_post_meta( $pid, 'image_url', true ) );
      $out .= '<td>';
      $out .= $image_url ? '<a href="'.$image_url.'" target="_blank">🔍</a>' : '—';
      $out .= '</td>';
      $out .= '</tr>';
      $i++;
    endwhile;
    wp_reset_postdata();
    $out .= '</tbody></table></div>';
  }

  return $out;
}



add_action('woocommerce_account_style-committe_endpoint', function(){
  echo do_shortcode('[elementor-template id="4023"]');
});

add_action('woocommerce_account_self-declarations-list_endpoint', function(){
  echo do_shortcode('[crm_self_declarations_list]');
});


// 1) Register the shortcode
add_shortcode('crm_self_declarations_list','crm_self_declarations_list_handler');
function crm_self_declarations_list_handler(){
    if ( ! is_user_logged_in() ) {
        return '<p style="text-align:center;color:#c00;">'.__('لطفاً وارد شوید.','crm-plugin').'</p>';
    }
    $user_id = get_current_user_id();

    // — mappings (should match your form) —
    $coursetypes = [1=>'فنی',2=>'داوری',3=>'مربیگری',4=>'قهرمانی',6=>'بازآموزی',7=>'دوره آموزشی',10=>'کارورزی (عملی)',13=>'استاژ فنی'];

  // فقط ۶ رنگ پایه و دان‌های ۱ تا ۱۰
  $degrees = [
    'yellow' => 'زرد',
    'orange' => 'نارنجی',
    'green'  => 'سبز',
    'blue'   => 'آبی',
    'brown'  => 'قهوه‌ای',
    'black'  => 'مشکی',
    'dan1'   => 'دان 1',
    'dan2'   => 'دان 2',
    'dan3'   => 'دان 3',
    'dan4'   => 'دان 4',
    'dan5'   => 'دان 5',
    'dan6'   => 'دان 6',
    'dan7'   => 'دان 7',
    'dan8'   => 'دان 8',
    'dan9'   => 'دان 9',
    'dan10'  => 'دان 10',
  ];
    $branches = (new WC_Countries())->get_states('IR');

    // — view‐URL per type —
    $view_map = [
      1 => '../certificates/technical.php?hokm_id=',
      2 => '../certificates/referee2.php?hokm_id=',
      3 => '../certificates/coaching2.php?hokm_id=',
      4 => '../certificates/champion.php?hokm_id=',
      6 => '../certificates/recertify.php?hokm_id=',
      7 => '../certificates/referee-trainer.php?hokm_id=',
      10=> '../certificates/coaching2.php?hokm_id=',
      13=> '../certificates/technical.php?hokm_id=',
    ];

    // — fetch user submissions —
    $q = new WP_Query([
      'post_type'      => 'self_declaration',
      'author'         => $user_id,
      'posts_per_page' => -1,
      'orderby'        => 'date',
      'order'          => 'DESC'
    ]);

    if ( ! $q->have_posts() ) {
        return '<p style="text-align:center;">'.__('هیچ درخواست خوداظهاری‌ ثبت نکرده‌اید.','crm-plugin').'</p>';
    }

    // — start output —
    
    ?>
     <style>
        *{ font-family:'vaziri' !important; }
        .sd-container{background:#fff;border:1px solid #e0e0e0;padding:20px;margin:30px auto;font-family:Vazir,droid;font-size:14px;direction:rtl;}
        .sd-header{border-radius:5px;background:#060097;color:#fff;padding:10px;font-size:18px;font-weight:600;}
        .sd-header-warning{border-radius:5px;background:red;color:#fff;padding:10px;font-size:18px;font-weight:600;}
        .sd-instruction{margin:15px 0;color:#444;line-height:1.6;margin-bottom:20px !important;}
        .sd-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;}
        .sd-field{display:flex;flex-direction:column;}
        .sd-field label{font-weight:500;margin-bottom:6px;}
        .sd-field input,.sd-field select,.sd-field textarea{padding:8px;border:1px solid #ccc !important;border-color: #bdbdbd !important;border-radius:4px;background:#fafafa !important;font-size:14px;}
        .sd-field .helper-note{margin-top:4px;font-size:12px;background:#e6f7ff;color:#0050b3;padding:4px 6px;border-radius:3px;}
        .sd-divider{border:0;border-top:1px solid #ddd;margin:20px 0;}
        .sd-submit{text-align:left;}
        .sd-submit button{background:#1890ff;color:#fff;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:15px;}
        .sd-warning{color:#ff4d4f;font-size:13px;margin-top:12px;line-height:1.4;}
        .sd-disclaimer{color:#888;font-size:12px;margin-top:8px;line-height:1.5;}
      </style>
    <?php
    
    $out  = '<div class="sd-container"><div class="col-md-9">';
    $out .= ' <div class="sd-header" style="margin-bottom:20px">لیست احکام ثبت شده</div>';
    $out .= '<table class="table table-striped table-hover table-responsive-sm custom-table rtl tbl-loader" style="width:100%;">';
    $out .= '<thead style="background:#ff92008f;color:#000;">'
          .'<tr>'
          .'<th class="text-center" style="font-size:12px;text-align:center">ردیف</th>'
          .'<th class="text-center" style="font-size:12px;text-align:center">نوع حکم</th>'
          .'<th class="text-center" style="font-size:12px;text-align:center">درجه</th>'
          .'<th class="text-center" style="font-size:12px;text-align:center">تاریخ حکم</th>'
          .'<th class="text-center" style="font-size:12px;text-align:center">شماره حکم</th>'
          .'<th class="text-center" style="font-size:12px;text-align:center">نحوه ثبت حکم</th>'
          .'<th class="text-center" style="font-size:12px;text-align:center">اقدام</th>'
          .'</tr>'
          .'</thead>'
          .'<tbody>';

    $i = 1;
    while ( $q->have_posts() ): $q->the_post();
      $pid     = get_the_ID();
      $type    = get_post_meta($pid,'coursetype',true);
      $deg     = get_post_meta($pid,'degree',true);
      $date    = get_post_meta($pid,'getdate',true);
      $num     = get_post_meta($pid,'hokm_number',true);
      $method  = get_post_meta($pid,'registration_method',true) ?: 'خوداظهاری';

      // build view link
      $view_base = $view_map[$type] ?? $view_map[1];
      $view_url  = esc_url( $view_base . $pid );

      // payment link
      $pay_url   = esc_url( "certificate-payment.php?certificateId={$pid}" );

      $out .= '<tr>';
      $out .= '<td class="text-center" style="font-size:12px;text-align:center">'.$i.'</td>';
      $out .= '<td class="text-center" style="font-size:12px;text-align:center">'.esc_html($coursetypes[$type]??'').'</td>';
      $out .= '<td class="text-center" style="font-size:12px;text-align:center">'.esc_html($degrees[$deg]??'').'</td>';
      $out .= '<td class="text-center" style="font-size:12px;text-align:center">'.esc_html($date).'</td>';
      $out .= '<td class="text-center" style="font-size:12px;text-align:center">'.esc_html($num).'</td>';
      $out .= '<td class="text-center" style="font-size:12px;text-align:center">'.esc_html($method).'</td>';

      // actions
      $out .= '<td class="text-center" style="font-size:12px;">'
            ."<a href=\"{$view_url}\" target=\"_blank\" title=\"مشاهده حکم\" style=\"margin-left:5px;\">"
            ."<i class=\"fas fa-award fa-lg text-danger\"></i></a>"
            ."<a href=\"{$pay_url}\" title=\"درخواست صدور و ارسال حکم\">"
            ."<i class=\"fa fa-credit-card fa-lg fa-primary\"></i></a>"
            .'</td>';

      $out .= '</tr>';
      $i++;
    endwhile;
    wp_reset_postdata();

    $out .= '</tbody></table></div>';

    return $out;
}

add_action('woocommerce_account_smartcard-issue_endpoint', function(){
  echo do_shortcode('[crm_smartcard_issue]');
});



function wpw_get_wallet( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	return floatval( get_user_meta( $user_id, '_wallet_balance', true ) );
}
function wpw_set_wallet( $user_id, $amount ) {
	update_user_meta( $user_id, '_wallet_balance', (float) $amount );
}
function wpw_add_wallet( $user_id, $amount ) {
	wpw_set_wallet( $user_id, wpw_get_wallet( $user_id ) + (float) $amount );
}
function wpw_deduct_wallet( $user_id, $amount ) {
	wpw_set_wallet( $user_id, max( 0, wpw_get_wallet( $user_id ) - (float) $amount ) );
}


add_action( 'woocommerce_account_wallet_endpoint', function () {
	echo do_shortcode( '[crm_wallet]' );
} );



add_action( 'woocommerce_order_status_completed', function ( $order_id ) {

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$user_id = $order->get_customer_id();
	$topup   = $order->get_meta( 'wallet_topup' );

	if ( $user_id && $topup ) {
		wpw_add_wallet( $user_id, $topup );
		$order->add_order_note( "Wallet credited: {$topup}" );
	}
} );

add_shortcode( 'crm_smartcard_issue', 'crm_smartcard_issue_handler' );
function crm_smartcard_issue_handler() {

	/* دسترسی فقط برای کاربران لاگین‌شده */
	if ( ! is_user_logged_in() ) {
		return '<p style="text-align:center;color:#c00;">' . __( 'لطفاً وارد شوید.', 'crm-plugin' ) . '</p>';
	}

	$user_id = get_current_user_id();
	$wallet  = wpw_get_wallet( $user_id );   // موجودی فعلی

	/* ─────────  پردازش فرم  ───────── */
	if ( isset( $_POST['sc_submit'] ) ) {

		$want_post = ! empty( $_POST['want_post'] );
		$coupon    = sanitize_text_field( $_POST['coupon'] ?? '' );

        $base_amount = ir_price( 1000000 );            // 100 هزار تومان
        $post_amount = $want_post ? ir_price( 300000 ) : 0; // 30 هزار تومان

		try {

			$order = wc_create_order();

			/* آیتم هزینه کارت */
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( 'هزینه صدور / تمدید کارت' );
			$fee->set_amount( $base_amount );
			$fee->set_total(  $base_amount );
			$order->add_item( $fee );

			/* هزینه پست */
			if ( $post_amount ) {
				$post_fee = new WC_Order_Item_Fee();
				$post_fee->set_name( 'هزینه پست' );
				$post_fee->set_amount( $post_amount );
				$post_fee->set_total(  $post_amount );
				$order->add_item( $post_fee );
				$order->update_meta_data( 'smartcard_post', true );
			}

			/* کوپن تخفیف (اختیاری) */
			if ( $coupon ) {
				try { $order->apply_coupon( $coupon ); }
				catch ( Exception $e ) { wc_add_notice( 'کد تخفیف نامعتبر است.', 'error' ); }
			}

			$order->calculate_totals();
			$payable = $order->get_total();   // مبلغ پس از تخفیف

			/* ───── استفاده از کیف پول ───── */
			if ( $wallet >= $payable ) {

				// پرداخت کامل با کیف پول
				wpw_deduct_wallet( $user_id, $payable );
				$order->set_total( 0 );
				$order->payment_complete();
				$order->add_order_note( 'پرداخت کامل با کیف پول انجام شد.' );

				wc_add_notice( 'هزینه از کیف پول شما کسر و کارت در حال صدور است.', 'success' );
				wp_safe_redirect( wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ) );
				exit;

			} elseif ( $wallet > 0 ) {

				// پرداخت بخشی از کیف پول
				wpw_deduct_wallet( $user_id, $wallet );
				$order->set_total( $payable - $wallet );
				$order->add_order_note( "مبلغ {$wallet} ریال از کیف پول کسر شد." );
			}

			/* نهایی‌سازی سفارش برای پرداخت آنلاین باقیمانده */
			$order->set_customer_id( $user_id );
			$order->update_status( 'pending', 'Created by self-service' );
			$order->save();

			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;

		} catch ( Exception $e ) {
			wc_add_notice( 'خطا در ایجاد سفارش: ' . $e->getMessage(), 'error' );
		}
	}

	/* ─────────  فرم و خروجی HTML (بدون تغییر)  ───────── */
	ob_start(); ?>
	<style>
		*{font-family:"vaziri" !important;}
		.sc-container{background:#fff;border:1px solid #e0e0e0;padding:20px;margin:30px auto;direction:rtl;}
		.sc-header{background:#060097;color:#fff;padding:10px;font-size:18px;font-weight:600;}
		.sc-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-bottom:16px;}
		.sc-field{display:flex;flex-direction:column;}
		.sc-price{background:#eee;padding:8px;border-radius:4px;text-align:center;}
		.sc-button{background:#1890ff;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;}
		.sc-apply{background:#555;color:#fff;border:none;padding:8px 12px;margin-left:8px;border-radius:4px;cursor:pointer;}
	</style>

	<div class="sc-container">
		<div class="sc-header">صدور/تمدید کارت عضویت</div>

		<!-- نمایش موجودی کیف پول -->
		<p style="margin:8px 0;font-weight:600;">موجودی کیف پول شما: <span style="color:#28a745;"><?php echo wc_price( $wallet ); ?></span></p>

		<p class="sc-instruction">
برای دریافت کارت عضویت می توانید با پرداخت هزینه آن بصورت آنلاین نسبت به دریافت کارت اقدام کنید.
		</p>
		<p class="sc-warning">
			- قبل از درخواست صدور فیزیکی کارت، از بارگذاری صحیح تصویر پرسنلی خود اطمینان حاصل فرمایید.<br>
			- بر روی درخواست های صدور کارتی که فاقد تصویر پرسنلی باشند، اقدامی صورت نخواهد گرفت.
		</p>

		<form class="needs-swal" method="POST">
			<input type="hidden" name="sc_submit" value="1">

			<div class="sc-grid">
				<!-- کد تخفیف -->
				<div class="sc-field">
					<input type="text" name="coupon" placeholder="کد تخفیف را اینجا وارد کنید" value="<?php echo esc_attr( $_POST['coupon'] ?? '' ); ?>">
					<button class="sc-apply" type="submit" formaction="#">اعمال کد</button>
				</div>

				<!-- مبلغ ثابت نمایشی -->
				<div class="sc-field sc-price"><?php echo wc_price( ir_price( 1000000 ) ); ?></div>
			</div>

			<label style="display:flex;align-items:center;margin:8px 0;">
				<input type="checkbox" name="want_post" value="1" style="margin-left:6px;"> ارسال کارت فیزیکی (+30,000 تومان)
			</label>

			<button class="sc-button" type="submit">پرداخت آنلاین / استفاده از کیف پول</button>

			<p class="sc-disclaimer">
				پس از پرداخت آنلاین، کارت شما آماده‌سازی و برای شما ارسال خواهد شد.
			</p>
		</form>
	</div>
	<?php
	$out = ob_get_clean();  /* از خروجی بافر به متغیر برگردانده می‌شود */

	return $out;
}

function ir_price( $rial_amount ) {
	$currency      = get_option( 'woocommerce_currency' );
	$toman_currencies = [ 'IRT', 'TOMAN', 'IRHT', 'IRHR' ];

	return in_array( $currency, $toman_currencies, true )
		? $rial_amount / 10         // نمایش و کار با تومان
		: $rial_amount;             // واحد ریال
}




/* ============================================================
 *  ADMIN MENUS – Basic-Info & Professional Identity Management
 * ============================================================*/
 
/**
 * If the meta is a user-ID (club_id / coach_id) → return a nice label.
 */
function crm_display_name_or_value( $meta_key, $meta_value ) {

    if ( in_array( $meta_key, [ 'club_id', 'coach_id' ], true ) && $meta_value ) {
        /* ● club: prefer its stored club_name, else fall back to display_name */
        if ( $meta_key === 'club_id' ) {
            $club_name = get_user_meta( $meta_value, 'club_name', true );
            if ( $club_name ) {
                return $club_name;
            }
        }
        $u = get_userdata( (int) $meta_value );
        return $u ? $u->display_name : '';
    }
    return $meta_value;
}



# 1) add main menu + sub pages
add_action( 'admin_menu', function () {

	add_users_page(
		'اطلاعات پایه',
		'اطلاعات پایه',
		'manage_options',
		'crm-basic-info',
		'crm_basic_info_list_page'
	);

	add_users_page(
		'تأیید هویت حرفه‌ای',
		'هویت حرفه‌ای',
		'manage_options',
		'crm-prof-identity',
		'crm_prof_identity_page'
	);
	
	    add_users_page(
        'خوداظهاری‌ها',
        'خوداظهاری‌ها',   
        'manage_options', 
        'crm-self-declarations',  
        'crm_self_declarations_admin_page'
    );
    
    add_users_page( 'باشگاه‌ها', 'باشگاه‌ها', 'manage_options',
                    'crm-clubs', 'crm_club_admin_page' );
} );

# 2) enqueue admin assets only for our pages
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    $dir = get_stylesheet_directory_uri().'/assets';

	if ( isset($_GET['page']) && in_array( $_GET['page'], ['crm-basic-info','crm-prof-identity'], true ) ) {

		/* DataTables & Select2 from CDN */
        wp_enqueue_style(  'datatables', "$dir/css/jquery.dataTables.min.css");
        wp_enqueue_script( 'datatables', "$dir/js/jquery.dataTables.min.js", ['jquery'], null, true );
        wp_enqueue_style(  'select2',    "$dir/css/select2.min.css", );
        wp_enqueue_script( 'select2',    "$dir/js/select2.min.js", ['jquery'], null, true );

		wp_enqueue_style ( 'crm-admin',  get_stylesheet_directory_uri().'/crm-admin.css', [], null );
		wp_enqueue_script( 'crm-admin',  get_stylesheet_directory_uri().'/crm-admin.js', ['jquery','datatables','select2'], null, true );

		wp_localize_script( 'crm-admin', 'CRM_ADMIN', [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'crm_admin_nonce' ),
		] );
	}
	
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'crm-self-declarations' ) {
  
        wp_enqueue_style(  'datatables', "$dir/css/jquery.dataTables.min.css");
        wp_enqueue_script( 'datatables', "$dir/js/jquery.dataTables.min.js", ['jquery'], null, true );
        wp_enqueue_style(  'select2',    "$dir/css/select2.min.css", );
        wp_enqueue_script( 'select2',    "$dir/js/select2.min.js", ['jquery'], null, true );

        wp_enqueue_script( 'crm-selfdec-admin', get_stylesheet_directory_uri() . '/crm-selfdec-admin.js', ['jquery','datatables','select2'], null, true );
        wp_localize_script( 'crm-selfdec-admin', 'CRM_SELFDEC', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('crm_selfdec_nonce'),
        ] );
    }
} );

/**
 * Render the admin table for Self-Declarations
 */
function crm_self_declarations_admin_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Access denied');
    }

    // Map type IDs → Persian labels (same order you use on the form)
    $coursetypes = [
        1  => 'فنی',
        2  => 'داوری',
        3  => 'مربیگری',
        4  => 'قهرمانی',
        6  => 'بازآموزی',
        7  => 'دوره آموزشی',
        10 => 'کارورزی (عملی)',
        13 => 'استاژ فنی',
        14 => 'تربیت مدرس داوری',
    ];

    // For showing board/province names
    $wc_states = class_exists('WC_Countries') ? (new WC_Countries())->get_states('IR') : [];

    // Fetch all submissions
    $q = new WP_Query([
        'post_type'      => 'self_declaration',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'post_status'    => ['pending','publish','draft'],
    ]);

    echo '<div class="wrap"><h1>مدیریت خوداظهاری‌ها</h1>';

    echo '<table id="crm-selfdec-table" class="wp-list-table widefat striped">';
    echo '<thead><tr>';
    // Expanded columns
    echo '<th>ردیف</th>';
    echo '<th>کاربر</th>';
    echo '<th>نوع حکم</th>';
    echo '<th>درجه</th>';
    echo '<th>شماره حکم</th>';
    echo '<th>تاریخ اخذ</th>';
    echo '<th>تاریخ آزمون</th>';
    echo '<th>تاریخ تئوری</th>';
    echo '<th>هیئت</th>';
    echo '<th>فایل</th>';
    echo '<th>وضعیت</th>';
    echo '<th>اقدامات</th>';
    echo '</tr></thead><tbody>';

    $i = 1;
    while ( $q->have_posts() ) {
        $q->the_post();
        $pid     = get_the_ID();
        $author  = get_the_author_meta('display_name');
        $status  = get_post_status();
        $status_label = $status === 'publish' ? 'تأیید شده' : ( $status === 'pending' ? 'در حال بررسی' : 'رد شده' );

        // Meta
        $type_id      = (int) get_post_meta($pid, 'coursetype', true);
        $type_label   = $coursetypes[$type_id] ?? '—';

        $degree_raw   = (string) get_post_meta($pid, 'degree', true);      // (you can later map ID→label if desired)
        $hokm_number  = get_post_meta($pid, 'hokm_number', true);
        $getdate      = get_post_meta($pid, 'getdate', true);
        $exam_date    = get_post_meta($pid, 'exam_date', true);
        $theory_date  = get_post_meta($pid, 'theory_date', true);
        $board_code   = (string) get_post_meta($pid, 'boards', true);
        $board_label  = $wc_states[$board_code] ?? $board_code ?: '—';
        $image_url    = esc_url( get_post_meta($pid, 'image_url', true) );

        echo '<tr data-id="'. esc_attr($pid) .'">';
        echo '<td>'. ($i++) .'</td>';
        echo '<td>'. esc_html($author) .'</td>';
        echo '<td>'. esc_html($type_label) .'</td>';
        echo '<td>'. esc_html($degree_raw ?: '—') .'</td>';
        echo '<td>'. esc_html($hokm_number ?: '—') .'</td>';
        echo '<td>'. esc_html($getdate ?: '—') .'</td>';
        echo '<td>'. esc_html($exam_date ?: '—') .'</td>';
        echo '<td>'. esc_html($theory_date ?: '—') .'</td>';
        echo '<td>'. esc_html($board_label) .'</td>';
        echo '<td>'. ( $image_url ? '<a href="'.$image_url.'" target="_blank">مشاهده</a>' : '—' ) .'</td>';
        echo '<td>'. $status_label .'</td>';
        echo '<td>
                <button class="button approve-btn" data-id="'.$pid.'">تأیید</button>
                <button class="button disapprove-btn" data-id="'.$pid.'">رد</button>
              </td>';
        echo '</tr>';
    }
    wp_reset_postdata();

    echo '</tbody></table></div>';
}


add_action( 'wp_ajax_crm_selfdec_change_status', function () {
    check_ajax_referer( 'crm_selfdec_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error();
    }

    $post_id  = intval( $_POST['post_id'] ?? 0 );
    $decision = sanitize_text_field( $_POST['decision'] ?? '' ); // ← new key
    $reason   = sanitize_text_field( $_POST['reason'] ?? '' );

    if ( $decision === 'approve' ) {
        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
        delete_post_meta( $post_id, 'selfdec_rejection_reason' );
    } elseif ( $decision === 'disapprove' ) {
        wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
        update_post_meta( $post_id, 'selfdec_rejection_reason', $reason );
    } else {
        wp_send_json_error();
    }

    wp_send_json_success();
} );


# 3) helper – list of Basic-Info meta keys (same as front-end)
function crm_basic_info_fields() {
	return [
		'billing_phone'     => 'شماره موبایل',
		'national_id'       => 'کد ملی',
		'first_name_fa'     => 'نام (فا)',
		'last_name_fa'      => 'نام‌خانوادگی (فا)',
		'first_name_en'     => 'نام (En)',
		'last_name_en'      => 'نام‌خانوادگی (En)',
		'gender'            => 'جنسیت',
		'father_name'       => 'نام پدر',
		'birth_date'        => 'تاریخ تولد',
		'birth_province'    => 'استان تولد',
                'birth_city'        => 'شهرستان تولد',
		'marital_status'    => 'وضعیت تاهل',
		'education_status'  => 'وضعیت تحصیلی',
		'military_status'   => 'وضعیت خدمت',
		'residence_province'=> 'استان سکونت',
                'residence_city'    => 'شهرستان سکونت',
		'postal_code'       => 'کدپستی',
		'residence_address' => 'آدرس',
		'club_id' => 'باشگاه',
		'coach_id' => 'مربی',
	];
}

/* -----------------------------------------------------
 *   Page A – Basic-Info list OR editor
 * ----------------------------------------------------*/
function crm_basic_info_list_page() {

	/* edit mode */
	if ( isset($_GET['edit_user']) ) {
		crm_basic_info_edit_form( intval($_GET['edit_user']) );
		return;
	}

	$fields = crm_basic_info_fields();
	$users  = get_users();

	echo '<div class="wrap table-responsive" style="max-width:90vw;overflow-x: scroll;"><h1>اطلاعات پایه کاربران</h1>';
	echo '<table id="crm-basic-table" class="wp-list-table widefat striped">';
	echo '<thead><tr><th>ID</th><th>نام</th>';
	foreach ( $fields as $k=>$lbl ) echo "<th>{$lbl}</th>";
	echo '<th>عملیات</th></tr></thead><tbody>';

	foreach ( $users as $u ) {
		echo '<tr>';
		echo '<td>'.$u->ID.'</td>';
		echo '<td>'.$u->display_name.'</td>';
        foreach ( $fields as $k => $lbl ) {
            $raw = get_user_meta( $u->ID, $k, true );
            $val = crm_display_name_or_value( $k, $raw );
            echo '<td>' . esc_html( $val ) . '</td>';
        }

		echo '<td><a class="button" href="'.admin_url("users.php?page=crm-basic-info&edit_user={$u->ID}").'">ویرایش</a></td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

/* ---------- edit form ---------- */
function crm_basic_info_edit_form( $user_id ) {

	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'NO' );

	$fields = crm_basic_info_fields();

	/* save */
	if ( isset($_POST['crm_save_basic_admin']) && check_admin_referer( 'crm_basic_admin', 'crm_nonce' ) ) {
		foreach ( $fields as $meta_key => $label ) {
			if ( isset($_POST[$meta_key]) ) {
				update_user_meta( $user_id, $meta_key, sanitize_text_field( $_POST[$meta_key] ) );
			}
		}
		echo '<div class="updated"><p>اطلاعات ذخیره شد.</p></div>';
	}

	echo '<div class="wrap"><h1>ویرایش اطلاعات کاربر #'.$user_id.'</h1>';
	echo '<form method="post">';
	wp_nonce_field( 'crm_basic_admin', 'crm_nonce' );
	echo '<table class="form-table">';

	foreach ( $fields as $k=>$lbl ) {
		$val = esc_attr( get_user_meta( $user_id, $k, true ) );
		echo "<tr><th>{$lbl}</th><td><input type='text' name='{$k}' value='{$val}' class='regular-text'/></td></tr>";
	}

	echo '</table><p><input type="submit" name="crm_save_basic_admin" class="button-primary" value="ذخیره"></p>';
	echo '</form><p><a href="'.admin_url('users.php?page=crm-basic-info').'">← بازگشت به لیست</a></p></div>';
}

/* -----------------------------------------------------
 *   Page B – Professional Identity (extended of yours)
 * ----------------------------------------------------*/
function crm_prof_identity_page() {

	$pro_fields = [
	    'personal_photo'     => 'عکس پرسنلی',
		'birth_certificate'     => 'تصویر شناسنامه',
		'national_id_card' => 'تصویر کارت ملی',
		'education_certificate'  => 'تصویر آخرین مدرک تحصیلی',
		'military_service_status'    => 'کارت پایان خدمت/معافیت/اشتغال به تحصیل',
	];

	$users = get_users();

	echo '<div class="wrap"><h1>تأیید هویت حرفه‌ای</h1>';
	echo '<table id="crm-prof-table" class="wp-list-table widefat striped"><thead><tr>';
	echo '<th>ID</th><th>نام</th>';
	foreach ( $pro_fields as $lbl ) echo "<th>{$lbl}</th>";
	echo '<th>وضعیت</th><th>نقش</th><th>عملیات</th></tr></thead><tbody>';

	foreach ( $users as $u ) {

		$status = get_user_meta( $u->ID,'identity_verified_professional',true ) ?: 'pending';
		$label  = $status==='approved' ? 'مورد تایید' : ( $status==='disapproved' ? 'مردود' : 'در انتظار' );

		echo '<tr>';
		echo '<td>'.$u->ID.'</td><td>'.$u->display_name.'</td>';

		foreach ( $pro_fields as $key=>$lbl ) {
			$uurl = get_user_meta( $u->ID, $key, true );
			echo '<td>'. ( $uurl ? "<a href='".esc_url($uurl)."' target='_blank'>مشاهده</a>" : '-' ) .'</td>';
		}

		echo '<td>'.$label.'</td>';

		/* role dropdown */
		echo '<td><select class="role-select" data-user-id="'.$u->ID.'"><option value="">--</option>';
		foreach ( wp_roles()->roles as $rk=>$rd ) {
			if ( $rk==='administrator' ) continue;
			$sel = in_array( $rk, $u->roles, true ) ? 'selected':'';
			echo "<option value='{$rk}' {$sel}>{$rd['name']}</option>";
		}
		echo '</select></td>';

		/* actions */
		echo '<td>
			<form method="post" class="identity-action-form" style="display:inline;">
				<input type="hidden" name="user_id" value="'.$u->ID.'">
				'.wp_nonce_field( 'crm_admin_nonce', '_wpnonce', true, false ).'
				<button class="button" name="crm_user_action" value="approve">تایید</button>
				<button class="button disapprove-btn" data-user="'.$u->ID.'">رد</button>
				<button class="button" name="crm_user_action" value="pending">در انتظار</button>
			</form></td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

/* -------------- AJAX for role update --------------*/
add_action( 'wp_ajax_crm_admin_update_role', function (){

	check_ajax_referer( 'crm_admin_nonce', 'nonce' );
	if ( ! current_user_can('manage_options') ) wp_send_json_error();

	$user = get_userdata( intval($_POST['user']) );
	$role = sanitize_text_field( $_POST['role'] );

	if ( $user && $role && isset(wp_roles()->roles[$role]) ) {
		$user->set_role( $role );
		wp_send_json_success( ['msg'=>'نقش بروز شد'] );
	}
	wp_send_json_error( ['msg'=>'خطا'] );
});

/* -------------- AJAX for status change --------------*/
add_action( 'wp_ajax_crm_admin_id_status', function (){

	check_ajax_referer( 'crm_admin_nonce', 'nonce' );
	if ( ! current_user_can('manage_options') ) wp_die();

	$id   = intval($_POST['user']);
	$act  = sanitize_text_field($_POST['action_type']);
	$meta = 'identity_verified_professional';

	if ( $act==='approve' ) {
		update_user_meta( $id, $meta, 'approved' );
		delete_user_meta( $id,'identity_rejection_reason_professional' );
		$photo = get_user_meta( $id, 'personal_photo', true );
        if ( $photo ){
            /* simple-local-avatar & most avatar plugins read this meta key: */
            update_user_meta( $id, 'simple_local_avatar', [ 'full' => esc_url_raw( $photo ) ] );
        }
	} elseif ( $act==='pending' ) {
		update_user_meta( $id, $meta, 'pending' );
		delete_user_meta( $id,'identity_rejection_reason_professional' );
	} elseif ( $act==='disapprove' ) {
		update_user_meta( $id, $meta, 'disapproved' );
		update_user_meta( $id, 'identity_rejection_reason_professional', sanitize_text_field($_POST['reason']) );
	}
	wp_send_json_success();
});


add_action('woocommerce_account_club-register_endpoint', function(){
  echo do_shortcode('[club_register]');
});


add_shortcode( 'club_register', function () {

	/* ========== دسترسی ========== */
	if ( ! is_user_logged_in() ) {
		return '<p style="text-align:center;color:#c00;">لطفاً ابتدا وارد شوید.</p>';
	}
	$user_id = get_current_user_id();

	/* ========== ذخیرهٔ فرم ========== */
	if ( isset( $_POST['club_apply'] ) && wp_verify_nonce( $_POST['club_nonce'], 'club_apply' ) ) {

		$required = [ 'club_name','club_owner',
		              'club_province','club_city','club_address' ];
		$errs = [];

		foreach ( $required as $r ) {
			if ( empty( $_POST[ $r ] ) ) {
				$errs[] = "فیلد «{$r}» اجباری است.";
			}
		}
		if ( empty( $_FILES['club_license_image']['name'] ) )
			$errs[] = 'آپلود تصویر مجوز الزامی است.';

		if ( ! $errs ) {

			$pid = wp_insert_post( [
				'post_type'   => 'club_application',
				'post_status' => 'pending',
				'post_author' => $user_id,
				'post_title'  => sanitize_text_field( $_POST['club_name'] ),
			] );

			if ( $pid && ! is_wp_error( $pid ) ) {

				update_post_meta( $pid, 'owner_name',    sanitize_text_field( $_POST['club_owner'] ) );
				update_post_meta( $pid, 'club_postal',   sanitize_text_field( $_POST['club_postal'] ) );
				update_post_meta( $pid, 'club_province', sanitize_text_field( $_POST['club_province'] ) );
				update_post_meta( $pid, 'club_city',     sanitize_text_field( $_POST['club_city'] ) );
				update_post_meta( $pid, 'club_address',  sanitize_textarea_field( $_POST['club_address'] ) );

				require_once ABSPATH . 'wp-admin/includes/file.php';
				$up = wp_handle_upload( $_FILES['club_license_image'], [ 'test_form'=>false ] );
				if ( ! isset( $up['error'] ) )
					update_post_meta( $pid,'license_image',esc_url_raw( $up['url'] ) );

				wc_add_notice( 'درخواست ثبت شد و در انتظار بررسی است.', 'success' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'club-register' ) );
				exit;
			}
			$errs[] = 'خطا در ذخیرهٔ درخواست.';
		}
		foreach ( $errs as $e ) wc_add_notice( $e, 'error' );
	}

	$app = get_posts( [
		'post_type'   => 'club_application',
		'author'      => $user_id,
		'numberposts' => 1,
		'post_status' => [ 'pending','publish','draft' ],
	] );
	$app    = $app ? $app[0] : null;
	$status = $app ? get_post_status( $app ) : '';
	$locked = $app && in_array( $status, [ 'pending','publish' ], true );
	$val = fn($k) => $app ? esc_attr( get_post_meta( $app->ID, $k, true ) ) : '';

	$club_name  = $app ? esc_attr( $app->post_title ) : '';
	$lic_image  = $app ? esc_url( get_post_meta( $app->ID,'license_image',true ) ) : '';

	ob_start();
	wc_print_notices();            

	if ( $locked ) {
		echo $status==='pending'
		     ? '<div class="notice-warning">درخواست شما در حال بررسی است.</div>'
		     : '<div class="notice-success">درخواست شما تأیید شده است.</div>';
	} elseif ( $status === 'draft' ) {
		echo '<div class="notice-error">درخواست شما رد شد. دلیل: '
		     . esc_html( get_post_meta( $app->ID,'rejection_reason',true ) )
		     . '</div>';
	}

	$provinces = ( new WC_Countries() )->get_states('IR');

	/* ========== فرم ========== */ ?>
	<style>
		.notice-success,.notice-error,.notice-warning{padding:10px;border-radius:10px;margin-bottom:10px}
		.notice-success{background:#00ffaa8f;}
		.notice-error  {background:#ff00008f;}
		.notice-warning{background:#ff92008f;}

		.club-form-container{
			background:#fff;border:1px solid #e0e0e0;padding:20px;
			margin:30px auto;direction:rtl;font-family:"vaziri",sans-serif;
			max-width:95%;
		}
		.club-form-container .cf-grid{
			display:grid;grid-template-columns:repeat(3,1fr);gap:16px;
		}
		.club-form-container .cf-field{display:flex;flex-direction:column;}
		.club-form-container .cf-field label{font-weight:600;margin-bottom:6px;}
		.club-form-container .cf-field input,
		.club-form-container .cf-field textarea,
		.club-form-container .cf-field select{
			padding:8px;border:1px solid #ccc;border-radius:4px;
			background:#fff;font-size:14px;width:100%;
		}
		.club-form-container input[disabled],
		.club-form-container textarea[disabled],
		.club-form-container select[disabled]{
			background:#f5f5f5;color:#666;cursor:not-allowed;
		}
		.club-form-container .cf-wide{grid-column:span 3;}
		.club-form-container .cf-submit{grid-column:3/4;text-align:left;margin-top:10px;}
		.club-form-container .cf-submit button{
			background:#1890ff;color:#fff;border:none;padding:10px 20px;
			border-radius:4px;cursor:pointer;font-size:15px;
		}
		.thumb-lic{margin-top:6px;max-width:180px;border:1px solid #ccc;border-radius:6px;}
	</style>

	<div class="club-form-container">
		<form method="post" enctype="multipart/form-data" class="needs-swal" id="club-form">
			<?php wp_nonce_field( 'club_apply','club_nonce' ); ?>

			<div class="cf-grid">

				<div class="cf-field">
					<label>نام باشگاه <span style="color:#d00">*</span></label>
					<input type="text" name="club_name" value="<?php echo $club_name; ?>"
					       <?php echo $locked?'disabled':''; ?> required>
				</div>

				<div class="cf-field">
					<label>نام صاحب باشگاه <span style="color:#d00">*</span></label>
					<input type="text" name="club_owner" value="<?php echo $val('owner_name'); ?>"
					       <?php echo $locked?'disabled':''; ?> required>
				</div>

				<div class="cf-field">
					<label>کد پستی</label>
					<input type="text" name="club_postal" pattern="[0-9]{10}"
					       value="<?php echo $val('club_postal'); ?>"
					       <?php echo $locked?'disabled':''; ?> required>
				</div>

				<div class="cf-field">
					<label>استان <span style="color:#d00">*</span></label>
					<select name="club_province" class="crm-select2"  id="club_province"
					        <?php echo $locked?'disabled':''; ?> required>
						<option value="">— انتخاب کنید —</option>
						<?php foreach ( $provinces as $code=>$name ) : ?>
							<option value="<?php echo esc_attr($code); ?>"
								<?php selected( $code, $val('club_province') ); ?>>
								<?php echo esc_html($name); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

                                <div class="cf-field">
                                        <label>شهرستان <span style="color:#d00">*</span></label>
                                        <select name="club_city" id="club_city" class="crm-select2"
                                                <?php echo $locked?'disabled':''; ?> required>
						<?php
						if ( $locked || $val('club_city') ) {
							echo '<option>'. esc_html( $val('club_city') ?: '— انتخاب کنید —' ) .'</option>';
						} else {
							echo '<option>— ابتدا استان را انتخاب کنید —</option>';
						}
						?>
					</select>
				</div>

				<div class="cf-field">
					<label>تصویر مجوز باشگاه <span style="color:#d00">*</span></label>
					<?php if ( $locked && $lic_image ) : ?>
						<a href="<?php echo $lic_image; ?>" target="_blank">
							<img src="<?php echo $lic_image; ?>" class="thumb-lic" alt="">
						</a>
					<?php else : ?>
						<input type="file" name="club_license_image"
						       accept=".jpg,.jpeg,.png,.pdf" required>
					<?php endif; ?>
				</div>

				<div class="cf-field cf-wide">
					<label>آدرس باشگاه <span style="color:#d00">*</span></label>
					<textarea name="club_address" rows="3"
						<?php echo $locked?'disabled':''; ?> required><?php
						echo esc_textarea( $val('club_address') );
					?></textarea>
				</div>

				<?php if ( ! $locked ) : ?>
					<div class="cf-submit">
						<button type="submit" name="club_apply">ارسال برای بررسی</button>
					</div>
				<?php endif; ?>

			</div>
		</form>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded',function(){
		if( <?php echo $locked?'false':'true'; ?> ){ 
			var cityMap = window.CBIF_CITIES || {};
			var p = document.getElementById('club_province'),
			    c = document.getElementById('club_city');
			if(!p) return;
			p.addEventListener('change',function(){
				var list = cityMap[this.value] || [],
				    html = '<option value="">— ابتدا استان را انتخاب کنید —</option>';
				list.forEach(function(ci){ html += '<option>'+ci+'</option>'; });
				c.innerHTML = html;
				c.disabled  = ! list.length;
			});
		}
	});
	</script>
	<?php
	return ob_get_clean();
} );


function crm_club_admin_page() {

	/* واکشی تمام درخواست‌ها */
	$q = new WP_Query( [
		'post_type'      => 'club_application',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'post_status'    => [ 'pending', 'publish', 'draft' ],
	] );

	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">درخواست‌های ثبت باشگاه</h1>
		<hr class="wp-header-end">

		<table id="club-table" class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th>ردیف</th>
				<th>نام باشگاه</th>
				<th>صاحب امتیاز</th>
                                <th>استان</th>
                                <th>شهرستان</th>
				<th>وضعیت</th>
				<th>اقدام</th>
			</tr></thead>
			<tbody>
			<?php
			$i = 1;
			while ( $q->have_posts() ) : $q->the_post();
				$pid      = get_the_ID();
				$uid      = get_post_field( 'post_author', $pid );
				$status   = get_post_status( $pid );           // pending, publish, draft
				$province = get_post_meta( $pid, 'club_province', true );
				$city     = get_post_meta( $pid, 'club_city', true );

				$status_label = ( $status === 'publish' )
					? 'تأیید'
					: ( $status === 'draft' ? 'رد' : 'در انتظار' );

				echo "<tr data-id='{$pid}' data-user='{$uid}'>";
				echo "<td>{$i}</td>";
				echo "<td>". esc_html( get_the_title() ) ."</td>";
				echo "<td>". esc_html( get_post_meta( $pid, 'owner_name', true ) ) ."</td>";
				echo "<td>". esc_html( $province ) ."</td>";
				echo "<td>". esc_html( $city ) ."</td>";
				echo "<td>{$status_label}</td>";
				echo '<td>
						<button class="button view-club"   data-pid="'.$pid.'">جزئیات</button>
						<button class="button approve-club">تأیید</button>
						<button class="button reject-club">رد</button>
					  </td>';
				echo '</tr>';
				$i++;
			endwhile; wp_reset_postdata();
			?>
			</tbody>
		</table>
	</div>

	<!-- پنجرهٔ Modal ساده برای نمایش جزئیات -->
	<div id="club-modal" style="display:none;">
		<style>
			#club-modal .modal-inner{padding:20px;font-family:"vaziri",sans-serif;direction:rtl;}
			#club-modal h2{margin-top:0;}
			#club-modal p{margin:4px 0;}
		</style>
		<div class="modal-inner"></div>
	</div>

	<script>
jQuery(function($){

	/* ---------- نمایش جزئیات ------------- */
	$('body').on('click','.view-club',function(){
		var pid = $(this).data('pid');
		tb_show('جزئیات باشگاه','#TB_inline?inlineId=club-modal');
		$('#TB_ajaxContent .modal-inner').html('در حال بارگیری ...');

		$.post(ajaxurl,{
			action:'crm_club_get',
			nonce :'<?php echo wp_create_nonce("crm_club_get"); ?>',
			post  : pid
		},function(r){
			if(r.success){
				var d = r.data,
				    html =
					'<h2>'+d.title+'</h2>'+
					'<p><strong>صاحب امتیاز:</strong> '+d.owner+'</p>'+
                                   '<p><strong>استان/شهرستان:</strong> '+d.province+' - '+d.city+'</p>'+
					'<p><strong>کد پستی:</strong> '+d.postal+'</p>'+
					'<p><strong>آدرس:</strong><br>'+d.address+'</p>'+
					'<p><strong>تصویر مجوز:</strong><br>'+
					'<a href="'+d.lic+'" target="_blank"><img src="'+d.lic+'" style="max-width:200px;border:1px solid #ccc"></a></p>'+
					(d.reason ? '<p style="color:#d00;"><strong>دلیل رد:</strong> '+d.reason+'</p>' : '');
				$('#TB_ajaxContent .modal-inner').html(html);
			}else{
				$('#TB_ajaxContent .modal-inner').html('<p style="color:red;">خطا در دریافت اطلاعات.</p>');
			}
		});
	});

	/* ---------- تأیید / رد ------------- */
	$('#club-table').on('click','.approve-club, .reject-club',function(){
		var $tr = $(this).closest('tr'),
		    dec = $(this).hasClass('approve-club') ? 'approve' : 'reject',
		    reason = '';

		if(dec==='reject'){
			reason = prompt('دلیل رد این باشگاه؟');
			if(reason===null) return; // cancel
		}

		$.post(ajaxurl,{
			action   : 'crm_club_decide',
			nonce    : '<?php echo wp_create_nonce("crm_club_decide"); ?>',
			post     : $tr.data('id'),
			user     : $tr.data('user'),
			decision : dec,
			reason   : reason
		},function(r){
			if(r.success) location.reload();
			else alert('خطا در انجام عملیات');
		});
	});
});
</script>

	<?php
}

/* ---------- Ajax: fetch single club (for modal) ---------- */
add_action( 'wp_ajax_crm_club_get', function () {

	check_ajax_referer( 'crm_club_get', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

	$pid = intval( $_POST['post'] ?? 0 );
	if ( ! $pid ) wp_send_json_error();

	$data = [
		'title'    => get_the_title( $pid ),
		'owner'    => get_post_meta( $pid, 'owner_name',    true ),
		'province' => get_post_meta( $pid, 'club_province', true ),
		'city'     => get_post_meta( $pid, 'club_city',     true ),
		'postal'   => get_post_meta( $pid, 'club_postal',   true ),
		'address'  => nl2br( esc_html( get_post_meta( $pid, 'club_address', true ) ) ),
		'lic'      => esc_url_raw( get_post_meta( $pid, 'license_image',  true ) ),
		'reason'   => get_post_meta( $pid, 'rejection_reason', true ),
	];
	wp_send_json_success( $data );
} );

/* ---------- Ajax: approve / reject ---------- */
add_action( 'wp_ajax_crm_club_decide', function () {

    check_ajax_referer( 'crm_club_decide', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
    
        $pid    = intval( $_POST['post'] );
        $uid    = intval( $_POST['user'] );
        $dec    = sanitize_text_field( $_POST['decision'] );
        $reason = sanitize_text_field( $_POST['reason'] ?? '' );
    
        if ( $dec === 'approve' ) {
    
        wp_update_post( [ 'ID' => $pid, 'post_status' => 'publish' ] );
        delete_post_meta( $pid, 'rejection_reason' );
    
        $club_name = get_the_title( $pid );
        $user      = get_userdata( $uid );
    
        if ( $user && $club_name ) {
            /* 1) keep user's public name intact – just save the club name */
            update_user_meta( $uid, 'club_name', $club_name );
            $user->add_role( 'club' );
        }
    } elseif ( $dec === 'reject' ) {
        wp_update_post( [ 'ID' => $pid, 'post_status' => 'draft' ] );
        update_post_meta( $pid, 'rejection_reason', $reason );
    } else {
        wp_send_json_error();
    }

    wp_send_json_success();
} );


add_filter( 'pre_get_avatar_data', function ( $args, $id_or_email ) {

	// Resolve a user ID from anything WP gives us (ID, email, WP_User…)
	if ( is_numeric( $id_or_email ) ) {
		$user = get_user_by( 'id', $id_or_email );
	} elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
		$user = get_user_by( 'id', $id_or_email->user_id );
	} elseif ( is_string( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
	} else {
		return $args;                       // not a user → bail
	}

	if ( ! $user ) {
		return $args;
	}

	$url = get_user_meta( $user->ID, 'personal_photo', true );
	if ( $url ) {
		$args['url']          = esc_url_raw( $url ); // serve our own file
		$args['found_avatar'] = true;                // stops WP from looking further
	}

	return $args;
}, 10, 2 );



/* save meta + payouts + sync hidden product */
add_action( 'save_post_course', function( $pid, $post ){
	if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
	if ( ! isset($_POST['crm_course_nonce']) || ! wp_verify_nonce($_POST['crm_course_nonce'],'crm_save_course') ) return;

	$meta_keys = [ 'course_code','course_type','course_level','course_title','start_date','end_date','exam_date',
	               'board','style','scope','gender','attendance','organizer','organizer_tel','price' ];
	foreach($meta_keys as $k){
		if(isset($_POST[$k])) update_post_meta($pid,$k,sanitize_text_field($_POST[$k]));
	}

	/* payouts */
	$payouts = [];
	if(!empty($_POST['payout_user_id'])){
		foreach((array)$_POST['payout_user_id'] as $i=>$uid){
			$uid = (int) $uid; if(!$uid) continue;
			$payouts[]=[
				'user_id'=>$uid,
				'type'=> sanitize_text_field($_POST['payout_type'][$i]??'percent'),
				'value'=> (float)($_POST['payout_value'][$i]??0),
			];
		}
	}
	update_post_meta($pid,CRM_PAYOUT_META,$payouts);

	/* sync product */
	crm_sync_product_for_course($pid);
},10,2);

function crm_sync_product_for_course( $course_id ){
	if(!class_exists('WC_Product')) return;
	$price   = (float) get_post_meta($course_id,'price',true);
	$prod_id = (int)   get_post_meta($course_id,CRM_LINKED_PROD,true);

	if($prod_id && ($prod=wc_get_product($prod_id))){
		$prod->set_name( get_the_title($course_id) );
		$prod->set_regular_price($price);
		$prod->save();
	}else{
		$prod = new WC_Product_Simple();
		$prod->set_name( get_the_title($course_id) );
		$prod->set_regular_price($price);
		$prod->set_virtual(true);
		$prod->set_catalog_visibility('hidden');
		$prod_id = $prod->save();
		update_post_meta($course_id, CRM_LINKED_PROD,  $prod_id );
		update_post_meta($prod_id  , CRM_LINKED_COURSE,$course_id );
	}
}

/* -----------------------------------------------------------------
 * 3.  Course list shortcode  & single template
 * ----------------------------------------------------------------*/
/* =========================================================
 *  لیست کامل دوره‌ها  –  [crm_courses_list]
 * =========================================================*/
add_shortcode( 'crm_courses_list', 'crm_courses_list_cb' );
function crm_courses_list_cb() {

	$q = new WP_Query( [
		'post_type'      => 'course',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	] );

	if ( ! $q->have_posts() ) {
		return '<p>دوره‌ای موجود نیست.</p>';
	}

	/* کلید متا ⇒ عنوان ستون */
	$cols = [
		'course_code'   => 'کد دوره',
		'course_type'   => 'نوع دوره',
		'course_level'  => 'درجه/زیرشاخه',
		'board'         => 'هیئت',
		'style'         => 'سبک',
		'scope'         => 'نوع',
		'gender'        => 'جنسیت',
	];

	ob_start(); ?>

<style>
    .crm-course-wrap {
        background:#fff;
        border:1px solid #e0e0e0;
        padding:20px;
        margin:30px auto;
        overflow-x:auto;
        direction:rtl;
        font-family:'vaziri';
    }
    .crm-course-title {
        background:#060097;
        color:#fff;
        padding:10px 15px;
        font-size:18px;
        font-weight:600;
        border-radius:5px;
    }
    .crm-course-table {
        width:100%;
        border-collapse:collapse;
        margin-top:20px;
    }
    .crm-course-table th,
    .crm-course-table td {
        border:1px solid #dcdcdc;
        padding:6px 10px;
        text-align:center;
        font-size:13px;
    }
    .crm-course-table thead {
        background:#ff92008f;
        color:#000;
    }
    /* striped effect */
    .crm-course-table tbody tr:nth-child(even) {
        background-color: #f5f5f5;
    }
</style>


	<div class="crm-course-wrap">
		<div class="crm-course-title">لیست دوره‌ها</div>

		<table class="crm-course-table">
			<thead><tr>
				<th>#</th>
				<th>عنوان</th>
				<?php foreach ( $cols as $label ) echo '<th>'. esc_html( $label ) .'</th>'; ?>
				<th>اقدام</th>
			</tr></thead>
			<tbody>
			<?php $i=1; while ( $q->have_posts() ) : $q->the_post();
				$cid = get_the_ID(); ?>
				<tr>
					<td class="text-center"><?php echo $i++; ?></td>
					<td class="text-center"><?php the_title();?></td>
					<?php foreach ( $cols as $key => $label ) :
						$val = get_post_meta( $cid, $key, true );
						echo '<td class="text-center">'. ( $val ? esc_html( $val ) : '—' ) .'</td>';
					endforeach; ?>

                    <td class="text-center">
                        <a href="<?php echo esc_url("/my-account/course-details/?course_id=$cid"); ?>">جزئیات / ثبت‌نام</a>
                    </td>
				</tr>
			<?php endwhile; wp_reset_postdata(); ?>
			</tbody>
		</table>
	</div>

	<?php
	return ob_get_clean();
}



/* -----------------------------------------------------------------
 * 4.  Wallet usage in checkout
 * ----------------------------------------------------------------*/
add_action('woocommerce_review_order_after_order_total','crm_wallet_checkbox');
add_action('woocommerce_cart_totals_after_order_total',   'crm_wallet_checkbox');
function crm_wallet_checkbox(){
	if(!is_user_logged_in()) return;
	$bal=wpw_get_wallet();
	if($bal<=0) return;
	$chk=WC()->session->get('crm_use_wallet')?' checked':'';
	echo '<tr class="wallet-use"><th>استفاده از کیف پول ('.wc_price($bal).')</th><td><input type="checkbox" name="crm_use_wallet" value="1"'.$chk.'></td></tr>';
}

/* store flag */
add_action('woocommerce_checkout_update_order_review',function($post){
	WC()->session->set('crm_use_wallet', ! empty($_POST['crm_use_wallet']) );
});

/* apply discount (negative fee) */
add_action('woocommerce_cart_calculate_fees',function($cart){
	if(is_admin() && !defined('DOING_AJAX')) return;
	if(!WC()->session->get('crm_use_wallet') || !is_user_logged_in()) return;
	$bal=wpw_get_wallet();
	if($bal<=0) return;
	$tot=$cart->get_total('edit');
	$use=min($bal,$tot);
	if($use>0){
		$cart->add_fee('کیف پول', -$use, false );
		WC()->session->set('crm_wallet_use_amount',$use);
	}
});

/* after order created – save meta */
add_action('woocommerce_checkout_create_order',function($order,$data){
	$use=(float)WC()->session->get('crm_wallet_use_amount');
	if($use>0){
		$order->update_meta_data(CRM_USED_WALLET,$use);
	}
},10,2);

/* on payment complete – deduct wallet & pay payouts */
add_action('woocommerce_payment_complete','crm_after_payment');
function crm_after_payment( $order_id ){
	$order=wc_get_order($order_id);
	if(!$order) return;
	$uid=$order->get_customer_id();

	/* deduct wallet */
	$used=(float)$order->get_meta(CRM_USED_WALLET);
	if($used>0){
		wpw_deduct_wallet($uid,$used);
		crm_add_wallet_log($uid,-$used,'استفاده در سفارش #'.$order_id);
	}

	/* payouts */
	foreach($order->get_items() as $it){
		$cid=(int)get_post_meta($it->get_product_id(),CRM_LINKED_COURSE,true);
		if(!$cid) continue;
		$payouts=(array)get_post_meta($cid,CRM_PAYOUT_META,true);
		$line_total=$it->get_total(); // after discount but before tax
		foreach($payouts as $p){
			$dest=(int)$p['user_id']; if(!$dest) continue;
			$amt = $p['type']=='percent' ? $line_total*$p['value']/100 : (float)$p['value'];
			if($amt<=0) continue;
			wpw_add_wallet($dest,$amt);
			crm_add_wallet_log($dest,$amt,'درآمد از دوره #'.$cid.' (سفارش '.$order_id.')');
		}
	}
}

/* refund wallet on order cancel/refund */
add_action('woocommerce_order_status_cancelled','crm_wallet_refund',10);
add_action('woocommerce_order_status_refunded','crm_wallet_refund',10);
function crm_wallet_refund($order_id){
	$order=wc_get_order($order_id); if(!$order) return;
	$uid=$order->get_customer_id();
	$used=(float)$order->get_meta(CRM_USED_WALLET);
	if($used>0){
		wpw_add_wallet($uid,$used);
		crm_add_wallet_log($uid,$used,'بازگشت وجه سفارش لغو شده #'.$order_id);
		$order->delete_meta_data(CRM_USED_WALLET); $order->save();
	}
}

/* wallet log helper */
function crm_add_wallet_log($user_id,$amount,$note=''){
	$log=(array)get_user_meta($user_id,CRM_WALLET_LOG,true);
	$log[]= [ 'date'=>current_time('mysql'),'amount'=>$amount,'note'=>$note ];
	update_user_meta($user_id,CRM_WALLET_LOG,$log);
}

/* -----------------------------------------------------------------
 * 5-A  enqueue DataTables only on Wallet-Manager admin page
 * ----------------------------------------------------------------*/
add_action( 'admin_enqueue_scripts', function ( $hook ) {

	/* users_page_{slug} → hook-name */
	if ( $hook !== 'users_page_crm-wallet-manager' ) {
		return;
	}

	// CDN assets
  	$dir = get_stylesheet_directory_uri().'/assets';
	wp_enqueue_style( 'dt-css', "$dir/css/jquery.dataTables.min.css");
	wp_enqueue_script( 'dt-js',  "$dir/js/jquery.dataTables.min.js", [ 'jquery' ], null, true );

	// init
	wp_add_inline_script( 'dt-js', "
		jQuery(function($){
			$('#crm-wallet-table').DataTable({
				language:{ url:'https://cdn.datatables.net/plug-ins/1.13.8/i18n/fa.json' },
				pageLength: 50,
				order:[[1,'desc']]
			});
		});
	" );
} );


/* -----------------------------------------------------------------
 * 5-B  Wallet Manager – table markup with ID
 * ----------------------------------------------------------------*/
add_action('admin_menu',function(){
	add_users_page( 'مدیریت کیف پول','مدیریت کیف پول','manage_options','crm-wallet-manager','crm_wallet_manager_page');
});

function crm_wallet_manager_page(){

	if( ! current_user_can('manage_options') ) wp_die('Access denied');

	$users = get_users( [ 'fields'=>['ID','display_name','user_email'] ] );

	
	if ( isset($_POST['crm_wallet_adj']) ) {
		$uid  = (int) $_POST['user'];
		$amt  = (float) $_POST['amount'];
		$note = sanitize_text_field( $_POST['memo'] );
		$act  = sanitize_text_field( $_POST['action'] );

		if     ( $act === 'set' ) wpw_set_wallet( $uid, $amt );
		elseif ( $act === 'add' ) wpw_add_wallet( $uid, $amt );
		elseif ( $act === 'sub' ) wpw_deduct_wallet( $uid, $amt );

		crm_add_wallet_log( $uid, $act === 'sub' ? -abs( $amt ) : $amt, 'مدیریت: '.$note );

		echo '<div class="updated"><p>تغییر ذخیره شد.</p></div>';
	}

	echo '<div class="wrap"><h1>مدیریت کیف پول کاربران</h1>';
	wp_nonce_field( 'crm_wallet_adj_nonce' );

	/* ★★★ جدول با ID: crm-wallet-table ★★★ */
	echo '<table id="crm-wallet-table" class="widefat striped nowrap" style="width:100%"><thead>
	        <tr><th>کاربر</th><th>موجودی</th><th>مبلغ</th><th>پرداخت بابت</th><th>عملیات</th></tr>
	      </thead><tbody>';

	foreach ( $users as $u ) {
		$bal = wpw_get_wallet( $u->ID );

		echo '<tr><form method="post">'
		    .'<td>'. esc_html( $u->display_name ) .' ('. esc_html( $u->user_email ) .')</td>'
		    .'<td>'. wc_price( $bal ) .'</td>'
		    .'<td><input type="number" step="0.01" name="amount" required style="width:100px"></td>'
		    .'<td><input type="text" name="memo" style="width:100%"></td>'
		    .'<td>'
		    .   '<input type="hidden" name="user" value="'. $u->ID .'">'
		    .   '<button class="button" name="action" value="add">افزایش</button> '
		    .   '<button class="button" name="action" value="sub">کاهش</button> '
		    .   '<button class="button" name="action" value="set">تنظیم موجودی</button>'
		    .   '<input type="hidden" name="crm_wallet_adj" value="1">'
		    .'</td></form></tr>';
	}

	echo '</tbody></table></div>';
}

/* -----------------------------------------------------------------
 * 6.  Wallet page shortcode – log table
 * ----------------------------------------------------------------*/

add_shortcode( 'crm_wallet', function () {

	if ( ! is_user_logged_in() ) {
		return '<p style="text-align:center;color:#c00;">برای مشاهدهٔ کیف پول ابتدا وارد شوید.</p>';
	}

	$user_id = get_current_user_id();
	$balance = wpw_get_wallet( $user_id );

	/* ----------  پردازش فرم شارژ  ---------- */
	if ( isset( $_POST['wallet_charge'], $_POST['amount'] ) ) {

		$amount = max( 0, floatval( $_POST['amount'] ) );
		$min_charge = ir_price( 10000 );               // حداقل شارژ (۱۰ هزار تومان)

		if ( $amount < $min_charge ) {
			wc_add_notice( "حداقل شارژ " . wc_price( $min_charge ) . " است.", 'error' );
		} else {

			$order = wc_create_order();
			$item  = new WC_Order_Item_Fee();
			$item->set_name( 'شارژ کیف پول' );
			$item->set_amount( $amount );
			$item->set_total(  $amount );
			$order->add_item( $item );

			$order->update_meta_data( 'wallet_topup', $amount );
			$order->calculate_totals();
			$order->set_customer_id( $user_id );
			$order->update_status( 'pending', 'Wallet top-up' );
			$order->save();

			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}
	}

	/* ----------  خروجی HTML  ---------- */
	ob_start(); ?>

	<style>
		#wallet-box{background:#fff;border:1px solid #e0e0e0;padding:20px;margin:30px auto;direction:rtl;}
		#wallet-box h4{margin:0 0 1rem 0}
		#wallet-box table{width:100%;border-collapse:collapse;margin-top:1.5rem}
		#wallet-box th,#wallet-box td{border:1px solid #dcdcdc;padding:6px;text-align:center;font-size:13px}
		#wallet-box thead{background:#f5f5f5;font-weight:600}
		.log-table{margin-top:2.5rem}
	</style>

	<div id="wallet-box" class="needs-swal">
		<h4>موجودی کیف پول: <span style="color:#28a745;"><?php echo wc_price( $balance ); ?></span></h4>

		<!-- فرم شارژ -->
		<form method="post" style="margin-top:20px;">
			<label>مبلغ شارژ (تومان):</label>
			<input type="number" name="amount" min="100000" step="10000"
			       style="padding:6px;min-width:30%;border:1px solid #cdcdcd;border-radius:5px;">
			       <style>
			           button.btn.button {
                            font-size: 12pt;
                            padding: 10px;
                            border-radius: 5px;
                        }
			       </style>
			<button class="btn button" type="submit" name="wallet_charge" class="button">پرداخت و شارژ</button>
		</form>

		<?php
		/* ----------  جدول سوابق سفارش‌های شارژ  ---------- */
		$orders = wc_get_orders( [
			'customer_id' => $user_id,
			'limit'       => -1,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'meta_key'    => 'wallet_topup',
		] );

		if ( $orders ) : ?>
		    <h4 style="margin-top:30px">جدوال پرداخت های شما</h4>
			<table>
				<thead>
					<tr>
						<th>#</th><th>تاریخ</th><th>مبلغ شارژ</th><th>وضعیت سفارش</th>
					</tr>
				</thead>
				<tbody>
				<?php
				$i = 1;
				foreach ( $orders as $o ) :
					$amount = (float) $o->get_meta( 'wallet_topup' );
					$status = wc_get_order_status_name( $o->get_status() );
					$date   = $o->get_date_created()->date_i18n( 'Y/m/d H:i' ); ?>
					<tr>
						<td><?php echo $i++; ?></td>
						<td><?php echo esc_html( $date ); ?></td>
						<td><?php echo wc_price( $amount ); ?></td>
						<td><?php echo esc_html( $status ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p style="margin-top:20px;">هیچ تراکنشی برای شارژ کیف پول ثبت نشده است.</p>
		<?php endif; ?>
        <h4>جدول تراکنش های سامانه</h4>
		<?php
		/* ----------  جدول لاگ‌های دستی/درآمد دوره‌ها  ---------- */
		$logs = (array) get_user_meta( $user_id, CRM_WALLET_LOG, true );
		usort( $logs, fn( $a, $b ) => strtotime( $b['date'] ) <=> strtotime( $a['date'] ) );

		if ( $logs ) : ?>
			<table class="log-table">
				<thead>
					<tr>
						<th>تاریخ</th><th>مبلغ</th><th>پرداخت بابت</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $logs as $l ) : ?>
					<tr>
						<td><?php echo esc_html( date_i18n( 'Y/m/d H:i', strtotime( $l['date'] ) ) ); ?></td>
						<td><?php echo wc_price( $l['amount'] ); ?></td>
						<td><?php echo esc_html( $l['note'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<?php
	return ob_get_clean();
} );


add_shortcode( 'crm_user_courses', function () {

	if ( ! is_user_logged_in() ) {
		return '<p style="text-align:center;color:#c00;">برای مشاهدهٔ دوره‌های خریداری‌شده ابتدا وارد شوید.</p>';
	}

	$user_id = get_current_user_id();
	$rows    = [];

	/* ---- تمام سفارش‌های کاربر ---- */
	$orders = wc_get_orders( [
		'customer_id' => $user_id,
		'limit'       => -1,
		'orderby'     => 'date',
		'order'       => 'DESC',
		'status'      => [ 'completed','processing','pending','on-hold' ],
	] );

	foreach ( $orders as $order ) {
		foreach ( $order->get_items() as $it ) {

			/* محصول مربوط به دوره است؟ */
			$course_id = (int) get_post_meta( $it->get_product_id(), CRM_LINKED_COURSE, true );
			if ( ! $course_id ) { continue; }

			$rows[] = [
				'course_id'  => $course_id,
				'order_id'   => $order->get_id(),
				'order_date' => $order->get_date_created()->date_i18n( 'Y/m/d' ),
				'amount'     => $it->get_total(),             // مبلغ همان آیتم
				'status'     => wc_get_order_status_name( $order->get_status() ),
			];
		}
	}

	if ( ! $rows ) {
		return '<p>تا کنون دوره‌ای خریداری نکرده‌اید.</p>';
	}

	/* ---- خروجی جدول ---- */
	ob_start(); ?>
	<style>
		.user-courses-table{width:100%;border-collapse:collapse;margin:20px 0}
		.user-courses-table th,.user-courses-table td{border:1px solid #dcdcdc;padding:6px 10px;text-align:center;font-size:13px}
		.user-courses-table thead{background:#ff92008f;color:#000}
	</style>
	
	  <style>
    *{ font-family:'vaziri' !important; }
    .sd-container{background:#fff;border:1px solid #e0e0e0;padding:20px;margin:30px auto;font-family:Vazir,droid;font-size:14px;direction:rtl;}
    .sd-header{border-radius:5px;background:#060097;color:#fff;padding:10px;font-size:18px;font-weight:600;}
    .sd-header-warning{border-radius:5px;background:red;color:#fff;padding:10px;font-size:18px;font-weight:600;}
    .sd-instruction{margin:15px 0;color:#444;line-height:1.6;margin-bottom:20px !important;}
    .sd-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;}
    .sd-field{display:flex;flex-direction:column;}
    .sd-field label{font-weight:500;margin-bottom:6px;}
    .sd-field input,.sd-field select,.sd-field textarea{padding:8px;border:1px solid #ccc !important;border-color: #bdbdbd !important;border-radius:4px;background:#fafafa !important;font-size:14px;}
    .sd-field .helper-note{margin-top:4px;font-size:12px;background:#e6f7ff;color:#0050b3;padding:4px 6px;border-radius:3px;}
    .sd-divider{border:0;border-top:1px solid #ddd;margin:20px 0;}
    .sd-submit{text-align:left;}
    .sd-submit button{background:#1890ff;color:#fff;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:15px;}
    .sd-warning{color:#ff4d4f;font-size:13px;margin-top:12px;line-height:1.4;}
    .sd-disclaimer{color:#888;font-size:12px;margin-top:8px;line-height:1.5;}
  </style>
     <div class="sd-container">
        <div class="sd-header">لیست دوره های شما</div>
    	<table class="user-courses-table">
    		<thead>
    			<tr>
    				<th>#</th>
    				<th>نام دوره</th>
    				<th>کد دوره</th>
    				<th>تاریخ شروع</th>
    				<th>شماره سفارش</th>
    				<th>تاریخ سفارش</th>
    				<th>مبلغ پرداختی</th>
    				<th>وضعیت سفارش</th>
    				<th>اقدام</th>
    			</tr>
    		</thead>
    		<tbody>
    		<?php $i=1; foreach ( $rows as $r ) : 
    			$cid   = $r['course_id'];
    			$code  = get_post_meta( $cid, 'course_code', true );
    			$start = get_post_meta( $cid, 'start_date',  true );
    		?>
    			<tr>
    				<td><?php echo $i++; ?></td>
    				<td><?php echo esc_html( get_the_title( $cid ) ); ?></td>
    				<td><?php echo esc_html( $code ); ?></td>
    				<td><?php echo esc_html( $start ); ?></td>
    				<td>#<?php echo $r['order_id']; ?></td>
    				<td><?php echo esc_html( $r['order_date'] ); ?></td>
    				<td><?php echo wc_price( $r['amount'] ); ?></td>
    				<td><?php echo esc_html( $r['status'] ); ?></td>
    				<td>
    					<a href="<?php echo esc_url("/my-account/course-details/?course_id=$cid"); ?>">جزئیات</a>
    				</td>
    			</tr>
    		<?php endforeach; ?>
    		</tbody>
    	</table>
	</div>
	<?php
	return ob_get_clean();
} );


add_action('woocommerce_account_course-list_endpoint', function(){
  echo do_shortcode('[crm_courses_list]');
});

add_action('woocommerce_account_user-course-list_endpoint', function(){
  echo do_shortcode('[crm_user_courses]');
});


/* ============================================================
 *  [crm_course_details]  –  جدول مشخصات + دکمهٔ پرداخت
 * ============================================================*/
add_shortcode( 'crm_course_details', function ( $atts ) {

	/* ----------  تشخیص ID دوره  ---------- */
	$atts = shortcode_atts( [ 'id' => 0 ], $atts, 'crm_course_details' );
	$cid  = (int) $atts['id'];

	/* از URL ?course_id= یا ?id=  */
	if ( ! $cid && isset( $_GET['course_id'] ) ) {
		$cid = (int) $_GET['course_id'];
	}
	if ( ! $cid && isset( $_GET['id'] ) ) {
		$cid = (int) $_GET['id'];
	}

	/* اگر در قالب single-course هستیم */
	if ( ! $cid && is_singular( 'course' ) ) {
		$cid = get_the_ID();
	}

	if ( ! $cid || get_post_type( $cid ) !== 'course' ) {
		return '<p style="text-align:center;color:#c00;">دوره پیدا نشد.</p>';
	}

	/* ----------  متافیلدها ---------- */
	$fields = [
		'course_code'   => 'کد دوره',
		'course_type'   => 'نوع دوره',
		'course_level'  => 'درجه / زیرشاخه',
		'start_date'    => 'تاریخ شروع',
		'end_date'      => 'تاریخ پایان',
		'exam_date'     => 'تاریخ آزمون',
		'board'         => 'هیئت',
		'style'         => 'سبک',
		'scope'         => 'نوع',
		'gender'        => 'جنسیت',
		'attendance'    => 'حضور',
		'organizer'     => 'مسئول',
		'organizer_tel' => 'تلفن مسئول',
		'price'         => 'قیمت',
	];

	/* ----------  محصول ووکامرس ---------- */
	$prod_id = (int) get_post_meta( $cid, CRM_LINKED_PROD, true );
	if ( ! $prod_id ) {
		crm_sync_product_for_course( $cid );                         // از کد قبل
		$prod_id = (int) get_post_meta( $cid, CRM_LINKED_PROD, true );
	}
	$cart_link = wc_get_cart_url() . '?add-to-cart=' . $prod_id;

	/* ----------  خروجی ---------- */
	ob_start(); ?>
	<style>
		.crm-single-course{max-width:650px;margin:40px auto;background:#fff;border:1px solid #e0e0e0;padding:25px;direction:rtl;font-family:'vaziri'}
		.crm-single-course h2{margin-top:0;text-align:center}
		.crm-single-course table{width:100%;border-collapse:collapse;margin:20px 0}
		.crm-single-course th,.crm-single-course td{border:1px solid #dcdcdc;padding:6px 10px;text-align:center;font-size:13px}
		.crm-single-course thead{background:#ff92008f;color:#000}
		.crm-single-course tbody tr:nth-child(even){background:#f5f5f5}
		.crm-buy-btn{display:inline-block;background:#060097;color:#fff !important;padding:10px 25px;border-radius:6px;text-decoration:none;font-weight:600;margin-top:15px}
	</style>

	<div class="crm-single-course">
		<h3 class="text-center"><?php echo esc_html( get_the_title( $cid ) ); ?></h3>

		<table>
			<tbody>
			<?php foreach ( $fields as $key => $label ) :
				$val = get_post_meta( $cid, $key, true );
				if ( $key === 'price' ) { $val = wc_price( (float) $val ); }
				?>
				<tr>
					<th><?php echo esc_html( $label ); ?></th>
					<td><?php echo $val ?  $val : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p style="text-align:center">
			<a class="crm-buy-btn" href="<?php echo esc_url( $cart_link ); ?>">پرداخت و ثبت‌نام</a>
		</p>
	</div>
	<?php
	return ob_get_clean();
} );

add_action('woocommerce_account_course-details_endpoint', function(){
  echo do_shortcode('[crm_course_details]');
});


add_action( 'init', 'crm_register_shared_taxonomies', 5 );
function crm_register_shared_taxonomies(){

  // helper
  $tax = function( $slug, $singular, $plural, $hier=false, $objects=[] ){
    $labels = [
      'name'                       => $plural,
      'singular_name'              => $singular,
      'add_new_item'               => "افزودن $singular جدید",
      'edit_item'                  => "ویرایش $singular",
      'search_items'               => "جستجوی $plural",
      'all_items'                  => "همه $plural",
    ];
    $args = [
      'hierarchical'      => $hier,
      'labels'            => $labels,
      'public'            => true,
      'show_admin_column' => true,
      'rewrite'           => [ 'slug' => $slug ],
      'show_in_rest'      => true,
    ];
    register_taxonomy( $slug, $objects, $args );
  };

  $objs = [ 'course', 'competition' ];
  $tax( 'gender',        'جنسیت',        'جنسیت',        false, $objs );
  $tax( 'board',         'هیئت',          'هیئت‌ها',       true , $objs );
  $tax( 'course_type',   'نوع دوره',     'انواع دوره',   false, $objs );
  $tax( 'age_category',  'رده سنی',       'رده‌های سنی',   true , $objs );
  $tax( 'level',         'سطح',           'سطوح',         true , $objs );

  // weight_class only for competition
  $tax( 'weight_class',  'کلاس وزنی',     'کلاس‌های وزنی', false, [ 'competition' ] );
}

// Ensure taxonomies are attached to course CPT even if course was
// registered earlier in the request.
add_action( 'init', function(){
  foreach( ['gender','board','course_type','age_category','level'] as $t )
    register_taxonomy_for_object_type( $t, 'course' );
}, 20 );


add_action( 'init', 'crm_register_competition_cpt' );
function crm_register_competition_cpt(){
     $labels = [
        'name'          => 'دوره‌ها',
        'singular_name' => 'دوره',
        'add_new'       => 'افزودن دوره',
        'add_new_item'  => 'دورهٔ جدید',
        'edit_item'     => 'ویرایش دوره',
        'new_item'      => 'دورهٔ جدید',
        'view_item'     => 'مشاهدهٔ دوره',
        'search_items'  => 'جستجوی دوره',
        'menu_name'     => 'دوره‌ها',
    ];
    
    register_post_type( 'course', [
        'labels'        => $labels,
        'public'        => true,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'menu_icon'     => 'dashicons-welcome-learn-more',
        'has_archive'   => true,
        'rewrite'       => [ 'slug' => 'courses' ],
        'supports'      => [ 'title', 'editor', 'thumbnail' ],
        'show_in_rest'  => true,          // برای ویرایشگر بلاک یا API
    ] );
    
  $labels = [
    'name'               => 'مسابقات',
    'singular_name'      => 'مسابقه',
    'add_new'            => 'افزودن مسابقه',
    'add_new_item'       => 'مسابقه جدید',
    'edit_item'          => 'ویرایش مسابقه',
    'new_item'           => 'مسابقه جدید',
    'view_item'          => 'نمایش مسابقه',
    'search_items'       => 'جستجوی مسابقه',
    'not_found'          => 'موردی پیدا نشد',
    'not_found_in_trash' => 'در زباله‌دان موردی نیست',
    'menu_name'          => 'مسابقات',
  ];
  register_post_type( 'competition', [
    'labels'        => $labels,
    'public'        => true,
    'show_ui'       => true,
    'show_in_menu'  => true,
    'menu_icon'     => 'dashicons-awards',
    'has_archive'   => true,
    'rewrite'       => [ 'slug' => 'competitions' ],
    'supports'      => [ 'title', 'thumbnail' ],
    'taxonomies'    => [ 'gender','board','course_type','age_category','weight_class','level' ],
    'show_in_rest'  => true,
  ] );
}


add_action( 'add_meta_boxes', 'crm_add_unified_metaboxes' );
function crm_add_unified_metaboxes(){
  foreach( ['course','competition'] as $pt ){
    add_meta_box( 'crm_details', 'جزئیات',    'crm_details_box_cb',    $pt, 'normal', 'high' );
    add_meta_box( 'crm_payouts', 'ذی‌نفعان',  'crm_payouts_box_cb',   $pt, 'normal', 'default' );
    add_meta_box( 'crm_manual',  'حضور دستی', 'crm_manual_box_cb',    $pt, 'side',   'default' );
  }
}

/* ---------- DETAILS box ---------- */
function crm_details_box_cb( WP_Post $post ){
  wp_nonce_field( 'crm_save_details', 'crm_details_nonce' );
  $val = fn($k)=>esc_attr(get_post_meta($post->ID,$k,true));

  $common = [
    'course_code'  => 'کد',
    'course_type'  => 'نوع/عنوان کوتاه',
    'level'        => 'سطح',
    'gender'       => 'جنسیت',
    'start_date'   => 'تاریخ شروع',
    'end_date'     => 'تاریخ پایان',
    'exam_date'    => 'تاریخ آزمون',
    'board'        => 'هیئت',
    'price'        => 'قیمت (تومان)',
  ];
  $extra = $post->post_type==='competition'
          ? [ 'special_conditions' => 'شرایط خاص' ]
          : [ 'course_level' => 'درجه / زیرشاخه' ];
  $fields = $common + $extra;
  echo '<table class="form-table"><tbody>';
  foreach($fields as $k=>$label){
    $type = in_array($k,['start_date','end_date','exam_date']) ? 'text' : ($k==='price'?'number':'text');
    $step = $k==='price'? 'step="1000"' : '';
    printf('<tr><th><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" style="width:100%%" %5$s></td></tr>',
      $k,$label,$type,$val($k),$step);
  }
  echo '</tbody></table>';
}
/* ---------- PAYOUTS box (same repeater you already use) ---------- */
function crm_payouts_box_cb( WP_Post $post ){
  wp_nonce_field( 'crm_save_payouts', 'crm_payouts_nonce' );
  $rows = (array) get_post_meta( $post->ID, '_course_payouts', true );
  $user_opts = function($sel){
    $opts='';
    foreach( get_users([ 'fields'=>['ID','display_name'] ]) as $u )
      $opts.=sprintf('<option value="%d"%s>%s</option>',$u->ID,selected($u->ID,$sel,false),esc_html($u->display_name));
    return $opts;
  };
  echo '<table class="widefat" id="crm-payout-table"><thead><tr><th>کاربر</th><th>نوع</th><th>مقدار</th><th></th></tr></thead><tbody id="crm-payout-body">';
  $rowTpl = function($uid='',$type='percent',$val='') use($user_opts){
    return '<tr>
      <td><select name="payout_user_id[]" class="crm-select2" style="width:100%">'.$user_opts($uid).'</select></td>
      <td><select name="payout_type[]"><option value="percent"'.selected('percent',$type,false).'>درصد</option><option value="fixed"'.selected('fixed',$type,false).'>مبلغ ثابت</option></select></td>
      <td><input type="number" step="0.01" name="payout_value[]" value="'.$val.'"></td>
      <td><span class="dashicons dashicons-no-alt crm-remove-row" style="cursor:pointer;color:#c00"></span></td>
    </tr>';
  };
  if($rows) foreach($rows as $r) echo $rowTpl($r['user_id']??'',$r['type']??'percent',$r['value']??'');
  echo '</tbody></table><button type="button" class="button" id="crm-add-payout">افزودن</button>';
  // inline js
  ?>
  <script>jQuery(function($){
    $('#crm-add-payout').on('click',function(){ $('#crm-payout-body').append(`<?php echo addslashes($rowTpl()); ?>`); });
    $(document).on('click','.crm-remove-row',function(){ $(this).closest('tr').remove(); });
  });</script><?php
}
/* ---------- MANUAL attendees box ---------- */
function crm_manual_box_cb( WP_Post $post ){
  wp_nonce_field( 'crm_save_manual', 'crm_manual_nonce' );
  $att = (array) get_post_meta( $post->ID, '_manual_attendees', true );
  echo '<p><select multiple name="manual_attendees[]" class="crm-select2" style="width:100%">';
  foreach( get_users([ 'fields'=>['ID','display_name'] ]) as $u )
    printf('<option value="%d"%s>%s</option>',$u->ID, selected(in_array($u->ID,$att,true),true,false), esc_html($u->display_name));
  echo '</select></p><p style="font-size:12px">نگه‌داشتن CTRL برای چند انتخاب.</p>';
}


add_action( 'save_post', 'crm_save_unified_cpt_meta', 10, 3 );
function crm_save_unified_cpt_meta( $post_id, $post, $update ){
  if( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
  if( ! in_array( $post->post_type, ['course','competition'], true ) ) return;

  /* details */
  if( isset($_POST['crm_details_nonce'])){
    $keys = [ 'course_code','course_type','course_level','level','gender','start_date','end_date','exam_date','board','price','special_conditions' ];
    foreach($keys as $k) if(isset($_POST[$k])) update_post_meta($post_id,$k,sanitize_text_field($_POST[$k]));
  }

  /* payouts */
  if( isset($_POST['crm_payouts_nonce'])){
    $rows=[];
    if(!empty($_POST['payout_user_id'])){
      foreach((array)$_POST['payout_user_id'] as $i=>$uid){
        $uid=(int)$uid; if(!$uid) continue;
        $rows[]=[
          'user_id'=>$uid,
          'type'   => sanitize_text_field($_POST['payout_type'][$i]??'percent'),
          'value'  => (float)($_POST['payout_value'][$i]??0),
        ];
      }
    }
    update_post_meta($post_id,'_course_payouts',$rows);
  }

  /* manual */
  if( isset($_POST['crm_manual_nonce'])){
    $att = array_map('intval', $_POST['manual_attendees']??[] );
    update_post_meta( $post_id, '_manual_attendees', $att );
  }

  crm_sync_hidden_product( $post_id );
}

/* ---- hidden WC product sync ---- */
function crm_sync_hidden_product( $post_id ){
  if( ! class_exists('WC_Product') ) return;
  $price   = (float) get_post_meta($post_id,'price',true);
  $prod_id = (int)   get_post_meta($post_id,'_linked_product_id',true);

  if( $prod_id && ( $prod = wc_get_product($prod_id) ) ){
    $prod->set_name( get_the_title($post_id) );
    $prod->set_regular_price( $price );
    $prod->save();
  }else{
    $prod = new WC_Product_Simple();
    $prod->set_name( get_the_title($post_id) );
    $prod->set_regular_price( $price );
    $prod->set_virtual( true );
    $prod->set_catalog_visibility('hidden');
    $prod_id = $prod->save();
    update_post_meta( $post_id, '_linked_product_id',  $prod_id );
    update_post_meta( $prod_id,  '_linked_post_id',    $post_id );
  }
}

add_shortcode( 'crm_competitions_list', 'crm_competitions_list_cb' );
function crm_competitions_list_cb(){
  $q = new WP_Query([
    'post_type'      => 'competition',
    'posts_per_page' => -1,
    'orderby'        => 'date',
    'order'          => 'DESC',
  ]);
  if(!$q->have_posts()) return '<p>مسابقه‌ای موجود نیست.</p>';

  $tax_cols = ['weight_class'=>'کلاس وزنی','gender'=>'جنسیت','board'=>'هیئت','level'=>'سطح'];
  ob_start();
  echo '<table class="shop_table shop_table_responsive"><thead><tr><th>#</th><th>عنوان</th>';
  foreach($tax_cols as $lbl) echo "<th>{$lbl}</th>";
  echo '<th>قیمت</th><th>اقدام</th></tr></thead><tbody>';
  $i=1;
  while($q->have_posts()):$q->the_post();
    $pid=get_the_ID();
    echo '<tr><td>'.($i++).'</td><td>'.get_the_title().'</td>';
    foreach($tax_cols as $slug=>$lbl){
      $terms = wp_get_post_terms($pid,$slug,[ 'fields'=>'names' ]);
      echo '<td>'.($terms?implode(', ',$terms):'—').'</td>';
    }
    $price = get_post_meta($pid,'price',true);
    echo '<td>'.wc_price($price).'</td>';
    echo '<td><a href="'.esc_url('/my-account/competition-details/?competition_id='.$pid).'">جزئیات / ثبت‌نام</a></td></tr>';
  endwhile; wp_reset_postdata();
  echo '</tbody></table>';
  return ob_get_clean();
}

add_shortcode( 'crm_competition_details', 'crm_competition_details_cb' );
function crm_competition_details_cb( $atts ){
  $atts = shortcode_atts(['id'=>0],$atts);
  $cid = $atts['id'] ?: ( $_GET['competition_id']??0 );
  $cid = intval($cid);
  if(!$cid || get_post_type($cid)!=='competition') return '<p>مسابقه پیدا نشد.</p>';

  // available weight‑class terms for this post
  $weights = wp_get_post_terms($cid,'weight_class');
  $price   = get_post_meta($cid,'price',true);
  $prod_id = (int) get_post_meta($cid,'_linked_product_id',true);

  ob_start();
  ?>
  <form method="get" action="<?php echo esc_url( wc_get_cart_url() ); ?>" class="crm-competition-form">
    <input type="hidden" name="add-to-cart" value="<?php echo $prod_id; ?>">
    <h3 style="text-align:center;"><?php echo esc_html(get_the_title($cid)); ?></h3>
    <table class="shop_table"><tbody>
      <tr><th>کد</th><td><?php echo esc_html( get_post_meta($cid,'course_code',true) ); ?></td></tr>
      <tr><th>قیمت</th><td><?php echo wc_price($price); ?></td></tr>
      <?php if($conditions = get_post_meta($cid,'special_conditions',true) )
        echo '<tr><th>شرایط خاص</th><td>'.nl2br(esc_html($conditions)).'</td></tr>'; ?>
      <tr><th>کلاس وزنی</th><td>
        <select name="weight_class_term" required>
          <option value="">— انتخاب کنید —</option>
          <?php foreach($weights as $t) echo '<option value="'.$t->term_id.'">'.esc_html($t->name).'</option>'; ?>
        </select>
      </td></tr>
    </tbody></table>
    <p style="text-align:center"><button type="submit" class="button alt">پرداخت و ثبت‌نام</button></p>
  </form>
  <?php
  return ob_get_clean();
}

add_action('woocommerce_account_competitions-list_endpoint', function(){
  echo do_shortcode('[crm_competitions_list]');
});

add_action('woocommerce_account_competition-details_endpoint', function(){
  echo do_shortcode('[crm_competition_details]');
});

add_action('woocommerce_account_user-competitions-list_endpoint', function(){
  echo do_shortcode('[crm_user_competitions]');
});

add_filter('woocommerce_add_cart_item_data', function($data,$prod_id){
  if( isset($_REQUEST['weight_class_term']) )
    $data['weight_class_term'] = (int) $_REQUEST['weight_class_term'];
  return $data;
},10,2);

add_filter('woocommerce_get_item_data', function($data,$cart_item){
  if( ! empty($cart_item['weight_class_term']) ){
    $term = get_term($cart_item['weight_class_term'],'weight_class');
    if($term) $data[]=[ 'name'=>'کلاس وزنی','value'=>$term->name ];
  }
  return $data;
},10,2);

add_action('woocommerce_checkout_create_order_line_item', function($item,$cart_item){
  if( ! empty($cart_item['weight_class_term']) )
    $item->add_meta_data( 'کلاس وزنی', get_term($cart_item['weight_class_term'])->name, true );
},10,2);

add_action('admin_enqueue_scripts','crm_admin_local_assets');
function crm_admin_local_assets($hook){
  if( in_array( get_current_screen()->post_type ?? '', ['course','competition'] , true ) ){
    $dir = get_stylesheet_directory_uri().'/assets';
    wp_enqueue_style ('crm-persian-css', "$dir/css/persian-datepicker.min.css" );
    wp_enqueue_script('crm-persian-js', "$dir/js/persian-datepicker.min.js", ['jquery'], null, true );
    wp_enqueue_style ('crm-select2-css', "$dir/css/select2.min.css" );
    wp_enqueue_script('crm-select2-js', "$dir/js/select2.min.js", ['jquery'], null, true );
    wp_add_inline_script('crm-persian-js','jQuery(function($){$(".crm-select2").select2({dir:"rtl",width:"resolve"});});');
  }
}



/* ===================================================================
 * 0) نصب / به‌روزرسانی جداول ─ wp_crm_points   /   wp_crm_settings
 * ===================================================================*/
register_activation_hook( __FILE__, function () {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	$sql_points = "CREATE TABLE {$wpdb->prefix}crm_points (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint unsigned NOT NULL,
		competition_id bigint unsigned NOT NULL,
		weight_class bigint unsigned NOT NULL,
		points int NOT NULL DEFAULT 0,
		assigned_date datetime NOT NULL,
		PRIMARY KEY (id),
		KEY idx_comp (competition_id),
		KEY idx_user (user_id)
	) $charset;";
	dbDelta( $sql_points );

	$sql_settings = "CREATE TABLE {$wpdb->prefix}crm_settings (
		opt_key varchar(60) NOT NULL PRIMARY KEY,
		opt_val varchar(191) NOT NULL
	) $charset;";
	dbDelta( $sql_settings );

	// مقدارپیش‌فرض: انقضا = 365 روز
	$wpdb->replace(
		$wpdb->prefix.'crm_settings',
		[ 'opt_key' => 'points_expiry_days', 'opt_val' => '365' ],
		[ '%s', '%s' ]
	);
} );


/* ===================================================================
 * 1) زیرمنو «امتیازات / رده‌بندی» در پیشخوان
 * ===================================================================*/
add_action( 'admin_menu', function () {
	add_users_page(
		'امتیازات / رده‌بندی',
		'امتیازات / رده‌بندی',
		'manage_options',
		'crm-points-manager',
		'crm_points_manager_page'
	);
} );

/* صفحه‌ی مادر با دو تب: تنظیمات ‖ اختصاص امتیاز ‖ مشاهده رده‌بندی */
function crm_points_manager_page() {
	$tab = $_GET['tab'] ?? 'assign';
	echo '<div class="wrap"><h1 class="wp-heading-inline">مدیریت امتیازات</h1><hr>';
	echo '<h2 class="nav-tab-wrapper">';
	echo '<a class="nav-tab '.($tab==='assign'?'nav-tab-active':'').'" href="?page=crm-points-manager&tab=assign">اختصاص امتیاز</a>';
	echo '<a class="nav-tab '.($tab==='rank'?'nav-tab-active':'').'"   href="?page=crm-points-manager&tab=rank">مشاهده رده‌بندی</a>';
	echo '<a class="nav-tab '.($tab==='settings'?'nav-tab-active':'').'" href="?page=crm-points-manager&tab=settings">تنظیمات</a>';
	echo '</h2>';

	if ( $tab === 'settings' ) {
		crm_points_settings_tab();
	} elseif ( $tab === 'rank' ) {
		crm_points_view_tab();
	} else {
		crm_points_assign_tab();
	}
	echo '</div>';
}

/* ---------------- 1-a) تب تنظیمات -------------- */
function crm_points_settings_tab() {
	global $wpdb;
	if ( isset( $_POST['save_settings'] ) ) {
		check_admin_referer( 'crm_points_settings' );
		$days = max( 0, intval( $_POST['expiry_days'] ) );
		$wpdb->replace(
			$wpdb->prefix.'crm_settings',
			[ 'opt_key' => 'points_expiry_days', 'opt_val' => (string) $days ],
			[ '%s', '%s' ]
		);
		echo '<div class="updated"><p>ذخیره شد.</p></div>';
	}
	$expiry = intval( $wpdb->get_var(
		"SELECT opt_val FROM {$wpdb->prefix}crm_settings WHERE opt_key='points_expiry_days' LIMIT 1"
	) );
	?>
	<form method="post">
		<?php wp_nonce_field( 'crm_points_settings' ); ?>
		<table class="form-table">
			<tr>
				<th scope="row">انقضای امتیازها (روز)</th>
				<td><input type="number" name="expiry_days" value="<?php echo $expiry; ?>" min="0">
					<p class="description">0 = بدون انقضا</p></td>
			</tr>
		</table>
		<?php submit_button( 'ذخیره', 'primary', 'save_settings' ); ?>
	</form>
	<?php
}

/* ---------------- 1-b) تب اختصاص امتیاز -------------- */
/**
 * Tab: Assign points to an athlete
 * - admin must pick a competition explicitly
 * - competition list comes from all published `competition` posts
 */
function crm_points_assign_tab() {
	global $wpdb;

	/* ---------- save ---------- */
	if ( isset( $_POST['crm_assign_points'] ) ) {
		check_admin_referer( 'crm_assign_points' );

		$user_id        = intval( $_POST['user_id']        ?? 0 );
		$competition_id = intval( $_POST['competition_id'] ?? 0 );
		$weight_class   = intval( $_POST['weight_class']   ?? 0 );
		$points         = intval( $_POST['points']         ?? 0 );

		/* quick sanity-checks */
		if ( ! $competition_id || ! $user_id || ! $weight_class ) {
			echo '<div class="error"><p>تمام فیلدها الزامی است.</p></div>';
		} else {
			$wpdb->insert(
				$wpdb->prefix . 'crm_points',
				[
					'user_id'        => $user_id,
					'competition_id' => $competition_id,
					'weight_class'   => $weight_class,
					'points'         => $points,
					'assigned_date'  => current_time( 'mysql' ),
				],
				[ '%d', '%d', '%d', '%d', '%s' ]
			);
			echo '<div class="updated"><p>امتیاز ثبت شد.</p></div>';
		}
	}

	/* ---------- form ---------- */
	?>
	<form method="post">
		<?php wp_nonce_field( 'crm_assign_points' ); ?>
		<table class="form-table">
			<tr>
				<th>مسابقه<span style="color:#d00">*</span></th>
				<td>
                    <select name="competition_id" class="crm-select2" required>
                    	<option value=\"\">— انتخاب کنید —</option>
                    	<?php
                    	foreach ( get_posts( [
                    		'post_type'      => 'competition',
                    		'posts_per_page' => -1,
                    		'post_status'    => 'publish',
                    		'orderby'        => 'date',
                    		'order'          => 'DESC',
                    	] ) as $c ) {
                    		printf( '<option value=\"%d\">%s</option>', $c->ID, esc_html( $c->post_title ) );
                    	}
                    	?>
                    </select>
				</td>
			</tr>

			<tr>
				<th>کلاس وزنی<span style="color:#d00">*</span></th>
				<td>
				<?php
                    echo str_replace(
                    	'class=\'postform\'',
                    	'class=\"postform crm-select2\"',
                    	wp_dropdown_categories( [
                    		'taxonomy'         => 'weight_class',
                    		'name'             => 'weight_class',
                    		'show_option_none' => '— انتخاب —',
                    		'option_none_value'=> '',
                    		'hide_empty'       => false,
                    		'echo'             => 0,
                    	] )
                    );
                    ?>
				</td>
			</tr>

			<tr>
				<th>کاربر<span style="color:#d00">*</span></th>
				<td><select name="user_id" class="crm-select2" required>
            	<option value=\"\">— انتخاب —</option>
            	<?php foreach ( get_users() as $u ) {
            		printf( '<option value=\"%d\">%s</option>', $u->ID, esc_html( $u->display_name ) );
            	} ?>
            </select></td>
			</tr>

			<tr>
				<th>امتیاز<span style="color:#d00">*</span></th>
				<td><input type="number" name="points" step="1" required></td>
			</tr>
		</table>

		<?php submit_button( 'ذخیره', 'primary', 'crm_assign_points' ); ?>
	</form>
	<?php
}


/* ---------------- 1-c) تب مشاهده رده‌بندی -------------- */
/**
 * Tab: View rankings (with optional filters)
 */
function crm_points_view_tab() {
	global $wpdb;

	$comp_id = intval( $_GET['competition_id'] ?? 0 );
	$w_term  = intval( $_GET['weight_class']   ?? 0 );

	/* ---------- filter bar ---------- */
	echo '<form method="get" style="margin-bottom:15px">';
	echo '<input type="hidden" name="page" value="crm-points-manager">';
	echo '<input type="hidden" name="tab"  value="rank">';

	// competition selector (All / specific)
	echo '<select name="competition_id"  class="crm-select2" style="min-width:200px">';
	echo '<option value="0">همه مسابقات</option>';
	foreach ( get_posts( [
		'post_type'      => 'competition',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => 'date',
		'order'          => 'DESC',
	] ) as $c ) {
		printf(
			'<option value="%d"%s>%s</option>',
			$c->ID,
			selected( $comp_id, $c->ID, false ),
			esc_html( $c->post_title )
		);
	}
	echo '</select> ';

	// weight-class selector

    echo str_replace(
    	'class=\'postform\'',
    	'class=\"postform crm-select2\"',
    	wp_dropdown_categories( [
    		'taxonomy'         => 'weight_class',
    		'name'             => 'weight_class',
    		'selected'         => $w_term,
    		'show_option_none' => 'همه کلاس‌ها',
    		'option_none_value'=> 0,
    		'hide_empty'       => false,
    		'echo'             => 0,
    	] )
    );

	submit_button( 'نمایش', 'secondary', '', false );
	echo '</form>';

	/* ---------- build WHERE conditions ---------- */
	$where = 'WHERE 1=1';
	if ( $comp_id ) {
		$where .= $wpdb->prepare( ' AND competition_id = %d', $comp_id );
	}
	if ( $w_term ) {
		$where .= $wpdb->prepare( ' AND weight_class = %d', $w_term );
	}

	/* respect the expiry window */
	$expiry_days = (int) $wpdb->get_var(
		"SELECT opt_val FROM {$wpdb->prefix}crm_settings WHERE opt_key='points_expiry_days' LIMIT 1"
	);
	$expiry_sql = $expiry_days
		? $wpdb->prepare( ' AND assigned_date >= DATE_SUB( NOW(), INTERVAL %d DAY )', $expiry_days )
		: '';

	/* ---------- query totals ---------- */
	$ranking = $wpdb->get_results(
		"SELECT user_id, SUM(points) AS total_pts
		 FROM {$wpdb->prefix}crm_points
		 $where $expiry_sql
		 GROUP BY user_id
		 ORDER BY total_pts DESC"
	);

	if ( ! $ranking ) {
		echo '<p>موردی یافت نشد.</p>';
		return;
	}

	/* grand-total for this view */
	$grand_total = array_sum( wp_list_pluck( $ranking, 'total_pts' ) );
	echo '<p style="font-weight:600;margin:10px 0;"> مجموع امتیازهای فعال در این نما: '
	     . esc_html( number_format_i18n( $grand_total ) )
	     . '</p>';

	/* ---------- table ---------- */
	echo '<table class="widefat striped"><thead><tr>'
	     . '<th>#</th><th>کاربر</th><th>امتیاز</th>'
	     . '</tr></thead><tbody>';

	$pos = 1;
	foreach ( $ranking as $row ) {
		$user = get_userdata( $row->user_id );
		$img  = get_user_meta( $row->user_id, 'personal_photo', true ) ?: get_avatar_url( $row->user_id );

		printf(
			'<tr><td>%d</td><td><img src="%s" style="width:30px;border-radius:50%%;vertical-align:middle"> %s</td><td>%d</td></tr>',
			$pos++,
			esc_url( $img ),
			esc_html( $user ? $user->display_name : '—' ),
			(int) $row->total_pts
		);
	}

	echo '</tbody></table>';
}



/* ===================================================================
 * 2) شورتکد رده‌بندی یک مسابقه  [crm_competition_rankings id="123" weight="456"]
 * ===================================================================*/
function crm_competition_rankings_sc( $atts ) {
	global $wpdb;
	$a = shortcode_atts( [
		'id'     => 0,         // competition ID
		'weight' => 0,         // term_id از taxonomy وزن
	], $atts, 'crm_competition_rankings' );

	$cid  = intval( $a['id'] ?: $_GET['competition_id'] ?? 0 );
	$wt   = intval( $a['weight'] ?: $_GET['weight']      ?? 0 );
	if ( ! $cid ) return '<p>مسابقه نامشخص است.</p>';

	/* انقضا */
	$expiry = intval( $wpdb->get_var(
		"SELECT opt_val FROM {$wpdb->prefix}crm_settings WHERE opt_key='points_expiry_days'"
	) );
	$exp   = $expiry ? $wpdb->prepare( "AND assigned_date >= DATE_SUB(NOW(), INTERVAL %d DAY)", $expiry ) : '';

	$where = $wpdb->prepare( 'WHERE competition_id=%d', $cid );
	$where .= $wt ? $wpdb->prepare( ' AND weight_class=%d', $wt ) : '';

	$rows = $wpdb->get_results(
		"SELECT user_id, SUM(points) pts
		 FROM {$wpdb->prefix}crm_points
		 $where $exp
		 GROUP BY user_id
		 ORDER BY pts DESC"
	);
	if ( ! $rows ) return '<p>امتیازی ثبت نشده است.</p>';

	ob_start(); ?>
	<style>
		.crm-rank-table{width:100%;border-collapse:collapse;margin:15px 0;direction:rtl}
		.crm-rank-table th,.crm-rank-table td{border:1px solid #dcdcdc;padding:6px 8px;text-align:center;font-size:13px}
		.crm-rank-table thead{background:#ff92008f;color:#000}
	</style>
	<table class="crm-rank-table">
		<thead><tr><th>#</th><th>کاربر</th><th>امتیاز</th></tr></thead><tbody>
		<?php $i=1; foreach ( $rows as $r ) :
			$u   = get_userdata( $r->user_id );
			$img = get_user_meta( $r->user_id, 'personal_photo', true ) ?: get_avatar_url( $r->user_id ); ?>
			<tr>
				<td><?php echo $i++; ?></td>
				<td><img src="<?php echo esc_url( $img ); ?>" style="width:30px;height:30px;border-radius:50%;vertical-align:middle"> <?php echo esc_html( $u->display_name ); ?></td>
				<td><?php echo intval( $r->pts ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	return ob_get_clean();
}
add_shortcode( 'crm_competition_rankings', 'crm_competition_rankings_sc' );


/* ===================================================================
 * 3) شورتکد «امتیازات من + رده‌بندی کل»  [crm_my_rankings]
 * ===================================================================*/
function crm_my_rankings_sc() {
	if ( ! is_user_logged_in() ) return '<p>لطفاً وارد شوید.</p>';

	global $wpdb;
	$uid = get_current_user_id();

	/* انقضا */
	$expiry = intval( $wpdb->get_var(
		"SELECT opt_val FROM {$wpdb->prefix}crm_settings WHERE opt_key='points_expiry_days'"
	) );
	$exp = $expiry ? $wpdb->prepare( "AND assigned_date >= DATE_SUB(NOW(), INTERVAL %d DAY)", $expiry ) : '';

	/* جمع امتیازات کاربر در هر مسابقه + کلاس */
	$my = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT competition_id, weight_class, SUM(points) pts
			 FROM {$wpdb->prefix}crm_points
			 WHERE user_id=%d $exp
			 GROUP BY competition_id, weight_class
			 ORDER BY pts DESC",
			$uid
		)
	);

	/* رده‌بندی کلی برای محاسبه‌ی جایگاه من */
	$all = $wpdb->get_results(
		"SELECT user_id, SUM(points) pts
		 FROM {$wpdb->prefix}crm_points
		 WHERE 1=1 $exp
		 GROUP BY user_id
		 ORDER BY pts DESC"
	);

	$overall_pts = array_sum( wp_list_pluck( $my, 'pts' ) );
	$overall_rank = 0;
	foreach ( $all as $idx => $row ) {
		if ( intval( $row->user_id ) === $uid ) {
			$overall_rank = $idx + 1; break;
		}
	}

	ob_start(); ?>
	<style>
		.crm-my-rank{direction:rtl;margin:20px 0;font-family:'vaziri'}
		.crm-my-rank table{width:100%;border-collapse:collapse}
		.crm-my-rank th,.crm-my-rank td{border:1px solid #dcdcdc;padding:6px 8px;text-align:center;font-size:13px}
		.crm-my-rank thead{background:#ff92008f;color:#000}
	</style>

	<div class="crm-my-rank">
		<h3>جایگاه کلی من</h3>
		<p><strong>امتیاز کل:</strong> <?php echo $overall_pts; ?> ‖ <strong>رتبه:</strong> <?php echo $overall_rank; ?></p>

		<h3>جزئیات امتیازات</h3>
		<table>
			<thead><tr><th>#</th><th>مسابقه</th><th>کلاس وزنی</th><th>امتیاز</th></tr></thead><tbody>
			<?php $i=1; foreach ( $my as $row ) :
				$title = get_the_title( $row->competition_id );
				$term  = get_term( $row->weight_class, 'weight_class' );
			?>
				<tr>
					<td><?php echo $i++; ?></td>
					<td><?php echo esc_html( $title ); ?></td>
					<td><?php echo esc_html( $term->name ?? '—' ); ?></td>
					<td><?php echo intval( $row->pts ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'crm_my_rankings', 'crm_my_rankings_sc' );


/* ===================================================================
 * 4) افزودن لینک «امتیازات من» به منوی حساب کاربری ووکامرس
 * ===================================================================*/
add_action( 'woocommerce_account_my-rankings_endpoint', function () {
	echo do_shortcode( '[crm_my_rankings]' );
} );


/* -----------------------------------------------------------------
 *  Select2 for the Points-Manager admin screen
 * ----------------------------------------------------------------*/
add_action( 'admin_enqueue_scripts', function ( $hook ) {

	/* hook name is users_page_{slug} */
	if ( $hook !== 'users_page_crm-points-manager' ) {
		return;
	}

	// CDN assets
	$dir = get_stylesheet_directory_uri().'/assets';
	wp_enqueue_style(  'select2',  "$dir/css/select2.min.css" );
	wp_enqueue_script( 'select2', "$dir/js/select2.min.js",
		[ 'jquery' ], null, true );

	// auto-activate for every .crm-select2 element
	wp_add_inline_script( 'select2', "
		jQuery(function($){
			$('.crm-select2').select2({dir:'rtl', width:'resolve'});
		});
	" );
} );
/**
 * Load Select2 + Jalali date-picker on every WooCommerce “My Account” endpoint.
 *
 * ❶ `is_account_page()` → true on /my-account/ …  
 * ❷ `is_wc_endpoint_url()` is true for **all** endpoints (core + custom),
 *    so if you add new endpoints later you don’t have to touch this code.
 *
 *  After the assets are present we run one tiny inline-script that
 *  • upgrades every <select class="crm-select2"> to Select2 (RTL, width=auto)  
 *  • re-applies Select2 when WooCommerce refreshes fragments (e.g. after AJAX)
 *  The Jalali date-picker doesn’t need manual init — it watches every
 *  element that carries the `data-jdp` attribute by itself.
 */
add_action( 'wp_enqueue_scripts', 'crm_enqueue_myaccount_libraries' );
function crm_enqueue_myaccount_libraries() {
	$dir = get_stylesheet_directory_uri().'/assets';

	/* ─────  Select2  ───── */
	wp_enqueue_style(
		'crm-select2',
		"$dir/css/select2.min.css",
		[],
		null
	);
	wp_enqueue_script(
		'crm-select2',
		"$dir/js/select2.min.js",
		[ 'jquery' ],
		null,
		true
	);

	/* ─────  Jalali date-picker  ───── */
	wp_enqueue_style(
		'crm-jalali-datepicker',
		"$dir/css/jalalidatepicker.min.css",
		[],
		null
	);
	wp_enqueue_script(
		'crm-jalali-datepicker',
		"$dir/js/jalalidatepicker.min.js",
		[ 'jquery' ],
		null,
		true
	);

	/* ─────  one-shot initialisation  ───── */
	wp_add_inline_script( 'crm-jalali-datepicker', "
		jQuery( function ( $ ) {

			function initSelect2() {
				if ( $.fn.select2 ) {
					$('.crm-select2').select2({ dir: 'rtl', width: 'resolve' });
				}
			}

			/* initial run */
			initSelect2();

			/* run again after WooCommerce does an AJAX refresh */
			$( document.body ).on( 'updated_checkout updated_wc_div', initSelect2 );
			
			jalaliDatepicker.startWatch();
		} );
	" );
}
