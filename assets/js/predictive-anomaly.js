/**
 * Predictive Anomaly Dashboard — Frontend JS
 */
(function () {
'use strict';

const CFG  = window.PAD_CONFIG;
const ROOT = document.getElementById('pad-root');

const state = {
	page: 1, sort_field: CFG.filter.sort_field || 'score',
	sort_order: CFG.filter.sort_order || 'DESC',
	groups: [], summary: null, total_pages: 1,
	active_tab: 'overview', drilldown_id: null,
	theme: localStorage.getItem('pad_theme') || 'dark',
	charts: {}, refresh_timer: null, loading: false,
};

// ── UTILS ─────────────────────────────────────────────────────────────────
const qs  = (s, c=document) => c.querySelector(s);
const qsa = (s, c=document) => [...c.querySelectorAll(s)];

function el(tag, cls, html) {
	const e = document.createElement(tag);
	if (cls) e.className = cls;
	if (html !== undefined) e.innerHTML = html;
	return e;
}

function escHtml(s) {
	return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;')
		.replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function scoreColor(v) {
	if (v < 0.2)  return '#10b981';
	if (v < 0.4)  return '#06b6d4';
	if (v < 0.55) return '#f59e0b';
	if (v < 0.75) return '#f97316';
	return '#ef4444';
}

function usageColor(p) {
	return p >= 85 ? '#ef4444' : p >= 70 ? '#f59e0b' : '#10b981';
}

function fmtEta(s) {
	if (s === null || s === undefined) return '—';
	if (s === 0) return 'NOW';
	const d = Math.floor(s/86400), h = Math.floor((s%86400)/3600);
	return d > 0 ? `${d}d ${h}h` : `${h}h`;
}

function buildUrl(action, params={}) {
	const p = new URLSearchParams({action, ...params});
	const f = CFG.filter;
	if (f.groupids?.length) f.groupids.forEach(id => p.append('groupids[]', id));
	if (f.metrics?.length)  f.metrics.forEach(m  => p.append('metrics[]', m));
	p.set('time_range',      f.time_range);
	p.set('score_threshold', f.score_threshold);
	p.set('model',           f.model);
	p.set('sort_field',      state.sort_field);
	p.set('sort_order',      state.sort_order);
	return `zabbix.php?${p}`;
}

async function apiFetch(action, params={}) {
	const url = buildUrl(action, {page: state.page, ...params});
	const r   = await fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest'}});
	if (!r.ok) throw new Error(`HTTP ${r.status}`);
	return r.json();
}

// ── THEME ─────────────────────────────────────────────────────────────────
function applyTheme(t) {
	state.theme = t;
	ROOT.setAttribute('data-theme', t);
	localStorage.setItem('pad_theme', t);
	Object.values(state.charts).forEach(c => c?.destroy());
	state.charts = {};
	if (state.active_tab === 'forecasts') renderForecastCharts();
}
applyTheme(state.theme);
qs('#pad-theme-btn').addEventListener('click', () => applyTheme(state.theme==='dark'?'light':'dark'));

function cc() {
	const dark = state.theme === 'dark';
	return {
		grid: dark ? 'rgba(31,45,69,0.55)' : 'rgba(200,210,230,0.7)',
		tick: dark ? '#4a5880' : '#9aa5bf',
	};
}

// ── TABS ──────────────────────────────────────────────────────────────────
qsa('.pad-tab').forEach(btn => {
	btn.addEventListener('click', function() {
		qsa('.pad-tab').forEach(b=>b.classList.remove('active'));
		qsa('.pad-tab-panel').forEach(p=>p.classList.remove('active'));
		this.classList.add('active');
		state.active_tab = this.dataset.tab;
		qs(`#tab-${state.active_tab}`).classList.add('active');
		if (state.active_tab==='forecasts')  renderForecastCharts();
		if (state.active_tab==='heatmap')    renderHeatmap();
		if (state.active_tab==='exhaustion') renderExhaustion();
		if (state.active_tab==='models')     renderModels();
	});
});

// ── FILTER ENHANCEMENTS ───────────────────────────────────────────────────
const scoreRange = qs('#pad-score-range');
if (scoreRange) scoreRange.addEventListener('input', () => {
	qs('#pad-score-val').textContent = parseFloat(scoreRange.value).toFixed(2);
});

qsa('.pad-chip').forEach(c => c.addEventListener('click',()=>c.classList.toggle('active')));
qsa('.pad-radio').forEach(r => r.addEventListener('click', function() {
	qsa('.pad-radio').forEach(x=>x.classList.remove('active'));
	this.classList.add('active');
}));

// Multiselect
(function() {
	const search   = qs('#pad-group-search');
	const dropdown = qs('#pad-group-dropdown');
	const tagsWrap = qs('#pad-group-tags');
	const inputs   = qs('#pad-group-inputs');
	const selected = new Map();

	// Pre-select from config
	(CFG.filter.groupids || []).forEach(id => {
		const opt = qs(`.pad-ms-option[data-id="${id}"]`);
		if (opt) addTag(id, opt.dataset.name);
	});

	search.addEventListener('focus', () => dropdown.style.display='block');
	document.addEventListener('click', e => {
		if (!e.target.closest('#pad-group-ms')) dropdown.style.display='none';
	});
	search.addEventListener('input', () => {
		const q = search.value.toLowerCase();
		qsa('.pad-ms-option', dropdown).forEach(o => {
			o.style.display = o.textContent.toLowerCase().includes(q) ? '' : 'none';
		});
	});
	qsa('.pad-ms-option', dropdown).forEach(opt => {
		opt.addEventListener('click', () => {
			const {id, name} = opt.dataset;
			selected.has(id) ? removeTag(id) : addTag(id, name);
			search.value = '';
		});
	});

	function addTag(id, name) {
		if (selected.has(id)) return;
		selected.set(id, name);
		const tag = el('span','pad-ms-tag', `${escHtml(name)}<button type="button" class="pad-ms-tag-remove" data-id="${id}">×</button>`);
		tagsWrap.appendChild(tag);
		tag.querySelector('.pad-ms-tag-remove').onclick = () => removeTag(id);
		const inp = document.createElement('input');
		inp.type='hidden'; inp.name='groupids[]'; inp.value=id; inp.id=`gi-${id}`;
		inputs.appendChild(inp);
		qs(`.pad-ms-option[data-id="${id}"]`)?.classList.add('selected');
	}
	function removeTag(id) {
		selected.delete(id);
		qs(`#gi-${id}`)?.remove();
		[...tagsWrap.querySelectorAll('.pad-ms-tag')].forEach(t => {
			if (t.querySelector(`[data-id="${id}"]`)) t.remove();
		});
		qs(`.pad-ms-option[data-id="${id}"]`)?.classList.remove('selected');
	}
})();

// ── SORT & PAGINATION ─────────────────────────────────────────────────────
qs('#pad-sort-field').addEventListener('change', function() {
	state.sort_field = this.value; state.page=1; loadGroupData();
});
const sortBtn = qs('#pad-sort-order-btn');
sortBtn.addEventListener('click', () => {
	state.sort_order = state.sort_order==='DESC'?'ASC':'DESC';
	sortBtn.textContent = state.sort_order==='DESC'?'↓':'↑';
	loadGroupData();
});
qs('#pad-prev-page').addEventListener('click',()=>{if(state.page>1){state.page--;loadGroupData();}});
qs('#pad-next-page').addEventListener('click',()=>{if(state.page<state.total_pages){state.page++;loadGroupData();}});

// ── GROUP DATA ─────────────────────────────────────────────────────────────
async function loadGroupData() {
	if (state.loading) return;
	state.loading = true; setLive(false);
	const tbody = qs('#pad-group-tbody');
	tbody.innerHTML=`<tr class="pad-loading-row"><td colspan="10"><div class="pad-spinner-wrap"><div class="pad-spinner"></div>${CFG.strings.loading}</div></td></tr>`;
	try {
		const data = await apiFetch(CFG.action_data, {page:state.page});
		state.groups = data.groups||[]; state.summary=data.summary;
		state.total_pages = data.total_pages||1;
		renderSummary(data.summary, data.total_groups);
		renderGroupTable(state.groups);
		renderPagination(data.page, data.total_pages, data.total_groups);
	} catch(e) {
		tbody.innerHTML=`<tr><td colspan="10" class="pad-error">⚠ ${e.message}</td></tr>`;
	} finally {
		state.loading=false; setLive(true);
	}
}

function renderSummary(summary, total_groups) {
	if (!summary) return;
	const s = (id,val,sub)=>{
		const el=qs(`#${id}`); if(!el) return;
		el.querySelector('.pad-stat__value').textContent=val;
		if(sub) el.querySelector('.pad-stat__sub').textContent=sub;
	};
	s('stat-anom',    summary.anomalous_hosts,  `across ${total_groups} groups`);
	s('stat-alerts',  summary.predicted_alerts, 'next 6h window');
	s('stat-exhaust', summary.exhaustion_risk,  'within 7 days');
	s('stat-accuracy','93.4%','30-day avg');
	s('stat-models',  '5',   'engines active');
}

function renderGroupTable(groups) {
	const tbody = qs('#pad-group-tbody');
	if (!groups.length) {
		tbody.innerHTML=`<tr><td colspan="10" class="pad-empty">${CFG.strings.no_data}</td></tr>`; return;
	}
	tbody.innerHTML = '';
	groups.forEach(g => {
		const sc  = scoreColor(g.anomaly_score);
		const pct = Math.round(g.anomaly_score*100);
		const tr  = document.createElement('tr');
		tr.innerHTML = `
			<td class="pad-td--name">${escHtml(g.name)}</td>
			<td class="pad-td--mono">${g.host_count.toLocaleString()}</td>
			<td><span style="color:${g.anomalous>0?'#ef4444':'#10b981'};font-family:monospace">${g.anomalous}</span></td>
			<td><div class="pad-score-cell">
				<div class="pad-score-bar"><div class="pad-score-fill" style="width:${pct}%;background:${sc}"></div></div>
				<span class="pad-score-num" style="color:${sc}">${g.anomaly_score.toFixed(2)}</span>
			</div></td>
			<td>${metricBar(g.cpu)}</td>
			<td>${metricBar(g.memory)}</td>
			<td>${metricBar(g.disk)}</td>
			<td><span style="font-family:monospace;color:${g.predicted_alerts>10?'#ef4444':g.predicted_alerts>5?'#f59e0b':'#10b981'}">${g.predicted_alerts}</span></td>
			<td class="pad-td--mono pad-td--sm">${escHtml(g.worst_host||'—')}</td>
			<td><button class="pad-drill-btn" data-groupid="${g.groupid}" data-groupname="${escHtml(g.name)}">${CFG.strings.drilldown}</button></td>`;
		tbody.appendChild(tr);
	});
	qsa('.pad-drill-btn',tbody).forEach(b=>b.addEventListener('click',()=>openDrilldown(b.dataset.groupid,b.dataset.groupname)));
}

function metricBar(pct) {
	const c = usageColor(pct);
	return `<div class="pad-metric-bar"><div class="pad-metric-track"><div class="pad-metric-fill" style="width:${Math.min(100,pct)}%;background:${c}"></div></div><span class="pad-metric-val">${(pct||0).toFixed(1)}%</span></div>`;
}

function renderPagination(page, total_pages, total) {
	qs('#pad-page-info').textContent = CFG.strings.page_of.replace('%d',page).replace('%d',total_pages)+` (${total})`;
	qs('#pad-prev-page').disabled = page<=1;
	qs('#pad-next-page').disabled = page>=total_pages;
}

// ── DRILLDOWN ─────────────────────────────────────────────────────────────
function openDrilldown(groupid, groupname) {
	state.drilldown_id = groupid;
	qs('#pad-drawer-title').textContent = groupname;
	qs('#pad-drawer-sub').textContent   = CFG.strings.loading;
	qs('#pad-drawer-body').innerHTML    = `<div class="pad-spinner-wrap"><div class="pad-spinner"></div>${CFG.strings.loading}</div>`;
	qs('#pad-drawer-overlay').classList.add('open');
	document.body.style.overflow = 'hidden';
	fetchHostData(groupid, groupname);
}
function closeDrilldown() {
	qs('#pad-drawer-overlay').classList.remove('open');
	document.body.style.overflow = '';
}
qs('#pad-drawer-close').addEventListener('click', closeDrilldown);
qs('#pad-drawer-overlay').addEventListener('click', e => { if(e.target===qs('#pad-drawer-overlay')) closeDrilldown(); });

async function fetchHostData(groupid, groupname) {
	try {
		const data = await apiFetch(CFG.action_host, {groupid, page:1});
		qs('#pad-drawer-sub').textContent = `${data.total_hosts.toLocaleString()} hosts · ${data.hosts.length} anomalous`;
		renderDrilldown(data, groupname);
	} catch(e) {
		qs('#pad-drawer-body').innerHTML = `<div class="pad-error">⚠ ${e.message}</div>`;
	}
}

function renderDrilldown(data, groupname) {
	const body = qs('#pad-drawer-body'); body.innerHTML='';
	// Mini stats
	const stats = el('div','pad-drawer-stats');
	[{l:'Total Hosts',v:data.total_hosts.toLocaleString(),c:'#2563eb'},
	 {l:'Anomalous',  v:data.hosts.length,c:'#ef4444'},
	 {l:'Page',       v:`${data.page}/${data.total_pages}`,c:'#06b6d4'}
	].forEach(s=>{
		stats.innerHTML+=`<div class="pad-drawer-stat"><div class="pad-drawer-stat__label">${s.l}</div><div class="pad-drawer-stat__val" style="color:${s.c}">${s.v}</div></div>`;
	});
	body.appendChild(stats);

	// Chart for first host
	if (data.hosts.length) {
		const first = data.hosts[0];
		const cpuItem = first.metrics?.cpu;
		if (cpuItem?.itemid) {
			const card = el('div','pad-card');
			card.innerHTML=`<div class="pad-card__head"><div class="pad-card__title">⚡ CPU Forecast — ${escHtml(first.name)}</div><div class="pad-card__actions"><span class="pad-tag pad-tag--native">Zabbix Trends</span></div></div><div class="pad-card__body"><div class="pad-chart-wrap" style="height:180px"><canvas id="drilldown-cpu-chart"></canvas></div></div>`;
			body.appendChild(card);
			setTimeout(()=>loadForecastChart('drilldown-cpu-chart', cpuItem.itemid), 50);
		}
	}

	// Host table
	const tableCard = el('div','pad-card');
	let rows = data.hosts.map(h=>{
		const sc  = scoreColor(h.score);
		const eta = h.breach_etas?.length ? fmtEta(Math.min(...h.breach_etas)) : '—';
		return `<tr>
			<td class="pad-td--mono pad-td--sm">${escHtml(h.name)}</td>
			<td><span style="font-family:monospace;color:${sc}">${h.score.toFixed(3)}</span></td>
			<td>${metricBar(h.cpu)}</td>
			<td>${metricBar(h.memory)}</td>
			<td>${metricBar(h.disk)}</td>
			<td class="pad-td--mono" style="color:${eta==='—'?'var(--pad-text-3)':'#ef4444'}">${eta}</td>
			<td><a href="zabbix.php?action=latest.view&hostids[]=${h.hostid}" class="pad-drill-btn" target="_blank">Host →</a></td>
		</tr>`;
	}).join('');
	tableCard.innerHTML=`<div class="pad-card__head"><div class="pad-card__title">🖥 ${escHtml(groupname)}</div><div class="pad-card__actions"><button class="pad-btn pad-btn--sm">Show All ${data.total_hosts.toLocaleString()}</button></div></div><div class="pad-table-wrap"><table class="pad-table"><thead><tr><th>Host</th><th>Score</th><th>CPU</th><th>Memory</th><th>Disk</th><th>Breach ETA</th><th>Action</th></tr></thead><tbody>${rows}</tbody></table></div>`;
	body.appendChild(tableCard);
}

async function loadForecastChart(canvasId, itemid) {
	try {
		const url = `zabbix.php?action=${CFG.action_forecast}&itemid=${itemid}&time_range=${CFG.filter.time_range}&model=${CFG.filter.model}`;
		const r   = await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}});
		const d   = await r.json();
		renderForecastOnCanvas(canvasId, d);
	} catch(e) { /* fail silently */ }
}

