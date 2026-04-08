const API_BASE_URL = window.location.origin;

const state = {
  overview: null,
  reportToday: null,
  reportHistory: null,
  item: null,
  health: null,
  jobs: null,
  watchlist: null,
};

const euro = (value) => {
  if (typeof value !== "number" || Number.isNaN(value)) {
    return "-";
  }

  return `${value.toFixed(2).replace(".", ",")} EUR`;
};

const pct = (value) => {
  if (typeof value !== "number" || Number.isNaN(value)) {
    return "-";
  }

  return `${value > 0 ? "+" : ""}${value.toFixed(1).replace(".", ",")} %`;
};

const fetchJson = async (path, options = {}) => {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    headers: { "Content-Type": "application/json" },
    ...options,
  });

  const payload = await response.json();
  if (!response.ok) {
    throw new Error(payload.message ?? `API error on ${path}`);
  }

  return payload;
};

const formatCooldown = (seconds) => {
  if (!Number.isFinite(seconds) || seconds <= 0) {
    return "disponible";
  }

  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;
  if (minutes <= 0) {
    return `${remainder}s`;
  }

  return `${minutes}m ${String(remainder).padStart(2, "0")}s`;
};

const bindViews = () => {
  const buttons = document.querySelectorAll("[data-view-target]");
  const views = document.querySelectorAll("[data-view]");

  buttons.forEach((button) => {
    button.addEventListener("click", () => {
      const target = button.dataset.viewTarget;
      buttons.forEach((entry) => entry.classList.remove("is-active"));
      views.forEach((view) => view.classList.remove("is-active"));
      button.classList.add("is-active");
      document.querySelector(`[data-view="${target}"]`)?.classList.add("is-active");
    });
  });
};

const renderSimpleTable = (targetId, rows, formatter) => {
  const root = document.getElementById(targetId);
  if (!root) {
    return;
  }

  root.innerHTML = rows.map((row) => formatter(row)).join("");
};

const renderSignals = () => {
  const rows = state.overview?.top_signals ?? [];
  renderSimpleTable("top-signals", rows, (item) => `
    <article class="signal-item">
      <div class="skin-thumb">${item.weapon ?? "CS2"}</div>
      <div class="signal-item-meta">
        <strong>${item.name}</strong>
        <span class="table-meta">${euro(item.current_price)} • ${pct(item.change_24h)}</span>
        <div class="badge-row">${(item.tags ?? []).map((tag) => `<span class="badge">${tag}</span>`).join("")}</div>
      </div>
      <div class="signal-score">
        <span class="table-meta">Score</span>
        <strong>${item.interest_score ?? item.score ?? "-"}</strong>
      </div>
    </article>
  `);
};

const renderOpportunityBars = () => {
  const barsRoot = document.getElementById("opportunity-bars");
  const axisRoot = document.getElementById("opportunity-axis");
  const series = state.overview?.opportunity_series ?? [];
  const max = Math.max(1, ...series.map((bar) => bar.value));

  barsRoot.innerHTML = series.map((bar) => `
    <div class="bar-wrap">
      <span class="table-meta">${bar.value}</span>
      <div class="bar" style="height:${(bar.value / max) * 180}px"></div>
    </div>
  `).join("");

  axisRoot.innerHTML = series.map((bar) => `<span>${bar.day}</span>`).join("");
};

const renderOpportunities = () => {
  const rows = state.reportToday?.top_opportunities ?? [];
  renderSimpleTable("opportunity-cards", rows, (item) => `
    <article class="opportunity-card">
      <div class="opportunity-top">
        <div class="skin-thumb">${item.weapon ?? "CS2"}</div>
        <div class="opportunity-copy">
          <h5>${item.name}</h5>
          <div class="metric-row">
            <span>${euro(item.price)}</span>
            <span>${pct(item.change_24h)} 24h</span>
            <span>${pct(item.change_7d)} 7j</span>
          </div>
          <div class="metric-row">
            <span>${item.volume_24h ?? 0} ventes</span>
            <span>score ${item.score ?? "-"}/100</span>
          </div>
        </div>
      </div>
      <div class="reason">${item.reason ?? "signal live"}</div>
    </article>
  `);
};

const renderOverviewKpis = () => {
  const overview = state.overview;
  if (!overview) {
    return;
  }

  document.getElementById("hero-date").textContent = overview.date ?? "-";
  document.querySelectorAll(".kpi-value")[0].textContent = overview.items_tracked ?? "-";
  document.querySelectorAll(".kpi-value")[1].textContent = overview.items_in_range ?? "-";
  document.querySelectorAll(".kpi-value")[2].textContent = overview.opportunities_count ?? "-";
  document.querySelectorAll(".kpi-value")[3].textContent = overview.watchlist_moving_count ?? "-";
};

