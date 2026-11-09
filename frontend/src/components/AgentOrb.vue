<script setup>
import { computed } from 'vue'

// Codificación visual de datos computacionales (no de estados subjetivos):
//  - tamaño/brillo del núcleo  -> puntuación de curiosidad C del tema principal
//  - velocidad de la animación -> si hay un proceso en ejecución
const props = defineProps({
  name: { type: String, default: 'EXILIO-001' },
  version: { type: Number, default: 1 },
  curiosity: { type: Number, default: 0 },
  running: { type: Boolean, default: false },
  topic: { type: String, default: '' },
  processTitle: { type: String, default: '' },
  traitsCount: { type: Number, default: 0 },
  interestsCount: { type: Number, default: 0 },
  memoriesCount: { type: Number, default: 0 },
})

const c = computed(() => Math.max(0, Math.min(1, Number(props.curiosity) || 0)))
const orbStyle = computed(() => ({
  '--c': c.value,
  '--spin': props.running ? '7s' : '26s',
  '--breathe': props.running ? '2.4s' : '7s',
}))
</script>

<template>
  <div class="orb-wrap" :style="orbStyle" role="img"
    :aria-label="`Representación abstracta de ${name}: curiosidad C=${c.toFixed(2)}, ${running ? 'procesando' : 'en reposo'}`">
    <div class="orb">
      <span class="halo"></span>
      <span class="blob b1"></span>
      <span class="blob b2"></span>
      <span class="blob b3"></span>
      <span class="core"></span>
      <svg class="ring" viewBox="0 0 100 100" aria-hidden="true">
        <circle cx="50" cy="50" r="48" fill="none" stroke="currentColor" stroke-width="0.25" stroke-dasharray="0.6 2.2" />
        <circle cx="50" cy="50" r="42" fill="none" stroke="currentColor" stroke-width="0.2" opacity="0.5" />
      </svg>
    </div>

    <div class="mt-6 text-center space-y-1">
      <h2 class="name">{{ name }}</h2>
      <p class="text-xs text-violet-200/60 tracking-[0.25em] uppercase">versión {{ version }}.0</p>
      <p class="text-[11px] text-violet-200/50 pt-1 min-h-[1rem]">
        <template v-if="running">{{ processTitle }}</template>
        <template v-else-if="topic">atención en «{{ topic }}»</template>
        <template v-else>en reposo</template>
      </p>
    </div>

    <dl class="vitals">
      <div><dt>C</dt><dd>{{ c.toFixed(2) }}</dd></div>
      <div><dt>rasgos</dt><dd>{{ traitsCount }}</dd></div>
      <div><dt>intereses</dt><dd>{{ interestsCount }}</dd></div>
      <div><dt>memorias</dt><dd>{{ memoriesCount }}</dd></div>
    </dl>
  </div>
</template>

<style scoped>
.orb-wrap { display: flex; flex-direction: column; align-items: center; }

.orb {
  position: relative;
  width: clamp(200px, 26vw, 280px);
  aspect-ratio: 1;
  color: rgb(196 181 253 / 0.45);
}

.halo {
  position: absolute; inset: -25%;
  border-radius: 50%;
  background: radial-gradient(circle, rgb(139 92 246 / calc(0.15 + var(--c) * 0.4)) 0%, transparent 62%);
  animation: breathe var(--breathe) ease-in-out infinite;
}

.blob {
  position: absolute; inset: calc(14% - var(--c) * 6%);
  filter: blur(14px);
  mix-blend-mode: screen;
  animation: morph 11s ease-in-out infinite, spin var(--spin) linear infinite;
  opacity: 0.85;
}
.b1 { background: radial-gradient(circle at 30% 30%, #22d3ee, transparent 70%); }
.b2 { background: radial-gradient(circle at 70% 40%, #a78bfa, transparent 70%); animation-delay: -4s, -3s; animation-direction: normal, reverse; }
.b3 { background: radial-gradient(circle at 50% 75%, #f0abfc, transparent 70%); animation-delay: -8s, -6s; opacity: 0.6; }

.core {
  position: absolute; inset: 36%;
  border-radius: 50%;
  background: radial-gradient(circle, #fff 0%, rgb(221 214 254 / 0.9) 30%, transparent 72%);
  filter: blur(3px);
  transform: scale(calc(0.7 + var(--c) * 0.6));
  animation: breathe var(--breathe) ease-in-out infinite;
}

.ring { position: absolute; inset: 0; animation: spin calc(var(--spin) * 3) linear infinite reverse; }

.name {
  font-family: ui-serif, Georgia, 'Times New Roman', serif;
  font-size: 1.6rem; font-weight: 400; letter-spacing: 0.08em; color: #f5f3ff;
}

.vitals { display: flex; gap: 1.5rem; margin-top: 1.25rem; font-size: 11px; }
.vitals div { text-align: center; }
.vitals dt { color: rgb(196 181 253 / 0.45); letter-spacing: 0.15em; text-transform: uppercase; font-size: 9px; }
.vitals dd { color: rgb(237 233 254 / 0.8); font-variant-numeric: tabular-nums; }

@keyframes morph {
  0%, 100% { border-radius: 62% 38% 55% 45% / 48% 60% 40% 52%; }
  33% { border-radius: 40% 60% 38% 62% / 58% 40% 60% 42%; }
  66% { border-radius: 55% 45% 62% 38% / 40% 55% 45% 60%; }
}
@keyframes spin { to { rotate: 360deg; } }
@keyframes breathe {
  0%, 100% { opacity: 0.75; scale: 0.97; }
  50% { opacity: 1; scale: 1.04; }
}

@media (prefers-reduced-motion: reduce) {
  .halo, .blob, .core, .ring { animation: none; }
}
</style>
