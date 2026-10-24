# WordPress 智能极速数据漏斗与全景分析系统 (WP Funnel & Web Analytics)

专为 WordPress 与 WooCommerce 深度研发的**极速、全景、零拖累**网站数据漏斗与用户行为分析插件。

---

## 🌟 核心特色与设计哲学

### 1. ⚡ 极致轻量与零拖累 (Ultra-Fast Zero-Lag Architecture)
- **极小前端体积**：纯原生 Vanilla JavaScript，无 jQuery、无庞大图表库依赖，体积压缩至 **< 3.5KB**。
- **零阻塞首屏加载**：采用 `defer` 与 `async` 异步载入，完全不阻塞 DOM 解析和 First Contentful Paint (FCP)。
- **非阻塞无感通信**：优先采用浏览器底层原生 `navigator.sendBeacon`（页面关闭/跳转亦可 100% 可靠送达），降级使用 `fetch(..., { keepalive: true })`，绝不占用网页渲染主线程。
- **双通道上报引擎**：支持官方标准 REST API 模式与**独立极速 Endpoint 模式**（绕过 95% 插件与主题重型加载，单次执行 < 3ms，专为高并发设计）。
- **管理员零污染**：支持自动排除已登录的管理员/编辑角色，开启后在后台人员浏览时不加载任何追踪脚本。
- **自动归档与数据库防膨胀**：内置每日聚合 Cron，定期自动归档并清理超期细粒度日志，保证 MySQL 数据库常年轻巧飞速。

### 2. 📊 全链路转化漏斗分析 (Funnel Analytics)
- **WooCommerce 电商交易全流程漏斗**：
  `进站访问 (Visits)` &rarr; `浏览商品 (View Product)` &rarr; `加入购物车 (Add to Cart)` &rarr; `发起结账 (Begin Checkout)` &rarr; `完成购买 (Purchase)`。
  精准呈现每个环节的到达人数、流失量（Drop-off）、环节流失率、步骤间转化率及全站总体留存率。
- **B2B / 外贸询盘线索漏斗 (Lead Funnel)**：
  `访客进站` &rarr; `深度互动 (>30s或>50%滚动)` &rarr; `点击沟通渠道 (WhatsApp / Email / Tel)` &rarr; `提交询盘表单`。
- **多维度自定义漏斗构建器**：
  自由创建任意多步骤转化漏斗，支持页面访问、点击特定元素、表单提交等自定义事件。

### 3. 🎯 全维度流量与营销获客分析 (Acquisition & UTM)
- **渠道分类归因**：直接访问 (Direct)、自然搜索引擎 (Organic)、社交媒体 (Social)、外部引荐 (Referral)、付费广告战役 (Campaign)。
- **UTM 战役矩阵追踪**：全面解析 `utm_source`、`utm_medium`、`utm_campaign`、`utm_content`、`utm_term`，直接对比不同投放广告渠道的最终漏斗转化率。
- **热门引荐来源域名 Top 10** 与搜索引擎细分。

### 4. 📈 页面受访与互动行为画像 (Behavior)
- 最热门受访页面排行 Top Pages (PV、UV、平均访问时长)。
- 核心着陆页排行 Landing Pages (入口量与跳出率)。
- 关键退出页 Exit Pages (访客流失与离开页)。
- 深度停留与 50% 滚动自动检测。

### 5. 👥 受众画像与终端技术环境 (Audience & Tech)
- 设备终端分布 (Desktop 桌面、Mobile 手机、Tablet 平板)。
- 操作系统 (Windows、macOS、iOS、Android、Linux)。
- 浏览器占比 (Chrome、Safari、Edge、Firefox、WeChat 等)。
- 国家与地区分布排行 (带国旗 Emoji、国家代码与访问占比)。

### 6. 🟢 实时在线大屏 (Real-Time Live Monitor)
- 过去 30 分钟内活跃访客计数牌（呼吸绿点指示）。
- 实时正在浏览的热门页面与最近动态事件流。

### 7. 🛡️ 隐私合规 (GDPR Friendly)
- 支持 IP 加盐单向 Hash 脱敏匿名化存储，杜绝泄露明文个人隐私。
- 严格遵循浏览器 `Do Not Track (DNT)` 防追踪首选项。

---

## 🛠️ 开发者自定义打点 API

插件在前端全局注入了 `window.wpfa` 对象，开发者可在主题或任何页面中手动调用记录自定义事件：

```javascript
// 语法：window.wpfa.track(eventType, category, label, value, customData)

// 示例 1: 追踪用户点击自定义预约按钮
document.querySelector('#book-btn').addEventListener('click', function() {
    window.wpfa.track('booking_click', 'lead', 'Book Consultation', 0, { source: 'hero_banner' });
});

// 示例 2: 追踪高客单价意向咨询
window.wpfa.track('vip_inquiry', 'sales', 'VIP Inquired', 500, { plan: 'Enterprise' });
```

---

## 🚀 自动在线更新与部署规范

本插件已内置 GitHub 自动更新器，发布新版标签后，所有已安装站点可在 WordPress 后台无缝一键在线升级：
- 托管仓库：`wuyou2001/wp-funnel-analytics`
- 本地一键发版命令：
  ```bash
  ./scripts/release-plugin.sh wp-funnel-analytics
  ```
