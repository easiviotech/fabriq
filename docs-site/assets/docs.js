/* ===================================================================
   Fabriq Documentation — Shared Layout & Navigation
   =================================================================== */

(function () {
    'use strict';

    // ── Navigation Structure ──────────────────────────────────────────
    const NAV = [
        {
            section: 'Prologue',
            items: [
                { title: 'Why Fabriq?', href: 'comparison.html' },
                { title: 'Getting Started', href: 'index.html' },
                { title: 'Architecture', href: 'architecture.html' },
                { title: 'Configuration', href: 'configuration.html' },
            ],
        },
        {
            section: 'The Basics',
            items: [
                { title: 'HTTP Routing', href: 'http.html' },
                { title: 'Frontend Serving', href: 'frontend.html' },
                { title: 'Multi-Tenancy', href: 'tenancy.html' },
                { title: 'Security', href: 'security.html' },
            ],
        },
        {
            section: 'Data Layer',
            items: [
                { title: 'Database & ORM', href: 'database.html' },
                { title: 'Real-Time', href: 'realtime.html' },
            ],
        },
        {
            section: 'Advanced',
            items: [
                { title: 'Async Processing', href: 'async.html' },
                { title: 'Live Streaming (add-on)', href: 'streaming.html' },
                { title: 'Game Server (add-on)', href: 'gaming.html' },
                { title: 'Operations', href: 'operations.html' },
                { title: 'Deployment', href: 'deployment.html' },
            ],
        },
    ];

    // ── Page Ordering (for prev/next) ─────────────────────────────────
    const PAGE_ORDER = NAV.flatMap(g => g.items.map(i => i));

    // ── Search Index (populated from page headings) ───────────────────
    const SEARCH_INDEX = [
        { title: 'Why Fabriq?', section: 'Prologue', href: 'comparison.html' },
        { title: 'Unified Runtime', section: 'Why Fabriq?', href: 'comparison.html#unified-runtime' },
        { title: 'Multi-Tenancy', section: 'Why Fabriq?', href: 'comparison.html#multi-tenancy' },
        { title: 'Context Isolation', section: 'Why Fabriq?', href: 'comparison.html#context-isolation' },
        { title: 'Idempotency', section: 'Why Fabriq?', href: 'comparison.html#idempotency' },
        { title: 'Policy Engine', section: 'Why Fabriq?', href: 'comparison.html#policy-engine' },
        { title: 'Connection Pool Safety', section: 'Why Fabriq?', href: 'comparison.html#pool-safety' },
        { title: 'Cross-Worker WebSocket', section: 'Why Fabriq?', href: 'comparison.html#cross-worker-ws' },
        { title: 'Unified Frontend Serving', section: 'Why Fabriq?', href: 'comparison.html#frontend-serving' },
        { title: 'Laravel-Familiar DX', section: 'Why Fabriq?', href: 'comparison.html#laravel-dx' },
        { title: 'Feature Comparison Matrix', section: 'Why Fabriq?', href: 'comparison.html#comparison-matrix' },
        { title: 'Getting Started', section: 'Prologue', href: 'index.html' },
        { title: 'Prerequisites', section: 'Getting Started', href: 'index.html#prerequisites' },
        { title: 'Installation', section: 'Getting Started', href: 'index.html#installation' },
        { title: 'Install PHP', section: 'Installation', href: 'index.html#install-php' },
        { title: 'Install Swoole Extension', section: 'Installation', href: 'index.html#install-swoole' },
        { title: 'Verify Environment', section: 'Installation', href: 'index.html#install-verify' },
        { title: 'Install Dependencies', section: 'Installation', href: 'index.html#install-deps' },
        { title: 'Packagist Packages (Core + Add-ons)', section: 'Installation', href: 'index.html#individual-packages' },
        { title: 'Quick Start', section: 'Getting Started', href: 'index.html#quick-start' },
        { title: 'CLI Commands', section: 'Getting Started', href: 'index.html#cli-commands' },
        { title: 'Project Structure', section: 'Getting Started', href: 'index.html#project-structure' },
        { title: 'Architecture', section: 'Prologue', href: 'architecture.html' },
        { title: 'Component Diagram', section: 'Architecture', href: 'architecture.html#component-diagram' },
        { title: 'Data Flow', section: 'Architecture', href: 'architecture.html#data-flow' },
        { title: 'Bootstrap Lifecycle', section: 'Architecture', href: 'architecture.html#bootstrap' },
        { title: 'Context', section: 'Architecture', href: 'architecture.html#context' },
        { title: 'Package Distribution (Packagist)', section: 'Architecture', href: 'architecture.html#packages' },
        { title: 'Configuration', section: 'Prologue', href: 'configuration.html' },
        { title: 'Config Files', section: 'Configuration', href: 'configuration.html#config-files' },
        { title: 'Dot Notation Access', section: 'Configuration', href: 'configuration.html#dot-notation' },
        { title: 'Bootstrap File', section: 'Configuration', href: 'configuration.html#bootstrap-file' },
        { title: 'HTTP Routing', section: 'The Basics', href: 'http.html' },
        { title: 'Defining Routes', section: 'HTTP Routing', href: 'http.html#defining-routes' },
        { title: 'Route Parameters', section: 'HTTP Routing', href: 'http.html#route-parameters' },
        { title: 'Request Object', section: 'HTTP Routing', href: 'http.html#request' },
        { title: 'Response Object', section: 'HTTP Routing', href: 'http.html#response' },
        { title: 'Middleware', section: 'HTTP Routing', href: 'http.html#middleware' },
        { title: 'Writing Middleware', section: 'HTTP Routing', href: 'http.html#writing-middleware' },
        { title: 'Validation', section: 'HTTP Routing', href: 'http.html#validation' },
        { title: 'Frontend Serving', section: 'The Basics', href: 'frontend.html' },
        { title: 'Why Serve Frontends Through Fabriq?', section: 'Frontend Serving', href: 'frontend.html#why-fabriq' },
        { title: 'Static File Serving', section: 'Frontend Serving', href: 'frontend.html#static-serving' },
        { title: 'Per-Tenant Frontends', section: 'Frontend Serving', href: 'frontend.html#multi-tenancy' },
        { title: 'Custom Domains', section: 'Frontend Serving', href: 'frontend.html#custom-domains' },
        { title: 'SPA Fallback', section: 'Frontend Serving', href: 'frontend.html#spa-fallback' },
        { title: 'Build Automation (CI/CD)', section: 'Frontend Serving', href: 'frontend.html#build-automation' },
        { title: 'Frontend CLI Commands', section: 'Frontend Serving', href: 'frontend.html#cli-commands' },
        { title: 'Frontend API Endpoints', section: 'Frontend Serving', href: 'frontend.html#api-endpoints' },
        { title: 'Frontend Webhooks', section: 'Frontend Serving', href: 'frontend.html#webhooks' },
        { title: 'Domain Map Configuration', section: 'Frontend Serving', href: 'frontend.html#domain-map' },
        { title: 'Frontend Caching Strategy', section: 'Frontend Serving', href: 'frontend.html#caching' },
        { title: 'Multi-Tenancy', section: 'The Basics', href: 'tenancy.html' },
        { title: 'Tenant Resolution', section: 'Multi-Tenancy', href: 'tenancy.html#resolution' },
        { title: 'TenantContext', section: 'Multi-Tenancy', href: 'tenancy.html#tenant-context' },
        { title: 'Tenant Enforcement', section: 'Multi-Tenancy', href: 'tenancy.html#enforcement' },
        { title: 'Security', section: 'The Basics', href: 'security.html' },
        { title: 'JWT Authentication', section: 'Security', href: 'security.html#jwt' },
        { title: 'API Key Authentication', section: 'Security', href: 'security.html#api-keys' },
        { title: 'RBAC + ABAC Policy Engine', section: 'Security', href: 'security.html#policy-engine' },
        { title: 'Rate Limiting', section: 'Security', href: 'security.html#rate-limiting' },
        { title: 'Database & ORM', section: 'Data Layer', href: 'database.html' },
        { title: 'Database Architecture', section: 'Database & ORM', href: 'database.html#architecture' },
        { title: 'Per-Tenant Database Routing', section: 'Database & ORM', href: 'database.html#architecture' },
        { title: 'Connection Pools', section: 'Database & ORM', href: 'database.html#connection-pools' },
        { title: 'ORM Query Builder', section: 'Database & ORM', href: 'database.html#query-builder' },
        { title: 'Active Record Models', section: 'Database & ORM', href: 'database.html#models' },
        { title: 'Stored Procedures', section: 'Database & ORM', href: 'database.html#stored-procedures' },
        { title: 'Schema & Migrations', section: 'Database & ORM', href: 'database.html#schema' },
        { title: 'Transactions', section: 'Database & ORM', href: 'database.html#transactions' },
        { title: 'DbManager', section: 'Database & ORM', href: 'database.html#dbmanager' },
        { title: 'TenantAwareRepository', section: 'Database & ORM', href: 'database.html#repository' },
        { title: 'ORM Configuration', section: 'Database & ORM', href: 'database.html#configuration' },
        { title: 'Real-Time', section: 'Data Layer', href: 'realtime.html' },
        { title: 'WebSocket Authentication', section: 'Real-Time', href: 'realtime.html#ws-auth' },
        { title: 'WebSocket Handler', section: 'Real-Time', href: 'realtime.html#ws-handler' },
        { title: 'Push API', section: 'Real-Time', href: 'realtime.html#push-api' },
        { title: 'Presence', section: 'Real-Time', href: 'realtime.html#presence' },
        { title: 'Async Processing', section: 'Advanced', href: 'async.html' },
        { title: 'Background Jobs', section: 'Async Processing', href: 'async.html#jobs' },
        { title: 'Event Bus', section: 'Async Processing', href: 'async.html#events' },
        { title: 'Scheduled Jobs', section: 'Async Processing', href: 'async.html#scheduling' },
        { title: 'Idempotency', section: 'Async Processing', href: 'async.html#idempotency' },
        { title: 'Live Streaming (add-on package)', section: 'Advanced', href: 'streaming.html' },
        { title: 'Architecture', section: 'Live Streaming', href: 'streaming.html#architecture' },
        { title: 'Configuration', section: 'Live Streaming', href: 'streaming.html#configuration' },
        { title: 'WebRTC Signaling', section: 'Live Streaming', href: 'streaming.html#signaling' },
        { title: 'HLS Transcoding', section: 'Live Streaming', href: 'streaming.html#hls' },
        { title: 'Stream Lifecycle', section: 'Live Streaming', href: 'streaming.html#lifecycle' },
        { title: 'Viewer Tracking', section: 'Live Streaming', href: 'streaming.html#viewers' },
        { title: 'Chat Moderation', section: 'Live Streaming', href: 'streaming.html#chat' },
        { title: 'Streaming Metrics', section: 'Live Streaming', href: 'streaming.html#monitoring' },
        { title: 'Game Server (add-on package)', section: 'Advanced', href: 'gaming.html' },
        { title: 'Architecture', section: 'Game Server', href: 'gaming.html#architecture' },
        { title: 'Game Loop & Tick Rates', section: 'Game Server', href: 'gaming.html#tick-rates' },
        { title: 'Configuration', section: 'Game Server', href: 'gaming.html#configuration' },
        { title: 'Binary Protocol', section: 'Game Server', href: 'gaming.html#protocol' },
        { title: 'Game Rooms', section: 'Game Server', href: 'gaming.html#rooms' },
        { title: 'Matchmaking', section: 'Game Server', href: 'gaming.html#matchmaking' },
        { title: 'Pre-Game Lobbies', section: 'Game Server', href: 'gaming.html#lobbies' },
        { title: 'Player Reconnection', section: 'Game Server', href: 'gaming.html#reconnection' },
        { title: 'State Synchronization', section: 'Game Server', href: 'gaming.html#state-sync' },
        { title: 'Gaming Metrics', section: 'Game Server', href: 'gaming.html#monitoring' },
        { title: 'Operations', section: 'Advanced', href: 'operations.html' },
        { title: 'Structured Logging', section: 'Operations', href: 'operations.html#logging' },
        { title: 'Prometheus Metrics', section: 'Operations', href: 'operations.html#metrics' },
        { title: 'Health Check', section: 'Operations', href: 'operations.html#health' },
        { title: 'Testing', section: 'Operations', href: 'operations.html#testing' },
        { title: 'Deployment', section: 'Operations', href: 'operations.html#deployment' },
        { title: 'Production Deployment', section: 'Advanced', href: 'deployment.html' },
        { title: 'Process Types', section: 'Deployment', href: 'deployment.html#process-types' },
        { title: 'Production Dockerfile', section: 'Deployment', href: 'deployment.html#production-dockerfile' },
        { title: 'Environment Configuration', section: 'Deployment', href: 'deployment.html#environment-config' },
        { title: 'Production Docker Compose', section: 'Deployment', href: 'deployment.html#docker-compose' },
        { title: 'Reverse Proxy & TLS', section: 'Deployment', href: 'deployment.html#reverse-proxy' },
        { title: 'Cloud Deployment Options', section: 'Deployment', href: 'deployment.html#cloud-options' },
        { title: 'Kubernetes Example', section: 'Deployment', href: 'deployment.html#cloud-options' },
        { title: 'Connection Pool Sizing', section: 'Deployment', href: 'deployment.html#pool-sizing' },
        { title: 'Zero-Downtime Deployment', section: 'Deployment', href: 'deployment.html#zero-downtime' },
        { title: 'Production Checklist', section: 'Deployment', href: 'deployment.html#checklist' },
    ];

    // ── Detect Current Page ───────────────────────────────────────────
    function currentPage() {
        const path = window.location.pathname;
        const file = path.substring(path.lastIndexOf('/') + 1) || 'index.html';
        return file;
    }

    // ── Build Sidebar HTML ────────────────────────────────────────────
    function buildSidebar() {
        const page = currentPage();
        let html = `
            <div class="sidebar-brand">
                <svg width="28" height="28" viewBox="0 0 100 100" fill="none">
                    <path d="M35,15 L70,50 L35,85 L0,50Z M35,27 L58,50 L35,73 L12,50Z" fill="#b91c1c" fill-rule="evenodd"/>
                    <path d="M65,15 L100,50 L65,85 L30,50Z M65,27 L88,50 L65,73 L42,50Z" fill="#dc2626" fill-rule="evenodd"/>
                    <path d="M50,30 L56,36 L50,42 L44,36Z" fill="#b91c1c"/>
                </svg>
                <span class="sidebar-brand-name">Fabriq</span>
                <span class="sidebar-brand-tag">Docs</span>
            </div>
        `;

        for (const group of NAV) {
            html += `<div class="sidebar-section">`;
            html += `<div class="sidebar-section-title">${group.section}</div>`;
            for (const item of group.items) {
                const isActive = page === item.href || (page === '' && item.href === 'index.html');
                html += `<a href="${item.href}" class="sidebar-link${isActive ? ' active' : ''}">${item.title}</a>`;
            }
            html += `</div>`;
        }

        return html;
    }

    // ── Build Header HTML ─────────────────────────────────────────────
    function buildHeader() {
        return `
            <button id="mobile-menu-btn" aria-label="Toggle menu">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
            <div class="header-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="text" id="search-input" placeholder="Search docs... (Ctrl+K)" autocomplete="off" />
                <div id="search-results"></div>
            </div>
            <div class="header-links">
                <span class="version-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    PHP 8.2+
                </span>
                <a href="https://github.com" target="_blank" rel="noopener" title="GitHub">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                </a>
            </div>
        `;
    }

    // ── Build Prev / Next Nav ─────────────────────────────────────────
    function buildPrevNext() {
        const page = currentPage();
        const idx = PAGE_ORDER.findIndex(p => p.href === page);
        if (idx === -1) return '';

        let html = '<div class="docs-nav">';

        if (idx > 0) {
            const prev = PAGE_ORDER[idx - 1];
            html += `
                <a href="${prev.href}" class="prev">
                    <span class="docs-nav-label">&larr; Previous</span>
                    <span class="docs-nav-title">${prev.title}</span>
                </a>`;
        } else {
            html += '<div></div>';
        }

        if (idx < PAGE_ORDER.length - 1) {
            const next = PAGE_ORDER[idx + 1];
            html += `
                <a href="${next.href}" class="next">
                    <span class="docs-nav-label">Next &rarr;</span>
                    <span class="docs-nav-title">${next.title}</span>
                </a>`;
        }

        html += '</div>';
        return html;
    }

    // ── Wrap Code Blocks ──────────────────────────────────────────────
    function wrapCodeBlocks() {
        document.querySelectorAll('pre > code[class*="language-"]').forEach(code => {
            const pre = code.parentElement;
            if (pre.parentElement.classList.contains('code-wrapper')) return;

            const lang = (code.className.match(/language-(\w+)/) || [])[1] || '';
            const langLabel = { php: 'PHP', bash: 'Bash', sql: 'SQL', json: 'JSON', yaml: 'YAML', text: 'Text', ini: 'INI', dockerfile: 'Dockerfile' }[lang] || lang.toUpperCase();

            const wrapper = document.createElement('div');
            wrapper.className = 'code-wrapper';

            const header = document.createElement('div');
            header.className = 'code-header';
            header.innerHTML = `
                <span class="lang-label">${langLabel}</span>
                <button class="copy-btn" onclick="window._copyCode(this)">Copy</button>
            `;

            pre.parentNode.insertBefore(wrapper, pre);
            wrapper.appendChild(header);
            wrapper.appendChild(pre);
        });
    }

    // ── Copy Code ─────────────────────────────────────────────────────
    window._copyCode = function (btn) {
        const code = btn.closest('.code-wrapper').querySelector('code');
        navigator.clipboard.writeText(code.textContent).then(() => {
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(() => {
                btn.textContent = 'Copy';
                btn.classList.remove('copied');
            }, 2000);
        });
    };

    // ── Search ────────────────────────────────────────────────────────
    function initSearch() {
        const input = document.getElementById('search-input');
        const results = document.getElementById('search-results');
        if (!input || !results) return;

        input.addEventListener('input', () => {
            const q = input.value.trim().toLowerCase();
            if (q.length < 2) {
                results.classList.remove('active');
                return;
            }

            const matches = SEARCH_INDEX.filter(item =>
                item.title.toLowerCase().includes(q) ||
                item.section.toLowerCase().includes(q)
            );

            if (matches.length === 0) {
                results.innerHTML = '<div class="search-no-results">No results found.</div>';
            } else {
                results.innerHTML = matches.slice(0, 10).map(m => `
                    <a href="${m.href}" class="search-result-item">
                        <div class="search-result-title">${m.title}</div>
                        <div class="search-result-section">${m.section}</div>
                    </a>
                `).join('');
            }
            results.classList.add('active');
        });

        input.addEventListener('blur', () => {
            setTimeout(() => results.classList.remove('active'), 200);
        });

        input.addEventListener('focus', () => {
            if (input.value.trim().length >= 2) results.classList.add('active');
        });

        // Ctrl+K shortcut
        document.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                input.focus();
                input.select();
            }
            if (e.key === 'Escape') {
                results.classList.remove('active');
                input.blur();
            }
        });
    }

    // ── Mobile Menu ───────────────────────────────────────────────────
    function initMobileMenu() {
        const btn = document.getElementById('mobile-menu-btn');
        const sidebar = document.getElementById('docs-sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        if (!btn || !sidebar || !overlay) return;

        function toggle() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
        }

        btn.addEventListener('click', toggle);
        overlay.addEventListener('click', toggle);
    }

    // ── Initialize Layout ─────────────────────────────────────────────
    function init() {
        // Inject sidebar
        const sidebar = document.getElementById('docs-sidebar');
        if (sidebar) sidebar.innerHTML = buildSidebar();

        // Inject header
        const header = document.getElementById('docs-header');
        if (header) header.innerHTML = buildHeader();

        // Inject prev/next nav
        const navSlot = document.getElementById('docs-prev-next');
        if (navSlot) navSlot.innerHTML = buildPrevNext();

        // Wrap code blocks and re-run Prism
        wrapCodeBlocks();
        if (window.Prism) Prism.highlightAll();

        // Initialize interactions
        initSearch();
        initMobileMenu();
    }

    // ── Run on DOM Ready ──────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

