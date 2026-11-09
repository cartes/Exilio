<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'

// Scouts de investigación web (Fase 6a): EXILIO propone, el auditor aprueba y lanza, y revisa cada
// hallazgo. Lo que se registra es «la fuente afirma X», no que X sea cierto.
const props = defineProps({
  agentId: { type: String, default: null },
})
const emit = defineEmits(['memories-changed', 'error', 'success'])

const scouts = ref([])
const detail = ref({})        // scout_id -> { scout, documents, findings }
const expanded = ref(null)
const busy = ref(false)
const own = ref({ topic: '', query: '', max_pages: 5 })

const STATUS = {
  proposed: ['propuesta', 'text-amber-300 border-amber-300/30'],
  approved: ['aprobada', 'text-cyan-300 border-cyan-300/30'],
  running: ['en curso', 'text-violet-200 border-violet-300/40 animate-pulse'],
  completed: ['completada', 'text-emerald-300 border-emerald-300/30'],
  failed: ['fallida', 'text-rose-300 border-rose-300/30'],
  rejected: ['rechazada', 'text-slate-400 border-slate-400/30'],
  cancelled: ['cancelada', 'text-slate-400 border-slate-400/30'],
}

const anyRunning = computed(() => scouts.value.some(s => s.status === 'running'))