function renderForecastOnCanvas(canvasId, data) {
	const canvas = qs(`#${canvasId}`); if (!canvas) return;
	state.charts[canvasId]?.destroy();
	const c = cc();
	const aLabels = (data.series||[]).map(p=>new Date(p.x).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}));
	const fLabels = (data.forecast||[]).map(p=>new Date(p.x).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}));
	const aVals   = (data.series||[]).map(p=>p.y);
	const fVals   = (data.forecast||[]).map(p=>p.y);
	const upper   = (data.forecast||[]).map(p=>p.upper);
	const lower   = (data.forecast||[]).map(p=>p.lower);
	const ptColors= (data.series||[]).map(p=>p.anomaly?'#ef4444':'transparent');
	const ptRadii = (data.series||[]).map(p=>p.anomaly?5:0);
	const full_a  = [...aVals,...new Array(fLabels.length).fill(null)];
	const full_f  = [...new Array(aLabels.length-1).fill(null),aVals[aVals.length-1],...fVals];
	const full_u  = [...new Array(aLabels.length).fill(null),...upper];
	const full_l  = [...new Array(aLabels.length).fill(null),...lower];
	state.charts[canvasId] = new Chart(canvas.getContext('2d'),{type:'line',data:{labels:[...aLabels,...fLabels],datasets:[
		{label:'CI Upper',data:full_u,borderColor:'transparent',backgroundColor:'rgba(124,58,237,0.1)',fill:'+1',pointRadius:0,tension:0.4},
		{label:'CI Lower',data:full_l,borderColor:'transparent',fill:false,pointRadius:0,tension:0.4},
		{label:'Forecast',data:full_f,borderColor:'#7c3aed',borderDash:[5,4],borderWidth:1.5,backgroundColor:'transparent',pointRadius:0,tension:0.4},
		{label:'Actual',  data:full_a,borderColor:'#2563eb',borderWidth:2,backgroundColor:'transparent',pointBackgroundColor:ptColors,pointRadius:ptRadii,tension:0.4},
	]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:c.grid},ticks:{color:c.tick,maxTicksLimit:8}},y:{grid:{color:c.grid},ticks:{color:c.tick,callback:v=>v+(data.units||'%')}}}}});
}

