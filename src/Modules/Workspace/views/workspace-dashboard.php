<?php
/**
 * Sovereign Workspace Executive Dashboard View.
 * Rendered by shortcode [vmsos_workspace] on vmstudio.digital/workspace.
 */
defined( 'ABSPATH' ) || exit;

$isAdmin = current_user_can( 'manage_options' );
$restNonce = wp_create_nonce( 'wp_rest' );
?>

<div id="vmsos-workspace-root" class="vmsos-ws-container">
<?php if ( ! $isAdmin ) : ?>
	<!-- Security Gate / Access Restricted -->
	<div class="vmsos-ws-gate">
		<div class="vmsos-ws-gate-card">
			<div class="vmsos-ws-gate-badge">
				<span class="vmsos-ws-gate-icon">🔒</span>
				<span>RESTRICTED ACCESS</span>
			</div>
			<h2>VM Studio Sovereign Workspace</h2>
			<p>This mission command center is locked to authenticated administrators. Live agent fleet telemetry, workflow execution pipelines, and predictive growth models are restricted.</p>
			<div class="vmsos-ws-gate-actions">
				<a href="<?php echo esc_url( wp_login_url( home_url( '/workspace/' ) ) ); ?>" class="vmsos-ws-btn vmsos-ws-btn-primary">
					⚡ Authenticate as Administrator
				</a>
			</div>
		</div>
	</div>
<?php else : ?>
	<!-- Executive Header HUD -->
	<header class="vmsos-ws-hud">
		<div class="vmsos-ws-hud-brand">
			<div class="vmsos-ws-hud-logo">⚡</div>
			<div>
				<div class="vmsos-ws-hud-title">VM STUDIO SOVEREIGN WORKSPACE</div>
				<div class="vmsos-ws-hud-sub">Sales OS · Paperclip Autonomous Fleet · Predictive Intelligence</div>
			</div>
		</div>

		<div class="vmsos-ws-hud-pills">
			<div class="vmsos-ws-pill vmsos-ws-pill-emerald">
				<span class="vmsos-ws-dot"></span>
				<span id="vmsos-ws-stat-fleet">Fleet: Loading...</span>
			</div>
			<div class="vmsos-ws-pill vmsos-ws-pill-indigo">
				<span class="vmsos-ws-dot"></span>
				<span id="vmsos-ws-stat-tasks">Workflows: Active</span>
			</div>
			<div class="vmsos-ws-pill vmsos-ws-pill-amber">
				<span>Growth:</span>
				<strong id="vmsos-ws-stat-growth">+34.2% MoM</strong>
			</div>
			<button id="vmsos-ws-voice-btn" class="vmsos-ws-btn vmsos-ws-btn-sm vmsos-ws-btn-voice" title="Talk to VM Studio Sovereign Brain">
				🎙️ Voice Brain
			</button>
			<button id="vmsos-ws-heal-btn" class="vmsos-ws-btn vmsos-ws-btn-sm vmsos-ws-btn-heal" title="Execute Autonomous Self-Healing Diagnostic">
				🛡️ Self-Heal
			</button>
			<button id="vmsos-ws-refresh-btn" class="vmsos-ws-btn vmsos-ws-btn-sm vmsos-ws-btn-ghost" title="Refresh Live Fleet State">
				🔄 Sync
			</button>
			<button id="vmsos-ws-open-dispatch" class="vmsos-ws-btn vmsos-ws-btn-sm vmsos-ws-btn-neon">
				+ Dispatch Task
			</button>
		</div>
	</header>

	<!-- KPI Metrics Banner -->
	<section class="vmsos-ws-kpi-grid">
		<div class="vmsos-ws-kpi-card">
			<div class="vmsos-ws-kpi-label">PIPELINE VALUE</div>
			<div class="vmsos-ws-kpi-val" id="kpi-pipeline">₹--</div>
			<div class="vmsos-ws-kpi-meta"><span class="vmsos-ws-growth-up" id="kpi-pipeline-meta">Active Deals Pipeline</span></div>
		</div>
		<div class="vmsos-ws-kpi-card">
			<div class="vmsos-ws-kpi-label">LEAD VELOCITY</div>
			<div class="vmsos-ws-kpi-val" id="kpi-leads">--</div>
			<div class="vmsos-ws-kpi-meta"><span class="vmsos-ws-growth-up" id="kpi-leads-growth">+--% MoM</span></div>
		</div>
		<div class="vmsos-ws-kpi-card">
			<div class="vmsos-ws-kpi-label">60-DAY PROJECTED REVENUE</div>
			<div class="vmsos-ws-kpi-val vmsos-ws-text-neon" id="kpi-forecast-rev">₹--</div>
			<div class="vmsos-ws-kpi-meta"><span>Confidence: 84%</span></div>
		</div>
		<div class="vmsos-ws-kpi-card">
			<div class="vmsos-ws-kpi-label">PROJECTED DAILY TRAFFIC</div>
			<div class="vmsos-ws-kpi-val vmsos-ws-text-cyan" id="kpi-forecast-traffic">-- /day</div>
			<div class="vmsos-ws-kpi-meta"><span>Growth Trajectory</span></div>
		</div>
	</section>

	<!-- Main Workspace Split: Agent Fleet & Growth Intelligence -->
	<div class="vmsos-ws-main-layout">
		
		<!-- Left: Paperclip Autonomous Fleet -->
		<section class="vmsos-ws-section vmsos-ws-fleet-section">
			<div class="vmsos-ws-section-head">
				<div>
					<h3>🤖 Autonomous Agent Fleet (Paperclip)</h3>
					<p class="vmsos-ws-section-desc">Sovereign autonomous AI employees assigned to system architecture, sales triage, and authority SEO.</p>
				</div>
				<span class="vmsos-ws-badge" id="vmsos-ws-fleet-count">8 Agents</span>
			</div>

			<div class="vmsos-ws-agent-grid" id="vmsos-ws-agent-cards">
				<div class="vmsos-ws-loading">Loading fleet telemetry...</div>
			</div>
		</section>

		<!-- Right: What's Growing & Predictive Forecasting -->
		<section class="vmsos-ws-section vmsos-ws-growth-section">
			<div class="vmsos-ws-section-head">
				<div>
					<h3>📈 Growth Intelligence & Traffic Forecasting</h3>
					<p class="vmsos-ws-section-desc">30 / 60 / 90-day predictive trajectory model with confidence bounds.</p>
				</div>
				<div class="vmsos-ws-tab-group">
					<button class="vmsos-ws-tab active" data-horizon="30">30 Days</button>
					<button class="vmsos-ws-tab" data-horizon="60">60 Days</button>
					<button class="vmsos-ws-tab" data-horizon="90">90 Days</button>
				</div>
			</div>

			<!-- Dynamic SVG Trajectory Chart -->
			<div class="vmsos-ws-chart-card">
				<div class="vmsos-ws-chart-header">
					<div>
						<div class="vmsos-ws-chart-title">Traffic & Inbound Growth Projection</div>
						<div class="vmsos-ws-chart-legend">
							<span class="vmsos-ws-legend-dot expected"></span> Expected Path
							<span class="vmsos-ws-legend-dot aggressive"></span> Optimistic (+25%)
							<span class="vmsos-ws-legend-dot conservative"></span> Conservative (-15%)
						</div>
					</div>
					<div class="vmsos-ws-milestone-box" id="forecast-milestone-highlight">
						<strong>--</strong>
						<span>Expected</span>
					</div>
				</div>
				<div class="vmsos-ws-svg-wrap" id="vmsos-ws-svg-chart">
					<!-- SVG injected via JS -->
				</div>
			</div>

			<!-- What's Growing Breakdown -->
			<div class="vmsos-ws-growing-columns">
				<div class="vmsos-ws-mini-panel">
					<h4>🚀 Channel Growth Velocity</h4>
					<div id="vmsos-ws-channels-list" class="vmsos-ws-list">
						<!-- Injected via JS -->
					</div>
				</div>
				<div class="vmsos-ws-mini-panel">
					<h4>💎 Top Growing Catalog Offerings</h4>
					<div id="vmsos-ws-catalog-list" class="vmsos-ws-list">
						<!-- Injected via JS -->
					</div>
				</div>
			</div>
		</section>

	</div>

	<!-- Workflow & Task Matrix (Paperclip Issues) -->
	<section class="vmsos-ws-section vmsos-ws-tasks-section">
		<div class="vmsos-ws-section-head">
			<div>
				<h3>📋 Live Autonomous Workflow Matrix</h3>
				<p class="vmsos-ws-section-desc">Real-time missions, code audits, content clusters, and sales triage pipelines.</p>
			</div>
			<div class="vmsos-ws-task-filters">
				<button class="vmsos-ws-filter active" data-filter="all">All</button>
				<button class="vmsos-ws-filter" data-filter="in_progress">In Progress</button>
				<button class="vmsos-ws-filter" data-filter="todo">Backlog</button>
				<button class="vmsos-ws-filter" data-filter="done">Completed</button>
			</div>
		</div>

		<div class="vmsos-ws-task-table-wrap">
			<table class="vmsos-ws-table">
				<thead>
					<tr>
						<th>IDENTIFIER</th>
						<th>MISSION / TASK</th>
						<th>STATUS</th>
						<th>PRIORITY</th>
						<th>ASSIGNEE AGENT</th>
					</tr>
				</thead>
				<tbody id="vmsos-ws-tasks-body">
					<tr><td colspan="5" class="vmsos-ws-loading">Loading active workflow tasks...</td></tr>
				</tbody>
			</table>
		</div>
	</section>

	<!-- Dispatch Mission Modal -->
	<div id="vmsos-ws-modal" class="vmsos-ws-modal" hidden>
		<div class="vmsos-ws-modal-backdrop"></div>
		<div class="vmsos-ws-modal-card">
			<div class="vmsos-ws-modal-head">
				<h3>⚡ Dispatch Autonomous Mission</h3>
				<button id="vmsos-ws-modal-close" class="vmsos-ws-btn-close">✕</button>
			</div>
			<form id="vmsos-ws-dispatch-form">
				<div class="vmsos-ws-field">
					<label>Mission Title</label>
					<input type="text" id="vmsos-disp-title" placeholder="e.g. Audit OnlinePuja checkout conversion funnel" required />
				</div>
				<div class="vmsos-ws-field">
					<label>Assign to Paperclip Agent</label>
					<select id="vmsos-disp-agent">
						<option value="">Select Target Agent...</option>
					</select>
				</div>
				<div class="vmsos-ws-field">
					<label>Priority</label>
					<select id="vmsos-disp-priority">
						<option value="medium">Medium</option>
						<option value="high">High</option>
						<option value="critical">Critical</option>
						<option value="low">Low</option>
					</select>
				</div>
				<div class="vmsos-ws-field">
					<label>Mission Instructions & Context</label>
					<textarea id="vmsos-disp-desc" rows="4" placeholder="Detailed objectives, target URLs, deliverables, and acceptance criteria..."></textarea>
				</div>
				<div class="vmsos-ws-modal-footer">
					<button type="button" id="vmsos-ws-cancel-btn" class="vmsos-ws-btn vmsos-ws-btn-ghost">Cancel</button>
					<button type="submit" id="vmsos-ws-submit-btn" class="vmsos-ws-btn vmsos-ws-btn-primary">⚡ Dispatch Mission</button>
				</div>
			</form>
		</div>
	</div>

	<!-- VM Studio Voice Commander & Brain Assistant Modal -->
	<div id="vmsos-ws-voice-modal" class="vmsos-ws-modal" hidden>
		<div class="vmsos-ws-modal-backdrop" id="vmsos-ws-voice-backdrop"></div>
		<div class="vmsos-ws-modal-card vmsos-ws-voice-card">
			<div class="vmsos-ws-modal-head">
				<div class="vmsos-ws-voice-head-info">
					<div class="vmsos-ws-voice-avatar">🧠</div>
					<div>
						<h3>VM Studio Sovereign Brain</h3>
						<span class="vmsos-ws-voice-status-sub" id="vmsos-voice-sub">Voice Commander · Live Telemetry</span>
					</div>
				</div>
				<button id="vmsos-ws-voice-close" class="vmsos-ws-btn-close">✕</button>
			</div>

			<!-- Dynamic Audio Visualizer Waveform -->
			<div class="vmsos-ws-visualizer-wrap">
				<div class="vmsos-ws-visualizer" id="vmsos-ws-visualizer">
					<span class="vmsos-ws-bar"></span>
					<span class="vmsos-ws-bar"></span>
					<span class="vmsos-ws-bar"></span>
					<span class="vmsos-ws-bar"></span>
					<span class="vmsos-ws-bar"></span>
					<span class="vmsos-ws-bar"></span>
					<span class="vmsos-ws-bar"></span>
				</div>
				<div class="vmsos-ws-voice-indicator" id="vmsos-voice-indicator">Tap microphone or speak...</div>
			</div>

			<!-- Live Conversation Stream -->
			<div class="vmsos-ws-voice-stream" id="vmsos-voice-stream">
				<div class="vmsos-ws-voice-msg vmsos-ws-msg-bot">
					<strong>VM Studio:</strong> I am the sovereign intelligence orchestrating your multi-company fleet, deals, and servers. How can I direct operations today?
				</div>
			</div>

			<!-- Quick Prompt Chips -->
			<div class="vmsos-ws-voice-chips">
				<button class="vmsos-ws-chip" data-prompt="Give me a full executive morning briefing on revenue and fleet.">🎙️ Morning Briefing</button>
				<button class="vmsos-ws-chip" data-prompt="Run an autonomous self-healing check on all daemons and databases.">🛡️ Run Self-Heal</button>
				<button class="vmsos-ws-chip" data-prompt="What is our active pipeline value and lead velocity?">📈 Pipeline & Deals</button>
				<button class="vmsos-ws-chip" data-prompt="Which Paperclip agents are currently running missions?">🤖 Active Agents</button>
			</div>

			<!-- Input & Audio Controls -->
			<div class="vmsos-ws-voice-controls">
				<input type="text" id="vmsos-voice-input" placeholder="Speak or type a command..." />
				<button id="vmsos-mic-btn" class="vmsos-ws-mic-btn" title="Toggle Voice Listening">
					<span class="vmsos-mic-icon">🎙️</span>
				</button>
				<button id="vmsos-send-btn" class="vmsos-ws-btn vmsos-ws-btn-primary">
					⚡
				</button>
			</div>
		</div>
	</div>

