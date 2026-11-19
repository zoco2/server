<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\power\MarketVideoModelRuntimeService;
use app\common\service\power\MarketCatalogReadScope;
use app\common\service\power\MarketVideoAppRuntimeService;
use app\common\service\power\MarketGenerationMeter;
use InvalidArgumentException;
use think\facade\Cache;

final class MicroVideoCatalog
{
    // Definitions are keyed by all live market fields (including tenant prices and SKU rules).
    // Keep unchanged validated entries warm instead of rebuilding every SKU every 15 minutes.
    // The complete catalogue still expires after 15 seconds; execution quotes always read live.
    private const ENTRY_CACHE_TTL=604800;
    private static array $cache=[];
    public static function models(int $tenant,bool $legacy=false,string $mode='both',?array $allowedIds=null,bool $summary=false): array
    {
        // Share market reads only while discovering callable specifications.
        // Real quotes and admissions run after this scope has been released.
        return MarketCatalogReadScope::run(static fn(): array => self::buildModels($tenant,$legacy,$mode,$allowedIds,$summary));
    }
    private static function buildModels(int $tenant,bool $legacy,string $mode,?array $allowedIds,bool $summary): array
    {
        $subset='';if($allowedIds!==null){$allowedIds=array_values(array_unique(array_map('strval',$allowedIds)));if(!$allowedIds)return [];sort($allowedIds);$subset=':'.hash('sha256',MicroVideoStore::encode($allowedIds));}
        $cacheKey=$tenant.':'.(int)$legacy.':'.$mode.$subset.($summary?':summary':'');if(isset(self::$cache[$cacheKey]))return self::$cache[$cacheKey];
        $sharedKey='micro_video_callable_models_v7:'.$tenant.':'.$mode.$subset.($summary?':summary':'').':'.MicroVideoCatalogVersion::current($tenant);if(!$legacy){try{$cached=Cache::get($sharedKey);if(is_array($cached))return self::$cache[$cacheKey]=$cached;}catch(\Throwable){}}
        $result=[];$options=AigcVideoService::marketOptions($tenant);
        foreach($options['channels']??[] as $model){
            if($allowedIds!==null&&!in_array((string)$model['id'],$allowedIds,true))continue;
            if((int)($model['status']??1)!==1||(int)($model['max_reference_images']??0)<1)continue;
            // Reuse validated entries while the complete live market definition
            // (including tenant prices, schemas and SKU rules) is unchanged.
            $entryCacheKey='micro_video_callable_entry_v7:'.$tenant.':'.$mode.':'.(int)$summary.':'.hash('sha256',MicroVideoStore::encode($model));
            $entryCache=null;if(!$legacy)try{$entryCache=Cache::get($entryCacheKey);}catch(\Throwable){}
            if(is_array($entryCache)){
                // A failed renewal must not discard a valid read and revalidate every SKU.
                try{Cache::set($entryCacheKey,$entryCache,self::ENTRY_CACHE_TTL);}catch(\Throwable){}
                if($entryCache)$result[]=$entryCache;continue;
            }
            $modes=(array)($model['generation_modes']??$model['input_modes']??[]);$method='';
            foreach(['image_reference','omni_reference','image_to_video'] as $m)if(in_array($m,$modes,true)){$method=$m;break;}
            if($method===''){if($legacy)$method='image_reference';else continue;}
            $hasFrames=!empty($model['supports_first_last_frame'])&&in_array('start_end',$modes,true);if(!$legacy&&$mode!=='reference'&&!$hasFrames)continue;
            $specs=[];$frameSpecs=[];$validatedPairs=[];
            foreach($model['specs']??[] as $s){
                if(($s['settlement_mode']??'reserved')!=='reserved'||in_array($s['input_mode']??'', ['video_edit','audio_reference'],true))continue;
                $skuModes=(array)($s['generation_modes']??$modes);$method='';
                foreach(['image_reference','omni_reference','image_to_video'] as $candidate)if(in_array($candidate,$skuModes,true)){$method=$candidate;break;}
                if($method==='')continue;
                $durations=$s['duration_options']?:((int)$s['duration']>0?[(int)$s['duration']]:($model['duration_options']??[]));
                $ratios=$s['ratio_options']?:($s['ratio']!==''?[$s['ratio']]:($model['ratio_options']??[]));
                $inheritsRatio=!$ratios&&$method==='image_to_video';if($inheritsRatio)$ratios=['adaptive'];
                if(!$ratios)continue;
                foreach($ratios as $ratio){$ratio=is_array($ratio)?($ratio['value']??$ratio['ratio']??''):$ratio;if($ratio===''||($ratio==='adaptive'&&!$inheritsRatio))continue;
                    foreach($durations as $duration){$duration=(int)$duration;if($duration<1||$duration>60)continue;
                        $unit=$s['usage_unit']??'';$quantity=in_array($unit,['input_second','output_second','second'],true)?$duration:1;
                        $entry=['quality'=>(string)$s['quality'],'ratio'=>$ratio,'duration'=>$duration,'generation_method'=>$method,'market_product_id'=>(int)$model['market_product_id'],'market_sku_id'=>(int)$s['market_sku_id'],'resource_type'=>$model['resource_type'],'user_charge_points'=>round((float)$s['tenant_unit_price']*$quantity/max(1,(float)($s['usage_unit_size']??1)),4)];
                        $input=(string)($s['input_mode']??'');
                        if($legacy){$specs[]=$entry;if($hasFrames&&($input===''||in_array($input,['start_end','image_reference'],true)))$frameSpecs[]=array_replace($entry,['generation_method'=>'start_end']);continue;}
                        // A pricing SKU may be labeled text_to_video while the shared
                        // adapter permits image reference and start/end requests. Let
                        // the real read-only admission validator decide each exact
                        // mode/spec pair, including ratio and duration constraints.
                        $secondBased=in_array($unit,['input_second','output_second','second'],true);
                        $pairKey=implode('|',[$s['market_sku_id'],$entry['quality'],$ratio,$method,$secondBased?'seconds':$duration]);
                        if(!array_key_exists($pairKey,$validatedPairs)){
                            try{$referenceQuote=self::quote($tenant,$entry,(string)$model['id'],true);if($secondBased&&$duration!==max($durations))self::quote($tenant,array_replace($entry,['duration'=>(int)max($durations)]),(string)$model['id']);}catch(\Throwable){continue;}
                            $frameQuote=null;if($hasFrames){try{$frameQuote=self::quote($tenant,array_replace($entry,['generation_method'=>'start_end']),(string)$model['id'],true);}catch(\Throwable){}}
                            $validatedPairs[$pairKey]=($mode!=='reference'&&!$frameQuote)?false:['duration'=>$duration,'reference'=>$referenceQuote,'frames'=>$frameQuote];
                        }
                        $pair=$validatedPairs[$pairKey];if($pair===false)continue;$reference=$pair['reference'];$frames=$pair['frames'];
                        // Reuse the billing meter, not a rounded quote multiplied by time.
                        // Second-based ranges come from the SKU; both endpoints are validated.
                        // Per-call duration variants are validated and priced individually.
                        $refEntry=array_replace($entry,['market_sku_id'=>$reference['market_sku_id'],'user_charge_points'=>self::catalogPrice($reference,$duration,$secondBased)]);
                        $frameEntry=$frames?array_replace($entry,['generation_method'=>'start_end','market_sku_id'=>$frames['market_sku_id'],'user_charge_points'=>self::catalogPrice($frames,$duration,$secondBased)]):null;
                        // App adapters can normalize text-priced aliases to the same
                        // reference SKU. Display and freeze that authoritative SKU.
                        $key=implode('|',[$entry['quality'],$ratio,$duration,$reference['market_sku_id'],($frames['market_sku_id']??0)]);$specs[$key]=$refEntry;if($frameEntry)$frameSpecs[$key]=$frameEntry;
                        // A picker needs availability and frame support only.
                        // Full specifications are loaded separately for the active model.
                        if($summary&&(!$hasFrames||$frameSpecs))break 3;
                    }
                }
            }
            if(!$legacy){$specs=array_values($specs);$frameSpecs=array_values($frameSpecs);}
            if(!$specs||(!$legacy&&$mode!=='reference'&&!$frameSpecs))continue;
            $result[]=['channel_source'=>(string)($model['channel_source']??'unknown'),'channel_source_label'=>(string)($model['channel_source_label']??'渠道未标注'),'id'=>(string)$model['id'],'label'=>(string)$model['name'],'description'=>(string)($model['description']??''),'display_icon'=>(string)($model['display_icon']??''),'max_reference_images'=>array_intersect(['image_reference','omni_reference'],array_column($specs,'generation_method'))?min(15,(int)$model['max_reference_images']):1,'specs'=>$specs,'start_end_specs'=>$frameSpecs,'generation_modes'=>$hasFrames&&$frameSpecs?['reference','start_end']:['reference'],'supports_first_last_frame'=>(bool)($hasFrames&&$frameSpecs)];
            if($summary)$result[array_key_last($result)]['specs_complete']=false;
            if(!$legacy)try{Cache::set($entryCacheKey,$result[array_key_last($result)],self::ENTRY_CACHE_TTL);}catch(\Throwable){}
        }
        // Catalog discovery is advisory; exact quote/reserve always validates live.
        // A short shared cache avoids repeating market reads for simultaneous
        // PC/mobile/admin config loads and never authorizes a paid request.
        if(!$legacy)try{Cache::set($sharedKey,$result,15);}catch(\Throwable){}
        return self::$cache[$cacheKey]=$result;
    }
    public static function model(int $tenant,string $id,bool $legacy=false,string $mode='both'): array
    {
        foreach(self::models($tenant,$legacy,$mode,[$id]) as $m)if($m['id']===$id)return $m;throw new InvalidArgumentException('视频模型暂不可用，请重新选择');
    }
    public static function specs(array $model,string $quality,string $ratio): array
    {
        $rows=array_values(array_filter($model['specs'],fn($s)=>$s['quality']===$quality&&$s['ratio']===$ratio));
        if(!$rows)throw new InvalidArgumentException('当前模型不支持所选画质与比例');return $rows;
    }
    public static function spec(array $model,string $quality,string $ratio,int $duration,string $mode='reference'): array
    {
        if($mode==='start_end')$model['specs']=$model['start_end_specs']??[];
        foreach(self::specs($model,$quality,$ratio) as $s)if($s['duration']===$duration)return $s;throw new InvalidArgumentException('当前模型不支持此分段时长');
    }
    public static function runtime(array $spec): string{return ($spec['resource_type']??'model')==='app_api'?MarketVideoAppRuntimeService::class:MarketVideoModelRuntimeService::class;}
    private static function catalogPrice(array $quote,int $duration,bool $scale): float
    {
        $quantity=(float)($quote['_catalog_quantity']??1);
        if($scale)$quantity*=$duration/max(1,(int)$quote['selection']['duration']);
        return isset($quote['_catalog_meter'])?MarketGenerationMeter::price($quote['_catalog_unit_price'],$quantity,$quote['_catalog_meter']):$quote['user_charge_points'];
    }
    public static function quote(int $tenant,array $spec,string $model,bool $catalog=false): array
    {
        $selection=$spec+['model_id'=>$model,'channel'=>$model,'resolution'=>$spec['quality']];
        // Read-only quotes validate the generation mode's reference shape. This
        // application admits owned images separately; never send this pricing-only
        // placeholder to a provider or persist it in the executable selection.
        // An absolute non-routable URL avoids resolving a nonexistent upload
        // through tenant storage for every SKU/mode check. This is never fetched.
        $asset=['type'=>'image','uri'=>'https://pricing-only.invalid/aigc_micro_video/reference.png','role'=>$selection['generation_method']==='image_to_video'?'first_frame_image':'reference_image'];
        $assets=[$asset];if($selection['generation_method']==='start_end'){$assets=[array_replace($asset,['role'=>'first_frame_image']),array_replace($asset,['role'=>'last_frame_image'])];}
        $q=self::runtime($spec)::quote($tenant,$selection+['prompt'=>'保持参考图片中的商品外观，生成连续的商品展示镜头。','reference_images'=>array_column($assets,'uri'),'reference_assets'=>$assets]);
        if(($q['settlement_mode']??'reserved')!=='reserved')throw new InvalidArgumentException('此规格按实际用量结算，暂不支持分段预报价');
        $selection['market_sku_id']=(int)$q['market_sku_id'];
        $out=['selection'=>$selection,'tenant_cost_points'=>(float)$q['tenant_cost_points'],'user_charge_points'=>(float)$q['user_charge_points'],'market_sku_id'=>(int)$q['market_sku_id']];
        if($catalog){$snapshot=$q['market_snapshot'];$out['_catalog_quantity']=(float)$q['quantity'];$out['_catalog_unit_price']=(float)$snapshot['tenant_price'];$out['_catalog_meter']=array_intersect_key($snapshot,array_flip(['billing_meter','meter_context','usage_unit_size']));}
        return $out;
    }

}
