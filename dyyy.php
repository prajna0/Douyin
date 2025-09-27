<?php
/**
 * 抖音无水印视频解析API服务
 * 
 * 功能说明：
 * 1. 提供抖音视频无水印解析功能，支持多清晰度视频获取
 * 2. 集成API Key验证机制，仅允许授权访问
 * 3. 实时获取视频播放量、点赞数等统计数据
 * 4. 对视频URL、帧率等非实时数据进行缓存优化性能
 * 
 * 接口调用方式：
 * GET请求，需携带参数：
 * - key: 授权访问密钥(在配置区VALID_API_KEYS中定义)
 * - url: 抖音视频分享链接
 * 
 * @author JiJiang
 * @version 2.3.1
 * @date 2025-09-27
 */

// 配置参数区
$TIKHUB_API_KEY = '请替换为你的TikHub API密钥'; // Tikhub API密钥(从https://user.tikhub.io/zh-hans/users/api_keys获取)
$CACHE_DIR = __DIR__ . '/cache'; // 缓存文件存储目录
$CACHE_EXPIRE_TIME = 3600; // 缓存有效期(秒)，默认60分钟(仅缓存非实时数据)
$VALID_API_KEYS = [          // 合法API密钥列表(支持多个)
    'prajna',   
    'test'  
];

// 基础响应头设置
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

/**
 * 格式化播放量为易读格式
 * 
 * @param int|float $count 原始播放量数值
 * @return string 格式化后的播放量(如：1.2万、3.5亿)
 */
function formatPlayCount($count) {
    $count = (float)$count;
    
    if ($count < 10000) {
        return number_format((int)$count);
    }
    
    if ($count < 100000000) {
        $value = $count / 10000;
        return (is_int($value) ? number_format((int)$value) : number_format($value, 2)) . '万';
    }
    
    $value = $count / 100000000;
    return (is_int($value) ? number_format((int)$value) : number_format($value, 2)) . '亿';
}

/**
 * 格式化文件大小为易读格式
 * 
 * @param int $bytes 文件大小(字节)
 * @param int $precision 小数保留位数
 * @return string 格式化后的文件大小(如：2.5MB、1.3GB)
 */
function formatFileSize($bytes, $precision = 2) {
    if ($bytes == 0) return '0 Bytes';
    
    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * 获取远程文件大小
 * 
 * @param string $url 远程文件URL
 * @return string 格式化后的文件大小，失败则返回"未知大小"
 */
function getRemoteFileSize($url) {
    $result = curlRequest($url, [], null, true);
    $contentLength = $result['info']['download_content_length'];
    
    if ($contentLength > 0) {
        return formatFileSize($contentLength);
    }
    
    if ($result['response']) {
        $headers = [];
        foreach (explode("\n", $result['response']) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) == 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }
        
        if (isset($headers['Content-Length'])) {
            return formatFileSize((int)$headers['Content-Length']);
        }
    }
    
    $headers = @get_headers($url, 1);
    if ($headers && isset($headers['Content-Length'])) {
        $length = $headers['Content-Length'];
        if (is_array($length)) $length = end($length);
        if (is_numeric($length)) return formatFileSize((float)$length);
    }
    
    return "未知大小";
}

/**
 * 通用CURL请求工具
 * 
 * @param string $url 请求URL
 * @param array $headers 请求头信息数组
 * @param mixed $postData POST数据(为null时表示GET请求)
 * @param bool $isHeadRequest 是否仅请求头信息
 * @return array 包含响应内容(response)和请求信息(info)的数组
 */
function curlRequest($url, $headers = [], $postData = null, $isHeadRequest = false) {
    $ch = curl_init($url);
    
    // 基础配置
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    // 头信息请求配置
    if ($isHeadRequest) {
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
    }
    
    // 设置请求头
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    // POST请求配置
    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }
    
    // 执行请求并处理结果
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    return ['response' => $response, 'info' => $info];
}

// 确保缓存目录存在
if (!is_dir($CACHE_DIR)) {
    mkdir($CACHE_DIR, 0755, true);
}

/**
 * 获取缓存文件路径
 * 
 * @param string $videoId 视频ID
 * @return string 缓存文件完整路径
 */