<?php endif; ?>
</div>

<style>
/* World-Class Obsidian Glassmorphism Styling */
.vmsos-ws-container {
	--ws-bg: #070a13;
	--ws-surface: #0d1322;
	--ws-card: rgba(16, 23, 41, 0.75);
	--ws-border: rgba(255, 255, 255, 0.08);
	--ws-border-glow: rgba(99, 102, 241, 0.3);
	--ws-text: #f1f5f9;
	--ws-muted: #94a3b8;
	--ws-neon-indigo: #6366f1;
	--ws-neon-emerald: #10b981;
	--ws-neon-amber: #f59e0b;
	--ws-neon-cyan: #06b6d4;
	--ws-neon-rose: #f43f5e;
	box-sizing: border-box;
	width: 100%;
	background: var(--ws-bg);
	color: var(--ws-text);
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
	padding: 24px;
	border-radius: 16px;
	box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
	margin: 20px auto;
	max-width: 1400px;
	line-height: 1.5;
	backdrop-filter: blur(20px);
}

/* Security Gate */
.vmsos-ws-gate {
	display: flex;
	justify-content: center;
	align-items: center;
	padding: 80px 20px;
}
.vmsos-ws-gate-card {
	background: var(--ws-card);
	border: 1px solid var(--ws-border);
	padding: 40px;
	border-radius: 20px;
	max-width: 550px;
	text-align: center;
	box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
}
.vmsos-ws-gate-badge {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	background: rgba(244, 63, 94, 0.15);
	border: 1px solid rgba(244, 63, 94, 0.3);
	color: var(--ws-neon-rose);
	padding: 6px 14px;
	border-radius: 100px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 1px;
	margin-bottom: 20px;
}

