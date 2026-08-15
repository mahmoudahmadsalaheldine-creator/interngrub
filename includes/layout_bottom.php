   </div>
</div>
</div>

<script src="<?= BASE_URL ?>/assets/js/main.v2.js"></script>
<script src="<?= BASE_URL ?>/assets/js/modal-validate.js"></script>

<?php if (($role ?? '') === 'admin'): ?>
    <?php if (empty($showAddInternBtn)): ?>
        <?php
            // Shared "Add intern" modal so the header's "+ New intern" button works from
            // any admin/manager page, not just admin/interns.php (which renders its own
            // copy of this same modal and sets $showAddInternBtn to avoid an ID clash).
            $__layoutBottomDepartments = getDB()->query("SELECT * FROM department ORDER BY department_name")->fetchAll();
        ?>
        <div class="modal-overlay" id="addInternModal" style="display:none;">
            <div class="modal" style="width:560px;">
                <div class="modal-header">
                    <div>
                        <div class="modal-title">Add intern</div>
                        <div class="modal-subtitle">Create a new intern profile and account</div>
                    </div>
                    <button class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                </div>
                <form method="POST" action="<?= BASE_URL ?>/admin/interns.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <div class="modal-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Full name <span style="color:#DC2626;">*</span></label>
                                <input type="text" name="full_name" class="form-control" placeholder="e.g. Layla Haddad" required data-val-required="Full name" data-val-maxlen="100" data-val-label="Full name">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Username <span style="color:#DC2626;">*</span></label>
                                <input type="text" id="lb_un" name="username" class="form-control" placeholder="e.g. layla.h" required autocomplete="off" data-val-username>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                                <span>Password <span style="color:#DC2626;">*</span></span>
                                <button type="button" onclick="suggestPwFromUsername('lb_un','lb_pw')" style="font-size:11px;color:var(--purple);background:none;border:none;cursor:pointer;font-weight:600;">⚡ Suggest password</button>
                            </label>
                            <div style="position:relative;">
                                <input type="password" id="lb_pw" name="password" class="form-control" required placeholder="Min 8 chars" style="padding-right:38px;" oninput="updatePwStrength(this.value,'lb_strength')" autocomplete="new-password" data-val-required="Password" data-val-minlen="8" data-val-label="Password">
                                <button type="button" onclick="toggleEye('lb_pw',this)" tabindex="-1" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted-dark);padding:0;line-height:1;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                            <div id="lb_strength" style="height:4px;border-radius:4px;background:#E5E7EB;margin-top:6px;overflow:hidden;"><div style="height:100%;width:0;border-radius:4px;transition:width .2s,background .2s;"></div></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Email <span style="color:var(--muted-lightest);font-weight:400;">(optional)</span></label>
                                <input type="email" name="email" class="form-control" placeholder="name@interngrub.co" data-val-email data-val-label="Email">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Phone</label>
                                <input type="text" name="phone" class="form-control" placeholder="+961 XX XXX XXX">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Department</label>
                                <select name="department_id" class="form-control">
                                    <option value="">Select</option>
                                    <?php foreach ($__layoutBottomDepartments as $d): ?>
                                        <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Position</label>
                                <input type="text" name="position" class="form-control" placeholder="e.g. SWE Intern">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Start date</label>
                                <input type="date" name="start_date" class="form-control" data-val-date data-val-label="Start date">
                            </div>
                            <div class="form-group">
                                <label class="form-label">End date <span style="color:#DC2626;">*</span></label>
                                <input type="date" name="end_date" class="form-control" required data-val-required="End date" data-val-date data-val-label="End date">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Probation end date <span style="font-size:11px;color:#94A3B8;font-weight:400;">(optional)</span></label>
                            <input type="date" name="probation_end_date" class="form-control" data-val-date data-val-label="Probation end date">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addInternModal')">Cancel</button>
                        <button type="submit" name="add_intern" value="1" class="btn btn-primary">Create intern</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
