<template>
  <section class="page">
    <header class="page-header">
      <h2>Rapport du jour</h2>
      <p>Resume IA, opportunites, hausses, baisses et anomalies de volume.</p>
    </header>

    <article class="panel">
      <h3>Resume</h3>
      <p class="muted">Le texte genere sera stocke dans `daily_reports.summary_text`.</p>
    </article>

    <section class="grid">
      <article class="panel">
        <h3>Top opportunites</h3>
        <p class="muted">Cartes illustrees avec image, score, variation et raison courte.</p>
      </article>

      <article class="panel">
        <h3>Top hausses</h3>
        <p class="muted">Comparaison avec J-1 et moyenne 7 jours.</p>
      </article>

      <article class="panel">
        <h3>Top baisses</h3>
        <p class="muted">Focus sur les drops exploitables ou a risque.</p>
      </article>

      <article class="panel">
        <h3>Volume anormal</h3>
        <p class="muted">Signal base sur le ratio volume 24h versus moyenne recente.</p>
      </article>
    </section>
  </section>
</template>

<script setup>
</script>

