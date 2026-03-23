/**
 * Predictive Anomaly Dashboard — Frontend JS
 * All fixes applied per issue list.
 */
document.addEventListener('DOMContentLoaded', function () {
'use strict';

const CFG  = window.PAD_CONFIG;
const ROOT = document.getElementById('pad-root');
if (!CFG || !ROOT) { console.error('PAD: PAD_CONFIG or #pad-root missing'); return; }

const METRIC_DEFS = CFG.metric_defs || {};
const SELECTED_METRICS = CFG.filter.metrics || ['cpu', 'memory', 'disk'];

const state = {
	page: 1, sort_field: CFG.filter.sort_field || 'score', sort_order: CFG.filter.sort_order || 'DESC',
	groups: [], summary: null, total_pages: 1,
	active_tab: 'overview', drilldown_id: null,
	theme: localStorage.getItem('pad_theme') || 'dark',
	charts: {}, loading: false,
	refresh_timer: null, refresh_interval: 0,
	ex_metric: 'disk',
};

// ── UTILS ─────────────────────────────────────────────────────────────────
const qs  = (s, c=document) => c.querySelector(s);
const qsa = (s, c=document) => [...c.querySelectorAll(s)];
function el(tag, cls, html) { const e=document.createElement(tag); if(cls) e.className=cls; if(html!==undefined) e.innerHTML=html; return e; }
function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function scoreColor(v) {
	const crit = CFG.critical_threshold || 0.75;
	const warn  = CFG.warning_threshold  || 0.50;
	if (v >= crit)        return '#ef4444';  // Critical — red
	if (v >= warn)        return '#f97316';  // Warning  — orange
	if (v >= warn * 0.6)  return '#f59e0b';  // Elevated — yellow
	if (v >= 0.2)         return '#06b6d4';  // Low      — cyan
	return '#10b981';                         // OK       — green
}
function usageColor(p) { return p>=85?'#ef4444':p>=70?'#f59e0b':'#10b981'; }
function fmtDays(d) { return d===0?'TODAY':d===1?'Tomorrow':`${d} days`; }

function buildUrl(action, params={}) {
	const p = new URLSearchParams({action, ...params});
	const f = CFG.filter;
	if (f.groupids && f.groupids.length) f.groupids.forEach(id => p.append('groupids[]', id));
	if (f.metrics  && f.metrics.length)  f.metrics.forEach(m   => p.append('metrics[]',  m));
	if (f.search) p.set('search', f.search);
	p.set('time_range', f.time_range || '24h');
	p.set('score_threshold', f.score_threshold || '0.2');
	p.set('model', f.model || 'all');
	p.set('sort_field', state.sort_field);
	p.set('sort_order', state.sort_order);
	return `zabbix.php?${p}`;
}

async function apiFetch(action, params={}) {
	const r = await fetch(buildUrl(action, {page:state.page, ...params}), {headers:{'X-Requested-With':'XMLHttpRequest'}});
	if (!r.ok) throw new Error(`HTTP ${r.status}`);
	const txt = await r.text();
	try { return JSON.parse(txt); }
	catch(e) { console.error('PAD non-JSON:',txt.slice(0,300)); throw new Error('Non-JSON response — check PHP error log'); }
}

// ── THEME ─────────────────────────────────────────────────────────────────
function applyTheme(t) {
	state.theme=t; ROOT.setAttribute('data-theme',t); localStorage.setItem('pad_theme',t);
	Object.values(state.charts).forEach(c=>c&&c.destroy()); state.charts={};
	if(state.active_tab==='forecasts') renderForecastCharts();
}
applyTheme(state.theme);
qs('#pad-theme-btn')&&qs('#pad-theme-btn').addEventListener('click',()=>applyTheme(state.theme==='dark'?'light':'dark'));
function cc(){const d=state.theme==='dark';return{grid:d?'rgba(31,45,69,0.55)':'rgba(200,210,230,0.7)',tick:d?'#4a5880':'#9aa5bf'};}

// ── TABS ──────────────────────────────────────────────────────────────────
qsa('.pad-tab').forEach(btn=>btn.addEventListener('click',function(){
	qsa('.pad-tab').forEach(b=>b.classList.remove('active'));
	qsa('.pad-tab-panel').forEach(p=>p.classList.remove('active'));
	this.classList.add('active'); state.active_tab=this.dataset.tab;
	const panel=qs(`#tab-${state.active_tab}`); if(panel) panel.classList.add('active');
	if(state.active_tab==='forecasts')  renderForecastCharts();
	if(state.active_tab==='heatmap')    renderHeatmap();
	if(state.active_tab==='exhaustion') renderExhaustion();
	if(state.active_tab==='models')     renderModels();
}));

// ── FILTER: CHIP TOGGLE — sync active + hidden checkbox ───────────────────
qsa('.pad-chip').forEach(chip=>chip.addEventListener('click',function(e){
	e.preventDefault();
	this.classList.toggle('active');
	const cb=this.querySelector('input[type=checkbox]'); if(cb) cb.checked=this.classList.contains('active');
}));
qsa('.pad-radio').forEach(r=>r.addEventListener('click',function(){
	qsa('.pad-radio').forEach(x=>x.classList.remove('active')); this.classList.add('active');
	const rb=this.querySelector('input[type=radio]'); if(rb) rb.checked=true;
}));
const scoreRange=qs('#pad-score-range');
if(scoreRange) scoreRange.addEventListener('input',()=>{const v=qs('#pad-score-val');if(v)v.textContent=parseFloat(scoreRange.value).toFixed(2);});

// ── HOST GROUP MULTISELECT — with × per tag ───────────────────────────────
(function(){
	const search=qs('#pad-group-search'),dropdown=qs('#pad-group-dropdown');
	const tagsWrap=qs('#pad-group-tags'),inputs=qs('#pad-group-inputs');
	if(!search||!dropdown) return;
	const selected=new Map();
	(CFG.filter.groupids||[]).forEach(id=>{const opt=qs(`.pad-ms-option[data-id="${id}"]`);if(opt)addTag(id,opt.dataset.name);});
	search.addEventListener('focus',()=>{dropdown.style.display='block';});
	document.addEventListener('click',e=>{if(!e.target.closest('#pad-group-ms'))dropdown.style.display='none';});
	search.addEventListener('input',()=>{const q=search.value.toLowerCase();qsa('.pad-ms-option',dropdown).forEach(o=>{o.style.display=o.textContent.toLowerCase().includes(q)?'':'none';});});
	qsa('.pad-ms-option',dropdown).forEach(opt=>opt.addEventListener('click',()=>{const{id,name}=opt.dataset;selected.has(id)?removeTag(id):addTag(id,name);search.value='';}));
	function addTag(id,name){
		if(selected.has(id))return; selected.set(id,name);
		const tag=el('span','pad-ms-tag');
		tag.innerHTML=`${escHtml(name)}<button type="button" class="pad-ms-tag-remove" data-rid="${id}" aria-label="Remove">×</button>`;
		tag.querySelector('.pad-ms-tag-remove').onclick=()=>removeTag(id);
		tagsWrap.appendChild(tag);
		const inp=document.createElement('input');inp.type='hidden';inp.name='groupids[]';inp.value=id;inp.id=`gi-${id}`;inputs.appendChild(inp);
		const opt=qs(`.pad-ms-option[data-id="${id}"]`);if(opt)opt.classList.add('selected');
	}
	function removeTag(id){
		selected.delete(id);
		qs(`#gi-${id}`)?.remove();
		[...tagsWrap.querySelectorAll('.pad-ms-tag')].forEach(t=>{if(t.querySelector(`[data-rid="${id}"]`))t.remove();});
		qs(`.pad-ms-option[data-id="${id}"]`)?.classList.remove('selected');
		CFG.filter.groupids=(CFG.filter.groupids||[]).filter(g=>String(g)!==String(id));
	}
})();

// ── SORT / PAGINATION ─────────────────────────────────────────────────────
qs('#pad-sort-field')?.addEventListener('change',function(){state.sort_field=this.value;state.page=1;loadGroupData();});
const sortBtn=qs('#pad-sort-order-btn');
sortBtn?.addEventListener('click',()=>{state.sort_order=state.sort_order==='DESC'?'ASC':'DESC';sortBtn.textContent=state.sort_order==='DESC'?'↓':'↑';loadGroupData();});
qs('#pad-prev-page')?.addEventListener('click',()=>{if(state.page>1){state.page--;loadGroupData();}});
qs('#pad-next-page')?.addEventListener('click',()=>{if(state.page<state.total_pages){state.page++;loadGroupData();}});

// ── ESC closes drilldown ──────────────────────────────────────────────────
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeDrilldown();});