(function() {
    var ls = document.getElementById('liveSearch');
    if (!ls) return;
    var tbl   = ls.dataset.target ? document.getElementById(ls.dataset.target) : null;
    var cards = ls.dataset.cards  ? document.getElementById(ls.dataset.cards)  : null;
    var also  = ls.dataset.also   ? ls.dataset.also.split(',').map(function(id) { return document.getElementById(id.trim()); }).filter(Boolean) : [];
    if (!tbl && !cards && !also.length) return;
    ls.addEventListener('input', function() {
        var q = ls.value.trim().toLowerCase();
        if (tbl) {
            tbl.querySelectorAll('tbody tr').forEach(function(row) {
                row.style.display = (!q || row.textContent.toLowerCase().includes(q)) ? '' : 'none';
            });
        }
        also.forEach(function(extraTbl) {
            extraTbl.querySelectorAll('tbody tr').forEach(function(row) {
                row.style.display = (!q || row.textContent.toLowerCase().includes(q)) ? '' : 'none';
            });
        });
        if (cards) {
            cards.querySelectorAll('.intern-card').forEach(function(card) {
                card.style.display = (!q || card.textContent.toLowerCase().includes(q)) ? '' : 'none';
            });
        }
    });
})();

(function() {
    var inp = document.getElementById('globalSearchInput');
    var drop = document.getElementById('globalSearchDropdown');
    if (!inp || !drop) return;

    var timer = null;
    var BASE = '<?= BASE_URL ?>';

    inp.addEventListener('input', function() {
        clearTimeout(timer);
        var q = inp.value.trim();
        if (q.length < 2) { drop.style.display = 'none'; drop.innerHTML = ''; return; }
        timer = setTimeout(function() { doSearch(q); }, 220);
    });

    inp.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { drop.style.display = 'none'; inp.blur(); }
    });

    document.addEventListener('click', function(e) {
        if (!document.getElementById('globalSearchWrap').contains(e.target)) {
            drop.style.display = 'none';
        }
    });

    function doSearch(q) {
        fetch(BASE + '/admin/search_ajax.php?q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(results) {
                if (!results.length) {
                    drop.innerHTML = '<div style="padding:14px 16px;font-size:13px;color:#64748B;">No results for "<strong>' + escHtml(q) + '</strong>"</div>';
                    drop.style.display = 'block';
                    return;
                }
                var html = '';
                var lastType = null;
                results.forEach(function(r) {
                    if (r.type !== lastType) {
                        html += '<div style="padding:6px 14px 2px;font-size:10px;font-weight:700;letter-spacing:.08em;color:#94A3B8;text-transform:uppercase;">'
                              + (r.type === 'intern' ? 'Interns' : 'Tasks') + '</div>';
                        lastType = r.type;
                    }
                    var avatar = r.type === 'intern'
                        ? '<div style="width:32px;height:32px;border-radius:8px;background:#DB2777;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">' + escHtml((r.initials||'?').slice(0,2)) + '</div>'
                        : '<div style="width:32px;height:32px;border-radius:8px;background:#EDE8F5;color:#7B5EA7;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;letter-spacing:-.02em;">TASK</div>';
                    html += '<a href="' + escHtml(r.url) + '" style="display:flex;align-items:center;gap:10px;padding:8px 14px;text-decoration:none;transition:background .1s;" '
                          + 'onmouseenter="this.style.background=\'#F8FAFC\'" onmouseleave="this.style.background=\'\'">'
                          + avatar
                          + '<div style="min-width:0;"><div style="font-size:13px;font-weight:600;color:#1E293B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(r.label) + '</div>'
                          + (r.sub ? '<div style="font-size:11px;color:#64748B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(r.sub) + '</div>' : '')
                          + '</div></a>';
                });
                drop.innerHTML = html;
                drop.style.display = 'block';
            })
            .catch(function(){ drop.style.display = 'none'; });
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>
<script>
function suggestPw(pwId, cpwId) {
    var c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%&*';
    var pw = '';
    for (var i = 0; i < 12; i++) pw += c[Math.floor(Math.random() * c.length)];
    var f = document.getElementById(pwId), s = document.getElementById(cpwId);
    f.value = pw; f.type = 'text';
    if (s) { s.value = pw; s.type = 'text'; }
    var eyeSvgOff = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    [f, s].forEach(function(el){ if (!el) return; var btn = el.parentElement && el.parentElement.querySelector('button[onclick^="toggleEye"]'); if (btn) btn.innerHTML = eyeSvgOff; });
}
function suggestPwFromUsername(unId, pwId, strengthId) {
    var un = (document.getElementById(unId) ? document.getElementById(unId).value.trim() : '') || 'intern';
    var base = un.charAt(0).toUpperCase() + un.slice(1).replace(/[^a-zA-Z0-9]/g, '');
    var suffix = Math.floor(100 + Math.random() * 900);
    var specials = ['!', '@', '#', '$', '&'];
    var pw = base + suffix + specials[Math.floor(Math.random() * specials.length)];
    var f = document.getElementById(pwId);
    f.value = pw; f.type = 'text';
    var eyeSvgOff = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    var btn = f.parentElement && f.parentElement.querySelector('button[onclick^="toggleEye"]');
    if (btn) btn.innerHTML = eyeSvgOff;
    if (strengthId) updatePwStrength(pw, strengthId);
}
function updatePwStrength(pw, barId) {
    var bar = document.getElementById(barId);
    if (!bar) return;
    var fill = bar.querySelector('div');
    if (!fill) return;
    var score = 0;
    if (pw.length >= 8) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    var colors = ['#DC2626','#F59E0B','#16A34A','#7B5EA7'];
    var widths = ['25%','50%','75%','100%'];
    fill.style.width = pw.length === 0 ? '0' : widths[score - 1] || '25%';
    fill.style.background = pw.length === 0 ? '' : colors[score - 1] || '#DC2626';
}
function toggleEye(inputId, btn) {
    var f = document.getElementById(inputId);
    var show = f.type === 'password';
    f.type = show ? 'text' : 'password';
    btn.innerHTML = show
        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
        : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}
</script>

<script>
// Modal close delegation — handles all .modal-close buttons regardless of onclick
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.modal-close');
    if (!btn) return;
    var overlay = btn.closest('.modal-overlay');
    if (overlay) overlay.style.display = 'none';
});
</script>

