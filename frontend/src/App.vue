<script setup>
import { ref, computed, onMounted, useTemplateRef } from 'vue'

const agent = ref(null)
const memories = ref([])
const tombstones = ref([])
const loading = ref(true)
const error = ref(null)
const successMessage = ref(null)

// Filters
const selectedType = ref('all')
const selectedEpistemicStatus = ref('all')
const includeSuperseded = ref(false)

// Modals
const createModal = useTemplateRef('createModal')
const rectifyModal = useTemplateRef('rectifyModal')
const deleteModal = useTemplateRef('deleteModal')
const tombstonesModal = useTemplateRef('tombstonesModal')

// Form states
const newMemoryForm = ref({
  type: 'episodic',
  epistemic_status: 'fact',
  origin: 'manual_entry',
  content: '',
  confidence: null,
  evidence_ref: '',
})

const rectifyTarget = ref(null)
const rectifyForm = ref({
  type: 'semantic',
  epistemic_status: 'hypothesis',
  origin: 'manual_entry',
  content: '',
  confidence: 0.5,
  evidence_ref: '',
})

const deleteTarget = ref(null)
const deleteReason = ref('Purga manual por auditoría')

// Light dismiss click fallback for browsers without native closedby support
function handleDialogClickFallback(event, dialogRef) {
  if (!('closedBy' in HTMLDialogElement.prototype) && dialogRef) {
    if (event.target !== dialogRef) return
    const rect = dialogRef.getBoundingClientRect()
    const isInside =
      rect.top <= event.clientY &&
      event.clientY <= rect.top + rect.height &&
      rect.left <= event.clientX &&
      event.clientX <= rect.left + rect.width
    if (!isInside) {
      dialogRef.close()
    }
  }
}

async function fetchAgent() {
  try {
    const res = await fetch('/api/agents')
    if (!res.ok) throw new Error(`Error ${res.status}: Fallo al cargar agentes`)
    const data = await res.json()
    const founder = data.find(a => a.name === 'EXILIO-001') || data[0]
    agent.value = founder
    return founder
  } catch (err) {
    error.value = err.message
    return null
  }
}

async function fetchMemories() {
  if (!agent.value) return
  loading.value = true
  error.value = null
  try {
    const params = new URLSearchParams()
    if (includeSuperseded.value) params.append('include_superseded', 'true')
    if (selectedType.value !== 'all') params.append('type', selectedType.value)
    if (selectedEpistemicStatus.value !== 'all') params.append('epistemic_status', selectedEpistemicStatus.value)

    const res = await fetch(`/api/agents/${agent.value.id}/memories?${params.toString()}`)
    if (!res.ok) throw new Error(`Error ${res.status}: Fallo al consultar memorias`)
    memories.value = await res.json()
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

async function fetchTombstones() {
  if (!agent.value) return
  try {
    const res = await fetch(`/api/agents/${agent.value.id}/tombstones`)
    if (!res.ok) throw new Error(`Error ${res.status}: Fallo al consultar tombstones`)
    tombstones.value = await res.json()
  } catch (err) {
    error.value = err.message
  }
}

function flashSuccess(msg) {
  successMessage.value = msg
  setTimeout(() => {
    successMessage.value = null
  }, 4000)
}

// Dialog open helpers
function openCreateModal() {
  newMemoryForm.value = {
    type: 'episodic',
    epistemic_status: 'fact',
    origin: 'manual_entry',
    content: '',
    confidence: null,
    evidence_ref: '',
  }
  createModal.value?.showModal()
}

function openRectifyModal(memory) {
  rectifyTarget.value = memory
  rectifyForm.value = {
    type: memory.type,
    epistemic_status: memory.epistemic_status,
    origin: 'manual_entry',
    content: memory.content,
    confidence: memory.confidence !== null ? memory.confidence : 0.5,
    evidence_ref: memory.evidence_ref || '',
  }
  rectifyModal.value?.showModal()
}

function openDeleteModal(memory) {
  deleteTarget.value = memory
  deleteReason.value = 'Purga manual por auditoría'
  deleteModal.value?.showModal()
}

function openTombstonesModal() {
  fetchTombstones()
  tombstonesModal.value?.showModal()
}

// Form logic
function onTypeChange(form) {
  if (form.type === 'oneiric') {
    form.epistemic_status = 'dream'
    form.confidence = null
  } else if (form.epistemic_status === 'dream') {
    form.epistemic_status = 'fact'
  }
  syncConfidence(form)
}

function onEpistemicChange(form) {
  if (form.epistemic_status === 'dream') {
    form.type = 'oneiric'
    form.confidence = null
  } else if (form.type === 'oneiric') {
    form.type = 'episodic'
  }
  syncConfidence(form)
}

function syncConfidence(form) {
  if (form.epistemic_status === 'hypothesis') {
    if (form.confidence === null || form.confidence === undefined) {
      form.confidence = 0.5
    }
  } else {
    form.confidence = null
  }
}

async function submitCreate() {
  if (!agent.value) return
  error.value = null

  const payload = {
    type: newMemoryForm.value.type,
    epistemic_status: newMemoryForm.value.epistemic_status,
    origin: newMemoryForm.value.origin,
    content: newMemoryForm.value.content,
    evidence_ref: newMemoryForm.value.evidence_ref || null,
  }

  if (newMemoryForm.value.epistemic_status === 'hypothesis') {
    payload.confidence = parseFloat(newMemoryForm.value.confidence)
  }

  try {
    const res = await fetch(`/api/agents/${agent.value.id}/memories`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload),
    })

    if (!res.ok) {
      const data = await res.json()
      throw new Error(data.message || JSON.stringify(data.errors) || 'Error al persistir memoria')
    }

    createModal.value?.close()
    flashSuccess('Memoria registrada con procedencia explícita.')
    await fetchMemories()
  } catch (err) {
    error.value = err.message
  }
}