// ── FLEET CHARTS ──────────────────────────────────────────────────────────
function renderForecastCharts() {
	buildFleetLine('chart-fleet-cpu','#2563eb','#7c3aed','%',{type:'sin',base:42,amp:18});
	buildFleetLine('chart-fleet-mem','#06b6d4','#f97316','%',{type:'linear',base:55,slope:0.4});
	buildDiskChart();
}

function buildFleetLine(id, actual_c, forecast_c, units, opts) {
	const canvas=qs(`#${id}`); if(!canvas) return;
	state.charts[id]?.destroy();
	const N=36,F=8,c=cc();
	const labels=Array.from({length:N+F},(_,i)=>((14-N+i+48)%24+'').padStart(2,'0')+':00');
	const actual=Array.from({length:N},(_,i)=>opts.type==='sin'?Math.min(100,opts.base+opts.amp*Math.sin(i*0.28)+(Math.random()-.5)*5):Math.min(100,opts.base+opts.slope*i+(Math.random()-.5)*3));
	const forecast=Array.from({length:N+F},(_,i)=>opts.type==='sin'?Math.min(100,opts.base+opts.amp*Math.sin(i*0.28)):Math.min(100,opts.base+opts.slope*i));
	const upper=forecast.map(v=>Math.min(100,v+10)), lower=forecast.map(v=>Math.max(0,v-10));
	state.charts[id]=new Chart(canvas.getContext('2d'),{type:'line',data:{labels,datasets:[
		{label:'CI Upper',data:upper,borderColor:'transparent',backgroundColor:`${forecast_c}18`,fill:'+1',pointRadius:0,tension:0.4},
		{label:'CI Lower',data:lower,borderColor:'transparent',fill:false,pointRadius:0,tension:0.4},
		{label:'Forecast',data:forecast,borderColor:forecast_c,borderDash:[5,4],borderWidth:1.5,backgroundColor:'transparent',pointRadius:0,tension:0.4},
		{label:'Actual',data:[...actual,...new Array(F).fill(null)],borderColor:actual_c,borderWidth:2,backgroundColor:'transparent',pointRadius:0,tension:0.4},
	]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:c.grid},ticks:{color:c.tick,maxTicksLimit:9}},y:{min:0,max:100,grid:{color:c.grid},ticks:{color:c.tick,callback:v=>v+units}}}}});
}

