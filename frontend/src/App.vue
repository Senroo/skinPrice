<template>
  <div class="shell">
    <aside class="sidebar">
      <div>
        <p class="eyebrow">CS2 Market Daily Radar</p>
        <h1>Radar</h1>
      </div>

      <nav class="nav">
        <RouterLink to="/">Dashboard</RouterLink>
        <RouterLink to="/report">Rapport du jour</RouterLink>
        <RouterLink to="/history">Historique</RouterLink>
        <RouterLink to="/admin">Admin</RouterLink>
      </nav>
    </aside>

    <main class="content">
      <RouterView />
    </main>
  </div>
</template>

<script setup>
</script>

