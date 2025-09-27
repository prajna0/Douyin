<?php
/**
 * 抖音无水印视频解析API服务
 * 
 * 该服务提供抖音视频的无水印解析功能，支持从抖音分享链接中提取视频ID，
 * 获取不同清晰度的视频资源链接，并返回视频相关统计信息（播放量、点赞数等）。
 * 系统采用缓存机制减少重复请求，提高响应速度并降低第三方API调用频率。
 * 
 * 核心功能：
 * - 从抖音分享URL提取视频ID
 * - 获取多清晰度无水印视频播放链接
 * - 提供视频元数据（分辨率、帧率、文件大小）
 * - 返回视频统计信息（播放量、点赞、评论等）
 * - 实现数据缓存机制，提升性能
 * 
 * @author JiJiang
 * @version 2.2.0
 * @date 2025-09-27
 */

// ======================
// 配置区 - 请根据实际情况修改
// ======================
$TIKHUB_API_KEY = '请替换为你的TikHub API密钥'; // 从https://tikhub.dev获取
$CACHE_DIR = __DIR__ . '/cache'; // 缓存目录路径
$CACHE_EXPIRE_TIME = 1; // 缓存有效期(秒)，默认1分钟
// ======================
// 以下为核心代码，通常无需修改
// ======================

// 设置跨域访问和响应格式
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

/**
 * 格式化播放量数字为易读格式
 * 
 * 将原始播放量数字转换为带有单位的字符串表示，支持以下格式：
 * - 小于1万：整数+千分位（如：1,234）
 * - 1万到1亿之间：保留两位小数+万单位（如：1.23万，1,000万）
 * - 1亿及以上：保留两位小数+亿单位（如：1.23亿，1,000.00亿）
 * 
 * @param int|float $count 原始播放量数字
 * @return string 格式化后的播放量字符串
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
 * 将字节数转换为带有合适单位的字符串表示（Bytes, KB, MB, GB, TB），
 * 保留指定精度的小数位数。
 * 
 * @param int $bytes 文件大小（字节）
 * @param int $precision 小数保留位数，默认2位
 * @return string 格式化后的文件大小字符串
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
 * 获取远程文件的大小
 * 
 * 通过多种方式（CURL头请求、响应头解析、get_headers函数）获取远程文件的大小，
 * 并使用formatFileSize函数进行格式化。
 * 
 * @param string $url 远程文件URL
 * @return string 格式化后的文件大小，获取失败时返回"未知大小"
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
 * 通用CURL请求函数
 * 
 * 封装CURL操作，支持GET/POST请求、自定义请求头、头信息请求等功能，
 * 并返回响应内容和请求信息。
 * 
 * @param string $url 请求URL
 * @param array $headers 请求头信息数组
 * @param mixed $postData POST数据，为null时表示GET请求
 * @param bool $isHeadRequest 是否仅请求头信息
 * @return array 包含'response'（响应内容）和'info'（请求信息）的数组
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
    
    // 执行请求并获取结果
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
 * 根据视频ID生成对应的缓存文件路径
 * 
 * @param string $videoId 视频ID
 * @return string 缓存文件的完整路径
 */
function getCacheFilePath($videoId) {
    global $CACHE_DIR;
    return $CACHE_DIR . '/video_' . $videoId . '.cache';
}

/**
 * 获取缓存数据
 * 
 * 检查指定视频ID的缓存是否存在且未过期，若有效则返回缓存数据
 * 
 * @param string $videoId 视频ID
 * @return array|null 缓存数据数组，缓存无效时返回null
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
 * 设置缓存数据
 * 
 * 将视频解析数据写入缓存文件，包含时间戳用于过期判断
 * 
 * @param string $videoId 视频ID
 * @param array $highQualityData 高清视频数据
 * @param array $statistics 视频统计数据
 * @param string $fps 视频帧率
 * @param int $width 视频宽度
 * @param int $height 视频高度
 * @return void
 */
