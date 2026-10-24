/**
 * WP Funnel Analytics - Admin Dashboard & Pure Canvas Chart Engine
 * Zero Dependency, Ultra-Smooth Modern SaaS Interface
 */
(function($) {
    'use strict';

    var currentRange = '7d';
    var currentFunnelId = 0;
    var realtimeTimer = null;
    var lastTimelineData = null;
    var activeMetrics = {
        pv: true,
        uv: true,
        sessions: true
    };
    var hoveredIndex = -1;

    $(document).ready(function() {
        if ($('#wpfa-trend-canvas').length > 0) {
            initDashboard();
        }

        if ($('#wpfa-funnel-form').length > 0) {
            initFunnelManager();
        }

        if ($('#wpfa-settings-form').length > 0) {
            initSettings();
        }
    });

    /**
     * 仪表盘初始化
     */
    function initDashboard() {
        populateFunnelSelect();
        initChartInteractions();
        loadDashboardData();

        // 时间范围切换
        $('.wpfa-date-btn').on('click', function() {
            $('.wpfa-date-btn').removeClass('active');
            $(this).addClass('active');
            currentRange = $(this).data('range');

            // 同步更新导出链接
            var exportBtn = $('#wpfa-export-csv-btn');
            var href = exportBtn.attr('href');
            if (href) {
                exportBtn.attr('href', href.replace(/range=[^&]+/, 'range=' + currentRange));
            }

            loadDashboardData();
        });

        // 切换漏斗
        $('#wpfa-funnel-select').on('change', function() {
            currentFunnelId = parseInt($(this).val(), 10);
            loadDashboardData();
        });

        // 窗口 Resize 响应式重绘 (防抖)
        var resizeTimer;
        $(window).on('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (lastTimelineData) {
                    renderTimelineChart(lastTimelineData);
                }
            }, 100);
        });

        // 启动实时活跃访客轻量轮询 (15s 一次)
        startRealtimePoll();
    }

    /**
     * 图例切换与鼠标交互初始化
     */
    function initChartInteractions() {
        // 图例点击切换指标
        $('#wpfa-chart-legend').on('click', '.wpfa-legend-btn', function() {
            var metric = $(this).data('metric');
            activeMetrics[metric] = !activeMetrics[metric];

            if (activeMetrics[metric]) {
                $(this).addClass('active');
            } else {
                $(this).removeClass('active');
            }

            if (lastTimelineData) {
                renderTimelineChart(lastTimelineData);
            }
        });

        // 鼠标移动到画布显示悬浮 Tooltip 和导向线
        var wrapper = $('#wpfa-canvas-wrapper');
        var canvas = document.getElementById('wpfa-trend-canvas');
        var tooltip = $('#wpfa-chart-tooltip');

        if (canvas) {
            wrapper.on('mousemove', function(e) {
                if (!lastTimelineData || !lastTimelineData.labels || lastTimelineData.labels.length === 0) return;

                var rect = canvas.getBoundingClientRect();
                var mouseX = e.clientX - rect.left;
                var mouseY = e.clientY - rect.top;

                var paddingLeft = 50;
                var paddingRight = 30;
                var chartW = rect.width - paddingLeft - paddingRight;
                var count = lastTimelineData.labels.length;
                var stepX = count > 1 ? chartW / (count - 1) : chartW;

                var rawIdx = Math.round((mouseX - paddingLeft) / stepX);
                var idx = Math.max(0, Math.min(count - 1, rawIdx));

                hoveredIndex = idx;
                renderTimelineChart(lastTimelineData);

                // 更新 Tooltip 内容
                var dateLabel = lastTimelineData.labels[idx];
                var pvVal = lastTimelineData.pv[idx] || 0;
                var uvVal = lastTimelineData.uv[idx] || 0;
                var sesVal = lastTimelineData.sessions[idx] || 0;

                var tooltipHtml = '<div class="wpfa-tt-head">' + escapeHtml(dateLabel) + '</div>';
                if (activeMetrics.pv) {
                    tooltipHtml += '<div class="wpfa-tt-row"><span class="wpfa-tt-dot" style="background:#3b82f6;"></span> 浏览量 (PV): <strong>' + formatNumber(pvVal) + '</strong></div>';
                }
                if (activeMetrics.uv) {
                    tooltipHtml += '<div class="wpfa-tt-row"><span class="wpfa-tt-dot" style="background:#10b981;"></span> 独立访客 (UV): <strong>' + formatNumber(uvVal) + '</strong></div>';
                }
                if (activeMetrics.sessions) {
                    tooltipHtml += '<div class="wpfa-tt-row"><span class="wpfa-tt-dot" style="background:#8b5cf6;"></span> 访问会话: <strong>' + formatNumber(sesVal) + '</strong></div>';
                }

                tooltip.html(tooltipHtml);

                // 计算 Tooltip 坐标
                var targetX = paddingLeft + idx * stepX;
                var ttW = tooltip.outerWidth();
                var ttLeft = targetX + 15;
                if (ttLeft + ttW > rect.width) {
                    ttLeft = targetX - ttW - 15;
                }

                tooltip.css({
                    display: 'block',
                    left: ttLeft + 'px',
                    top: Math.max(15, Math.min(rect.height - 120, mouseY - 20)) + 'px'
                });
            });

            wrapper.on('mouseleave', function() {
                hoveredIndex = -1;
                tooltip.hide();
                if (lastTimelineData) {
                    renderTimelineChart(lastTimelineData);
                }
            });
        }
    }

    /**
     * 填充漏斗下拉菜单
     */
    function populateFunnelSelect() {
        var select = $('#wpfa-funnel-select');
        select.empty();

        if (wpfa_admin_data.funnels && wpfa_admin_data.funnels.length > 0) {
            wpfa_admin_data.funnels.forEach(function(f) {
                select.append($('<option>', {
                    value: f.id,
                    text: f.name
                }));
            });
            currentFunnelId = wpfa_admin_data.funnels[0].id;
        } else {
            select.append($('<option>', { value: 0, text: '默认漏斗' }));
        }
    }

    /**
     * 加载全量仪表盘数据
     */
    function loadDashboardData() {
        $('#wpfa-loading-overlay').fadeIn(150);

        $.ajax({
            url: wpfa_admin_data.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'wpfa_fetch_data',
                nonce: wpfa_admin_data.nonce,
                range: currentRange,
                funnel_id: currentFunnelId
            },
            success: function(res) {
                $('#wpfa-loading-overlay').fadeOut(150);
                if (res.success && res.data) {
                    renderOverview(res.data.overview);

                    lastTimelineData = res.data.timeline;
                    renderTimelineChart(res.data.timeline);

                    renderFunnel(res.data.funnel);
                    renderAcquisition(res.data.acquisition);
                    renderBehavior(res.data.behavior);
                    renderAudience(res.data.audience);
                    renderRealtime(res.data.realtime);
                }
            },
            error: function() {
                $('#wpfa-loading-overlay').fadeOut(150);
            }
        });
    }

    /**
     * 渲染 KPI 概览数据
     */
    function renderOverview(data) {
        if (!data) return;

        $('#kpi-pv').text(formatNumber(data.pageviews));
        formatTrendBadge('#kpi-pv-diff', data.pageviews_diff);

        $('#kpi-uv').text(formatNumber(data.visitors));
        formatTrendBadge('#kpi-uv-diff', data.visitors_diff);

        $('#kpi-sessions').text(formatNumber(data.sessions));
        formatTrendBadge('#kpi-sessions-diff', data.sessions_diff);

        $('#kpi-bounce').text(data.bounce_rate + '%');
        $('#kpi-duration').text(data.avg_duration_formatted);

        $('#kpi-conversions').text(formatNumber(data.conversions));
        $('#kpi-conversion-rate').text('(' + data.conversion_rate + '%)');
        formatTrendBadge('#kpi-conversions-diff', data.conversions_diff);

        $('#kpi-revenue').text(data.currency_symbol + formatNumber(data.revenue, 2));
    }

    function formatTrendBadge(selector, val) {
        var el = $(selector);
        var num = parseFloat(val);
        if (num > 0) {
            el.html('<span class="trend-up">&uarr; +' + num + '%</span>');
        } else if (num < 0) {
            el.html('<span class="trend-down">&darr; ' + num + '%</span>');
        } else {
            el.html('<span class="trend-neutral">0.0%</span>');
        }
    }

    /**
     * 极佳整步长计算 (Nice Scale)
     */
    function calculateNiceScale(maxVal, ticksCount) {
        ticksCount = ticksCount || 4;
        if (maxVal <= 0) maxVal = 10;
        var range = maxVal;
        var roughStep = range / ticksCount;
        var stepPower = Math.pow(10, Math.floor(Math.log10(roughStep)));
        var normalizedStep = roughStep / stepPower;

        var niceStep;
        if (normalizedStep < 1.5) niceStep = 1;
        else if (normalizedStep < 3) niceStep = 2;
        else if (normalizedStep < 7) niceStep = 5;
        else niceStep = 10;

        var step = niceStep * stepPower;
        var niceMax = Math.ceil(maxVal / step) * step;
        return {
            max: Math.max(step * ticksCount, niceMax),
            step: step
        };
    }

    /**
     * 原生 Canvas 渲染高质感平滑时序趋势图 (PV / UV / Sessions)
     */
    function renderTimelineChart(timeline) {
        var canvas = document.getElementById('wpfa-trend-canvas');
        if (!canvas) return;

        var wrapper = document.getElementById('wpfa-canvas-wrapper');
        if (!wrapper) return;

        // 彻底解决宽度只有 300px 的问题：读取父容器真实像素宽度
        var width = wrapper.clientWidth || wrapper.getBoundingClientRect().width || 800;
        var height = 300;

        var dpr = window.devicePixelRatio || 1;
        canvas.width = Math.floor(width * dpr);
        canvas.height = Math.floor(height * dpr);

        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';

        var ctx = canvas.getContext('2d');
        ctx.resetTransform && ctx.resetTransform();
        ctx.scale(dpr, dpr);

        ctx.clearRect(0, 0, width, height);

        if (!timeline || !timeline.labels || timeline.labels.length === 0) {
            ctx.fillStyle = '#9ca3af';
            ctx.font = '14px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('该时间段内暂无访问数据', width / 2, height / 2);
            return;
        }

        var labels = timeline.labels;
        var pv = timeline.pv || [];
        var uv = timeline.uv || [];
        var sessions = timeline.sessions || [];

        // 更新顶部图例的总和数值
        var sumPv = pv.reduce(function(a, b) { return a + b; }, 0);
        var sumUv = uv.reduce(function(a, b) { return a + b; }, 0);
        var sumSes = sessions.reduce(function(a, b) { return a + b; }, 0);

        $('#legend-pv-sum').text(formatNumber(sumPv));
        $('#legend-uv-sum').text(formatNumber(sumUv));
        $('#legend-sessions-sum').text(formatNumber(sumSes));

        var padding = { top: 30, right: 30, bottom: 40, left: 50 };
        var chartW = width - padding.left - padding.right;
        var chartH = height - padding.top - padding.bottom;

        // 计算最大值 (仅统计激活的指标)
        var maxActiveVal = 0;
        if (activeMetrics.pv) maxActiveVal = Math.max(maxActiveVal, Math.max.apply(null, pv));
        if (activeMetrics.uv) maxActiveVal = Math.max(maxActiveVal, Math.max.apply(null, uv));
        if (activeMetrics.sessions) maxActiveVal = Math.max(maxActiveVal, Math.max.apply(null, sessions));

        var scale = calculateNiceScale(maxActiveVal, 4);
        var maxVal = scale.max;
        var stepVal = scale.step;

        // 绘制水平背景网格线与 Y 轴刻度
        ctx.lineWidth = 1;
        ctx.font = '11px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

        for (var val = 0; val <= maxVal; val += stepVal) {
            var y = padding.top + chartH - (val / maxVal) * chartH;
            
            // 网格横线
            ctx.beginPath();
            ctx.strokeStyle = (val === 0) ? '#e2e8f0' : '#f1f5f9';
            ctx.moveTo(padding.left, y);
            ctx.lineTo(width - padding.right, y);
            ctx.stroke();

            // Y 轴文字
            ctx.fillStyle = '#94a3b8';
            ctx.textAlign = 'right';
            ctx.fillText(formatNumber(val), padding.left - 12, y + 4);
        }

        // 绘制 X 轴标签
        var count = labels.length;
        var stepX = count > 1 ? chartW / (count - 1) : chartW;
        ctx.textAlign = 'center';
        ctx.fillStyle = '#64748b';

        var skip = 1;
        if (chartW < 500 && count > 10) skip = 3;
        else if (count > 20) skip = Math.ceil(count / 10);

        labels.forEach(function(lbl, idx) {
            if (idx % skip === 0 || idx === count - 1) {
                var x = padding.left + idx * stepX;
                ctx.fillText(lbl, x, height - 12);
            }
        });

        // 绘制竖向交互标尺线
        if (hoveredIndex >= 0 && hoveredIndex < count) {
            var hoverX = padding.left + hoveredIndex * stepX;
            ctx.save();
            ctx.setLineDash([4, 4]);
            ctx.strokeStyle = '#cbd5e1';
            ctx.lineWidth = 1.5;
            ctx.beginPath();
            ctx.moveTo(hoverX, padding.top);
            ctx.lineTo(hoverX, padding.top + chartH);
            ctx.stroke();
            ctx.restore();
        }

        // 贝塞尔曲线平滑绘制函数
        function drawCurve(dataArr, strokeColor, fillColor, isHighlighted) {
            if (!dataArr || dataArr.length === 0) return;
            var points = [];
            dataArr.forEach(function(v, idx) {
                var x = padding.left + idx * stepX;
                var y = padding.top + chartH - (v / maxVal) * chartH;
                points.push({ x: x, y: y, val: v });
            });

            // 1. 绘制渐变区域填充
            if (fillColor && points.length > 1) {
                var grad = ctx.createLinearGradient(0, padding.top, 0, padding.top + chartH);
                grad.addColorStop(0, fillColor);
                grad.addColorStop(1, 'rgba(255, 255, 255, 0)');

                ctx.beginPath();
                ctx.moveTo(points[0].x, points[0].y);
                for (var j = 0; j < points.length - 1; j++) {
                    var xc = (points[j].x + points[j + 1].x) / 2;
                    var yc = (points[j].y + points[j + 1].y) / 2;
                    ctx.quadraticCurveTo(points[j].x, points[j].y, xc, yc);
                }
                ctx.lineTo(points[points.length - 1].x, points[points.length - 1].y);
                ctx.lineTo(points[points.length - 1].x, padding.top + chartH);
                ctx.lineTo(points[0].x, padding.top + chartH);
                ctx.closePath();
                ctx.fillStyle = grad;
                ctx.fill();
            }

            // 2. 绘制平滑主线条
            ctx.beginPath();
            ctx.strokeStyle = strokeColor;
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';

            ctx.moveTo(points[0].x, points[0].y);
            for (var k = 0; k < points.length - 1; k++) {
                var xMid = (points[k].x + points[k + 1].x) / 2;
                var yMid = (points[k].y + points[k + 1].y) / 2;
                ctx.quadraticCurveTo(points[k].x, points[k].y, xMid, yMid);
            }
            ctx.lineTo(points[points.length - 1].x, points[points.length - 1].y);
            ctx.stroke();

            // 3. 绘制数据节点圆点
            points.forEach(function(pt, pIdx) {
                var isNodeHover = (hoveredIndex === pIdx);
                ctx.beginPath();
                ctx.arc(pt.x, pt.y, isNodeHover ? 5.5 : 3.5, 0, Math.PI * 2);
                ctx.fillStyle = '#ffffff';
                ctx.fill();
                ctx.strokeStyle = strokeColor;
                ctx.lineWidth = isNodeHover ? 3 : 2;
                ctx.stroke();

                if (isNodeHover) {
                    // 外层光晕
                    ctx.beginPath();
                    ctx.arc(pt.x, pt.y, 9, 0, Math.PI * 2);
                    ctx.strokeStyle = strokeColor;
                    ctx.globalAlpha = 0.3;
                    ctx.lineWidth = 4;
                    ctx.stroke();
                    ctx.globalAlpha = 1.0;
                }
            });
        }

        // 按层次绘制开启的折线
        if (activeMetrics.sessions) {
            drawCurve(sessions, '#8b5cf6', 'rgba(139, 92, 246, 0.08)');
        }
        if (activeMetrics.pv) {
            drawCurve(pv, '#3b82f6', 'rgba(59, 130, 246, 0.16)');
        }
        if (activeMetrics.uv) {
            drawCurve(uv, '#10b981', 'rgba(16, 185, 129, 0.12)');
        }
    }

    /**
     * 核心转化漏斗渲染 (SaaS 级流转漏斗)
     */
    function renderFunnel(funnel) {
        var container = $('#wpfa-funnel-container');
        container.empty();

        if (!funnel || !funnel.steps || funnel.steps.length === 0) {
            container.html('<div class="wpfa-empty">暂无漏斗数据</div>');
            return;
        }

        $('#wpfa-funnel-desc').text(funnel.description || '多节点转化留存深度分析');

        var stepsHtml = '<div class="wpfa-funnel-steps-grid">';

        funnel.steps.forEach(function(step, idx) {
            var isLast = idx === funnel.steps.length - 1;
            var widthPercent = Math.max(12, Math.min(100, step.overall_rate || 100));

            stepsHtml += '<div class="wpfa-funnel-col">';
            
            // 步骤卡片
            stepsHtml += '<div class="wpfa-funnel-card">';
            stepsHtml += '  <div class="wpfa-funnel-card-head">';
            stepsHtml += '    <span class="wpfa-step-badge">Step ' + step.step_number + '</span>';
            stepsHtml += '    <span class="wpfa-step-title" title="' + escapeHtml(step.name) + '">' + escapeHtml(step.name) + '</span>';
            stepsHtml += '  </div>';

            stepsHtml += '  <div class="wpfa-funnel-count">' + formatNumber(step.count) + ' <small>会话</small></div>';

            // 步骤进度条
            stepsHtml += '  <div class="wpfa-funnel-bar-track">';
            stepsHtml += '    <div class="wpfa-funnel-bar-fill step-fill-' + ((idx % 5) + 1) + '" style="width:' + widthPercent + '%;"></div>';
            stepsHtml += '  </div>';

            stepsHtml += '  <div class="wpfa-funnel-meta">';
            stepsHtml += '    <div>总留存率: <strong>' + step.overall_rate + '%</strong></div>';
            if (idx > 0) {
                stepsHtml += '    <div>节点转化: <strong style="color:#10b981;">' + step.step_rate + '%</strong></div>';
            } else {
                stepsHtml += '    <div>基准流量: <strong style="color:#3b82f6;">100%</strong></div>';
            }
            stepsHtml += '  </div>';
            stepsHtml += '</div>';

            // 步骤间流失转化指示箭头
            if (!isLast) {
                stepsHtml += '<div class="wpfa-funnel-transition">';
                stepsHtml += '  <div class="wpfa-transition-arrow">&rarr;</div>';
                stepsHtml += '  <div class="wpfa-drop-badge" title="该环节流失率">';
                stepsHtml += '    <span class="dashicons dashicons-arrow-down-alt"></span> 流失 ' + step.drop_rate + '%';
                stepsHtml += '    <small>(-' + formatNumber(step.drop_count) + ')</small>';
                stepsHtml += '  </div>';
                stepsHtml += '</div>';
            }

            stepsHtml += '</div>';
        });

        stepsHtml += '</div>';
        container.html(stepsHtml);
    }

    /**
     * 渲染获客渠道与战役列表
     */
    function renderAcquisition(data) {
        var chanContainer = $('#wpfa-channels-list');
        var campContainer = $('#wpfa-campaigns-list');
        chanContainer.empty();
        campContainer.empty();

        if (data.channels && data.channels.length > 0) {
            data.channels.forEach(function(c) {
                chanContainer.append(
                    '<div class="wpfa-row-item">' +
                    '  <div class="wpfa-row-info">' +
                    '    <span class="wpfa-row-title">' + escapeHtml(c.label) + '</span>' +
                    '    <span class="wpfa-row-sub">跳出率: ' + c.bounce_rate + '% · PV: ' + formatNumber(c.pageviews) + '</span>' +
                    '  </div>' +
                    '  <div class="wpfa-row-stats">' +
                    '    <strong>' + formatNumber(c.sessions) + '</strong> <small>占比 ' + c.share + '%</small>' +
                    '  </div>' +
                    '</div>'
                );
            });
        } else {
            chanContainer.html('<div class="wpfa-empty">暂无获客渠道数据</div>');
        }

        if (data.campaigns && data.campaigns.length > 0) {
            data.campaigns.forEach(function(cmp) {
                var campName = cmp.utm_campaign || cmp.utm_source || 'Unknown';
                campContainer.append(
                    '<div class="wpfa-row-item">' +
                    '  <div class="wpfa-row-info">' +
                    '    <span class="wpfa-row-title"><span class="dashicons dashicons-tag"></span> ' + escapeHtml(campName) + '</span>' +
                    '    <span class="wpfa-row-sub">Source: ' + escapeHtml(cmp.utm_source || '-') + ' | Medium: ' + escapeHtml(cmp.utm_medium || '-') + '</span>' +
                    '  </div>' +
                    '  <div class="wpfa-row-stats">' +
                    '    <strong>' + formatNumber(cmp.sessions) + ' 会话</strong> <small>访客: ' + formatNumber(cmp.visitors) + '</small>' +
                    '  </div>' +
                    '</div>'
                );
            });
        } else {
            campContainer.html('<div class="wpfa-empty">暂无包含 UTM 参数的营销广告活动</div>');
        }
    }

    /**
     * 渲染受访页面分析
     */
    function renderBehavior(data) {
        var container = $('#wpfa-pages-list');
        container.empty();

        if (data.top_pages && data.top_pages.length > 0) {
            data.top_pages.forEach(function(p) {
                container.append(
                    '<div class="wpfa-row-item">' +
                    '  <div class="wpfa-row-info">' +
                    '    <span class="wpfa-row-title"><a href="' + escapeHtml(p.page_url) + '" target="_blank">' + escapeHtml(p.page_title || p.page_url) + '</a></span>' +
                    '    <span class="wpfa-row-sub">' + escapeHtml(p.page_url) + '</span>' +
                    '  </div>' +
                    '  <div class="wpfa-row-stats">' +
                    '    <strong>' + formatNumber(p.pv) + ' PV</strong> <small>' + formatNumber(p.uv) + ' UV</small>' +
                    '  </div>' +
                    '</div>'
                );
            });
        } else {
            container.html('<div class="wpfa-empty">暂无页面受访数据</div>');
        }
    }

    /**
     * 渲染受众与终端画像
     */
    function renderAudience(data) {
        var devContainer = $('#wpfa-device-list');
        var countryContainer = $('#wpfa-country-list');
        devContainer.empty();
        countryContainer.empty();

        // 设备
        var totalDev = 0;
        (data.devices || []).forEach(function(d) { totalDev += parseInt(d.sessions, 10); });

        (data.devices || []).forEach(function(d) {
            var percent = totalDev > 0 ? Math.round((d.sessions / totalDev) * 100) : 0;
            var devIcon = d.device === 'mobile' ? 'smartphone' : (d.device === 'tablet' ? 'tablet' : 'desktop');
            var devName = d.device === 'mobile' ? '手机移动端' : (d.device === 'tablet' ? '平板电脑' : '桌面电脑');

            devContainer.append(
                '<div class="wpfa-prog-row">' +
                '  <div class="wpfa-prog-labels">' +
                '    <span><span class="dashicons dashicons-' + devIcon + '"></span> ' + devName + '</span>' +
                '    <span>' + formatNumber(d.sessions) + ' (' + percent + '%)</span>' +
                '  </div>' +
                '  <div class="wpfa-prog-track"><div class="wpfa-prog-fill" style="width:' + percent + '%;"></div></div>' +
                '</div>'
            );
        });

        // 国家
        var totalCount = 0;
        (data.countries || []).forEach(function(c) { totalCount += parseInt(c.sessions, 10); });

        (data.countries || []).slice(0, 6).forEach(function(c) {
            var percent = totalCount > 0 ? Math.round((c.sessions / totalCount) * 100) : 0;
            countryContainer.append(
                '<div class="wpfa-prog-row">' +
                '  <div class="wpfa-prog-labels">' +
                '    <span>' + c.flag + ' ' + escapeHtml(c.country || c.country_code) + '</span>' +
                '    <span>' + formatNumber(c.sessions) + ' (' + percent + '%)</span>' +
                '  </div>' +
                '  <div class="wpfa-prog-track"><div class="wpfa-prog-fill" style="width:' + percent + '%; background:#10b981;"></div></div>' +
                '</div>'
            );
        });
    }

    /**
     * 渲染实时活跃流
     */
    function renderRealtime(data) {
        if (!data) return;

        $('#wpfa-rt-visitors').text(data.active_visitors || 0);

        var feed = $('#wpfa-realtime-feed');
        feed.empty();

        if (data.recent_activities && data.recent_activities.length > 0) {
            data.recent_activities.forEach(function(act) {
                var eventBadgeClass = 'badge-general';
                if (act.event_type === 'purchase') eventBadgeClass = 'badge-success';
                else if (act.event_type === 'add_to_cart') eventBadgeClass = 'badge-primary';
                else if (act.event_type === 'form_submit') eventBadgeClass = 'badge-warning';

                feed.append(
                    '<div class="wpfa-feed-item">' +
                    '  <div class="wpfa-feed-time">' + act.time_ago + '</div>' +
                    '  <div class="wpfa-feed-badge ' + eventBadgeClass + '">' + escapeHtml(act.event_type) + '</div>' +
                    '  <div class="wpfa-feed-content">' +
                    '    <strong>' + (act.flag || '🌐') + ' ' + escapeHtml(act.event_label || act.page_title || '浏览网页') + '</strong>' +
                    '    <span class="wpfa-feed-url">' + escapeHtml(act.page_url || '') + '</span>' +
                    '  </div>' +
                    '</div>'
                );
            });
        } else {
            feed.html('<div class="wpfa-empty">过去 30 分钟暂无实时动态</div>');
        }
    }

    /**
     * 实时活跃大屏轻量轮询
     */
    function startRealtimePoll() {
        if (realtimeTimer) clearInterval(realtimeTimer);
        realtimeTimer = setInterval(function() {
            $.ajax({
                url: wpfa_admin_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wpfa_fetch_realtime',
                    nonce: wpfa_admin_data.nonce
                },
                success: function(res) {
                    if (res.success && res.data) {
                        renderRealtime(res.data);
                    }
                }
            });
        }, 15000);
    }

    /**
     * 自定义漏斗管理模块
     */
    function initFunnelManager() {
        $('#wpfa-create-funnel-btn').on('click', function() {
            $('#wpfa-modal-title').text('创建新转化漏斗');
            $('#wpfa-form-funnel-id').val(0);
            $('#wpfa-form-funnel-name').val('');
            $('#wpfa-form-funnel-desc').val('');
            $('#wpfa-modal-steps-container').empty();

            addStepRow('进站访问', 'page_view');
            addStepRow('目标动作', 'form_submit');

            $('#wpfa-funnel-modal').fadeIn(200);
        });

        $('.wpfa-edit-funnel-btn').on('click', function() {
            var funnel = $(this).data('funnel');
            $('#wpfa-modal-title').text('编辑漏斗: ' + funnel.name);
            $('#wpfa-form-funnel-id').val(funnel.id);
            $('#wpfa-form-funnel-name').val(funnel.name);
            $('#wpfa-form-funnel-desc').val(funnel.description);
            $('#wpfa-modal-steps-container').empty();

            var steps = JSON.parse(funnel.steps || '[]');
            steps.forEach(function(s) {
                addStepRow(s.name, s.event);
            });

            $('#wpfa-funnel-modal').fadeIn(200);
        });

        $('.wpfa-modal-close, .wpfa-modal-cancel, .wpfa-modal-overlay').on('click', function() {
            $('#wpfa-funnel-modal').fadeOut(200);
        });

        $('#wpfa-add-step-row-btn').on('click', function() {
            addStepRow('', 'page_view');
        });

        $('#wpfa-modal-steps-container').on('click', '.wpfa-remove-step-btn', function() {
            if ($('#wpfa-modal-steps-container .wpfa-step-row').length > 1) {
                $(this).closest('.wpfa-step-row').remove();
            } else {
                alert('漏斗至少需要包含 1 个步骤');
            }
        });

        $('#wpfa-funnel-form').on('submit', function(e) {
            e.preventDefault();
            var id = $('#wpfa-form-funnel-id').val();
            var name = $('#wpfa-form-funnel-name').val();
            var desc = $('#wpfa-form-funnel-desc').val();

            var steps = [];
            $('#wpfa-modal-steps-container .wpfa-step-row').each(function() {
                var sName = $(this).find('.wpfa-step-name-input').val();
                var sEvent = $(this).find('.wpfa-step-event-select').val();
                if (sName) {
                    steps.push({ name: sName, event: sEvent });
                }
            });

            if (steps.length === 0) {
                alert('请至少输入一个有效步骤名称');
                return;
            }

            $.ajax({
                url: wpfa_admin_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wpfa_save_funnel',
                    nonce: wpfa_admin_data.nonce,
                    id: id,
                    name: name,
                    description: desc,
                    steps: JSON.stringify(steps)
                },
                success: function(res) {
                    if (res.success) {
                        alert(res.data.message);
                        window.location.reload();
                    } else {
                        alert(res.data.message || '保存失败');
                    }
                }
            });
        });

        $('.wpfa-del-funnel-btn').on('click', function() {
            if (!confirm('确定要删除此转化漏斗吗？')) return;
            var id = $(this).data('id');

            $.ajax({
                url: wpfa_admin_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wpfa_delete_funnel',
                    nonce: wpfa_admin_data.nonce,
                    id: id
                },
                success: function(res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        alert(res.data.message || '删除失败');
                    }
                }
            });
        });
    }

    function addStepRow(name, eventVal) {
        var container = $('#wpfa-modal-steps-container');
        var count = container.find('.wpfa-step-row').length + 1;

        var html = '<div class="wpfa-step-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">';
        html += '<span style="font-weight:600;min-width:55px;">Step ' + count + ':</span>';
        html += '<input type="text" class="regular-text wpfa-step-name-input" placeholder="步骤名称 (如: 点击加购)" value="' + escapeHtml(name) + '" required style="flex:1;">';
        html += '<select class="wpfa-step-event-select" style="min-width:180px;">';
        
        var eventOptions = [
            { val: 'page_view', text: '页面访问 (page_view)' },
            { val: 'engaged_visit', text: '深度互动 >30s (engaged_visit)' },
            { val: 'scroll_50', text: '滚动超过50% (scroll_50)' },
            { val: 'view_product', text: '商品详情浏览 (view_product)' },
            { val: 'add_to_cart', text: '加入购物车 (add_to_cart)' },
            { val: 'begin_checkout', text: '进入结账页 (begin_checkout)' },
            { val: 'purchase', text: '完成支付购买 (purchase)' },
            { val: 'contact_click', text: '点击联系方式 (contact_click)' },
            { val: 'form_submit', text: '提交表单 (form_submit)' },
        ];

        eventOptions.forEach(function(opt) {
            var selected = opt.val === eventVal ? 'selected' : '';
            html += '<option value="' + opt.val + '" ' + selected + '>' + opt.text + '</option>';
        });

        html += '</select>';
        html += '<button type="button" class="button button-small wpfa-remove-step-btn">&times;</button>';
        html += '</div>';

        container.append(html);
    }

    /**
     * 设置保存与重置
     */
    function initSettings() {
        $('#wpfa-settings-form').on('submit', function(e) {
            e.preventDefault();
            var formData = $(this).serialize();

            $.ajax({
                url: wpfa_admin_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: formData + '&action=wpfa_save_settings&nonce=' + wpfa_admin_data.nonce,
                success: function(res) {
                    if (res.success) {
                        alert(res.data.message);
                    }
                }
            });
        });

        $('#wpfa-truncate-btn').on('click', function() {
            if (!confirm('【高危操作警告】您确定要彻底清空所有的访问数据与行为事件记录吗？此操作不可逆！')) return;
            if (!confirm('请二次确认：清空后将从零开始统计。')) return;

            $.ajax({
                url: wpfa_admin_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wpfa_truncate_data',
                    nonce: wpfa_admin_data.nonce
                },
                success: function(res) {
                    if (res.success) {
                        alert(res.data.message);
                        window.location.reload();
                    }
                }
            });
        });
    }

    // 格式化数字
    function formatNumber(num, decimals) {
        if (num === null || num === undefined) return '0';
        var n = parseFloat(num);
        if (isNaN(n)) return '0';
        return n.toLocaleString('zh-CN', {
            minimumFractionDigits: decimals || 0,
            maximumFractionDigits: decimals || 0
        });
    }

    // 转义 HTML
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

})(jQuery);
