<script setup>
import { computed, ref } from 'vue'

// Cada memoria vigente es un nodo. Sector angular = categoría taxonómica;
// color/forma = estado epistémico; opacidad de hipótesis = confianza.
const props = defineProps({
  memories: { type: Array, default: () => [] },
})

const TYPES = ['episodic', 'semantic', 'social', 'procedural', 'oneiric']
const TYPE_LABEL = {
  episodic: 'episódica', semantic: 'semántica', social: 'social',
  procedural: 'procedimental', oneiric: 'onírica',
}
const STATUS_LABEL = { fact: 'hecho', hypothesis: 'hipótesis', dream: 'sueño' }
const COLORS = { fact: '#34d399', hypothesis: '#fbbf24', dream: '#c084fc' }

const W = 720
const H = 420
const cx = W / 2
const cy = H / 2

// Hash determinista para que la posición de un nodo sea estable entre renders.
function hash(str) {
  let h = 2166136261
  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return (h >>> 0) / 4294967295
}

const nodes = computed(() => {
  return props.memories
    .filter(m => !m.superseded_at)
    .map(m => {
      const sector = Math.max(0, TYPES.indexOf(m.type))
      const span = (Math.PI * 2) / TYPES.length
      const angle = sector * span - Math.PI / 2 + 0.15 * span + hash(m.id + 'a') * span * 0.7
      const radius = 70 + hash(m.id + 'r') * 125
      const conf = m.epistemic_status === 'hypothesis' ? Number(m.confidence ?? 0.5) : 1
      return {
        id: m.id,
        x: cx + Math.cos(angle) * radius * 1.45,
        y: cy + Math.sin(angle) * radius,
        r: 4 + Math.min(6, (m.content || '').length / 60),
        color: COLORS[m.epistemic_status] || '#94a3b8',
        opacity: m.epistemic_status === 'hypothesis' ? 0.35 + conf * 0.65 : 0.9,
        status: m.epistemic_status,
        type: m.type,
        content: m.content || '',
        confidence: m.confidence,
      }
    })
})

// Etiquetas de sector
const sectorLabels = computed(() => TYPES.map((t, i) => {
  const a = ((i + 0.5) * Math.PI * 2) / TYPES.length - Math.PI / 2
  return { t, label: TYPE_LABEL[t], x: cx + Math.cos(a) * 290, y: cy + Math.sin(a) * 195 }
}))

const hovered = ref(null)
const counts = computed(() => ({
  fact: nodes.value.filter(n => n.status === 'fact').length,
  hypothesis: nodes.value.filter(n => n.status === 'hypothesis').length,
  dream: nodes.value.filter(n => n.status === 'dream').length,
}))
</script>

<template>
  <div class="relative w-full">
    <svg :viewBox="`0 0 ${W} ${H}`" class="w-full h-auto" role="img"
      aria-label="Constelación de memorias vigentes por categoría y estado epistémico">
      <defs>
        <radialGradient id="mc-center" cx="50%" cy="50%" r="50%">
          <stop offset="0%" stop-color="#a78bfa" stop-opacity="0.35" />
          <stop offset="100%" stop-color="#a78bfa" stop-opacity="0" />
        </radialGradient>
      </defs>

      <circle :cx="cx" :cy="cy" r="190" fill="url(#mc-center)" />
      <ellipse :cx="cx" :cy="cy" rx="130" ry="90" fill="none" stroke="#a78bfa" stroke-opacity="0.12" stroke-dasharray="1 5" />
      <ellipse :cx="cx" :cy="cy" rx="250" ry="170" fill="none" stroke="#a78bfa" stroke-opacity="0.1" stroke-dasharray="1 7" />

      <text v-for="s in sectorLabels" :key="s.t" :x="s.x" :y="s.y" text-anchor="middle"
        fill="#c4b5fd" fill-opacity="0.35" font-size="10" letter-spacing="2.5">{{ s.label.toUpperCase() }}</text>

      <!-- hilos hacia el centro -->
      <line v-for="n in nodes" :key="'l' + n.id" :x1="cx" :y1="cy" :x2="n.x" :y2="n.y"
        :stroke="n.color" :stroke-opacity="hovered === n.id ? 0.45 : 0.07" stroke-width="0.6" />

      <g v-for="n in nodes" :key="n.id" class="node" tabindex="0"
        @mouseenter="hovered = n.id" @mouseleave="hovered = null"
        @focus="hovered = n.id" @blur="hovered = null">
        <circle :cx="n.x" :cy="n.y" :r="n.r * 2.6" :fill="n.color" :fill-opacity="hovered === n.id ? 0.22 : 0.08" />
        <circle :cx="n.x" :cy="n.y" :r="n.r" :fill="n.status === 'dream' ? 'none' : n.color"
          :fill-opacity="n.opacity" :stroke="n.color" :stroke-width="n.status === 'dream' ? 1.4 : 0"
          :stroke-dasharray="n.status === 'dream' ? '2 2' : 'none'" />
      </g>

      <circle :cx="cx" :cy="cy" r="3" fill="#ede9fe" fill-opacity="0.8" />

      <text v-if="nodes.length === 0" :x="cx" :y="cy + 34" text-anchor="middle" fill="#c4b5fd" fill-opacity="0.45"
        font-size="12">La memoria autobiográfica está vacía. Cada experiencia registrada aparecerá aquí.</text>
    </svg>

    <!-- Detalle al apuntar un nodo -->
    <Transition name="fade">
      <div v-if="hovered" class="absolute left-1/2 -translate-x-1/2 bottom-2 max-w-md w-[90%] px-4 py-3 rounded-2xl
        bg-slate-950/85 backdrop-blur border border-violet-300/15 text-xs text-violet-50/90 shadow-xl pointer-events-none">
        <template v-for="n in nodes.filter(x => x.id === hovered)" :key="n.id">
          <div class="flex items-center gap-2 mb-1 text-[10px] uppercase tracking-widest" :style="{ color: n.color }">
            {{ STATUS_LABEL[n.status] }}<span v-if="n.status === 'hypothesis'"> · {{ Math.round((n.confidence ?? 0) * 100) }}%</span>
            <span class="text-violet-200/40">· {{ TYPE_LABEL[n.type] }}</span>
          </div>
          <p class="leading-relaxed line-clamp-3">{{ n.content }}</p>
        </template>
      </div>
    </Transition>

    <ul class="flex flex-wrap justify-center gap-x-5 gap-y-1 mt-2 text-[11px] text-violet-200/60">
      <li class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-400"></span>hechos {{ counts.fact }}</li>
      <li class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-400/70"></span>hipótesis {{ counts.hypothesis }} <span class="text-violet-200/35">(opacidad = confianza)</span></li>
      <li class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full border border-dashed border-purple-400"></span>sueños {{ counts.dream }}</li>
    </ul>
  </div>
</template>

<style scoped>
.node { cursor: pointer; outline: none; transition: transform .2s; }
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