function setCache($videoId, $highQualityData, $statistics, $fps, $width, $height) {
    $cacheData = [
        'timestamp' => time(),
        'highQualityData' => $highQualityData,
        'statistics' => $statistics,
        'fps' => $fps,
        'width' => $width,
        'height' => $height
    ];
    
    file_put_contents(getCacheFilePath($videoId), json_encode($cacheData));
}

/**
 * 清理过期缓存
 * 
 * 扫描缓存目录，删除所有超过有效期的缓存文件
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
 * 调用TikHub API获取指定视频ID的高清视频播放地址，优先使用缓存数据
 * 
 * @param string $videoId 视频ID
 * @return array 包含高清视频信息的数组，获取失败时返回空数组
 */
function getHighQualityVideoUrl($videoId) {
    global $TIKHUB_API_KEY;
    
    // 尝试从缓存获取
    $cache = getCache($videoId);
    if ($cache && isset($cache['highQualityData'])) {
        return $cache['highQualityData'];
    }
    
    // 调用API获取
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
 * 获取视频统计信息
 * 
 * 调用TikHub API获取指定视频ID的统计数据（播放量、点赞数等），优先使用缓存
 * 
 * @param string $videoId 视频ID
 * @return array 包含视频统计信息的数组
 */
function getVideoStatistics($videoId) {
    // 尝试从缓存获取
    $cache = getCache($videoId);
    if ($cache && isset($cache['statistics'])) {
        return $cache['statistics'];
    }
    
    // 调用API获取
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
    
    // 默认返回空统计数据
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
 * 跟随抖音分享链接的重定向，获取最终的视频页面URL
 * 
 * @param string $url 抖音分享链接
 * @return string 最终跳转后的URL
 */
function getDyFinalUrl($url) {
    $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';
    $result = curlRequest($url, ['User-Agent: ' . $userAgent], null, true);
    
    return $result['info']['url'] ?? $url;
}

/**
 * 从抖音分享链接提取视频ID
 * 
 * 通过解析最终跳转URL，使用正则表达式提取视频ID
 * 
 * @param string $shareUrl 抖音分享链接
 * @return string|null 提取到的视频ID，失败时返回null
 */
function extractDyId($shareUrl) {
    $finalUrl = getDyFinalUrl($shareUrl);
    preg_match('/(?<=video\/)[0-9]+|[0-9]{10,}/', $finalUrl, $match);
    
    return $match[0] ?? null;
}

/**
 * 构建API响应数组
 * 
 * 统一API响应格式，包含状态码、消息和数据三部分
 * 
 * @param int $code 状态码，200表示成功
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
 * 获取视频清晰度列表
 * 
 * 从API返回数据中提取不同清晰度的视频播放链接，并整理成规范格式
 * 支持原画、1080P、720P、540P等多种清晰度
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
    $statistics = getVideoStatistics($videoId);
    $playCount = isset($statistics['play_count']) ? formatPlayCount($statistics['play_count']) : '未知';
    $fpsDesc = $fps ?: '未知';
    $resolutionDesc = $width && $height ? "{$width}×{$height}" : '未知分辨率';
    
    // 添加说明信息
    $videoList[] = ['url' => 'javascript:void(0)', 'level' => "免责声明: 下载的视频仅供个人学习"];
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
 * 整合各个功能模块，完成从URL解析到视频信息提取的完整流程
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
    
    // 获取高清视频数据
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
    
    // 提取视频元数据（帧率、宽高）
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
    
    // 获取视频列表
    $videoData = getVideoQualityList($highQualityData, $item, $videoId, $fps, $width, $height);
    
    // 更新缓存
    setCache($videoId, $highQualityData, $videoData['statistics'], $fps, $width, $height);
    
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

// 处理请求参数并执行解析
$inputUrl = $_GET['url'] ?? '';
if (empty($inputUrl)) {
    echo json_encode(douyinResponse(400, '缺少url参数'), JSON_UNESCAPED_UNICODE);
    exit;
}

$result = parseDouyinContent($inputUrl);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
?>
