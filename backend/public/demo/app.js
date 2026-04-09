const API_BASE_URL = window.location.origin;

const state = {
  overview: null,
  reportToday: null,
  reportHistory: null,
  item: null,
  health: null,
  jobs: null,
  watchlist: null,
  filteredItems: [],
  filteredMeta: null,
  skinAdvisorMessages: [],
  activeView: "dashboard",
  lastNonItemView: "dashboard",
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

const escapeHtml = (value) =>
  String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");

const itemVisual = (item, options = {}) => {
  const label = escapeHtml(options.label ?? item?.weapon ?? item?.name ?? "CS2");
  const alt = escapeHtml(item?.name ?? options.label ?? "CS2 item");
  const imageUrl = item?.image_url ?? item?.icon_url ?? null;
  const className = options.className ?? "skin-thumb";

  if (imageUrl) {
    return `
      <div class="${className} skin-thumb-image">
        <img src="${escapeHtml(imageUrl)}" alt="${alt}" loading="lazy" referrerpolicy="no-referrer" />
      </div>
    `;
  }

  return `<div class="${className}">${label}</div>`;
};

const itemPrimaryLink = (item) => item?.market_page ?? item?.item_page ?? null;

const itemLink = (item, label = "Ouvrir l'item") => {
  const url = itemPrimaryLink(item);
  if (!url) {
    return "";
  }

  return `<a class="item-link" href="${escapeHtml(url)}" target="_blank" rel="noreferrer">${escapeHtml(label)}</a>`;
};

const itemDetailButton = (item, label = "Voir la fiche") => {
  if (!item?.id) {
    return "";
  }

  return `<button class="item-link item-link-button" type="button" data-open-item="${escapeHtml(item.id)}">${escapeHtml(label)}</button>`;
};

const itemActions = (item) => {
  const actions = [itemDetailButton(item), itemLink(item, "Voir le marché")].filter(Boolean);
  if (actions.length === 0) {
    return "";
  }

  return `<div class="item-link-row">${actions.join("")}</div>`;
};

const knownItems = () => [
  ...(state.overview?.top_signals ?? []),
  ...(state.reportToday?.top_opportunities ?? []),
  ...(state.reportToday?.top_gainers ?? []),
  ...(state.reportToday?.top_losers ?? []),
  ...(state.reportToday?.top_volume ?? []),
  ...(state.watchlist?.data ?? []),
  ...(state.filteredItems ?? []),
  ...(state.item ? [state.item] : []),
];

const resolveItemByName = (input) => {
  const targetName =
    typeof input === "string"
      ? input
      : (input?.name ?? input?.market_hash_name ?? "");
  if (!targetName) {
    return null;
  }

  return knownItems().find((item) => (item?.name ?? item?.market_hash_name ?? "") === targetName) ?? null;
};

const itemNameMarkup = (input, options = {}) => {
  const resolved =
    typeof input === "string"
      ? resolveItemByName(input)
      : (input ?? resolveItemByName(input));
  const label = escapeHtml(
    typeof input === "string"
      ? input
      : (input?.name ?? input?.market_hash_name ?? options.fallbackLabel ?? "")
  );

  if (resolved?.id) {
    return `<button class="item-name-link" type="button" data-open-item="${escapeHtml(resolved.id)}">${label}</button>`;
  }

  const url = itemPrimaryLink(resolved ?? input);
  if (url) {
    return `<a class="item-name-link" href="${escapeHtml(url)}" target="_blank" rel="noreferrer">${label}</a>`;
  }

  return `<span class="item-name-link is-static">${label}</span>`;
};

const aiSection = (title, cards, emptyText) => `
  <div class="ai-section">
    <h5>${escapeHtml(title)}</h5>
    <div class="table-list">
      ${
        (cards ?? []).length > 0
          ? cards
              .map(
                (card) => `
                <article class="table-item ai-card">
                  <div class="table-item-main">
                    ${itemNameMarkup(card.name)}
                    <span class="table-meta">${escapeHtml(card.rationale)}</span>
                    <div class="ai-badge-row">
                      <span class="ai-badge ai-badge-primary">${escapeHtml(card.verdict || "A surveiller")}</span>
                      <span class="ai-badge">${escapeHtml(title)}</span>
                    </div>
                  </div>
                    <strong>${escapeHtml(card.verdict || "À surveiller")}</strong>
                    <span class="table-meta">${escapeHtml(title)}</span>
                  </div>
                </article>
              `
              )
              .join("")
          : `
            <article class="table-item">
              <div class="table-item-main">
                <strong>Rien de marquant pour cette section</strong>
                <span class="table-meta">${escapeHtml(emptyText)}</span>
              </div>
            </article>
          `
      }
    </div>
  </div>
`;

const aiWatchlistSection = (title, actions, emptyText) => `
  <div class="ai-section">
    <h5>${escapeHtml(title)}</h5>
    <div class="table-list">
      ${
        (actions ?? []).length > 0
          ? actions
              .map(
                (action) => `
                <article class="table-item ai-card">
                  <div class="table-item-main">
                    ${itemNameMarkup(action.name)}
                    <span class="table-meta">${escapeHtml(action.rationale || action.note || "")}</span>
                    <div class="ai-badge-row">
                      <span class="ai-badge ai-badge-primary">${escapeHtml((action.action || "keep").toUpperCase())}</span>
                      <span class="ai-badge">${escapeHtml(action.note || "watchlist IA")}</span>
                    </div>
                  </div>
                  <div class="value-stack">
                    <strong>${escapeHtml((action.action || "keep").toUpperCase())}</strong>
                    <span class="table-meta">${escapeHtml(action.note || "watchlist IA")}</span>
                  </div>
                </article>
              `
              )
              .join("")
          : `
            <article class="table-item">
              <div class="table-item-main">
                <strong>Aucun ajustement IA applique</strong>
                <span class="table-meta">${escapeHtml(emptyText)}</span>
              </div>
            </article>
          `
      }
    </div>
  </div>
`;

const renderAiCardList = (title, cards, emptyText, options = {}) => `
  <div class="ai-section">
    <h5>${escapeHtml(title)}</h5>
    <div class="table-list">
      ${
        (cards ?? []).length > 0
          ? cards
              .map(
                (card) => `
                <article class="table-item ai-card">
                  <div class="table-item-main">
                    ${itemNameMarkup(card.name)}
                    <span class="table-meta">${escapeHtml(card.rationale || "")}</span>
                    <div class="ai-badge-row">
                      <span class="ai-badge ai-badge-primary">${escapeHtml(card.verdict || options.primaryFallback || "A surveiller")}</span>
                      <span class="ai-badge">${escapeHtml(options.secondaryLabel || title)}</span>
                    </div>
                  </div>
                </article>
              `
              )
              .join("")
          : `
            <article class="table-item ai-card">
              <div class="table-item-main">
                <strong>Rien de marquant pour cette section</strong>
                <span class="table-meta">${escapeHtml(emptyText)}</span>
              </div>
            </article>
          `
      }
    </div>
  </div>
`;

const renderAiWatchlistList = (title, actions, emptyText) => `
  <div class="ai-section">
    <h5>${escapeHtml(title)}</h5>
    <div class="table-list">
      ${
        (actions ?? []).length > 0
          ? actions
              .map(
                (action) => `
                <article class="table-item ai-card">
                  <div class="table-item-main">
                    ${itemNameMarkup(action.name)}
                    <span class="table-meta">${escapeHtml(action.rationale || action.note || "")}</span>
                    <div class="ai-badge-row">
                      <span class="ai-badge ai-badge-primary">${escapeHtml((action.action || "keep").toUpperCase())}</span>
                      <span class="ai-badge">${escapeHtml(action.note || "watchlist IA")}</span>
                    </div>
                  </div>
                </article>
              `
              )
              .join("")
          : `
            <article class="table-item ai-card">
              <div class="table-item-main">
                <strong>Aucun ajustement IA applique</strong>
                <span class="table-meta">${escapeHtml(emptyText)}</span>
              </div>
            </article>
          `
      }
    </div>
  </div>
`;

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

const defaultFilterValues = {
  q: "Redline",
  price_min: "5",
  price_max: "800",
  volume_min: "25",
  rarity: "Classified",
  score_min: "70",
};

const readFilterValues = () => ({
  q: document.getElementById("filter-query")?.value?.trim() ?? "",
  price_min: document.getElementById("filter-price-min")?.value?.trim() ?? "",
  price_max: document.getElementById("filter-price-max")?.value?.trim() ?? "",
  volume_min: document.getElementById("filter-volume-min")?.value?.trim() ?? "",
  rarity: document.getElementById("filter-rarity")?.value?.trim() ?? "",
  score_min: document.getElementById("filter-score-min")?.value?.trim() ?? "",
});

const buildItemsQuery = (filters) => {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== "") {
      params.set(key, value);
    }
  });
  params.set("per_page", "12");
  return `/api/items?${params.toString()}`;
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