/* HUD Header */
.vmsos-ws-hud {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 16px;
	padding-bottom: 20px;
	border-bottom: 1px solid var(--ws-border);
	margin-bottom: 24px;
}
.vmsos-ws-hud-brand {
	display: flex;
	align-items: center;
	gap: 14px;
}
.vmsos-ws-hud-logo {
	width: 44px;
	height: 44px;
	border-radius: 12px;
	background: linear-gradient(135deg, var(--ws-neon-indigo), #4f46e5);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 22px;
	box-shadow: 0 0 20px rgba(99, 102, 241, 0.4);
}
.vmsos-ws-hud-title {
	font-size: 18px;
	font-weight: 800;
	letter-spacing: 0.5px;
	color: #fff;
}
.vmsos-ws-hud-sub {
	font-size: 12px;
	color: var(--ws-muted);
}
.vmsos-ws-hud-pills {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}
.vmsos-ws-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border-radius: 20px;
	font-size: 12px;
	background: var(--ws-surface);
	border: 1px solid var(--ws-border);
}
.vmsos-ws-pill-emerald { border-color: rgba(16, 185, 129, 0.3); color: var(--ws-neon-emerald); }
.vmsos-ws-pill-indigo { border-color: rgba(99, 102, 241, 0.3); color: #a5b4fc; }
.vmsos-ws-pill-amber { border-color: rgba(245, 158, 11, 0.3); color: #fde68a; }
.vmsos-ws-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: currentColor;
	box-shadow: 0 0 8px currentColor;
}

/* KPI Cards */
.vmsos-ws-kpi-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}
.vmsos-ws-kpi-card {
	background: var(--ws-card);
	border: 1px solid var(--ws-border);
	padding: 20px;
	border-radius: 14px;
	transition: border-color 0.2s, transform 0.2s;
}
.vmsos-ws-kpi-card:hover {
	border-color: var(--ws-border-glow);
	transform: translateY(-2px);
}
.vmsos-ws-kpi-label {
	font-size: 11px;
	font-weight: 700;
	color: var(--ws-muted);
	letter-spacing: 0.5px;
	margin-bottom: 6px;
}
.vmsos-ws-kpi-val {
	font-size: 26px;
	font-weight: 800;
	color: #fff;
	margin-bottom: 6px;
}
.vmsos-ws-text-neon { color: #a5b4fc; text-shadow: 0 0 15px rgba(99, 102, 241, 0.5); }
.vmsos-ws-text-cyan { color: #67e8f9; text-shadow: 0 0 15px rgba(6, 182, 212, 0.5); }
.vmsos-ws-kpi-meta {
	font-size: 12px;
	color: var(--ws-muted);
}
.vmsos-ws-growth-up { color: var(--ws-neon-emerald); font-weight: 600; }

/* Main Split */
.vmsos-ws-main-layout {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 20px;
	margin-bottom: 24px;
}
@media (max-width: 1024px) {
	.vmsos-ws-main-layout { grid-template-columns: 1fr; }
}

.vmsos-ws-section {
	background: var(--ws-card);
	border: 1px solid var(--ws-border);
	border-radius: 16px;
	padding: 22px;
}
.vmsos-ws-section-head {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 18px;
	flex-wrap: wrap;
	gap: 12px;
}
.vmsos-ws-section-head h3 {
	margin: 0 0 4px 0;
	font-size: 17px;
	font-weight: 700;
	color: #fff;
}
.vmsos-ws-section-desc {
	margin: 0;
	font-size: 12px;
	color: var(--ws-muted);
}
.vmsos-ws-badge {
	background: rgba(99, 102, 241, 0.15);
	border: 1px solid rgba(99, 102, 241, 0.3);
	color: #a5b4fc;
	font-size: 11px;
	font-weight: 700;
	padding: 4px 10px;
	border-radius: 12px;
}

/* Agent Fleet Grid */
.vmsos-ws-agent-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 14px;
	max-height: 520px;
	overflow-y: auto;
	padding-right: 4px;
}
.vmsos-ws-agent-card {
	background: var(--ws-surface);
	border: 1px solid var(--ws-border);
	border-radius: 12px;
	padding: 16px;
	display: flex;
	flex-direction: column;
	justify-content: space-between;
	transition: border-color 0.2s;
}
.vmsos-ws-agent-card:hover {
	border-color: var(--ws-border-glow);
}
.vmsos-ws-agent-top {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 10px;
}
.vmsos-ws-agent-avatar {
	width: 38px;
	height: 38px;
	border-radius: 10px;
	background: linear-gradient(135deg, #1e293b, #334155);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 18px;
}
.vmsos-ws-agent-name {
	font-size: 14px;
	font-weight: 700;
	color: #fff;
}
.vmsos-ws-agent-title {
	font-size: 11px;
	color: var(--ws-muted);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 170px;
}
.vmsos-ws-agent-meta {
	font-size: 11px;
	color: var(--ws-muted);
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: 10px;
	padding-top: 10px;
	border-top: 1px solid rgba(255, 255, 255, 0.05);
}
.vmsos-ws-agent-status {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-weight: 600;
	text-transform: capitalize;
}
.vmsos-ws-agent-status.running { color: var(--ws-neon-emerald); }
.vmsos-ws-agent-status.idle { color: var(--ws-muted); }

/* Chart & Forecasting */
.vmsos-ws-chart-card {
	background: var(--ws-surface);
	border: 1px solid var(--ws-border);
	border-radius: 12px;
	padding: 16px;
	margin-bottom: 16px;
}
.vmsos-ws-chart-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}
.vmsos-ws-chart-title {
	font-size: 13px;
	font-weight: 700;
	color: #fff;
}
.vmsos-ws-chart-legend {
	font-size: 11px;
	color: var(--ws-muted);
	margin-top: 4px;
	display: flex;
	gap: 12px;
}
.vmsos-ws-legend-dot {
	display: inline-block;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	vertical-align: middle;
	margin-right: 4px;
}
.vmsos-ws-legend-dot.expected { background: var(--ws-neon-indigo); }
.vmsos-ws-legend-dot.aggressive { background: var(--ws-neon-emerald); }
.vmsos-ws-legend-dot.conservative { background: var(--ws-neon-amber); }

.vmsos-ws-milestone-box {
	text-align: right;
	background: rgba(99, 102, 241, 0.1);
	border: 1px solid rgba(99, 102, 241, 0.2);
	padding: 6px 12px;
	border-radius: 8px;
}
.vmsos-ws-milestone-box strong { font-size: 16px; color: #a5b4fc; display: block; }
.vmsos-ws-milestone-box span { font-size: 10px; color: var(--ws-muted); text-transform: uppercase; }

.vmsos-ws-svg-wrap {
	width: 100%;
	height: 180px;
	overflow: hidden;
}
.vmsos-ws-svg-wrap svg {
	width: 100%;
	height: 100%;
}

.vmsos-ws-growing-columns {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 14px;
}
.vmsos-ws-mini-panel {
	background: var(--ws-surface);
	border: 1px solid var(--ws-border);
	border-radius: 12px;
	padding: 14px;
}
.vmsos-ws-mini-panel h4 {
	margin: 0 0 10px 0;
	font-size: 12px;
	font-weight: 700;
	color: #fff;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}
.vmsos-ws-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.vmsos-ws-list-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 12px;
	padding: 6px 8px;
	border-radius: 6px;
	background: rgba(255, 255, 255, 0.02);
}
.vmsos-ws-list-item strong { color: #fff; }

/* Tasks Table */
.vmsos-ws-task-table-wrap {
	overflow-x: auto;
}
.vmsos-ws-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
	text-align: left;
}
.vmsos-ws-table th {
	padding: 12px;
	border-bottom: 1px solid var(--ws-border);
	color: var(--ws-muted);
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.5px;
}
.vmsos-ws-table td {
	padding: 12px;
	border-bottom: 1px solid rgba(255, 255, 255, 0.04);
	color: var(--ws-text);
}
.vmsos-ws-table tr:hover td {
	background: rgba(255, 255, 255, 0.02);
}
.vmsos-ws-task-id {
	font-family: monospace;
	font-weight: 700;
	color: #a5b4fc;
}
.vmsos-ws-priority-badge {
	font-size: 10px;
	font-weight: 700;
	padding: 3px 8px;
	border-radius: 6px;
	text-transform: uppercase;
}
.vmsos-ws-priority-critical { background: rgba(244, 63, 94, 0.2); color: var(--ws-neon-rose); border: 1px solid rgba(244, 63, 94, 0.4); }
.vmsos-ws-priority-high { background: rgba(245, 158, 11, 0.2); color: var(--ws-neon-amber); border: 1px solid rgba(245, 158, 11, 0.4); }
.vmsos-ws-priority-medium { background: rgba(99, 102, 241, 0.2); color: #a5b4fc; border: 1px solid rgba(99, 102, 241, 0.4); }
.vmsos-ws-priority-low { background: rgba(148, 163, 184, 0.15); color: var(--ws-muted); border: 1px solid rgba(148, 163, 184, 0.3); }

/* Buttons & Controls */
.vmsos-ws-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 8px 16px;
	border-radius: 10px;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
	text-decoration: none;
	border: none;
	transition: all 0.2s;
}
.vmsos-ws-btn-sm { padding: 5px 12px; font-size: 12px; border-radius: 8px; }
.vmsos-ws-btn-primary { background: #4f46e5; color: #fff; box-shadow: 0 0 15px rgba(79, 70, 229, 0.4); }
.vmsos-ws-btn-primary:hover { background: #4338ca; }
.vmsos-ws-btn-neon { background: var(--ws-neon-indigo); color: #fff; box-shadow: 0 0 15px rgba(99, 102, 241, 0.5); }
.vmsos-ws-btn-voice { 
	background: linear-gradient(135deg, #ec4899, #8b5cf6); 
	color: #fff; 
	box-shadow: 0 0 16px rgba(236, 72, 153, 0.45); 
	font-weight: 700;
}
.vmsos-ws-btn-voice:hover { 
	transform: translateY(-1px); 
	box-shadow: 0 0 22px rgba(236, 72, 153, 0.65); 
}
.vmsos-ws-btn-heal { 
	background: rgba(16, 185, 129, 0.15); 
	border: 1px solid rgba(16, 185, 129, 0.4); 
	color: #34d399; 
}
.vmsos-ws-btn-heal:hover { 
	background: rgba(16, 185, 129, 0.25); 
	box-shadow: 0 0 15px rgba(16, 185, 129, 0.35); 
}
.vmsos-ws-btn-ghost { background: var(--ws-surface); color: var(--ws-text); border: 1px solid var(--ws-border); }
.vmsos-ws-btn-ghost:hover { border-color: var(--ws-border-glow); }

.vmsos-ws-tab-group, .vmsos-ws-task-filters {
	display: flex;
	gap: 6px;
}
.vmsos-ws-tab, .vmsos-ws-filter {
	background: var(--ws-surface);
	border: 1px solid var(--ws-border);
	color: var(--ws-muted);
	font-size: 11px;
	padding: 4px 10px;
	border-radius: 8px;
	cursor: pointer;
	font-weight: 600;
}
.vmsos-ws-tab.active, .vmsos-ws-filter.active {
	background: var(--ws-neon-indigo);
	border-color: var(--ws-neon-indigo);
	color: #fff;
}

/* Modal */
.vmsos-ws-modal[hidden] { display: none; }
.vmsos-ws-modal {
	position: fixed;
	inset: 0;
	z-index: 99999;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 20px;
}
.vmsos-ws-modal-backdrop {
	position: absolute;
	inset: 0;
	background: rgba(0, 0, 0, 0.75);
	backdrop-filter: blur(8px);
}
.vmsos-ws-modal-card {
	position: relative;
	background: #0f172a;
	border: 1px solid var(--ws-border-glow);
	border-radius: 16px;
	width: 100%;
	max-width: 520px;
	padding: 24px;
	box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);
}
.vmsos-ws-modal-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 18px;
}
.vmsos-ws-modal-head h3 { margin: 0; color: #fff; font-size: 16px; font-weight: 700; }
.vmsos-ws-btn-close {
	background: transparent;
	border: none;
	color: var(--ws-muted);
	font-size: 16px;
	cursor: pointer;
}
.vmsos-ws-field {
	margin-bottom: 14px;
}
.vmsos-ws-field label {
	display: block;
	font-size: 12px;
	color: var(--ws-muted);
	margin-bottom: 6px;
	font-weight: 600;
}
.vmsos-ws-field input, .vmsos-ws-field select, .vmsos-ws-field textarea {
	width: 100%;
	background: #070a13;
	border: 1px solid var(--ws-border);
	color: #fff;
	padding: 10px 12px;
	border-radius: 8px;
	font-size: 13px;
	box-sizing: border-box;
}
.vmsos-ws-field input:focus, .vmsos-ws-field select:focus, .vmsos-ws-field textarea:focus {
	outline: none;
	border-color: var(--ws-neon-indigo);
	box-shadow: 0 0 10px rgba(99, 102, 241, 0.3);
}
.vmsos-ws-modal-footer {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 20px;
}

/* Voice Commander & Brain Styles */
.vmsos-ws-voice-card {
	border-color: rgba(236, 72, 153, 0.4);
	box-shadow: 0 25px 60px -10px rgba(236, 72, 153, 0.25), 0 0 40px rgba(99, 102, 241, 0.2);
}
.vmsos-ws-voice-head-info {
	display: flex;
	align-items: center;
	gap: 12px;
}
.vmsos-ws-voice-avatar {
	width: 42px;
	height: 42px;
	border-radius: 12px;
	background: linear-gradient(135deg, #ec4899, #8b5cf6);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 22px;
	box-shadow: 0 0 15px rgba(236, 72, 153, 0.5);
}
.vmsos-ws-voice-status-sub {
	font-size: 11px;
	color: #a5b4fc;
}

/* Waveform Visualizer */
.vmsos-ws-visualizer-wrap {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 24px 10px 16px;
	background: rgba(0, 0, 0, 0.25);
	border-radius: 14px;
	border: 1px solid rgba(255, 255, 255, 0.05);
	margin-bottom: 16px;
}
.vmsos-ws-visualizer {
	display: flex;
	align-items: center;
	gap: 6px;
	height: 40px;
	margin-bottom: 10px;
}
.vmsos-ws-bar {
	display: inline-block;
	width: 5px;
	height: 12px;
	border-radius: 4px;
	background: #6366f1;
	transition: height 0.15s ease, background 0.2s;
}
.vmsos-ws-visualizer.active .vmsos-ws-bar {
	background: linear-gradient(to top, #ec4899, #8b5cf6);
	animation: vmsos-wave 0.8s infinite ease-in-out alternate;
}
.vmsos-ws-visualizer.active .vmsos-ws-bar:nth-child(1) { animation-delay: 0.1s; }
.vmsos-ws-visualizer.active .vmsos-ws-bar:nth-child(2) { animation-delay: 0.25s; }
.vmsos-ws-visualizer.active .vmsos-ws-bar:nth-child(3) { animation-delay: 0.4s; }
.vmsos-ws-visualizer.active .vmsos-ws-bar:nth-child(4) { animation-delay: 0.15s; }
.vmsos-ws-visualizer.active .vmsos-ws-bar:nth-child(5) { animation-delay: 0.35s; }
.vmsos-ws-visualizer.active .vmsos-ws-bar:nth-child(6) { animation-delay: 0.2s; }
.vmsos-ws-visualizer.active .vmsos-ws-bar:nth-child(7) { animation-delay: 0.3s; }

@keyframes vmsos-wave {
	0% { height: 8px; }
	100% { height: 38px; }
}

.vmsos-ws-voice-indicator {
	font-size: 12px;
	color: var(--ws-muted);
}
.vmsos-ws-voice-indicator.listening {
	color: #f43f5e;
	font-weight: 700;
	animation: vmsos-pulse 1s infinite alternate;
}
@keyframes vmsos-pulse {
	0% { opacity: 0.6; }
	100% { opacity: 1; }
}

/* Voice Message Stream */
.vmsos-ws-voice-stream {
	max-height: 200px;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 10px;
	background: rgba(7, 10, 19, 0.6);
	border-radius: 10px;
	border: 1px solid rgba(255, 255, 255, 0.05);
	margin-bottom: 14px;
	font-size: 13px;
	line-height: 1.5;
}
.vmsos-ws-voice-msg {
	padding: 8px 12px;
	border-radius: 8px;
}
.vmsos-ws-msg-bot {
	background: rgba(99, 102, 241, 0.12);
	border-left: 3px solid #6366f1;
	color: #e0e7ff;
}
.vmsos-ws-msg-user {
	background: rgba(236, 72, 153, 0.12);
	border-left: 3px solid #ec4899;
	color: #fce7f3;
}

/* Prompt Chips */
.vmsos-ws-voice-chips {
	display: flex;
	gap: 6px;
	overflow-x: auto;
	padding-bottom: 8px;
	margin-bottom: 14px;
	-webkit-overflow-scrolling: touch;
}
.vmsos-ws-chip {
	background: var(--ws-surface);
	border: 1px solid var(--ws-border);
	color: var(--ws-text);
	font-size: 11px;
	padding: 6px 12px;
	border-radius: 20px;
	cursor: pointer;
	white-space: nowrap;
	flex-shrink: 0;
	transition: all 0.2s;
}
.vmsos-ws-chip:hover {
	border-color: #ec4899;
	color: #fff;
	background: rgba(236, 72, 153, 0.15);
}

/* Voice Input Controls */
.vmsos-ws-voice-controls {
	display: flex;
	align-items: center;
	gap: 8px;
}
.vmsos-ws-voice-controls input {
	flex: 1;
	background: #070a13;
	border: 1px solid var(--ws-border);
	color: #fff;
	padding: 10px 14px;
	border-radius: 10px;
	font-size: 13px;
	box-sizing: border-box;
}
.vmsos-ws-voice-controls input:focus {
	outline: none;
	border-color: #ec4899;
	box-shadow: 0 0 12px rgba(236, 72, 153, 0.35);
}
.vmsos-ws-mic-btn {
	width: 44px;
	height: 44px;
	border-radius: 50%;
	background: #1e293b;
	border: 2px solid rgba(255, 255, 255, 0.1);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 20px;
	cursor: pointer;
	transition: all 0.2s;
	flex-shrink: 0;
}
.vmsos-ws-mic-btn:hover {
	transform: scale(1.05);
	border-color: #ec4899;
}
.vmsos-ws-mic-btn.active {
	background: #f43f5e;
	border-color: #fff;
	box-shadow: 0 0 20px rgba(244, 63, 94, 0.8);
	animation: vmsos-mic-pulse 1s infinite alternate;
}
@keyframes vmsos-mic-pulse {
	0% { transform: scale(1); }
	100% { transform: scale(1.08); }
}

/* ==========================================================================
   MOBILE & TABLET RESPONSIVE OVERHAULS
   ========================================================================== */

/* Tablet & Smaller Desktops (<= 1024px) */
@media (max-width: 1024px) {
	.vmsos-ws-container {
		padding: 18px;
		margin: 12px auto;
		border-radius: 14px;
	}
	.vmsos-ws-main-layout {
		grid-template-columns: 1fr;
		gap: 16px;
	}
	.vmsos-ws-kpi-grid {
		grid-template-columns: repeat(2, 1fr);
		gap: 12px;
	}
}

/* Mobile Devices (<= 768px) */
@media (max-width: 768px) {
	.vmsos-ws-container {
		padding: 12px 10px;
		margin: 8px auto;
		border-radius: 12px;
		max-width: 100vw;
		overflow-x: hidden;
	}

	/* Security Gate Card */
	.vmsos-ws-gate {
		padding: 30px 10px;
	}
	.vmsos-ws-gate-card {
		padding: 24px 16px;
		border-radius: 14px;
	}
	.vmsos-ws-gate-card h2 {
		font-size: 18px;
	}
	.vmsos-ws-gate-card p {
		font-size: 13px;
	}
	.vmsos-ws-gate-actions .vmsos-ws-btn {
		width: 100%;
		box-sizing: border-box;
		padding: 12px 16px;
	}

	/* HUD Header Mobile */
	.vmsos-ws-hud {
		flex-direction: column;
		align-items: stretch;
		gap: 14px;
		padding-bottom: 14px;
		margin-bottom: 16px;
	}
	.vmsos-ws-hud-brand {
		gap: 10px;
	}
	.vmsos-ws-hud-logo {
		width: 36px;
		height: 36px;
		font-size: 18px;
		border-radius: 10px;
		flex-shrink: 0;
	}
	.vmsos-ws-hud-title {
		font-size: 14px;
		line-height: 1.25;
		word-break: break-word;
	}
	.vmsos-ws-hud-sub {
		font-size: 11px;
		line-height: 1.3;
		margin-top: 2px;
	}
	.vmsos-ws-hud-pills {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 8px;
		width: 100%;
	}
	.vmsos-ws-hud-pills .vmsos-ws-pill {
		font-size: 11px;
		padding: 8px 10px;
		justify-content: center;
		text-align: center;
		min-width: 0;
	}
	.vmsos-ws-hud-pills .vmsos-ws-pill span,
	.vmsos-ws-hud-pills .vmsos-ws-pill strong {
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	#vmsos-ws-refresh-btn {
		grid-column: 1 / 2;
		width: 100%;
		padding: 8px 12px;
		font-size: 12px;
	}
	#vmsos-ws-open-dispatch {
		grid-column: 2 / 3;
		width: 100%;
		padding: 8px 12px;
		font-size: 12px;
	}

	/* KPI Cards: Responsive 2-Col Grid */
	.vmsos-ws-kpi-grid {
		grid-template-columns: repeat(2, 1fr);
		gap: 10px;
		margin-bottom: 16px;
	}
	.vmsos-ws-kpi-card {
		padding: 14px 12px;
		border-radius: 10px;
	}
	.vmsos-ws-kpi-label {
		font-size: 10px;
		letter-spacing: 0.3px;
	}
	.vmsos-ws-kpi-val {
		font-size: 18px;
		word-break: break-word;
	}
	.vmsos-ws-kpi-meta {
		font-size: 10px;
		margin-top: 4px;
	}

	/* Section Containers */
	.vmsos-ws-section {
		padding: 14px;
		border-radius: 12px;
		box-sizing: border-box;
	}
	.vmsos-ws-section-head {
		flex-direction: column;
		align-items: stretch;
		gap: 10px;
		margin-bottom: 14px;
	}
	.vmsos-ws-section-head h3 {
		font-size: 15px;
	}

	/* Agent Cards */
	.vmsos-ws-agent-grid {
		grid-template-columns: 1fr;
		gap: 10px;
		max-height: 420px;
	}
	.vmsos-ws-agent-card {
		padding: 12px;
	}
	.vmsos-ws-agent-title {
		max-width: 100%;
		white-space: normal;
		overflow: visible;
		text-overflow: clip;
	}

	/* Growth Tabs */
	.vmsos-ws-tab-group {
		width: 100%;
		display: flex;
		gap: 4px;
	}
	.vmsos-ws-tab {
		flex: 1;
		text-align: center;
		padding: 8px 4px;
		font-size: 11px;
	}

	/* Chart Card */
	.vmsos-ws-chart-card {
		padding: 12px;
	}
	.vmsos-ws-chart-header {
		flex-direction: column;
		align-items: stretch;
		gap: 10px;
	}
	.vmsos-ws-chart-legend {
		flex-wrap: wrap;
		gap: 8px;
		font-size: 10px;
	}
	.vmsos-ws-milestone-box {
		width: 100%;
		box-sizing: border-box;
		display: flex;
		justify-content: space-between;
		align-items: center;
		text-align: left;
		padding: 8px 12px;
	}
	.vmsos-ws-milestone-box strong {
		display: inline;
		font-size: 15px;
	}
	.vmsos-ws-svg-wrap {
		height: 140px;
	}

	/* WHAT'S GROWING COLUMNS: Break 2 columns to 1 full-width column on phone */
	.vmsos-ws-growing-columns {
		grid-template-columns: 1fr;
		gap: 10px;
	}
	.vmsos-ws-mini-panel {
		padding: 12px;
	}

	/* Task Filters Horizontal Scrollable Bar */
	.vmsos-ws-task-filters {
		width: 100%;
		overflow-x: auto;
		white-space: nowrap;
		padding-bottom: 6px;
		gap: 6px;
		-webkit-overflow-scrolling: touch;
	}
	.vmsos-ws-filter {
		padding: 6px 12px;
		font-size: 11px;
		flex-shrink: 0;
	}

	/* Task Table Mobile Responsive Overflow Scroll */
	.vmsos-ws-task-table-wrap {
		width: 100%;
		overflow-x: auto;
		-webkit-overflow-scrolling: touch;
		border-radius: 8px;
		border: 1px solid rgba(255, 255, 255, 0.05);
	}
	.vmsos-ws-table {
		min-width: 540px;
		font-size: 12px;
	}
	.vmsos-ws-table th, .vmsos-ws-table td {
		padding: 10px 8px;
	}

	/* Dispatch Mission Modal */
	.vmsos-ws-modal {
		padding: 10px;
	}
	.vmsos-ws-modal-card {
		padding: 16px;
		max-height: 92vh;
		overflow-y: auto;
		box-sizing: border-box;
	}
	.vmsos-ws-modal-footer {
		flex-direction: column-reverse;
		gap: 8px;
	}
	.vmsos-ws-modal-footer .vmsos-ws-btn {
		width: 100%;
		padding: 12px;
	}
	.vmsos-ws-field input,
	.vmsos-ws-field select,
	.vmsos-ws-field textarea {
		font-size: 16px; /* Prevents auto-zoom in iOS Safari on focus */
	}
}

/* Extra-Small Phones (<= 380px) */
@media (max-width: 380px) {
	.vmsos-ws-kpi-grid {
		grid-template-columns: 1fr;
	}
	.vmsos-ws-hud-pills {
		grid-template-columns: 1fr;
	}
	#vmsos-ws-refresh-btn,
	#vmsos-ws-open-dispatch {
		grid-column: 1 / 2;
	}
}
</style>

<script>
(function() {
	if ( ! document.getElementById( 'vmsos-workspace-root' ) ) return;

	var REST_URL = '/wp-json/vmsos/v1/workspace';
	var NONCE = '<?php echo esc_js( $restNonce ); ?>';

	var state = {
		overview: null,
		horizon: 30,
		filter: 'all'
	};

	function fetchOverview() {
		return fetch( REST_URL + '/overview', {
			headers: { 'X-WP-Nonce': NONCE }
		} )
		.then( function( r ) { return r.json(); } )
		.then( function( json ) {
			if ( json && json.ok ) {
				state.overview = json.data;
				renderAll();
			}
		} )
		.catch( function( err ) { console.error('Workspace fetch error:', err); } );
	}

	function renderAll() {
		if ( ! state.overview ) return;
		var d = state.overview;

		// HUD Pills
		var agentsOnline = ( d.agents || [] ).length;
		document.getElementById( 'vmsos-ws-stat-fleet' ).textContent = 'Fleet: ' + agentsOnline + ' Sovereign Agents';
		document.getElementById( 'vmsos-ws-fleet-count' ).textContent = agentsOnline + ' Agents Active';

		var taskTotal = d.task_counts ? d.task_counts.total : 0;
		document.getElementById( 'vmsos-ws-stat-tasks' ).textContent = 'Workflows: ' + taskTotal + ' Tasks';

		// KPIs
		var growing = d.growing || {};
		var pipelineVal = ( growing.pipeline && growing.pipeline.total_value ) ? Number( growing.pipeline.total_value ).toLocaleString('en-IN') : '0';
		document.getElementById( 'kpi-pipeline' ).textContent = '₹' + pipelineVal;
		if ( growing.pipeline ) {
			document.getElementById( 'kpi-pipeline-meta' ).textContent = ( growing.pipeline.total_deals || 0 ) + ' Active Deals (' + ( growing.pipeline.win_rate || 0 ) + '% Win Rate)';
		}

		var leads = growing.leads || {};
		document.getElementById( 'kpi-leads' ).textContent = ( leads.current_30d || 0 ) + ' leads';
		document.getElementById( 'kpi-leads-growth' ).textContent = ( leads.growth_mom >= 0 ? '+' : '' ) + ( leads.growth_mom || 0 ) + '% MoM';
		document.getElementById( 'vmsos-ws-stat-growth' ).textContent = ( leads.growth_mom >= 0 ? '+' : '' ) + ( leads.growth_mom || 0 ) + '% MoM';

		// Forecast KPIs
		var forecast = d.forecast || {};
		var mKey = state.horizon + '_days';
		var m = ( forecast.milestones && forecast.milestones[mKey] ) ? forecast.milestones[mKey] : ( forecast.milestones && forecast.milestones['60_days'] );
		if ( m ) {
			document.getElementById( 'kpi-forecast-rev' ).textContent = '₹' + Number( m.projected_revenue ).toLocaleString('en-IN');
			document.getElementById( 'kpi-forecast-traffic' ).textContent = Number( m.projected_traffic ).toLocaleString('en-IN') + ' /day';
			document.getElementById( 'forecast-milestone-highlight' ).innerHTML = '<strong>' + Number( m.projected_traffic ).toLocaleString('en-IN') + ' /day</strong><span>' + state.horizon + '-Day Trajectory</span>';
		}

		// Agent Cards
		renderAgents( d.agents || [] );

		// Trajectory SVG Chart
		renderChart( forecast.timeline || [] );

		// What's Growing Channels
		renderChannels( growing.channels || [] );

		// Top Offerings
		renderOfferings( growing.top_offerings || [] );

		// Tasks Table
		renderTasks( d.tasks || [] );

		// Populate Dispatch Dropdown
		populateAgentSelect( d.agents || [] );
	}

	function renderAgents( agents ) {
		var wrap = document.getElementById( 'vmsos-ws-agent-cards' );
		if ( ! agents.length ) {
			wrap.innerHTML = '<div class="vmsos-ws-loading">No active agents reported by Paperclip daemon.</div>';
			return;
		}

		var html = '';
		agents.forEach( function( a ) {
			var icon = a.role === 'cmo' ? '📈' : ( a.role === 'researcher' ? '🎯' : ( a.role === 'pm' ? '📋' : '⚡' ) );
			html += '<div class="vmsos-ws-agent-card">';
			html += '  <div>';
			html += '    <div class="vmsos-ws-agent-top">';
			html += '      <div class="vmsos-ws-agent-avatar">' + icon + '</div>';
			html += '      <div>';
			html += '        <div class="vmsos-ws-agent-name">' + escHtml( a.name ) + '</div>';
			html += '        <div class="vmsos-ws-agent-title" title="' + escHtml( a.title ) + '">' + escHtml( a.title || a.role ) + '</div>';
			html += '      </div>';
			html += '    </div>';
			html += '  </div>';
			html += '  <div class="vmsos-ws-agent-meta">';
			html += '    <span class="vmsos-ws-agent-status ' + ( a.status === 'running' ? 'running' : 'idle' ) + '">';
			html += '      <span class="vmsos-ws-dot"></span> ' + escHtml( a.status );
			html += '    </span>';
			html += '    <button class="vmsos-ws-btn vmsos-ws-btn-sm vmsos-ws-btn-ghost vmsos-ws-quick-dispatch" data-agent-id="' + escHtml( a.id ) + '" data-agent-name="' + escHtml( a.name ) + '">+ Dispatch</button>';
			html += '  </div>';
			html += '</div>';
		} );
		wrap.innerHTML = html;

		// Wire quick dispatch buttons
		wrap.querySelectorAll( '.vmsos-ws-quick-dispatch' ).forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				var aid = btn.getAttribute( 'data-agent-id' );
				document.getElementById( 'vmsos-disp-agent' ).value = aid;
				openModal();
			} );
		} );
	}

	function renderChart( timeline ) {
		var wrap = document.getElementById( 'vmsos-ws-svg-chart' );
		if ( ! timeline || ! timeline.length ) {
			wrap.innerHTML = '<div class="vmsos-ws-loading">No forecast timeline available.</div>';
			return;
		}

		var maxTraffic = 0;
		timeline.forEach( function( t ) {
			if ( t.optimistic_traffic > maxTraffic ) maxTraffic = t.optimistic_traffic;
		} );
		maxTraffic = maxTraffic * 1.1;

		var w = 560;
		var h = 180;
		var padX = 35;
		var padY = 25;

		var pointsExpected = [];
		var pointsOpt = [];
		var pointsCons = [];

		timeline.forEach( function( pt, i ) {
			var x = padX + ( i / ( timeline.length - 1 ) ) * ( w - padX * 2 );
			var yExp = h - padY - ( ( pt.daily_traffic ) / maxTraffic ) * ( h - padY * 2 );
			var yOpt = h - padY - ( ( pt.optimistic_traffic ) / maxTraffic ) * ( h - padY * 2 );
			var yCons = h - padY - ( ( pt.conservative_traffic ) / maxTraffic ) * ( h - padY * 2 );

			pointsExpected.push( x.toFixed(1) + ',' + yExp.toFixed(1) );
			pointsOpt.push( x.toFixed(1) + ',' + yOpt.toFixed(1) );
			pointsCons.push( x.toFixed(1) + ',' + yCons.toFixed(1) );
		} );

		var svg = '<svg viewBox="0 0 ' + w + ' ' + h + '">';
		// Grid lines
		svg += '<line x1="' + padX + '" y1="' + (h - padY) + '" x2="' + (w - padX) + '" y2="' + (h - padY) + '" stroke="rgba(255,255,255,0.08)" />';
		svg += '<line x1="' + padX + '" y1="' + (padY) + '" x2="' + (w - padX) + '" y2="' + (padY) + '" stroke="rgba(255,255,255,0.08)" stroke-dasharray="3,3" />';

		// Curves
		svg += '<polyline fill="none" stroke="#10b981" stroke-width="2" stroke-dasharray="4,4" points="' + pointsOpt.join(' ') + '" />';
		svg += '<polyline fill="none" stroke="#f59e0b" stroke-width="2" stroke-dasharray="4,4" points="' + pointsCons.join(' ') + '" />';
		svg += '<polyline fill="none" stroke="#6366f1" stroke-width="3" points="' + pointsExpected.join(' ') + '" />';

		// Dots on expected path
		timeline.forEach( function( pt, i ) {
			var parts = pointsExpected[i].split(',');
			svg += '<circle cx="' + parts[0] + '" cy="' + parts[1] + '" r="4" fill="#6366f1" stroke="#fff" stroke-width="1.5"><title>Day ' + pt.day + ': ' + pt.daily_traffic.toLocaleString() + ' visits</title></circle>';
		} );

		svg += '</svg>';
		wrap.innerHTML = svg;
	}

	function renderChannels( channels ) {
		var wrap = document.getElementById( 'vmsos-ws-channels-list' );
		var html = '';
		channels.forEach( function( c ) {
			html += '<div class="vmsos-ws-list-item">';
			html += '  <span>' + escHtml( c.channel ) + '</span>';
			html += '  <strong class="vmsos-ws-growth-up">' + escHtml( c.growth ) + '</strong>';
			html += '</div>';
		} );
		wrap.innerHTML = html;
	}

	function renderOfferings( items ) {
		var wrap = document.getElementById( 'vmsos-ws-catalog-list' );
		if ( ! items.length ) {
			wrap.innerHTML = '<div class="vmsos-ws-loading">No catalog offerings seeded.</div>';
			return;
		}
		var html = '';
		items.forEach( function( it ) {
			var price = Number( it.price ).toLocaleString('en-IN');
			html += '<div class="vmsos-ws-list-item">';
			html += '  <span>' + escHtml( it.name ) + '</span>';
			html += '  <strong>₹' + price + '</strong>';
			html += '</div>';
		} );
		wrap.innerHTML = html;
	}

	function renderTasks( tasks ) {
		var tbody = document.getElementById( 'vmsos-ws-tasks-body' );
		if ( ! tasks.length ) {
			tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding: 20px; color: var(--ws-muted);">No active tasks in workflow queue.</td></tr>';
			return;
		}

		var filtered = tasks.filter( function( t ) {
			if ( state.filter === 'all' ) return true;
			return ( t.status || 'todo' ) === state.filter;
		} );

		var html = '';
		filtered.forEach( function( t ) {
			var pri = ( t.priority || 'medium' ).toLowerCase();
			var priClass = 'vmsos-ws-priority-' + pri;
			var idText = t.identifier || ('#' + t.id.slice(0, 8));
			var assignee = t.assigneeAgentId ? 'Agent Assigned' : 'Unassigned';

			html += '<tr>';
			html += '  <td class="vmsos-ws-task-id">' + escHtml( idText ) + '</td>';
			html += '  <td><strong>' + escHtml( t.title ) + '</strong></td>';
			html += '  <td><span class="vmsos-ws-agent-status running"><span class="vmsos-ws-dot"></span> ' + escHtml( t.status ) + '</span></td>';
			html += '  <td><span class="vmsos-ws-priority-badge ' + priClass + '">' + escHtml( pri ) + '</span></td>';
			html += '  <td style="color: var(--ws-muted); font-size: 11px;">' + escHtml( assignee ) + '</td>';
			html += '</tr>';
		} );
		tbody.innerHTML = html;
	}

	function populateAgentSelect( agents ) {
		var sel = document.getElementById( 'vmsos-disp-agent' );
		sel.innerHTML = '<option value="">Select Target Agent...</option>';
		agents.forEach( function( a ) {
			var opt = document.createElement( 'option' );
			opt.value = a.id;
			opt.textContent = a.name + ' (' + ( a.title || a.role ) + ')';
			sel.appendChild( opt );
		} );
	}

	function escHtml( str ) {
		if ( ! str ) return '';
		return String( str ).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	// Modal Controls
	var modal = document.getElementById( 'vmsos-ws-modal' );
	function openModal() { if ( modal ) modal.hidden = false; }
	function closeModal() { if ( modal ) modal.hidden = true; }

	if ( document.getElementById( 'vmsos-ws-open-dispatch' ) ) {
		document.getElementById( 'vmsos-ws-open-dispatch' ).addEventListener( 'click', openModal );
	}
	if ( document.getElementById( 'vmsos-ws-modal-close' ) ) {
		document.getElementById( 'vmsos-ws-modal-close' ).addEventListener( 'click', closeModal );
	}
	if ( document.getElementById( 'vmsos-ws-cancel-btn' ) ) {
		document.getElementById( 'vmsos-ws-cancel-btn' ).addEventListener( 'click', closeModal );
	}

	// Dispatch Form Submission
	var form = document.getElementById( 'vmsos-ws-dispatch-form' );
	if ( form ) {
		form.addEventListener( 'submit', function( e ) {
			e.preventDefault();
			var submitBtn = document.getElementById( 'vmsos-ws-submit-btn' );
			submitBtn.disabled = true;
			submitBtn.textContent = 'Dispatching...';

			var payload = {
				title: document.getElementById( 'vmsos-disp-title' ).value,
				agent_id: document.getElementById( 'vmsos-disp-agent' ).value,
				priority: document.getElementById( 'vmsos-disp-priority' ).value,
				description: document.getElementById( 'vmsos-disp-desc' ).value
			};

			fetch( REST_URL + '/dispatch', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE
				},
				body: JSON.stringify( payload )
			} )
			.then( function( r ) { return r.json(); } )
			.then( function( res ) {
				submitBtn.disabled = false;
				submitBtn.textContent = '⚡ Dispatch Mission';
				if ( res && res.ok ) {
					alert( 'Mission dispatched successfully to autonomous agent!' );
					closeModal();
					form.reset();
					fetchOverview();
				} else {
					alert( 'Dispatch failed: ' + ( res.error || 'Server error' ) );
				}
			} )
			.catch( function( err ) {
				submitBtn.disabled = false;
				submitBtn.textContent = '⚡ Dispatch Mission';
				alert( 'Network error during dispatch.' );
			} );
		} );
	}

	// Horizon Tabs
	document.querySelectorAll( '.vmsos-ws-tab' ).forEach( function( tab ) {
		tab.addEventListener( 'click', function() {
			document.querySelectorAll( '.vmsos-ws-tab' ).forEach( function( t ) { t.classList.remove( 'active' ); } );
			tab.classList.add( 'active' );
			state.horizon = parseInt( tab.getAttribute( 'data-horizon' ), 10 ) || 30;
			renderAll();
		} );
	} );

	// Filter Tabs
	document.querySelectorAll( '.vmsos-ws-filter' ).forEach( function( f ) {
		f.addEventListener( 'click', function() {
			document.querySelectorAll( '.vmsos-ws-filter' ).forEach( function( t ) { t.classList.remove( 'active' ); } );
			f.classList.add( 'active' );
			state.filter = f.getAttribute( 'data-filter' );
			if ( state.overview ) renderTasks( state.overview.tasks || [] );
		} );
	} );

	// Refresh Button
	if ( document.getElementById( 'vmsos-ws-refresh-btn' ) ) {
		document.getElementById( 'vmsos-ws-refresh-btn' ).addEventListener( 'click', function() {
			var b = document.getElementById( 'vmsos-ws-refresh-btn' );
			b.textContent = 'Syncing...';
			fetchOverview().then( function() { b.textContent = '🔄 Sync'; } );
		} );
	}

	// Responsive SVG resize watcher
	var resizeTimer;
	window.addEventListener( 'resize', function() {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( function() {
			if ( state.overview && state.overview.forecast ) {
				renderChart( state.overview.forecast.timeline || [] );
			}
		}, 150 );
	} );

	// Self-Healing Button Listener
	var healBtn = document.getElementById( 'vmsos-ws-heal-btn' );
	if ( healBtn ) {
		healBtn.addEventListener( 'click', function() {
			healBtn.disabled = true;
			healBtn.textContent = '🛡️ Healing...';
			fetch( REST_URL + '/brain/heal', {
				method: 'POST',
				headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' }
			} )
			.then( function( r ) { return r.json(); } )
			.then( function( res ) {
				healBtn.disabled = false;
				healBtn.textContent = '🛡️ Self-Heal';
				if ( res && res.ok ) {
					var count = res.data.actions_count || 0;
					alert( 'Self-Healing Complete!\n' + ( count > 0 ? res.data.actions.join('\n') : 'All daemons, containers, and databases nominal.' ) );
					fetchOverview();
				} else {
					alert( 'Healing check failed: ' + ( res.error || 'Server error' ) );
				}
			} )
			.catch( function( err ) {
				healBtn.disabled = false;
				healBtn.textContent = '🛡️ Self-Heal';
				alert( 'Network error during self-healing routine.' );
			} );
		} );
	}

	// Voice Commander Modal & WebRTC Voice Assistant
	var voiceModal = document.getElementById( 'vmsos-ws-voice-modal' );
	var voiceBtn = document.getElementById( 'vmsos-ws-voice-btn' );
	var voiceClose = document.getElementById( 'vmsos-ws-voice-close' );
	var voiceBackdrop = document.getElementById( 'vmsos-ws-voice-backdrop' );
	var micBtn = document.getElementById( 'vmsos-mic-btn' );
	var voiceInput = document.getElementById( 'vmsos-voice-input' );
	var sendBtn = document.getElementById( 'vmsos-send-btn' );
	var visualizer = document.getElementById( 'vmsos-ws-visualizer' );
	var voiceIndicator = document.getElementById( 'vmsos-voice-indicator' );
	var voiceStream = document.getElementById( 'vmsos-voice-stream' );

	function openVoiceModal() {
		if ( voiceModal ) voiceModal.removeAttribute( 'hidden' );
		if ( voiceInput ) voiceInput.focus();
	}
	function closeVoiceModal() {
		if ( voiceModal ) voiceModal.setAttribute( 'hidden', '' );
		stopSpeechRecognition();
		if ( window.speechSynthesis ) window.speechSynthesis.cancel();
	}

	if ( voiceBtn ) voiceBtn.addEventListener( 'click', openVoiceModal );
	if ( voiceClose ) voiceClose.addEventListener( 'click', closeVoiceModal );
	if ( voiceBackdrop ) voiceBackdrop.addEventListener( 'click', closeVoiceModal );

	// Speech Recognition setup (Voice to Text)
	var recognition = null;
	var isListening = false;
	var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

	if ( SpeechRecognition ) {
		recognition = new SpeechRecognition();
		recognition.continuous = false;
		recognition.interimResults = false;
		recognition.lang = 'en-US';

		recognition.onstart = function() {
			isListening = true;
			if ( micBtn ) micBtn.classList.add( 'active' );
			if ( visualizer ) visualizer.classList.add( 'active' );
			if ( voiceIndicator ) {
				voiceIndicator.textContent = 'Listening to your voice...';
				voiceIndicator.classList.add( 'listening' );
			}
		};

		recognition.onresult = function( event ) {
			var transcript = event.results[0][0].transcript;
			if ( voiceInput ) voiceInput.value = transcript;
			sendVoiceQuery( transcript );
		};

		recognition.onerror = function( event ) {
			console.warn( 'Speech recognition error:', event.error );
			stopSpeechRecognition();
		};

		recognition.onend = function() {
			stopSpeechRecognition();
		};
	}

	function startSpeechRecognition() {
		if ( ! recognition ) {
			alert( 'Speech Recognition is not supported by your browser. You can type commands into the input box directly.' );
			return;
		}
		try {
			recognition.start();
		} catch ( e ) {
			console.warn( e );
		}
	}

	function stopSpeechRecognition() {
		isListening = false;
		if ( micBtn ) micBtn.classList.remove( 'active' );
		if ( visualizer ) visualizer.classList.remove( 'active' );
		if ( voiceIndicator ) {
			voiceIndicator.textContent = 'Tap microphone or speak...';
			voiceIndicator.classList.remove( 'listening' );
		}
		if ( recognition ) {
			try { recognition.stop(); } catch( e ) {}
		}
	}

	if ( micBtn ) {
		micBtn.addEventListener( 'click', function() {
			if ( isListening ) {
				stopSpeechRecognition();
			} else {
				startSpeechRecognition();
			}
		} );
	}

	// Audio speech synthesis (Voice response)
	function speakText( text ) {
		if ( ! window.speechSynthesis ) return;
		window.speechSynthesis.cancel();
		var cleaned = text.replace( /[\*\_#`]/g, '' );
		var utterance = new SpeechSynthesisUtterance( cleaned );
		utterance.rate = 1.05;
		utterance.pitch = 1.0;
		if ( visualizer ) visualizer.classList.add( 'active' );
		utterance.onend = function() {
			if ( visualizer ) visualizer.classList.remove( 'active' );
		};
		utterance.onerror = function() {
			if ( visualizer ) visualizer.classList.remove( 'active' );
		};
		window.speechSynthesis.speak( utterance );
	}

	// Send Voice / Text Query to Brain Endpoint
	function sendVoiceQuery( query ) {
		if ( ! query || ! query.trim() ) return;
		var q = query.trim();
		if ( voiceInput ) voiceInput.value = '';

		// Append User Msg
		appendVoiceMessage( 'user', 'You: ' + q );

		if ( visualizer ) visualizer.classList.add( 'active' );
		if ( voiceIndicator ) voiceIndicator.textContent = 'Brain reasoning...';

		fetch( REST_URL + '/brain/ask', {
			method: 'POST',
			headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
			body: JSON.stringify( { query: q } )
		} )
		.then( function( r ) { return r.json(); } )
		.then( function( res ) {
			if ( voiceIndicator ) voiceIndicator.textContent = 'Tap microphone or speak...';
			if ( res && res.ok && res.data ) {
				var reply = res.data.response || 'Telemetry analyzed.';
				appendVoiceMessage( 'bot', 'VM Studio: ' + reply );
				speakText( reply );
			} else {
				var err = ( res && res.error ) ? res.error : 'Execution timeout.';
				appendVoiceMessage( 'bot', 'VM Studio Error: ' + err );
			}
		} )
		.catch( function( err ) {
			if ( visualizer ) visualizer.classList.remove( 'active' );
			if ( voiceIndicator ) voiceIndicator.textContent = 'Tap microphone or speak...';
			appendVoiceMessage( 'bot', 'Connection error to Brain gateway.' );
		} );
	}

	function appendVoiceMessage( role, text ) {
		if ( ! voiceStream ) return;
		var msg = document.createElement( 'div' );
		msg.className = 'vmsos-ws-voice-msg ' + ( role === 'user' ? 'vmsos-ws-msg-user' : 'vmsos-ws-msg-bot' );
		msg.textContent = text;
		voiceStream.appendChild( msg );
		voiceStream.scrollTop = voiceStream.scrollHeight;
	}

	if ( sendBtn ) {
		sendBtn.addEventListener( 'click', function() {
			if ( voiceInput && voiceInput.value ) sendVoiceQuery( voiceInput.value );
		} );
	}
	if ( voiceInput ) {
		voiceInput.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				if ( voiceInput.value ) sendVoiceQuery( voiceInput.value );
			}
		} );
	}

	// Quick Prompt Chips
	document.querySelectorAll( '.vmsos-ws-chip' ).forEach( function( chip ) {
		chip.addEventListener( 'click', function() {
			var prompt = chip.getAttribute( 'data-prompt' );
			if ( prompt ) sendVoiceQuery( prompt );
		} );
	} );

	// Initial load
	fetchOverview();
})();
</script>
