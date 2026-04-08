<template>
  <section class="page">
    <header class="page-header">
      <h2>Dashboard admin</h2>
      <p>Supervision des jobs, des sources et de la fraicheur des donnees.</p>
    </header>

    <div class="grid">
      <article class="panel">
        <div class="muted">Dernier sync</div>
        <div class="metric">-</div>
      </article>

      <article class="panel">
        <div class="muted">Etat API</div>
        <div class="metric">Pending</div>
      </article>

      <article class="panel">
        <div class="muted">Rapports generes</div>
        <div class="metric">0</div>
      </article>
    </div>

    <article class="panel">
      <h3>Jobs</h3>
      <p class="muted">Cette page consommera `GET /api/admin/health` et `GET /api/admin/jobs`.</p>
    </article>
  </section>
</template>

<script setup>
</script>