function getCacheFilePath($videoId) {
    global $CACHE_DIR;
    return $CACHE_DIR . '/video_' . $videoId . '.cache';
}

/**
 * 获取缓存数据(仅包含非实时数据)
 * 
 * @param string $videoId 视频ID
 * @return array|null 缓存数据数组(缓存有效)，无效时返回null
 */
function getCache($videoId) {
    global $CACHE_EXPIRE_TIME;
    $cacheFile = getCacheFilePath($videoId);
    
    if (file_exists($cacheFile)) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        if (isset($cacheData['timestamp']) && (time() - $cacheData['timestamp']) < $CACHE_EXPIRE_TIME) {
            return $cacheData;
        }
    }
    
    return null;
}

/**
 * 设置缓存数据(仅存储非实时数据)
 * 
 * @param string $videoId 视频ID
 * @param array $highQualityData 高清视频数据
 * @param string $fps 视频帧率
 * @param int $width 视频宽度
 * @param int $height 视频高度
 * @return void
 */
function setCache($videoId, $highQualityData, $fps, $width, $height) {
    $cacheData = [
        'timestamp' => time(),
        'highQualityData' => $highQualityData,
        'fps' => $fps,
        'width' => $width,
        'height' => $height
    ];
    
    file_put_contents(getCacheFilePath($videoId), json_encode($cacheData));
}

/**
 * 清理过期缓存文件
 * 
 * @return void
 */
function cleanExpiredCache() {
    global $CACHE_DIR, $CACHE_EXPIRE_TIME;
    
    if (!is_dir($CACHE_DIR)) return;
    
    $files = scandir($CACHE_DIR);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filePath = $CACHE_DIR . '/' . $file;
        if (!is_file($filePath) || !strpos($file, '.cache')) continue;
        
        if ((time() - filemtime($filePath)) > $CACHE_EXPIRE_TIME) {
            @unlink($filePath);
        }
    }
}

// 执行过期缓存清理
cleanExpiredCache();

/**
 * 获取高清视频URL数据
 * 
 * @param string $videoId 视频ID
 * @return array 高清视频信息数组，失败时返回空数组
 */
function getHighQualityVideoUrl($videoId) {
    global $TIKHUB_API_KEY;
    
    // 尝试从缓存获取(视频URL信息)
    $cache = getCache($videoId);
    if ($cache && isset($cache['highQualityData'])) {
        return $cache['highQualityData'];
    }
    
    // 调用API获取数据
    $apiUrl = "https://api.tikhub.dev/api/v1/douyin/web/fetch_video_high_quality_play_url?aweme_id={$videoId}";
    $result = curlRequest($apiUrl, [
        "Authorization: Bearer {$TIKHUB_API_KEY}",
        'Content-Type: application/json'
    ]);
    
    if ($result['info']['http_code'] == 200) {
        $data = json_decode($result['response'], true);
        if (isset($data['data'])) {
            return $data;
        }
    }
    
    return [];
}

/**
 * 获取视频实时统计信息(播放量、点赞数等)
 * 
 * @param string $videoId 视频ID
 * @return array 视频统计信息数组
 */
function getVideoStatistics($videoId) {
    global $TIKHUB_API_KEY;
    $apiUrl = "https://api.tikhub.dev/api/v1/douyin/app/v3/fetch_video_statistics?aweme_ids={$videoId}";
    $result = curlRequest($apiUrl, [
        "Authorization: Bearer {$TIKHUB_API_KEY}",
        'Content-Type: application/json'
    ]);
    
    if ($result['info']['http_code'] == 200) {
        $data = json_decode($result['response'], true);
        if (isset($data['data']['statistics_list']) && !empty($data['data']['statistics_list'])) {
            foreach ($data['data']['statistics_list'] as $item) {
                if (isset($item['aweme_id']) && $item['aweme_id'] == $videoId) {
                    return $item;
                }
            }
        }
    }
    
    // 默认返回空统计数据结构
    return [
        'aweme_id' => $videoId,
        'play_count' => 0,
        'digg_count' => 0,
        'comment_count' => 0,
        'share_count' => 0,
        'download_count' => 0
    ];
}

