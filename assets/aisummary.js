(function ($) {
    'use strict';

    var settings = window.AISummarySettings || {};
    var strings = settings.strings || {};
    var initialized = false;

    function format(template, value) {
        return String(template || '').replace('%s', value);
    }

    function trim(value) {
        return String(value == null ? '' : value).replace(/^\s+|\s+$/g, '');
    }

    function init() {
        if (initialized) {
            return;
        }

        var $input = $('textarea[name="fields[ai_summary]"]').first();
        if (!$input.length || $input.closest('.ai-summary-field').length) {
            return;
        }

        initialized = true;

        var $row = $input.closest('li.field');
        var $name = $row.find('.field-name').first();
        var $scope = $row.length ? $row : $input.parent();
        var $count = $('<span class="ai-summary-count"></span>');
        var $toolbar = $('<div class="ai-summary-toolbar"></div>');
        var $generate = $('<button type="button" class="btn btn-s btn-primary ai-summary-generate"></button>')
            .text(strings.generate || '生成摘要');
        var $clear = $('<button type="button" class="btn btn-s ai-summary-clear"></button>')
            .text(strings.clear || '清空');
        var $status = $('<span class="ai-summary-status" role="status" aria-live="polite"></span>');
        var $hint = $('<p class="description ai-summary-hint"></p>').text(strings.hint || '');

        $row.addClass('ai-summary-field');
        $toolbar.append($generate, $clear, $status);

        if ($name.length) {
            $name.append($count);
        } else {
            $toolbar.prepend($count);
        }

        // 保留字段原来的位置，只在输入框下方追加工具条和提示。
        $input.after($toolbar);
        $toolbar.after($hint);

        if (typeof settings.value === 'string') {
            $input.val(settings.value);
        }

        function updateCount() {
            var length = String($input.val() || '').length;
            $count.text(format(strings.count || '%s 字', length));
        }

        function setStatus(message, type) {
            $status
                .removeClass('is-error is-success is-busy')
                .addClass(type ? 'is-' + type : '')
                .text(message || '');
        }

        function setBusy(busy) {
            $scope.toggleClass('ai-summary-busy', Boolean(busy));
            $generate
                .prop('disabled', Boolean(busy))
                .text(busy ? (strings.generating || '正在生成…') : (strings.generate || '生成摘要'));
        }

        function markChanged() {
            var $form = $input.closest('form');
            $input.trigger('input').trigger('change');

            if ($form.length) {
                // 复用 Typecho 的写页逻辑：标记已修改并触发自动保存计时。
                $form.trigger('write');
            }
        }

        $input.on('input change', updateCount);
        updateCount();

        $clear.on('click', function () {
            if (trim($input.val()) !== '' && !window.confirm(strings.confirmClear || '确定要清空摘要吗？')) {
                return;
            }

            $input.val('');
            markChanged();
            setStatus(strings.cleared || '摘要已清空', 'success');
        });

        $generate.on('click', function () {
            var text = $('#text').val() || '';
            var current = trim($input.val());

            if (!trim(text)) {
                setStatus(strings.emptyContent || '文章内容为空，无法生成摘要。', 'error');
                return;
            }

            if (current !== '' && !window.confirm(strings.confirmOverwrite || '摘要已有内容，确定要重新生成并覆盖吗？')) {
                return;
            }

            setBusy(true);
            setStatus(strings.generating || '正在生成…', 'busy');

            $.ajax({
                url: settings.actionUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 300000,
                data: {
                    do: 'generate',
                    title: $('#title').val() || '',
                    text: text
                }
            }).done(function (response) {
                if (!response || !response.success) {
                    setStatus(
                        (response && response.message) || strings.failed || '生成失败，请稍后重试。',
                        'error'
                    );
                    return;
                }

                var summary = typeof response.summary === 'string' ? response.summary : '';
                $input.val(summary);
                markChanged();

                var message = response.message || strings.success || '摘要生成成功';
                if (response.imageCount > 0) {
                    message += ' ' + format(strings.imagesSent || '已发送 %s 张图片', response.imageCount);
                }
                if (response.skippedImageCount > 0) {
                    message += ' ' + format(strings.imagesSkipped || '跳过 %s 张图片', response.skippedImageCount);
                }
                if (response.truncated) {
                    message += ' ' + (strings.truncated || '正文已截断');
                }

                setStatus(message, 'success');
            }).fail(function (xhr) {
                var payload = xhr && xhr.responseJSON ? xhr.responseJSON : {};

                setStatus(
                    payload.message || strings.networkError || '网络错误，请稍后重试。',
                    'error'
                );
            }).always(function () {
                setBusy(false);
                updateCount();
            });
        });
    }

    $(document).ready(init);
})(jQuery);
