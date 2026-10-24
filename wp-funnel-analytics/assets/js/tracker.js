/**
 * WP Funnel Analytics - Ultra-Lightweight Zero-Lag Client Tracker
 * Pure Vanilla JS (< 3.5KB), Zero Dependency, Non-blocking Async Beacon
 */
(function() {
    'use strict';

    if (typeof window === 'undefined' || window.__wpfa_loaded) return;
    window.__wpfa_loaded = true;

    var config = window.wpfa_config || {};
    if (!config.endpoint) return;

    // 遵守浏览器 DNT (Do Not Track)
    if (config.respectDnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1')) {
        return;
    }

    var startTime = Date.now();
    var pageviewSent = false;
    var engagedSent = false;
    var scroll50Sent = false;

    // 辅助工具：生成高强度随机唯一 ID
    function uid() {
        return 'fa_' + Date.now().toString(36) + Math.random().toString(36).substring(2, 9);
    }

    // 访客 ID (持久化 1 年)
    var visitorKey = 'wpfa_vid';
    var visitorId = '';
    try {
        visitorId = localStorage.getItem(visitorKey);
        if (!visitorId) {
            visitorId = uid();
            localStorage.setItem(visitorKey, visitorId);
        }
    } catch (e) {
        visitorId = uid();
    }

    // 会话 ID (会话级 30 分钟无活动重置)
    var sessionKey = 'wpfa_sid';
    var sessionTimeKey = 'wpfa_stime';
    var sessionId = '';
    var now = Date.now();
    try {
        var lastTime = parseInt(sessionStorage.getItem(sessionTimeKey) || '0', 10);
        sessionId = sessionStorage.getItem(sessionKey);
        if (!sessionId || (now - lastTime > 30 * 60 * 1000)) {
            sessionId = uid();
            sessionStorage.setItem(sessionKey, sessionId);
        }
        sessionStorage.setItem(sessionTimeKey, now.toString());
    } catch (e) {
        sessionId = uid();
    }

    // 解析 URL 参数
    function getQueryParam(name) {
        var match = RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
        return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
    }

    // 采集客户端环境参数
    var utmParams = {
        source: getQueryParam('utm_source'),
        medium: getQueryParam('utm_medium'),
        campaign: getQueryParam('utm_campaign'),
        content: getQueryParam('utm_content'),
        term: getQueryParam('utm_term')
    };

    var screenInfo = (window.screen ? (window.screen.width + 'x' + window.screen.height) : '');

    // 极速无阻发送机制 (sendBeacon 优先，fetch keepalive 兜底)
    function sendPayload(data) {
        data.visitor_id = visitorId;
        data.session_id = sessionId;
        data.timestamp  = Math.floor(Date.now() / 1000);

        var json = JSON.stringify(data);

        if (navigator.sendBeacon) {
            var blob = new Blob([json], { type: 'application/json' });
            var success = navigator.sendBeacon(config.endpoint, blob);
            if (success) return;
        }

        if (window.fetch) {
            fetch(config.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: json,
                keepalive: true,
                credentials: 'omit',
                mode: 'cors'
            }).catch(function() {});
        } else {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', config.endpoint, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(json);
        }
    }

    // 上报事件公共方法
    function trackEvent(eventType, category, label, value, customData) {
        var payload = {
            action: 'event',
            event_type: eventType,
            event_category: category || 'general',
            event_label: label || '',
            event_value: value || 0,
            page_url: window.location.href,
            page_title: document.title,
            custom_data: customData ? JSON.stringify(customData) : ''
        };
        sendPayload(payload);
    }

    // 曝光与页面浏览
    function sendPageView() {
        if (pageviewSent) return;
        pageviewSent = true;

        var payload = {
            action: 'pageview',
            page_url: window.location.href,
            page_title: document.title,
            referrer: document.referrer || '',
            utm: utmParams,
            screen: screenInfo
        };
        sendPayload(payload);

        // WooCommerce 页面预置事件
        if (config.wc) {
            if (config.wc.isProduct && config.wc.product) {
                trackEvent('view_product', 'ecommerce', config.wc.product.name, config.wc.product.price, {
                    product_id: config.wc.product.id
                });
            } else if (config.wc.isCheckout) {
                trackEvent('begin_checkout', 'ecommerce', 'Checkout Page', 0);
            } else if (config.wc.isThankYou && config.wc.order) {
                trackEvent('purchase', 'ecommerce', 'Order #' + config.wc.order.id, config.wc.order.total, {
                    order_id: config.wc.order.id,
                    currency: config.wc.order.currency
                });
            }
        }
    }

    // 深度停留 (30 秒)
    var engageTimer = setTimeout(function() {
        if (!engagedSent) {
            engagedSent = true;
            trackEvent('engaged_visit', 'engagement', 'Stayed > 30s', 30);
        }
    }, 30000);

    // 滚动深度监听 (50%)
    function checkScroll() {
        if (scroll50Sent) return;
        var doc = document.documentElement;
        var top = (window.pageYOffset || doc.scrollTop) - (doc.clientTop || 0);
        var total = doc.scrollHeight - doc.clientHeight;
        if (total > 0 && (top / total) >= 0.5) {
            scroll50Sent = true;
            trackEvent('scroll_50', 'engagement', 'Scrolled 50%', 50);
            window.removeEventListener('scroll', checkScroll);
        }
    }
    window.addEventListener('scroll', checkScroll, { passive: true });

    // 离开页面时更新停留时长 (无感 Beacon)
    function sendExitDuration() {
        var duration = Math.round((Date.now() - startTime) / 1000);
        if (duration < 1) return;

        var payload = {
            action: 'duration',
            duration: duration,
            exit_page: window.location.href
        };
        sendPayload(payload);
    }

    var hiddenProp = 'hidden';
    var visibilityChange = 'visibilitychange';
    if (typeof document.msHidden !== 'undefined') {
        hiddenProp = 'msHidden';
        visibilityChange = 'msvisibilitychange';
    } else if (typeof document.webkitHidden !== 'undefined') {
        hiddenProp = 'webkitHidden';
        visibilityChange = 'webkitvisibilitychange';
    }

    var exitSent = false;
    function onExit() {
        if (!exitSent) {
            exitSent = true;
            clearTimeout(engageTimer);
            sendExitDuration();
        }
    }

    window.addEventListener('pagehide', onExit);
    window.addEventListener('beforeunload', onExit);
    document.addEventListener(visibilityChange, function() {
        if (document[hiddenProp]) {
            sendExitDuration();
        }
    });

    // 页面交互事件委托 (点击、表单、外链、加购)
    document.addEventListener('click', function(e) {
        var target = e.target;
        var link = target.closest ? target.closest('a') : null;
        var btn  = target.closest ? target.closest('button, input[type="submit"], .add_to_cart_button') : null;

        // 1. 联系方式与外链点击
        if (link && link.href) {
            var href = link.href.toLowerCase();
            if (href.indexOf('mailto:') === 0) {
                trackEvent('contact_click', 'lead', 'Email Click', 0, { href: link.href });
            } else if (href.indexOf('tel:') === 0) {
                trackEvent('contact_click', 'lead', 'Phone Click', 0, { href: link.href });
            } else if (href.indexOf('whatsapp.com') !== -1 || href.indexOf('wa.me') !== -1) {
                trackEvent('contact_click', 'lead', 'WhatsApp Click', 0, { href: link.href });
            } else if (href.indexOf('t.me/') !== -1) {
                trackEvent('contact_click', 'lead', 'Telegram Click', 0, { href: link.href });
            }
        }

        // 2. 加购按钮点击 (WooCommerce)
        if (btn) {
            var isAddToCart = btn.classList.contains('add_to_cart_button') || 
                              btn.classList.contains('single_add_to_cart_button') ||
                              btn.name === 'add-to-cart' ||
                              (btn.getAttribute('data-product_id') !== null);
            if (isAddToCart) {
                var pid = btn.getAttribute('data-product_id') || (document.querySelector('[name="add-to-cart"]') ? document.querySelector('[name="add-to-cart"]').value : '');
                var label = btn.innerText || 'Add to Cart';
                trackEvent('add_to_cart', 'ecommerce', label.trim(), 0, { product_id: pid });
            }
        }
    }, { passive: true });

    // 3. 表单提交监听 (询盘/留言/Lead 捕获)
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form) return;
        var action = form.getAttribute('action') || '';
        // 排除站内搜索表单与后台登录表单
        if (form.getAttribute('role') === 'search' || action.indexOf('wp-login.php') !== -1) {
            return;
        }
        var formId = form.id || form.className || 'Form';
        trackEvent('form_submit', 'lead', 'Form Submitted: ' + formId.substring(0, 50), 0);
    }, { passive: true });

    // 暴露全局手动打点 API (给网站开发者自定义调用)
    window.wpfa = {
        track: function(eventType, category, label, value, customData) {
            trackEvent(eventType, category, label, value, customData);
        },
        visitorId: visitorId,
        sessionId: sessionId
    };

    // 立即启动首次页面曝光上报
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sendPageView);
    } else {
        sendPageView();
    }
})();