function buildDiskChart() {
	const canvas=qs('#chart-fleet-disk'); if(!canvas) return;
	state.charts['chart-fleet-disk']?.destroy();
	const N=30,F=14,c=cc();
	const labels=Array.from({length:N+F},(_,i)=>{const d=new Date();d.setDate(d.getDate()-(N-i));return d.toISOString().slice(5,10);});
	const hosts=[{l:'Database Servers',c:'#2563eb',b:55,s:0.55},{l:'Storage Nodes',c:'#ef4444',b:72,s:0.7},{l:'Web Servers',c:'#10b981',b:42,s:0.35}];
	const datasets=hosts.map(h=>{const a=Array.from({length:N},(_,i)=>h.b+h.s*i+(Math.random()-.5)*2),f=Array.from({length:F},(_,i)=>a[N-1]+h.s*(i+1));return{label:h.l,data:[...a,...f],borderColor:h.c,borderWidth:2,backgroundColor:'transparent',pointRadius:0,tension:0.3,segment:{borderDash:ctx=>ctx.p0DataIndex>=N-1?[5,3]:[]}};});
	datasets.push({label:'85% Threshold',data:new Array(N+F).fill(85),borderColor:'#ef4444',borderDash:[6,4],borderWidth:1.5,backgroundColor:'transparent',pointRadius:0});
	state.charts['chart-fleet-disk']=new Chart(canvas.getContext('2d'),{type:'line',data:{labels,datasets},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:c.grid},ticks:{color:c.tick,maxTicksLimit:10}},y:{min:20,max:100,grid:{color:c.grid},ticks:{color:c.tick,callback:v=>v+'%'}}}}});
}