<?php $__toast = getToast(); ?>
<div id="toast-container"></div>
<script>
(function () {
    var container = document.getElementById('toast-container');
    var ICONS = {
        success: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>',
        error:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        info:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };
    window.showToast = function (message, type, duration) {
        type     = type     || 'success';
        duration = duration || 3500;
        var t = document.createElement('div');
        t.className = 'toast toast-' + type;
        t.innerHTML =
            '<div class="toast-icon">' + (ICONS[type] || ICONS.info) + '</div>' +
            '<div class="toast-body"><div class="toast-message">' + message + '</div></div>' +
            '<button class="toast-close" aria-label="Dismiss"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
        function dismiss() {
            if (t._out) return;
            t._out = true;
            t.classList.add('toast-out');
            setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 220);
        }
        t.querySelector('.toast-close').addEventListener('click', dismiss);
        container.appendChild(t);
        setTimeout(dismiss, duration);
    };
})();
</script>
<?php if ($__toast): ?>
<script>document.addEventListener('DOMContentLoaded', function(){ showToast(<?= json_encode($__toast['message']) ?>, <?= json_encode($__toast['type']) ?>); });</script>
<?php endif; ?>
<?php if (!empty($success)): ?>
<script>document.addEventListener('DOMContentLoaded', function(){ showToast(<?= json_encode($success) ?>, 'success'); });</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
<script>document.addEventListener('DOMContentLoaded', function(){ showToast(<?= json_encode($error) ?>, 'error'); });</script>
<?php endif; ?>