/**
 * 获取抖音链接的最终跳转URL
 * 
 * @param string $url 抖音分享链接
 * @return string 跳转后的最终URL
 */
function getDyFinalUrl($url) {
    $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';
    $result = curlRequest($url, ['User-Agent: ' . $userAgent], null, true);
    
    return $result['info']['url'] ?? $url;
}

/**
 * 从抖音分享链接提取视频ID
 * 
 * @param string $shareUrl 抖音分享链接
 * @return string|null 提取到的视频ID，失败返回null
 */
function extractDyId($shareUrl) {
    $finalUrl = getDyFinalUrl($shareUrl);
    preg_match('/(?<=video\/)[0-9]+|[0-9]{10,}/', $finalUrl, $match);
    
    return $match[0] ?? null;
}

/**
 * 构建API标准响应结构
 * 
 * @param int $code 状态码(200表示成功)
 * @param string $msg 响应消息
 * @param array $data 响应数据
 * @return array 格式化的响应数组
 */
function douyinResponse($code = 200, $msg = '解析成功', $data = []) {
    return [
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ];
}

/**
 * 获取视频清晰度列表及统计信息
 * 
 * @param array $highQualityData 高清视频数据
 * @param array $item 视频详情数据
 * @param string $videoId 视频ID
 * @param string $fps 视频帧率
 * @param int $width 视频宽度
 * @param int $height 视频高度
 * @return array 包含视频列表和统计信息的数组
 */