const renderReportSummary = () => {
  const report = state.reportToday;
  if (!report) {
    return;
  }

  const summaryPanel = document.querySelector(".summary-copy p + h4 + p");
  if (summaryPanel) {
    summaryPanel.textContent = report.summary_text ?? "";
  }

  const stats = document.querySelectorAll(".summary-stats strong");
  if (stats.length >= 3) {
    stats[0].textContent = report.items_scanned ?? "-";
    stats[1].textContent = report.items_in_range ?? "-";
    stats[2].textContent = report.opportunities_count ?? "-";
  }
};

const renderWatchlist = () => {
  renderSimpleTable("watchlist-table", state.watchlist?.data ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${item.name}</strong><span class="table-meta">${item.note}</span></div>
      <div class="value-stack"><strong>${euro(item.price)}</strong><span class="table-meta">watchlist</span></div>
    </article>
  `);
};

const renderGainersLosersVolume = () => {
  renderSimpleTable("gainers-list", state.reportToday?.top_gainers ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${item.name}</strong><span class="table-meta">hausse 24h</span></div>
      <div class="value-stack"><strong class="positive">${pct(item.change_24h)}</strong><span class="table-meta">${euro(item.price)}</span></div>
    </article>
  `);

  renderSimpleTable("losers-list", state.reportToday?.top_losers ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${item.name}</strong><span class="table-meta">baisse 24h</span></div>
      <div class="value-stack"><strong class="negative">${pct(item.change_24h)}</strong><span class="table-meta">${euro(item.price)}</span></div>
    </article>
  `);

  renderSimpleTable("volume-list", state.reportToday?.top_volume ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${item.name}</strong><span class="table-meta">accélération de liquidité</span></div>
      <div class="value-stack"><strong>x${(item.volume_ratio ?? 0).toFixed(1).replace(".", ",")}</strong><span class="table-meta">${item.volume_24h ?? 0} ventes</span></div>
    </article>
  `);
};

const renderHistory = () => {
  renderSimpleTable("reports-history", state.reportHistory?.data ?? [], (report) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${report.date}</strong><span class="table-meta">${report.summary_text ?? report.note ?? "rapport live"}</span></div>
      <div class="value-stack"><strong>${report.opportunities_count ?? "-"}</strong><span class="table-meta">opportunités</span></div>
    </article>
  `);
};

const renderItem = () => {
  const item = state.item;
  if (!item) {
    return;
  }

  const title = document.querySelector(".item-copy h4");
  if (title) {
    title.textContent = item.name;
  }

  const badges = document.querySelectorAll(".item-copy .badge");
  if (badges.length >= 3) {
    badges[0].textContent = `${pct(item.latest_snapshot?.change_vs_yesterday_pct)} 24h`;
    badges[1].textContent = `Score ${item.latest_snapshot?.interest_score ?? "-"}/100`;
    badges[2].textContent = `volume x${(item.latest_snapshot?.volume_ratio_24h_7d ?? 0).toFixed(1).replace(".", ",")}`;
  }

  const explanation = document.querySelector(".item-copy p:last-child");
  if (explanation) {
    explanation.textContent = item.explanation ?? "";
  }

  renderSimpleTable("item-history", item.history ?? [], (entry) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${entry.snapshot_date}</strong><span class="table-meta">volume ${entry.volume ?? 0}</span></div>
      <div class="value-stack"><strong>${euro(entry.current_price)}</strong><span class="table-meta">snapshot</span></div>
    </article>
  `);

  renderSimpleTable("item-signals", item.recent_listing_signals ?? [], (signal) => `
    <article class="table-item">
      <div class="table-item-main">
        <strong>${signal.float_value != null ? `float ${Number(signal.float_value).toFixed(4)}` : "listing CSFloat"}</strong>
        <span class="table-meta">
          ${signal.has_stickers ? `${signal.sticker_count ?? 0} stickers` : "sans stickers"} • vendeur ${signal.seller_score ?? "-"}
        </span>
      </div>
      <div class="value-stack">
        <strong>${typeof signal.listing_price === "number" ? `${signal.listing_price.toFixed(2).replace(".", ",")} USD` : "-"}</strong>
        <span class="table-meta">score ${signal.signal_score ?? "-"}</span>
      </div>
    </article>
  `);
};

