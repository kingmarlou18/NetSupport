    </main>
</div>

<nav class="mobile-dock" aria-label="Quick navigation">
    <?php foreach ($dock as [$href, $icon, $label, $match]): ?>
        <a href="<?= h($href) ?>" class="dock-link <?= in_array($page, $match, true) ? 'active' : '' ?>"
           <?= in_array($page, $match, true) ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= h($icon) ?>" aria-hidden="true"></i>
            <?php if ($href === 'notifications.php' && $unreadNotifications > 0): ?>
                <span class="dock-count"><?= $unreadNotifications ?><span class="visually-hidden"> unread</span></span>
            <?php endif; ?>
            <span><?= h($label) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<div class="ns-ai-widget" id="nsAiWidget" data-csrf="<?= h(csrf()) ?>">
    <section class="ns-ai-panel" id="nsAiPanel" aria-label="NetSupport AI assistant" hidden>
        <header class="ns-ai-header">
            <div class="ns-ai-brand"><span class="ns-ai-mark"><i class="bi bi-stars" aria-hidden="true"></i></span><div><strong>NetSupport AI</strong><small>System assistant</small></div></div>
            <button class="ns-ai-icon-btn" id="nsAiClose" type="button" aria-label="Close assistant"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>
        <div class="ns-ai-controls">
            <label for="nsAiAgent">Assistant mode</label>
            <select id="nsAiAgent">
                <option value="operations">Operations assistant</option>
                <option value="dispatch">Dispatch and crew assistant</option>
                <option value="reports">Reports and performance assistant</option>
                <option value="gis">GIS and barangay assistant</option>
            </select>
        </div>
        <div class="ns-ai-messages" id="nsAiMessages" aria-live="polite" aria-relevant="additions">
            <div class="ns-ai-message assistant">Hi! Ask about NetSupport operations, assignments, reports, or barangay maps. Answers use a limited summary of system data.</div>
        </div>
        <form class="ns-ai-form" id="nsAiForm">
            <label class="visually-hidden" for="nsAiInput">Message the assistant</label>
            <textarea id="nsAiInput" rows="1" maxlength="2000" placeholder="Ask a question…" required></textarea>
            <button type="submit" aria-label="Send message"><i class="bi bi-send-fill" aria-hidden="true"></i></button>
        </form>
        <p class="ns-ai-disclosure">Your question and limited system counts are processed by the local Ollama model on this computer.</p>
    </section>
    <button class="ns-ai-launcher" id="nsAiOpen" type="button" aria-label="Open NetSupport AI assistant" title="NetSupport AI assistant" aria-controls="nsAiPanel" aria-expanded="false"><i class="bi bi-stars" aria-hidden="true"></i><span class="visually-hidden">AI Assistant</span></button>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var aiRoot = document.getElementById('nsAiWidget');
    if (aiRoot) {
        var aiPanel = document.getElementById('nsAiPanel');
        var aiOpen = document.getElementById('nsAiOpen');
        var aiClose = document.getElementById('nsAiClose');
        var aiForm = document.getElementById('nsAiForm');
        var aiInput = document.getElementById('nsAiInput');
        var aiMessages = document.getElementById('nsAiMessages');
        var aiAgent = document.getElementById('nsAiAgent');
        function setAiOpen(open) {
            aiPanel.hidden = !open;
            aiOpen.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) aiInput.focus();
        }
        function addAiMessage(text, kind) {
            var node = document.createElement('div');
            node.className = 'ns-ai-message ' + kind;
            node.textContent = text;
            aiMessages.appendChild(node);
            aiMessages.scrollTop = aiMessages.scrollHeight;
            return node;
        }
        aiOpen.addEventListener('click', function () { setAiOpen(aiPanel.hidden); });
        aiClose.addEventListener('click', function () { setAiOpen(false); aiOpen.focus(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !aiPanel.hidden) setAiOpen(false); });
        aiForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            var message = aiInput.value.trim();
            if (!message) return;
            addAiMessage(message, 'user');
            aiInput.value = '';
            aiInput.disabled = true;
            var sendButton = aiForm.querySelector('button');
            sendButton.disabled = true;
            var pending = addAiMessage('Thinking…', 'assistant pending');
            try {
                var response = await fetch('ai_assistant.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': aiRoot.dataset.csrf},
                    body: JSON.stringify({agent: aiAgent.value, message: message})
                });
                var data = await response.json();
                pending.textContent = data.reply || data.error || 'The assistant could not respond.';
                pending.classList.remove('pending');
            } catch (error) {
                pending.textContent = 'Could not reach the assistant. Please try again.';
                pending.classList.remove('pending');
            } finally {
                aiInput.disabled = false;
                sendButton.disabled = false;
                aiInput.focus();
                aiMessages.scrollTop = aiMessages.scrollHeight;
            }
        });
    }

    var toggle = document.getElementById('sidebarToggle');
    var rail   = document.getElementById('appSidebar');
    var scrim  = document.getElementById('sidebarScrim');
    if (!toggle || !rail) return;

    var phone = window.matchMedia('(max-width: 991.98px)');

    /* The button does two different jobs. On a wide screen it shrinks the rail
       to icons and remembers that. On a phone there is no room for a rail at
       all, so it opens and closes a drawer instead — and that state is never
       persisted, because nobody wants to land on a page with the menu already
       covering it. */

    function openDrawer() {
        rail.classList.add('is-open');
        scrim.hidden = false;
        // let the element paint before transitioning opacity
        requestAnimationFrame(function () { scrim.classList.add('is-open'); });
        document.body.classList.add('drawer-open');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'Close navigation');
        var first = rail.querySelector('.sidebar-nav-link');
        if (first) first.focus({ preventScroll: true });
    }

    function closeDrawer(returnFocus) {
        rail.classList.remove('is-open');
        scrim.classList.remove('is-open');
        document.body.classList.remove('drawer-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open navigation');
        window.setTimeout(function () {
            if (!rail.classList.contains('is-open')) scrim.hidden = true;
        }, 260);
        if (returnFocus) toggle.focus({ preventScroll: true });
    }

    function applyDesktopState() {
        var collapsed = localStorage.getItem('nss_rail_collapsed') === 'true';
        rail.classList.toggle('collapsed', collapsed);
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
    }

    function syncToViewport() {
        if (phone.matches) {
            closeDrawer(false);
            toggle.setAttribute('aria-label', 'Open navigation');
        } else {
            rail.classList.remove('is-open');
            scrim.classList.remove('is-open');
            scrim.hidden = true;
            document.body.classList.remove('drawer-open');
            applyDesktopState();
        }
    }

    toggle.addEventListener('click', function () {
        if (phone.matches) {
            rail.classList.contains('is-open') ? closeDrawer(true) : openDrawer();
        } else {
            var collapsed = rail.classList.toggle('collapsed');
            localStorage.setItem('nss_rail_collapsed', collapsed);
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Collapse navigation');
        }
    });

    scrim.addEventListener('click', function () { closeDrawer(true); });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && rail.classList.contains('is-open')) closeDrawer(true);
    });

    // Following a link should not leave the drawer hanging open mid-navigation
    rail.addEventListener('click', function (e) {
        if (e.target.closest('a') && phone.matches) closeDrawer(false);
    });

    if (phone.addEventListener) {
        phone.addEventListener('change', syncToViewport);
    } else if (phone.addListener) {
        phone.addListener(syncToViewport);   // Safari < 14
    }

    syncToViewport();
});
</script>
</body>
</html>
