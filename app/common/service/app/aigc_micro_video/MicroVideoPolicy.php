<?php
namespace app\common\service\app\aigc_micro_video;

use InvalidArgumentException;

/** Pure plan, retry and public presentation rules; no model calls or writes. */
final class MicroVideoPolicy
{
    public static function allowedModel(string $id,array $allowed): void
    {
        if($id===''||!in_array($id,$allowed,true))throw new InvalidArgumentException('请选择应用支持的视频模型');
    }

    public static function durations(int $target,array $supported,int $max): array
    {
        $supported=array_values(array_unique(array_filter(array_map('intval',$supported),fn($s)=>$s>0&&$s<=120)));
        if($target<1||$target>600||$max<1||!$supported)throw new InvalidArgumentException('目标时长或模型时长配置无效');
        sort($supported);$paths=[0=>[]];
        for($i=0;$i<$max;$i++){
            foreach($paths as $sum=>$parts){
                if(count($parts)!==$i)continue;
                foreach($supported as $seconds){$next=$sum+$seconds;if($next>$target+max($supported))continue;$candidate=[...$parts,$seconds];if(!isset($paths[$next])||count($candidate)<count($paths[$next]))$paths[$next]=$candidate;}
            }
        }
        unset($paths[0]);if(!$paths)throw new InvalidArgumentException('无法按当前模型规划视频时长');
        $keys=array_keys($paths);usort($keys,fn($a,$b)=>[abs($a-$target),$a<$target?1:0,count($paths[$a])]<=>[abs($b-$target),$b<$target?1:0,count($paths[$b])]);
        $best=$keys[0];if(abs($best-$target)>=min($supported))throw new InvalidArgumentException('目标时长超出当前模型与最大分段数的能力范围');
        return $paths[$best];
    }

    public static function segments(array $segments,array $durations,int $imageCount,int $referenceLimit,int $max,bool $requireTimeline=false,bool $allowUnboundReferences=false): array
    {
        if(!$segments||count($segments)>$max)throw new InvalidArgumentException('分段数量超出配置范围');
        $out=[];
        foreach(array_values($segments) as $index=>$s){
            if(!is_array($s)||!(is_int($s['duration']??null)||(is_string($s['duration']??null)&&ctype_digit($s['duration'])))||!in_array((int)$s['duration'],$durations,true))throw new InvalidArgumentException('分段时长不受当前模型支持');
            $row=['sort'=>$index+1,'duration'=>(int)$s['duration']];
            foreach(['title','prompt','start_state','end_state','continuity'] as $field){
                $value=$s[$field]??'';
                if(!is_string($value))throw new InvalidArgumentException('分段文本字段格式无效，请重新规划');
                $text=$field==='prompt'&&$requireTimeline?MicroVideoTimeline::normalize($value):trim($value);
                if($text===''||mb_strlen($text)>($field==='prompt'?12000:($field==='title'?200:2000)))throw new InvalidArgumentException('请完整填写分段提示词和衔接信息，标题限200字');
                $row[$field]=$text;
            }
            if($requireTimeline)MicroVideoTimeline::validate($row['prompt'],$row['duration']);
            $indices=$s['image_indices']??[];
            if(!is_array($indices)||(!$indices&&!$allowUnboundReferences)||count($indices)>$referenceLimit)throw new InvalidArgumentException('分段引用图片数量超出模型限制');
            foreach($indices as $value)if(!is_int($value)&&!(is_string($value)&&ctype_digit($value)))throw new InvalidArgumentException('图片引用无效');
            $indices=array_values(array_unique(array_map('intval',$indices)));
            foreach($indices as $value)if($value<0||$value>=$imageCount)throw new InvalidArgumentException('分段引用图片不存在');
            $row['image_indices']=$indices;$out[]=$row;
        }
        return $out;
    }