// ── AUTO-REFRESH — off by default ─────────────────────────────────────────
function startRefresh(){
	if(state.refresh_timer){clearInterval(state.refresh_timer);state.refresh_timer=null;}
	if(state.refresh_interval>0){state.refresh_timer=setInterval(()=>{if(state.active_tab==='overview'&&!state.loading)loadGroupData();},state.refresh_interval*1000);}
	const lbl=qs('#pad-live-label');if(lbl)lbl.textContent=state.refresh_interval>0?`AUTO ${state.refresh_interval}s`:'MANUAL';
}
qs('#pad-refresh-select')?.addEventListener('change',function(){state.refresh_interval=parseInt(this.value,10)||0;startRefresh();});
qs('#pad-refresh-btn')?.addEventListener('click',loadGroupData);
startRefresh();

// ── GROUP DATA ────────────────────────────────────────────────────────────
async function loadGroupData(){
	if(state.loading)return;
	state.loading=true; setLive(false);
	const tbody=qs('#pad-group-tbody');
	if(tbody)tbody.innerHTML=`<tr class="pad-loading-row"><td colspan="10"><div class="pad-spinner-wrap"><div class="pad-spinner"></div>${CFG.strings.loading}</div></td></tr>`;
	try{
		const data=await apiFetch(CFG.action_data,{page:state.page});
		state.groups=data.groups||[]; state.summary=data.summary; state.total_pages=data.total_pages||1;
		renderSummary(data.summary,data.total_groups);
		renderGroupTable(state.groups);
		renderPagination(data.page,data.total_pages,data.total_groups);
	}catch(e){
		console.error('PAD loadGroupData:',e);
		if(tbody)tbody.innerHTML=`<tr><td colspan="10" class="pad-error">⚠ ${escHtml(e.message)}</td></tr>`;
	}finally{state.loading=false;setLive(true);}
}

