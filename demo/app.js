const demoState = {
  topSignals: [
    { weapon: "AK-47", name: "AK-47 | Redline (Field-Tested)", price: "17,40 €", change: "+4,8 %", score: 78, tags: ["volume x1,9", "watchlist"] },
    { weapon: "AWP", name: "AWP | Asiimov (Battle-Scarred)", price: "71,20 €", change: "+3,6 %", score: 74, tags: ["drop récupéré", "liquide"] },
    { weapon: "M4A1-S", name: "M4A1-S | Printstream (Field-Tested)", price: "146,50 €", change: "-2,9 %", score: 72, tags: ["sous moyenne 7j", "volume stable"] },
  ],
  opportunities: [
    { weapon: "AK-47", name: "AK-47 | Redline (Field-Tested)", price: "17,40 €", change24h: "+4,8 %", change7d: "-3,1 %", volume: "48 ventes", score: "78/100", reason: "volume x2 vs moyenne 7j" },
    { weapon: "USP-S", name: "USP-S | Cortex (Minimal Wear)", price: "11,95 €", change24h: "+6,2 %", change7d: "-1,4 %", volume: "64 ventes", score: "76/100", reason: "watchlist + accélération" },
    { weapon: "Desert Eagle", name: "Desert Eagle | Printstream (Field-Tested)", price: "38,10 €", change24h: "+2,7 %", change7d: "-4,6 %", volume: "29 ventes", score: "73/100", reason: "prix sous moyenne 7j" },
    { weapon: "AWP", name: "AWP | Asiimov (Battle-Scarred)", price: "71,20 €", change24h: "+3,6 %", change7d: "-2,2 %", volume: "22 ventes", score: "71/100", reason: "retour de liquidité" },
  ],
  gainers: [
    ["USP-S | Cortex (Minimal Wear)", "+6,2 %", "11,95 €"],
    ["AK-47 | Redline (Field-Tested)", "+4,8 %", "17,40 €"],
    ["AWP | Asiimov (Battle-Scarred)", "+3,6 %", "71,20 €"],
  ],
  losers: [
    ["M4A1-S | Printstream (Field-Tested)", "-2,9 %", "146,50 €"],
    ["Glock-18 | Vogue (Field-Tested)", "-2,4 %", "6,70 €"],
    ["FAMAS | Commemoration (MW)", "-2,1 %", "14,20 €"],
  ],
  volume: [
    ["AK-47 | Redline (Field-Tested)", "x1,9", "48 ventes"],
    ["USP-S | Cortex (Minimal Wear)", "x2,2", "64 ventes"],
    ["M4A4 | Neo-Noir (FT)", "x1,8", "34 ventes"],
  ],
  watchlist: [
    ["AK-47 | Redline (FT)", "17,40 €", "Alerte sous 17,00 €"],
    ["USP-S | Cortex (MW)", "11,95 €", "Alerte au-dessus de 12,20 €"],
    ["AWP | Asiimov (BS)", "71,20 €", "Surveillance liquidité"],
    ["Desert Eagle | Printstream (FT)", "38,10 €", "Signal moyen 7j"],
  ],
  reports: [
    ["2026-04-08", "27 opportunités", "Rapport généré à 06:52"],
    ["2026-04-07", "22 opportunités", "Volume en baisse"],
    ["2026-04-06", "31 opportunités", "Pic sur rifles"],
    ["2026-04-05", "19 opportunités", "Marché plus calme"],
  ],
  itemHistory: [
    ["2026-04-04", "18,10 €", "volume 23"],
    ["2026-04-05", "17,85 €", "volume 25"],
    ["2026-04-06", "17,60 €", "volume 31"],
    ["2026-04-07", "16,60 €", "volume 42"],
    ["2026-04-08", "17,40 €", "volume 48"],
  ],
  health: [
    ["ByMykel", "OK", "catalogue + images à jour"],
    ["Skinport", "OK", "prix et historique récupérés"],
    ["CSFloat", "Dégradé", "timeouts sur une partie des listings"],
  ],
  logs: [
    ["06:35", "sync-market success - 614 items dans la tranche 5-800 €"],
    ["06:42", "sync-history success - agrégats 24h/7j/30j enrichis"],
    ["06:48", "csfloat partial - 73 items enrichis, 9 timeouts"],
    ["06:52", "generate-report success - rapport quotidien publié"],
  ],
  opportunityBars: [
    { day: "Mer", value: 12 },
    { day: "Jeu", value: 15 },
    { day: "Ven", value: 19 },
    { day: "Sam", value: 24 },
    { day: "Dim", value: 21 },
    { day: "Lun", value: 18 },
    { day: "Mar", value: 27 },
  ],
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

const renderSimpleTable = (targetId, rows, format) => {
  const root = document.getElementById(targetId);
  root.innerHTML = rows.map((row) => format(row)).join("");
};

const renderSignals = () => {
  const root = document.getElementById("top-signals");
  root.innerHTML = demoState.topSignals.map((item) => `
    <article class="signal-item">
      <div class="skin-thumb">${item.weapon}</div>
      <div class="signal-item-meta">
        <strong>${item.name}</strong>
        <span class="table-meta">${item.price} • ${item.change}</span>
        <div class="badge-row">${item.tags.map((tag) => `<span class="badge">${tag}</span>`).join("")}</div>
      </div>
      <div class="signal-score">
        <span class="table-meta">Score</span>
        <strong>${item.score}</strong>
      </div>
    </article>
  `).join("");
};

const renderOpportunityBars = () => {
  const barsRoot = document.getElementById("opportunity-bars");
  const axisRoot = document.getElementById("opportunity-axis");
  const max = Math.max(...demoState.opportunityBars.map((bar) => bar.value));

  barsRoot.innerHTML = demoState.opportunityBars.map((bar) => `
    <div class="bar-wrap">
      <span class="table-meta">${bar.value}</span>
      <div class="bar" style="height:${(bar.value / max) * 180}px"></div>
    </div>
  `).join("");

  axisRoot.innerHTML = demoState.opportunityBars.map((bar) => `<span>${bar.day}</span>`).join("");
};

const renderOpportunities = () => {
  const root = document.getElementById("opportunity-cards");
  root.innerHTML = demoState.opportunities.map((item) => `
    <article class="opportunity-card">
      <div class="opportunity-top">
        <div class="skin-thumb">${item.weapon}</div>
        <div class="opportunity-copy">
          <h5>${item.name}</h5>
          <div class="metric-row">
            <span>${item.price}</span>
            <span>${item.change24h} 24h</span>
            <span>${item.change7d} 7j</span>
          </div>
          <div class="metric-row">
            <span>${item.volume}</span>
            <span>score ${item.score}</span>
          </div>
        </div>
      </div>
      <div class="reason">${item.reason}</div>
    </article>
  `).join("");
};

const init = () => {
  bindViews();
  renderSignals();
  renderOpportunityBars();
  renderOpportunities();

  renderSimpleTable("watchlist-table", demoState.watchlist, ([name, price, note]) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${name}</strong><span class="table-meta">${note}</span></div>
      <div class="value-stack"><strong>${price}</strong><span class="table-meta">watchlist</span></div>
    </article>
  `);

  renderSimpleTable("gainers-list", demoState.gainers, ([name, change, price]) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${name}</strong><span class="table-meta">hausse 24h</span></div>
      <div class="value-stack"><strong class="positive">${change}</strong><span class="table-meta">${price}</span></div>
    </article>
  `);

  renderSimpleTable("losers-list", demoState.losers, ([name, change, price]) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${name}</strong><span class="table-meta">baisse 24h</span></div>
      <div class="value-stack"><strong class="negative">${change}</strong><span class="table-meta">${price}</span></div>
    </article>
  `);

  renderSimpleTable("volume-list", demoState.volume, ([name, ratio, volume]) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${name}</strong><span class="table-meta">accélération de liquidité</span></div>
      <div class="value-stack"><strong>${ratio}</strong><span class="table-meta">${volume}</span></div>
    </article>
  `);

  renderSimpleTable("reports-history", demoState.reports, ([date, count, note]) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${date}</strong><span class="table-meta">${note}</span></div>
      <div class="value-stack"><strong>${count}</strong><span class="table-meta">archive</span></div>
    </article>
  `);

  renderSimpleTable("item-history", demoState.itemHistory, ([date, price, volume]) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${date}</strong><span class="table-meta">${volume}</span></div>
      <div class="value-stack"><strong>${price}</strong><span class="table-meta">snapshot</span></div>
    </article>
  `);

  renderSimpleTable("api-health", demoState.health, ([source, status, note]) => `
    <article class="table-item">
      <div class="table-item-main"><strong>${source}</strong><span class="table-meta">${note}</span></div>
      <div class="value-stack"><strong class="${status === "Dégradé" ? "negative" : "positive"}">${status}</strong><span class="table-meta">source</span></div>
    </article>
  `);

  renderSimpleTable("job-logs", demoState.logs, ([time, line]) => `
    <article class="log-entry">
      <span class="log-time">${time}</span>
      <span class="log-line">${line}</span>
    </article>
  `);
};

init();