async function api(path, method = 'GET', body = null) {
  const res = await fetch(`/api${path}`, {
    method,
    headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(data.message || (data.errors && Object.values(data.errors).flat().join(' ')) || `Error ${res.status}`)
  }
  return data
}

async function load() {
  if (!props.agentId) return
  try {
    scouts.value = await api(`/agents/${props.agentId}/research/scouts`)
    if (expanded.value) await loadDetail(expanded.value)
  } catch (err) {
    emit('error', err.message)
  }
}

async function loadDetail(id) {
  detail.value[id] = await api(`/research/scouts/${id}`)
}

async function toggle(id) {
  expanded.value = expanded.value === id ? null : id
  if (expanded.value) {
    try { await loadDetail(id) } catch (err) { emit('error', err.message) }
  }
}

async function act(fn, okMsg) {
  if (busy.value) return
  busy.value = true
  try {
    await fn()
    if (okMsg) emit('success', okMsg)
    await load()
  } catch (err) {
    emit('error', err.message)
  } finally {
    busy.value = false
  }
}

const propose = () => act(
  () => api(`/agents/${props.agentId}/research/propose`, 'POST'),
  'EXILIO-001 propuso una misión de investigación. Revísala antes de aprobarla.'
)
const approve = (s) => act(() => api(`/research/scouts/${s.id}/approve`, 'POST', {}))
const reject = (s) => act(() => api(`/research/scouts/${s.id}/reject`, 'POST'))
const cancel = (s) => act(() => api(`/research/scouts/${s.id}/cancel`, 'POST'), 'Misión detenida.')
const launch = (s) => act(async () => {
  await api(`/research/scouts/${s.id}/run`, 'POST')
  expanded.value = s.id
}, 'Misión lanzada: el scout busca y descarga páginas en modo solo lectura.')

const createOwn = () => act(async () => {
  if (!own.value.topic.trim()) throw new Error('Indica un tema para la misión.')
  await api(`/agents/${props.agentId}/research/scouts`, 'POST', {
    topic: own.value.topic.trim(),
    query: own.value.query.trim() || null,
    max_pages: Number(own.value.max_pages) || 5,
  })
  own.value = { topic: '', query: '', max_pages: 5 }
}, 'Misión creada (aprobada por ti). Pulsa «Lanzar» cuando quieras ejecutarla.')

const approveFinding = (f, scoutId) => act(async () => {
  await api(`/research/findings/${f.id}/approve`, 'POST')
  await loadDetail(scoutId)
  emit('memories-changed')
}, 'Registrado como hecho observable: la fuente lo afirma (no implica que sea cierto).')

const rejectFinding = (f, scoutId) => act(async () => {
  await api(`/research/findings/${f.id}/reject`, 'POST')
  await loadDetail(scoutId)
})

function safeUrl(u) {
  return /^https?:\/\//i.test(u || '') ? u : null
}
function host(u) {
  try { return new URL(u).hostname } catch { return u }
}

// Observación (no es un bucle autónomo): solo consulta el estado mientras una misión lanzada por el
// auditor sigue en curso, y se detiene al terminar.
let timer = null
watch(anyRunning, (running) => {
  clearInterval(timer)
  timer = running ? setInterval(load, 3000) : null
})
watch(() => props.agentId, (id) => { if (id) load() }, { immediate: true })
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <section class="rounded-3xl border border-violet-300/10 bg-slate-950/30 backdrop-blur p-5 space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-sm tracking-[0.3em] uppercase text-violet-100/90" style="font-family: ui-serif, Georgia, serif">
          Exploradores web
        </h2>
        <p class="text-[11px] text-violet-200/45 mt-1">
          Solo lectura. EXILIO propone, tú apruebas. Lo que se guarda es «la fuente afirma…», no una verdad verificada.
        </p>
      </div>
      <button type="button" @click="propose" :disabled="busy || !agentId"
        class="px-4 py-1.5 rounded-full text-xs tracking-widest uppercase text-violet-100 bg-violet-500/10 hover:bg-violet-500/20
          border border-violet-300/20 transition disabled:opacity-40">
        Que EXILIO proponga una misión
      </button>
    </div>

    <details class="text-xs text-violet-200/60">
      <summary class="cursor-pointer hover:text-violet-100">Crear una misión propia</summary>
      <form class="mt-3 grid grid-cols-1 md:grid-cols-6 gap-2" @submit.prevent="createOwn">
        <input v-model="own.topic" placeholder="Tema" maxlength="120"
          class="md:col-span-2 px-3 py-1.5 rounded-lg bg-slate-950/70 border border-violet-300/15 text-violet-50 outline-none focus:border-violet-300/40" />
        <input v-model="own.query" placeholder="Pregunta (opcional)" maxlength="500"
          class="md:col-span-3 px-3 py-1.5 rounded-lg bg-slate-950/70 border border-violet-300/15 text-violet-50 outline-none focus:border-violet-300/40" />
        <input v-model.number="own.max_pages" type="number" min="1" max="8" title="Máximo de páginas (tope duro 8)"
          class="px-3 py-1.5 rounded-lg bg-slate-950/70 border border-violet-300/15 text-violet-50 outline-none focus:border-violet-300/40" />
        <button type="submit" :disabled="busy"
          class="md:col-span-6 justify-self-end px-4 py-1.5 rounded-full bg-violet-500/20 hover:bg-violet-500/30 border border-violet-300/25 text-violet-50 transition disabled:opacity-40">
          Crear misión
        </button>
      </form>
    </details>

    <p v-if="scouts.length === 0" class="text-xs text-violet-200/40 py-2">
      Aún no hay misiones. Pide a EXILIO que proponga una a partir de su mayor curiosidad.
    </p>

    <ul class="space-y-3">
      <li v-for="s in scouts" :key="s.id" class="rounded-2xl border border-violet-300/10 bg-slate-950/40 p-4 space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 space-y-1">
            <div class="flex flex-wrap items-center gap-2">
              <span :class="['px-2 py-0.5 rounded-full border text-[10px] uppercase tracking-widest', STATUS[s.status]?.[1]]">
                {{ STATUS[s.status]?.[0] || s.status }}
              </span>
              <span class="text-sm text-violet-50">{{ s.topic }}</span>
              <span class="text-[10px] text-violet-200/40">
                {{ s.origin === 'agent_proposal' ? 'propuesta por EXILIO' : 'creada por ti' }}
              </span>
            </div>
            <p v-if="s.proposal_reason" class="text-[11px] text-violet-200/55 leading-relaxed">{{ s.proposal_reason }}</p>
            <p class="text-[11px] text-violet-200/40">
              {{ s.pages_fetched }}/{{ s.max_pages }} páginas · {{ s.findings_count || 0 }} hallazgos
              <template v-if="s.pending_findings_count"> ({{ s.pending_findings_count }} por revisar)</template>
            </p>
            <p v-if="s.error" class="text-[11px] text-rose-300/90">{{ s.error }}</p>
          </div>

          <div class="flex flex-wrap gap-2 shrink-0">
            <button v-if="s.status === 'proposed'" type="button" @click="approve(s)" :disabled="busy"
              class="px-3 py-1 rounded-full text-[11px] bg-emerald-500/15 hover:bg-emerald-500/25 border border-emerald-300/30 text-emerald-100 transition disabled:opacity-40">Aprobar</button>
            <button v-if="s.status === 'approved'" type="button" @click="launch(s)" :disabled="busy"
              class="px-3 py-1 rounded-full text-[11px] bg-violet-500/25 hover:bg-violet-500/40 border border-violet-300/30 text-white transition disabled:opacity-40">Lanzar</button>
            <button v-if="['proposed', 'approved'].includes(s.status)" type="button" @click="reject(s)" :disabled="busy"
              class="px-3 py-1 rounded-full text-[11px] text-violet-200/60 hover:text-white transition">Rechazar</button>
            <button v-if="s.status === 'running'" type="button" @click="cancel(s)" :disabled="busy"
              class="px-3 py-1 rounded-full text-[11px] bg-rose-500/15 hover:bg-rose-500/25 border border-rose-300/30 text-rose-100 transition">Detener</button>
            <button v-if="['completed', 'failed', 'cancelled', 'running'].includes(s.status)" type="button" @click="toggle(s.id)"
              class="px-3 py-1 rounded-full text-[11px] text-violet-200/70 hover:text-white transition">
              {{ expanded === s.id ? 'Ocultar' : 'Ver detalle' }}
            </button>
          </div>
        </div>

        <div v-if="expanded === s.id && detail[s.id]" class="space-y-3 border-t border-violet-300/10 pt-3">
          <div v-if="detail[s.id].findings.length === 0" class="text-[11px] text-violet-200/40">
            Sin hallazgos todavía.
          </div>

          <article v-for="f in detail[s.id].findings" :key="f.id"
            class="rounded-xl bg-violet-500/5 border border-violet-300/10 p-3 space-y-2">
            <p class="text-xs leading-relaxed text-violet-50/90">«{{ f.excerpt }}»</p>
            <div class="flex flex-wrap items-center justify-between gap-2 text-[10px] text-violet-200/45">
              <span>
                Fuente:
                <a v-if="safeUrl(f.document?.url)" :href="safeUrl(f.document.url)" target="_blank" rel="noopener noreferrer nofollow"
                  class="text-violet-300 underline hover:text-white">{{ host(f.document.url) }}</a>
                <span v-else>{{ host(f.document?.url) }}</span>
                · relevancia {{ Number(f.relevance).toFixed(2) }}
              </span>
              <span v-if="f.status !== 'pending'" :class="f.status === 'approved' ? 'text-emerald-300' : 'text-slate-400'">
                {{ f.status === 'approved' ? 'registrado como hecho observable' : 'descartado' }}
              </span>
              <span v-else class="flex gap-2">
                <button type="button" @click="approveFinding(f, s.id)" :disabled="busy"
                  class="px-2.5 py-0.5 rounded-full bg-emerald-500/15 hover:bg-emerald-500/25 border border-emerald-300/30 text-emerald-100 transition disabled:opacity-40"
                  title="Guarda «la fuente afirma…» como hecho observable. No implica que sea cierto.">Registrar lo que afirma la fuente</button>
                <button type="button" @click="rejectFinding(f, s.id)" :disabled="busy"
                  class="px-2.5 py-0.5 rounded-full text-violet-200/60 hover:text-white transition">Descartar</button>
              </span>
            </div>
          </article>

          <details v-if="detail[s.id].scout.run_log?.length" class="text-[11px] text-violet-200/40">
            <summary class="cursor-pointer hover:text-violet-100">Registro de la ejecución ({{ detail[s.id].scout.run_log.length }})</summary>
            <ul class="mt-2 space-y-0.5 font-mono">
              <li v-for="(l, i) in detail[s.id].scout.run_log" :key="i" class="break-all">
                <span :class="{ 'text-emerald-300': l.result === 'ok', 'text-rose-300': l.result === 'blocked' || l.result === 'error' }">{{ l.result }}</span>
                · {{ l.url || '—' }} · {{ l.detail }}
              </li>
            </ul>
          </details>
        </div>
      </li>
    </ul>
  </section>
</template>