const activateView = (target) => {
  if (!target) {
    return;
  }

  document.querySelectorAll("[data-view-target]").forEach((entry) => entry.classList.remove("is-active"));
  document.querySelectorAll("[data-view]").forEach((entry) => entry.classList.remove("is-active"));
  document.querySelector(`[data-view-target="${target}"]`)?.classList.add("is-active");
  document.querySelector(`[data-view="${target}"]`)?.classList.add("is-active");

  if (target !== "item") {
    state.lastNonItemView = target;
  }

  state.activeView = target;
};

const bindViews = () => {
  const buttons = document.querySelectorAll("[data-view-target]");

  buttons.forEach((button) => {
    button.addEventListener("click", () => {
      activateView(button.dataset.viewTarget);
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
      ${itemVisual(item)}
      <div class="signal-item-meta">
        ${itemNameMarkup(item)}
        <div class="item-link-row">${itemLink(item, "Voir le marché")}</div>
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
        ${itemVisual(item)}
        <div class="opportunity-copy">
          <h5>${itemNameMarkup(item)}</h5>
          <div class="metric-row">
            <span>${euro(item.price)}</span>
            <span>${pct(item.change_24h)} 24h</span>
            <span>${pct(item.change_7d)} 7j</span>
          </div>
          <div class="metric-row">
            <span>${item.volume_24h ?? 0} ventes</span>
            <span>score ${item.score ?? "-"}/100</span>
          </div>
          <div class="item-link-row">${itemLink(item, "Ouvrir l'item")}</div>
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

  const eyebrow = document.querySelector(".summary-copy .eyebrow");
  if (eyebrow) {
    eyebrow.textContent = report.ai_best_deals_text ? "Analyse IA OpenRouter" : "Résumé du rapport";
  }

  const title = document.querySelector(".summary-copy h4");
  if (title) {
    title.textContent = report.ai_best_deals_title ?? "Résumé du marché du jour";
  }

  const body = document.querySelector(".summary-copy h4 + p");
  if (body) {
    body.textContent = report.ai_best_deals_text ?? report.summary_text ?? "";
  }

  const summaryCopy = document.querySelector(".summary-copy");
  let modelNode = document.getElementById("summary-model");
  if (!modelNode && summaryCopy) {
    modelNode = document.createElement("p");
    modelNode.id = "summary-model";
    modelNode.className = "table-meta";
    summaryCopy.appendChild(modelNode);
  }

  if (modelNode) {
    if (report.ai_model) {
      modelNode.textContent = `Analyse générée via ${report.ai_model}${report.ai_generated_at ? ` le ${report.ai_generated_at.slice(0, 16).replace("T", " ")}` : ""}.`;
    } else if (report.ai_best_deals_error) {
      modelNode.textContent = `Analyse IA indisponible: ${report.ai_best_deals_error}`;
    } else {
      modelNode.textContent = "Analyse locale uniquement. Ajoute OPENROUTER_API_KEY pour enrichir ce bloc avec la recherche web.";
    }
  }

  const stats = document.querySelectorAll(".summary-stats strong");
  if (stats.length >= 3) {
    stats[0].textContent = report.items_scanned ?? "-";
    stats[1].textContent = report.items_in_range ?? "-";
    stats[2].textContent = report.opportunities_count ?? "-";
  }

  let panel = document.getElementById("ai-insights-panel");
  if (!panel) {
    const summary = document.querySelector(".summary-panel");
    if (summary) {
      panel = document.createElement("article");
      panel.id = "ai-insights-panel";
      panel.className = "panel";
      panel.innerHTML = `
        <div class="card-header">
          <div>
            <h4>Lecture IA des meilleures affaires</h4>
            <p>Ce bloc croise le JSON du jour avec la recherche web OpenRouter.</p>
          </div>
        </div>
        <div class="table-list" id="ai-best-deals"></div>
        <div class="ai-grid" id="ai-secondary-sections"></div>
        <div class="table-list" id="ai-sources"></div>
      `;
      summary.insertAdjacentElement("afterend", panel);
    }
  }

  const cardsRoot = document.getElementById("ai-best-deals");
  if (cardsRoot) {
    const cards = report.ai_best_deals_cards ?? [];
    cardsRoot.innerHTML =
      cards.length > 0
        ? cards
            .map(
              (card) => `
              <article class="table-item ai-card">
                <div class="table-item-main">
                  ${itemNameMarkup(card.name)}
                  <span class="table-meta">${escapeHtml(card.rationale)}</span>
                  <div class="ai-badge-row">
                    <span class="ai-badge ai-badge-primary">${escapeHtml(card.verdict || "A surveiller")}</span>
                    <span class="ai-badge">meilleure affaire</span>
                  </div>
                </div>
                  <strong>${escapeHtml(card.verdict || "À surveiller")}</strong>
                  <span class="table-meta">meilleure affaire</span>
                </div>
              </article>
            `
            )
            .join("")
        : `
          <article class="table-item">
            <div class="table-item-main">
              <strong>Aucune carte IA détaillée pour l'instant</strong>
              <span class="table-meta">Le rapport reste disponible, mais l'analyse web n'a pas encore produit de shortlist exploitable.</span>
            </div>
          </article>
        `;
  }

  const sourcesRoot = document.getElementById("ai-sources");
  if (sourcesRoot) {
    const sources = report.ai_best_deals_sources ?? [];
    sourcesRoot.innerHTML =
      sources.length > 0
        ? sources
            .map(
              (source) => `
              <article class="table-item">
                <div class="table-item-main">
                  <strong>Source web</strong>
                  <span class="table-meta"><a href="${escapeHtml(source)}" target="_blank" rel="noreferrer">${escapeHtml(source)}</a></span>
                </div>
              </article>
            `
            )
            .join("")
        : "";
  }

  const secondaryRoot = document.getElementById("ai-secondary-sections");
  if (secondaryRoot) {
    secondaryRoot.innerHTML = [
      aiSection(
        "Risques du jour",
        report.ai_risk_cards ?? [],
        "Aucun risque prioritaire n'a ete isole par l'analyse IA."
      ),
      aiSection(
        "Faux signaux",
        report.ai_false_signal_cards ?? [],
        "L'analyse IA n'a pas releve de faux signal dominant sur cet echantillon."
      ),
      aiSection(
        "Items stables a surveiller",
        report.ai_stable_watch_cards ?? [],
        "Aucun item stable supplementaire n'a ete mis en avant par l'analyse IA."
      ),
      aiWatchlistSection(
        "Mouvements de watchlist",
        report.ai_watchlist_actions ?? [],
        "L'analyse IA n'a ni ajoute ni retire d'item sur ce run."
      ),
    ].join("");
  }

  if (cardsRoot) {
    cardsRoot.innerHTML = renderAiCardList(
      "Meilleures affaires",
      report.ai_best_deals_cards ?? [],
      "Le rapport reste disponible, mais l'analyse web n'a pas encore produit de shortlist exploitable.",
      { secondaryLabel: "meilleure affaire" }
    );
  }

  if (secondaryRoot) {
    secondaryRoot.innerHTML = [
      renderAiCardList(
        "Risques du jour",
        report.ai_risk_cards ?? [],
        "Aucun risque prioritaire n'a ete isole par l'analyse IA."
      ),
      renderAiCardList(
        "Faux signaux",
        report.ai_false_signal_cards ?? [],
        "L'analyse IA n'a pas releve de faux signal dominant sur cet echantillon."
      ),
      renderAiCardList(
        "Items stables a surveiller",
        report.ai_stable_watch_cards ?? [],
        "Aucun item stable supplementaire n'a ete mis en avant par l'analyse IA."
      ),
      renderAiWatchlistList(
        "Mouvements de watchlist",
        report.ai_watchlist_actions ?? [],
        "L'analyse IA n'a ni ajoute ni retire d'item sur ce run."
      ),
    ].join("");
  }
};

const renderWatchlist = () => {
  renderSimpleTable("watchlist-table", state.watchlist?.data ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main table-item-with-image">${itemVisual(item, { label: item.name })}<div class="table-item-copy">${itemNameMarkup(item)}<span class="table-meta">${item.note}</span><div class="item-link-row">${itemLink(item, "Ouvrir l'item")}</div></div></div>
      <div class="value-stack"><strong>${euro(item.price)}</strong><span class="table-meta">watchlist</span></div>
    </article>
  `);
};

const renderFilteredItems = () => {
  const root = document.getElementById("filtered-items");
  const feedback = document.getElementById("filter-feedback");
  if (!root || !feedback) {
    return;
  }

  const rows = state.filteredItems ?? [];
  const total = state.filteredMeta?.total ?? rows.length;
  feedback.textContent = `${total} item${total > 1 ? "s" : ""} trouvés avec les filtres actuels.`;

  root.innerHTML =
    rows.length > 0
      ? rows
          .map(
            (item) => `
            <article class="table-item filtered-item">
              <div class="table-item-main table-item-with-image">
                ${itemVisual(item, { label: item.name })}
                <div class="table-item-copy">
                  ${itemNameMarkup(item)}
                  <span class="table-meta">${item.rarity ?? "Rareté inconnue"} • ${item.weapon ?? "Skin CS2"}</span>
                  <span class="table-meta">volume ${item.sales_24h_volume ?? 0} • score ${item.interest_score ?? "-"}/100</span>
                  ${itemActions(item)}
                </div>
              </div>
              <div class="value-stack">
                <strong>${euro(item.current_price)}</strong>
                <span class="table-meta">${pct(item.change_vs_yesterday_pct)} 24h</span>
              </div>
            </article>
          `
          )
          .join("")
      : `
        <article class="table-item filtered-item">
          <div class="table-item-main">
            <strong>Aucun item ne correspond aux filtres actuels.</strong>
            <span class="table-meta">Essaie d’élargir la rareté, le volume mini ou le score mini.</span>
          </div>
        </article>
      `;
};

const verdictLabel = (verdict) => ({
  good_deal: "Bonne affaire",
  fair_price: "Prix correct",
  avoid: "À éviter",
  uncertain: "Incertain",
}[verdict] ?? "Incertain");

const renderSkinAdvisor = () => {
  const root = document.getElementById("skin-chat-log");
  if (!root) {
    return;
  }

  const messages = state.skinAdvisorMessages ?? [];
  root.innerHTML =
    messages.length > 0
      ? messages
          .map((message) => {
            if (message.role === "user") {
              return `
                <article class="chat-message user">
                  <p class="eyebrow">Toi</p>
                  <strong>${escapeHtml(message.text)}</strong>
                </article>
              `;
            }

            if (message.role === "assistant" && message.pending) {
              return `
                <article class="chat-message">
                  <p class="eyebrow">IA</p>
                  <span class="table-meta">Analyse en cours du skin demandé...</span>
                </article>
              `;
            }

            const response = message.response ?? {};
            const item = response.matched_item ?? null;
            return `
              <article class="chat-message">
                <p class="eyebrow">IA skin advisor</p>
                <div class="chip-row">
                  <span class="badge">${escapeHtml(verdictLabel(response.verdict))}</span>
                  <span class="badge">Confiance ${escapeHtml(String(response.confidence ?? "-"))}/100</span>
                  ${response.model ? `<span class="badge">${escapeHtml(response.model)}</span>` : ""}
                </div>
                <strong>${escapeHtml(response.title ?? "Avis IA")}</strong>
                <p>${escapeHtml(response.summary ?? "")}</p>
                ${item ? `
                  <div class="chat-card">
                    ${itemVisual(item, { className: "skin-thumb", label: item.name })}
                    <div class="chat-card-copy">
                      ${itemNameMarkup(item)}
                      <span class="table-meta">${euro(item.current_price)} • ${pct(item.change_vs_yesterday_pct)} 24h • ${pct(item.change_vs_7d_pct)} 7j</span>
                      <span class="table-meta">volume ${item.sales_24h_volume ?? 0} • score ${item.interest_score ?? "-"}/100</span>
                      ${itemActions(item)}
                    </div>
                  </div>
                ` : ""}
                ${response.action ? `<p><strong>Action :</strong> ${escapeHtml(response.action)}</p>` : ""}
                ${(response.positives ?? []).length > 0 ? `<div><strong>Points pour</strong><ul class="chat-list">${response.positives.map((line) => `<li>${escapeHtml(line)}</li>`).join("")}</ul></div>` : ""}
                ${(response.risks ?? []).length > 0 ? `<div><strong>Risques</strong><ul class="chat-list">${response.risks.map((line) => `<li>${escapeHtml(line)}</li>`).join("")}</ul></div>` : ""}
                ${(response.sources ?? []).length > 0 ? `<div class="item-link-row">${response.sources.map((source) => `<a class="item-link" href="${escapeHtml(source)}" target="_blank" rel="noreferrer">Source web</a>`).join("")}</div>` : ""}
              </article>
            `;
          })
          .join("")
      : `
        <article class="chat-message">
          <p class="eyebrow">IA skin advisor</p>
          <span class="table-meta">Envoie un nom de skin ou un message avec prix, par exemple : “AWP | Asiimov (Battle-Scarred) à 145€ est-ce une bonne affaire ?”</span>
        </article>
      `;
};

const renderGainersLosersVolume = () => {
  renderSimpleTable("gainers-list", state.reportToday?.top_gainers ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main table-item-with-image">${itemVisual(item, { label: item.name })}<div class="table-item-copy">${itemNameMarkup(item)}<span class="table-meta">hausse 24h</span><div class="item-link-row">${itemLink(item, "Ouvrir l'item")}</div></div></div>
      <div class="value-stack"><strong class="positive">${pct(item.change_24h)}</strong><span class="table-meta">${euro(item.price)}</span></div>
    </article>
  `);

  renderSimpleTable("losers-list", state.reportToday?.top_losers ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main table-item-with-image">${itemVisual(item, { label: item.name })}<div class="table-item-copy">${itemNameMarkup(item)}<span class="table-meta">baisse 24h</span><div class="item-link-row">${itemLink(item, "Ouvrir l'item")}</div></div></div>
      <div class="value-stack"><strong class="negative">${pct(item.change_24h)}</strong><span class="table-meta">${euro(item.price)}</span></div>
    </article>
  `);

  renderSimpleTable("volume-list", state.reportToday?.top_volume ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main table-item-with-image">${itemVisual(item, { label: item.name })}<div class="table-item-copy">${itemNameMarkup(item)}<span class="table-meta">accélération de liquidité</span></div></div>
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

  const shot = document.querySelector(".item-shot");
  if (shot) {
    shot.innerHTML = itemVisual(item, { className: "skin-thumb large", label: item.weapon ?? item.name });
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

  let actionRow = document.getElementById("item-action-row");
  const itemCopy = document.querySelector(".item-copy");
  if (!actionRow && itemCopy) {
    actionRow = document.createElement("div");
    actionRow.id = "item-action-row";
    actionRow.className = "item-link-row";
    itemCopy.appendChild(actionRow);
  }

  if (actionRow) {
    actionRow.innerHTML = itemLink(item, "Voir la page marché");
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
      <div class="value-stack"><strong class="${source.status === "ok" ? "positive" : (source.status === "ready" || source.status === "public" || source.status === "unknown" ? "neutral" : "negative")}">${source.status}</strong><span class="table-meta">source</span></div>
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
  const syncCsfloatButton = document.querySelector('[data-job="sync-csfloat"]');
  const cooldownNode = document.getElementById("market-cooldown");

  if (!health || !syncMarketButton || !syncCsfloatButton || !cooldownNode) {
    return;
  }

  const marketRemaining = Number(health.market_sync_cooldown_remaining ?? 0);
  const marketAvailable = Boolean(health.market_sync_available ?? marketRemaining === 0);
  const csfloatRemaining = Number(health.csfloat_sync_cooldown_remaining ?? 0);
  const csfloatAvailable = Boolean(health.csfloat_sync_available ?? csfloatRemaining === 0);

  syncMarketButton.disabled = !marketAvailable;
  syncMarketButton.textContent = marketAvailable ? "Sync market" : `Sync market (${formatCooldown(marketRemaining)})`;

  syncCsfloatButton.disabled = !csfloatAvailable;
  syncCsfloatButton.textContent = csfloatAvailable ? "Sync CSFloat" : `Sync CSFloat (${formatCooldown(csfloatRemaining)})`;

  const lines = [];
  lines.push(
    marketAvailable
      ? "Skinport est disponible pour un nouveau sync."
      : `Skinport est en cooldown. Prochain lancement possible dans ${formatCooldown(marketRemaining)}.`
  );
  lines.push(
    csfloatAvailable
      ? "CSFloat est disponible pour un nouveau sync."
      : `CSFloat est en cooldown. Prochain lancement possible dans ${formatCooldown(csfloatRemaining)}.`
  );

  cooldownNode.textContent = lines.join(" ");
};

const openItemView = async (itemId) => {
  if (!itemId) {
    return;
  }

  if (state.activeView && state.activeView !== "item") {
    state.lastNonItemView = state.activeView;
  }

  state.item = await fetchJson(`/api/items/${itemId}`);
  renderItem();
  activateView("item");
};

const bindItemOpenActions = () => {
  document.addEventListener("click", async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
      return;
    }

    const button = target.closest("[data-open-item]");
    if (!(button instanceof HTMLElement)) {
      return;
    }

    const itemId = button.dataset.openItem;
    if (!itemId) {
      return;
    }

    event.preventDefault();

    const feedback = document.getElementById("job-feedback");
    try {
      if (feedback) {
        feedback.textContent = "Chargement de la fiche item...";
      }
      await openItemView(itemId);
    } catch (error) {
      if (feedback) {
        feedback.textContent = error.message;
      }
    }
  });
};

const renderSignalsLinked = () => {
  const rows = state.overview?.top_signals ?? [];
  renderSimpleTable("top-signals", rows, (item) => `
    <article class="signal-item">
      ${itemVisual(item)}
      <div class="signal-item-meta">
${itemNameMarkup(item)}
        ${itemActions(item)}
        <span class="table-meta">${euro(item.current_price)} - ${pct(item.change_24h)}</span>
        <div class="badge-row">${(item.tags ?? []).map((tag) => `<span class="badge">${tag}</span>`).join("")}</div>
      </div>
      <div class="signal-score">
        <span class="table-meta">Score</span>
        <strong>${item.interest_score ?? item.score ?? "-"}</strong>
      </div>
    </article>
  `);
};

const renderOpportunitiesLinked = () => {
  const rows = state.reportToday?.top_opportunities ?? [];
  renderSimpleTable("opportunity-cards", rows, (item) => `
    <article class="opportunity-card">
      <div class="opportunity-top">
        ${itemVisual(item)}
        <div class="opportunity-copy">
          <h5>${itemNameMarkup(item)}</h5>
          <div class="metric-row">
            <span>${euro(item.price)}</span>
            <span>${pct(item.change_24h)} 24h</span>
            <span>${pct(item.change_7d)} 7j</span>
          </div>
          <div class="metric-row">
            <span>${item.volume_24h ?? 0} ventes</span>
            <span>score ${item.score ?? "-"}/100</span>
          </div>
          ${itemActions(item)}
        </div>
      </div>
      <div class="reason">${item.reason ?? "signal live"}</div>
    </article>
  `);
};

const renderWatchlistLinked = () => {
  renderSimpleTable("watchlist-table", state.watchlist?.data ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main table-item-with-image">${itemVisual(item, { label: item.name })}<div class="table-item-copy">${itemNameMarkup(item)}<span class="table-meta">${item.note}</span><span class="table-meta">gestion ${escapeHtml(item.managed_by || "system")}${item.last_ai_action ? ` • IA ${escapeHtml(item.last_ai_action)}` : ""}</span>${item.last_ai_reason ? `<span class="table-meta">${escapeHtml(item.last_ai_reason)}</span>` : ""}${itemActions(item)}</div></div>
      <div class="value-stack"><strong>${euro(item.price)}</strong><span class="table-meta">watchlist</span></div>
    </article>
  `);
};

const renderGainersLosersVolumeLinked = () => {
  renderSimpleTable("gainers-list", state.reportToday?.top_gainers ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main table-item-with-image">${itemVisual(item, { label: item.name })}<div class="table-item-copy">${itemNameMarkup(item)}<span class="table-meta">hausse 24h</span>${itemActions(item)}</div></div>
      <div class="value-stack"><strong class="positive">${pct(item.change_24h)}</strong><span class="table-meta">${euro(item.price)}</span></div>
    </article>
  `);

  renderSimpleTable("losers-list", state.reportToday?.top_losers ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main table-item-with-image">${itemVisual(item, { label: item.name })}<div class="table-item-copy">${itemNameMarkup(item)}<span class="table-meta">baisse 24h</span>${itemActions(item)}</div></div>
      <div class="value-stack"><strong class="negative">${pct(item.change_24h)}</strong><span class="table-meta">${euro(item.price)}</span></div>
    </article>
  `);

  renderSimpleTable("volume-list", state.reportToday?.top_volume ?? [], (item) => `
    <article class="table-item">
      <div class="table-item-main table-item-with-image">${itemVisual(item, { label: item.name })}<div class="table-item-copy">${itemNameMarkup(item)}<span class="table-meta">acceleration de liquidite</span>${itemActions(item)}</div></div>
      <div class="value-stack"><strong>x${(item.volume_ratio ?? 0).toFixed(1).replace(".", ",")}</strong><span class="table-meta">${item.volume_24h ?? 0} ventes</span></div>
    </article>
  `);
};

const renderItemLinked = () => {
  const item = state.item;
  if (!item) {
    return;
  }

  const sectionHeading = document.querySelector('[data-view="item"] .section-heading');
  let backButton = document.getElementById("item-back-button");
  if (!backButton && sectionHeading) {
    backButton = document.createElement("button");
    backButton.id = "item-back-button";
    backButton.type = "button";
    backButton.className = "item-link item-link-button";
    sectionHeading.appendChild(backButton);
  }

  if (backButton) {
    const targetView = state.lastNonItemView || "dashboard";
    const labels = {
      dashboard: "Retour dashboard",
      report: "Retour rapport",
      history: "Retour historique",
      admin: "Retour admin",
    };
    backButton.textContent = labels[targetView] ?? "Retour";
    backButton.onclick = () => activateView(targetView);
  }

  let actionRow = document.getElementById("item-action-row");
  const itemCopy = document.querySelector(".item-copy");
  if (!actionRow && itemCopy) {
    actionRow = document.createElement("div");
    actionRow.id = "item-action-row";
    actionRow.className = "item-link-row";
    itemCopy.appendChild(actionRow);
  }

  if (actionRow) {
    actionRow.innerHTML = itemActions(item);
  }
};

const renderAll = () => {
  renderOverviewKpis();
  renderSignals();
  renderSignalsLinked();
  renderOpportunityBars();
  renderOpportunities();
  renderOpportunitiesLinked();
  renderReportSummary();
  renderWatchlist();
  renderWatchlistLinked();
  renderFilteredItems();
  renderSkinAdvisor();
  renderGainersLosersVolume();
  renderGainersLosersVolumeLinked();
  renderHistory();
  renderItem();
  renderItemLinked();
  renderHealth();
  renderJobs();
  renderAdminActionsState();
};

const loadData = async () => {
  const activeFilters = document.getElementById("filter-form") ? readFilterValues() : defaultFilterValues;
  const [overview, reportToday, reportHistory, health, jobs, watchlist, items, filteredItems] = await Promise.all([
    fetchJson("/api/dashboard/overview"),
    fetchJson("/api/reports/today"),
    fetchJson("/api/reports/history"),
    fetchJson("/api/admin/health"),
    fetchJson("/api/admin/jobs"),
    fetchJson("/api/watchlist"),
    fetchJson("/api/items?per_page=1"),
    fetchJson(buildItemsQuery(activeFilters)),
  ]);

  const firstItem = items.data?.[0] ?? null;
  const item = firstItem ? await fetchJson(`/api/items/${firstItem.id}`) : null;

  Object.assign(state, {
    overview,
    reportToday,
    reportHistory,
    item,
    health,
    jobs,
    watchlist,
    filteredItems: filteredItems.data ?? [],
    filteredMeta: filteredItems.meta ?? null,
  });
};

const setButtonsDisabled = (disabled) => {
  document.querySelectorAll(".action-button").forEach((button) => {
    if (button.dataset.job === "sync-market" && !disabled) {
      return;
    }

    button.disabled = disabled;
  });
};

const applyFilters = async () => {
  const feedback = document.getElementById("filter-feedback");
  try {
    if (feedback) {
      feedback.textContent = "Chargement des résultats filtrés...";
    }

    const payload = await fetchJson(buildItemsQuery(readFilterValues()));
    state.filteredItems = payload.data ?? [];
    state.filteredMeta = payload.meta ?? null;
    renderFilteredItems();
  } catch (error) {
    if (feedback) {
      feedback.textContent = error.message;
    }
  }
};

const bindFilterPanel = () => {
  const form = document.getElementById("filter-form");
  if (form) {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      await applyFilters();
    });
  }

  const resetButton = document.getElementById("reset-filters");
  if (resetButton) {
    resetButton.addEventListener("click", async () => {
      Object.entries(defaultFilterValues).forEach(([key, value]) => {
        const input = form?.querySelector(`[name="${key}"]`);
        if (input instanceof HTMLInputElement) {
          input.value = value;
        }
      });
      await applyFilters();
    });
  }
};

const bindSkinAdvisor = () => {
  const form = document.getElementById("skin-chat-form");
  const input = document.getElementById("skin-chat-input");
  if (form && input instanceof HTMLTextAreaElement) {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const text = input.value.trim();
      if (!text) {
        return;
      }

      state.skinAdvisorMessages.push({ role: "user", text });
      const pendingMessage = { role: "assistant", pending: true };
      state.skinAdvisorMessages.push(pendingMessage);
      renderSkinAdvisor();
      input.value = "";

      try {
        const response = await fetchJson("/api/assistant/skin-advice", {
          method: "POST",
          body: JSON.stringify({ message: text }),
        });
        const index = state.skinAdvisorMessages.indexOf(pendingMessage);
        if (index >= 0) {
          state.skinAdvisorMessages[index] = { role: "assistant", response };
        }
      } catch (error) {
        const index = state.skinAdvisorMessages.indexOf(pendingMessage);
        if (index >= 0) {
          state.skinAdvisorMessages[index] = {
            role: "assistant",
            response: {
              title: "Analyse indisponible",
              verdict: "uncertain",
              confidence: 0,
              summary: error.message,
              action: "Réessaie dans un instant ou vérifie la configuration OpenRouter.",
              positives: [],
              risks: [error.message],
              sources: [],
            },
          };
        }
      }

      renderSkinAdvisor();
    });
  }

  document.getElementById("skin-chat-suggest")?.addEventListener("click", () => {
    const suggestion = state.reportToday?.top_opportunities?.[0];
    if (!(input instanceof HTMLTextAreaElement)) {
      return;
    }

    input.value = suggestion?.name
      ? `${suggestion.name} à ${typeof suggestion.price === "number" ? suggestion.price.toFixed(2).replace(".", ",") : "?"}€ est-ce une bonne affaire aujourd’hui ?`
      : "AK-47 | Redline (Field-Tested) à 17,40€ est-ce une bonne affaire ?";
    input.focus();
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
  bindItemOpenActions();
  bindFilterPanel();
  bindSkinAdvisor();

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