async function submitRectify() {
  if (!rectifyTarget.value) return
  error.value = null

  const payload = {
    type: rectifyForm.value.type,
    epistemic_status: rectifyForm.value.epistemic_status,
    origin: rectifyForm.value.origin,
    content: rectifyForm.value.content,
    evidence_ref: rectifyForm.value.evidence_ref || null,
  }

  if (rectifyForm.value.epistemic_status === 'hypothesis') {
    payload.confidence = parseFloat(rectifyForm.value.confidence)
  }

  try {
    const res = await fetch(`/api/memories/${rectifyTarget.value.id}/rectify`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload),
    })

    if (!res.ok) {
      const data = await res.json()
      throw new Error(data.message || JSON.stringify(data.errors) || 'Error al rectificar memoria')
    }

    rectifyModal.value?.close()
    flashSuccess('Memoria rectificada exitosamente sin mutación in-place.')
    await fetchMemories()
  } catch (err) {
    error.value = err.message
  }
}

async function submitDelete() {
  if (!deleteTarget.value) return
  error.value = null

  try {
    const res = await fetch(`/api/memories/${deleteTarget.value.id}`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ reason: deleteReason.value }),
    })

    if (!res.ok) {
      const data = await res.json()
      throw new Error(data.message || 'Error al purgar memoria')
    }

    deleteModal.value?.close()
    flashSuccess('Memoria purgada permanentemente y registrada en tombstones.')
    await fetchMemories()
  } catch (err) {
    error.value = err.message
  }
}

onMounted(async () => {
  const currentAgent = await fetchAgent()
  if (currentAgent) {
    await fetchMemories()
  }
})
</script>

