(function () {
    var username = 'RedjanVisitacion';
    var apiBase = 'https://api.github.com';
    var profileUrl = 'https://github.com/' + username;

    var fallbackRepos = [
        {
            name: 'RPSV_CODES Portfolio',
            description: 'Personal developer portfolio for Redjan Phil S. Visitacion with PHP, JavaScript, and responsive design.',
            html_url: profileUrl,
            homepage: 'https://redjan.page.gd',
            language: 'JavaScript',
            stargazers_count: 0,
            updated_at: new Date().toISOString(),
            topics: ['portfolio', 'javascript', 'php', 'mysql']
        },
        {
            name: 'IoT Smart Home',
            description: 'ESP32 automation concept for monitoring devices, sensors, and connected home controls.',
            html_url: profileUrl,
            homepage: '',
            language: 'C++',
            stargazers_count: 0,
            updated_at: new Date().toISOString(),
            topics: ['esp32', 'iot', 'arduino', 'automation']
        },
        {
            name: 'Student Management System',
            description: 'Database-backed school management project concept using PHP, MySQL, and admin workflows.',
            html_url: profileUrl,
            homepage: '',
            language: 'PHP',
            stargazers_count: 0,
            updated_at: new Date().toISOString(),
            topics: ['php', 'mysql', 'dashboard', 'system']
        }
    ];

    document.addEventListener('DOMContentLoaded', initGitHubPortfolio);

    async function initGitHubPortfolio() {
        var projectsEl = document.getElementById('githubProjects');
        if (!projectsEl) return;

        try {
            var bundle = await fetchPortfolioBundle();
            var user = bundle.user;
            var repos = bundle.repos;
            repos = Array.isArray(repos) ? repos.filter(function (repo) { return !repo.fork && !repo.archived; }) : [];

            if (!repos.length) repos = fallbackRepos;
            updateProfile(user);
            updateDashboard(user, repos, bundle.contributions);
            renderProjects(projectsEl, chooseBestRepos(repos));
        } catch (error) {
            updateDashboard(null, fallbackRepos);
            renderProjects(projectsEl, fallbackRepos);
        }
    }

    async function fetchPortfolioBundle() {
        try {
            var local = await fetchJson('php/github_api.php?t=' + Date.now());
            if (local && local.success) {
                return {
                    user: local.user,
                    repos: local.repos,
                    contributions: local.contributions
                };
            }
        } catch (error) {}

        return {
            user: await fetchJson(apiBase + '/users/' + username),
            repos: await fetchJson(apiBase + '/users/' + username + '/repos?per_page=100&sort=updated'),
            contributions: null
        };
    }

    async function fetchJson(url) {
        var response = await fetch(url, {
            headers: { Accept: 'application/vnd.github+json' },
            cache: 'no-store'
        });
        if (!response.ok) throw new Error('GitHub request failed');
        return response.json();
    }

    function updateProfile(user) {
        if (!user) return;
        setText('githubName', 'Redjan Phil S. Visitacion');
        setText('githubBio', user.bio || 'BSIT Student, Full-Stack Web Developer, and IoT enthusiast building web systems and automation projects.');
        var avatar = document.getElementById('githubAvatar');
        if (avatar && user.avatar_url) avatar.src = user.avatar_url;
    }

    function updateDashboard(user, repos, contributions) {
        var stars = repos.reduce(function (total, repo) { return total + Number(repo.stargazers_count || 0); }, 0);
        var latest = repos.slice().sort(function (a, b) {
            return new Date(b.updated_at || 0) - new Date(a.updated_at || 0);
        })[0];

        setText('githubRepoCount', user && typeof user.public_repos === 'number' ? user.public_repos : repos.length);
        setText('githubStarsCount', stars);
        setText('githubUpdatedAt', latest && latest.updated_at ? formatDate(latest.updated_at) : '--');
        setText('githubContributionCount', contributions || 'View Profile');
        renderTopLanguages(repos);
    }

    function renderTopLanguages(repos) {
        var host = document.getElementById('githubTopLanguages');
        if (!host) return;

        var counts = repos.reduce(function (map, repo) {
            var lang = repo.language;
            if (lang) map[lang] = (map[lang] || 0) + 1;
            (repo.topics || []).forEach(function (topic) {
                var normalized = normalizeTopic(topic);
                if (isStackTopic(normalized)) map[normalized] = (map[normalized] || 0) + 1;
            });
            return map;
        }, {});

        var languages = Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a]; }).slice(0, 8);
        if (!languages.length) languages = ['JavaScript', 'PHP', 'Python', 'MySQL', 'ESP32'];

        host.innerHTML = languages.map(function (language) {
            return '<span>' + escapeHtml(language) + '</span>';
        }).join('');
    }

    function chooseBestRepos(repos) {
        return repos.slice()
            .sort(function (a, b) {
                return repoScore(b) - repoScore(a);
            })
            .slice(0, 6);
    }

    function repoScore(repo) {
        var updatedDays = Math.max(1, (Date.now() - new Date(repo.updated_at || repo.pushed_at || 0).getTime()) / 86400000);
        var activity = Math.max(0, 120 - updatedDays);
        var hasDemo = repo.homepage ? 40 : 0;
        var stars = Number(repo.stargazers_count || 0) * 12;
        var topics = (repo.topics || []).length * 4;
        var preferred = /portfolio|iot|esp32|system|student|management|barangay|php|mysql|python|javascript/i.test([
            repo.name,
            repo.description,
            (repo.topics || []).join(' ')
        ].join(' ')) ? 24 : 0;
        return activity + hasDemo + stars + topics + preferred;
    }

    function renderProjects(host, repos) {
        host.innerHTML = repos.map(function (repo, index) {
            var name = repo.name || 'Untitled Repository';
            var description = repo.description || 'A GitHub repository by Redjan Phil S. Visitacion showcasing practical development work.';
            var language = repo.language || inferLanguage(repo);
            var stack = getStack(repo, language);
            var demo = normalizeDemoUrl(repo.homepage);
            var source = repo.html_url || profileUrl;
            var updated = repo.updated_at ? formatDate(repo.updated_at) : 'Recently';
            var stars = Number(repo.stargazers_count || 0);
            var thumbStyle = thumbnailGradient(index, language);

            return [
                '<article class="project-card reveal">',
                    '<div class="project-thumb" style="' + thumbStyle + '">',
                        '<strong>' + escapeHtml(prettyName(name)) + '</strong>',
                    '</div>',
                    '<div class="project-body">',
                        '<div class="project-meta">',
                            '<span><i class="fa-solid fa-star"></i>' + stars + '</span>',
                            '<span><i class="fa-solid fa-code"></i>' + escapeHtml(language) + '</span>',
                            '<span><i class="fa-regular fa-clock"></i>' + escapeHtml(updated) + '</span>',
                        '</div>',
                        '<h3>' + escapeHtml(prettyName(name)) + '</h3>',
                        '<p>' + escapeHtml(description) + '</p>',
                        '<div class="project-badges">' + stack.map(function (item) {
                            return '<span>' + escapeHtml(item) + '</span>';
                        }).join('') + '</div>',
                        '<div class="project-actions">',
                            '<a href="' + escapeAttr(source) + '" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-github"></i>View Source</a>',
                            demo ? '<a href="' + escapeAttr(demo) + '" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-arrow-up-right-from-square"></i>Live Demo</a>' : '<a href="' + escapeAttr(source) + '" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-eye"></i>Repository</a>',
                        '</div>',
                    '</div>',
                '</article>'
            ].join('');
        }).join('');

        if (typeof initReveal === 'function') initReveal();
    }

    function getStack(repo, language) {
        var text = [
            repo.name || '',
            repo.description || '',
            (repo.topics || []).join(' '),
            language || ''
        ].join(' ').toLowerCase();
        var stack = [];
        var checks = [
            ['HTML', /html/],
            ['CSS', /css|tailwind|bootstrap/],
            ['JavaScript', /javascript|js|node/],
            ['PHP', /php/],
            ['MySQL', /mysql|sql|database/],
            ['Python', /python/],
            ['Firebase', /firebase/],
            ['ESP32', /esp32|iot|arduino|embedded|sensor/],
            ['UI/UX', /ui|ux|design|portfolio/]
        ];

        checks.forEach(function (check) {
            if (check[1].test(text)) stack.push(check[0]);
        });

        if (language && stack.indexOf(language) === -1) stack.unshift(language);
        if (!stack.length) stack = ['GitHub', 'Web Project'];
        return stack.slice(0, 5);
    }

    function inferLanguage(repo) {
        var text = ((repo.name || '') + ' ' + (repo.description || '')).toLowerCase();
        if (/php|mysql|system/.test(text)) return 'PHP';
        if (/python|ai|automation/.test(text)) return 'Python';
        if (/esp32|iot|arduino/.test(text)) return 'C++';
        if (/html|css|portfolio|website|web/.test(text)) return 'JavaScript';
        return 'Repository';
    }

    function normalizeDemoUrl(url) {
        if (!url || typeof url !== 'string') return '';
        var trimmed = url.trim();
        if (!trimmed || trimmed === profileUrl) return '';
        if (!/^https?:\/\//i.test(trimmed)) return 'https://' + trimmed;
        return trimmed;
    }

    function thumbnailGradient(index, language) {
        var gradients = [
            'linear-gradient(135deg, rgba(59,130,246,.86), rgba(239,68,68,.72))',
            'linear-gradient(135deg, rgba(34,197,94,.78), rgba(59,130,246,.72))',
            'linear-gradient(135deg, rgba(249,115,22,.78), rgba(239,68,68,.72))',
            'linear-gradient(135deg, rgba(14,165,233,.78), rgba(99,102,241,.72))',
            'linear-gradient(135deg, rgba(168,85,247,.76), rgba(59,130,246,.72))',
            'linear-gradient(135deg, rgba(20,184,166,.76), rgba(239,68,68,.66))'
        ];
        var accent = gradients[index % gradients.length];
        var badge = encodeURIComponent(language || 'Project');
        return 'background:' + accent + ', url("https://placehold.co/800x480/111827/ffffff?text=' + badge + '"); background-size:cover; background-position:center;';
    }

    function prettyName(name) {
        return String(name || '')
            .replace(/[-_]+/g, ' ')
            .replace(/\b\w/g, function (letter) { return letter.toUpperCase(); });
    }

    function normalizeTopic(topic) {
        var map = {
            js: 'JavaScript',
            mysql: 'MySQL',
            esp32: 'ESP32',
            iot: 'IoT',
            ui: 'UI/UX',
            ux: 'UI/UX'
        };
        var key = String(topic || '').toLowerCase();
        return map[key] || prettyName(key);
    }

    function isStackTopic(topic) {
        return /JavaScript|PHP|Python|MySQL|ESP32|IoT|CSS|HTML|Firebase|Bootstrap|Arduino|UI\/UX/i.test(topic);
    }

    function formatDate(value) {
        try {
            return new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(value));
        } catch (error) {
            return 'Recently';
        }
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[char];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#96;');
    }
})();
