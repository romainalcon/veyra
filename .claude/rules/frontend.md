---
paths:
  - "resources/js/**/*"
---
# Frontend rules
- Vue 3 Composition API, `<script setup lang="ts">`.
- Inertia 3 navigation and forms; do not duplicate backend authorization in the UI.
- Reusable accessible components, loading/empty/error states, responsive layout, dark/light themes.
- Do not push unbounded raw time-series data to the browser; request aggregated series.
- Reverb updates must support reconnection and HTTP resynchronization.