<?php if (($role ?? '') === 'admin' || ($role ?? '') === 'manager'): ?>
<!-- Admin AI Chat Widget -->
<div id="adminChat" style="position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;align-items:flex-end;gap:10px;">
    <button id="adminChatToggle" onclick="toggleAdminChat()" title="AI Assistant"
        style="width:52px;height:52px;border-radius:50%;background:#7B5EA7;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(123,94,167,.40);transition:transform .15s;">
        <svg id="adminChatIconOpen" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="7" width="18" height="13" rx="3"/>
            <circle cx="8.5" cy="13.5" r="1.5" fill="#fff" stroke="none"/>
            <circle cx="15.5" cy="13.5" r="1.5" fill="#fff" stroke="none"/>
            <path d="M9 17h6"/>
            <line x1="12" y1="7" x2="12" y2="3.5"/>
            <circle cx="12" cy="3" r="1.2" fill="#fff" stroke="none"/>
        </svg>
        <svg id="adminChatIconClose" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" style="display:none;">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
    </button>
    <div id="adminChatPanel" style="display:none;width:340px;background:#fff;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.14);border:1.5px solid var(--border);overflow:hidden;flex-direction:column;">
        <div style="background:#7B5EA7;padding:14px 18px;display:flex;align-items:center;gap:10px;">
            <div style="width:36px;height:36px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <img src="<?= BASE_URL ?>/assets/mini-logo.png" style="width:26px;height:26px;object-fit:contain;">
            </div>
            <div>
                <div style="color:#fff;font-size:13.5px;font-weight:700;">AI Assistant</div>
                <div style="color:rgba(255,255,255,.6);font-size:11px;">Ask anything about interns &amp; platform data</div>
            </div>
        </div>
        <div id="adminChatMessages" style="flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;min-height:180px;max-height:320px;">
            <div style="font-size:12px;color:#94A3B8;text-align:center;">Hi! Ask me anything about your interns, tasks, or attendance.</div>
            <div id="adminChatSuggestions" style="display:flex;flex-direction:column;gap:6px;margin-top:4px;">
                <button onclick="adminSuggest('How many active interns do we have?')"           class="hr-suggest-btn">How many active interns do we have?</button>
                <button onclick="adminSuggest('How many open tasks are there?')"                class="hr-suggest-btn">How many open tasks are there?</button>
                <button onclick="adminSuggest('Who are the active interns and their departments?')" class="hr-suggest-btn">Who are the active interns and their departments?</button>
                <button onclick="adminSuggest('How many interviews are scheduled this week?')"  class="hr-suggest-btn">How many interviews are scheduled this week?</button>
            </div>
        </div>
        <div style="padding:10px 12px;border-top:1.5px solid var(--border);display:flex;gap:8px;background:#fff;">
            <input id="adminChatInput" type="text" placeholder="Ask a question…"
                style="flex:1;border:1.5px solid var(--border);border-radius:8px;padding:8px 12px;font-size:13px;outline:none;color:var(--navy);background:var(--bg);"
                onkeydown="if(event.key==='Enter')sendAdminChat()">
            <button id="adminChatSend" onclick="sendAdminChat()"
                style="background:#7B5EA7;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer;font-weight:600;white-space:nowrap;">Send</button>
        </div>
    </div>
</div>
<script>
function toggleAdminChat() {
    var panel  = document.getElementById('adminChatPanel');
    var open   = document.getElementById('adminChatIconOpen');
    var close  = document.getElementById('adminChatIconClose');
    var toggle = document.getElementById('adminChatToggle');
    var visible = panel.style.display !== 'none';
    if (visible) {
        panel.classList.remove('ai-panel-enter');
        panel.classList.add('ai-panel-exit');
        setTimeout(function() {
            panel.style.display = 'none';
            panel.classList.remove('ai-panel-exit');
        }, 170);
        open.style.display  = 'flex';
        close.style.display = 'none';
        toggle.classList.remove('panel-open');
    } else {
        panel.style.display = 'flex';
        panel.style.flexDirection = 'column';
        void panel.offsetWidth;
        panel.classList.remove('ai-panel-exit');
        panel.classList.add('ai-panel-enter');
        open.style.display  = 'none';
        close.style.display = 'flex';
        toggle.classList.add('panel-open');
        toggle.classList.add('ai-btn-bounce');
        setTimeout(function() { toggle.classList.remove('ai-btn-bounce'); }, 300);
    }
}
function adminSuggest(q) {
    var sug = document.getElementById('adminChatSuggestions');
    if (sug) sug.remove();
    document.getElementById('adminChatInput').value = q;
    sendAdminChat();
}
function sendAdminChat() {
    var sug = document.getElementById('adminChatSuggestions');
    if (sug) sug.remove();
    var input   = document.getElementById('adminChatInput');
    var q       = input.value.trim();
    if (!q) return;
    addAdminChatBubble(q, 'user');
    input.value = '';
    document.getElementById('adminChatSend').disabled = true;
    var thinking = addAdminChatBubble('Thinking…', 'ai-thinking', true);
    var fd = new FormData();
    fd.append('question', q);
    fetch('<?= BASE_URL ?>/hr/api_chat.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            thinking.remove();
            document.getElementById('adminChatSend').disabled = false;
            if (d.error) { addAdminChatBubble(d.error, 'ai-err'); return; }
            addAdminChatBubble(d.answer, 'ai');
        })
        .catch(function() {
            thinking.remove();
            document.getElementById('adminChatSend').disabled = false;
            addAdminChatBubble('Request failed. Please try again.', 'ai-err');
        });
}
function addAdminChatBubble(text, type, isTemp) {
    var box = document.getElementById('adminChatMessages');
    var el  = document.createElement('div');
    var isUser = type === 'user';
    el.style.cssText = 'font-size:12.5px;padding:9px 12px;border-radius:10px;max-width:90%;white-space:pre-wrap;line-height:1.5;'
        + (isUser
            ? 'background:#7B5EA7;color:#fff;align-self:flex-end;'
            : type === 'ai-err'
                ? 'background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;'
                : 'background:#F8FAFC;color:var(--navy);border:1.5px solid var(--border);');
    el.textContent = text;
    box.appendChild(el);
    box.scrollTop = box.scrollHeight;
    return el;
}
</script>
<?php endif; ?>