const renderHealth = () => {
  const health = state.health;
  if (!health) {
    return;
  }

  const pill = document.getElementById("admin-health-pill");
  if (pill) {
    pill.textContent = health.status === "ok" ? "Sources live OK" : "Sources live à surveiller";
  }

  renderSimpleTable("api-health", health.sources ?? [], (source) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${source.name}</strong><span class="table-meta">${source.note}</span></div>
      <div class="value-stack"><strong class="${source.status === "ok" ? "positive" : (source.status === "ready" || source.status === "public" ? "neutral" : "negative")}">${source.status}</strong><span class="table-meta">source</span></div>
    </article>
  `);
};

const renderJobs = () => {
  renderSimpleTable("job-logs", state.jobs?.data ?? [], (job) => `
    <article class="log-entry">
      <span class="log-time">${(job.ended_at ?? "").slice(11, 16)}</span>
      <span class="log-line">${job.job_name} ${job.status} - ${job.log_excerpt}</span>
    </article>
  `);
};

const renderAdminActionsState = () => {
  const health = state.health;
  const syncMarketButton = document.querySelector('[data-job="sync-market"]');
  const cooldownNode = document.getElementById("market-cooldown");

  if (!health || !syncMarketButton || !cooldownNode) {
    return;
  }

  const remaining = Number(health.market_sync_cooldown_remaining ?? 0);
  const available = Boolean(health.market_sync_available ?? remaining === 0);

  syncMarketButton.disabled = !available;
  syncMarketButton.textContent = available ? "Sync market" : `Sync market (${formatCooldown(remaining)})`;
  cooldownNode.textContent = available
    ? "Skinport est disponible pour un nouveau sync."
    : `Skinport est en cooldown. Prochain lancement possible dans ${formatCooldown(remaining)}.`;
};

const renderAll = () => {
  renderOverviewKpis();
  renderSignals();
  renderOpportunityBars();
  renderOpportunities();
  renderReportSummary();
  renderWatchlist();
  renderGainersLosersVolume();
  renderHistory();
  renderItem();
  renderHealth();
  renderJobs();
  renderAdminActionsState();
};

const loadData = async () => {
  const [overview, reportToday, reportHistory, health, jobs, watchlist, items] = await Promise.all([
    fetchJson("/api/dashboard/overview"),
    fetchJson("/api/reports/today"),
    fetchJson("/api/reports/history"),
    fetchJson("/api/admin/health"),
    fetchJson("/api/admin/jobs"),
    fetchJson("/api/watchlist"),
    fetchJson("/api/items?per_page=1"),
  ]);

  const firstItem = items.data?.[0] ?? null;
  const item = firstItem ? await fetchJson(`/api/items/${firstItem.id}`) : null;

  Object.assign(state, { overview, reportToday, reportHistory, item, health, jobs, watchlist });
};

const setButtonsDisabled = (disabled) => {
  document.querySelectorAll(".action-button").forEach((button) => {
    if (button.dataset.job === "sync-market" && !disabled) {
      return;
    }

    button.disabled = disabled;
  });
};

const bindAdminActions = () => {
  document.querySelectorAll("[data-job]").forEach((button) => {
    button.addEventListener("click", async () => {
      const feedback = document.getElementById("job-feedback");
      const job = button.dataset.job;

      try {
        setButtonsDisabled(true);
        feedback.textContent = `Running ${job}...`;
        const result = await fetchJson(`/api/admin/jobs/${job}`, { method: "POST" });
        feedback.textContent = `${job} ${result.status} - ${result.log_excerpt ?? "done"}`;
        await loadData();
        renderAll();
      } catch (error) {
        feedback.textContent = error.message;
      } finally {
        setButtonsDisabled(false);
      }
    });
  });

  document.getElementById("refresh-live")?.addEventListener("click", async () => {
    const feedback = document.getElementById("job-feedback");
    try {
      setButtonsDisabled(true);
      feedback.textContent = "Refreshing live data...";
      await loadData();
      renderAll();
      feedback.textContent = "UI refreshed from live endpoints.";
    } catch (error) {
      feedback.textContent = error.message;
    } finally {
      setButtonsDisabled(false);
    }
  });
};

const init = async () => {
  bindViews();
  bindAdminActions();

  try {
    await loadData();
    renderAll();
  } catch (error) {
    const feedback = document.getElementById("job-feedback");
    if (feedback) {
      feedback.textContent = error.message;
    }
    console.error(error);
  }
};

init();