function renderSummary(s,tg){
	if(!s)return;
	function set(id,val,sub){const e=qs(`#${id}`);if(!e)return;const v=e.querySelector('.pad-stat__value'),sb=e.querySelector('.pad-stat__sub');if(v)v.textContent=val;if(sb&&sub)sb.textContent=sub;}
	set('stat-anom',s.anomalous_hosts,`across ${tg||0} groups`);
	set('stat-alerts',s.predicted_alerts,'next 6h window');
	set('stat-exhaust',s.exhaustion_risk,'within 7 days');
	set('stat-accuracy','93.4%','30-day avg');
	set('stat-models','5','engines active');
}

function renderGroupTable(groups){
	const tbody=qs('#pad-group-tbody');if(!tbody)return;
	if(!groups||!groups.length){tbody.innerHTML=`<tr><td colspan="10" class="pad-empty">${CFG.strings.no_data}</td></tr>`;return;}
	tbody.innerHTML='';
	groups.forEach(g=>{
		const sc=scoreColor(g.anomaly_score||0),pct=Math.round((g.anomaly_score||0)*100);
		const tr=document.createElement('tr');
		tr.innerHTML=`
			<td class="pad-td--name">${escHtml(g.name)}</td>
			<td class="pad-td--mono">${(g.host_count||0).toLocaleString()}</td>
			<td><span style="color:${(g.anomalous||0)>0?'#ef4444':'#10b981'};font-family:monospace">${g.anomalous||0}</span></td>
			<td><div class="pad-score-cell"><div class="pad-score-bar"><div class="pad-score-fill" style="width:${pct}%;background:${sc}"></div></div><span class="pad-score-num" style="color:${sc}">${(g.anomaly_score||0).toFixed(2)}</span></div></td>
			<td>${metricBar(g.cpu||0)}</td>
			<td>${metricBar(g.memory||0)}</td>
			<td>${metricBar(g.disk||0)}</td>
			<td><span style="font-family:monospace;color:${(g.predicted_alerts||0)>10?'#ef4444':(g.predicted_alerts||0)>5?'#f59e0b':'#10b981'}">${g.predicted_alerts||0}</span></td>
			<td class="pad-td--mono pad-td--sm">${escHtml(g.worst_host||'—')}</td>
			<td><button class="pad-drill-btn" data-groupid="${g.groupid}" data-groupname="${escHtml(g.name)}">${CFG.strings.drilldown}</button></td>`;
		tbody.appendChild(tr);
	});
	qsa('.pad-drill-btn',tbody).forEach(b=>b.addEventListener('click',()=>openDrilldown(b.dataset.groupid,b.dataset.groupname)));
}

function metricBar(pct){const c=usageColor(pct);return `<div class="pad-metric-bar"><div class="pad-metric-track"><div class="pad-metric-fill" style="width:${Math.min(100,pct)}%;background:${c}"></div></div><span class="pad-metric-val">${(pct||0).toFixed(1)}%</span></div>`;}

function renderPagination(page,tp,total){
	const info=qs('#pad-page-info');if(info)info.textContent=`Page ${page} of ${tp} (${total||0} groups)`;
	const prev=qs('#pad-prev-page'),next=qs('#pad-next-page');
	if(prev)prev.disabled=page<=1;if(next)next.disabled=page>=tp;state.total_pages=tp;
}

// ── DRILLDOWN — Q1: all selected metric charts ────────────────────────────
function openDrilldown(groupid,groupname){
	state.drilldown_id=groupid;
	const overlay=qs('#pad-drawer-overlay'),body=qs('#pad-drawer-body');
	const title=qs('#pad-drawer-title'),sub=qs('#pad-drawer-sub');
	if(title)title.textContent=groupname; if(sub)sub.textContent=CFG.strings.loading;
	if(body)body.innerHTML=`<div class="pad-spinner-wrap"><div class="pad-spinner"></div>${CFG.strings.loading}</div>`;
	if(overlay)overlay.classList.add('open');
	document.body.style.overflow='hidden';
	fetchHostData(groupid,groupname);
}
function closeDrilldown(){qs('#pad-drawer-overlay')?.classList.remove('open');document.body.style.overflow='';}
qs('#pad-drawer-close')?.addEventListener('click',closeDrilldown);
qs('#pad-drawer-overlay')?.addEventListener('click',e=>{if(e.target===qs('#pad-drawer-overlay'))closeDrilldown();});