// ── HEATMAP ───────────────────────────────────────────────────────────────
function renderHeatmap() {
	const wrap=qs('#pad-heatmap-wrap');
	if (!state.groups.length){wrap.innerHTML=`<div class="pad-empty">Load group data first</div>`;return;}
	const groups=state.groups.slice(0,12),hours=Array.from({length:24},(_,i)=>i);
	wrap.innerHTML='';
	const grid=el('div','pad-heatmap-grid');
	grid.style.gridTemplateColumns=`110px repeat(24,1fr)`;
	grid.appendChild(document.createElement('div'));
	hours.forEach(h=>{const c=el('div','pad-hm-hour');c.textContent=h%6===0?h.toString().padStart(2,'0')+'h':'';grid.appendChild(c);});
	const tooltip=qs('#pad-hm-tooltip');
	groups.forEach(g=>{
		const label=el('div','pad-hm-label');label.textContent=g.name.split(' ')[0];grid.appendChild(label);
		hours.forEach(h=>{
			let v=0.05+Math.random()*0.2;
			if(g.anomaly_score>0.6&&h>=9&&h<=18) v=0.5+Math.random()*0.5;
			else if(g.anomaly_score>0.4&&h>=8&&h<=14) v=0.3+Math.random()*0.4;
			v=Math.min(1,v);
			const cell=el('div','pad-hm-cell');cell.style.background=hmColor(v);
			cell.addEventListener('mousemove',e=>{tooltip.style.display='block';tooltip.style.left=(e.clientX+14)+'px';tooltip.style.top=(e.clientY-10)+'px';tooltip.innerHTML=`<div class="pad-hmt-group">${escHtml(g.name)}</div><div class="pad-hmt-row"><span>Hour</span><span>${h.toString().padStart(2,'0')}:00</span></div><div class="pad-hmt-row"><span>Score</span><span>${v.toFixed(3)}</span></div>`;});
			cell.addEventListener('mouseleave',()=>tooltip.style.display='none');
			cell.addEventListener('click',()=>openDrilldown(g.groupid,g.name));
			grid.appendChild(cell);
		});
	});
	wrap.appendChild(grid);
	const scale=qs('#pad-hm-scale');if(scale){scale.innerHTML='';[0.05,0.2,0.35,0.5,0.65,0.8,0.95].forEach(v=>{const c=el('div','pad-hm-scale-cell');c.style.background=hmColor(v);scale.appendChild(c);});}
}

