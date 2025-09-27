# 抖音无水印视频解析API服务

一个高效、稳定的抖音无水印视频解析接口，支持多清晰度视频获取、API权限控制和实时数据统计。

## 功能特点

- **无水印解析**：获取抖音视频的无水印播放地址
- **多清晰度支持**：自动提取原画、1080P、720P、540P等多种清晰度视频
- **API权限控制**：通过API Key验证机制，仅允许授权用户访问
- **实时数据统计**：获取视频实时播放量、点赞数、评论数等统计信息
- **缓存优化**：对视频URL、帧率等非实时数据进行缓存，提升性能并减少API调用次数
- **跨域支持**：默认开启跨域访问，方便前端直接调用

## 环境要求

- PHP 7.0+
- cURL扩展
- 可写的缓存目录权限

## 安装部署

1. 下载代码到服务器
   ```bash
   上传 dyyy.php
   ```

2. 配置参数（在代码顶部配置区）
   ```php
   // 替换为你的Tikhub API密钥（从https://tikhub.dev获取）
   $TIKHUB_API_KEY = '你的Tikhub API密钥';
   
   // 配置允许访问的API Key列表
   $VALID_API_KEYS = [
       '你的第一个API密钥',
       '你的第二个API密钥'  // 可选
   ];
   ```

3. 确保缓存目录可写
   ```bash
   chmod 755 cache
   ```

4. 将代码部署到你的Web服务器（如Nginx、Apache）

## 使用方法

### 接口地址

```
https://你的域名/dyyy.php
```

### 请求方式

GET请求

### 请求参数

| 参数名 | 必选 | 说明 |
|--------|------|------|
| key    | 是   | 授权API密钥（在配置区VALID_API_KEYS中定义） |
| url    | 是   | 抖音视频分享链接（如：https://v.douyin.com/xxxx/） |

### 调用示例

```
https://你的域名/dyyy.php?key=prajna&url=https://v.douyin.com/xxxx/
```

## 响应说明

### 成功响应（code=200）

```json
{
  "code": 200,
  "msg": "解析成功",
  "data": {
    "aweme_id": "1234567890123456789",
    "fps": "30",
    "width": 1080,
    "height": 1920,
    "play_count": "125.3万",
    "digg_count": 5623,
    "comment_count": 128,
    "share_count": 356,
    "download_count": 89,
    "video_list": [
      {
        "url": "javascript:void(0)",
        "level": "免责声明: 下载的视频仅供个人学习"
      },
      {
        "url": "javascript:void(0)",
        "level": "未经作者授权不得用于商业或非法途径"
      },
      {
        "url": "javascript:void(0)",
        "level": "当前作品播放量: 125.3万"
      },
      {
        "url": "https://xxx.com/original_video.mp4",
        "level": "[原画]-[30FPS]-[1080×1920]-[12.5 MB]"
      },
      {
        "url": "https://xxx.com/1080p_video.mp4",
        "level": "[1080P]-[30FPS]-[8.2 MB]"
      }
    ]
  }
}
```

### 错误响应

```json
// 缺少API Key
{
  "code": 400,
  "msg": "缺少必要的key参数",
  "data": []
}

// 无效的API Key
{
  "code": 403,
  "msg": "无效的key参数，访问被拒绝",
  "data": []
}

// 提取视频ID失败
{
  "code": 201,
  "msg": "未能提取到视频ID",
  "data": []
}
```

## 配置说明

| 配置项 | 说明 |
|--------|------|
| TIKHUB_API_KEY | Tikhub API密钥，从[https://tikhub.io](https://tikhub.io)获取 |
| CACHE_DIR | 缓存文件存储目录，默认值：`__DIR__ . '/cache'` |
| CACHE_EXPIRE_TIME | 缓存有效期（秒），默认60分钟（3600秒） |
| VALID_API_KEYS | 允许访问的API密钥列表，可配置多个 |

## 注意事项

1. 请遵守抖音平台的使用规范，本工具仅用于学习和研究目的
2. 请勿将本工具用于商业用途或非法活动
3. 确保你的服务器能够正常访问抖音和Tikhub的API服务
4. 定期清理缓存目录，避免占用过多磁盘空间
5. Tikhub API有调用限制，请合理使用缓存功能