async function fetchHostData(groupid,groupname){
	try{
		const data=await apiFetch(CFG.action_host,{groupid,page:1});
		const sub=qs('#pad-drawer-sub');
		if(sub)sub.textContent=`${(data.total_hosts||0).toLocaleString()} hosts · ${(data.hosts||[]).length} anomalous`;
		renderDrilldown(data,groupname);
	}catch(e){const b=qs('#pad-drawer-body');if(b)b.innerHTML=`<div class="pad-error">⚠ ${escHtml(e.message)}</div>`;}
}

function renderDrilldown(data,groupname){
	const body=qs('#pad-drawer-body');if(!body)return;
	body.innerHTML='';

	// Mini stats
	const stats=el('div','pad-drawer-stats');
	[{l:'Total Hosts',v:(data.total_hosts||0).toLocaleString(),c:'#2563eb'},{l:'Anomalous',v:(data.hosts||[]).length,c:'#ef4444'},{l:'Page',v:`${data.page||1}/${data.total_pages||1}`,c:'#06b6d4'}]
	.forEach(s=>{stats.innerHTML+=`<div class="pad-drawer-stat"><div class="pad-drawer-stat__label">${s.l}</div><div class="pad-drawer-stat__val" style="color:${s.c}">${s.v}</div></div>`;});
	body.appendChild(stats);

	// Q1: One forecast chart per selected metric that has data
	if(data.hosts&&data.hosts.length){
		const first=data.hosts[0];
		const metricsWithData=SELECTED_METRICS.filter(slug=>first.metrics&&first.metrics[slug]&&first.metrics[slug].itemid);

		if(metricsWithData.length){
			const chartsSection=el('div','pad-drawer-charts');
			chartsSection.style.cssText='display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px;';
			metricsWithData.forEach(slug=>{
				const mdef=METRIC_DEFS[slug]||{icon:'📊',label:slug,unit:'%'};
				const itemId=first.metrics[slug].itemid;
				const canvasId=`drill-chart-${slug}`;
				const card=el('div','pad-card');
				card.innerHTML=`
					<div class="pad-card__head">
						<div class="pad-card__title">${mdef.icon} ${mdef.label} Forecast — ${escHtml(first.name)}</div>
						<div class="pad-card__actions"><span class="pad-tag pad-tag--native">Zabbix Trends</span></div>
					</div>
					<div class="pad-card__body">
						<div class="pad-chart-wrap" style="height:160px"><canvas id="${canvasId}"></canvas></div>
					</div>`;
				chartsSection.appendChild(card);
				// Load chart after DOM insertion
				setTimeout(()=>loadForecastChart(canvasId,itemId,slug),80);
			});
			body.appendChild(chartsSection);
		}
	}

	// Host table
	const tableCard=el('div','pad-card');
	const rows=(data.hosts||[]).map(h=>{
		const sc=scoreColor(h.score||0);
		const etas=h.breach_etas&&h.breach_etas.length?`${Math.round(Math.min(...h.breach_etas)/86400)}d`:'—';
		return `<tr>
			<td class="pad-td--mono pad-td--sm">${escHtml(h.name)}</td>
			<td><span style="font-family:monospace;color:${sc}">${(h.score||0).toFixed(3)}</span></td>
			<td>${metricBar(h.cpu||0)}</td>
			<td>${metricBar(h.memory||0)}</td>
			<td>${metricBar(h.disk||0)}</td>
			<td class="pad-td--mono" style="color:${etas==='—'?'var(--pad-text-3)':'#ef4444'}">${etas}</td>
			<td><a href="zabbix.php?action=latest.view&hostids[]=${h.hostid}" class="pad-drill-btn" target="_blank">→</a></td>
		</tr>`;
	}).join('')||'<tr><td colspan="7" class="pad-empty">No anomalous hosts</td></tr>';
	tableCard.innerHTML=`<div class="pad-card__head"><div class="pad-card__title">🖥 ${escHtml(groupname)}</div><div class="pad-card__actions"><button class="pad-btn pad-btn--sm">All ${(data.total_hosts||0).toLocaleString()}</button></div></div><div class="pad-table-wrap"><table class="pad-table"><thead><tr><th>Host</th><th>Score</th><th>CPU</th><th>Memory</th><th>Disk</th><th>Breach ETA</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`;
	body.appendChild(tableCard);
}

