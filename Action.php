<?php

namespace TypechoPlugin\AISummary;

use Typecho\Widget\Exception;
use Widget\ActionInterface;
use Widget\Base;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 摘要生成接口。
 *
 * 编辑页通过 AJAX 调用 /action/aisummary，只生成文本并返回给前端，
 * 不会直接写入数据库；摘要随文章表单一起保存。
 */
final class Action extends Base implements ActionInterface
{
    /** 单次请求接受的正文上限，防止异常大的请求占用内存。 */
    private const MAX_TEXT_BYTES = 4194304;

    /**
     * 处理生成请求。
     */
    public function action(): void
    {
        $status = 200;
        $response = ['success' => true];

        try {
            if (!$this->request->isPost()) {
                throw new Exception(_t('请求方式错误'), 405);
            }

            if (!$this->user->hasLogin()) {
                throw new Exception(_t('请先登录'), 403);
            }

            $this->user->pass('contributor');

            // Typecho 的 Security::protect() 在失败时会重定向，这里手动校验以返回 JSON。
            // 令牌使用固定的动作名作为后缀，避免依赖浏览器是否发送 Referer。
            $token = $this->request->get('_');
            if (
                !is_string($token)
                || !hash_equals($this->security->getToken(Plugin::ACTION), $token)
            ) {
                throw new Exception(_t('安全令牌无效，请刷新页面后重试'), 403);
            }

            if ((string)$this->request->get('do') !== 'generate') {
                throw new Exception(_t('未知操作'), 400);
            }

            $text = $this->request->get('text', '');
            $title = $this->request->get('title', '');

            if (!is_string($text) || !is_string($title)) {
                throw new Exception(_t('参数格式错误'), 400);
            }

            if (strlen($text) > self::MAX_TEXT_BYTES) {
                throw new Exception(_t('文章内容过大，无法生成摘要'), 413);
            }

            $response['message'] = _t('摘要生成成功');
            $response += Summary::generate($title, $text);
        } catch (\Throwable $exception) {
            $status = (int)$exception->getCode();
            $response = [
                'success' => false,
                'message' => $exception->getMessage() ?: _t('生成摘要失败'),
            ];
        }

        if (!$response['success'] && ($status < 400 || $status >= 600)) {
            $status = 500;
        }

        $this->response
            ->setStatus($status)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->throwJson($response);
    }
}
