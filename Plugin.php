<?php

namespace TypechoPlugin\AISummary;

use Typecho\Common;
use Typecho\Db;
use Typecho\Http\Client;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Plugin\PluginInterface;
use Typecho\Request;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Url;
use Typecho\Widget\Helper\Layout;
use Utils\Helper;
use Widget\Base\Contents;
use Widget\Options;
use Widget\Security;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 为文章提供 AI 摘要自定义字段，一键把当前文章的标题、正文与图片发送给 OpenAI 格式接口生成摘要。
 *
 * @package AISummary
 * @author 罗伊
 * @version 1.2.0
 * @link https://blog.roysgensokyo.space/
 */
final class Plugin implements PluginInterface
{
    public const NAME = 'AISummary';
    public const FIELD = 'ai_summary';
    public const ACTION = 'aisummary';
    public const VERSION = '1.0.0';

    public const DEFAULT_API_URL = 'https://api.deepseek.com/';
    public const DEFAULT_MODEL = 'deepseek-flash';

    public const DEFAULT_PROMPT = <<<'PROMPT'
你是一名专业的文章摘要编辑。请根据用户提供的文章标题、正文和图片，生成一段简体中文摘要，并严格遵守以下要求：
1. 只输出摘要正文，不要添加标题、标签、引号或解释。
2. 准确概括文章的核心主题、关键观点与结论；如果文章包含图片，请结合图片中的信息。
3. 使用第三人称客观叙述，长度控制在 90 字以内。
PROMPT;

    /**
     * 注册编辑页字段、后台脚本与生成接口。
     *
     * @throws PluginException
     */
    public static function activate(): void
    {
        if (Client::get() === null) {
            throw new PluginException(_t('AISummary 需要启用 PHP cURL 扩展'));
        }

        $plugins = \Typecho\Plugin::export();
        if (isset($plugins['activated']['AIContentSummaryPlus'])) {
            throw new PluginException(
                _t('AISummary 与 AIContentSummaryPlus 都使用 ai_summary 字段，请先停用 AIContentSummaryPlus')
            );
        }

        // 摘要作为文章默认自定义字段，保存逻辑复用 Typecho 自身的自定义字段机制。
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->getDefaultFieldItems =
            self::class . '::addField';
        \Typecho\Plugin::factory('admin/write-js.php')->write = self::class . '::renderAssets';

        // 前台在正文顶部输出摘要。
        \Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = self::class . '::prependSummary';
        \Typecho\Plugin::factory('Widget\Base\Contents')->excerptEx = self::class . '::summaryExcerpt';
        \Typecho\Plugin::factory('Widget\Archive')->header = self::class . '::renderFrontAssets';

        Helper::addAction(self::ACTION, Action::class);
    }

    /**
     * 移除生成接口。
     */
    public static function deactivate(): void
    {
        Helper::removeAction(self::ACTION);
    }