function getVideoQualityList($highQualityData, $item, $videoId, $fps, $width, $height) {
    $videoList = [];
    // 获取实时统计数据
    $statistics = getVideoStatistics($videoId);
    $playCount = isset($statistics['play_count']) ? formatPlayCount($statistics['play_count']) : '未知';
    $fpsDesc = $fps ?: '未知';
    $resolutionDesc = $width && $height ? "{$width}×{$height}" : '未知分辨率';
    
    // 添加说明信息
    $videoList[] = ['url' => 'javascript:void(0)', 'level' => "prajna's Douyin API"];
    $videoList[] = ['url' => 'javascript:void(0)', 'level' => "未经作者授权不得用于商业或非法途径"];
    $videoList[] = ['url' => 'javascript:void(0)', 'level' => "当前作品播放量: {$playCount}"];
    
    // 添加原画视频
    $originalVideoUrl = $highQualityData['data']['original_video_url'] ?? '';
    if ($originalVideoUrl) {
        $fileSize = getRemoteFileSize($originalVideoUrl);
        $videoList[] = [
            'url' => $originalVideoUrl,
            'level' => "[原画]-[{$fpsDesc}FPS]-[{$resolutionDesc}]-[{$fileSize}]"
        ];
    }
    
    // 处理不同清晰度的视频
    $resolutionMap = [];
    $addedCount = 0;
    $targetResolutions = [
        '[1080P]' => ['keyword' => '1080'],
        '[720P]'  => ['keyword' => '720'],
        '[540P]'  => ['keyword' => '540']
    ];
    
    // 从bit_rate中提取不同清晰度视频
    if (isset($highQualityData['data']['video_data']['aweme_detail']['video']['bit_rate'])) {
        $bitRates = $highQualityData['data']['video_data']['aweme_detail']['video']['bit_rate'];
        
        // 提取1080P视频
        foreach ($bitRates as $bitRate) {
            if ($addedCount >= 1 || !isset($bitRate['play_addr']['url_list'][0]) || isset($resolutionMap['[1080P]'])) {
                break;
            }
            
            if (strpos($bitRate['gear_name'] ?? '', $targetResolutions['[1080P]']['keyword']) !== false) {
                $url = $bitRate['play_addr']['url_list'][0];
                $fileSize = getRemoteFileSize($url);
                $videoList[] = ['url' => $url, 'level' => "[1080P]-[{$fpsDesc}FPS]-[{$fileSize}]"];
                $resolutionMap['[1080P]'] = true;
                $addedCount++;
            }
        }
        
        // 提取720P视频
        foreach ($bitRates as $bitRate) {
            if ($addedCount >= 2 || !isset($bitRate['play_addr']['url_list'][0]) || isset($resolutionMap['[720P]'])) {
                break;
            }
            
            if (strpos($bitRate['gear_name'] ?? '', $targetResolutions['[720P]']['keyword']) !== false) {
                $url = $bitRate['play_addr']['url_list'][0];
                $fileSize = getRemoteFileSize($url);
                $videoList[] = ['url' => $url, 'level' => "[720P]-[{$fpsDesc}FPS]-[{$fileSize}]"];
                $resolutionMap['[720P]'] = true;
                $addedCount++;
            }
        }
        
        // 提取540P视频
        foreach ($bitRates as $bitRate) {
            if ($addedCount >= 3 || !isset($bitRate['play_addr']['url_list'][0]) || isset($resolutionMap['[540P]'])) {
                break;
            }
            
            if (strpos($bitRate['gear_name'] ?? '', $targetResolutions['[540P]']['keyword']) !== false) {
                $url = $bitRate['play_addr']['url_list'][0];
                $fileSize = getRemoteFileSize($url);
                $videoList[] = ['url' => $url, 'level' => "[540P]-[{$fpsDesc}FPS]-[{$fileSize}]"];
                $resolutionMap['[540P]'] = true;
                $addedCount++;
            }
        }
    }
    
    // 从play_url中补充不同清晰度视频
    if ($addedCount < 3 && isset($highQualityData['data']['play_url']['url_list'])) {
        $playUrls = $highQualityData['data']['play_url']['url_list'];
        $fileSize = getRemoteFileSize($playUrls[0] ?? '');
        
        if (!isset($resolutionMap['[1080P]']) && count($playUrls) >= 1) {
            $videoList[] = ['url' => $playUrls[0], 'level' => "[1080P]-[{$fpsDesc}FPS]-[{$fileSize}]"];
            $resolutionMap['[1080P]'] = true;
            $addedCount++;
        }
        
        if (!isset($resolutionMap['[720P]']) && count($playUrls) >= 2) {
            $videoList[] = ['url' => $playUrls[1], 'level' => "[720P]-[{$fpsDesc}FPS]-[{$fileSize}]"];
            $resolutionMap['[720P]'] = true;
            $addedCount++;
        }
        
        if (!isset($resolutionMap['[540P]']) && count($playUrls) >= 3) {
            $videoList[] = ['url' => $playUrls[2], 'level' => "[540P]-[{$fpsDesc}FPS]-[{$fileSize}]"];
            $resolutionMap['[540P]'] = true;
            $addedCount++;
        }
    }
    
    // 从item数据中补充不同清晰度视频
    if ($addedCount < 3 && isset($item['video']['bit_rate'])) {
        $itemBitRates = $item['video']['bit_rate'];
        
        if (!isset($resolutionMap['[1080P]']) && isset($itemBitRates[0]['play_addr']['url_list'][0])) {
            $url = $itemBitRates[0]['play_addr']['url_list'][0];
            $fileSize = getRemoteFileSize($url);
            $videoList[] = ['url' => $url, 'level' => "[1080P]-[{$fpsDesc}FPS]-[{$fileSize}]"];
            $resolutionMap['[1080P]'] = true;
            $addedCount++;
        }
        
        if (!isset($resolutionMap['[720P]']) && isset($itemBitRates[1]['play_addr']['url_list'][0])) {
            $url = $itemBitRates[1]['play_addr']['url_list'][0];
            $fileSize = getRemoteFileSize($url);
            $videoList[] = ['url' => $url, 'level' => "[720P]-[{$fpsDesc}FPS]-[{$fileSize}]"];
            $resolutionMap['[720P]'] = true;
            $addedCount++;
        }
        
        if (!isset($resolutionMap['[540P]']) && isset($itemBitRates[2]['play_addr']['url_list'][0])) {
            $url = $itemBitRates[2]['play_addr']['url_list'][0];
            $fileSize = getRemoteFileSize($url);
            $videoList[] = ['url' => $url, 'level' => "[540P]-[{$fpsDesc}FPS]-[{$fileSize}]"];
            $resolutionMap['[540P]'] = true;
            $addedCount++;
        }
    }
    
    return ['videoList' => $videoList, 'statistics' => $statistics];
}