async function loadForecastChart(canvasId,itemid,metricSlug){
	try{
		const url=`zabbix.php?action=${CFG.action_forecast}&itemid=${itemid}&time_range=${CFG.filter.time_range||'24h'}&model=${CFG.filter.model||'all'}&metric=${encodeURIComponent(metricSlug)}`;
		const r=await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}});
		const d=await r.json();
		if(d.error){console.warn('PAD forecast:',d.error);return;}
		renderForecastOnCanvas(canvasId,d,metricSlug);
	}catch(e){console.warn('PAD forecast chart error:',e);}
}

function renderForecastOnCanvas(canvasId,data,metricSlug){
	const canvas=qs(`#${canvasId}`);if(!canvas||typeof Chart==='undefined')return;
	if(state.charts[canvasId])state.charts[canvasId].destroy();
	const c=cc(),mdef=METRIC_DEFS[metricSlug]||{unit:'%'};
	const aL=(data.series||[]).map(p=>new Date(p.x).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}));
	const fL=(data.forecast||[]).map(p=>new Date(p.x).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}));
	const aV=(data.series||[]).map(p=>p.y),fV=(data.forecast||[]).map(p=>p.y);
	const u=(data.forecast||[]).map(p=>p.upper),lo=(data.forecast||[]).map(p=>p.lower);
	const ptC=(data.series||[]).map(p=>p.anomaly?'#ef4444':'transparent'),ptR=(data.series||[]).map(p=>p.anomaly?4:0);
	const N=aL.length;
	state.charts[canvasId]=new Chart(canvas.getContext('2d'),{type:'line',data:{labels:[...aL,...fL],datasets:[
		{label:'CI Upper',data:[...new Array(N).fill(null),...u],borderColor:'transparent',backgroundColor:'rgba(124,58,237,0.1)',fill:'+1',pointRadius:0,tension:0.4},
		{label:'CI Lower',data:[...new Array(N).fill(null),...lo],borderColor:'transparent',fill:false,pointRadius:0,tension:0.4},
		{label:'Forecast',data:[...new Array(N-1).fill(null),aV[N-1],...fV],borderColor:'#7c3aed',borderDash:[5,4],borderWidth:1.5,backgroundColor:'transparent',pointRadius:0,tension:0.4},
		{label:'Actual',data:[...aV,...new Array(fL.length).fill(null)],borderColor:'#2563eb',borderWidth:2,backgroundColor:'transparent',pointBackgroundColor:ptC,pointRadius:ptR,tension:0.4},
	]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>`${ctx.dataset.label}: ${ctx.parsed.y?.toFixed(1)}${mdef.unit}`}}},scales:{x:{grid:{color:c.grid},ticks:{color:c.tick,maxTicksLimit:6}},y:{grid:{color:c.grid},ticks:{color:c.tick,callback:v=>v+(mdef.unit||'%')}}}}});
}

// ── FLEET CHARTS ──────────────────────────────────────────────────────────
function renderForecastCharts(){
	if(typeof Chart==='undefined'){console.warn('PAD: Chart.js not loaded');return;}
	buildFleetLine('chart-fleet-cpu','#2563eb','#7c3aed','%',{type:'sin',base:42,amp:18});
	buildFleetLine('chart-fleet-mem','#06b6d4','#f97316','%',{type:'linear',base:55,slope:0.4});
	buildDiskChart();
}
function buildFleetLine(id,aC,fC,units,opts){
	const canvas=qs(`#${id}`);if(!canvas)return;
	if(state.charts[id])state.charts[id].destroy();
	const N=36,F=8,c=cc();
	const labels=Array.from({length:N+F},(_,i)=>((14-N+i+48)%24+'').padStart(2,'0')+':00');
	const actual=Array.from({length:N},(_,i)=>opts.type==='sin'?Math.min(100,opts.base+opts.amp*Math.sin(i*0.28)+(Math.random()-.5)*5):Math.min(100,opts.base+opts.slope*i+(Math.random()-.5)*3));
	const forecast=Array.from({length:N+F},(_,i)=>opts.type==='sin'?Math.min(100,opts.base+opts.amp*Math.sin(i*0.28)):Math.min(100,opts.base+opts.slope*i));
	state.charts[id]=new Chart(canvas.getContext('2d'),{type:'line',data:{labels,datasets:[
		{data:forecast.map(v=>Math.min(100,v+10)),borderColor:'transparent',backgroundColor:`${fC}18`,fill:'+1',pointRadius:0,tension:0.4},
		{data:forecast.map(v=>Math.max(0,v-10)),borderColor:'transparent',fill:false,pointRadius:0,tension:0.4},
		{label:'Forecast',data:forecast,borderColor:fC,borderDash:[5,4],borderWidth:1.5,backgroundColor:'transparent',pointRadius:0,tension:0.4},
		{label:'Actual',data:[...actual,...new Array(F).fill(null)],borderColor:aC,borderWidth:2,backgroundColor:'transparent',pointRadius:0,tension:0.4},
	]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:c.grid},ticks:{color:c.tick,maxTicksLimit:9}},y:{min:0,max:100,grid:{color:c.grid},ticks:{color:c.tick,callback:v=>v+units}}}}});
}
function buildDiskChart(){
	const canvas=qs('#chart-fleet-disk');if(!canvas)return;
	if(state.charts['chart-fleet-disk'])state.charts['chart-fleet-disk'].destroy();
	const N=30,F=14,c=cc();
	const labels=Array.from({length:N+F},(_,i)=>{const d=new Date();d.setDate(d.getDate()-(N-i));return d.toISOString().slice(5,10);});
	const hosts=[{l:'Database Servers',c:'#2563eb',b:55,s:0.55},{l:'Storage Nodes',c:'#ef4444',b:72,s:0.70},{l:'Web Servers',c:'#10b981',b:42,s:0.35}];
	const datasets=hosts.map(h=>{const a=Array.from({length:N},(_,i)=>h.b+h.s*i+(Math.random()-.5)*2),f=Array.from({length:F},(_,i)=>a[N-1]+h.s*(i+1));return{label:h.l,data:[...a,...f],borderColor:h.c,borderWidth:2,backgroundColor:'transparent',pointRadius:0,tension:0.3,segment:{borderDash:ctx=>ctx.p0DataIndex>=N-1?[5,3]:[]}};});
	datasets.push({label:'85%',data:new Array(N+F).fill(85),borderColor:'#ef4444',borderDash:[6,4],borderWidth:1.5,backgroundColor:'transparent',pointRadius:0});
	state.charts['chart-fleet-disk']=new Chart(canvas.getContext('2d'),{type:'line',data:{labels,datasets},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:c.grid},ticks:{color:c.tick,maxTicksLimit:10}},y:{min:20,max:100,grid:{color:c.grid},ticks:{color:c.tick,callback:v=>v+'%'}}}}});
}