    /**
     * 插件设置。
     */
    public static function config(Form $form): void
    {
        $form->addInput(
            (new Url(
                'apiUrl',
                null,
                self::DEFAULT_API_URL,
                _t('API 地址'),
                _t('OpenAI 格式接口的根地址，例如 https://api.example.com/v1；也可以直接填写完整的 /chat/completions 地址。')
            ))->addRule('url', _t('请填写合法的 API 地址'))
        );

        $form->addInput(
            new Password(
                'apiKey',
                null,
                '',
                _t('API Key'),
                _t('以 Bearer 方式发送。留空保存时会保留当前已保存的密钥。')
            )
        );

        $form->addInput(
            (new Text(
                'modelName',
                null,
                self::DEFAULT_MODEL,
                _t('模型名称'),
                _t('支持图片输入的模型，例如 gpt-4o-mini、qwen-vl-plus、glm-4v 等。')
            ))->addRule('required', _t('模型名称不能为空'))
        );

        $form->addInput(
            (new Textarea(
                'prompt',
                null,
                self::DEFAULT_PROMPT,
                _t('系统提示词'),
                _t('用于约束摘要的语言、风格和长度。')
            ))->addRule('required', _t('系统提示词不能为空'))
        );

        $form->addInput(
            (new Text(
                'temperature',
                null,
                '1.0',
                _t('接口温度 (Temperature)'),
                _t('控制生成文本的随机性，通常在 0.0 到 2.0 之间。留空时不发送该参数，以兼容限制温度值的模型。')
            ))->addRule('isFloat', _t('接口温度必须为数字'))
        );

        $form->addInput(
            (new Text(
                'timeout',
                null,
                '120',
                _t('请求超时（秒）'),
                _t('生成摘要时等待接口返回的最长时间，图片较多时应适当调大。')
            ))->addRule('isInteger', _t('请求超时必须是整数'))
        );

        $form->addInput(
            new Radio(
                'sendImages',
                ['1' => _t('开启'), '0' => _t('关闭')],
                '1',
                _t('发送图片'),
                _t('关闭后只把标题和正文发送给 AI。')
            )
        );

        $form->addInput(
            (new Text(
                'maxImages',
                null,
                '6',
                _t('最多发送图片数'),
                _t('按正文中出现的顺序取前 N 张图片，0 表示不发送图片。')
            ))->addRule('isInteger', _t('最多发送图片数必须为整数'))
        );

        $form->addInput(
            (new Text(
                'maxTextChars',
                null,
                '20000',
                _t('正文最大字符数'),
                _t('超出部分会被截断后再发送，避免请求体过大。填 0 表示不截断。')
            ))->addRule('isInteger', _t('正文最大字符数必须为整数'))
        );

        $form->addInput(
            (new Text(
                'imageMaxEdge',
                null,
                '1024',
                _t('图片最长边（像素）'),
                _t('超过该尺寸的图片会压缩到该边长；需要 PHP GD 扩展。没有 GD 时无法压缩，超出体积限制的图片会被跳过。')
            ))->addRule('isInteger', _t('图片最长边必须为整数'))
        );

        $form->addInput(
            (new Text(
                'imageMaxKB',
                null,
                '4096',
                _t('单张图片最大体积（KB）'),
                _t('超过该体积的图片会尝试压缩；无法压缩到限制内时会跳过。')
            ))->addRule('isInteger', _t('单张图片最大体积必须为整数'))
        );

        $form->addInput(
            new Radio(
                'showInContent',
                ['1' => _t('开启'), '0' => _t('关闭')],
                '1',
                _t('正文前显示摘要'),
                _t('开启后会在文章正文顶部输出 ai_summary 的内容；只对存在摘要的文章生效。')
            )
        );

        $form->addInput(
            new Text(
                'summaryTitle',
                null,
                'AI 摘要',
                _t('摘要标题'),
                _t('显示在摘要内容上方的标题，留空则只输出摘要正文。')
            )
        );
    }

    /**
     * 插件未提供个人配置项。
     */
    public static function personalConfig(Form $form): void
    {
    }

    /**
     * 保存设置：API Key 留空时保留原值。
     *
     * @param array<string, mixed> $settings
     */
    public static function configHandle(array $settings, bool $isInit): void
    {
        if (!$isInit && trim((string)($settings['apiKey'] ?? '')) === '') {
            try {
                $current = Options::alloc()->plugin(self::NAME);
                $settings['apiKey'] = (string)($current->apiKey ?? '');
            } catch (\Throwable) {
                $settings['apiKey'] = '';
            }
        }

        if (isset($settings['apiUrl'])) {
            $settings['apiUrl'] = rtrim(trim((string)$settings['apiUrl']), '/');
        }

        \Widget\Plugins\Edit::configPlugin(self::NAME, $settings);
    }

    /**
     * 在文章编辑页的自定义字段区域注册 ai_summary。
     */
    public static function addField(Layout $layout): void
    {
        $field = new Textarea(
            self::FIELD,
            null,
            null,
            _t('AI 摘要'),
        );
        $field->setInputsAttribute('class', 'text-s w-100 ai-summary-input');
        $field->setInputsAttribute('rows', '5');
        $field->setInputsAttribute('placeholder', _t('手动填写摘要，或点击下方“生成摘要”按钮由 AI 生成。'));

        $layout->addItem($field);
    }

    /**
     * 在文章正文顶部追加摘要。
     */
    public static function prependSummary(string $content, Contents $widget): string
    {
        if (self::pluginSetting('showInContent', '1') === '0') {
            return $content;
        }

        $summary = $widget->fields->{self::FIELD} ?? null;
        if (!is_string($summary)) {
            return $content;
        }

        $summary = trim($summary);
        if ($summary === '') {
            return $content;
        }

        return self::renderSummary($summary) . $content;
    }

