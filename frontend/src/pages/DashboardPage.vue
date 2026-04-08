<template>
  <section class="page">
    <header class="page-header">
      <h2>Dashboard produit</h2>
      <p>Vue rapide sur la collecte, les opportunites du jour et les signaux a surveiller.</p>
    </header>

    <div class="grid">
      <article class="panel">
        <div class="muted">Items suivis</div>
        <div class="metric">0</div>
      </article>

      <article class="panel">
        <div class="muted">Items 5-800 EUR</div>
        <div class="metric">0</div>
      </article>

      <article class="panel">
        <div class="muted">Opportunites</div>
        <div class="metric">0</div>
      </article>

      <article class="panel">
        <div class="muted">Watchlist active</div>
        <div class="metric">0</div>
      </article>
    </div>

    <section class="panel section">
      <h3>Top signaux du jour</h3>
      <p>Ce bloc sera alimente par `GET /api/dashboard/overview`.</p>
      <div class="badge-row">
        <span class="badge">drop</span>
        <span class="badge">spike</span>
        <span class="badge">volume_anomaly</span>
        <span class="badge">watchlist</span>
      </div>
    </section>
  </section>
</template>

<script setup>
</script>

