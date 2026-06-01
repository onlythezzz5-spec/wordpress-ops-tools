/**
 * PIX Enhanced Payment Gateway - Frontend Script
 * 转化率优先的支付体验
 * Version: 5.0.0
 */

(function($) {
    'use strict';

    // ===================================================================
    // 1. 配置中心
    // ===================================================================
    const PixConfig = {
        // DOM 选择器
        selectors: {
            proofForm: '#pix-proof-form',
            fileInput: '#pix-proof-file',
            submitBtn: '#submit-proof-btn',
            feedback: '#pix-feedback',
            fileDisplay: '#file-name-display',
            countdownTimer: '#countdown-timer'
        },

        // 文件限制
        file: {
            maxSize: 5 * 1024 * 1024, // 5MB
            allowedTypes: ['image/jpeg', 'image/png', 'application/pdf'],
            typeLabels: {
                'image/jpeg': 'JPEG',
                'image/png': 'PNG',
                'application/pdf': 'PDF'
            }
        },

        // 文案（葡萄牙语优先，转化率导向）
        text: {
            selectFile: 'Por favor, selecione um arquivo',
            fileTooBig: 'Arquivo muito grande! Máximo 5MB',
            invalidType: 'Formato inválido. Use JPG, PNG ou PDF',
            uploading: 'Enviando...',
            success: '✓ Comprovante recebido! Atualizando...',
            error: 'Erro ao enviar. Tente novamente.',
            networkError: 'Erro de conexão. Verifique sua internet.',
            confirmation: 'Confirmar Envio',
            retry: 'Tentar Novamente',
            uploading_short: 'Enviando...',
            confirm_text: '✓ Confirmar Envio'
        },

        // 转化率优化：显示技巧
        ux: {
            showSuccessBadge: true,
            autoReloadDelay: 1500,
            animationDuration: 300,
            retryCountdown: 3
        }
    };

    // ===================================================================
    // 2. 工具函数
    // ===================================================================

    class PixUtils {
        /**
         * 显示反馈信息（带动画）
         */
        static showFeedback(msg, type = 'info') {
            const $feedback = $(PixConfig.selectors.feedback);

            // 移除旧的样式
            $feedback.removeClass('pix-success pix-error pix-info');

            // 添加新样式
            const className = type === 'success' ? 'pix-success' : 
                            type === 'error' ? 'pix-error' : 'pix-info';
            
            $feedback
                .addClass(className)
                .html(msg)
                .fadeIn(PixConfig.ux.animationDuration);
        }

        /**
         * 隐藏反馈
         */
        static hideFeedback() {
            $(PixConfig.selectors.feedback).fadeOut(PixConfig.ux.animationDuration);
        }

        /**
         * 验证文件
         */
        static validateFile(file) {
            if (!file) {
                return {
                    valid: false,
                    message: PixConfig.text.selectFile
                };
            }

            if (file.size > PixConfig.file.maxSize) {
                return {
                    valid: false,
                    message: PixConfig.text.fileTooBig
                };
            }

            if (!PixConfig.file.allowedTypes.includes(file.type)) {
                return {
                    valid: false,
                    message: PixConfig.text.invalidType
                };
            }

            return {
                valid: true
            };
        }

        /**
         * 格式化文件大小
         */
        static formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        /**
         * 显示加载状态
         */
        static setLoading(btn, isLoading, text = '') {
            if (isLoading) {
                btn.prop('disabled', true)
                   .data('original-text', btn.text())
                   .text(text || PixConfig.text.uploading_short);
            } else {
                btn.prop('disabled', false)
                   .text(btn.data('original-text') || PixConfig.text.confirm_text);
            }
        }

        /**
         * 更新倒计时
         */
        static startCountdown(minutes) {
            if (minutes <= 0) return;

            let remainingSeconds = minutes * 60;
            const $timer = $(PixConfig.selectors.countdownTimer);

            if (!$timer.length) return;

            // 格式化显示：最后5分钟显示 MM:SS，否则显示 X min
            const formatTime = (seconds) => {
                if (seconds <= 300) { // 最后5分钟
                    const m = Math.floor(seconds / 60);
                    const s = seconds % 60;
                    return m + ':' + (s < 10 ? '0' : '') + s;
                }
                return Math.ceil(seconds / 60) + ' min';
            };

            $timer.text(formatTime(remainingSeconds));

            const intervalId = setInterval(() => {
                remainingSeconds--;
                $timer.text(formatTime(remainingSeconds));

                if (remainingSeconds <= 0) {
                    clearInterval(intervalId);
                    location.reload();
                }

                // 最后5分钟变红
                if (remainingSeconds <= 300) {
                    $timer.css('color', '#dc2626');
                }
            }, 1000); // 每秒更新
        }

        /**
         * 复制到剪贴板
         */
        static copyToClipboard(text, callback) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(() => {
                    if (callback) callback(true);
                }).catch(() => {
                    // Fallback
                    this.copyFallback(text, callback);
                });
            } else {
                this.copyFallback(text, callback);
            }
        }

        static copyFallback(text, callback) {
            const $temp = $('<textarea></textarea>')
                .val(text)
                .appendTo('body');
            $temp[0].select();
            try {
                document.execCommand('copy');
                if (callback) callback(true);
            } catch (e) {
                if (callback) callback(false);
            }
            $temp.remove();
        }
    }

    // ===================================================================
    // 3. 主要功能类
    // ===================================================================

    class PixPaymentHandler {
        constructor() {
            this.init();
        }

        init() {
            this.bindEvents();
            this.initCountdown();
            this.initKeyDisplay();
        }

        /**
         * 初始化支付密钥显示功能
         */
        initKeyDisplay() {
            const $keyDisplay = $('.pix-key-display');

            $keyDisplay.on('click', function() {
                const text = $(this).text().trim();
                PixUtils.copyToClipboard(text, (success) => {
                    if (success) {
                        const originalText = $(this).html();
                        $(this).html('✓ Chave copiada!').css({
                            'background': '#dcfce7',
                            'border-color': '#86efac'
                        });

                        setTimeout(() => {
                            $(this).html(originalText).css({
                                'background': '#f0f0f0',
                                'border-color': '#ddd'
                            });
                        }, 2000);
                    }
                });
            });

            // 添加提示
            $keyDisplay.attr('title', 'Clique para copiar');
        }

        /**
         * 初始化倒计时
         */
        initCountdown() {
            const $timer = $(PixConfig.selectors.countdownTimer);
            if ($timer.length) {
                const minutes = parseInt($timer.text());
                PixUtils.startCountdown(minutes);
            }
        }

        /**
         * 绑定所有事件
         */
        bindEvents() {
            // 文件选择变化
            $(document).on('change', PixConfig.selectors.fileInput, (e) => {
                this.onFileSelected(e);
            });

            // 表单提交
            $(PixConfig.selectors.proofForm).on('submit', (e) => {
                e.preventDefault();
                this.submitProof();
            });
        }

        /**
         * 文件选择事件处理
         */
        onFileSelected(e) {
            const file = e.target.files[0];
            const $display = $(PixConfig.selectors.fileDisplay);
            const $btn = $(PixConfig.selectors.submitBtn);

            if (!file) return;

            // 验证文件
            const validation = PixUtils.validateFile(file);

            if (!validation.valid) {
                PixUtils.showFeedback(validation.message, 'error');
                $(PixConfig.selectors.fileInput).val('');
                $display.empty();
                $btn.slideUp();
                return;
            }

            // 显示文件信息
            const fileSize = PixUtils.formatFileSize(file.size);
            const fileType = PixConfig.file.typeLabels[file.type] || 'Arquivo';

            $display.html(`
                <span style="color: #16a34a; font-weight: 600;">
                    ✓ ${file.name} (${fileType}, ${fileSize})
                </span>
            `);

            // 图片预览（减少上传错误率）
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    const $preview = $('<div id="pix-file-preview" style="margin-top:10px;text-align:center;"></div>');
                    $preview.html('<img src="' + ev.target.result + '" style="max-width:200px;max-height:150px;border-radius:5px;border:1px solid #ddd;box-shadow:0 2px 4px rgba(0,0,0,0.1);" alt="Preview">');
                    $('#pix-file-preview').remove();
                    $display.after($preview);
                };
                reader.readAsDataURL(file);
            } else {
                $('#pix-file-preview').remove();
            }

            // 显示提交按钮
            $btn.slideDown(PixConfig.ux.animationDuration);

            PixUtils.hideFeedback();
        }

        /**
         * 提交凭证
         */
        submitProof() {
            const $form = $(PixConfig.selectors.proofForm);
            const $btn = $(PixConfig.selectors.submitBtn);
            const $fileInput = $(PixConfig.selectors.fileInput);
            const file = $fileInput[0].files[0];

            if (!file) {
                PixUtils.showFeedback(PixConfig.text.selectFile, 'error');
                return;
            }

            // 二次验证
            const validation = PixUtils.validateFile(file);
            if (!validation.valid) {
                PixUtils.showFeedback(validation.message, 'error');
                return;
            }

            // 上传文件
            PixUtils.setLoading($btn, true);

            const formData = new FormData($form[0]);
            formData.append('action', 'pix_enhanced_upload_proof');
            // 附加 order_key 用于安全验证
            if (pixEnhanced.order_key) {
                formData.append('order_key', pixEnhanced.order_key);
            }

            $.ajax({
                url: pixEnhanced.ajax,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json'
            })
            .done((response) => {
                if (response.success) {
                    PixUtils.showFeedback(PixConfig.text.success, 'success');
                    $btn.text('✓ Enviado!');
                    
                    // 自动刷新
                    setTimeout(() => {
                        location.reload();
                    }, PixConfig.ux.autoReloadDelay);
                } else {
                    const errorMsg = (response.data && response.data.message) ? response.data.message : (response.data || PixConfig.text.error);
                    PixUtils.showFeedback(errorMsg, 'error');
                    PixUtils.setLoading($btn, false);
                }
            })
            .fail(() => {
                PixUtils.showFeedback(PixConfig.text.networkError, 'error');
                PixUtils.setLoading($btn, false);
            });
        }
    }

    // ===================================================================
    // 4. 初始化
    // ===================================================================

    $(document).ready(function() {
        // 检查是否在订单确认页面
        if (typeof pixEnhanced !== 'undefined') {
            new PixPaymentHandler();
        }
    });

})(jQuery);