    /**
     * 让博客列表、meta description 等 excerpt 输出使用 AI 摘要。
     *
     * 返回转义后的纯文本，避免主题的 strip_tags 把摘要里的尖括号内容截断。
     */
    public static function summaryExcerpt(string $excerpt, Contents $widget): string
    {
        $summary = $widget->fields->{self::FIELD} ?? null;
        if (!is_string($summary)) {
            return $excerpt;
        }

        $summary = trim($summary);
        if ($summary === '') {
            return $excerpt;
        }

        return htmlspecialchars($summary, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 生成正文前摘要的 HTML。
     */
    private static function renderSummary(string $summary): string
    {
        $title = trim(self::pluginSetting('summaryTitle', 'AI 摘要'));
        $label = $title !== '' ? $title : 'AI 摘要';
        $titleHtml = $title !== ''
            ? '<div class="ai-summary-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';

        return '<aside class="ai-summary" role="note" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
            . $titleHtml
            . '<p>' . nl2br(htmlspecialchars($summary, ENT_QUOTES, 'UTF-8')) . '</p>'
            . '</aside>';
    }

    /**
     * 在前台 HTML 页面加载摘要样式。
     *
     * 不依赖当前页面是否单篇、也不查询摘要字段：主题开启 PJAX 时，用户可能从列表页
     * 直接换入文章内容，而 head 不会重新解析。样式需要在初次访问的任意页面就加载。
     *
     * @param mixed $header
     * @param mixed $archive
     */
    public static function renderFrontAssets($header = null, $archive = null): void
    {
        static $rendered = false;

        if ($rendered || self::pluginSetting('showInContent', '1') === '0') {
            return;
        }

        $rendered = true;
        $options = Options::alloc();
        $css = Common::url(self::NAME . '/assets/aisummary-front.css', $options->pluginUrl)
            . '?v=' . self::assetVersion(__DIR__ . '/assets/aisummary-front.css');

        echo '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    /**
     * 读取插件设置，失败时返回默认值。
     */
    private static function pluginSetting(string $name, string $default): string
    {
        try {
            $settings = Options::alloc()->plugin(self::NAME);
            $value = $settings->{$name} ?? null;

            return $value === null ? $default : (string)$value;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * 输出编辑页所需的样式、脚本与接口配置。
     */
    public static function renderAssets(): void
    {
        if (!self::isPostWriteScreen()) {
            return;
        }

        $options = Options::alloc();
        $security = Security::alloc();

        $css = Common::url(self::NAME . '/assets/aisummary.css', $options->pluginUrl);
        $js = Common::url(self::NAME . '/assets/aisummary.js', $options->pluginUrl);
        $css .= '?v=' . self::assetVersion(__DIR__ . '/assets/aisummary.css');
        $js .= '?v=' . self::assetVersion(__DIR__ . '/assets/aisummary.js');

        $settings = [
            'actionUrl' => Common::url(
                $security->getTokenUrl('/action/' . self::ACTION, self::ACTION),
                $options->index
            ),
            'value'     => self::currentSummary(),
            'strings'   => [
                'generate'          => _t('生成摘要'),
                'generating'        => _t('正在生成…'),
                'clear'             => _t('清空'),
                'count'             => _t('%s 字'),
                'success'           => _t('摘要生成成功'),
                'imagesSent'        => _t('已发送 %s 张图片'),
                'imagesSkipped'     => _t('跳过 %s 张图片'),
                'truncated'         => _t('正文已截断'),
                'emptyContent'      => _t('文章内容为空，无法生成摘要。'),
                'confirmOverwrite'  => _t('摘要已有内容，确定要重新生成并覆盖吗？'),
                'confirmClear'      => _t('确定要清空摘要吗？'),
                'cleared'           => _t('摘要已清空'),
                'failed'            => _t('生成失败，请稍后重试。'),
                'networkError'      => _t('网络错误，请稍后重试。'),
                'hint'              => _t('使用当前标题、正文和图片生成摘要；结果仅填入摘要框，保存后生效。'),
            ],
        ];

        $json = json_encode(
            $settings,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        echo '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        echo '<script>window.AISummarySettings=' . $json . ';</script>' . "\n";
        echo '<script src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    }

    /**
     * 读取当前正在编辑的摘要。
     *
     * 默认自定义字段由 Typecho 从正式文章读取，这里补充读取草稿/修订版上的值，
     * 避免重新打开编辑页时显示旧摘要。返回 null 表示不覆盖页面中已有的值。
     */
    private static function currentSummary(): ?string
    {
        try {
            $editor = \Widget\Contents\Post\Edit::alloc();
            if (!$editor->have()) {
                return null;
            }

            $draft = $editor->draft;
            $cid = is_array($draft) && !empty($draft['cid']) ? (int)$draft['cid'] : (int)$editor->cid;
            if ($cid <= 0) {
                return null;
            }

            $db = Db::get();
            $row = $db->fetchRow(
                $db->select('str_value')
                    ->from('table.fields')
                    ->where('cid = ? AND name = ?', $cid, self::FIELD)
                    ->limit(1)
            );

            return trim((string)($row['str_value'] ?? ''));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 当前请求是否处于文章（而非独立页面）编辑页。
     */
    private static function isPostWriteScreen(): bool
    {
        try {
            return str_contains(Request::getInstance()->getRequestUrl(), 'write-post.php');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 生成静态资源版本号。
     */
    private static function assetVersion(string $file): string
    {
        $mtime = @filemtime($file);

        return $mtime === false ? self::VERSION : self::VERSION . '-' . $mtime;
    }
}