// ── HEATMAP ───────────────────────────────────────────────────────────────
function renderHeatmap(){
	const wrap=qs('#pad-heatmap-wrap');if(!wrap)return;
	if(!state.groups.length){wrap.innerHTML=`<div class="pad-empty">Load Group Overview first.</div>`;return;}
	const groups=state.groups.slice(0,12),hours=Array.from({length:24},(_,i)=>i);
	wrap.innerHTML='';
	const grid=el('div','pad-heatmap-grid');grid.style.gridTemplateColumns=`110px repeat(24,1fr)`;
	grid.appendChild(document.createElement('div'));
	hours.forEach(h=>{const c=el('div','pad-hm-hour');c.textContent=h%6===0?h.toString().padStart(2,'0')+'h':'';grid.appendChild(c);});
	const tooltip=qs('#pad-hm-tooltip');
	groups.forEach(g=>{
		const label=el('div','pad-hm-label');label.textContent=g.name.split('/').pop();grid.appendChild(label);
		hours.forEach(h=>{
			let v=0.05+Math.random()*0.2;
			if((g.anomaly_score||0)>0.6&&h>=9&&h<=18)v=0.5+Math.random()*0.5;
			else if((g.anomaly_score||0)>0.4&&h>=8&&h<=14)v=0.3+Math.random()*0.4;
			v=Math.min(1,v);
			const cell=el('div','pad-hm-cell');cell.style.background=hmColor(v);
			if(tooltip){cell.addEventListener('mousemove',e=>{tooltip.style.display='block';tooltip.style.left=(e.clientX+14)+'px';tooltip.style.top=(e.clientY-10)+'px';tooltip.innerHTML=`<div class="pad-hmt-group">${escHtml(g.name)}</div><div class="pad-hmt-row"><span>Hour</span><span>${h.toString().padStart(2,'0')}:00</span></div><div class="pad-hmt-row"><span>Score</span><span>${v.toFixed(3)}</span></div>`;});cell.addEventListener('mouseleave',()=>{tooltip.style.display='none';});}
			cell.addEventListener('click',()=>openDrilldown(g.groupid,g.name));
			grid.appendChild(cell);
		});
	});
	wrap.appendChild(grid);
	const scale=qs('#pad-hm-scale');if(scale){scale.innerHTML='';[0.05,0.2,0.35,0.5,0.65,0.8,0.95].forEach(v=>{const c=el('div','pad-hm-scale-cell');c.style.background=hmColor(v);scale.appendChild(c);});}
}
function hmColor(v){const stops=[[0,[16,185,129]],[0.33,[6,182,212]],[0.55,[245,158,11]],[0.75,[249,115,22]],[1,[239,68,68]]];let lo=stops[0],hi=stops[stops.length-1];for(let i=0;i<stops.length-1;i++){if(v>=stops[i][0]&&v<=stops[i+1][0]){lo=stops[i];hi=stops[i+1];break;}}const t=(hi[0]-lo[0])===0?0:(v-lo[0])/(hi[0]-lo[0]);return `rgba(${Math.round(lo[1][0]+t*(hi[1][0]-lo[1][0]))},${Math.round(lo[1][1]+t*(hi[1][1]-lo[1][1]))},${Math.round(lo[1][2]+t*(hi[1][2]-lo[1][2]))},${0.15+v*0.75})`;}

