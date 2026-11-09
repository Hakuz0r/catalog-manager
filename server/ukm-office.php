<?php
/** Plugin Name: UralKM Office — private stage API
 * Version: 0.3.0
 */
defined('ABSPATH') || exit;
// This first server integration must never expose writes on the production copy.
if (!defined('UKM_AUDIT_STAGE') || !UKM_AUDIT_STAGE) return;
final class UKM_Office_API {
    const NS = 'ukm-office/v1';
    static function err($code, $text, $status=400) { return new WP_Error($code,$text,['status'=>$status]); }
    static function allowed() { return current_user_can('ukm_office_access') && !get_user_meta(get_current_user_id(),'_ukm_office_disabled',true); }
    static function table() { global $wpdb; return $wpdb->prefix.'ukm_office_changes'; }
    static function install() {
        global $wpdb;
        if (get_option('ukm_office_schema') !== '1') {
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $table=self::table();$collate=$wpdb->get_charset_collate();
            dbDelta("CREATE TABLE $table (id bigint unsigned NOT NULL AUTO_INCREMENT, product_id bigint unsigned NOT NULL, user_id bigint unsigned NOT NULL, created_at datetime NOT NULL, kind varchar(12) NOT NULL DEFAULT 'edit', before_json longtext NOT NULL, after_json longtext NOT NULL, after_hash varchar(64) NOT NULL, undone_by bigint unsigned DEFAULT NULL, PRIMARY KEY (id), KEY product_id (product_id)) $collate;");
            update_option('ukm_office_schema','1',false);
        }
        add_role('ukm_catalog_editor','Редактор каталога УралКМ',['read'=>true,'ukm_office_access'=>true,'edit_products'=>true,'edit_published_products'=>true,'edit_others_products'=>true,'edit_private_products'=>true,'read_private_products'=>true,'assign_product_terms'=>true]);
    }
    static $auth_error = null;
    // The same throttle covers the office endpoint and native WordPress authentication.
    static function login_key($login) { $account=get_user_by('login',$login);if(!$account&&is_email($login))$account=get_user_by('email',$login);if($account)$login=$account->user_login;return 'ukmo_login_'.hash_hmac('sha256',mb_strtolower(trim($login)),wp_salt('auth')); }
    static function throttle($user,$login,$password) {
        if(!is_string($login)||!is_string($password)||strlen($login)>200||strlen($password)>4096)return self::err('login_input','Проверьте данные для входа.',400);
        if($login===''||$password==='')return $user;
        global $wpdb;$lock='ukmo_auth_'.md5(DB_NAME);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))!==1)return self::err('office_throttled','Слишком много попыток входа. Повторите позже.',429);
        try {
            $keys=[self::login_key($login)=>10,'ukmo_ip_'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'local',wp_salt('auth'))=>100];
            foreach($keys as $key=>$limit){$bucket=get_transient($key);if(is_array($bucket)&&$bucket['until']>time()&&$bucket['count']>=$limit)return self::err('office_throttled','Слишком много попыток входа. Повторите через 15 минут.',429);}
            foreach($keys as $key=>$limit){$bucket=get_transient($key);if(!is_array($bucket)||$bucket['until']<=time())$bucket=['count'=>0,'until'=>time()+900];$bucket['count']++;set_transient($key,$bucket,max(1,$bucket['until']-time()));}
            return $user;
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    static function boot() {
        add_filter('authenticate',static function($user,$login,$password){self::$auth_error=null;$result=self::throttle($user,$login,$password);if(is_wp_error($result)&&in_array($result->get_error_code(),['office_throttled','login_input'],true))self::$auth_error=$result;return $result;},5,3);
        // Core password filters can replace WP_Error; enforce the early throttle again last.
        add_filter('authenticate',static function($user){if(self::$auth_error)return self::$auth_error;if($user instanceof WP_User&&get_user_meta($user->ID,'_ukm_office_disabled',true))return self::err('office_disabled','Не удалось войти. Проверьте данные или обратитесь к управляющему.',403);return $user;},PHP_INT_MAX);
        add_filter('user_has_cap',static function($caps,$required,$args,$user){return get_user_meta($user->ID,'_ukm_office_disabled',true)?[]:$caps;},PHP_INT_MAX,4);
        add_action('wp_login',static function($login){delete_transient(self::login_key($login));});
        add_filter('rest_pre_dispatch',static function($result,$server,$request){
            if(strpos($request->get_route(),'/'.self::NS.'/')!==0)return $result;
            $origin=rtrim($_SERVER['HTTP_ORIGIN']??'','/');
            if($origin&&!in_array($origin,[rtrim(home_url(),'/'),'http://localhost:5173','http://127.0.0.1:5173'],true))return self::err('origin','Недопустимый источник запроса.',403);
            if(strlen($request->get_body())>3*1024*1024)return self::err('size','Запрос превышает 3 МБ.',413);
            if(in_array($request->get_method(),['POST','PUT','PATCH'],true)&&strpos((string)$request->get_header('content-type'),'application/json')!==false){$body=$request->get_json_params();if(is_wp_error($body))return $body;if(!is_array($body)||($body!==[]&&array_is_list($body)))return self::err('body','Передайте объект JSON.',400);}
            return $result;
        },10,3);
        add_action('init',[__CLASS__,'install']);
        foreach (['session','login','logout'] as $action) {
            add_action('wp_ajax_ukm_office_'.$action,static function() use ($action) { self::auth($action); });
            add_action('wp_ajax_nopriv_ukm_office_'.$action,static function() use ($action) { self::auth($action); });
        }
        add_action('rest_api_init',function() {
            $auth=static fn()=>self::allowed() ?: self::err('office_forbidden','Требуется вход с правами на каталог.',401);
            foreach ([['/categories','GET','categories'],['/products','GET','products'],['/products/(?P<id>\d+)','GET','product'],['/products/(?P<id>\d+)','POST','save'],['/history','GET','history'],['/history/(?P<id>\d+)/undo','POST','undo']] as [$route,$method,$callback]) register_rest_route(self::NS,$route,['methods'=>$method,'callback'=>[__CLASS__,$callback],'permission_callback'=>$auth]);
        });
        add_filter('rest_post_dispatch',static function($response,$server,$request) {
            if(strpos($request->get_route(),'/'.self::NS.'/')===0){$response->header('Cache-Control','private, no-store');$response->header('X-Robots-Tag','noindex');}
            return $response;
        },10,3);
    }
    static function auth($action) {
        nocache_headers();
        $origin=rtrim($_SERVER['HTTP_ORIGIN']??'','/');
        $allowed=[rtrim(home_url(),'/'),'http://localhost:5173','http://127.0.0.1:5173'];
        if (($_SERVER['HTTP_X_UKM_OFFICE']??'')!=='1' || ($origin && !in_array($origin,$allowed,true))) wp_send_json(['message'=>'Недопустимый источник запроса.'],403);
        if ($action==='login') {
            if ($_SERVER['REQUEST_METHOD']!=='POST') wp_send_json(['message'=>'Используйте POST.'],405);
            $input=json_decode(file_get_contents('php://input'),true);
            if(strlen(file_get_contents('php://input'))>8192||!is_array($input)||!is_string($input['login']??null)||!is_string($input['password']??null))wp_send_json(['message'=>'Введите логин и пароль.'],400);
            $user=wp_authenticate($input['login'],$input['password']);
            if(is_wp_error($user)&&in_array($user->get_error_code(),['office_throttled','ukm_login_locked'],true)){header('Retry-After: '.($user->get_error_code()==='ukm_login_locked'?1800:900));wp_send_json(['message'=>$user->get_error_message()],429);}
            if(is_wp_error($user)&&$user->get_error_code()==='office_disabled')wp_send_json(['message'=>'У этой записи нет прав на панель каталога.'],403);
            if(is_wp_error($user))wp_send_json(['message'=>'Не удалось войти. Проверьте логин и пароль или повторите позже.'],401);
            if(!user_can($user,'ukm_office_access')||get_user_meta($user->ID,'_ukm_office_disabled',true))wp_send_json(['message'=>'У этой записи нет прав на панель каталога.'],403);
            wp_set_current_user($user->ID);
            $capture=static function($cookie){$_COOKIE[LOGGED_IN_COOKIE]=$cookie;};
            add_action('set_logged_in_cookie',$capture);wp_set_auth_cookie($user->ID,false,is_ssl());remove_action('set_logged_in_cookie',$capture);
            do_action('wp_login',$user->user_login,$user);
        }
        if($action==='logout') {
            if($_SERVER['REQUEST_METHOD']!=='POST'||!wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE']??'','wp_rest'))wp_send_json(['message'=>'Обновите страницу и повторите выход.'],403);
            wp_logout();wp_send_json(['authenticated'=>false]);
        }
        if(!self::allowed())wp_send_json(['authenticated'=>false]);
        wp_send_json(['authenticated'=>true,'nonce'=>wp_create_nonce('wp_rest'),'user'=>['id'=>get_current_user_id(),'name'=>wp_get_current_user()->display_name],'maxUploadBytes'=>min(8*1024*1024,wp_max_upload_size()),'permissions'=>['edit'=>current_user_can('edit_products'),'create'=>current_user_can('ukm_office_create_products'),'catalog'=>current_user_can('ukm_office_manage_catalog'),'staff'=>current_user_can('ukm_office_manage_staff'),'publish'=>current_user_can('publish_products')],'environment'=>'stage']);
    }
    static function categories() {
        $terms=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
        if(is_wp_error($terms))return $terms;
        return array_map(static fn($t)=>['id'=>$t->term_id,'name'=>html_entity_decode($t->name,ENT_QUOTES,'UTF-8'),'parent'=>$t->parent,'catalogCount'=>$t->count,'sampleCount'=>0],$terms);
    }
    static function attrs($p) {
        $out=[];
        foreach($p->get_attributes() as $a) {
            if(!($a instanceof WC_Product_Attribute))continue;
            $values=$a->is_taxonomy()?wc_get_product_terms($p->get_id(),$a->get_name(),['fields'=>'names']):$a->get_options();
            if(is_wp_error($values))continue;
            $out[]=['name'=>wc_attribute_label($a->get_name()),'values'=>array_values($values),'key'=>$a->get_name(),'taxonomy'=>$a->is_taxonomy(),'id'=>$a->get_id()];
        }
        return $out;
    }
    static function snapshot($p) {
        $a=[];foreach($p->get_attributes() as $attr)$a[]=$attr->get_data();
        $meta=[];foreach(['_wcaci_prices','_price_metr','_price_tonna','ukm_price_unit','count_value'] as $key)$meta[$key]=['exists'=>metadata_exists('post',$p->get_id(),$key),'value'=>get_post_meta($p->get_id(),$key,true)];
        return ['name'=>$p->get_name('edit'),'regular'=>$p->get_regular_price('edit'),'sale'=>$p->get_sale_price('edit'),'price'=>$p->get_price('edit'),'categories'=>$p->get_category_ids(),'attributes'=>$a,'meta'=>$meta,'description'=>$p->get_description('edit'),'shortDescription'=>$p->get_short_description('edit'),'sku'=>$p->get_sku('edit'),'image'=>$p->get_image_id(),'gallery'=>$p->get_gallery_image_ids(),'status'=>$p->get_status(),'type'=>$p->get_type()];
    }
    static function hash($snap) {return hash('sha256',wp_json_encode($snap));}
    static function pack($p) {
        $cats=$p->get_category_ids();$category=$cats?end($cats):0;
        // Prefer a leaf category while preserving every original category on save.
        foreach($cats as $id){$parent=false;foreach($cats as $other)if($id!==$other&&term_is_ancestor_of($id,$other,'product_cat'))$parent=true;if(!$parent){$category=$id;break;}}
        $info=class_exists('Ukm_Catalog_Base_Price')?Ukm_Catalog_Base_Price::inspect($p):null;
        $calc=$info['calculation']??[];$old=class_exists('UralKM_Cart_Units')?UralKM_Cart_Units::catalog($p):[];
        return ['id'=>$p->get_id(),'name'=>$p->get_name('edit'),'categoryId'=>$category,'categoryIds'=>$cats,'url'=>get_permalink($p->get_id()),'basePrice'=>(float)$p->get_price('edit')<=0?null:(float)$p->get_price('edit'),'baseUnit'=>$calc['base_unit']??$old['native']??null,'attributes'=>self::attrs($p),'calculatedPrices'=>(object)($calc['prices']??[]),'unitFactors'=>(object)($calc['factors']??[]),'coefficients'=>(object)($info['coefficients']??[]),'calculationAvailable'=>(bool)$info,'revision'=>self::hash(self::snapshot($p)),'status'=>$p->get_status(),'description'=>$p->get_description('edit'),'shortDescription'=>$p->get_short_description('edit'),'sku'=>$p->get_sku('edit'),'imageUrl'=>get_the_post_thumbnail_url($p->get_id(),'woocommerce_thumbnail')?:null,'images'=>array_values(array_filter(array_map(static fn($id)=>$id?['id'=>(int)$id,'url'=>wp_get_attachment_image_url($id,'large')?:wp_get_attachment_url($id),'alt'=>get_post_meta($id,'_wp_attachment_image_alt',true)]:null,array_merge([$p->get_image_id()],$p->get_gallery_image_ids()))))];
    }
    static function products($r) {
        global $wpdb;
        $page=max(1,min(100000,(int)($r['page']??1)));$limit=max(1,min(50,(int)($r['per_page']??10)));
        $args=['post_type'=>'product','post_status'=>['publish','draft','pending','private'],'posts_per_page'=>$limit,'paged'=>$page,'orderby'=>'title','order'=>($r['order']??'asc')==='desc'?'DESC':'ASC','ukm_office'=>true];
        if((int)$r['category'])$args['tax_query']=[['taxonomy'=>'product_cat','field'=>'term_id','terms'=>(int)$r['category']]];
        $q=trim((string)$r['search']);
        $filter=static function($where,$query) use($q,$wpdb) {
            if(!$query->get('ukm_office')||$q==='')return $where;
            $like='%'.$wpdb->esc_like(mb_substr($q,0,200)).'%';
            return $where.$wpdb->prepare(" AND ($wpdb->posts.post_title LIKE %s OR $wpdb->posts.ID = %d OR EXISTS (SELECT 1 FROM $wpdb->term_relationships tr JOIN $wpdb->term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN $wpdb->terms t ON t.term_id=tt.term_id WHERE tr.object_id=$wpdb->posts.ID AND tt.taxonomy LIKE 'pa_%%' AND t.name LIKE %s) OR EXISTS (SELECT 1 FROM $wpdb->postmeta pm WHERE pm.post_id=$wpdb->posts.ID AND pm.meta_key IN ('_product_attributes','_sku') AND pm.meta_value LIKE %s))",$like,ctype_digit($q)?(int)$q:0,$like,$like);
        };
        $meta=[];
        if(($r['price_state']??'')==='priced')$meta[]=['key'=>'_price','value'=>0,'compare'=>'>','type'=>'DECIMAL(20,6)'];
        if(($r['price_state']??'')==='missing')$meta[]=['relation'=>'OR',['key'=>'_price','compare'=>'NOT EXISTS'],['key'=>'_price','value'=>'','compare'=>'='],['key'=>'_price','value'=>0,'compare'=>'<=','type'=>'DECIMAL(20,6)']];
        $bounds=[];foreach(['min_price'=>'>=','max_price'=>'<='] as $param=>$compare){$raw=$r[$param];if($raw===null||$raw==='')continue;$value=str_replace(',','.',trim((string)$raw));if(!preg_match('/^\d+(?:\.\d+)?$/D',$value)||!is_finite((float)$value)||(float)$value>1e12)return self::err('price_filter','Введите корректные границы цены.');$bounds[$param]=(float)$value;$meta[]=['key'=>'_price','value'=>(float)$value,'compare'=>$compare,'type'=>'DECIMAL(20,6)'];}
        if(isset($bounds['min_price'],$bounds['max_price'])&&$bounds['min_price']>$bounds['max_price'])return self::err('price_filter','Цена «от» не должна быть больше цены «до».');
        if($meta)$args['meta_query']=array_merge(['relation'=>'AND'],$meta);
        add_filter('posts_where',$filter,10,2);$query=new WP_Query($args);remove_filter('posts_where',$filter,10);
        $items=[];foreach($query->posts as $post){$p=wc_get_product($post->ID);if($p&&current_user_can('read_post',$p->get_id()))$items[]=self::pack($p);}
        $counts=wp_count_posts('product');
        return ['items'=>$items,'total'=>(int)$query->found_posts,'page'=>$page,'pages'=>(int)$query->max_num_pages,'catalogTotal'=>(int)$counts->publish,'environment'=>'stage'];
    }
    static function product($r) {
        $p=wc_get_product((int)$r['id']);if(!$p||!current_user_can('read_post',$p->get_id()))return self::err('not_found','Товар не найден или недоступен.',404);
        if($p->is_type('variation'))return self::err('unsupported_type','Отдельные вариации подключим позже.',422);
        return self::pack($p);
    }
    static function validate($body,$p,$allow_missing_terms=false) {
        if(!is_array($body)||!is_string($body['revision']??null)||!is_array($body['draft']??null))return self::err('invalid_body','Не передана версия товара.');
        if(!$p->is_type('simple'))return self::err('unsupported_type','Редактирование этого типа товара ещё не подключено.',422);
        $d=$body['draft'];
        if(!is_string($d['name']??null)||!trim(sanitize_text_field($d['name']))||mb_strlen($d['name'])>300)return self::err('invalid_name','Укажите название длиной до 300 символов.');
        $term=get_term((int)($d['categoryId']??0),'product_cat');if(!$term||is_wp_error($term))return self::err('invalid_category','Категория не найдена.');
        if(!array_key_exists('basePrice',$d))return self::err('missing_price','Не передана основная цена.');
        $price=$d['basePrice'];if($price!==null&&(!is_numeric($price)||!is_finite((float)$price)||(float)$price<=0||(float)$price>1e12))return self::err('invalid_price','Цена должна быть положительным числом не более 1 трлн.');
        if(!is_array($d['attributes']??null)||count($d['attributes'])>150)return self::err('invalid_attributes','Список характеристик некорректен.');
        $seen=[];$existing=$p->get_attributes();$oldValues=self::attrs($p);
        foreach($d['attributes'] as $a){
            if(!is_array($a)||!is_string($a['name']??null)||!trim($a['name'])||mb_strlen($a['name'])>150||!is_array($a['values']??null)||!count($a['values'])||count($a['values'])>200)return self::err('invalid_attribute','Заполните название и значения характеристики.');
            if(isset($a['key'])&&!is_string($a['key']))return self::err('attribute_key','Некорректный ключ характеристики.');
            if(isset($a['taxonomy'])&&!is_bool($a['taxonomy']))return self::err('attribute_type','Некорректный тип характеристики.');
            $key=!empty($a['taxonomy'])&&is_string($a['key']??null)?'tax:'.$a['key']:'local:'.mb_strtolower(trim($a['name']));if(isset($seen[$key]))return self::err('duplicate_attribute','Названия характеристик повторяются.');$seen[$key]=true;
            foreach($a['values'] as $v)if(!is_string($v)||!trim($v)||mb_strlen($v)>1000)return self::err('invalid_value','Значение характеристики должно содержать от 1 до 1000 символов.');
            if(empty($a['taxonomy'])&&class_exists('UKM_Office_Catalog')){
                $unchanged=false;foreach($oldValues as $old)if($old['name']===$a['name']&&$old['values']===$a['values'])$unchanged=true;
                if(!$unchanged)foreach($a['values'] as $value){$check=UKM_Office_Catalog::value($value,$a['name']);if(is_wp_error($check))return $check;}
            }
            if(!empty($a['taxonomy'])){
                $tax=$a['key']??'';
                if(!is_string($tax)||strpos($tax,'pa_')!==0||!wc_attribute_taxonomy_id_by_name($tax)||!taxonomy_exists($tax)||wc_attribute_label($tax)!==$a['name'])return self::err('invalid_taxonomy','Переименование общего справочника выполняется отдельно.');
                foreach($a['values'] as $v)if(!$allow_missing_terms&&!get_term_by('name',$v,$tax))return self::err('unknown_term','Значение «'.$v.'» отсутствует в справочнике «'.$a['name'].'». Добавление значений справочника подключим отдельно.',422);
            }
        }
        if(isset($d['baseUnit'])){
            $native=$p->get_id()?self::pack($p)['baseUnit']:'native';
            if(!is_string($d['baseUnit'])||(!in_array($d['baseUnit'],['metr','kg','tonna','sht','m2'],true)&&$d['baseUnit']!==$native)||($native!=='native'&&$native!==$d['baseUnit']))return self::err('unit','Изменение уже заданной основной единицы требует отдельного пересчёта.',422);
        }
        if(isset($d['status'])&&(!in_array($d['status'],['draft','publish','pending','private'],true)||($d['status']==='publish'&&!current_user_can('publish_products'))))return self::err('invalid_status','Недостаточно прав для выбранного статуса.',403);
        foreach(['description','shortDescription'] as $field)if(isset($d[$field])&&(!is_string($d[$field])||strlen($d[$field])>200000))return self::err('description','Описание слишком длинное.');
        if(isset($d['sku'])&&(!is_string($d['sku'])||mb_strlen($d['sku'])>100))return self::err('sku','Артикул должен быть короче 100 символов.');
        return null;
    }
    static function write($p,$d) {
        if(isset($d['baseUnit'])&&in_array($d['baseUnit'],['metr','kg','tonna','sht','m2'],true))$p->update_meta_data('ukm_price_unit',['metr'=>'метр','kg'=>'кг','tonna'=>'тонна','sht'=>'шт.','m2'=>'м²'][$d['baseUnit']]);
        $packed=self::pack($p);$priceChanged=$packed['basePrice']!==($d['basePrice']===null?null:(float)$d['basePrice']);
        if($priceChanged){
            if($p->get_sale_price('edit')!=='')throw new RuntimeException('У товара есть акционная цена. Сначала требуется отдельное правило изменения акции.');
            $unit=$packed['baseUnit'];if(!$unit||$unit==='native')throw new RuntimeException('Основная единица товара не определена. Изменение цены пока заблокировано.');
            $value=$d['basePrice']===null?'':wc_format_decimal($d['basePrice'],2);
            if($value!==''&&(float)$value<=0)throw new RuntimeException('После округления цена должна быть больше нуля.');
            $p->set_regular_price($value);$p->set_price($value);
            $old=$p->get_meta('_wcaci_prices',true);if(is_array($old)){foreach($old as $label=>$raw)if(UralKM_Cart_Units::key($label)===$unit){if($value==='')unset($old[$label]);else $old[$label]=$value;}$p->update_meta_data('_wcaci_prices',$old);}
            foreach(['metr'=>'_price_metr','tonna'=>'_price_tonna'] as $key=>$meta)if($unit===$key&&metadata_exists('post',$p->get_id(),$meta))$p->update_meta_data($meta,$value);
            // Preserve the native unit when old tariff matching no longer identifies it.
            $labels=['metr'=>'метр','tonna'=>'тонна','kg'=>'кг','sht'=>'шт.','m2'=>'м²'];if(isset($labels[$unit]))$p->update_meta_data('ukm_price_unit',$labels[$unit]);
        }
        if(array_key_exists('description',$d)&&$d['description']!==$p->get_description('edit'))$p->set_description(wp_kses_post($d['description']));
        if(array_key_exists('shortDescription',$d)&&$d['shortDescription']!==$p->get_short_description('edit'))$p->set_short_description(wp_kses_post($d['shortDescription']));
        if(array_key_exists('sku',$d)&&$d['sku']!==$p->get_sku('edit'))$p->set_sku(sanitize_text_field($d['sku']));
        if(array_key_exists('status',$d))$p->set_status($d['status']);
        if($d['name']!==$p->get_name('edit'))$p->set_name(sanitize_text_field($d['name']));
        if($packed['categoryId']!==(int)$d['categoryId']){
            $new=(int)$d['categoryId'];$cats=array_values(array_diff($p->get_category_ids(),[$packed['categoryId']]));
            foreach(get_ancestors($packed['categoryId'],'product_cat','taxonomy') as $ancestor){$needed=false;foreach($cats as $other)if($other!==$ancestor&&term_is_ancestor_of($ancestor,$other,'product_cat'))$needed=true;if(!$needed)$cats=array_values(array_diff($cats,[$ancestor]));}
            $p->set_category_ids(array_values(array_unique(array_merge($cats,[$new],get_ancestors($new,'product_cat','taxonomy')))));
        }
        if($d['attributes'] !== self::attrs($p)) {
        $attributes=[];$old=$p->get_attributes();
        foreach($d['attributes'] as $i=>$a){
            $attr=new WC_Product_Attribute();$original=$old[$a['key']??'']??null;
            if(!empty($a['taxonomy'])){
                $attr->set_id(wc_attribute_taxonomy_id_by_name($a['key']));$attr->set_name($a['key']);$ids=[];foreach($a['values'] as $v){$term=get_term_by('name',$v,$a['key']);if(!$term)throw new RuntimeException('Значение справочника изменилось. Обновите карточку.');$ids[]=(int)$term->term_id;}$attr->set_options($ids);
            }else{$attr->set_id(0);$attr->set_name(sanitize_text_field($a['name']));$attr->set_options(array_map('sanitize_text_field',$a['values']));}
            $attr->set_visible($original?$original->get_visible():true);$attr->set_variation($original?$original->get_variation():false);$attr->set_position($i);$attributes[]=$attr;
        }
        $p->set_attributes($attributes);
        }
        $p->save();wc_delete_product_transients($p->get_id());clean_post_cache($p->get_id());
    }
    static function fresh($id) {clean_post_cache($id);return wc_get_product($id);}
    static function lock($id) {global $wpdb;$wpdb->query('START TRANSACTION');$wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID=%d FOR UPDATE",$id));$wpdb->get_results($wpdb->prepare("SELECT meta_id FROM $wpdb->postmeta WHERE post_id=%d FOR UPDATE",$id));}
    static function log($id,$before,$after,$kind='edit') {global $wpdb;$ok=$wpdb->insert(self::table(),['product_id'=>$id,'user_id'=>get_current_user_id(),'created_at'=>current_time('mysql',true),'kind'=>$kind,'before_json'=>wp_json_encode($before),'after_json'=>wp_json_encode($after),'after_hash'=>self::hash($after)]);if(!$ok)throw new RuntimeException('Не удалось сохранить историю.');return (int)$wpdb->insert_id;}
    static function save($r) {
        global $wpdb;$id=(int)$r['id'];$p=wc_get_product($id);if(!$p||!current_user_can('edit_post',$id))return self::err('not_found','Товар не найден или недоступен.',404);
        $body=$r->get_json_params();$error=self::validate($body,$p);if($error)return $error;
        self::lock($id);
        try {
            $p=self::fresh($id);$before=self::snapshot($p);
            if(!hash_equals(self::hash($before),$body['revision'])){$wpdb->query('ROLLBACK');return self::err('version_conflict','Товар уже изменён. Обновите его перед сохранением.',409);}
            self::write($p,$body['draft']);$after=self::snapshot(self::fresh($id));
            if(self::hash($before)!==self::hash($after))self::log($id,$before,$after);
            $wpdb->query('COMMIT');return self::pack(self::fresh($id));
        }catch(Throwable $e){$wpdb->query('ROLLBACK');clean_post_cache($id);return self::err('save_failed',$e->getMessage(),422);}
    }
    static function can_undo($row,$p){
        if(!$p||!current_user_can('edit_post',$p->get_id())||!in_array($row->kind,['edit','create'],true)||$row->undone_by)return false;
        $recorded=json_decode($row->after_json,true);if(!is_array($recorded))return false;
        $current=self::snapshot($p);$projected=array_intersect_key($current,$recorded);
        if(!hash_equals($row->after_hash,self::hash($projected)))return false;
        global $wpdb;$table=self::table();return !(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE product_id=%d AND id>%d AND kind IN ('edit','create') AND undone_by IS NULL",$p->get_id(),$row->id));
    }
    static function history($r) {
        global $wpdb;$where=(int)$r['product_id']?$wpdb->prepare(' WHERE product_id=%d',(int)$r['product_id']):'';
        $rows=$wpdb->get_results('SELECT * FROM '.self::table().$where.' ORDER BY id DESC LIMIT 100');$out=[];
        foreach($rows as $row){if(!current_user_can('read_post',(int)$row->product_id))continue;$after=json_decode($row->after_json,true);$before=json_decode($row->before_json,true);$p=wc_get_product($row->product_id);$u=get_userdata($row->user_id);$out[]=['id'=>(string)$row->id,'productId'=>(int)$row->product_id,'productName'=>$after['name'],'at'=>str_replace(' ','T',$row->created_at).'Z','before'=>null,'after'=>['name'=>$after['name'],'categoryId'=>$after['categories'][0]??0,'basePrice'=>$after['price']===''?null:(float)$after['price'],'attributes'=>[]],'undone'=>(bool)$row->undone_by,'kind'=>$row->kind,'actor'=>$u?$u->display_name:'Удалённый сотрудник','canUndo'=>self::can_undo($row,$p),'summary'=>['name'=>($before['name']??'')!==$after['name'],'price'=>($before['price']??'')!==$after['price'],'attributes'=>($before['attributes']??[])!==$after['attributes'],'categories'=>($before['categories']??[])!==$after['categories']]];}
        if(class_exists('UKM_Office_Features')&&!(int)$r['product_id'])$out=array_merge($out,UKM_Office_Features::event_history());
        usort($out,static fn($a,$b)=>strcmp($b['at'],$a['at']));return array_slice($out,0,100);
    }
    static function undo($r) {
        global $wpdb;$table=self::table();$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",(int)$r['id']));if(!$row||!current_user_can('edit_post',(int)$row->product_id))return self::err('not_found','Запись не найдена.',404);
        $id=(int)$row->product_id;self::lock($id);
        try{
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE",(int)$r['id']));$p=self::fresh($id);$current=self::snapshot($p);
            if(!self::can_undo($row,$p)){$wpdb->query('ROLLBACK');return self::err('undo_conflict','После этой правки товар изменён. Сначала отмените последнюю правку или обновите данные.',409);}
            if($row->kind==='create'){
                wp_trash_post($id);$after=self::snapshot(self::fresh($id));$undo=self::log($id,$current,$after,'undo');$wpdb->update($table,['undone_by'=>$undo],['id'=>$row->id]);$wpdb->query('COMMIT');return self::pack(self::fresh($id));
            }
            $old=json_decode($row->before_json,true);$p->set_name($old['name']);$p->set_regular_price($old['regular']);$p->set_sale_price($old['sale']);$p->set_price($old['price']);$p->set_category_ids($old['categories']);
            if(isset($old['description']))$p->set_description($old['description']);if(isset($old['shortDescription']))$p->set_short_description($old['shortDescription']);if(isset($old['sku']))$p->set_sku($old['sku']);
            if(isset($old['image']))$p->set_image_id($old['image']);if(isset($old['gallery']))$p->set_gallery_image_ids($old['gallery']);$p->set_status($old['status']);$attrs=[];
            foreach($old['attributes'] as $a){$attr=new WC_Product_Attribute();$attr->set_id($a['id']);$attr->set_name($a['name']);$attr->set_options($a['options']);$attr->set_visible($a['visible']);$attr->set_variation($a['variation']);$attr->set_position($a['position']);$attrs[]=$attr;}$p->set_attributes($attrs);
            foreach($old['meta'] as $key=>$meta){if($meta['exists'])$p->update_meta_data($key,$meta['value']);else $p->delete_meta_data($key);}$p->save();wc_delete_product_transients($id);$after=self::snapshot(self::fresh($id));
            $undo=self::log($id,$current,$after,'undo');$wpdb->update($table,['undone_by'=>$undo],['id'=>$row->id]);$wpdb->query('COMMIT');return self::pack(self::fresh($id));
        }catch(Throwable $e){$wpdb->query('ROLLBACK');clean_post_cache($id);return self::err('undo_failed',$e->getMessage(),422);}
    }
}
UKM_Office_API::boot();

require_once __DIR__.'/ukm-office-features.php';
require_once __DIR__.'/ukm-office-catalog.php';
require_once __DIR__.'/ukm-office-import.php';