    /** Resolve authored shots against the tenant's enabled models and their exact SKUs. */
    public static function workbenchShots(array $segments, array $models, string $ratio): array
    {
        $shots = [];
        foreach (array_values($segments) as $index => $segment) {
            $quality = $segment['quality'] ?? '';
            $height = is_string($quality) ? match (strtolower($quality)) {
                '2k' => 1440, '4k' => 2160,
                default => preg_match('/^(\d+)p$/i', $quality, $match) ? (int)$match[1] : 0,
            } : 0;
            if ($height < 720) throw new InvalidArgumentException('请为分镜 '.($index + 1).' 选择720P及以上的分辨率');
            $id = $segment['model_id'] ?? '';
            $raw = $segment['duration'] ?? null;
            $duration = (is_int($raw) || is_string($raw)) ? filter_var($raw, FILTER_VALIDATE_INT) : false;
            if (!is_string($id) || $id === '' || !$duration || $duration < 1) throw new InvalidArgumentException('请为分镜 '.($index + 1).' 选择视频模型和时长');
            $model = $models[$id] ?? null;
            if (!$model) throw new InvalidArgumentException('分镜 '.($index + 1).' 的模型暂不可用');
            try {
                $spec = MicroVideoCatalog::spec($model, $quality, $ratio, $duration);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('分镜 '.($index + 1).' 的模型不支持所选分辨率、画幅或时长');
            }
            $frames = array_filter($model['start_end_specs'] ?? [], fn($s) => $s['quality'] === $quality && $s['ratio'] === $ratio && $s['duration'] === $duration);
            $audioIds = $segment['audio_ids'] ?? [];
            if (!is_array($audioIds) || !array_is_list($audioIds) || count($audioIds) > ($spec['max_reference_audios'] ?? 0)) {
                throw new InvalidArgumentException('分镜 '.($index + 1).' 的模型或规格不支持所选参考音频');
            }
            foreach ($audioIds as $audioId) {
                if ((!is_int($audioId) && !is_string($audioId)) || !ctype_digit((string)$audioId) || (int)$audioId < 1) throw new InvalidArgumentException('分镜参考音频标识无效');
            }
            $audioIds = array_map('intval', $audioIds);
            if (count($audioIds) !== count(array_unique($audioIds))) throw new InvalidArgumentException('分镜参考音频重复');
            $shots[] = ['model_id' => $id, 'quality' => $quality, 'duration' => $duration, 'max_reference_images' => $spec['generation_method'] === 'image_to_video' ? 1 : $model['max_reference_images'], 'supports_start_end' => (bool)$frames && !$audioIds] + ($audioIds ? ['audio_ids' => $audioIds] : []);
        }
        if (array_sum(array_column($shots, 'duration')) > 600) throw new InvalidArgumentException('分镜总时长不能超过600秒');
        return $shots;
    }

    public static function authoredSegments(array $segments, array $shots, int $imageCount): array
    {
        if (count($segments) !== count($shots)) throw new InvalidArgumentException('分镜数量已变化，请从项目重新生成方案');
        $rows = [];
        foreach (array_values($segments) as $index => $segment) {
            $shot = $shots[$index];
            if (isset($segment['model_id']) && $segment['model_id'] !== $shot['model_id']) throw new InvalidArgumentException('分镜模型已变化，请从项目重新生成方案');
            if (isset($segment['quality']) && $segment['quality'] !== $shot['quality']) throw new InvalidArgumentException('分镜分辨率已变化，请从项目重新生成方案');
            [$row] = self::segments([$segment], [$shot['duration']], $imageCount, $shot['max_reference_images'], 1);
            $row['sort'] = $index + 1;
            $rows[] = $row;
        }
        return $rows;
    }

    public static function retryIds(array $rows): array
    {
        return array_values(array_map(fn($r)=>(int)$r['id'],array_filter($rows,fn($r)=>($r['status']??'')==='failed')));
    }

    public static function publicState(string $status,string $frameStatus='off'): array
    {
        $labels=['planning'=>'正在构思','awaiting_confirmation'=>'待确认分段','running'=>'正在生成视频','waiting_continue'=>'待继续','merging'=>'正在整理成片','success'=>'已完成','failed'=>'需要调整创作信息','canceled'=>'已取消'];
        if($status==='awaiting_confirmation'){$frames=['idle'=>'待准备首尾帧','generating'=>'正在准备首尾帧','review'=>'待确认首尾帧','ready'=>'待确认生成','waiting_continue'=>'首尾帧待继续'];$labels[$status]=$frames[$frameStatus]??$labels[$status];}
        return ['status'=>$status,'status_text'=>$labels[$status]??'待继续','can_continue'=>$status==='waiting_continue','can_show_error'=>false];
    }

    public static function fingerprint(array $data): string
    {
        $sort=function($value)use(&$sort){if(!is_array($value))return $value;if(!array_is_list($value))ksort($value);return array_map($sort,$value);};
        return hash('sha256',json_encode($sort($data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    public static function stableParams(array $params): array
    {
        // Post-processing is bound by the execution quote, not the creative draft's idempotency key.
        unset($params['subtitles_enabled']);
        foreach($params['images']??[] as $i=>$image){unset($image['url']);$params['images'][$i]=$image;}return $params;
    }
}