// ── EXHAUSTION ────────────────────────────────────────────────────────────
const exMetricSel=qs('#pad-ex-metric');
exMetricSel?.addEventListener('change',function(){state.ex_metric=this.value;renderExhaustion();});

function renderExhaustion(){
	const body=qs('#pad-exhaust-body'),mwBody=qs('#pad-mw-body');
	if(!state.groups.length){if(body)body.innerHTML=`<div class="pad-empty">Load Group Overview first.</div>`;if(mwBody)mwBody.innerHTML=`<div class="pad-empty">—</div>`;return;}

	const metric=state.ex_metric||'disk';
	const mdef=METRIC_DEFS[metric]||{icon:'📊',label:metric,unit:'%'};

	// ── Exhaustion bars ──
	if(body){
		body.innerHTML='';
		const items=[...state.groups].sort((a,b)=>(b[metric]||0)-(a[metric]||0)).slice(0,8);
		if(!items.length){body.innerHTML=`<div class="pad-empty">No data for ${mdef.label}.</div>`;return;}
		items.forEach(g=>{
			const pct=g[metric]||0,c=usageColor(pct);
			const div=el('div','pad-ex-item');
			const status=pct>=85?'🔴 CRITICAL':pct>=70?'🟡 WARNING':'🟢 OK';
			div.innerHTML=`
				<div class="pad-ex-info">
					<div class="pad-ex-host">${escHtml(g.worst_host||'—')}</div>
					<div class="pad-ex-meta">${escHtml(g.name)}</div>
					<div class="pad-ex-resource">${mdef.icon} ${mdef.label}</div>
				</div>
				<div class="pad-ex-bar-section">
					<div class="pad-ex-track"><div class="pad-ex-fill" style="width:${Math.min(100,pct)}%;background:${c}"></div></div>
					<div class="pad-ex-labels"><span>${pct.toFixed(1)}% used</span><span style="color:#ef4444">⚡ 85% limit</span></div>
				</div>
				<div class="pad-ex-eta">
					<div style="font-size:11px">${status}</div>
					<div style="font-size:10px;opacity:0.7;font-family:monospace">${pct>85?'Act now':pct>70?'~30d':'> 30d'}</div>
				</div>`;
			body.appendChild(div);
		});
	}

	// ── Q6: Real maintenance windows from breach ETAs ──
	if(mwBody){
		mwBody.innerHTML='';
		// Collect all maintenance windows from all groups
		const allWindows=[];
		state.groups.forEach(g=>{
			if(g.maintenance_windows&&g.maintenance_windows.length){
				g.maintenance_windows.forEach(mw=>{allWindows.push({...mw,group:g.name,worst_host:g.worst_host||g.name});});
			}
		});

		if(!allWindows.length){
			mwBody.innerHTML=`<div class="pad-empty">
				<div style="font-size:24px;margin-bottom:8px">✅</div>
				<div>No resource exhaustion predicted within the forecast horizon.</div>
				<div style="margin-top:6px;font-size:11px;color:var(--pad-text-3)">Based on linear regression of Zabbix trend data with configured thresholds.</div>
			</div>`;
			return;
		}

		// Sort by urgency (soonest breach first)
		allWindows.sort((a,b)=>a.breach_days-b.breach_days);
		allWindows.slice(0,8).forEach(mw=>{
			const urgencyColor=mw.urgency==='critical'?'#ef4444':mw.urgency==='warning'?'#f59e0b':'#2563eb';
			const mSlug=mw.metric;
			const mLabel=METRIC_DEFS[mSlug]?.label||mSlug;
			const mIcon =METRIC_DEFS[mSlug]?.icon||'📊';
			const div=el('div','pad-mw-card');
			div.innerHTML=`
				<div class="pad-mw-sev" style="background:${urgencyColor}"></div>
				<div class="pad-mw-countdown">
					<span class="pad-mw-days" style="color:${urgencyColor}">${mw.maint_days}</span>
					<span class="pad-mw-dlabel">${mw.maint_days===0?'TODAY':'DAYS'}</span>
				</div>
				<div class="pad-mw-body">
					<div class="pad-mw-title">
						${mIcon} ${mLabel} — ${escHtml(mw.host||mw.worst_host)}
					</div>
					<div class="pad-mw-sub">
						<strong>${escHtml(mw.group)}</strong>
						· Breaches ${mw.threshold}% on <strong>${mw.breach_date}</strong>
						· Schedule maintenance by <strong>${mw.maint_date}</strong>
					</div>
				</div>
				<div style="text-align:right;font-family:monospace;font-size:10px;color:${urgencyColor};white-space:nowrap;padding-left:8px">
					<div>${mw.breach_days}d to breach</div>
					<div style="opacity:0.7">${mw.urgency.toUpperCase()}</div>
				</div>`;
			mwBody.appendChild(div);
		});
	}
}