<template>
  <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans">
    <!-- Top System Bar -->
    <header class="border-b border-slate-800 bg-slate-900/80 backdrop-blur sticky top-0 z-30 px-6 py-4">
      <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <div class="h-3 w-3 rounded-full bg-cyan-400 animate-pulse"></div>
          <div>
            <h1 class="text-lg font-bold tracking-tight text-white flex items-center gap-2">
              PROYECTO EXILIO
              <span class="text-xs font-mono font-normal px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700">Fase 2: Observación & Auditoría</span>
            </h1>
            <p class="text-xs text-slate-400">Inspección ontológica y procedencia auditable sin inferencia de estados subjetivos</p>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-950/80 text-emerald-400 border border-emerald-800/60 font-mono">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
            localhost:8000
          </span>
          <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-slate-800 text-slate-300 border border-slate-700 font-mono">
            Modo Auditor Local
          </span>
        </div>
      </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-6 py-8 space-y-8">
      <!-- Notification Alerts -->
      <div v-if="successMessage" class="p-4 rounded-lg bg-emerald-950/80 border border-emerald-700 text-emerald-200 text-sm flex items-center justify-between">
        <span>✓ {{ successMessage }}</span>
        <button type="button" @click="successMessage = null" class="text-emerald-400 hover:text-white">✕</button>
      </div>

      <div v-if="error" class="p-4 rounded-lg bg-rose-950/80 border border-rose-700 text-rose-200 text-sm flex items-center justify-between">
        <span>⚠ {{ error }}</span>
        <button type="button" @click="error = null" class="text-rose-400 hover:text-white">✕</button>
      </div>

      <!-- Agent Overview & Cognitive Config -->
      <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Agent Identity Card -->
        <div class="lg:col-span-1 rounded-xl bg-slate-900 border border-slate-800 p-6 flex flex-col justify-between">
          <div>
            <div class="flex items-center justify-between mb-4">
              <span class="text-xs uppercase font-mono tracking-wider text-slate-400">Agente Fundador</span>
              <span class="text-xs font-mono px-2 py-0.5 rounded bg-cyan-950 text-cyan-400 border border-cyan-800">
                v{{ agent?.personality_version || 1 }}.0
              </span>
            </div>
            <h2 class="text-2xl font-bold text-white mb-1 font-mono">{{ agent?.name || 'Cargando...' }}</h2>
            <p class="text-xs font-mono text-slate-400 truncate mb-4" :title="agent?.id">
              UUID: {{ agent?.id || '—' }}
            </p>

            <div class="space-y-3 pt-4 border-t border-slate-800/80 text-xs">
              <div class="flex justify-between">
                <span class="text-slate-400">Estado operativo:</span>
                <span class="font-mono text-emerald-400 capitalize">{{ agent?.status || 'desconocido' }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-400">Memoria autobiográfica:</span>
                <span class="font-mono text-slate-300">Inicial vacía (auditada)</span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-400">Personalidad evolutiva:</span>
                <span class="font-mono text-slate-300">Sin sesgo predefinido</span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-400">Creado en:</span>
                <span class="font-mono text-slate-400">{{ agent?.created_at ? new Date(agent.created_at).toLocaleString() : '—' }}</span>
              </div>
            </div>
          </div>

          <div class="mt-6 pt-4 border-t border-slate-800 text-[11px] text-slate-500 italic">
            "No se asume consciencia, sentimientos ni sufrimiento. Estados y memoria computacionalmente observables."
          </div>
        </div>

        <!-- Curiosity & Cognition Settings Card -->
        <div class="lg:col-span-2 rounded-xl bg-slate-900 border border-slate-800 p-6 flex flex-col justify-between">
          <div>
            <div class="flex items-center justify-between mb-3">
              <span class="text-xs uppercase font-mono tracking-wider text-slate-400">Motivación Cognitiva</span>
              <span class="text-xs font-mono text-slate-400">Pesos normalizados (&Sigma; = 1.0)</span>
            </div>
            <h3 class="text-lg font-semibold text-white mb-2 flex items-center gap-2">
              Función de Curiosidad
              <code class="text-xs font-mono bg-slate-800 text-cyan-300 px-2 py-0.5 rounded">C = 0.30N + 0.25I + 0.30A + 0.15R</code>
            </h3>
            <p class="text-xs text-slate-400 mb-6">
              Mecanismo puro de motivación inicial. La relevancia adquirida se fundamenta exclusivamente en evidencias registradas sin precargas intrínsecas.
            </p>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
              <div class="p-3 rounded-lg bg-slate-950 border border-slate-800/80">
                <div class="text-[11px] font-mono text-slate-400 mb-1">N (Novedad)</div>
                <div class="text-xl font-bold font-mono text-cyan-400">0.30</div>
                <div class="text-[10px] text-slate-500 mt-1">Peso asignado: 30%</div>
              </div>
              <div class="p-3 rounded-lg bg-slate-950 border border-slate-800/80">
                <div class="text-[11px] font-mono text-slate-400 mb-1">I (Incertidumbre)</div>
                <div class="text-xl font-bold font-mono text-amber-400">0.25</div>
                <div class="text-[10px] text-slate-500 mt-1">Peso asignado: 25%</div>
              </div>
              <div class="p-3 rounded-lg bg-slate-950 border border-slate-800/80">
                <div class="text-[11px] font-mono text-slate-400 mb-1">A (Aprendizaje)</div>
                <div class="text-xl font-bold font-mono text-emerald-400">0.30</div>
                <div class="text-[10px] text-slate-500 mt-1">Peso asignado: 30%</div>
              </div>
              <div class="p-3 rounded-lg bg-slate-950 border border-slate-800/80">
                <div class="text-[11px] font-mono text-slate-400 mb-1">R (Relevancia)</div>
                <div class="text-xl font-bold font-mono text-indigo-400">0.15</div>
                <div class="text-[10px] text-slate-500 mt-1">Peso asignado: 15%</div>
              </div>
            </div>
          </div>

          <div class="p-3 rounded-lg bg-slate-950/60 border border-slate-800/60 flex flex-wrap items-center justify-between text-xs text-slate-400 gap-2">
            <span>Proveedor activo: <strong class="text-slate-200 font-mono">MockProvider</strong> (simulado / $0 gasto)</span>
            <span>Presupuesto límite ref.: <strong class="text-slate-200 font-mono">US$5.00/mes</strong> (inactivo en Fase 2)</span>
            <span>Bucles autónomos: <strong class="text-rose-400 font-mono">Deshabilitados</strong></span>
          </div>
        </div>
      </section>

      <!-- Memory Audit & Inspector Section -->
      <section class="space-y-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800 pb-4">
          <div>
            <h2 class="text-xl font-bold text-white flex items-center gap-3">
              Registros de Memoria Persistida
              <span class="text-xs font-mono bg-slate-800 text-slate-300 px-2.5 py-0.5 rounded-full border border-slate-700">
                {{ memories.length }} entradas
              </span>
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">Auditoría ontológica con separación estricta de hechos, hipótesis y simulaciones oníricas</p>
          </div>

          <div class="flex flex-wrap items-center gap-3">
            <button
              type="button"
              @click="openTombstonesModal"
              class="px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-900 hover:bg-slate-800 text-slate-300 border border-slate-700 transition"
            >
              Auditoría de Purgas (Tombstones)
            </button>
            <button
              type="button"
              @click="openCreateModal"
              class="px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-cyan-600 hover:bg-cyan-500 text-white shadow-sm transition flex items-center gap-1.5"
            >
              <span>+</span> Nueva Entrada Manual
            </button>
            <button
              type="button"
              @click="fetchMemories"
              title="Recargar registros"
              class="p-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-400 hover:text-white border border-slate-800 transition"
            >
              ↻
            </button>
          </div>
        </div>

        <!-- Filter Controls -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-4">
          <div class="flex flex-wrap gap-6 items-center">
            <!-- Taxonomy filter -->
            <div class="flex items-center gap-2">
              <span class="text-xs font-mono text-slate-400">Categoría:</span>
              <div class="inline-flex rounded-lg bg-slate-950 p-1 border border-slate-800 text-xs">
                <button
                  type="button"
                  v-for="cat in ['all', 'episodic', 'semantic', 'social', 'procedural', 'oneiric']"
                  :key="cat"
                  @click="selectedType = cat; fetchMemories()"
                  :class="[
                    'px-2.5 py-1 rounded capitalize transition font-mono',
                    selectedType === cat ? 'bg-slate-800 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200'
                  ]"
                >
                  {{ cat === 'all' ? 'Todas' : cat }}
                </button>
              </div>
            </div>

            <!-- Epistemic Status filter -->
            <div class="flex items-center gap-2">
              <span class="text-xs font-mono text-slate-400">Estado Epistémico:</span>
              <div class="inline-flex rounded-lg bg-slate-950 p-1 border border-slate-800 text-xs">
                <button
                  type="button"
                  v-for="st in ['all', 'fact', 'hypothesis', 'dream']"
                  :key="st"
                  @click="selectedEpistemicStatus = st; fetchMemories()"
                  :class="[
                    'px-2.5 py-1 rounded capitalize transition font-mono',
                    selectedEpistemicStatus === st ? 'bg-slate-800 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200'
                  ]"
                >
                  {{ st === 'all' ? 'Todos' : (st === 'fact' ? 'Hecho' : (st === 'hypothesis' ? 'Hipótesis' : 'Sueño')) }}
                </button>
              </div>
            </div>

            <!-- Include superseded toggle -->
            <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer select-none">
              <input
                type="checkbox"
                v-model="includeSuperseded"
                @change="fetchMemories"
                class="rounded bg-slate-950 border-slate-700 text-cyan-600 focus:ring-0"
              />
              <span>Incluir obsoletas / rectificadas</span>
            </label>
          </div>
        </div>

        <!-- Memories List -->
        <div v-if="loading" class="text-center py-16 text-slate-500 font-mono text-sm">
          Consultando registros de persistencia...
        </div>

        <div v-else-if="memories.length === 0" class="rounded-xl border border-dashed border-slate-800 p-12 text-center space-y-3">
          <div class="text-3xl text-slate-600">∅</div>
          <h4 class="text-sm font-semibold text-slate-300">No hay registros de memoria con los filtros seleccionados</h4>
          <p class="text-xs text-slate-500 max-w-md mx-auto">
            La memoria autobiográfica inicial se encuentra vacía de acuerdo a los principios ontológicos de EXILIO-001. Puedes registrar una entrada manual para pruebas.
          </p>
          <button
            type="button"
            @click="openCreateModal"
            class="mt-2 px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-800 hover:bg-slate-700 text-cyan-300 border border-slate-700 transition inline-block"
          >
            Registrar primera entrada
          </button>
        </div>

        <div v-else class="space-y-3">
          <article
            v-for="m in memories"
            :key="m.id"
            :class="[
              'rounded-xl border p-5 transition bg-slate-900/90 relative group',
              m.superseded_at ? 'border-slate-800/60 opacity-60 bg-slate-950/40' : 'border-slate-800 hover:border-slate-700',
              m.epistemic_status === 'fact' ? 'border-l-4 border-l-emerald-500' :
              (m.epistemic_status === 'hypothesis' ? 'border-l-4 border-l-amber-500' : 'border-l-4 border-l-purple-500')
            ]"
          >
            <!-- Card Header -->
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
              <div class="flex flex-wrap items-center gap-2">
                <!-- Epistemic Badge -->
                <span
                  v-if="m.epistemic_status === 'fact'"
                  class="px-2 py-0.5 rounded text-[11px] font-bold font-mono bg-emerald-950 text-emerald-300 border border-emerald-800"
                >
                  HECHO (Fact)
                </span>
                <span
                  v-else-if="m.epistemic_status === 'hypothesis'"
                  class="px-2 py-0.5 rounded text-[11px] font-bold font-mono bg-amber-950 text-amber-300 border border-amber-800 flex items-center gap-1.5"
                >
                  HIPÓTESIS
                  <span class="text-[10px] font-normal px-1 rounded bg-amber-900/50 text-amber-200">
                    Certeza: {{ (m.confidence * 100).toFixed(0) }}%
                  </span>
                </span>
                <span
                  v-else-if="m.epistemic_status === 'dream'"
                  class="px-2 py-0.5 rounded text-[11px] font-bold font-mono bg-purple-950 text-purple-300 border border-purple-800"
                >
                  SUEÑO / SIMULACIÓN
                </span>

                <!-- Taxonomy Category -->
                <span class="px-2 py-0.5 rounded text-[11px] font-mono bg-slate-800 text-slate-300 border border-slate-700 capitalize">
                  {{ m.type }}
                </span>

                <!-- Origin Badge -->
                <span class="text-[11px] font-mono text-slate-400">
                  vía <span class="text-slate-300 font-semibold">{{ m.origin }}</span>
                </span>

                <!-- Evidence Ref -->
                <span v-if="m.evidence_ref" class="text-[11px] font-mono text-cyan-400/80 bg-cyan-950/40 px-1.5 py-0.5 rounded border border-cyan-900/60">
                  ref: {{ m.evidence_ref }}
                </span>
              </div>

              <!-- Timestamps & Actions -->
              <div class="flex items-center gap-3 text-xs text-slate-400 font-mono">
                <span>{{ new Date(m.created_at).toLocaleString() }}</span>

                <div v-if="!m.superseded_at" class="flex items-center gap-1">
                  <button
                    type="button"
                    @click="openRectifyModal(m)"
                    class="px-2 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition text-xs"
                    title="Rectificar mediante nueva versión (no in-place)"
                  >
                    Rectificar
                  </button>
                  <button
                    type="button"
                    @click="openDeleteModal(m)"
                    class="px-2 py-1 rounded bg-rose-950/60 hover:bg-rose-900 text-rose-300 border border-rose-800/60 hover:text-white transition text-xs"
                    title="Purgar memoria permanentemente (genera tombstone)"
                  >
                    Purgar
                  </button>
                </div>
              </div>
            </div>

            <!-- Content -->
            <p class="text-sm text-slate-200 leading-relaxed font-sans mb-3 select-text">
              {{ m.content }}
            </p>

            <!-- Lineage & Versioning Status -->
            <div class="flex flex-wrap items-center justify-between text-xs pt-2 border-t border-slate-800/80 text-slate-400 font-mono">
              <div class="flex items-center gap-3">
                <span class="text-[11px] text-slate-500">ID: {{ m.id.substring(0, 8) }}...</span>
                <span v-if="m.supersedes_id" class="text-cyan-400 text-[11px]">
                  ↳ Rectifica a: {{ m.supersedes_id.substring(0, 8) }}...
                </span>
              </div>

              <div v-if="m.superseded_at" class="text-rose-400 text-[11px] flex items-center gap-1">
                <span>⚠ Obsoleta / Reemplazada en:</span>
                <span>{{ new Date(m.superseded_at).toLocaleString() }}</span>
              </div>
            </div>
          </article>
        </div>
      </section>
    </main>

    <!-- Modal: Nueva Entrada Manual -->
    <dialog
      ref="createModal"
      closedby="any"
      aria-labelledby="createModalTitle"
      @click="handleDialogClickFallback($event, createModal)"
      class="rounded-2xl bg-slate-900 border border-slate-700 text-slate-100 p-6 max-w-xl w-full shadow-2xl backdrop:bg-slate-950/80"
    >
      <form @submit.prevent="submitCreate" class="space-y-5">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 id="createModalTitle" class="text-base font-bold text-white font-mono">Registrar Entrada Manual de Memoria</h3>
          <button type="button" @click="createModal?.close()" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <p class="text-xs text-slate-400">
          Operación de desarrollo local. Las reglas epistémicas se validan estrictamente antes de persistir en PostgreSQL.
        </p>

        <!-- Taxonomy category selection -->
        <fieldset class="space-y-1.5">
          <legend class="text-xs font-mono text-slate-300">Categoría Taxonómica:</legend>
          <div class="grid grid-cols-3 gap-2 text-xs">
            <label
              v-for="t in ['episodic', 'semantic', 'social', 'procedural', 'oneiric']"
              :key="t"
              :class="[
                'flex items-center gap-2 p-2 rounded-lg border cursor-pointer capitalize font-mono',
                newMemoryForm.type === t ? 'bg-cyan-950/80 border-cyan-600 text-cyan-200' : 'bg-slate-950 border-slate-800 text-slate-400 hover:border-slate-700'
              ]"
            >
              <input
                type="radio"
                name="new_type"
                :value="t"
                v-model="newMemoryForm.type"
                @change="onTypeChange(newMemoryForm)"
                class="hidden"
              />
              <span>{{ t }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Epistemic Status selection -->
        <fieldset class="space-y-1.5">
          <legend class="text-xs font-mono text-slate-300">Estado Epistémico:</legend>
          <div class="grid grid-cols-3 gap-2 text-xs">
            <label
              v-for="s in ['fact', 'hypothesis', 'dream']"
              :key="s"
              :class="[
                'flex items-center gap-2 p-2 rounded-lg border cursor-pointer capitalize font-mono',
                newMemoryForm.epistemic_status === s ? 'bg-cyan-950/80 border-cyan-600 text-cyan-200' : 'bg-slate-950 border-slate-800 text-slate-400 hover:border-slate-700'
              ]"
            >
              <input
                type="radio"
                name="new_status"
                :value="s"
                v-model="newMemoryForm.epistemic_status"
                @change="onEpistemicChange(newMemoryForm)"
                class="hidden"
              />
              <span>{{ s === 'fact' ? 'Hecho' : (s === 'hypothesis' ? 'Hipótesis' : 'Sueño') }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Confidence input (only if hypothesis) -->
        <div v-if="newMemoryForm.epistemic_status === 'hypothesis'" class="space-y-1.5 p-3 rounded-lg bg-amber-950/30 border border-amber-900/60">
          <div class="flex items-center justify-between text-xs">
            <label for="new_confidence" class="font-mono text-amber-300">Nivel de Confianza (Incertidumbre):</label>
            <span class="font-mono font-bold text-amber-200">{{ (newMemoryForm.confidence * 100).toFixed(0) }}%</span>
          </div>
          <input
            id="new_confidence"
            type="range"
            min="0.0"
            max="1.0"
            step="0.05"
            v-model="newMemoryForm.confidence"
            class="w-full accent-amber-500 cursor-pointer"
          />
        </div>

        <!-- Evidence ref input -->
        <div class="space-y-1">
          <label for="new_evidence" class="text-xs font-mono text-slate-300">Referencia de Evidencia (opcional):</label>
          <input
            id="new_evidence"
            type="text"
            v-model="newMemoryForm.evidence_ref"
            placeholder="ej. msg-042, obs-console-01"
            class="w-full text-xs font-mono px-3 py-2 rounded-lg bg-slate-950 border border-slate-800 text-slate-200 focus:outline-none focus:border-cyan-500"
          />
        </div>

        <!-- Content textarea -->
        <div class="space-y-1">
          <label for="new_content" class="text-xs font-mono text-slate-300">Contenido Observable / Enunciado:</label>
          <textarea
            id="new_content"
            v-model="newMemoryForm.content"
            required
            rows="3"
            placeholder="Descripción precisa del registro de experiencia..."
            class="w-full text-xs px-3 py-2 rounded-lg bg-slate-950 border border-slate-800 text-slate-200 focus:outline-none focus:border-cyan-500 leading-relaxed"
          ></textarea>
        </div>

        <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
          <button
            type="button"
            @click="createModal?.close()"
            class="px-4 py-2 rounded-lg text-xs font-mono text-slate-400 hover:text-white"
          >
            Cancelar
          </button>
          <button
            type="submit"
            class="px-4 py-2 rounded-lg text-xs font-semibold font-mono bg-cyan-600 hover:bg-cyan-500 text-white transition"
          >
            Persistir Registro
          </button>
        </div>
      </form>
    </dialog>

    <!-- Modal: Rectificar Memoria -->
    <dialog
      ref="rectifyModal"
      closedby="any"
      aria-labelledby="rectifyModalTitle"
      @click="handleDialogClickFallback($event, rectifyModal)"
      class="rounded-2xl bg-slate-900 border border-slate-700 text-slate-100 p-6 max-w-xl w-full shadow-2xl backdrop:bg-slate-950/80"
    >
      <form @submit.prevent="submitRectify" class="space-y-5">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 id="rectifyModalTitle" class="text-base font-bold text-white font-mono">Rectificar Memoria</h3>
          <button type="button" @click="rectifyModal?.close()" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <div class="p-3 rounded-lg bg-slate-950 border border-slate-800 text-xs space-y-1">
          <span class="text-slate-500 font-mono">Registro original a rectificar:</span>
          <p class="text-slate-300 italic truncate">{{ rectifyTarget?.content }}</p>
          <span class="text-[10px] text-cyan-400 font-mono">
            La versión actual quedará marcada como superseded_at y se creará una nueva entrada referenciando supersedes_id.
          </span>
        </div>

        <!-- Taxonomy category selection -->
        <fieldset class="space-y-1.5">
          <legend class="text-xs font-mono text-slate-300">Categoría Taxonómica:</legend>
          <div class="grid grid-cols-3 gap-2 text-xs">
            <label
              v-for="t in ['episodic', 'semantic', 'social', 'procedural', 'oneiric']"
              :key="t"
              :class="[
                'flex items-center gap-2 p-2 rounded-lg border cursor-pointer capitalize font-mono',
                rectifyForm.type === t ? 'bg-cyan-950/80 border-cyan-600 text-cyan-200' : 'bg-slate-950 border-slate-800 text-slate-400 hover:border-slate-700'
              ]"
            >
              <input
                type="radio"
                name="rectify_type"
                :value="t"
                v-model="rectifyForm.type"
                @change="onTypeChange(rectifyForm)"
                class="hidden"
              />
              <span>{{ t }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Epistemic Status selection -->
        <fieldset class="space-y-1.5">
          <legend class="text-xs font-mono text-slate-300">Estado Epistémico:</legend>
          <div class="grid grid-cols-3 gap-2 text-xs">
            <label
              v-for="s in ['fact', 'hypothesis', 'dream']"
              :key="s"
              :class="[
                'flex items-center gap-2 p-2 rounded-lg border cursor-pointer capitalize font-mono',
                rectifyForm.epistemic_status === s ? 'bg-cyan-950/80 border-cyan-600 text-cyan-200' : 'bg-slate-950 border-slate-800 text-slate-400 hover:border-slate-700'
              ]"
            >
              <input
                type="radio"
                name="rectify_status"
                :value="s"
                v-model="rectifyForm.epistemic_status"
                @change="onEpistemicChange(rectifyForm)"
                class="hidden"
              />
              <span>{{ s === 'fact' ? 'Hecho' : (s === 'hypothesis' ? 'Hipótesis' : 'Sueño') }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Confidence input (only if hypothesis) -->
        <div v-if="rectifyForm.epistemic_status === 'hypothesis'" class="space-y-1.5 p-3 rounded-lg bg-amber-950/30 border border-amber-900/60">
          <div class="flex items-center justify-between text-xs">
            <label for="rectify_confidence" class="font-mono text-amber-300">Nivel de Confianza (Incertidumbre):</label>
            <span class="font-mono font-bold text-amber-200">{{ (rectifyForm.confidence * 100).toFixed(0) }}%</span>
          </div>
          <input
            id="rectify_confidence"
            type="range"
            min="0.0"
            max="1.0"
            step="0.05"
            v-model="rectifyForm.confidence"
            class="w-full accent-amber-500 cursor-pointer"
          />
        </div>

        <!-- Evidence ref input -->
        <div class="space-y-1">
          <label for="rectify_evidence" class="text-xs font-mono text-slate-300">Referencia de Nueva Evidencia:</label>
          <input
            id="rectify_evidence"
            type="text"
            v-model="rectifyForm.evidence_ref"
            placeholder="ej. contra-evidencia-02"
            class="w-full text-xs font-mono px-3 py-2 rounded-lg bg-slate-950 border border-slate-800 text-slate-200 focus:outline-none focus:border-cyan-500"
          />
        </div>

        <!-- Content textarea -->
        <div class="space-y-1">
          <label for="rectify_content" class="text-xs font-mono text-slate-300">Contenido Rectificado:</label>
          <textarea
            id="rectify_content"
            v-model="rectifyForm.content"
            required
            rows="3"
            placeholder="Nueva formulación corregida..."
            class="w-full text-xs px-3 py-2 rounded-lg bg-slate-950 border border-slate-800 text-slate-200 focus:outline-none focus:border-cyan-500 leading-relaxed"
          ></textarea>
        </div>

        <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
          <button
            type="button"
            @click="rectifyModal?.close()"
            class="px-4 py-2 rounded-lg text-xs font-mono text-slate-400 hover:text-white"
          >
            Cancelar
          </button>
          <button
            type="submit"
            class="px-4 py-2 rounded-lg text-xs font-semibold font-mono bg-cyan-600 hover:bg-cyan-500 text-white transition"
          >
            Crear Versión Rectificada
          </button>
        </div>
      </form>
    </dialog>

    <!-- Modal: Eliminar / Purga con Tombstone -->
    <dialog
      ref="deleteModal"
      closedby="any"
      aria-labelledby="deleteModalTitle"
      @click="handleDialogClickFallback($event, deleteModal)"
      class="rounded-2xl bg-slate-900 border border-rose-800 text-slate-100 p-6 max-w-md w-full shadow-2xl backdrop:bg-slate-950/80"
    >
      <form @submit.prevent="submitDelete" class="space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 id="deleteModalTitle" class="text-base font-bold text-rose-400 font-mono">Confirmar Purga Permanente</h3>
          <button type="button" @click="deleteModal?.close()" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <p class="text-xs text-slate-300 leading-relaxed">
          Esta acción eliminará físicamente el contenido de la memoria de la tabla <code class="text-rose-300 font-mono">memories</code>. No se conservará texto sensible. Se registrará un tombstone mínimo con el motivo de auditoría.
        </p>

        <div class="space-y-1">
          <label for="delete_reason" class="text-xs font-mono text-slate-400">Motivo de la purga:</label>
          <input
            id="delete_reason"
            type="text"
            v-model="deleteReason"
            required
            placeholder="ej. Solicitud de olvido, error de captura"
            class="w-full text-xs font-mono px-3 py-2 rounded-lg bg-slate-950 border border-slate-800 text-slate-200 focus:outline-none focus:border-rose-500"
          />
        </div>

        <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
          <button
            type="button"
            @click="deleteModal?.close()"
            class="px-4 py-2 rounded-lg text-xs font-mono text-slate-400 hover:text-white"
          >
            Cancelar
          </button>
          <button
            type="submit"
            class="px-4 py-2 rounded-lg text-xs font-semibold font-mono bg-rose-700 hover:bg-rose-600 text-white transition"
          >
            Confirmar y Purgar
          </button>
        </div>
      </form>
    </dialog>

    <!-- Modal: Tombstones (Auditoría de Purgas) -->
    <dialog
      ref="tombstonesModal"
      closedby="any"
      aria-labelledby="tombstonesModalTitle"
      @click="handleDialogClickFallback($event, tombstonesModal)"
      class="rounded-2xl bg-slate-900 border border-slate-700 text-slate-100 p-6 max-w-2xl w-full shadow-2xl backdrop:bg-slate-950/80"
    >
      <div class="space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 id="tombstonesModalTitle" class="text-base font-bold text-white font-mono">Registro de Purgas (Memory Tombstones)</h3>
          <button type="button" @click="tombstonesModal?.close()" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <p class="text-xs text-slate-400">
          Auditoría de registros eliminados. Los tombstones garantizan trazabilidad sin almacenar texto o datos sensibles originales.
        </p>

        <div v-if="tombstones.length === 0" class="py-8 text-center text-slate-500 font-mono text-xs border border-dashed border-slate-800 rounded-lg">
          No hay registros de purgas registrados.
        </div>

        <div v-else class="max-h-80 overflow-y-auto space-y-2 pr-1">
          <div
            v-for="t in tombstones"
            :key="t.id"
            class="p-3 rounded-lg bg-slate-950 border border-slate-800 flex items-center justify-between gap-4 text-xs font-mono"
          >
            <div>
              <div class="text-slate-300 font-semibold">{{ t.reason || 'Sin motivo especificado' }}</div>
              <div class="text-[11px] text-slate-500 mt-0.5">Memoria ID: {{ t.memory_id }}</div>
            </div>
            <span class="text-slate-400 text-[11px] shrink-0">{{ new Date(t.created_at).toLocaleString() }}</span>
          </div>
        </div>

        <div class="flex justify-end pt-3 border-t border-slate-800">
          <button
            type="button"
            @click="tombstonesModal?.close()"
            class="px-4 py-2 rounded-lg text-xs font-mono bg-slate-800 hover:bg-slate-700 text-slate-200 transition"
          >
            Cerrar
          </button>
        </div>
      </div>
    </dialog>
  </div>
</template>

