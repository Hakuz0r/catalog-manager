<?php
/** Attribute dictionaries, section filters and staff for the private office. */
defined('ABSPATH') || exit;
if(!defined('UKM_AUDIT_STAGE')||!UKM_AUDIT_STAGE)return;
final class UKM_Office_Catalog {
    static function error($code,$text,$status=400){return UKM_Office_API::err($code,$text,$status);}
    static function roles(){
        $read=['read'=>true,'ukm_office_access'=>true,'read_private_products'=>true];
        $edit=$read+array_fill_keys(['edit_products','edit_published_products','edit_others_products','edit_private_products','assign_product_terms','upload_files','publish_products','ukm_office_create_products','ukm_office_manage_catalog'],true);
        return ['ukm_catalog_viewer'=>['name'=>'Просмотр','caps'=>$read],'ukm_catalog_editor'=>['name'=>'Редактор','caps'=>$edit],'ukm_catalog_manager'=>['name'=>'Управляющий','caps'=>$edit+['ukm_office_manage_staff'=>true]]];
    }
    static function install(){
        if(get_option('ukm_office_roles_schema')==='1')return;
        foreach(self::roles() as $key=>$def){add_role($key,$def['name'],$def['caps']);$role=get_role($key);foreach($def['caps'] as $cap=>$grant)$role->add_cap($cap);}
        // Account privileges are assigned by the explicit installer, never by a matching login.
        update_option('ukm_office_roles_schema','1',false);
    }
    static function boot(){
        add_action('init',[__CLASS__,'install'],30);
        add_filter('woocommerce_get_product_terms',static function($terms,$id,$taxonomy,$args){
            if(strpos($taxonomy,'pa_')!==0)return $terms;$revision=(string)get_option('ukm_office_term_revision','0');if($revision==='0')return $terms;
            if(wp_cache_get((string)$id,'ukm_office_term_versions')!==$revision){WC_Cache_Helper::invalidate_cache_group('product_'.$id);wp_cache_set((string)$id,$revision,'ukm_office_term_versions');return wp_get_post_terms($id,$taxonomy,$args);}return $terms;
        },20,4);
        add_action('rest_api_init',function(){
            foreach([
                ['/attributes','GET','attributes',null],['/attributes','POST','create_attribute','ukm_office_manage_catalog'],
                ['/attributes/(?P<id>\d+)/terms','GET','terms',null],['/attributes/(?P<id>\d+)/terms','POST','create_term','ukm_office_manage_catalog'],
                ['/attributes/(?P<id>\d+)/terms/(?P<term>\d+)','POST','rename_term','ukm_office_manage_catalog'],
                ['/categories/(?P<id>\d+)/filters','GET','filters',null],['/categories/(?P<id>\d+)/filters','POST','save_filters','ukm_office_manage_catalog'],
                ['/staff','GET','staff','ukm_office_manage_staff'],['/staff','POST','create_staff','ukm_office_manage_staff'],['/staff/(?P<id>\d+)','POST','save_staff','ukm_office_manage_staff']
            ] as [$route,$method,$cb,$cap])register_rest_route(UKM_Office_API::NS,$route,['methods'=>$method,'callback'=>$method==='GET'?[__CLASS__,$cb]:static fn($request)=>self::mutation($cb,$request),'permission_callback'=>static fn()=>UKM_Office_API::allowed()&&(!$cap||current_user_can($cap))?:self::error('forbidden','Недостаточно прав.',403)]);
        });
    }
    static function mutation($callback,$request){
        global $wpdb;$lock='ukm_office_settings_'.md5(DB_NAME);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)return self::error('busy','Другой сотрудник сохраняет настройки. Повторите попытку.',409);
        $wpdb->query('START TRANSACTION');
        try{$uid=get_current_user_id();clean_user_cache($uid);wp_cache_delete($uid,'user_meta');wp_set_current_user(0);wp_set_current_user($uid);$cap=in_array($callback,['staff','create_staff','save_staff'],true)?'ukm_office_manage_staff':'ukm_office_manage_catalog';if(!UKM_Office_API::allowed()||!current_user_can($cap)){$wpdb->query('ROLLBACK');return self::error('forbidden','Права изменились. Войдите заново.',403);}$result=self::$callback($request);if(is_wp_error($result)){$wpdb->query('ROLLBACK');self::rollback_cache($callback,$request);}else $wpdb->query('COMMIT');return $result;}
        catch(Throwable $e){$wpdb->query('ROLLBACK');self::rollback_cache($callback,$request);return self::error('settings',$e->getMessage(),422);}
        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    static function rollback_cache($callback,$request){
        foreach(['ukmf_category_filter_settings','ukm_office_term_revision','alloptions','notoptions'] as $key)wp_cache_delete($key,'options');
        if(in_array($callback,['save_staff','create_staff'],true)&&(int)$request['id'])clean_user_cache((int)$request['id']);
        if((int)$request['term']){$a=wc_get_attribute((int)$request['id']);if($a)clean_term_cache((int)$request['term'],$a->slug);}
        delete_transient('wc_attribute_taxonomies');WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
    }
    static function undo_event_proxy($request){return UKM_Office_Features::undo_event_impl($request);}
    static function attributes(){
        $out=[];foreach(wc_get_attribute_taxonomies() as $a){$tax='pa_'.$a->attribute_name;$out[]=['id'=>(int)$a->attribute_id,'name'=>$a->attribute_label,'key'=>$tax,'registered'=>taxonomy_exists($tax),'count'=>taxonomy_exists($tax)?(int)wp_count_terms(['taxonomy'=>$tax,'hide_empty'=>false]):0];}return $out;
    }
    static function attribute($id){$a=wc_get_attribute((int)$id);return $a&&taxonomy_exists($a->slug)?$a:self::error('attribute','Этот справочник не зарегистрирован в WordPress; автоматическое изменение заблокировано.',422);}
    static function create_attribute($r){
        $name=sanitize_text_field($r->get_param('name')??'');if(!trim($name)||mb_strlen($name)>100)return self::error('name','Название должно содержать от 1 до 100 символов.');
        foreach(wc_get_attribute_taxonomies() as $a)if(mb_strtolower(trim($a->attribute_label))===mb_strtolower(trim($name)))return self::error('duplicate','Справочник с таким названием уже существует.',409);
        $slug='office_'.substr(hash('sha256',$name),0,16);$id=wc_create_attribute(['name'=>$name,'slug'=>$slug,'type'=>'select','order_by'=>'name','has_archives'=>false]);if(is_wp_error($id))return self::error('attribute',$id->get_error_message(),422);
        UKM_Office_Features::event('attribute_create',$id,'Создан справочник: '.$name,null,['name'=>$name,'slug'=>'pa_'.$slug]);return ['id'=>$id,'attributes'=>self::attributes()];
    }
    static function term_state($term){return ['name'=>$term->name,'slug'=>$term->slug,'description'=>$term->description];}
    static function terms($r){
        $a=self::attribute($r['id']);if(is_wp_error($a))return $a;$page=max(1,(int)($r['page']??1));$search=mb_substr((string)$r['search'],0,100);
        $args=['taxonomy'=>$a->slug,'hide_empty'=>false,'number'=>50,'offset'=>($page-1)*50,'search'=>$search];$terms=get_terms($args);if(is_wp_error($terms))return $terms;
        $out=[];foreach($terms as $t)$out[]=['id'=>$t->term_id,'name'=>$t->name,'count'=>$t->count,'revision'=>UKM_Office_API::hash(self::term_state($t))];
        return ['items'=>$out,'total'=>(int)wp_count_terms(['taxonomy'=>$a->slug,'hide_empty'=>false,'search'=>$search]),'page'=>$page,'attribute'=>['id'=>$a->id,'name'=>$a->name,'key'=>$a->slug]];
    }
    static function value($name,$label){
        if(!is_string($name)||!trim(sanitize_text_field($name))||mb_strlen($name)>500)return self::error('value','Введите значение до 500 символов.');
        $name=trim(sanitize_text_field($name));
        if(preg_match('/,\s*(мм|м|кг|т|см|м²)\s*$/ui',$label)){
            if(!preg_match('/^\d+(?:[.,]\d+)?$/D',$name)||!is_finite((float)str_replace(',','.',$name)))return self::error('format','В числовом справочнике указывайте только число; единица уже есть в названии.');
            $name=str_replace(',','.',$name);$parts=explode('.',$name,2);$whole=ltrim($parts[0],'0');$whole=$whole===''?'0':$whole;$fraction=isset($parts[1])?rtrim($parts[1],'0'):'';$name=$whole.($fraction!==''?'.'.$fraction:'');
        }
        return $name;
    }
    static function create_term($r){
        $a=self::attribute($r['id']);if(is_wp_error($a))return $a;$name=self::value($r->get_param('name'),$a->name);if(is_wp_error($name))return $name;
        $term=wp_insert_term($name,$a->slug);if(is_wp_error($term))return self::error('term',$term->get_error_message(),422);
        $id=(int)$term['term_id'];UKM_Office_Features::event('term_create',$id,'Добавлено значение: '.$a->name.' → '.$name,null,['taxonomy'=>$a->slug,'state'=>self::term_state(get_term($id,$a->slug))]);if(function_exists('ukmf_invalidate'))ukmf_invalidate();return ['id'=>$id];
    }
    static function rename_term($r){
        global $wpdb;$a=self::attribute($r['id']);if(is_wp_error($a))return $a;$term=get_term((int)$r['term'],$a->slug);if(!$term||is_wp_error($term))return self::error('term','Значение не найдено.',404);
        $b=$r->get_json_params();$name=self::value($b['name']??null,$a->name);if(is_wp_error($name))return $name;
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->term_relationships WHERE term_taxonomy_id=%d",$term->term_taxonomy_id));
        if(!hash_equals(UKM_Office_API::hash(self::term_state($term)),(string)($b['revision']??'')))return self::error('conflict','Значение уже изменено. Обновите список.',409);
        if(($b['confirm']??false)!==true)return self::error('confirmation','Подтвердите изменение общего значения у товаров.',422);
        $before=self::term_state($term);$result=wp_update_term($term->term_id,$a->slug,['name'=>$name]);if(is_wp_error($result))return self::error('term',$result->get_error_message(),422);
        UKM_Office_Features::event('term_edit',$term->term_id,'Изменено значение: '.$a->name.' → '.$name,['taxonomy'=>$a->slug,'state'=>$before],['taxonomy'=>$a->slug,'state'=>self::term_state(get_term($term->term_id,$a->slug))]);update_option('ukm_office_term_revision',microtime(true),false);if(function_exists('ukmf_invalidate'))ukmf_invalidate();return ['id'=>$term->term_id,'affected'=>$used];
    }
    static function fields($category){
        global $wpdb;$children=get_term_children($category,'product_cat');$ids=array_map('intval',array_merge([$category],is_wp_error($children)?[]:$children));$slots=implode(',',$ids);
        $condition="EXISTS(SELECT 1 FROM $wpdb->term_relationships cr JOIN $wpdb->term_taxonomy ct ON ct.term_taxonomy_id=cr.term_taxonomy_id WHERE cr.object_id=p.ID AND ct.taxonomy='product_cat' AND ct.term_id IN ($slots))";
        $taxes=$wpdb->get_col("SELECT DISTINCT tt.taxonomy FROM $wpdb->posts p JOIN $wpdb->term_relationships tr ON tr.object_id=p.ID JOIN $wpdb->term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE p.post_type='product' AND p.post_status='publish' AND tt.taxonomy LIKE 'pa\\_%' AND $condition");
        $labels=[];foreach($taxes as $tax)if(taxonomy_exists($tax))$labels[]=wc_attribute_label($tax);
        $rows=$wpdb->get_col("SELECT pm.meta_value FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON pm.post_id=p.ID WHERE p.post_type='product' AND p.post_status='publish' AND pm.meta_key='_product_attributes' AND $condition");
        foreach($rows as $row){$attrs=is_serialized($row)?@unserialize(trim($row),['allowed_classes'=>false]):$row;if(!is_array($attrs))continue;foreach($attrs as $a)if(is_array($a)&&empty($a['is_taxonomy'])&&!empty($a['is_visible'])&&!empty($a['name'])&&!empty($a['value']))$labels[]=$a['name'];}
        $labels=array_values(array_unique(array_filter($labels,static fn($label)=>!function_exists('ukmf_excluded')||!ukmf_excluded($label))));sort($labels,SORT_NATURAL|SORT_FLAG_CASE);return $labels;
    }
    static function filter_state($id){$all=get_option('ukmf_category_filter_settings',[]);return ['exists'=>isset($all[$id]),'value'=>$all[$id]??null];}
    static function filters($r){
        $id=(int)$r['id'];$t=get_term($id,'product_cat');if(!$t||is_wp_error($t))return self::error('category','Раздел не найден.',404);
        $state=self::filter_state($id);$prefs=function_exists('ukmf_dictionary')?array_values(array_map(static fn($g)=>$g['label'],ukmf_dictionary($id))):(function_exists('ukmf_preferences')?ukmf_preferences($id):[]);$fields=self::fields($id);
        return ['categoryId'=>$id,'name'=>$t->name,'available'=>$fields,'selected'=>array_values(array_filter($prefs,static fn($p)=>in_array($p,$fields,true))),'explicit'=>$state['exists'],'revision'=>UKM_Office_API::hash($state),'url'=>get_term_link($id,'product_cat')];
    }
    static function save_filters($r){
        $id=(int)$r['id'];$b=$r->get_json_params();$current=self::filters($r);if(is_wp_error($current))return $current;
        if(!is_array($b['labels']??null)||count($b['labels'])>20||count($b['labels'])!==count(array_unique($b['labels'])))return self::error('labels','Выберите до 20 различных характеристик.');
        foreach($b['labels'] as $label)if(!is_string($label)||!in_array($label,$current['available'],true))return self::error('label','В разделе нет выбранной характеристики.');
        if(!hash_equals($current['revision'],(string)($b['revision']??'')))return self::error('conflict','Настройки раздела изменены. Обновите страницу.',409);
        $before=self::filter_state($id);$all=get_option('ukmf_category_filter_settings',[]);
        if(!empty($b['inherit']))unset($all[$id]);else $all[$id]=['labels'=>array_values($b['labels'])];
        update_option('ukmf_category_filter_settings',$all,false);$after=self::filter_state($id);UKM_Office_Features::event('filters',$id,'Фильтры раздела: '.$current['name'],$before,$after);if(function_exists('ukmf_invalidate'))ukmf_invalidate();return self::filters($r);
    }
    static function user_state($user){return ['name'=>$user->display_name,'role'=>array_values(array_intersect(array_keys(self::roles()),$user->roles))[0]??'','active'=>!get_user_meta($user->ID,'_ukm_office_disabled',true)];}
    static function staff(){
        $users=get_users(['meta_key'=>'_ukm_office_member','meta_value'=>1]);$out=[];
        foreach($users as $u)$out[]=['id'=>$u->ID,'login'=>$u->user_login]+self::user_state($u)+['revision'=>UKM_Office_API::hash(self::user_state($u))];return ['items'=>$out,'roles'=>array_map(static fn($key,$v)=>['id'=>$key,'name'=>$v['name']],array_keys(self::roles()),array_values(self::roles()))];
    }
    static function create_staff($r){
        $b=$r->get_json_params();$login=$b['login']??'';$password=$b['password']??'';$name=sanitize_text_field($b['name']??'');$role=$b['role']??'';
        if(!is_string($login)||!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/D',$login)||!trim($name)||(!is_string($role)||!isset(self::roles()[$role])))return self::error('staff','Проверьте имя, роль и логин (3–50 латинских букв, цифр, _, - или .).');
        if(!is_string($password)||strlen($password)<12||strlen($password)>200)return self::error('password','Задайте пароль длиной от 12 до 200 символов.');
        $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'display_name'=>$name,'role'=>$role]);if(is_wp_error($id))return self::error('staff',$id->get_error_message(),422);
        update_user_meta($id,'_ukm_office_member',1);UKM_Office_Features::event('staff_create',$id,'Добавлен сотрудник: '.$name,null,self::user_state(get_userdata($id)));return ['id'=>$id];
    }
    static function save_staff($r){
        $id=(int)$r['id'];$user=get_userdata($id);if(!$user||!get_user_meta($id,'_ukm_office_member',true))return self::error('staff','Запись недоступна.',404);
        $b=$r->get_json_params();$role=$b['role']??'';$name=sanitize_text_field($b['name']??'');$active=$b['active']??true;
        if((!is_string($role)||!isset(self::roles()[$role]))||!trim($name)||!is_bool($active))return self::error('staff','Укажите имя, роль и состояние.');
        $before=self::user_state($user);if(!hash_equals(UKM_Office_API::hash($before),(string)($b['revision']??'')))return self::error('conflict','Сотрудник уже изменён. Обновите список.',409);
        if($id===get_current_user_id()&&(!$active||$role!=='ukm_catalog_manager'))return self::error('self','Нельзя отключить свою запись или убрать у себя права управляющего.',409);
        wp_update_user(['ID'=>$id,'display_name'=>$name]);$user->set_role($role);update_user_meta($id,'_ukm_office_disabled',!$active);if($id!==get_current_user_id()||$before['role']!==$role||$before['active']!==$active)WP_Session_Tokens::get_instance($id)->destroy_all();
        UKM_Office_Features::event('staff_edit',$id,'Изменён сотрудник: '.$name,$before,self::user_state(get_userdata($id)));return ['id'=>$id];
    }
    static function undo_setting($event){
        global $wpdb;$id=(int)$event->object_id;$before=json_decode($event->before_json,true);$after=json_decode($event->after_json,true);$kind=$event->kind;
        try{
            if($kind==='filters'){
                if(UKM_Office_API::hash(self::filter_state($id))!==UKM_Office_API::hash($after))throw new RuntimeException('Настройки уже изменены. Сначала отмените более позднюю правку.');
                $all=get_option('ukmf_category_filter_settings',[]);if($before['exists'])$all[$id]=$before['value'];else unset($all[$id]);update_option('ukmf_category_filter_settings',$all,false);if(function_exists('ukmf_invalidate'))ukmf_invalidate();
            }elseif(in_array($kind,['term_create','term_edit'],true)){
                $t=get_term($id,$after['taxonomy']);if(!$t||is_wp_error($t)||self::term_state($t)!==$after['state'])throw new RuntimeException('Значение уже изменено.');
                if($kind==='term_create'){
                    if($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->term_relationships WHERE term_taxonomy_id=%d",$t->term_taxonomy_id)))throw new RuntimeException('Значение используется товарами. Сначала уберите его из товаров.');
                    wp_delete_term($id,$after['taxonomy']);
                }else{ $result=wp_update_term($id,$after['taxonomy'],$before['state']);if(is_wp_error($result))throw new RuntimeException($result->get_error_message()); }
                update_option('ukm_office_term_revision',microtime(true),false);if(function_exists('ukmf_invalidate'))ukmf_invalidate();
            }elseif($kind==='attribute_create'){
                $a=wc_get_attribute($id);if(!$a||$a->name!==$after['name']||$a->slug!==$after['slug'])throw new RuntimeException('Справочник изменён.');
                if((taxonomy_exists($a->slug)&&(int)wp_count_terms(['taxonomy'=>$a->slug,'hide_empty'=>false])>0)||$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_product_attributes' AND meta_value LIKE %s",'%'.$wpdb->esc_like($a->slug).'%')))throw new RuntimeException('Справочник уже содержит значения или используется товарами.');
                $result=wc_delete_attribute($id);if(is_wp_error($result))throw new RuntimeException($result->get_error_message());
            }elseif(in_array($kind,['staff_create','staff_edit'],true)){
                if(!current_user_can('ukm_office_manage_staff'))throw new RuntimeException('Недостаточно прав.');
                $u=get_userdata($id);if(!$u||self::user_state($u)!==$after)throw new RuntimeException('Запись сотрудника уже изменена.');
                if($id===get_current_user_id())throw new RuntimeException('Нельзя отменять права своей текущей записи.');
                if($kind==='staff_create')update_user_meta($id,'_ukm_office_disabled',true);
                else{wp_update_user(['ID'=>$id,'display_name'=>$before['name']]);$u->set_role($before['role']);update_user_meta($id,'_ukm_office_disabled',!$before['active']);}
                WP_Session_Tokens::get_instance($id)->destroy_all();
            }else throw new RuntimeException('Для этого действия автоматическая отмена недоступна.');
            $wpdb->update(UKM_Office_Features::events_table(),['undone'=>1],['id'=>$event->id]);$wpdb->query('COMMIT');return ['ok'=>true];
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::error('undo',$e->getMessage(),409);}
    }
}
UKM_Office_Catalog::boot();