/**
 * 抖音视频解析主函数
 * 
 * @param string $inputUrl 抖音分享URL
 * @return array 格式化的API响应数组
 */
function parseDouyinContent($inputUrl) {
    // 提取视频ID
    $videoId = extractDyId($inputUrl);
    if (!$videoId) {
        return douyinResponse(201, '未能提取到视频ID');
    }
    
    // 获取高清视频数据(带缓存)
    $highQualityData = getHighQualityVideoUrl($videoId);
    $cache = getCache($videoId);
    
    // 获取抖音页面数据
    $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';
    $headers = [
        'User-Agent: ' . $userAgent,
        'Referer: https://www.douyin.com/',
        'Accept-Language: zh-CN,zh;q=0.9',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8'
    ];
    
    $pageResult = curlRequest("https://www.iesdouyin.com/share/video/{$videoId}", $headers);
    $html = $pageResult['response'];
    
    if (!$html) {
        return douyinResponse(201, '请求抖音页面失败，备用清晰度不可用');
    }
    
    // 解析页面数据
    if (!preg_match('/window\.(?:_ROUTER_DATA|_RENDER_DATA)\s*=\s*(.*?);?\s*<\/script>/s', $html, $jsonMatch)) {
        return douyinResponse(201, '未能解析视频页面数据，备用清晰度不可用');
    }
    
    $dataArr = json_decode(trim($jsonMatch[1]), true);
    $item = $dataArr['loaderData']['video_(id)/page']['videoInfoRes']['item_list'][0] ?? [];
    
    // 提取视频元数据(帧率、宽高)
    $fps = $cache['fps'] ?? '';
    $width = $cache['width'] ?? 0;
    $height = $cache['height'] ?? 0;
    
    if (!$cache && !empty($highQualityData)) {
        $bitRateList = $highQualityData['data']['video_data']['aweme_detail']['video']['bit_rate'] ?? [];
        $fps = $bitRateList[0]['FPS'] ?? '';
        
        $videoInfo = $highQualityData['data']['video_data']['aweme_detail']['video'] ?? [];
        $width = $videoInfo['width'] ?? 0;
        $height = $videoInfo['height'] ?? 0;
    }
    
    $fps = $fps ?: '未知';
    $width = $width ?: '未知';
    $height = $height ?: '未知';
    
    // 获取视频列表和实时统计数据
    $videoData = getVideoQualityList($highQualityData, $item, $videoId, $fps, $width, $height);
    
    // 更新缓存(仅存储非实时数据)
    setCache($videoId, $highQualityData, $fps, $width, $height);
    
    // 构建返回结果
    $result = [
        'aweme_id' => $videoId,
        'fps' => $fps,
        'width' => $width,
        'height' => $height,
        'play_count' => formatPlayCount($videoData['statistics']['play_count'] ?? 0),
        'digg_count' => $videoData['statistics']['digg_count'] ?? 0,
        'comment_count' => $videoData['statistics']['comment_count'] ?? 0,
        'share_count' => $videoData['statistics']['share_count'] ?? 0,
        'download_count' => $videoData['statistics']['download_count'] ?? 0,
        'video_list' => $videoData['videoList']
    ];
    
    return douyinResponse(200, '解析成功', $result);
}

// API Key验证逻辑
$apiKey = $_GET['key'] ?? '';    // 获取请求中的API密钥
$inputUrl = $_GET['url'] ?? '';  // 获取请求中的视频链接

// 验证API密钥
if (empty($apiKey)) {
    echo json_encode(douyinResponse(400, '缺少必要的key参数'), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($apiKey, $VALID_API_KEYS)) {
    echo json_encode(douyinResponse(403, '无效的key参数，访问被拒绝'), JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证视频链接参数
if (empty($inputUrl)) {
    echo json_encode(douyinResponse(400, '缺少url参数，请在URL中添加 url=抖音分享链接'), JSON_UNESCAPED_UNICODE);
    exit;
}

// 执行解析并返回结果
$result = parseDouyinContent($inputUrl);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
?>