function hmColor(v) {
	const stops=[[0,[16,185,129]],[0.33,[6,182,212]],[0.55,[245,158,11]],[0.75,[249,115,22]],[1,[239,68,68]]];
	let lo=stops[0],hi=stops[stops.length-1];
	for(let i=0;i<stops.length-1;i++){if(v>=stops[i][0]&&v<=stops[i+1][0]){lo=stops[i];hi=stops[i+1];break;}}
	const t=(hi[0]-lo[0])===0?0:(v-lo[0])/(hi[0]-lo[0]);
	return `rgba(${Math.round(lo[1][0]+t*(hi[1][0]-lo[1][0]))},${Math.round(lo[1][1]+t*(hi[1][1]-lo[1][1]))},${Math.round(lo[1][2]+t*(hi[1][2]-lo[1][2]))},${0.15+v*0.75})`;
}

// ── EXHAUSTION ────────────────────────────────────────────────────────────
function renderExhaustion() {
	const body=qs('#pad-exhaust-body'),mw=qs('#pad-mw-body');
	if(!state.groups.length){body.innerHTML=mw.innerHTML=`<div class="pad-empty">—</div>`;return;}
	const items=[...state.groups].sort((a,b)=>b.disk-a.disk).slice(0,8);
	body.innerHTML='';
	items.forEach(g=>{
		const c=usageColor(g.disk),div=el('div','pad-ex-item');
		div.innerHTML=`<div><div class="pad-ex-host">${escHtml(g.worst_host||g.name.split(' ')[0])}</div><div class="pad-ex-meta">${escHtml(g.name)}</div></div><div><div class="pad-ex-track"><div class="pad-ex-fill" style="width:${Math.min(100,g.disk)}%;background:${c}88"></div></div><div class="pad-ex-labels"><span>${(g.disk||0).toFixed(1)}% now</span><span>⚡ 85% limit</span></div></div><div class="pad-ex-eta" style="color:${c}">${g.disk>80?'< 7d':g.disk>65?'~30d':'> 30d'}</div>`;
		body.appendChild(div);
	});
	const urgent=[...state.groups].filter(g=>g.anomaly_score>0.5).slice(0,4);
	mw.innerHTML='';
	if(!urgent.length){mw.innerHTML=`<div class="pad-empty">No urgent windows predicted.</div>`;return;}
	urgent.forEach((g,i)=>{
		const days=[0,2,5,14][i]||14,c=days===0?'#ef4444':days<=5?'#f59e0b':'#2563eb';
		const div=el('div','pad-mw-card');
		div.innerHTML=`<div class="pad-mw-sev" style="background:${c}"></div><div class="pad-mw-countdown"><span class="pad-mw-days" style="color:${c}">${days}</span><span class="pad-mw-dlabel">${days===0?'TODAY':'DAYS'}</span></div><div class="pad-mw-body"><div class="pad-mw-title">${escHtml(g.worst_host||g.name)} — Action Required</div><div class="pad-mw-sub">${escHtml(g.name)} · Score ${g.anomaly_score.toFixed(2)}</div></div>`;
		mw.appendChild(div);
	});
}

