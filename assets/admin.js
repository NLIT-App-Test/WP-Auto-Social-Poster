/* Admun Growth Engine — campaign app (no build step). */
(function () {
  'use strict';

  const root = document.getElementById('age-app');
  if (!root) return;

  /* ---------------------------------------------------------------- helpers */
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pct = (x) => (x == null ? '—' : Math.round(x * 100) + '%');
  const utc = (s) => new Date(String(s).replace(' ', 'T') + 'Z');
  // Show dates in the site's timezone, which is what the posting schedule uses.
  const tz = (() => { try { new Intl.DateTimeFormat([], { timeZone: AGE.tz }); return AGE.tz; } catch (e) { return undefined; } })();
  const day = (s) => utc(s).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', timeZone: tz });
  const when = (s) => utc(s).toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: tz });
  const $ = (sel, el = root) => el.querySelector(sel);
  const $$ = (sel, el = root) => [...el.querySelectorAll(sel)];

  async function api(path, body, method) {
    const res = await fetch(AGE.root + path, {
      method: method || (body ? 'POST' : 'GET'),
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': AGE.nonce },
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || res.statusText);
    return data;
  }

  let toastTimer;
  function toast(msg, bad) {
    let t = document.getElementById('age-toast');
    if (!t) { t = document.createElement('div'); t.id = 'age-toast'; document.body.appendChild(t); }
    t.textContent = msg; t.className = bad ? 'bad show' : 'show';
    clearTimeout(toastTimer); toastTimer = setTimeout(() => (t.className = ''), 5000);
  }

  async function act(btn, fn, okMsg) {
    const label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Working…';
    try { const r = await fn(); if (okMsg) toast(okMsg); return r; }
    catch (e) { toast(e.message, true); throw e; }
    finally { btn.disabled = false; btn.textContent = label; }
  }

  const STATUS = {
    planning: 'Planning', plan_review: 'Plan ready for you', plan_failed: 'Planning failed', active: 'Running', paused: 'Paused', completed: 'Completed',
    planned: 'Planned', queued: 'Queued', producing: 'Agents working', awaiting_approval: 'Needs your approval', scheduled: 'Approved',
    publishing: 'Publishing', live: 'Live', partial: 'Partly published', publish_failed: 'Publish failed', failed: 'Failed', skipped: 'Skipped',
  };
  const badge = (s) => `<span class="age-badge st-${esc(s)}">${esc(STATUS[s] || s)}</span>`;
  const bar = (x) => `<div class="age-bar"><i style="width:${Math.round((x || 0) * 100)}%"></i></div>`;

  /* ---------------------------------------------------------------- routing */
  let pollTimer;
  const route = () => location.hash.replace(/^#\/?/, '').split('/');

  async function render() {
    clearInterval(pollTimer);
    const [page, id] = route();
    root.innerHTML = `<header class="age-top"><h1><a href="#/">Growth Engine</a></h1>
      <nav><a href="#/" class="${!page || page === 'c' ? 'on' : ''}">Campaigns</a><a href="#/new" class="${page === 'new' ? 'on' : ''}">New campaign</a><a href="#/settings" class="${page === 'settings' ? 'on' : ''}">Settings</a></nav></header>
      <main id="age-view"></main>`;
    if (AGE.notice) { toast(AGE.notice); AGE.notice = ''; }
    if (AGE.error) { toast(AGE.error, true); AGE.error = ''; }
    const view = $('#age-view');
    try {
      if (page === 'new') await NewCampaign(view);
      else if (page === 'settings') await SettingsView(view);
      else if (page === 'c' && id) await CampaignView(view, +id);
      else await List(view);
    } catch (e) { view.innerHTML = `<div class="age-card bad">${esc(e.message)}</div>`; }
  }
  window.addEventListener('hashchange', render);
  const poll = (fn, ms = 6000) => { clearInterval(pollTimer); pollTimer = setInterval(() => fn().catch(() => {}), ms); };

  /* ---------------------------------------------------------------- campaign list */
  async function List(el) {
    const [list, s] = await Promise.all([api('campaigns'), api('settings')]);
    const setup = [];
    if (!s.claude || !s.jev) setup.push('Add your Anthropic (Claude) and TypeSafe (Jev) keys');
    if (!s.last_tick || s.now - s.last_tick > 600) setup.push('The background worker has not run recently; WP-Cron needs site traffic or a real cron job');
    el.innerHTML = `${setup.length ? `<div class="age-card warn"><b>Before you start:</b> ${setup.map(esc).join('. ')}. <a href="#/settings">Settings →</a></div>` : ''}
      ${list.length ? list.map((c) => {
        const total = Object.values(c.counts).reduce((a, b) => a + b, 0);
        const live = c.counts.live || 0;
        const waiting = c.counts.awaiting_approval || 0;
        return `<a class="age-card age-campaign" href="#/c/${c.id}">
          <div class="age-campaign-top"><h2>${esc(c.name)}</h2>${badge(c.status)}</div>
          <p class="age-goal"><b>Goal:</b> ${esc(c.goal)}</p>
          <p class="age-muted">${day(c.start_at)} – ${day(c.end_at)} · ${c.per_week}× per week · ${c.account_ids.length} network account(s)</p>
          ${total ? `${bar(live / total)}<p class="age-muted">${live} of ${total} published${waiting ? ` · <b class="age-warn">${waiting} waiting for your approval</b>` : ''}</p>` : ''}
          ${c.status === 'plan_review' ? '<p class="age-warn"><b>The plan is ready — review and approve it.</b></p>' : ''}
        </a>`;
      }).join('') : `<div class="age-card age-empty"><h2>Start your first campaign</h2>
        <p>Tell the agents a topic, the goal you want to reach, and how long to keep posting. They plan it, write it, make the images, check it, and bring each post to you for approval.</p>
        <p><a class="button button-primary button-hero" href="#/new">New campaign</a></p></div>`}`;
    poll(async () => { if (route()[0] === '' || route()[0] === undefined) await List(el); }, 15000);
  }

  /* ---------------------------------------------------------------- new campaign */
  async function NewCampaign(el) {
    const accounts = await api('accounts');
    const end = new Date(); end.setDate(end.getDate() + 28);
    el.innerHTML = `<div class="age-card age-form">
      <h2>What do you want to achieve?</h2>
      <label>Topic <small>what the posts are about</small><textarea id="n-topic" rows="2" placeholder="e.g. Weekly growth tips for small Shopify stores"></textarea></label>
      <label>Goal <small>what should happen because of these posts</small><textarea id="n-goal" rows="2" placeholder="e.g. Get 200 free-trial sign-ups for Weekly Wins by the end of the quarter"></textarea></label>
      <label>Who is it for? <small>optional</small><input id="n-aud" placeholder="e.g. Founders of small e-commerce brands"></label>
      <div class="age-row">
        <label>Start<input id="n-start" type="date" value="${esc(AGE.today)}"></label>
        <label>End<input id="n-end" type="date" value="${end.toISOString().slice(0, 10)}"></label>
        <label>Posts per week<select id="n-per">${[1, 2, 3, 4, 5, 7].map((n) => `<option ${n === 3 ? 'selected' : ''}>${n}</option>`).join('')}</select></label>
        <label>Post at<input id="n-time" type="time" value="09:00"></label>
      </div>
      <label>Post to</label>
      ${accounts.length ? `<div class="age-checks">${accounts.map((a) => `<label><input type="checkbox" class="n-acc" value="${a.id}" ${a.status === 'active' ? 'checked' : 'disabled'}> ${esc(a.label)} <small>${esc(a.platform)}</small></label>`).join('')}</div>`
        : `<p class="age-warn">No social accounts connected yet. <a href="#/settings">Connect one in Settings →</a></p>`}
      <label>Anything the agents should know? <small>optional</small><textarea id="n-notes" rows="2" placeholder="Offers, facts to use, things to avoid, links to include…"></textarea></label>
      <p><button class="button button-primary button-hero" id="n-go">Plan my campaign</button></p>
      <p class="age-muted">The planner agent will split this into a checklist, one article with social posts per date. You approve the plan before anything is written.</p></div>`;
    $('#n-go').onclick = (e) => act(e.target, async () => {
      const r = await api('campaigns', {
        topic: $('#n-topic').value, goal: $('#n-goal').value, audience: $('#n-aud').value, notes: $('#n-notes').value,
        start_date: $('#n-start').value, end_date: $('#n-end').value, per_week: +$('#n-per').value, post_time: $('#n-time').value,
        account_ids: $$('.n-acc:checked').map((x) => +x.value),
      });
      location.hash = '#/c/' + r.id;
    }).catch(() => {});
  }

  /* ---------------------------------------------------------------- campaign */
  let openItems = new Set();

  async function CampaignView(el, id) {
    const c = await api('campaigns/' + id);
    const drawn = el.dataset.drawn === String(id) ? el : null;
    if (drawn && el.querySelector('textarea:focus, input:focus')) return; // don't clobber typing
    el.dataset.drawn = String(id);

    const items = c.items;
    const live = items.filter((i) => i.status === 'live').length;
    const waiting = items.filter((i) => i.status === 'awaiting_approval');
    const working = items.filter((i) => ['queued', 'producing'].includes(i.status));
    const byPlatform = {};
    c.accounts.forEach((a) => (byPlatform[a.platform] ??= []).push(a.label));

    let body = '';
    if (c.status === 'planning') body = `<div class="age-card age-working"><span class="age-spinner"></span> <b>Planner agent</b> is studying the topic and splitting the period into a checklist…</div>`;
    else if (c.status === 'plan_failed') body = `<div class="age-card bad"><b>Planning failed:</b> ${esc(c.error)}<p><button class="button" id="c-replan-now">Try again</button></p></div>`;
    else if (c.status === 'plan_review') body = planReview(c);
    else body = `${waiting.length ? `<h2 class="age-h">Needs your approval (${waiting.length})</h2>${waiting.map((i) => reviewCard(i, c)).join('')}` : ''}
      ${working.length ? `<div class="age-card age-working"><span class="age-spinner"></span> Agents are working on ${working.map((i) => `#${i.seq}`).join(', ')}: ${esc(lastStep(working[0]))}</div>` : ''}
      <h2 class="age-h">Plan & checklist</h2>${planSummary(c)}${checklist(items)}`;

    el.innerHTML = `<div class="age-card">
        <div class="age-campaign-top"><h2>${esc(c.name)}</h2>${badge(c.status)}</div>
        <p class="age-goal"><b>Goal:</b> ${esc(c.goal)}</p>
        <p class="age-muted">${day(c.start_at)} – ${day(c.end_at)} · ${c.per_week}× per week at ${esc(c.post_time)} ·
          ${Object.entries(byPlatform).map(([p, l]) => `${esc(p)} (${l.map(esc).join(', ')})`).join(' · ')}</p>
        ${items.length && c.status !== 'plan_review' ? `${bar(live / items.length)}<p class="age-muted">${live} of ${items.length} published</p>` : ''}
        <p class="age-links">${c.status === 'active' ? '<button class="button-link" id="c-pause">Pause campaign</button>' : ''}
          ${c.status === 'paused' ? '<button class="button-link" id="c-resume">Resume campaign</button>' : ''}
          <button class="button-link age-del" id="c-delete">Delete campaign</button></p>
      </div>${body}`;
    bindCampaign(el, c);
    poll(() => CampaignView(el, id));
  }

  const lastStep = (i) => { const s = i.steps[i.steps.length - 1]; return s ? `${s.agent}: ${s.message}` : 'queued'; };

  function planSummary(c) {
    const p = c.plan || {};
    return `<details class="age-card" ${c.status === 'plan_review' ? 'open' : ''}><summary><b>What the planner understood</b></summary>
      <p>${esc(p.understanding)}</p><h3>Strategy</h3><p>${esc(p.strategy)}</p>
      <div class="age-grid2"><div><h3>How we'll know it's working</h3><ul>${(p.success_signals || []).map((s) => `<li>${esc(s)}</li>`).join('')}</ul></div>
      <div><h3>Keyword focus</h3><p>${(p.keywords || []).map((k) => `<span class="age-chip">${esc(k)}</span>`).join(' ')}</p></div></div></details>`;
  }

  function planReview(c) {
    return `<div class="age-card age-callout"><h2>Your plan is ready</h2>
        <p>The planner split your goal into <b>${c.items.length} pieces</b>. Each becomes an SEO article on your site plus a post for each network, produced a few days ahead and brought to you for approval.</p>
        <p><button class="button button-primary button-hero" id="c-approve">Approve plan</button></p>
        <details><summary>Ask for a different plan</summary><textarea id="c-feedback" rows="3" placeholder="e.g. More beginner topics first, fewer comparisons, include a case study each month"></textarea>
        <p><button class="button" id="c-replan">Re-plan with this feedback</button></p></details></div>
      ${planSummary(c)}${checklist(c.items)}`;
  }

  function checklist(items) {
    return `<div class="age-card"><table class="widefat age-table age-checklist"><thead><tr><th>#</th><th>Date</th><th>Piece</th><th>Status</th><th></th></tr></thead><tbody>
      ${items.map((i) => `<tr data-item="${i.id}">
        <td>${i.seq}</td><td class="age-nowrap">${day(i.publish_at)}</td>
        <td><b>${esc(i.title)}</b><br><small class="age-muted">🔑 ${esc(i.keyword)} — ${esc(i.objective)}</small>
          ${openItems.has(i.id) ? `<div class="age-steps">${i.angle ? `<p><b>Angle:</b> ${esc(i.angle)}</p>` : ''}${i.social && i.social.results && Object.keys(i.social.results).length ? `<p>${Object.values(i.social.results).map((r) => `<b>${esc(r.label)}</b>: ${r.ok ? (r.url ? `<a href="${esc(r.url)}" target="_blank" rel="noopener">posted ↗</a>` : 'posted') : `<span class="age-bad">${esc(r.error)}</span>`}`).join('<br>')}</p>` : ''}${i.steps.map((s) => `<div><time>${new Date(s.at * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</time> <b>${esc(s.agent)}</b> ${esc(s.message)}</div>`).join('')}</div>` : ''}</td>
        <td>${badge(i.status)}${i.error ? `<br><small class="age-bad">${esc(i.error)}</small>` : ''}</td>
        <td class="age-actions">
          <button class="button-link i-toggle">${openItems.has(i.id) ? 'Hide' : 'Details'}</button>
          ${['planned', 'failed'].includes(i.status) ? '<button class="button i-produce">Produce now</button>' : ''}
          ${['partial', 'publish_failed'].includes(i.status) ? '<button class="button i-retry">Retry publish</button>' : ''}
          ${i.post && i.post.url ? `<a class="button" href="${esc(i.post.url)}" target="_blank" rel="noopener">View</a>` : ''}
          ${i.social && i.social.results ? Object.values(i.social.results).filter((r) => r.url).map((r) => `<a href="${esc(r.url)}" target="_blank" rel="noopener" title="${esc(r.label)}">${esc(r.platform)}</a>`).join(' ') : ''}
        </td></tr>`).join('')}</tbody></table></div>`;
  }

  function reviewCard(i, c) {
    const r = i.review || {};
    const verdict = r.pass
      ? `<div class="age-verdict ok">✓ Reviewer approved: matches your goal (${pct(r.goal_match)}), quality ${pct(r.quality)}, SEO ${pct(r.seo)}</div>`
      : `<div class="age-verdict warn">⚠ Reviewer still has concerns after ${i.rounds} round(s): goal match ${pct(r.goal_match)}, quality ${pct(r.quality)}, SEO ${pct(r.seo)}
          ${r.issues && r.issues.length ? `<ul>${r.issues.slice(0, 6).map((x) => `<li>${esc(x)}</li>`).join('')}</ul>` : ''}</div>`;
    const texts = (i.social && i.social.texts) || {};
    return `<div class="age-card age-review" data-item="${i.id}">
      <div class="age-review-head"><span class="age-muted">#${i.seq} · goes live ${when(i.publish_at)}</span></div>
      <div class="age-review-grid">
        <div>${i.image ? `<img src="${esc(i.image)}" alt="" class="age-img">` : `<div class="age-noimg">No image${r.image ? ': ' + esc(r.image) : ''}</div>`}</div>
        <div><h3>${esc(i.title)}</h3><p>${esc(i.post ? i.post.excerpt : '')}</p>
          <p class="age-muted">🔑 ${esc(i.keyword)} · ${i.post ? i.post.words : 0} words</p>
          <p><a href="${esc(i.post.preview)}" target="_blank" rel="noopener" class="button">Preview article</a> <a href="${esc(i.post.edit)}" target="_blank" rel="noopener">Edit in WordPress ↗</a></p>
          ${verdict}
          ${r.scores ? `<div class="age-chips">${Object.entries(r.scores).map(([k, v]) => `<span class="age-chip">${esc(LABELS[k] || k)} ${pct(v.value)}</span>`).join('')}</div>` : ''}
        </div>
      </div>
      <h3>Social posts <small class="age-muted">edit before approving if you like</small></h3>
      <div class="age-socials">${Object.entries(texts).map(([p, t]) => {
        const s = (r.social || {})[p] || {};
        return `<label class="age-social"><span><b>${esc(p)}</b> ${s.score != null ? `<span class="age-chip ${s.ok ? '' : 'risk'}">${pct(s.score)}</span>` : ''}<small class="age-muted i-count">${t.length}${s.limit ? ' / ' + s.limit : ''}</small></span>
          <textarea class="i-social" data-platform="${esc(p)}" rows="6">${esc(t)}</textarea></label>`;
      }).join('')}</div>
      <div class="age-decide">
        <button class="button button-primary button-hero i-approve">Approve — publish ${when(i.publish_at)}</button>
        <details><summary>Request changes</summary><textarea class="i-feedback" rows="2" placeholder="What should the agents change?"></textarea>
          <label class="age-check"><input type="checkbox" class="i-redo"> Also make a new image</label>
          <button class="button i-changes">Send back to the agents</button></details>
        <button class="button-link age-del i-skip">Skip this piece</button>
      </div></div>`;
  }
  const LABELS = { goalFit: 'Goal fit', itemFit: 'On brief', intent: 'Search intent', depth: 'Depth', accuracy: 'Accuracy', readability: 'Readability', answerFirst: 'Answer-first', voice: 'Voice' };

  function bindCampaign(el, c) {
    const on = (sel, fn) => { const b = $(sel, el); if (b) b.onclick = (e) => fn(e.target); };
    on('#c-approve', (b) => act(b, () => api(`campaigns/${c.id}/approve`, {}), 'Plan approved. The agents will start producing content.').then(render));
    on('#c-replan', (b) => act(b, () => api(`campaigns/${c.id}/replan`, { feedback: $('#c-feedback', el).value }), 'Re-planning…').then(render));
    on('#c-replan-now', (b) => act(b, () => api(`campaigns/${c.id}/replan`, { feedback: 'Please try again.' })).then(render));
    on('#c-pause', (b) => act(b, () => api(`campaigns/${c.id}/pause`, { paused: true })).then(render));
    on('#c-resume', (b) => act(b, () => api(`campaigns/${c.id}/pause`, { paused: false })).then(render));
    on('#c-delete', (b) => { if (confirm('Delete this campaign? Published posts stay on your site and networks.')) act(b, () => api(`campaigns/${c.id}`, null, 'DELETE')).then(() => (location.hash = '#/')); });

    el.onclick = (e) => {
      const card = e.target.closest('[data-item]'); if (!card) return;
      const id = +card.dataset.item, t = e.target;
      if (t.classList.contains('i-toggle')) { openItems.has(id) ? openItems.delete(id) : openItems.add(id); CampaignView(el, c.id); }
      if (t.classList.contains('i-produce')) act(t, () => api(`items/${id}/produce`, {}), 'The agents are on it.').then(() => CampaignView(el, c.id));
      if (t.classList.contains('i-retry')) act(t, () => api(`items/${id}/retry`, {}), 'Publishing again…').then(() => CampaignView(el, c.id));
      if (t.classList.contains('i-skip')) { if (confirm('Skip this piece? It will not be published.')) act(t, () => api(`items/${id}/skip`, {})).then(() => CampaignView(el, c.id)); }
      if (t.classList.contains('i-approve')) {
        const social = {}; $$('.i-social', card).forEach((x) => (social[x.dataset.platform] = x.value));
        act(t, () => api(`items/${id}/approve`, { social }), 'Approved. It goes live on schedule.').then(() => { el.dataset.drawn = ''; CampaignView(el, c.id); });
      }
      if (t.classList.contains('i-changes')) {
        act(t, () => api(`items/${id}/changes`, { feedback: $('.i-feedback', card).value, redo_image: $('.i-redo', card).checked }), 'Sent back to the agents.')
          .then(() => { el.dataset.drawn = ''; CampaignView(el, c.id); });
      }
    };
    el.oninput = (e) => {
      if (e.target.classList.contains('i-social')) {
        const n = e.target.closest('.age-social').querySelector('.i-count');
        n.textContent = e.target.value.length + (n.textContent.includes('/') ? ' / ' + n.textContent.split('/')[1].trim() : '');
      }
    };
  }

  /* ---------------------------------------------------------------- settings */
  async function SettingsView(el) {
    const [st, platforms, accounts] = await Promise.all([api('settings'), api('platforms'), api('accounts')]);
    const s = st.settings;
    el.innerHTML = `
      <div class="age-card"><h2>AI keys</h2>
        <div class="age-row"><label>Anthropic key (Claude — planner, writer)<input type="password" id="k-anthropic_key" placeholder="${esc(s.anthropic_key || 'sk-ant-…')}" autocomplete="new-password"></label>
          <label>TypeSafe key (Jev — reviewer)<input type="password" id="k-typesafe_key" placeholder="${esc(s.typesafe_key || '')}" autocomplete="new-password"></label></div>
        <div class="age-row"><label>OpenAI key (media agent — AI images)<input type="password" id="k-openai_key" placeholder="${esc(s.openai_key)}" autocomplete="new-password"></label>
          <label>Pexels key (media fallback — stock photos, free)<input type="password" id="k-pexels_key" placeholder="${esc(s.pexels_key)}" autocomplete="new-password"></label></div>
        <p class="age-muted">Keys are encrypted with your site's salts. Leave blank to keep a saved key; type "-" to remove it.</p>
        <p><button class="button" id="k-test">Test Claude & Jev</button> <span id="k-out"></span></p></div>

      <div class="age-card"><h2>Your brand</h2><p class="age-muted">Every agent uses this.</p>
        ${[['name', 'Brand name'], ['product', 'What you offer'], ['voice', 'Voice & tone'], ['forbidden', 'Never mention'], ['cta_url', 'Main call-to-action link'], ['language', 'Language']]
          .map(([k, l]) => `<label>${l}${['product', 'voice', 'forbidden'].includes(k) ? `<textarea data-brand="${k}" rows="2">${esc(s.brand[k])}</textarea>` : `<input data-brand="${k}" value="${esc(s.brand[k])}">`}</label>`).join('')}
        <div class="age-row"><label>Article length (words)<input type="number" id="o-words" min="500" max="4000" value="${s.words}"></label>
          <label>Reviewer pass bar<select id="o-bar">${[[0.65, 'Relaxed (65%)'], [0.75, 'Standard (75%)'], [0.85, 'Strict (85%)']].map(([v, l]) => `<option value="${v}" ${+s.review_bar === v ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
          <label>Prepare content ahead<select id="o-ahead">${[3, 7, 14].map((d) => `<option value="${d}" ${+s.lookahead_days === d ? 'selected' : ''}>${d} days before its date</option>`).join('')}</select></label></div>
        <p><button class="button button-primary" id="k-save">Save</button></p></div>

      <div class="age-card"><h2>Social accounts</h2>
        ${accounts.length ? `<table class="widefat age-table"><tbody>${accounts.map((a) => `<tr data-acc="${a.id}"><td><b>${esc(a.label)}</b></td><td>${esc(a.platform)}</td><td>${badge(a.status === 'active' ? 'live' : a.status)}${a.status_note ? ` <small class="age-bad">${esc(a.status_note)}</small>` : ''}</td>
          <td><button class="button-link age-del a-del">Disconnect</button></td></tr>`).join('')}</tbody></table>` : '<p class="age-muted">No accounts yet.</p>'}
        <h3>Connect a network</h3>
        <p class="age-muted">Networks with a login button use your own developer app. Register this redirect URL in each app: <code class="age-copy">${esc(platforms.redirect_uri)}</code></p>
        <div class="age-platforms">${platforms.platforms.map(platformCard).join('')}</div></div>`;

    $('#k-test').onclick = (e) => act(e.target, async () => {
      const r = await api('test', {});
      $('#k-out').innerHTML = `Claude: <b class="${r.claude === 'ok' ? 'age-ok' : 'age-bad'}">${esc(r.claude)}</b> · Jev: <b class="${r.jev === 'ok' ? 'age-ok' : 'age-bad'}">${esc(r.jev)}</b>`;
    });
    $('#k-save').onclick = (e) => act(e.target, async () => {
      const body = { brand: {}, words: +$('#o-words').value, review_bar: +$('#o-bar').value, lookahead_days: +$('#o-ahead').value };
      ['anthropic_key', 'typesafe_key', 'openai_key', 'pexels_key'].forEach((k) => { const v = $('#k-' + k).value.trim(); if (v) body[k] = v; });
      $$('[data-brand]').forEach((i) => (body.brand[i.dataset.brand] = i.value));
      await api('settings', body); render();
    }, 'Saved.');

    el.onclick = (e) => {
      const t = e.target;
      const row = t.closest('[data-acc]');
      if (row && t.classList.contains('a-del') && confirm('Disconnect this account?')) act(t, () => api('accounts/' + row.dataset.acc, null, 'DELETE')).then(render);
      const pc = t.closest('[data-platform]'); if (!pc) return;
      const key = pc.dataset.platform;
      if (t.classList.contains('p-save')) act(t, () => api('apps/' + key, { client_id: $('.p-id', pc).value, client_secret: $('.p-secret', pc).value }), 'App saved.').then(render);
      if (t.classList.contains('p-connect')) act(t, async () => { location.href = (await api('oauth/' + key, {})).url; });
      if (t.classList.contains('p-manual')) {
        const fields = {}; $$('[data-field]', pc).forEach((i) => (fields[i.dataset.field] = i.value));
        act(t, () => api('accounts/manual', { platform: key, fields }), 'Connected.').then(render);
      }
    };
  }

  function platformCard(p) {
    let body = '';
    if (p.via) body = '<p class="age-muted">Comes with your Facebook login (Instagram professional account linked to a Page).</p>';
    else if (p.oauth) body = `<label>Client ID / App ID<input class="p-id" autocomplete="off"></label>
      <label>Client secret<input class="p-secret" type="password" autocomplete="new-password" placeholder="${p.app_configured ? 'saved — leave blank to keep' : ''}"></label>
      <p><button class="button p-save">Save app</button> <button class="button button-primary p-connect" ${p.app_configured ? '' : 'disabled'}>Log in & connect</button>
      ${p.docs ? ` <a href="${esc(p.docs)}" target="_blank" rel="noopener">Developer portal ↗</a>` : ''}</p>`;
    if (p.manual_fields.length) body += `${p.oauth ? '<details><summary>Or paste a token</summary>' : ''}
      ${p.manual_fields.map((f) => `<label>${esc(f.label)}<input data-field="${esc(f.name)}" type="${f.type === 'password' ? 'password' : 'text'}" autocomplete="off">${f.help ? `<small class="age-muted">${esc(f.help)}</small>` : ''}</label>`).join('')}
      <p><button class="button button-primary p-manual">Connect</button></p>${p.oauth ? '</details>' : ''}`;
    return `<details class="age-platform" data-platform="${esc(p.key)}"><summary><b>${esc(p.label)}</b>${p.needs_image ? ' <small class="age-muted">needs images</small>' : ''}</summary>${body}</details>`;
  }

  render();
})();