<?php if (($role ?? '') === 'hr'):
// Probation notification check — runs silently on every HR page load
$_pdo = getDB();
$_expired = $_pdo->query("
    SELECT ip.intern_id, u.full_name
    FROM intern_profile ip
    JOIN user u ON ip.user_id = u.user_id
    WHERE ip.probation_end_date IS NOT NULL
      AND ip.probation_end_date <= CURDATE()
      AND ip.internship_status = 'active'
      AND ip.probation_notified = 0
")->fetchAll();
if (!empty($_expired)) {
    $_hrUsers = $_pdo->query("SELECT user_id FROM user WHERE role='hr' AND status='active'")->fetchAll();
    foreach ($_expired as $_intern) {
        foreach ($_hrUsers as $_hr) {
            $_pdo->prepare("INSERT INTO notification (user_id, message, type) VALUES (?, ?, 'general')")
                ->execute([$_hr['user_id'], 'Probation period ended for ' . $_intern['full_name'] . '. Please prepare the internship agreement.']);
        }
        $_pdo->prepare("UPDATE intern_profile SET probation_notified=1 WHERE intern_id=?")->execute([$_intern['intern_id']]);
    }
}
unset($_pdo, $_expired, $_hrUsers, $_intern, $_hr);
?>
<!-- HR AI Chat Widget -->
<div id="hrChat" style="position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;align-items:flex-end;gap:10px;">
    <button id="hrChatToggle" onclick="toggleHrChat()" title="HR Assistant"
        style="width:52px;height:52px;border-radius:50%;background:#7B5EA7;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(123,94,167,.40);transition:transform .15s;">
        <svg id="hrChatIconOpen" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="7" width="18" height="13" rx="3"/>
            <circle cx="8.5" cy="13.5" r="1.5" fill="#fff" stroke="none"/>
            <circle cx="15.5" cy="13.5" r="1.5" fill="#fff" stroke="none"/>
            <path d="M9 17h6"/>
            <line x1="12" y1="7" x2="12" y2="3.5"/>
            <circle cx="12" cy="3" r="1.2" fill="#fff" stroke="none"/>
        </svg>
        <svg id="hrChatIconClose" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" style="display:none;">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
    </button>
    <div id="hrChatPanel" style="display:none;width:340px;background:#fff;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.14);border:1.5px solid var(--border);overflow:hidden;flex-direction:column;">
        <div style="background:#7B5EA7;padding:14px 18px;display:flex;align-items:center;gap:10px;">
            <div style="width:36px;height:36px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <img src="<?= BASE_URL ?>/assets/mini-logo.png" style="width:26px;height:26px;object-fit:contain;">
            </div>
            <div>
                <div style="color:#fff;font-size:13.5px;font-weight:700;">HR Assistant</div>
                <div style="color:rgba(255,255,255,.6);font-size:11px;">Ask anything about your recruitment data</div>
            </div>
        </div>
        <div id="hrChatMessages" style="height:320px;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;">
            <div style="font-size:12.5px;color:var(--muted);background:#F8FAFC;border-radius:10px;padding:10px 12px;">
                Hi! Ask me anything about your candidates, applications, interviews, or interns.
            </div>
            <div id="hrChatSuggestions" style="display:flex;flex-direction:column;gap:6px;margin-top:2px;">
                <div style="font-size:11px;color:#94A3B8;font-weight:600;letter-spacing:.04em;text-transform:uppercase;padding:0 2px;">Try asking</div>
                <button onclick="hrSuggest('How many open applications do we have?')"      class="hr-suggest-btn">How many open applications do we have?</button>
                <button onclick="hrSuggest('Who has an interview scheduled this week?')"   class="hr-suggest-btn">Who has an interview scheduled this week?</button>
                <button onclick="hrSuggest('How many candidates were accepted this month?')" class="hr-suggest-btn">How many candidates were accepted this month?</button>
                <button onclick="hrSuggest('What are the currently open job postings?')"   class="hr-suggest-btn">What are the currently open job postings?</button>
            </div>
        </div>
        <div style="padding:12px 14px;border-top:1.5px solid var(--border);display:flex;gap:8px;background:#FAFBFC;">
            <input id="hrChatInput" type="text" placeholder="Ask a question..."
                style="flex:1;border:1.5px solid var(--border);border-radius:9px;padding:8px 12px;font-size:13px;outline:none;color:var(--navy);"
                onkeydown="if(event.key==='Enter')sendHrChat()">
            <button onclick="sendHrChat()" id="hrChatSend"
                style="width:36px;height:36px;border-radius:9px;border:none;background:#7B5EA7;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
        </div>
    </div>
</div>
<script>
function toggleHrChat() {
    var panel  = document.getElementById('hrChatPanel');
    var open   = document.getElementById('hrChatIconOpen');
    var close  = document.getElementById('hrChatIconClose');
    var toggle = document.getElementById('hrChatToggle');
    var visible = panel.style.display !== 'none';
    if (visible) {
        panel.classList.remove('ai-panel-enter');
        panel.classList.add('ai-panel-exit');
        setTimeout(function() {
            panel.style.display = 'none';
            panel.classList.remove('ai-panel-exit');
        }, 170);
        open.style.display  = 'flex';
        close.style.display = 'none';
        toggle.classList.remove('panel-open');
    } else {
        panel.style.display = 'flex';
        panel.style.flexDirection = 'column';
        void panel.offsetWidth;
        panel.classList.remove('ai-panel-exit');
        panel.classList.add('ai-panel-enter');
        open.style.display  = 'none';
        close.style.display = 'flex';
        toggle.classList.add('panel-open');
        toggle.classList.add('ai-btn-bounce');
        setTimeout(function() { toggle.classList.remove('ai-btn-bounce'); }, 300);
    }
}
function hrSuggest(q) {
    var sug = document.getElementById('hrChatSuggestions');
    if (sug) sug.remove();
    document.getElementById('hrChatInput').value = q;
    sendHrChat();
}
function sendHrChat() {
    var sug = document.getElementById('hrChatSuggestions');
    if (sug) sug.remove();
    var input = document.getElementById('hrChatInput');
    var q = input.value.trim();
    if (!q) return;
    input.value = '';
    addChatBubble(q, 'user');
    document.getElementById('hrChatSend').disabled = true;
    var thinking = addChatBubble('Thinking…', 'ai', true);
    var fd = new FormData();
    fd.append('question', q);
    fetch('<?= BASE_URL ?>/hr/api_chat.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            thinking.remove();
            document.getElementById('hrChatSend').disabled = false;
            if (data.error) { addChatBubble(data.error, 'ai-err'); }
            else { addChatBubble(data.answer, 'ai'); }
        })
        .catch(function() {
            thinking.remove();
            document.getElementById('hrChatSend').disabled = false;
            addChatBubble('Request failed. Please try again.', 'ai-err');
        });
}
function addChatBubble(text, type, isTemp) {
    var box = document.getElementById('hrChatMessages');
    var el  = document.createElement('div');
    var isUser = type === 'user';
    el.style.cssText = 'font-size:12.5px;padding:9px 12px;border-radius:10px;max-width:90%;white-space:pre-wrap;line-height:1.5;'
        + (isUser
            ? 'background:#7B5EA7;color:#fff;align-self:flex-end;'
            : type === 'ai-err'
                ? 'background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;'
                : 'background:#F8FAFC;color:var(--navy);border:1.5px solid var(--border);');
    el.textContent = text;
    el.classList.add('ai-bubble-in');
    box.appendChild(el);
    box.scrollTop = box.scrollHeight;
    return el;
}
</script>
<?php endif; ?>
</body>
</html>