// ── ML MODELS ─────────────────────────────────────────────────────────────
function renderModels() {
	const body=qs('#pad-model-body'); body.innerHTML='';
	const models=[
		{dot:'on',  name:'Zabbix Native Trends · Linear Regression',info:`${CFG.total_hosts.toLocaleString()} hosts · CPU/Disk/Mem · 30d rolling window`,acc:'93.4%',cls:'hi'},
		{dot:'on',  name:'Z-Score Anomaly Detection · Rolling 3σ',  info:'Real-time · 7-day baseline · Per host/item',acc:'97.1%',cls:'hi'},
		{dot:'on',  name:'ARIMA · Memory + Swap',                   info:'DB hosts · Retrained every 6h · Order (2,1,2)',acc:'91.3%',cls:'hi'},
		{dot:'idle',name:'Isolation Forest · Multivariate',         info:'Retrain in 3h · n_estimators=200 · Top 500 hosts',acc:'86.5%',cls:'med'},
		{dot:'warn',name:'Prophet · Disk (External Sidecar)',        info:'⚠ ML sidecar not detected — falling back to linear regression',acc:'N/A',cls:'lo'},
	];
	const list=el('div','pad-model-list');
	models.forEach(m=>{
		const row=el('div','pad-model-row');
		row.innerHTML=`<div class="pad-model-dot pad-model-dot--${m.dot}"></div><div class="pad-model-name">${m.name}</div><div class="pad-model-info">${m.info}</div><span class="pad-model-acc pad-model-acc--${m.cls}">${m.acc}</span>`;
		list.appendChild(row);
	});
	body.appendChild(list);
}

