<?php
/** Previewed, resumable import of explicit catalogue fields. */
defined('ABSPATH') || exit;
if(!defined('UKM_AUDIT_STAGE')||!UKM_AUDIT_STAGE)return;
final class UKM_Office_Import {
    static function error($c,$m,$s=400){return UKM_Office_API::err($c,$m,$s);}
    static function boot(){add_action('rest_api_init',function(){foreach([['/bulk/scope','POST','scope'],['/bulk/preview','POST','bulk_preview'],['/imports/preview','POST','preview'],['/imports/(?P<job>[a-f0-9]{32})','GET','status'],['/imports/(?P<job>[a-f0-9]{32})/apply','POST','apply']] as [$route,$method,$cb])register_rest_route(UKM_Office_API::NS,$route,['methods'=>$method,'callback'=>[__CLASS__,$cb],'permission_callback'=>static fn()=>UKM_Office_API::allowed()&&current_user_can('edit_products')?:self::error('forbidden','Импорт доступен только редактору или управляющему.',403)]);});}
    static function scope($r){
        $category=(int)$r->get_param('categoryId');$term=get_term($category,'product_cat');if(!$term||is_wp_error($term))return self::error('category','Выберите раздел каталога.');
        $query=new WP_Query(['post_type'=>'product','post_status'=>['publish','draft','pending','private'],'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','no_found_rows'=>true,'tax_query'=>[['taxonomy'=>'product_cat','field'=>'term_id','terms'=>$category]]]);
        if(count($query->posts)>100000)return self::error('scope','В разделе больше 100 000 товаров; разделите операцию по подразделам.',422);
        return ['ids'=>array_map('intval',$query->posts),'total'=>count($query->posts),'categoryId'=>$category,'name'=>$term->name];
    }
    static function bulk_preview($r){
        $b=$r->get_json_params();$ids=$b['ids']??[];$operation=$b['operation']??[];$category=(int)($b['categoryId']??0);
        if(!is_array($ids)||!count($ids)||count($ids)>200)return self::error('ids','Размер части — до 200 товаров.');
        $term=get_term($category,'product_cat');if(!$term||is_wp_error($term))return self::error('category','Раздел не найден.');$children=get_term_children($category,'product_cat');
        $rows=[];$allowed=array_merge([$category],is_wp_error($children)?[]:$children);
        foreach($ids as $id){
            $p=wc_get_product((int)$id);if(!$p||!current_user_can('edit_post',$p->get_id()))return self::error('product','Товар недоступен.',403);
            if(!array_intersect($allowed,$p->get_category_ids()))return self::error('scope_changed','Один из товаров больше не относится к выбранному разделу. Подготовьте операцию заново.',409);
            $row=['ID'=>(string)$id];$mode=$operation['mode']??'';
            if($mode==='price'){$percent=$operation['percent']??null;if(!is_numeric($percent)||!is_finite((float)$percent)||(float)$percent<=-100||(float)$percent>100000)return self::error('percent','Процент должен быть больше −100 и не больше 100 000.');$price=$p->get_price('edit');if((float)$price>0)$row['Цена']=(string)round((float)$price*(1+(float)$percent/100),2);}
            elseif($mode==='category')$row['Категория']=(string)($operation['targetCategory']??0);
            elseif($mode==='attribute'){$label=trim((string)($operation['attributeName']??''));$value=trim((string)($operation['attributeValue']??''));if(!$label||!$value)return self::error('attribute','Укажите характеристику и значение.');$row['Характеристика:'.$label]=$value;}
            else return self::error('operation','Неизвестная операция.');$rows[]=$row;
        }
        $request=new WP_REST_Request('POST');$request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode(['rows'=>$rows]));return self::preview($request);
    }
    static function fields($row){
        $aliases=['id'=>'id','артикул'=>'sku','sku'=>'sku','название'=>'name','наименование'=>'name','цена'=>'price','основная цена'=>'price','категория'=>'category','категория id'=>'category','единица'=>'unit','статус'=>'status','описание'=>'description','краткое описание'=>'shortDescription'];$out=[];$attrs=[];
        if(!is_array($row)||count($row)>80)throw new RuntimeException('Строка должна содержать до 80 колонок.');
        foreach($row as $key=>$value){
            if(!is_string($key)||(!is_scalar($value)&&$value!==null))throw new RuntimeException('Недопустимое содержимое ячейки.');
            $key=trim(ltrim($key,"\xEF\xBB\xBF"));if($key==='__row')continue;$value=trim((string)($value??''));if(strlen($value)>200000)throw new RuntimeException('Слишком длинная ячейка.');
            if(preg_match('/^характеристика\s*:\s*(.+)$/ui',$key,$m)){$attrs[trim($m[1])]=$value;continue;}
            $field=$aliases[mb_strtolower($key)]??null;if(!$field)throw new RuntimeException('Неизвестная колонка «'.$key.'». Используйте шаблон.');
            if(array_key_exists($field,$out))throw new RuntimeException('Колонка «'.$key.'» повторяется.');$out[$field]=$value;
        }
        $out['attrs']=$attrs;return $out;
    }
    static function category($value,$fallback){
        if($value==='')return $fallback;
        if(ctype_digit($value)){$t=get_term((int)$value,'product_cat');if($t&&!is_wp_error($t))return $t->term_id;}
        else{$terms=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'name'=>$value]);if(!is_wp_error($terms)&&count($terms)===1)return $terms[0]->term_id;}
        throw new RuntimeException('Категория не найдена однозначно. Укажите её числовой ID.');
    }
    static function draft($p){return ['name'=>$p->get_name('edit'),'categoryId'=>UKM_Office_API::pack($p)['categoryId'],'basePrice'=>(float)$p->get_price('edit')>0?(float)$p->get_price('edit'):null,'attributes'=>UKM_Office_API::attrs($p),'description'=>$p->get_description('edit'),'shortDescription'=>$p->get_short_description('edit'),'sku'=>$p->get_sku('edit'),'status'=>$p->get_status()];}
    static function row($raw,$default){
        $r=self::fields($raw);$id=0;if(!empty($r['id'])){if(!ctype_digit($r['id']))throw new RuntimeException('ID должен быть целым числом.');$id=(int)$r['id'];if(!wc_get_product($id))throw new RuntimeException('Товар с указанным ID не найден.');}
        elseif(!empty($r['sku']))$id=(int)wc_get_product_id_by_sku($r['sku']);
        $p=$id?wc_get_product($id):new WC_Product_Simple();if($id&&!current_user_can('edit_post',$id))throw new RuntimeException('Нет прав на товар.');if(!$id&&!current_user_can('ukm_office_create_products'))throw new RuntimeException('Нет прав на создание товаров.');if(!$p->is_type('simple'))throw new RuntimeException('Поддерживаются простые товары.');
        $d=$id?self::draft($p):['name'=>'','categoryId'=>$default,'basePrice'=>null,'attributes'=>[],'description'=>'','shortDescription'=>'','sku'=>'','status'=>'draft'];$before=$d;
        foreach(['name','sku','description','shortDescription'] as $key)if(isset($r[$key])&&$r[$key]!=='')$d[$key]=$r[$key]==='[очистить]'?'':$r[$key];
        if(isset($r['category']))$d['categoryId']=self::category($r['category'],$d['categoryId']);
        if(isset($r['price'])&&$r['price']!==''){
            if($r['price']==='[очистить]')$d['basePrice']=null;
            else{$n=str_replace([',',' ',"\xc2\xa0"],[ '.', '', '' ],$r['price']);if(!preg_match('/^\d+(?:\.\d+)?$/D',$n)||!is_finite((float)$n))throw new RuntimeException('Цена должна быть числом.');$d['basePrice']=(float)$n;}
        }
        if(!empty($r['status'])){$s=['черновик'=>'draft','опубликован'=>'publish','draft'=>'draft','publish'=>'publish'];$d['status']=$s[mb_strtolower($r['status'])]??'';}
        $unit=!empty($r['unit'])?UralKM_Cart_Units::key($r['unit']):($id?UKM_Office_API::pack($p)['baseUnit']:'');
        if(!$id&&!in_array($unit,['tonna','kg','metr','sht','m2'],true))throw new RuntimeException('Для нового товара укажите единицу: м, кг, т, шт. или м².');
        if(!empty($r['unit']))$d['baseUnit']=$unit;
        $newTerms=[];
        foreach($r['attrs'] as $label=>$value){
            if($value==='')continue;$found=null;foreach($d['attributes'] as $i=>$a)if(mb_strtolower($a['name'])===mb_strtolower($label)||($a['key']??'')===$label){$found=$i;break;}
            if($value==='[очистить]'){if($found!==null)array_splice($d['attributes'],$found,1);continue;}
            $values=array_values(array_filter(array_map('trim',explode('|',$value)),static fn($v)=>$v!==''));if(!$values)throw new RuntimeException('Не заполнена характеристика «'.$label.'».');
            $attr=$found!==null?$d['attributes'][$found]:['name'=>$label,'values'=>[]];
            if($found===null){$matches=[];foreach(wc_get_attribute_taxonomies() as $a)if(mb_strtolower($a->attribute_label)===mb_strtolower($label)||'pa_'.$a->attribute_name===$label)$matches[]=$a;if(count($matches)>1)throw new RuntimeException('Название справочника неоднозначно: '.$label.'. Укажите его ключ pa_… в заголовке.');if($matches){$a=$matches[0];$attr=['name'=>$a->attribute_label,'key'=>'pa_'.$a->attribute_name,'taxonomy'=>true,'id'=>(int)$a->attribute_id,'values'=>[]];}}
            if(!empty($attr['taxonomy'])){
                if(!taxonomy_exists($attr['key']))throw new RuntimeException('Справочник не зарегистрирован: '.$label);
                foreach($values as &$v)if(!get_term_by('name',$v,$attr['key'])){$normalized=UKM_Office_Catalog::value($v,$attr['name']);if(is_wp_error($normalized))throw new RuntimeException($normalized->get_error_message());$v=$normalized;$newTerms[]=['taxonomy'=>$attr['key'],'name'=>$v];}unset($v);
            }else foreach($values as $v){$checked=UKM_Office_Catalog::value($v,$attr['name']);if(is_wp_error($checked))throw new RuntimeException($checked->get_error_message());}
            $attr['values']=$values;if($found===null)$d['attributes'][]=$attr;else $d['attributes'][$found]=$attr;
        }
        $valid=UKM_Office_API::validate(['revision'=>'preview','draft'=>$d],$p,true);if($valid)throw new RuntimeException($valid->get_error_message());
        if(!empty($d['sku'])&&!wc_product_has_unique_sku($id,$d['sku']))throw new RuntimeException('Артикул уже принадлежит другому товару.');
        if($id&&$before['basePrice']!==$d['basePrice']&&$unit==='native')throw new RuntimeException('Не определена основная единица; сначала укажите её в карточке.');
        if($id&&$p->get_sale_price('edit')!==''&&$before['basePrice']!==$d['basePrice'])throw new RuntimeException('У товара есть акционная цена; изменение заблокировано.');
        return ['id'=>$id,'operation'=>$id?'update':'create','revision'=>$id?UKM_Office_API::hash(UKM_Office_API::snapshot($p)):'','draft'=>$d,'baseUnit'=>$unit,'status'=>$d['status'],'newTerms'=>$newTerms,'summary'=>array_values(array_filter(array_keys($d),static fn($key)=>!array_key_exists($key,$before)||$d[$key]!==$before[$key]))];
    }
    static function summary($job,$id){return ['job'=>$id,'total'=>count($job['rows']),'cursor'=>$job['cursor'],'complete'=>!$job['errors']&&!$job['failed']&&count($job['rows'])>0&&$job['cursor']===count($job['rows']),'failed'=>$job['failed']??null,'errors'=>$job['errors'],'preview'=>array_map(static fn($r)=>['row'=>$r['row'],'id'=>$r['id'],'name'=>$r['draft']['name'],'operation'=>$r['operation'],'changes'=>$r['summary'],'newTerms'=>$r['newTerms'],'price'=>$r['draft']['basePrice'],'unit'=>$r['baseUnit']],$job['rows'])];}
    static function preview($r){
        $body=$r->get_json_params();$rows=$body['rows']??null;if(!is_array($rows)||!count($rows)||count($rows)>200)return self::error('rows','В одной пачке должно быть от 1 до 200 строк.');
        if(strlen(wp_json_encode($rows))>3*1024*1024)return self::error('size','Данные превышают 3 МБ.',413);
        $plan=[];$errors=[];$seen=[];
        foreach($rows as $i=>$row)try{$p=self::row($row,(int)($body['categoryId']??0));$key=$p['id']?'id:'.$p['id']:($p['draft']['sku']?'sku:'.$p['draft']['sku']:'new:'.$i);if(isset($seen[$key]))throw new RuntimeException('Товар или артикул повторяется в файле.');$seen[$key]=true;$p['row']=isset($row['__row'])&&ctype_digit((string)$row['__row'])?(int)$row['__row']:$i+2;$plan[]=$p;}catch(Throwable $e){$errors[]=['row'=>isset($row['__row'])&&ctype_digit((string)$row['__row'])?(int)$row['__row']:$i+2,'message'=>$e->getMessage()];}
        $id=bin2hex(random_bytes(16));$job=['user'=>get_current_user_id(),'rows'=>$plan,'errors'=>$errors,'cursor'=>0,'failed'=>null];set_transient('ukmo_import_'.$id,$job,1800);return self::summary($job,$id);
    }
    static function job($id){$job=get_transient('ukmo_import_'.$id);return is_array($job)&&$job['user']===get_current_user_id()?$job:self::error('job','Предпросмотр истёк или принадлежит другому сотруднику. Загрузите файл заново.',404);}
    static function status($r){$job=self::job($r['job']);return is_wp_error($job)?$job:self::summary($job,$r['job']);}
    static function apply($r){
        global $wpdb;$id=$r['job'];$lock='ukmo_import_'.$id;if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return self::error('busy','Пачка уже обрабатывается.',409);
        try{
            $job=self::job($id);if(is_wp_error($job))return $job;if($job['errors']||$job['failed'])return self::error('invalid_job','Исправьте ошибки и сделайте новый предпросмотр.',422);
            if((int)$r->get_param('cursor')!==$job['cursor'])return self::error('cursor','Прогресс изменился. Обновите состояние импорта.',409);
            $end=min(count($job['rows']),$job['cursor']+5);
            while($job['cursor']<$end){$p=$job['rows'][$job['cursor']];try{
                if($p['id']){$product=wc_get_product($p['id']);if(!$product||!current_user_can('edit_post',$p['id'])||!hash_equals(UKM_Office_API::hash(UKM_Office_API::snapshot($product)),$p['revision']))throw new RuntimeException('Товар изменился после предпросмотра.');}
                foreach($p['newTerms'] as $t)if(!get_term_by('name',$t['name'],$t['taxonomy'])){$term=wp_insert_term($t['name'],$t['taxonomy']);if(is_wp_error($term))throw new RuntimeException($term->get_error_message());$termid=(int)$term['term_id'];UKM_Office_Features::event('term_create',$termid,'Импорт: добавлено значение '.$t['name'],null,['taxonomy'=>$t['taxonomy'],'state'=>UKM_Office_Catalog::term_state(get_term($termid,$t['taxonomy']))]);}
                $req=new WP_REST_Request('POST');$req->set_header('Content-Type','application/json');
                if($p['operation']==='update'){$req->set_url_params(['id'=>$p['id']]);$req->set_body(wp_json_encode(['draft'=>$p['draft'],'revision'=>$p['revision']]));$result=UKM_Office_API::save($req);}
                else{if(!current_user_can('ukm_office_create_products'))throw new RuntimeException('Недостаточно прав на создание товара.');$req->set_body(wp_json_encode(['draft'=>$p['draft'],'baseUnit'=>$p['baseUnit'],'status'=>$p['status']]));$result=UKM_Office_Features::create_product($req);}
                if(is_wp_error($result))throw new RuntimeException($result->get_error_message());$job['rows'][$job['cursor']]['savedId']=rest_ensure_response($result)->get_data()['id'];$job['cursor']++;
                set_transient('ukmo_import_'.$id,$job,1800);
            }catch(Throwable $e){$job['failed']=['row'=>$p['row'],'message'=>$e->getMessage()];set_transient('ukmo_import_'.$id,$job,1800);break;}}
            return self::summary($job,$id);
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
}
UKM_Office_Import::boot();
