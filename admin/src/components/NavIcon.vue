<script setup lang="ts">
// Compact inline icon set (stroke, 24-grid). No icon library dependency.
const paths: Record<string, string> = {
  grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
  inbox: 'M4 13l2.5-8h11L20 13M4 13v6h16v-6M4 13h5l1 2h4l1-2h5',
  users: 'M9 11a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM3 20c0-3 2.7-5 6-5s6 2 6 5M17 11a3 3 0 000-6M21 20c0-2.4-1.6-4.2-4-4.8',
  columns: 'M4 5h4v14H4zM10 5h4v14h-4zM16 5h4v9h-4z',
  check: 'M5 12.5l4 4 10-10',
  window: 'M4 5h16v14H4zM4 9h16M7 7h.01M9.5 7h.01',
  mail: 'M4 6h16v12H4zM4 7l8 6 8-6',
  globe: 'M12 3a9 9 0 100 18 9 9 0 000-18zM3 12h18M12 3c2.5 2.5 3.5 6 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-6-3.5-9s1-6.5 3.5-9z',
  chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  pulse: 'M3 12h4l2 6 4-14 2 8h6',
  cog: 'M12 15a3 3 0 100-6 3 3 0 000 6zM19 12l2-1-2-3-2 .6a6 6 0 00-1.7-1L15 5H9l-.3 2.6a6 6 0 00-1.7 1L5 8 3 11l2 1a6 6 0 000 2l-2 1 2 3 2-.6a6 6 0 001.7 1L9 21h6l.3-2.6a6 6 0 001.7-1l2 .6 2-3-2-1a6 6 0 000-2z',
  menu: 'M4 6h16M4 12h16M4 18h16',
  search: 'M11 4a7 7 0 100 14 7 7 0 000-14zM20 20l-4-4',
  plus: 'M12 5v14M5 12h14',
  bell: 'M6 9a6 6 0 1112 0c0 5 2 6 2 6H4s2-1 2-6M10 20a2 2 0 004 0',
  arrow: 'M5 12h14M13 6l6 6-6 6',
  clock: 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 7v5l3 2',
  alert: 'M12 4l9 16H3zM12 10v4M12 17h.01',
  plug: 'M9 3v5M15 3v5M7 8h10v3a5 5 0 01-10 0zM12 16v5',
  key: 'M14 7a4 4 0 11-3.8 5.2L4 18.4V21h2.6l.9-.9M9 15l2-2',
  shield: 'M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6z',
  receipt: 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h4',
  calendar: 'M4 5h16v15H4zM4 9h16M8 3v4M16 3v4M8 13h.01M12 13h.01M16 13h.01M8 16h.01M12 16h.01',
  folder: 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z',
}
defineProps<{ name: string }>()
</script>

<template>
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
       stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path :d="paths[name] || ''" />
  </svg>
</template>
