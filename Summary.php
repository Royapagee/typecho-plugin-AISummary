<?php

namespace TypechoPlugin\AISummary;

use Typecho\Common;
use Typecho\Config;
use Typecho\Http\Client;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 摘要生成逻辑：整理正文、提取并处理图片，然后调用 OpenAI 格式的聊天补全接口。
 */
final class Summary
{
    /** 单次请求中所有图片 data URL 的总量上限（约 24 MiB）。 */
    private const MAX_TOTAL_IMAGE_BYTES = 25165824;

    /** 允许交给 GD 处理的最大像素数，避免压缩手机原图时耗尽内存。 */
    private const MAX_IMAGE_PIXELS = 16000000;

    private const SUPPORTED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** 即使站点地址不是它们，也应视为本机地址。 */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * 生成摘要。
     *
     * @return array{
     *     summary:string,
     *     imageCount:int,
     *     skippedImageCount:int,
     *     textLength:int,
     *     truncated:bool
     * }
     * @throws \RuntimeException
     */
    public static function generate(string $title, string $text): array
    {
        $settings = self::settings();

        $apiUrl = trim((string)($settings->apiUrl ?? ''));
        $apiKey = trim((string)($settings->apiKey ?? ''));
        $model = trim((string)($settings->modelName ?? ''));

        if ($apiUrl === '' || $apiKey === '' || $model === '') {
            throw new \RuntimeException(_t('请先在插件设置中填写 API 地址、API Key 和模型名称'));
        }

        $text = self::cleanText($text);
        $title = trim($title);

        $maxTextChars = max(0, self::intOption($settings, 'maxTextChars', 20000));
        $sentText = $text;
        $truncated = false;

        if ($maxTextChars > 0 && Common::strLen($sentText) > $maxTextChars) {
            // 截断时留出省略号的长度，避免请求体超出配置值太多。
            $sentText = Common::subStr($sentText, 0, $maxTextChars);
            $truncated = true;
        }

        [$imageParts, $skippedImages] = self::collectImages($text, $settings);

        if (trim($sentText) === '' && empty($imageParts)) {
            throw new \RuntimeException(_t('文章内容为空，无法生成摘要'));
        }

        $prompt = trim((string)($settings->prompt ?? ''));
        if ($prompt === '') {
            $prompt = Plugin::DEFAULT_PROMPT;
        }

        $userText = $title !== '' ? "标题：{$title}\n\n" : '';
        $userText .= "正文：\n" . ($sentText !== ''
            ? $sentText
            : _t('（文章没有文字内容，请根据随附图片进行总结。）'));

        $userContent = empty($imageParts)
            ? $userText
            : array_merge([['type' => 'text', 'text' => $userText]], $imageParts);

        $payload = [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];

        $temperature = $settings->temperature ?? null;
        if (is_numeric($temperature)) {
            $payload['temperature'] = (float)$temperature;
        }

        $client = Client::get();
        if ($client === null) {
            throw new \RuntimeException(_t('当前环境未启用 cURL 扩展'));
        }

        $timeout = max(10, self::intOption($settings, 'timeout', 120));

        // 部分主机的 max_execution_time 低于接口超时，给它留出足够时间。
        @set_time_limit($timeout + 15);

        $client
            ->setTimeout($timeout)
            ->setHeader('Authorization', 'Bearer ' . $apiKey)
            ->setHeader('Accept', 'application/json')
            ->setJson($payload)
            ->send(self::endpoint($apiUrl));

        $status = $client->getResponseStatus();
        $responseBody = $client->getResponseBody();
        $data = json_decode($responseBody, true);

        if (!is_array($data)) {
            throw new \RuntimeException(
                _t('摘要接口返回了无法解析的数据（HTTP %d）', $status),
                $status > 0 ? $status : 500
            );
        }

        if ($status < 200 || $status >= 300) {
            $message = $data['error']['message']
                ?? $data['message']
                ?? _t('摘要接口请求失败（HTTP %d）', $status);

            throw new \RuntimeException((string)$message, $status > 0 ? $status : 500);
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            // 少数兼容接口会以内容片段数组返回。
            $chunks = [];
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $chunks[] = (string)($part['text'] ?? '');
                } elseif (is_string($part)) {
                    $chunks[] = $part;
                }
            }
            $content = implode("\n", $chunks);
        }

        $summary = trim((string)$content);
        if ($summary === '') {
            throw new \RuntimeException(_t('摘要接口未返回有效内容'));
        }

        return [
            'summary'           => $summary,
            'imageCount'        => count($imageParts),
            'skippedImageCount' => $skippedImages,
            'textLength'        => Common::strLen($text),
            'truncated'         => $truncated,
        ];
    }

    /**
     * 读取插件设置。
     */
    private static function settings(): Config
    {
        return Options::alloc()->plugin(Plugin::NAME);
    }

    /**
     * 读取整数设置，空值回落到默认值。
     */
    private static function intOption(Config $settings, string $name, int $default): int
    {
        $value = $settings->{$name} ?? null;

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int)$value;
    }

    /**
     * 清理编辑器标记与控制字符。
     */
    private static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(['<!--markdown-->', '<!--more-->'], '', $text);
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

        return trim($cleaned ?? $text);
    }

    /**
     * 从正文中提取图片并转换为接口可用的 image_url 片段。
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private static function collectImages(string $text, Config $settings): array
    {
        if ((string)($settings->sendImages ?? '1') === '0') {
            return [[], 0];
        }

        $maxImages = max(0, self::intOption($settings, 'maxImages', 6));
        if ($maxImages === 0) {
            return [[], 0];
        }

        $parts = [];
        $skipped = 0;
        $totalBytes = 0;

        foreach (self::extractImageUrls($text) as $url) {
            if (count($parts) >= $maxImages) {
                break;
            }

            $part = self::imagePart($url, $settings);
            if ($part === null) {
                $skipped++;
                continue;
            }

            if ($totalBytes + $part['bytes'] > self::MAX_TOTAL_IMAGE_BYTES) {
                $skipped++;
                continue;
            }

            $totalBytes += $part['bytes'];
            $parts[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => $part['url']],
            ];
        }

        return [$parts, $skipped];
    }

    /**
     * 提取 Markdown 与 HTML 中引用的图片地址。
     *
     * @return array<int, string>
     */
    private static function extractImageUrls(string $text): array
    {
        $urls = [];

        // 行内图片：![alt](url "title")
        if (preg_match_all('/!\[[^\]]*\]\(\s*<?([^\s)>]+)>?(?:\s+["\'][^"\']*["\'])?\s*\)/u', $text, $matches)) {
            foreach ($matches[1] as $url) {
                $urls[] = $url;
            }
        }

        // HTML 图片：<img src="url">
        if (preg_match_all('/<img\b[^>]*?(?<![-\w])src\s*=\s*(["\'])(.*?)\1/is', $text, $matches)) {
            foreach ($matches[2] as $url) {
                $urls[] = $url;
            }
        }

        if (preg_match_all('/<img\b[^>]*?(?<![-\w])src\s*=\s*([^\s"\'>]+)/i', $text, $matches)) {
            foreach ($matches[1] as $url) {
                $urls[] = $url;
            }
        }

        // 引用式图片：![alt][label]、![alt][]、![alt]
        $definitions = self::extractReferenceDefinitions($text);
        if (!empty($definitions)) {
            if (preg_match_all('/!\[([^\]]*)\]\s*\[([^\]]*)\]/u', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $label = trim($match[2]) !== '' ? $match[2] : $match[1];
                    $url = $definitions[self::normalizeLabel($label)] ?? null;
                    if ($url !== null) {
                        $urls[] = $url;
                    }
                }
            }

            // 短引用 ![alt]：排除行内图片和完整引用已经覆盖的写法。
            if (preg_match_all('/!\[([^\]]*)\](?!\s*[\[(])/u', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $url = $definitions[self::normalizeLabel($match[1])] ?? null;
                    if ($url !== null) {
                        $urls[] = $url;
                    }
                }
            }
        }

        $urls = array_map(
            static fn(string $url): string => trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            $urls
        );

        return array_values(array_unique(array_filter($urls, static fn(string $url): bool => $url !== '')));
    }

    /**
     * 解析 Markdown 链接引用定义：[label]: destination "title"
     *
     * @return array<string, string> 归一化标签 => 图片地址
     */
    private static function extractReferenceDefinitions(string $text): array
    {
        $definitions = [];

        $pattern = '/^[ \t]{0,3}\[([^\]\n]+)\]:[ \t]*(?:<([^>\n]+)>|(\S+))/m';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = trim(($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
                if ($url !== '') {
                    $definitions[self::normalizeLabel($match[1])] = $url;
                }
            }
        }

        return $definitions;
    }

    /**
     * 按 CommonMark 规则归一化引用标签：忽略大小写和多余空白。
     */
    private static function normalizeLabel(string $label): string
    {
        $label = trim($label);
        $normalized = preg_replace('/\s+/u', ' ', $label);

        return strtolower($normalized ?? $label);
    }

    /**
     * 把单个图片地址转换为 image_url 片段。
     *
     * 本站图片读取本地文件并转为 data URL（外部接口访问不到 127.0.0.1）；
     * 外站图片直接交给接口抓取。
     *
     * @return array{url:string, bytes:int}|null
     */
    private static function imagePart(string $url, Config $settings): ?array
    {
        if (str_starts_with($url, 'data:')) {
            return self::dataUriPart($url, $settings);
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $path = (string)($parts['path'] ?? '');

        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if ($scheme === '') {
            // 协议相对地址：//example.com/image.jpg
            if (str_starts_with($url, '//')) {
                $host = strtolower((string)($parts['host'] ?? ''));
                if ($host !== '' && !self::isLocalHost($host)) {
                    return ['url' => 'https:' . $url, 'bytes' => 0];
                }

                return self::localImagePart($path, $settings);
            }

            return self::localImagePart($path, $settings);
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host !== '' && self::isLocalHost($host)) {
            return self::localImagePart($path, $settings);
        }

        return ['url' => $url, 'bytes' => 0];
    }

    /**
     * 读取本站图片文件。
     *
     * @return array{url:string, bytes:int}|null
     */
    private static function localImagePart(string $path, Config $settings): ?array
    {
        $file = self::localFile($path);

        return $file === null ? null : self::fileImagePart($file, $settings);
    }

    /**
     * 从本地图片文件生成 data URL，必要时压缩。
     *
     * @return array{url:string, bytes:int}|null
     */
    private static function fileImagePart(string $file, Config $settings): ?array
    {
        $info = @getimagesize($file);
        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;
        $mime = strtolower((string)($info['mime'] ?? ''));

        if (
            $width < 8 || $height < 8
            || !in_array($mime, self::SUPPORTED_IMAGE_MIMES, true)
        ) {
            return null;
        }

        $maxEdge = max(256, self::intOption($settings, 'imageMaxEdge', 1024));
        $maxBytes = max(64, self::intOption($settings, 'imageMaxKB', 4096)) * 1024;
        $size = @filesize($file);

        if ($size === false || $size <= 0) {
            return null;
        }

        if ($size > $maxBytes || $width > $maxEdge || $height > $maxEdge) {
            $resized = self::resizeImage($file, $width, $height, $maxEdge, $maxBytes);

            if ($resized !== null) {
                return $resized;
            }

            if ($size > $maxBytes) {
                return null;
            }
        }

        $data = @file_get_contents($file);
        if ($data === false || $data === '') {
            return null;
        }

        return self::dataPart($data, $mime);
    }

    /**
     * 把用户直接内嵌的 data:image 转为接口片段。
     *
     * @return array{url:string, bytes:int}|null
     */
    private static function dataUriPart(string $url, Config $settings): ?array
    {
        if (!preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.*)$#is', $url, $matches)) {
            return null;
        }

        $mime = strtolower($matches[1]);
        if (!in_array($mime, self::SUPPORTED_IMAGE_MIMES, true)) {
            return null;
        }

        $data = base64_decode(preg_replace('/\s+/', '', $matches[2]) ?? '', true);
        if ($data === false || $data === '') {
            return null;
        }

        $info = @getimagesizefromstring($data);
        if ($info === false || $info[0] < 8 || $info[1] < 8) {
            return null;
        }

        $maxBytes = max(64, self::intOption($settings, 'imageMaxKB', 4096)) * 1024;
        if (strlen($data) > $maxBytes || $info[0] * $info[1] > self::MAX_IMAGE_PIXELS) {
            return null;
        }

        return self::dataPart($data, $mime);
    }

    /**
     * 用 GD 压缩图片，输出 JPEG。
     *
     * @return array{url:string, bytes:int}|null
     */
    private static function resizeImage(string $file, int $width, int $height, int $maxEdge, int $maxBytes): ?array
    {
        if (!extension_loaded('gd') || $width * $height > self::MAX_IMAGE_PIXELS) {
            return null;
        }

        $data = @file_get_contents($file);
        if ($data === false || $data === '') {
            return null;
        }

        $source = @imagecreatefromstring($data);
        if ($source === false) {
            return null;
        }

        $ratio = min(1, $maxEdge / max($width, $height));
        $newWidth = max(1, (int)round($width * $ratio));
        $newHeight = max(1, (int)round($height * $ratio));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        if ($target === false) {
            return null;
        }

        // 统一填充白色背景，避免 PNG/WebP 透明区域在 JPEG 中变黑。
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // GdImage 对象由引用计数回收，PHP 8.5 起无需也不应调用 imagedestroy()。
        unset($source);

        $result = null;
        foreach ([82, 72, 62] as $quality) {
            ob_start();
            imagejpeg($target, null, $quality);
            $jpeg = ob_get_clean();

            if (is_string($jpeg) && $jpeg !== '' && strlen($jpeg) <= $maxBytes) {
                $result = self::dataPart($jpeg, 'image/jpeg');
                break;
            }
        }

        return $result;
    }

    /**
     * 构造 data URL 片段并记录其体积。
     *
     * @return array{url:string, bytes:int}
     */
    private static function dataPart(string $data, string $mime): array
    {
        $url = 'data:' . $mime . ';base64,' . base64_encode($data);

        return ['url' => $url, 'bytes' => strlen($url)];
    }

    /**
     * 把图片 URL 的路径解析为本站真实文件，并限制在站点根目录内。
     */
    private static function localFile(string $path): ?string
    {
        $path = rawurldecode(str_replace('\\', '/', $path));
        if ($path === '') {
            return null;
        }

        $options = Options::alloc();
        $basePath = rtrim((string)parse_url((string)$options->siteUrl, PHP_URL_PATH), '/');

        // 站点安装在子目录时，去掉图片路径中的子目录前缀。
        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            $path = substr($path, strlen($basePath));
        }

        $root = realpath(__TYPECHO_ROOT_DIR__);
        $real = realpath(rtrim(__TYPECHO_ROOT_DIR__, '/') . '/' . ltrim($path, '/'));

        if ($root === false || $real === false || !is_file($real)) {
            return null;
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (!str_starts_with(str_replace('\\', '/', $real), $rootPrefix)) {
            return null;
        }

        return $real;
    }

    /**
     * 判断主机名是否指向本站（含本机回环地址）。
     */
    private static function isLocalHost(string $host): bool
    {
        return in_array($host, self::siteHosts(), true)
            || in_array($host, self::LOOPBACK_HOSTS, true);
    }

    /**
     * 获取本站可能使用的主机名。
     *
     * @return array<int, string>
     */
    private static function siteHosts(): array
    {
        $options = Options::alloc();
        $hosts = [];

        foreach ([(string)$options->siteUrl, (string)$options->rootUrl] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * 兼容“根地址”和“完整 /chat/completions 地址”两种写法。
     */
    private static function endpoint(string $apiUrl): string
    {
        $apiUrl = rtrim(trim($apiUrl), '/');

        if (!preg_match('#/chat/completions$#i', $apiUrl)) {
            $apiUrl .= '/chat/completions';
        }

        return $apiUrl;
    }
}