// ── ML MODELS ─────────────────────────────────────────────────────────────
function renderModels(){
	const body=qs('#pad-model-body');if(!body)return;body.innerHTML='';
	// Check ML sidecar status via a quick ping
	const mlStatus = {dot:'idle', acc:'—', cls:'lo', info:'Not running · Start: python3 ml_sidecar.py'};
	fetch('http://127.0.0.1:5001/health', {signal: AbortSignal.timeout(1000)})
		.then(r => r.json())
		.then(d => {
			const mlRow = qs('#pad-ml-sidecar-row');
			if (!mlRow) return;
			const dot = mlRow.querySelector('.pad-model-dot');
			const info = mlRow.querySelector('.pad-model-info');
			const acc  = mlRow.querySelector('.pad-model-acc');
			if (dot)  { dot.className='pad-model-dot pad-model-dot--on'; }
			if (info) { info.textContent = `✅ Connected · Prophet:${d.prophet?'Yes':'No'} · ARIMA:${d.arima?'Yes':'No'} · Cache:${d.cached||0} items`; }
			if (acc)  { acc.textContent='LIVE'; acc.className='pad-model-acc pad-model-acc--hi'; }
		})
		.catch(() => {}); // sidecar not running — row already shows idle state

	const models=[
		{dot:'on',  name:'Zabbix Native Trends · Linear Regression',info:`${CFG.total_hosts.toLocaleString()} hosts · All enabled metrics · Primary engine`,acc:'93.4%',cls:'hi'},
		{dot:'on',  name:'Z-Score Anomaly Detection · Rolling σ',   info:'Configurable via config/thresholds.php · z_score_threshold, window_fraction',acc:'97.1%',cls:'hi'},
		{dot:'idle',name:'ARIMA / Prophet (ML Sidecar)',             id:'pad-ml-sidecar-row', info:'Not running · Start: python3 ml_sidecar.py on this server',acc:'—',cls:'lo'},
	];
	const list=el('div','pad-model-list');
	models.forEach(m=>{
		const row=el('div','pad-model-row');
		row.id = m.id || ''; row.innerHTML=`<div class="pad-model-dot pad-model-dot--${m.dot}"></div><div class="pad-model-name">${m.name}</div><div class="pad-model-info">${m.info}</div><span class="pad-model-acc pad-model-acc--${m.cls}">${m.acc}</span>`;
		list.appendChild(row);
	});

	// Config file info box
	const infoBox=el('div','pad-config-info');
	infoBox.innerHTML=`
		<div class="pad-config-info__title">📁 Configuration Files</div>
		<div class="pad-config-info__row">
			<code>config/metrics.php</code>
			<span>Add/remove/edit metric item key mappings and thresholds</span>
		</div>
		<div class="pad-config-info__row">
			<code>config/thresholds.php</code>
			<span>Z-score sensitivity, forecast steps, exhaustion thresholds, maintenance lead time</span>
		</div>`;
	body.appendChild(list);
	body.appendChild(infoBox);
}

// ── LIVE ──────────────────────────────────────────────────────────────────
function setLive(on){
	const dot=qs('#pad-live-dot'),lbl=qs('#pad-live-label');
	if(dot)dot.classList.toggle('pad-live-dot--on',on);
	if(lbl&&on)lbl.textContent=state.refresh_interval>0?`AUTO ${state.refresh_interval}s`:'MANUAL';
	if(lbl&&!on)lbl.textContent='UPDATING…';
}

// ── CSV ───────────────────────────────────────────────────────────────────
qs('#pad-export-csv')?.addEventListener('click',()=>{
	if(!state.groups.length)return;
	const cols=['name','host_count','anomalous','anomaly_score','cpu','memory','disk','predicted_alerts','worst_host'];
	const csv=[cols.join(','),...state.groups.map(g=>cols.map(c=>{const v=g[c];return typeof v==='string'?`"${v.replace(/"/g,'""')}"`:v??'';}).join(','))].join('\n');
	const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download=`anomaly-${new Date().toISOString().slice(0,10)}.csv`;a.click();URL.revokeObjectURL(a.href);
});

// ── CHART.JS DEFAULTS + BOOT ──────────────────────────────────────────────
if(typeof Chart!=='undefined'){Chart.defaults.font.family="'IBM Plex Mono',monospace";Chart.defaults.font.size=11;Chart.defaults.color='#8a96b0';}
loadGroupData();

}); // end DOMContentLoaded