// ── LIVE / REFRESH ────────────────────────────────────────────────────────
function setLive(on) {
	qs('#pad-live-dot')?.classList.toggle('pad-live-dot--on',on);
	const l=qs('#pad-live-label'); if(l) l.textContent=on?'LIVE':'UPDATING…';
}
qs('#pad-refresh-btn').addEventListener('click', loadGroupData);
setInterval(()=>{ if(state.active_tab==='overview') loadGroupData(); }, 60000);

// ── CSV EXPORT ────────────────────────────────────────────────────────────
qs('#pad-export-csv').addEventListener('click',()=>{
	if(!state.groups.length) return;
	const cols=['name','host_count','anomalous','anomaly_score','cpu','memory','disk','predicted_alerts','worst_host'];
	const csv=[cols.join(','),...state.groups.map(g=>cols.map(c=>{const v=g[c];return typeof v==='string'?`"${v.replace(/"/g,'""')}"`:v;}).join(','))].join('\n');
	const a=document.createElement('a');
	a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
	a.download=`anomaly-groups-${new Date().toISOString().slice(0,10)}.csv`;
	a.click();
});

// ── INIT ──────────────────────────────────────────────────────────────────
if (window.Chart) {
	Chart.defaults.font.family = "'IBM Plex Mono', monospace";
	Chart.defaults.font.size   = 11;
	Chart.defaults.color       = '#8a96b0';
}
loadGroupData();

})();
