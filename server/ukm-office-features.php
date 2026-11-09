<?php
/** Additional catalogue actions for the private stage. */
defined('ABSPATH') || exit;
if(!defined('UKM_AUDIT_STAGE')||!UKM_AUDIT_STAGE)return;
final class UKM_Office_Features {
    static function error($code,$message,$status=400){return UKM_Office_API::err($code,$message,$status);}
    static function events_table(){global $wpdb;return $wpdb->prefix.'ukm_office_events';}
    static function install(){
        if(get_option('ukm_office_features_schema')==='3')return;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';global $wpdb;$table=self::events_table();$c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (id bigint unsigned NOT NULL AUTO_INCREMENT, kind varchar(32) NOT NULL, object_id bigint unsigned NOT NULL, label varchar(255) NOT NULL, user_id bigint unsigned NOT NULL, created_at datetime NOT NULL, before_json longtext NOT NULL, after_json longtext NOT NULL, undone tinyint NOT NULL DEFAULT 0, PRIMARY KEY (id), KEY kind_object (kind,object_id)) $c;");
        $role=get_role('ukm_catalog_editor');if($role)foreach(['upload_files','publish_products','ukm_office_create_products','ukm_office_manage_catalog'] as $cap)$role->add_cap($cap);
        update_option('ukm_office_features_schema','3',false);
    }
    static function stage_upload_dir($d){$base=ABSPATH.'office-media';$url=home_url('/office-media');$d['basedir']=$base;$d['baseurl']=$url;$d['path']=$base.$d['subdir'];$d['url']=$url.$d['subdir'];$d['error']=false;return $d;}
    static function boot(){
        // Dedicated office users must never write into uploads shared with production,
        // including through native WordPress media endpoints.
        add_filter('upload_dir',static function($d){return get_user_meta(get_current_user_id(),'_ukm_office_member',true)?self::stage_upload_dir($d):$d;});
        add_action('init',[__CLASS__,'install'],20);
        add_action('rest_api_init',function(){
            foreach([
                ['/products','POST','create_product','ukm_office_create_products'],
                ['/categories','POST','create_category','ukm_office_manage_catalog'],
                ['/products/(?P<id>\d+)/media','POST','upload_media','upload_files'],
                ['/products/(?P<id>\d+)/photos','POST','save_photos','edit_products'],
                ['/events/(?P<id>\d+)/undo','POST','undo_event','ukm_office_manage_catalog'],
            ] as [$route,$method,$cb,$cap])register_rest_route(UKM_Office_API::NS,$route,['methods'=>$method,'callback'=>[__CLASS__,$cb],'permission_callback'=>static fn()=>UKM_Office_API::allowed()&&current_user_can($cap)?:self::error('forbidden','Для этого действия недостаточно прав.',403)]);
        });
        add_filter('wp_get_attachment_url',static function($url,$id){$own=get_post_meta($id,'_ukm_office_media_url',true);return $own?:$url;},10,2);
    }
    static function event($kind,$id,$label,$before,$after){global $wpdb;if(!$wpdb->insert(self::events_table(),['kind'=>$kind,'object_id'=>$id,'label'=>mb_substr($label,0,250),'user_id'=>get_current_user_id(),'created_at'=>current_time('mysql',true),'before_json'=>wp_json_encode($before),'after_json'=>wp_json_encode($after)]))throw new RuntimeException('Не удалось записать историю действия.');return (int)$wpdb->insert_id;}
    static function event_history(){
        global $wpdb;$out=[];
        foreach($wpdb->get_results('SELECT * FROM '.self::events_table().' ORDER BY id DESC LIMIT 100') as $r){if(strpos($r->kind,'staff_')===0&&!current_user_can('ukm_office_manage_staff'))continue;$u=get_userdata($r->user_id);$out[]=['id'=>'e'.$r->id,'productId'=>0,'productName'=>$r->label,'at'=>str_replace(' ','T',$r->created_at).'Z','before'=>null,'after'=>['name'=>$r->label,'categoryId'=>0,'basePrice'=>null,'attributes'=>[]],'undone'=>(bool)$r->undone,'kind'=>$r->kind,'actor'=>$u?$u->display_name:'Сотрудник','canUndo'=>!$r->undone&&in_array($r->kind,['category_create','attribute_create','term_create','term_edit','filters','staff_create','staff_edit'],true)&&(strpos($r->kind,'staff_')===0?current_user_can('ukm_office_manage_staff'):current_user_can('ukm_office_manage_catalog'))];}return $out;
    }
    static function category_state($id){$t=get_term($id,'product_cat');return $t&&!is_wp_error($t)?['name'=>$t->name,'parent'=>$t->parent,'slug'=>$t->slug,'description'=>$t->description]:null;}
    static function create_category($r){
        $b=$r->get_json_params();$name=sanitize_text_field($b['name']??'');$parent=(int)($b['parent']??0);
        if(!trim($name)||mb_strlen($name)>150)return self::error('name','Введите название раздела (до 150 символов).');
        if($parent&&(!get_term($parent,'product_cat')||is_wp_error(get_term($parent,'product_cat'))))return self::error('parent','Родительский раздел не найден.');
        global $wpdb;$wpdb->query('START TRANSACTION');$new=wp_insert_term($name,'product_cat',['parent'=>$parent]);if(is_wp_error($new)){$wpdb->query('ROLLBACK');return self::error('category',$new->get_error_message(),422);}
        try{$id=(int)$new['term_id'];self::event('category_create',$id,'Создан раздел: '.$name,null,self::category_state($id));$wpdb->query('COMMIT');return ['id'=>$id,'categories'=>UKM_Office_API::categories()];}catch(Throwable $e){$wpdb->query('ROLLBACK');clean_term_cache($new['term_id'],'product_cat');return self::error('category_failed',$e->getMessage(),422);}
    }
    static function undo_event($r){return UKM_Office_Catalog::mutation('undo_event_proxy',$r);}
    static function undo_event_impl($r){
        global $wpdb;$table=self::events_table();$wpdb->query('START TRANSACTION');$event=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE",(int)$r['id']));
        if(!$event||$event->undone){$wpdb->query('ROLLBACK');return self::error('event','Это действие нельзя отменить.',409);}
        if($event->kind!=='category_create'&&class_exists('UKM_Office_Catalog'))return UKM_Office_Catalog::undo_setting($event);
        $id=(int)$event->object_id;$state=self::category_state($id);
        if(wp_json_encode($state)!==$event->after_json){$wpdb->query('ROLLBACK');return self::error('changed','Раздел уже изменён. Автоматическая отмена заблокирована.',409);}
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->term_relationships tr JOIN $wpdb->term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN $wpdb->posts p ON p.ID=tr.object_id WHERE tt.term_id=%d AND tt.taxonomy='product_cat' AND p.post_status<>'trash'",$id));
        $children=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>$id,'number'=>1]);
        if($used||$children){$wpdb->query('ROLLBACK');return self::error('in_use','В разделе есть товары или подразделы. Сначала перенесите их.',409);}
        $deleted=wp_delete_term($id,'product_cat');if(is_wp_error($deleted)||!$deleted){$wpdb->query('ROLLBACK');return self::error('delete','Не удалось отменить создание раздела.',422);}
        $wpdb->update($table,['undone'=>1],['id'=>$event->id]);$wpdb->query('COMMIT');return ['ok'=>true];
    }
    static function create_product($r){
        $b=$r->get_json_params();$d=$b['draft']??[];$unit=$b['baseUnit']??'';$status=$b['status']??'draft';
        if(!in_array($unit,['tonna','kg','metr','sht','m2'],true))return self::error('unit','Выберите основную единицу продажи.');
        if(!in_array($status,['draft','publish'],true)||($status==='publish'&&!current_user_can('publish_products')))return self::error('status','Недостаточно прав для публикации.',403);
        $p=new WC_Product_Simple();$p->set_status('draft');$p->update_meta_data('ukm_price_unit',['tonna'=>'тонна','kg'=>'кг','metr'=>'метр','sht'=>'шт.','m2'=>'м²'][$unit]);
        $err=UKM_Office_API::validate(['revision'=>'new','draft'=>$d],$p);if($err)return $err;
        global $wpdb;$wpdb->query('START TRANSACTION');$id=0;
        try{
            $p->set_name(sanitize_text_field($d['name']));$p->save();$id=$p->get_id();$p->update_meta_data('_ukm_office_created',true);
            UKM_Office_API::write($p,$d);$p=UKM_Office_API::fresh($id);$p->set_status($status);$p->save();$after=UKM_Office_API::snapshot(UKM_Office_API::fresh($id));
            UKM_Office_API::log($id,['created'=>false],$after,'create');$wpdb->query('COMMIT');return new WP_REST_Response(UKM_Office_API::pack(UKM_Office_API::fresh($id)),201);
        }catch(Throwable $e){$wpdb->query('ROLLBACK');if($id){clean_post_cache($id);wc_delete_product_transients($id);}return self::error('create_failed',$e->getMessage(),422);}
    }
    static function editable($id,$revision){$p=wc_get_product($id);if(!$p||!current_user_can('edit_post',$id))return self::error('product','Товар недоступен.',404);if(!is_string($revision)||!hash_equals(UKM_Office_API::hash(UKM_Office_API::snapshot($p)),$revision))return self::error('version_conflict','Товар уже изменён. Обновите карточку.',409);return $p;}
    static function upload_media($r){
        $id=(int)$r['id'];$p=self::editable($id,$r->get_param('revision'));if(is_wp_error($p))return $p;
        if(count(array_filter(array_merge([$p->get_image_id()],$p->get_gallery_image_ids())))>=20)return self::error('photo_count','Максимум 20 фото у товара.',422);
        $files=$r->get_file_params();$f=$files['file']??null;if(!$f||($f['error']??1)!==UPLOAD_ERR_OK)return self::error('upload','Не удалось принять изображение.');
        if($f['size']>min(8*1024*1024,wp_max_upload_size()))return self::error('size','Изображение превышает лимит загрузки сервера.',413);
        require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
        $types=['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];$check=wp_check_filetype_and_ext($f['tmp_name'],$f['name'],$types);$size=@getimagesize($f['tmp_name']);
        if(!$check['type']||!$size||!in_array($size['mime']??'',array_values($types),true)||$size[0]*$size[1]>24000000)return self::error('image','Разрешены реальные JPG, PNG и WebP до 24 мегапикселей.');
        // Use a private stage-owned directory, never a shared live upload path.
        $directory=[__CLASS__,'stage_upload_dir'];
        add_filter('upload_dir',$directory);$upload=wp_handle_upload($f,['test_form'=>false,'mimes'=>$types]);remove_filter('upload_dir',$directory);
        if(isset($upload['error']))return self::error('upload',$upload['error'],422);
        $attachment=wp_insert_attachment(['post_mime_type'=>$check['type'],'post_title'=>sanitize_text_field(pathinfo($f['name'],PATHINFO_FILENAME)),'post_status'=>'inherit','post_parent'=>$id,'guid'=>$upload['url']],$upload['file'],$id,true);
        if(is_wp_error($attachment)){@unlink($upload['file']);return self::error('attachment',$attachment->get_error_message(),422);}
        update_post_meta($attachment,'_wp_attached_file',$upload['file']);update_post_meta($attachment,'_ukm_office_media_url',$upload['url']);update_post_meta($attachment,'_ukm_office_product',$id);
        wp_update_attachment_metadata($attachment,wp_generate_attachment_metadata($attachment,$upload['file']));
        // Lock and check again: uploading a file must not overwrite a concurrent edit.
        global $wpdb;UKM_Office_API::lock($id);
        try{
            $p=UKM_Office_API::fresh($id);if(!hash_equals(UKM_Office_API::hash(UKM_Office_API::snapshot($p)),(string)$r->get_param('revision')))throw new RuntimeException('Во время загрузки товар изменился. Обновите карточку и повторите.');
            $before=UKM_Office_API::snapshot($p);$gallery=$p->get_gallery_image_ids();
            if(!$p->get_image_id())$p->set_image_id($attachment);else{$gallery[]=$attachment;$p->set_gallery_image_ids(array_values(array_unique($gallery)));}
            $p->save();$after=UKM_Office_API::snapshot(UKM_Office_API::fresh($id));UKM_Office_API::log($id,$before,$after,'edit');$wpdb->query('COMMIT');return UKM_Office_API::pack(UKM_Office_API::fresh($id));
        }catch(Throwable $e){$wpdb->query('ROLLBACK');clean_post_cache($id);wp_delete_attachment($attachment,true);return self::error('photo_conflict',$e->getMessage(),409);}
    }
    static function save_photos($r){
        $id=(int)$r['id'];$b=$r->get_json_params();$p=self::editable($id,$b['revision']??'');if(is_wp_error($p))return $p;
        $ids=$b['imageIds']??null;if(!is_array($ids)||count($ids)>20||count(array_filter($ids,static fn($id)=>is_int($id)&&$id>0))!==count($ids)||count($ids)!==count(array_unique($ids)))return self::error('photos','Передайте до 20 уникальных фотографий.');
        $allowed=array_merge([$p->get_image_id()],$p->get_gallery_image_ids());
        foreach($ids as $image){if(!is_int($image)||$image<=0||!wp_attachment_is_image($image)||(!in_array($image,$allowed,true)&&(int)get_post_meta($image,'_ukm_office_product',true)!==$id))return self::error('photo','Нельзя использовать чужой файл или файл другого типа.',403);}
        global $wpdb;UKM_Office_API::lock($id);
        try{
            $p=UKM_Office_API::fresh($id);$before=UKM_Office_API::snapshot($p);if(!hash_equals(UKM_Office_API::hash($before),$b['revision']))throw new RuntimeException('Версия товара изменилась. Обновите карточку.');
            $p->set_image_id($ids[0]??0);$p->set_gallery_image_ids(array_slice($ids,1));$p->save();$after=UKM_Office_API::snapshot(UKM_Office_API::fresh($id));if(UKM_Office_API::hash($before)!==UKM_Office_API::hash($after))UKM_Office_API::log($id,$before,$after);
            $wpdb->query('COMMIT');return UKM_Office_API::pack(UKM_Office_API::fresh($id));
        }catch(Throwable $e){$wpdb->query('ROLLBACK');clean_post_cache($id);return self::error('photo_save',$e->getMessage(),409);}
    }
}
UKM_Office_Features::boot();
