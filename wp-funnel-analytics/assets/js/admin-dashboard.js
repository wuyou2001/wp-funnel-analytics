/**
 * WP Funnel Analytics - Admin Dashboard & SVG Chart Engine
 * Zero Dependency, Ultra-Smooth Modern SaaS Interface, High-Distinction Multi-Metrics
 */
(function($) {
    'use strict';

    var currentRange = '7d';
    var currentFunnelId = 0;
    var currentChartType = 'line'; // 'line' 或 'bar'
    var realtimeTimer = null;
    var lastTimelineData = null;
    var activeMetrics = {
        pv: true,
        uv: true,
        sessions: true
    };
    var hoveredIndex = -1;

    $(document).ready(function() {
        if ($('#wpfa-trend-svg').length > 0) {
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

        // 图表类型切换 (折线 / 柱状)
        $('.wpfa-toggle-btn').on('click', function() {
            $('.wpfa-toggle-btn').removeClass('active');
            $(this).addClass('active');
            currentChartType = $(this).data('chart-type');
            if (lastTimelineData) {
                renderTimelineChart(lastTimelineData);
            }
        });

        // 启动实时活跃访客轻量轮询
        startRealtimePoll();
    }

    /**
     * 图表交互与 Tooltip 挂载
     */
    function initChartInteractions() {
        // 指标图例点击显隐
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

        // 鼠标在 SVG 区域移动触发悬浮交互
        var container = $('#wpfa-svg-chart-container');
        var tooltip = $('#wpfa-chart-tooltip');

        container.on('mousemove', function(e) {
            if (!lastTimelineData || !lastTimelineData.labels || lastTimelineData.labels.length === 0) return;

            var offset = container.offset();
            var width = container.width();
            var height = container.height();
            var mouseX = e.pageX - offset.left;
            var mouseY = e.pageY - offset.top;

            // 映射到 SVG 坐标 (0 ~ 1000)
            var svgX = (mouseX / width) * 1000;

            var paddingLeft = 65;
            var paddingRight = 45;
            var chartW = 1000 - paddingLeft - paddingRight;
            var count = lastTimelineData.labels.length;
            var stepX = count > 1 ? chartW / (count - 1) : chartW;

            var rawIdx = Math.round((svgX - paddingLeft) / stepX);
            var idx = Math.max(0, Math.min(count - 1, rawIdx));

            if (hoveredIndex !== idx) {
                hoveredIndex = idx;
                renderTimelineChart(lastTimelineData);
            }

            // 更新 Tooltip 内容
            var dateLabel = lastTimelineData.labels[idx];
            var pvVal = lastTimelineData.pv[idx] || 0;
            var uvVal = lastTimelineData.uv[idx] || 0;
            var sesVal = lastTimelineData.sessions[idx] || 0;

            var tooltipHtml = '<div class="wpfa-tt-head">' + escapeHtml(dateLabel) + '</div>';
            if (activeMetrics.pv) {
                tooltipHtml += '<div class="wpfa-tt-row"><span class="wpfa-tt-dot" style="background:#2563eb;border-radius:50%;"></span> 浏览量 (PV): <strong>' + formatNumber(pvVal) + '</strong></div>';
            }
            if (activeMetrics.uv) {
                tooltipHtml += '<div class="wpfa-tt-row"><span class="wpfa-tt-dot" style="background:#059669;transform:rotate(45deg);"></span> 独立访客 (UV): <strong>' + formatNumber(uvVal) + '</strong></div>';
            }
            if (activeMetrics.sessions) {
                tooltipHtml += '<div class="wpfa-tt-row"><span class="wpfa-tt-dot" style="background:#7c3aed;border-radius:2px;"></span> 访问会话: <strong>' + formatNumber(sesVal) + '</strong></div>';
            }

            tooltip.html(tooltipHtml);

            var ttW = tooltip.outerWidth();
            var posX = mouseX + 15;
            if (posX + ttW > width - 10) {
                posX = mouseX - ttW - 15;
            }

            tooltip.css({
                display: 'block',
                left: Math.max(10, posX) + 'px',
                top: Math.max(10, Math.min(height - 120, mouseY - 20)) + 'px'
            });
        });

        container.on('mouseleave', function() {
            hoveredIndex = -1;
            tooltip.hide();
            if (lastTimelineData) {
                renderTimelineChart(lastTimelineData);
            }
        });
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
     * 优雅标尺刻度算法 (Nice Scale)
     */
    function calculateNiceScale(maxVal, ticksCount) {
        ticksCount = ticksCount || 4;
        if (maxVal <= 0) maxVal = 10;
        var roughStep = maxVal / ticksCount;
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
     * 自适应高质感 SVG 图表渲染核心 (彻底杜绝 300px 挤在左侧问题)
     */
    function renderTimelineChart(timeline) {
        var svg = document.getElementById('wpfa-trend-svg');
        if (!svg) return;

        $(svg).empty();

        if (!timeline || !timeline.labels || timeline.labels.length === 0) {
            svg.innerHTML = '<text x="500" y="160" fill="#94a3b8" font-size="14" text-anchor="middle">该时间段内暂无访问数据</text>';
            return;
        }

        var labels = timeline.labels;
        var pv = timeline.pv || [];
        var uv = timeline.uv || [];
        var sessions = timeline.sessions || [];

        // 更新顶部图例总数
        var sumPv = pv.reduce(function(a, b) { return a + b; }, 0);
        var sumUv = uv.reduce(function(a, b) { return a + b; }, 0);
        var sumSes = sessions.reduce(function(a, b) { return a + b; }, 0);

        $('#legend-pv-sum').text(formatNumber(sumPv));
        $('#legend-uv-sum').text(formatNumber(sumUv));
        $('#legend-sessions-sum').text(formatNumber(sumSes));

        // SVG 坐标系参数：宽 1000, 高 320 (全宽自适应)
        var totalW = 1000;
        var totalH = 320;
        var padding = { top: 35, right: 45, bottom: 45, left: 65 };
        var chartW = totalW - padding.left - padding.right;
        var chartH = totalH - padding.top - padding.bottom;

        // 计算最大值 (仅统计开启的指标)
        var maxActiveVal = 0;
        if (activeMetrics.pv) maxActiveVal = Math.max(maxActiveVal, Math.max.apply(null, pv));
        if (activeMetrics.uv) maxActiveVal = Math.max(maxActiveVal, Math.max.apply(null, uv));
        if (activeMetrics.sessions) maxActiveVal = Math.max(maxActiveVal, Math.max.apply(null, sessions));

        var scale = calculateNiceScale(maxActiveVal, 4);
        var maxVal = scale.max;
        var stepVal = scale.step;

        var html = '';

        // 定义渐变色与滤镜
        html += '<defs>';
        html += '  <linearGradient id="pvGrad" x1="0%" y1="0%" x2="0%" y2="100%">';
        html += '    <stop offset="0%" stop-color="#2563eb" stop-opacity="0.22" />';
        html += '    <stop offset="100%" stop-color="#2563eb" stop-opacity="0.0" />';
        html += '  </linearGradient>';
        html += '  <linearGradient id="uvGrad" x1="0%" y1="0%" x2="0%" y2="100%">';
        html += '    <stop offset="0%" stop-color="#059669" stop-opacity="0.18" />';
        html += '    <stop offset="100%" stop-color="#059669" stop-opacity="0.0" />';
        html += '  </linearGradient>';
        html += '  <linearGradient id="sesGrad" x1="0%" y1="0%" x2="0%" y2="100%">';
        html += '    <stop offset="0%" stop-color="#7c3aed" stop-opacity="0.12" />';
        html += '    <stop offset="100%" stop-color="#7c3aed" stop-opacity="0.0" />';
        html += '  </linearGradient>';
        html += '  <filter id="nodeGlow" x="-50%" y="-50%" width="200%" height="200%">';
        html += '    <feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.25" />';
        html += '  </filter>';
        html += '</defs>';

        // 绘制水平背景网格线与 Y 轴刻度
        for (var val = 0; val <= maxVal; val += stepVal) {
            var y = padding.top + chartH - (val / maxVal) * chartH;
            var strokeColor = (val === 0) ? '#cbd5e1' : '#f1f5f9';
            var strokeWidth = (val === 0) ? '1.5' : '1';

            html += '<line x1="' + padding.left + '" y1="' + y + '" x2="' + (totalW - padding.right) + '" y2="' + y + '" stroke="' + strokeColor + '" stroke-width="' + strokeWidth + '" />';
            html += '<text x="' + (padding.left - 12) + '" y="' + (y + 4) + '" fill="#94a3b8" font-size="11" font-family="sans-serif" text-anchor="end">' + formatNumber(val) + '</text>';
        }

        var count = labels.length;
        var stepX = count > 1 ? chartW / (count - 1) : chartW;

        // 绘制 X 轴刻度标签
        labels.forEach(function(lbl, idx) {
            var x = padding.left + idx * stepX;
            var isCurrentHover = (hoveredIndex === idx);
            var textColor = isCurrentHover ? '#0f172a' : '#64748b';
            var fontW = isCurrentHover ? 'bold' : 'normal';

            html += '<text x="' + x + '" y="' + (totalH - 15) + '" fill="' + textColor + '" font-size="11" font-weight="' + fontW + '" font-family="sans-serif" text-anchor="middle">' + escapeHtml(lbl) + '</text>';
        });

        // 悬浮高亮竖向标尺线
        if (hoveredIndex >= 0 && hoveredIndex < count) {
            var hx = padding.left + hoveredIndex * stepX;
            html += '<line x1="' + hx + '" y1="' + padding.top + '" x2="' + hx + '" y2="' + (padding.top + chartH) + '" stroke="#94a3b8" stroke-width="1.5" stroke-dasharray="4,4" opacity="0.8" />';
        }

        // ======================= 渲染模式 1: 柱状图对比视图 =======================
        if (currentChartType === 'bar') {
            var barSlotW = stepX * 0.65;
            var activeCount = (activeMetrics.pv ? 1 : 0) + (activeMetrics.uv ? 1 : 0) + (activeMetrics.sessions ? 1 : 0);
            activeCount = Math.max(1, activeCount);
            var subBarW = Math.max(6, (barSlotW / activeCount) - 3);

            labels.forEach(function(lbl, idx) {
                var centerX = padding.left + idx * stepX;
                var startX = centerX - (barSlotW / 2);
                var curX = startX;

                if (activeMetrics.pv) {
                    var vPv = pv[idx] || 0;
                    var bH = (vPv / maxVal) * chartH;
                    var bY = padding.top + chartH - bH;
                    html += '<rect x="' + curX + '" y="' + bY + '" width="' + subBarW + '" height="' + bH + '" rx="3" fill="#2563eb" opacity="' + (hoveredIndex === idx ? '1' : '0.85') + '" />';
                    curX += subBarW + 3;
                }
                if (activeMetrics.uv) {
                    var vUv = uv[idx] || 0;
                    var bH2 = (vUv / maxVal) * chartH;
                    var bY2 = padding.top + chartH - bH2;
                    html += '<rect x="' + curX + '" y="' + bY2 + '" width="' + subBarW + '" height="' + bH2 + '" rx="3" fill="#059669" opacity="' + (hoveredIndex === idx ? '1' : '0.85') + '" />';
                    curX += subBarW + 3;
                }
                if (activeMetrics.sessions) {
                    var vSes = sessions[idx] || 0;
                    var bH3 = (vSes / maxVal) * chartH;
                    var bY3 = padding.top + chartH - bH3;
                    html += '<rect x="' + curX + '" y="' + bY3 + '" width="' + subBarW + '" height="' + bH3 + '" rx="3" fill="#7c3aed" opacity="' + (hoveredIndex === idx ? '1' : '0.85') + '" />';
                }
            });
        }
        // ======================= 渲染模式 2: 平滑折线与多形状区分视图 =======================
        else {
            // 折线辅助函数
            function buildCurvePath(dataArr) {
                var pts = [];
                dataArr.forEach(function(v, idx) {
                    var x = padding.left + idx * stepX;
                    var y = padding.top + chartH - (v / maxVal) * chartH;
                    pts.push({ x: x, y: y, val: v });
                });

                if (pts.length === 0) return { path: '', area: '', pts: [] };

                var pStr = 'M ' + pts[0].x + ' ' + pts[0].y;
                for (var j = 0; j < pts.length - 1; j++) {
                    var xMid = (pts[j].x + pts[j + 1].x) / 2;
                    var yMid = (pts[j].y + pts[j + 1].y) / 2;
                    pStr += ' Q ' + pts[j].x + ' ' + pts[j].y + ', ' + xMid + ' ' + yMid;
                }
                pStr += ' T ' + pts[pts.length - 1].x + ' ' + pts[pts.length - 1].y;

                var areaStr = pStr + ' L ' + pts[pts.length - 1].x + ' ' + (padding.top + chartH) + ' L ' + pts[0].x + ' ' + (padding.top + chartH) + ' Z';
                return { path: pStr, area: areaStr, pts: pts };
            }

            // 1. 访问会话 (Sessions) - 紫色虚线 + 正方形标记 ■
            if (activeMetrics.sessions) {
                var sesData = buildCurvePath(sessions);
                if (sesData.path) {
                    html += '<path d="' + sesData.area + '" fill="url(#sesGrad)" />';
                    html += '<path d="' + sesData.path + '" fill="none" stroke="#7c3aed" stroke-width="2.5" stroke-dasharray="6,4" stroke-linecap="round" />';

                    sesData.pts.forEach(function(pt, pIdx) {
                        var isHov = (hoveredIndex === pIdx);
                        var sSize = isHov ? 10 : 7;
                        var half = sSize / 2;
                        html += '<rect x="' + (pt.x - half) + '" y="' + (pt.y - half) + '" width="' + sSize + '" height="' + sSize + '" fill="#ffffff" stroke="#7c3aed" stroke-width="' + (isHov ? 3 : 2) + '" rx="1.5" filter="url(#nodeGlow)" />';
                    });
                }
            }

            // 2. 独立访客 (UV) - 翡翠绿实线 + 菱形标记 ◆
            if (activeMetrics.uv) {
                var uvData = buildCurvePath(uv);
                if (uvData.path) {
                    html += '<path d="' + uvData.area + '" fill="url(#uvGrad)" />';
                    html += '<path d="' + uvData.path + '" fill="none" stroke="#059669" stroke-width="3" stroke-linecap="round" />';

                    uvData.pts.forEach(function(pt, pIdx) {
                        var isHov = (hoveredIndex === pIdx);
                        var dRadius = isHov ? 6.5 : 4.5;
                        // 绘制旋转 45 度的菱形
                        var diamondPath = 'M ' + pt.x + ' ' + (pt.y - dRadius) +
                                          ' L ' + (pt.x + dRadius) + ' ' + pt.y +
                                          ' L ' + pt.x + ' ' + (pt.y + dRadius) +
                                          ' L ' + (pt.x - dRadius) + ' ' + pt.y + ' Z';
                        html += '<path d="' + diamondPath + '" fill="#ffffff" stroke="#059669" stroke-width="' + (isHov ? 3 : 2) + '" filter="url(#nodeGlow)" />';
                    });
                }
            }

            // 3. 浏览量 (PV) - 亮蓝实线 + 圆形标记 🔵
            if (activeMetrics.pv) {
                var pvData = buildCurvePath(pv);
                if (pvData.path) {
                    html += '<path d="' + pvData.area + '" fill="url(#pvGrad)" />';
                    html += '<path d="' + pvData.path + '" fill="none" stroke="#2563eb" stroke-width="3.5" stroke-linecap="round" />';

                    pvData.pts.forEach(function(pt, pIdx) {
                        var isHov = (hoveredIndex === pIdx);
                        var r = isHov ? 6 : 4;
                        html += '<circle cx="' + pt.x + '" cy="' + pt.y + '" r="' + r + '" fill="#ffffff" stroke="#2563eb" stroke-width="' + (isHov ? 3.5 : 2.5) + '" filter="url(#nodeGlow)" />';

                        // 若数值大于 0，节点上方展示微型胶囊数字标签
                        if (pt.val > 0) {
                            html += '<g>';
                            html += '<rect x="' + (pt.x - 12) + '" y="' + (pt.y - 24) + '" width="24" height="15" rx="3" fill="#2563eb" opacity="0.9" />';
                            html += '<text x="' + pt.x + '" y="' + (pt.y - 13) + '" fill="#ffffff" font-size="10" font-weight="bold" font-family="sans-serif" text-anchor="middle">' + pt.val + '</text>';
                            html += '</g>';
                        }
                    });
                }
            }
        }

        svg.innerHTML = html;
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

    function formatNumber(num, decimals) {
        if (num === null || num === undefined) return '0';
        var n = parseFloat(num);
        if (isNaN(n)) return '0';
        return n.toLocaleString('zh-CN', {
            minimumFractionDigits: decimals || 0,
            maximumFractionDigits: decimals || 0
        });
    }

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
