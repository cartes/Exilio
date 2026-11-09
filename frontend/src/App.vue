<script setup>
import { ref, computed, onMounted, nextTick, useTemplateRef } from 'vue'

const agent = ref(null)
const memories = ref([])
const tombstones = ref([])
const versions = ref([])
const loading = ref(true)
const error = ref(null)
const successMessage = ref(null)

// Chat state (Fase 4)
const chatMessages = ref([])
const chatTelemetry = ref(null)
const chatInput = ref('')
const sendingChat = ref(false)
const chatBoxRef = useTemplateRef('chatBoxRef')

// Active Process Monitor
const activeProcess = ref({
  status: 'idle', // 'idle' | 'running'
  title: 'En reposo (Idle)',
  detail: 'Sin procesos en segundo plano. Esperando interacción del auditor.',
  lastAction: 'Sistema iniciado y sincronizado',
  lastActionTime: new Date().toLocaleTimeString(),
})

function setProcess(status, title, detail) {
  activeProcess.value.status = status
  activeProcess.value.title = title
  activeProcess.value.detail = detail
  if (status === 'running') {
    activeProcess.value.lastAction = title
    activeProcess.value.lastActionTime = new Date().toLocaleTimeString()
  }
}

// Filters
const selectedType = ref('all')
const selectedEpistemicStatus = ref('all')
const includeSuperseded = ref(false)

// Modals
const createModal = useTemplateRef('createModal')
const rectifyModal = useTemplateRef('rectifyModal')
const deleteModal = useTemplateRef('deleteModal')
const tombstonesModal = useTemplateRef('tombstonesModal')
const learnModal = useTemplateRef('learnModal')
const versionsModal = useTemplateRef('versionsModal')
const inquiryModal = useTemplateRef('inquiryModal')

// Exploration Inquiry State (Fase 4 - Opción A)
const exploringInquiry = ref(false)
const inquiryReport = ref(null)
const persistingFinding = ref(false)

// Form states
const learnForm = ref({
  evidence_memory_ids: [],
  summary: 'Operación explícita de aprendizaje controlado',
  trait_key: '',
  trait_value: 0.5,
  interest_topic: '',
  exploratory_query: '',
})

const revertReason = ref('Reversión explícita por auditoría')

const personalityList = computed(() => {
  return Object.values(agent.value?.attributes?.personality || {})
})

const interestsList = computed(() => {
  return Object.values(agent.value?.attributes?.interests || {})
})

const topInterest = computed(() => {
  const list = interestsList.value
  if (!list || list.length === 0) return null
  return [...list].sort((a, b) => (b.curiosity_score || 0) - (a.curiosity_score || 0))[0]
})

const activeMemories = computed(() => {
  return memories.value.filter(m => !m.superseded_at)
})

const selectedEvidenceMemories = computed(() => {
  const ids = new Set(learnForm.value.evidence_memory_ids)
  return activeMemories.value.filter(m => ids.has(m.id))
})

const isOnlyDreamEvidenceSelected = computed(() => {
  if (selectedEvidenceMemories.value.length === 0) return false
  return selectedEvidenceMemories.value.every(m => m.epistemic_status === 'dream')
})

function getEvidenceStatus(memId) {
  const audit = agent.value?.evidence_audit
  if (audit) {
    if ((audit.purged_memory_ids || []).includes(memId)) return 'purged'
    if ((audit.superseded_memory_ids || []).includes(memId)) return 'superseded'
  }
  return 'active'
}

function hasStaleEvidenceInList(memoryIds) {
  return (memoryIds || []).some(id => getEvidenceStatus(id) !== 'active')
}
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

async function fetchVersions() {
  if (!agent.value) return
  try {
    const res = await fetch(`/api/agents/${agent.value.id}/versions`)
    if (!res.ok) throw new Error(`Error ${res.status}: Fallo al consultar historial de versiones`)
    versions.value = await res.json()
  } catch (err) {
    error.value = err.message
  }
}

function openLearnModal() {
  learnForm.value = {
    evidence_memory_ids: [],
    summary: 'Operación explícita de aprendizaje controlado',
    trait_key: '',
    trait_value: 0.5,
    interest_topic: '',
    exploratory_query: '',
  }
  learnModal.value?.showModal()
}

function openVersionsModal() {
  fetchVersions()
  versionsModal.value?.showModal()
}

async function submitLearn() {
  if (!agent.value) return
  error.value = null
  setProcess('running', 'Ejecutando Aprendizaje Controlado', 'Consolidando rasgos y generando snapshot inmutable...')

  const payload = {
    evidence_memory_ids: learnForm.value.evidence_memory_ids,
    summary: learnForm.value.summary || 'Operación explícita de aprendizaje controlado',
  }

  if (!isOnlyDreamEvidenceSelected.value && learnForm.value.trait_key.trim() !== '') {
    payload.trait_updates = {
      [learnForm.value.trait_key.trim()]: parseFloat(learnForm.value.trait_value),
    }
  }

  if (learnForm.value.interest_topic.trim() !== '') {
    payload.interest_topics = [
      {
        topic: learnForm.value.interest_topic.trim(),
        exploratory_query: learnForm.value.exploratory_query.trim() || null,
      },
    ]
  }

  try {
    const res = await fetch(`/api/agents/${agent.value.id}/learn`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload),
    })

    if (!res.ok) {
      const data = await res.json()
      throw new Error(data.message || JSON.stringify(data.errors) || 'Error al ejecutar aprendizaje')
    }

    const data = await res.json()
    agent.value = data.agent
    learnModal.value?.close()
    flashSuccess(`Aprendizaje registrado en v${data.agent.personality_version}.0 con evidencia trazable.`)
    await fetchVersions()
  } catch (err) {
    error.value = err.message
  } finally {
    setProcess('idle', 'En reposo (Idle)', 'Ciclo de aprendizaje completado a las ' + new Date().toLocaleTimeString())
  }
}

async function submitRevert(targetVersion) {
  if (!agent.value) return
  error.value = null
  setProcess('running', 'Revirtiendo Versión', 'Restaurando snapshot auditado...')

  try {
    const res = await fetch(`/api/agents/${agent.value.id}/versions/${targetVersion}/revert`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ reason: revertReason.value || 'Reversión explícita por auditoría' }),
    })

    if (!res.ok) {
      const data = await res.json()
      throw new Error(data.message || JSON.stringify(data.errors) || 'Error al revertir versión')
    }

    const data = await res.json()
    agent.value = data.agent
    flashSuccess(`Estado restaurado desde v${targetVersion}.0 (nueva versión auditable: v${data.agent.personality_version}.0).`)
    await fetchVersions()
  } catch (err) {
    error.value = err.message
  } finally {
    setProcess('idle', 'En reposo (Idle)', 'Reversión completada a las ' + new Date().toLocaleTimeString())
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
    await Promise.all([fetchMemories(), fetchAgent()])
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
    await Promise.all([fetchMemories(), fetchAgent()])
  } catch (err) {
    error.value = err.message
  }
}

async function fetchChat() {
  if (!agent.value) return
  try {
    const res = await fetch(`/api/agents/${agent.value.id}/chat`)
    if (res.ok) {
      const data = await res.json()
      chatMessages.value = data.messages || []
      if (data.telemetry) {
        chatTelemetry.value = data.telemetry
      }
      await scrollToBottom()
    }
  } catch (err) {
    console.warn('Fallo al obtener historial de chat:', err)
  }
  await fetchAiUsage()
}

async function fetchAiUsage() {
  if (!agent.value) return
  try {
    const res = await fetch(`/api/agents/${agent.value.id}/ai-usage`)
    if (res.ok) {
      const usageData = await res.json()
      chatTelemetry.value = {
        ...chatTelemetry.value,
        ...usageData,
      }
    }
  } catch (err) {
    console.warn('Fallo al obtener telemetría de IA:', err)
  }
}

async function scrollToBottom() {
  await nextTick()
  if (chatBoxRef.value) {
    chatBoxRef.value.scrollTop = chatBoxRef.value.scrollHeight
  }
}

async function sendChat(predefinedText) {
  const text = (typeof predefinedText === 'string' ? predefinedText : chatInput.value || '').trim()
  if (!text || !agent.value || sendingChat.value) return

  sendingChat.value = true
  error.value = null
  chatInput.value = ''
  setProcess('running', 'Generando Diálogo Introspectivo', 'Consultando estado en PostgreSQL y procesando respuesta...')

  const tempUserMsg = {
    id: 'temp-' + Date.now(),
    role: 'user',
    content: text,
    created_at: new Date().toISOString(),
    grounding_snapshot: null,
  }
  chatMessages.value.push(tempUserMsg)
  await scrollToBottom()

  try {
    const res = await fetch(`/api/agents/${agent.value.id}/chat`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ message: text }),
    })

    if (!res.ok) {
      const data = await res.json()
      throw new Error(data.message || 'Error al comunicarse con EXILIO-001')
    }

    const data = await res.json()
    chatMessages.value = chatMessages.value.filter(m => m.id !== tempUserMsg.id)
    if (data.user_message) {
      chatMessages.value.push(data.user_message)
    }
    const agentMsg = data.agent_message || data.assistant_message
    if (agentMsg) {
      chatMessages.value.push(agentMsg)
    }
    if (data.telemetry) {
      chatTelemetry.value = {
        ...chatTelemetry.value,
        ...data.telemetry,
      }
    }
    await scrollToBottom()

    // Refresh memories and agent state (user message is saved as episodic fact)
    await Promise.all([fetchMemories(), fetchAgent()])
  } catch (err) {
    error.value = err.message
    chatMessages.value = chatMessages.value.filter(m => m.id !== tempUserMsg.id)
  } finally {
    sendingChat.value = false
    setProcess('idle', 'En reposo (Idle)', 'Última respuesta generada a las ' + new Date().toLocaleTimeString())
  }
}

async function runInquiryCycle(requestedTopic = null) {
  if (!agent.value || exploringInquiry.value) return
  exploringInquiry.value = true
  error.value = null
  setProcess('running', 'Ejecutando Ciclo de Indagación Cognitiva', 'EXILIO-001 analiza su curiosidad y formula hipótesis con Gemini...')

  try {
    const res = await fetch(`/api/agents/${agent.value.id}/explore`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ topic: requestedTopic || undefined }),
    })

    if (!res.ok) {
      const data = await res.json()
      throw new Error(data.message || 'Error al ejecutar ciclo de indagación')
    }

    const data = await res.json()
    inquiryReport.value = data.inquiry_report
    inquiryModal.value?.showModal()
    await fetchAiUsage()
  } catch (err) {
    error.value = err.message
  } finally {
    exploringInquiry.value = false
    setProcess('idle', 'En reposo (Idle)', 'Ciclo de indagación completado a las ' + new Date().toLocaleTimeString())
  }
}

async function persistInquiryFinding() {
  if (!agent.value || !inquiryReport.value || persistingFinding.value) return
  persistingFinding.value = true
  error.value = null
  setProcess('running', 'Persistiendo Hipótesis de Indagación', 'Guardando en PostgreSQL como memoria de inferencia...')

  try {
    const res = await fetch(`/api/agents/${agent.value.id}/explore/persist`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        topic: inquiryReport.value.topic,
        hypothesis_content: inquiryReport.value.hypothesis_content,
        confidence: inquiryReport.value.confidence,
        taxonomic_type: inquiryReport.value.taxonomic_type,
        next_exploratory_question: inquiryReport.value.next_exploratory_question,
      }),
    })

    if (!res.ok) {
      const data = await res.json()
      throw new Error(data.message || 'Error al persistir hipótesis de indagación')
    }

    const data = await res.json()
    flashSuccess('Hipótesis de indagación persistida exitosamente en la memoria autobiográfica.')
    inquiryModal.value?.close()
    await Promise.all([fetchMemories(), fetchAgent(), fetchVersions()])
  } catch (err) {
    error.value = err.message
  } finally {
    persistingFinding.value = false
    setProcess('idle', 'En reposo (Idle)', 'Hipótesis guardada a las ' + new Date().toLocaleTimeString())
  }
}

onMounted(async () => {
  setProcess('running', 'Sincronizando estado inicial', 'Cargando agente, memorias, versiones y telemetría...')
  const currentAgent = await fetchAgent()
  if (currentAgent) {
    await Promise.all([fetchMemories(), fetchVersions(), fetchChat()])
  }
  setProcess('idle', 'En reposo (Idle)', 'Sistema sincronizado con PostgreSQL a las ' + new Date().toLocaleTimeString())
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
              <span
                class="text-xs font-mono font-normal px-2 py-0.5 rounded bg-slate-800 text-cyan-300 border border-cyan-800">Fase
                4: Diálogo Introspectivo (Gemini 2.5) & Aprendizaje Controlado</span>
            </h1>
            <p class="text-xs text-slate-400">Inspección ontológica, procedencia auditable y evolución versionada sin
              inferencia de estados subjetivos</p>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <!-- Monitor de Procesos en Tiempo Real -->
          <div
            :class="[
              'inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-mono border transition-all',
              activeProcess.status === 'running'
                ? 'bg-amber-950/90 text-amber-300 border-amber-600 ring-2 ring-amber-500/30'
                : 'bg-slate-900 text-slate-300 border-slate-700'
            ]"
            :title="activeProcess.detail"
          >
            <span
              :class="[
                'h-2 w-2 rounded-full',
                activeProcess.status === 'running' ? 'bg-amber-400 animate-ping' : 'bg-emerald-400'
              ]"
            ></span>
            <span class="font-bold">{{ activeProcess.status === 'running' ? 'PROCESANDO:' : 'ESTADO:' }}</span>
            <span>{{ activeProcess.title }}</span>
          </div>

          <span
            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-950/80 text-emerald-400 border border-emerald-800/60 font-mono">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
            localhost:8000
          </span>
          <span
            class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-slate-800 text-slate-300 border border-slate-700 font-mono">
            Modo Auditor Local
          </span>
        </div>
      </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-6 py-8 space-y-8">
      <!-- Notification Alerts -->
      <div v-if="successMessage"
        class="p-4 rounded-lg bg-emerald-950/80 border border-emerald-700 text-emerald-200 text-sm flex items-center justify-between">
        <span>✓ {{ successMessage }}</span>
        <button type="button" @click="successMessage = null" class="text-emerald-400 hover:text-white">✕</button>
      </div>

      <div v-if="error"
        class="p-4 rounded-lg bg-rose-950/80 border border-rose-700 text-rose-200 text-sm flex items-center justify-between">
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
                <span class="text-slate-400">Proceso en pantalla:</span>
                <span :class="activeProcess.status === 'running' ? 'text-amber-400 font-bold font-mono animate-pulse' : 'text-emerald-400 font-mono'">
                  {{ activeProcess.status === 'running' ? '◐ ' + activeProcess.title : '● En reposo (Idle)' }}
                </span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-400">Última operación:</span>
                <span class="font-mono text-slate-300 truncate max-w-[170px]" :title="activeProcess.lastAction + ' (' + activeProcess.lastActionTime + ')'">
                  {{ activeProcess.lastAction }}
                </span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-400">Memoria autobiográfica:</span>
                <span class="font-mono text-slate-300">Persistida en PostgreSQL</span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-400">Bucles autónomos:</span>
                <span class="font-mono text-rose-400">Inactivos (on-demand)</span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-400">Creado en:</span>
                <span class="font-mono text-slate-400">{{ agent?.created_at ? new
                  Date(agent.created_at).toLocaleString() : '—' }}</span>
              </div>
            </div>

            <!-- Función & Ejemplo: Identidad -->
            <div class="mt-4 p-3 rounded-lg bg-slate-950/80 border border-slate-800 space-y-2 text-xs">
              <div>
                <span class="text-[10px] uppercase font-mono font-bold text-cyan-400 block">¿Qué hace esta
                  función?</span>
                <p class="text-slate-300 text-[11px] leading-relaxed">
                  Mantiene la identidad persistente de EXILIO-001 entre sesiones. Garantiza que la personalidad y la
                  memoria autobiográfica comiencen estrictamente vacías, evolucionando únicamente a partir de
                  experiencias registradas.
                </p>
              </div>
              <div class="pt-2 border-t border-slate-800/80">
                <span class="text-[10px] uppercase font-mono font-bold text-slate-400 block">Ejemplo observable:</span>
                <p
                  class="text-[11px] text-slate-300 font-mono bg-slate-900/90 p-1.5 rounded mt-1 border border-slate-800">
                  EXILIO-001 | v{{ agent?.personality_version || 1 }}.0 | personality: {{
                    Object.keys(agent?.attributes?.personality || {}).length === 0 ? '{}' : '{...}' }} |
                  autobiographical_memory: []
                </p>
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
              <code
                class="text-xs font-mono bg-slate-800 text-cyan-300 px-2 py-0.5 rounded">C = 0.30N + 0.25I + 0.30A + 0.15R</code>
            </h3>
            <p class="text-xs text-slate-400 mb-4">
              Mecanismo puro de motivación inicial. La relevancia adquirida se fundamenta exclusivamente en evidencias
              registradas sin precargas intrínsecas.
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

            <!-- Función & Ejemplo: Curiosidad -->
            <div class="p-3 rounded-lg bg-slate-950/80 border border-slate-800 space-y-2 text-xs">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <span class="text-[10px] uppercase font-mono font-bold text-cyan-400 block">¿Qué hace esta
                    función?</span>
                  <p class="text-slate-300 text-[11px] leading-relaxed">
                    Evalúa el impulso exploratorio del agente combinando 4 señales en [0, 1]. Prioriza qué indagar sin
                    sesgos arbitrarios: mayor novedad, incertidumbre epistemológica o relevancia registrada producen
                    mayor curiosidad (C).
                  </p>
                </div>
                <div>
                  <span class="text-[10px] uppercase font-mono font-bold text-slate-400 block">Ejemplo de cálculo
                    observable:</span>
                  <p class="text-slate-300 text-[11px]">
                    Tema con <code class="text-cyan-300 font-mono">N=0.85, I=0.80, A=0.75, R=0.15</code>:
                  </p>
                  <p class="text-slate-400 text-[10px] font-mono mt-0.5">
                    C = (0.30×0.85) + (0.25×0.80) + (0.30×0.75) + (0.15×0.15) = <strong
                      class="text-emerald-400">0.7025</strong> (alta prioridad de investigación).
                  </p>
                </div>
              </div>
            </div>
          </div>

          <div
            class="p-3 rounded-lg bg-slate-950/60 border border-slate-800/60 flex flex-wrap items-center justify-between text-xs text-slate-400 gap-2">
            <span>Proveedor activo: <strong class="text-cyan-300 font-mono">Gemini 2.5 Flash / Introspección
                Local</strong></span>
            <span>
              Presupuesto ref.: <strong class="text-slate-200 font-mono">US$5.00/mes</strong>
              <span v-if="chatTelemetry" class="text-emerald-400 ml-1">
                (Gastado: ${{ Number(chatTelemetry.month_cost_usd || 0).toFixed(4) }} | Restante: ${{
                  Number(chatTelemetry.remaining_budget_usd || 5.0).toFixed(4) }})
              </span>
            </span>
            <span>Bucles autónomos: <strong class="text-rose-400 font-mono">Deshabilitados</strong></span>
          </div>
        </div>
      </section>

      <!-- Consola de Diálogo Introspectivo con EXILIO-001 (Fase 4 - Gemini) -->
      <section class="space-y-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800 pb-4">
          <div>
            <h2 class="text-xl font-bold text-white flex items-center gap-3">
              Consola de Diálogo Introspectivo con EXILIO-001
              <span
                class="text-xs font-mono bg-cyan-950 text-cyan-300 px-2.5 py-0.5 rounded-full border border-cyan-800 flex items-center gap-1.5">
                <span class="h-1.5 w-1.5 rounded-full bg-cyan-400 animate-ping"></span>
                Gemini 2.5 Flash / Anclaje Epistémico
              </span>
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">
              Diálogo directo con el agente fundamentado exclusivamente en sus datos computacionales reales en
              PostgreSQL (personalidad, intereses, versiones y memoria autobiográfica).
            </p>
          </div>

          <!-- Telemetría de Presupuesto y Tokens -->
          <div class="flex flex-wrap items-center gap-2 text-xs font-mono">
            <div
              class="px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-800 text-slate-300 flex items-center gap-2">
              <span class="text-slate-400">Tokens Mes:</span>
              <span class="text-cyan-300 font-bold">{{ chatTelemetry?.total_tokens ?? chatTelemetry?.month_tokens ?? 0
                }}</span>
            </div>
            <div
              class="px-3 py-1.5 rounded-lg bg-slate-900 border border-slate-800 text-slate-300 flex items-center gap-2">
              <span class="text-slate-400">Gasto:</span>
              <span class="text-emerald-400 font-bold">${{ Number(chatTelemetry?.current_month_spend_usd ??
                chatTelemetry?.month_cost_usd ?? 0).toFixed(4) }}</span>
              <span class="text-slate-500">/ ${{ Number(chatTelemetry?.monthly_budget_usd || 5.0).toFixed(2) }}</span>
            </div>
          </div>
        </div>

        <!-- Función & Ejemplos: Consola de Diálogo Introspectivo -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-xs space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-xs font-mono font-bold text-cyan-300 uppercase tracking-wider flex items-center gap-2">
              <span>💬</span> ¿Qué hace el Diálogo Introspectivo con IA?
            </span>
            <span class="text-[11px] font-mono text-slate-400">Sin alucinación ontológica | Persistencia
              autobiográfica</span>
          </div>
          <p class="text-slate-300 leading-relaxed text-xs">
            Conecta un modelo LLM (Gemini 2.5 Flash) con el motor cognitivo de EXILIO-001 inyectando su estado
            computacional actual en el system instruction. Cada interacción del usuario se persiste automáticamente en
            PostgreSQL como memoria episódica (<code
              class="text-emerald-300 font-mono">type: episodic, epistemic_status: fact, origin: user_input</code>).
            EXILIO responde como un sistema computacional sin afirmar conciencia biológica ni sentimientos subjetivos.
          </p>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2 border-t border-slate-800/80 text-[11px]">
            <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800/80 space-y-1">
              <span class="font-mono font-bold text-cyan-400 block">Anclaje en Datos Reales:</span>
              <p class="text-slate-400">El agente solo conoce lo que está registrado en su base de datos: versión
                actual, rasgos, relevancia R y memorias vigentes.</p>
              <div class="text-[10px] font-mono text-slate-500 pt-1 border-t border-slate-900">
                <strong class="text-slate-400">Ejemplo:</strong> <em>"¿Qué rasgos tienes?"</em> &rarr; Reporta sus
                rasgos exactos en PostgreSQL con sus evidencias trazables.
              </div>
            </div>

            <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800/80 space-y-1">
              <span class="font-mono font-bold text-amber-400 block">Separación Epistémica:</span>
              <p class="text-slate-400">Distingue hechos observados de inferencias tentativas e ideas oníricas; nunca
                trata los sueños como realidad vivida.</p>
              <div class="text-[10px] font-mono text-slate-500 pt-1 border-t border-slate-900">
                <strong class="text-slate-400">Ejemplo:</strong> <em>"¿Qué soñaste?"</em> &rarr; Relata la memoria
                onírica aclarando que es una simulación interna sin valor de hecho.
              </div>
            </div>

            <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800/80 space-y-1">
              <span class="font-mono font-bold text-emerald-400 block">Control de Presupuesto:</span>
              <p class="text-slate-400">Límite duro de US$5.00/mes. Si se agota o si no hay API key configurada, conmuta
                de inmediato a fallback local.</p>
              <div class="text-[10px] font-mono text-slate-500 pt-1 border-t border-slate-900">
                <strong class="text-slate-400">Ejemplo:</strong> Telemetría en tiempo real registra tokens y costo
                estimado por cada intercambio.
              </div>
            </div>
          </div>
        </div>

        <!-- Chat Console Box -->
        <div class="rounded-xl bg-slate-900 border border-slate-800 overflow-hidden flex flex-col">
          <!-- Quick Prompt Chips -->
          <div class="p-3 bg-slate-950/80 border-b border-slate-800 flex flex-wrap items-center gap-2">
            <span class="text-[11px] font-mono text-slate-400 mr-1">Preguntas sugeridas:</span>
            <button
              type="button"
              @click="runInquiryCycle()"
              :disabled="exploringInquiry"
              class="px-2.5 py-1 rounded-full text-xs bg-cyan-950 hover:bg-cyan-900 text-cyan-200 border border-cyan-700 transition disabled:opacity-50 font-bold flex items-center gap-1.5 shadow-sm"
              title="Gatilla un ciclo de exploración cognitiva activo sobre el tema con mayor curiosidad"
            >
              <span v-if="exploringInquiry" class="animate-spin">◐</span>
              <span v-else>⚡</span>
              <span>Indagar sobre tema de mayor curiosidad ({{ topInterest?.topic || 'C=' + (topInterest?.curiosity_score || 0.43) }})</span>
            </button>
            <button type="button" @click="sendChat('¿Cuál es tu estado actual y qué rasgos tienes en tu modelo?')"
              :disabled="sendingChat"
              class="px-2.5 py-1 rounded-full text-xs bg-slate-900 hover:bg-slate-800 text-cyan-300 border border-cyan-800/60 transition disabled:opacity-50">
              ¿Cuál es tu estado actual y tus rasgos?
            </button>
            <button type="button"
              @click="sendChat('¿En qué versión de evolución estás y cómo ha sido tu historial de mejoras?')"
              :disabled="sendingChat"
              class="px-2.5 py-1 rounded-full text-xs bg-slate-900 hover:bg-slate-800 text-indigo-300 border border-indigo-800/60 transition disabled:opacity-50">
              ¿En qué versión estás y cómo has evolucionado?
            </button>
            <button type="button"
              @click="sendChat('¿Qué te da curiosidad ahora mismo y qué señales cognitivas tienes activas?')"
              :disabled="sendingChat"
              class="px-2.5 py-1 rounded-full text-xs bg-slate-900 hover:bg-slate-800 text-emerald-300 border border-emerald-800/60 transition disabled:opacity-50">
              ¿Qué te da curiosidad y cómo calculas tu C?
            </button>
            <button type="button" @click="sendChat('¿Tienes hipótesis o recuerdos que quieras explorar o verificar?')"
              :disabled="sendingChat"
              class="px-2.5 py-1 rounded-full text-xs bg-slate-900 hover:bg-slate-800 text-amber-300 border border-amber-800/60 transition disabled:opacity-50">
              ¿Tienes hipótesis o recuerdos que explorar?
            </button>
          </div>

          <!-- Messages Scroll Area -->
          <div ref="chatBoxRef" class="p-4 space-y-4 min-h-[280px] max-h-[460px] overflow-y-auto bg-slate-950/40">
            <!-- Empty state -->
            <div v-if="chatMessages.length === 0"
              class="py-12 text-center space-y-2 border border-dashed border-slate-800 rounded-lg mx-auto max-w-lg">
              <div class="text-2xl">🤖</div>
              <p class="text-sm font-semibold text-slate-300">Inicia una conversación con EXILIO-001</p>
              <p class="text-xs text-slate-400 max-w-md mx-auto px-4">
                Puedes preguntarle sobre lo que ha aprendido, sus rasgos consolidados, sus intereses oníricos o su
                estado actual. Haz clic en una pregunta sugerida o escribe tu propio mensaje abajo.
              </p>
            </div>

            <!-- Messages list -->
            <div v-for="msg in chatMessages" :key="msg.id" :class="[
              'flex flex-col',
              msg.role === 'user' ? 'items-end' : 'items-start'
            ]">
              <!-- Role Badge -->
              <div class="flex items-center gap-2 mb-1 px-1 text-[11px] font-mono text-slate-400">
                <span v-if="msg.role === 'user'" class="text-cyan-400 font-bold">Cristian (Auditor)</span>
                <span v-else class="text-indigo-300 font-bold flex items-center gap-1.5">
                  <span class="h-1.5 w-1.5 rounded-full bg-indigo-400"></span>
                  EXILIO-001
                </span>
                <span>{{ msg.created_at ? new Date(msg.created_at).toLocaleTimeString() : '' }}</span>
              </div>

              <!-- Message Bubble -->
              <div :class="[
                'max-w-2xl rounded-2xl px-4 py-3 text-sm leading-relaxed shadow',
                msg.role === 'user'
                  ? 'bg-cyan-950/80 border border-cyan-800 text-cyan-100 rounded-tr-none'
                  : 'bg-slate-900 border border-slate-800 text-slate-100 rounded-tl-none'
              ]">
                <p class="whitespace-pre-line">{{ msg.content }}</p>

                <!-- Grounding Metadata Badge for Agent Responses -->
                <div
                  v-if="(msg.role === 'agent' || msg.role === 'assistant') && (msg.grounding_snapshot || msg.grounding_metadata)"
                  class="mt-3 pt-2.5 border-t border-slate-800/80 flex flex-wrap items-center gap-2 text-[10px] font-mono text-slate-400">
                  <span class="px-1.5 py-0.5 rounded bg-slate-950 border border-slate-800 text-slate-300">
                    Proveedor: {{ msg.provider || (msg.grounding_snapshot || msg.grounding_metadata)?.provider ||
                      'gemini-2.5-flash' }}
                  </span>
                  <span class="px-1.5 py-0.5 rounded bg-slate-950 border border-slate-800 text-cyan-300">
                    Versión base: v{{ (msg.grounding_snapshot || msg.grounding_metadata)?.personality_version ||
                      (msg.grounding_snapshot || msg.grounding_metadata)?.agent_version || 1 }}.0
                  </span>
                  <span class="px-1.5 py-0.5 rounded bg-slate-950 border border-slate-800 text-emerald-300">
                    Memorias activas: {{ (msg.grounding_snapshot || msg.grounding_metadata)?.active_memories_count || 0
                    }}
                  </span>
                  <span v-if="msg.tokens_used || (msg.grounding_snapshot || msg.grounding_metadata)?.tokens_total"
                    class="px-1.5 py-0.5 rounded bg-slate-950 border border-slate-800 text-slate-300">
                    Tokens: {{ msg.tokens_used || (msg.grounding_snapshot || msg.grounding_metadata)?.tokens_total }}
                    (${{ Number(msg.cost_usd || (msg.grounding_snapshot || msg.grounding_metadata)?.cost_usd ||
                      0).toFixed(5) }})
                  </span>
                </div>
              </div>
            </div>

            <!-- Loading indicator when assistant is replying -->
            <div v-if="sendingChat" class="flex flex-col items-start">
              <div class="flex items-center gap-2 mb-1 px-1 text-[11px] font-mono text-slate-400">
                <span class="text-indigo-300 font-bold flex items-center gap-1.5">
                  <span class="h-1.5 w-1.5 rounded-full bg-indigo-400 animate-ping"></span>
                  EXILIO-001 procesando introspección...
                </span>
              </div>
              <div
                class="bg-slate-900 border border-slate-800 text-slate-400 px-4 py-3 rounded-2xl rounded-tl-none text-xs font-mono flex items-center gap-2">
                <span class="animate-spin text-cyan-400">◐</span> Consultando memoria autobiográfica y generando
                respuesta anclada...
              </div>
            </div>
          </div>

          <!-- Chat Input Bar -->
          <form @submit.prevent="sendChat()" class="p-3 bg-slate-900 border-t border-slate-800 flex items-center gap-3">
            <input type="text" v-model="chatInput" :disabled="sendingChat"
              placeholder="Pregúntale a EXILIO-001 sobre su estado, memoria, versión o curiosidad..."
              class="flex-1 text-xs px-4 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-cyan-500 disabled:opacity-50" />
            <button type="submit" :disabled="!chatInput.trim() || sendingChat"
              class="px-5 py-2.5 rounded-xl text-xs font-semibold bg-cyan-600 hover:bg-cyan-500 disabled:opacity-40 text-white shadow-sm transition flex items-center gap-2 font-mono shrink-0">
              <span v-if="sendingChat" class="animate-spin">◐</span>
              <span>{{ sendingChat ? 'Enviando...' : 'Enviar Diálogo' }}</span>
            </button>
          </form>
        </div>
      </section>

      <!-- Controlled Learning & Versioned Evolution Section (Fase 3) -->
      <section class="space-y-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800 pb-4">
          <div>
            <h2 class="text-xl font-bold text-white flex items-center gap-3">
              Aprendizaje Controlado & Evolución Versionada
              <span
                class="text-xs font-mono bg-cyan-950 text-cyan-300 px-2.5 py-0.5 rounded-full border border-cyan-800">
                v{{ agent?.personality_version || 1 }}.0 ({{ versions.length }} snapshots)
              </span>
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">
              Hechos consolidan rasgos y R (&le; 1.0); hipótesis ajustan tentativamente (R &le; 0.60); sueños guían
              semillas de curiosidad (R &le; 0.25) sin alterar rasgos
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-3">
            <button type="button" @click="openVersionsModal"
              class="px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-900 hover:bg-slate-800 text-slate-300 border border-slate-700 transition font-mono">
              Historial de Versiones / Revertir
            </button>
            <button type="button" @click="openLearnModal"
              class="px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-indigo-600 hover:bg-indigo-500 text-white shadow-sm transition flex items-center gap-1.5">
              <span>⚡</span> Ejecutar Aprendizaje Controlado
            </button>
          </div>
        </div>

        <!-- Función & Ejemplos: Aprendizaje Controlado (Fase 3) -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-xs space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-xs font-mono font-bold text-indigo-300 uppercase tracking-wider flex items-center gap-2">
              <span>⚡</span> ¿Qué hace el Aprendizaje Controlado?
            </span>
            <span class="text-[11px] font-mono text-slate-400">Sin mutación in-place | Reversión auditable</span>
          </div>
          <p class="text-slate-300 leading-relaxed text-xs">
            Evalúa evidencias de memorias vigentes (<code class="text-cyan-300 font-mono">superseded_at IS NULL</code>)
            bajo demanda para actualizar rasgos e intereses. Incrementa la versión (<code
              class="text-cyan-300 font-mono">v{{ agent?.personality_version || 1 }}.0 &rarr; v{{ (agent?.personality_version || 1) + 1 }}.0</code>)
            y persiste un snapshot inmutable en <code class="text-slate-300 font-mono">agent_learning_versions</code>.
            Aplica una estricta diferenciación epistémica en 3 niveles:
          </p>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2 border-t border-slate-800/80 text-[11px]">
            <div class="p-2.5 rounded-lg bg-slate-950 border border-emerald-900/50 space-y-1">
              <span class="font-mono font-bold text-emerald-400 block">1. Hechos (fact) &rarr; Consolidación</span>
              <p class="text-slate-400">Consolida rasgos de personalidad y relevancia adquirida <code
                  class="text-emerald-300">R &le; 1.0</code>.</p>
              <div class="text-[10px] font-mono text-slate-500 pt-1 border-t border-slate-900">
                <strong class="text-slate-400">Ejemplo:</strong> Hecho de sesión analítica consolida rasgo <code
                  class="text-emerald-300">analytical_rigor = 0.80</code> e interés <code
                  class="text-emerald-300">teoría de grafos (R=0.25)</code>.
              </div>
            </div>

            <div class="p-2.5 rounded-lg bg-slate-950 border border-amber-900/50 space-y-1">
              <span class="font-mono font-bold text-amber-400 block">2. Hipótesis (hypothesis) &rarr; Tentativo</span>
              <p class="text-slate-400">Ajusta rasgos ponderados por certeza. Relevancia acotada a <code
                  class="text-amber-300">R &le; 0.60</code>.</p>
              <div class="text-[10px] font-mono text-slate-500 pt-1 border-t border-slate-900">
                <strong class="text-slate-400">Ejemplo:</strong> Inferencia con confianza 50% ajusta tentativamente
                <code class="text-amber-300">conciseness = 0.40</code> e interés <code
                  class="text-amber-300">síntesis (R=0.10)</code>.
              </div>
            </div>

            <div class="p-2.5 rounded-lg bg-slate-950 border border-purple-900/50 space-y-1">
              <span class="font-mono font-bold text-purple-400 block">3. Sueños (dream) &rarr; Curiosidad</span>
              <p class="text-slate-400">No altera rasgos reales; siembra intereses con pregunta guiada y <code
                  class="text-purple-300">R &le; 0.25</code>.</p>
              <div class="text-[10px] font-mono text-slate-500 pt-1 border-t border-slate-900">
                <strong class="text-slate-400">Ejemplo:</strong> Sueño de perro en playa siembra interés <code
                  class="text-purple-300">playas para perros (R=0.15)</code> e indaga <code
                  class="text-purple-300">"Averiguar cuál es la mejor playa..."</code>.
              </div>
            </div>
          </div>
        </div>

        <!-- Stale Evidence Alert Banner -->
        <div v-if="agent?.evidence_audit?.has_stale_evidence"
          class="p-4 rounded-xl bg-amber-950/60 border border-amber-700 text-amber-200 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="space-y-1">
            <div class="font-bold font-mono text-amber-300">
              ⚠ Alerta de Auditoría Epistémica: Evidencia Rectificada o Purgada
            </div>
            <p class="text-amber-200/90">
              Uno o más rasgos o intereses de la versión actual (v{{ agent?.personality_version }}.0) referencian
              memorias que fueron posteriormente rectificadas ({{ (agent.evidence_audit.superseded_memory_ids ||
                []).length }}) o purgadas ({{ (agent.evidence_audit.purged_memory_ids || []).length }}). Se recomienda
              reevaluar o revertir a una versión previa.
            </p>
          </div>
          <button type="button" @click="openVersionsModal"
            class="px-3 py-1.5 rounded-lg bg-amber-900 hover:bg-amber-800 text-amber-100 border border-amber-700 font-mono shrink-0 transition">
            Revisar / Revertir Versión
          </button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Personality Traits Panel -->
          <div class="rounded-xl bg-slate-900 border border-slate-800 p-5 space-y-4">
            <div class="flex items-center justify-between">
              <h3 class="text-sm font-bold font-mono text-white uppercase tracking-wider">Rasgos de Personalidad</h3>
              <span class="text-xs font-mono text-slate-400">{{ personalityList.length }} registrados</span>
            </div>

            <div v-if="personalityList.length === 0"
              class="rounded-lg border border-dashed border-slate-800 p-6 text-center text-xs text-slate-500 font-mono">
              Sin rasgos adquiridos. La personalidad comienza vacía y solo evoluciona con hechos o hipótesis
              verificables.
            </div>

            <div v-else class="space-y-3">
              <div v-for="trait in personalityList" :key="trait.key" :class="[
                'p-3.5 rounded-lg bg-slate-950 border space-y-2',
                hasStaleEvidenceInList(trait.evidence_memory_ids) ? 'border-amber-700/80' : 'border-slate-800'
              ]">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-xs font-mono font-bold text-slate-200">{{ trait.key }}</span>
                  <div class="flex items-center gap-2">
                    <span v-if="hasStaleEvidenceInList(trait.evidence_memory_ids)"
                      class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-rose-950 text-rose-300 border border-rose-800">
                      ⚠ Evidencia obsoleta/purgada
                    </span>
                    <span :class="[
                      'px-2 py-0.5 rounded text-[10px] font-mono font-semibold border',
                      trait.consolidation_status === 'consolidated'
                        ? 'bg-emerald-950 text-emerald-300 border-emerald-800'
                        : 'bg-amber-950 text-amber-300 border-amber-800'
                    ]">
                      {{ trait.consolidation_status === 'consolidated' ? 'CONSOLIDADO (Fact)' : 'TENTATIVO (Hypothesis)'
                      }}
                    </span>
                    <span class="text-xs font-mono font-bold text-cyan-400">{{ Number(trait.value).toFixed(2) }}</span>
                  </div>
                </div>
                <div class="w-full h-1.5 rounded-full bg-slate-800 overflow-hidden">
                  <div class="h-full bg-cyan-500"
                    :style="{ width: `${Math.min(100, Math.round(trait.value * 100))}%` }"></div>
                </div>
                <div class="text-[11px] font-mono text-slate-500 flex flex-wrap items-center gap-1.5">
                  <span>Evidencia ({{ (trait.evidence_memory_ids || []).length }}):</span>
                  <span v-for="evId in (trait.evidence_memory_ids || [])" :key="evId" :class="[
                    'px-1.5 py-0.5 rounded border text-[10px]',
                    getEvidenceStatus(evId) === 'active'
                      ? 'bg-slate-900 border-slate-800 text-slate-300'
                      : getEvidenceStatus(evId) === 'superseded'
                        ? 'bg-amber-950/70 border-amber-800 text-amber-300'
                        : 'bg-rose-950/70 border-rose-800 text-rose-300'
                  ]">
                    {{ evId.substring(0, 8) }}...
                    <span v-if="getEvidenceStatus(evId) === 'superseded'">(rectificada)</span>
                    <span v-else-if="getEvidenceStatus(evId) === 'purged'">(purgada)</span>
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- Acquired Interests & Oneiric Curiosity Seeds Panel -->
          <div class="rounded-xl bg-slate-900 border border-slate-800 p-5 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h3 class="text-sm font-bold font-mono text-white uppercase tracking-wider">Intereses, Relevancia (R) &
                Semillas de Curiosidad</h3>
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  @click="runInquiryCycle()"
                  :disabled="exploringInquiry"
                  class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 text-white shadow-sm transition flex items-center gap-1.5 font-mono"
                  title="Ejecuta un ciclo donde EXILIO delibera sobre su mayor curiosidad y formula una hipótesis con Gemini"
                >
                  <span v-if="exploringInquiry" class="animate-spin">◐</span>
                  <span v-else>🔍</span>
                  <span>{{ exploringInquiry ? 'Indagando...' : 'Iniciar Indagación' }}</span>
                </button>
                <span class="text-xs font-mono text-slate-400">{{ interestsList.length }} registrados</span>
              </div>
            </div>

            <div v-if="interestsList.length === 0"
              class="rounded-lg border border-dashed border-slate-800 p-6 text-center text-xs text-slate-500 font-mono">
              Sin relevancia adquirida (R = 0). Los sueños pueden sembrar preguntas exploratorias; los hechos e
              hipótesis consolidan intereses.
            </div>

            <div v-else class="space-y-3">
              <div v-for="item in interestsList" :key="item.topic" :class="[
                'p-3.5 rounded-lg bg-slate-950 border space-y-2',
                hasStaleEvidenceInList(item.evidence_memory_ids) ? 'border-amber-700/80' : 'border-slate-800'
              ]">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span class="text-xs font-mono font-bold text-slate-100">{{ item.topic }}</span>
                  <div class="flex items-center gap-2">
                    <span v-if="hasStaleEvidenceInList(item.evidence_memory_ids)"
                      class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-rose-950 text-rose-300 border border-rose-800">
                      ⚠ Evidencia obsoleta/purgada
                    </span>
                    <span :class="[
                      'px-2 py-0.5 rounded text-[10px] font-mono font-semibold border',
                      item.consolidation_status === 'consolidated'
                        ? 'bg-emerald-950 text-emerald-300 border-emerald-800'
                        : item.consolidation_status === 'tentative'
                          ? 'bg-amber-950 text-amber-300 border-amber-800'
                          : 'bg-purple-950 text-purple-300 border-purple-800'
                    ]">
                      {{
                        item.consolidation_status === 'consolidated'
                          ? 'CONSOLIDADO (Fact)'
                          : item.consolidation_status === 'tentative'
                            ? 'TENTATIVO (Hypothesis)'
                            : 'SEMILLA ONÍRICA (Dream)'
                      }}
                    </span>
                    <span class="text-xs font-mono text-indigo-300">
                      R={{ Number(item.acquired_relevance).toFixed(2) }}
                    </span>
                    <span class="text-xs font-mono font-bold text-cyan-300">
                      C={{ Number(item.curiosity_score).toFixed(2) }}
                    </span>
                    <button
                      type="button"
                      @click="runInquiryCycle(item.topic)"
                      :disabled="exploringInquiry"
                      class="px-2 py-0.5 rounded text-[10px] font-mono bg-cyan-950/80 hover:bg-cyan-900 text-cyan-300 border border-cyan-800 transition disabled:opacity-50"
                      title="Indagar específicamente sobre este tema"
                    >
                      Indagar
                    </button>
                  </div>
                </div>

                <div v-if="item.exploratory_query"
                  class="p-2 rounded bg-slate-900 border border-slate-800 text-xs text-slate-300 font-sans">
                  <span class="font-mono text-[10px] uppercase text-purple-300 mr-1.5">Indagación guiada:</span>
                  <span>{{ item.exploratory_query }}</span>
                </div>

                <div class="text-[11px] font-mono text-slate-500 flex flex-wrap items-center gap-1.5">
                  <span>Evidencia ({{ (item.evidence_memory_ids || []).length }}):</span>
                  <span v-for="evId in (item.evidence_memory_ids || [])" :key="evId" :class="[
                    'px-1.5 py-0.5 rounded border text-[10px]',
                    getEvidenceStatus(evId) === 'active'
                      ? 'bg-slate-900 border-slate-800 text-slate-300'
                      : getEvidenceStatus(evId) === 'superseded'
                        ? 'bg-amber-950/70 border-amber-800 text-amber-300'
                        : 'bg-rose-950/70 border-rose-800 text-rose-300'
                  ]">
                    {{ evId.substring(0, 8) }}...
                    <span v-if="getEvidenceStatus(evId) === 'superseded'">(rectificada)</span>
                    <span v-else-if="getEvidenceStatus(evId) === 'purged'">(purgada)</span>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Memory Audit & Inspector Section -->
      <section class="space-y-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800 pb-4">
          <div>
            <h2 class="text-xl font-bold text-white flex items-center gap-3">
              Registros de Memoria Persistida
              <span
                class="text-xs font-mono bg-slate-800 text-slate-300 px-2.5 py-0.5 rounded-full border border-slate-700">
                {{ memories.length }} entradas
              </span>
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">Auditoría ontológica con separación estricta de hechos, hipótesis y
              simulaciones oníricas</p>
          </div>

          <div class="flex flex-wrap items-center gap-3">
            <button type="button" @click="openTombstonesModal"
              class="px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-900 hover:bg-slate-800 text-slate-300 border border-slate-700 transition">
              Auditoría de Purgas (Tombstones)
            </button>
            <button type="button" @click="openCreateModal"
              class="px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-cyan-600 hover:bg-cyan-500 text-white shadow-sm transition flex items-center gap-1.5">
              <span>+</span> Nueva Entrada Manual
            </button>
            <button type="button" @click="fetchMemories" title="Recargar registros"
              class="p-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-400 hover:text-white border border-slate-800 transition">
              ↻
            </button>
          </div>
        </div>

        <!-- Función & Ejemplos: Modelo de Memorias (Fase 1 & 2) -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-xs space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="text-xs font-mono font-bold text-cyan-300 uppercase tracking-wider flex items-center gap-2">
              <span>📦</span> ¿Qué hace el Sistema de Memoria Persistida?
            </span>
            <span class="text-[11px] font-mono text-slate-400">Taxonomía de 5 categorías | Separación de Certeza
              Epistémica</span>
          </div>
          <p class="text-slate-300 leading-relaxed text-xs">
            Almacena experiencias estructuradas sin mutación in-place. La corrección crea nuevas versiones referenciando
            <code class="text-cyan-300 font-mono">supersedes_id</code> (marcando la previa con <code
              class="text-slate-400 font-mono">superseded_at</code>), y la eliminación borra físicamente el texto
            sensible dejando un registro auditable en <code class="text-rose-400 font-mono">memory_tombstones</code>.
          </p>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2 border-t border-slate-800/80 text-[11px]">
            <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800/80 space-y-1">
              <span class="font-mono font-bold text-slate-200 block">5 Tipos Conceptuales:</span>
              <p class="text-slate-400">Episódica (hechos situados), Semántica (conceptos), Social (relaciones),
                Procedimental (métodos) y Onírica (simulaciones internas).</p>
              <div class="text-[10px] font-mono text-slate-500 pt-1 border-t border-slate-900">
                <strong class="text-slate-400">Ejemplo:</strong> Episódica + Hecho = <code
                  class="text-cyan-300">"El usuario saludó e inició sesión"</code> (origen: user_input).
              </div>
            </div>

            <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800/80 space-y-1">
              <span class="font-mono font-bold text-slate-200 block">3 Estados de Certeza:</span>
              <p class="text-slate-400">Hecho (<code class="text-emerald-400">fact</code>, observable, sin confianza),
                Hipótesis (<code class="text-amber-400">hypothesis</code>, confianza obligatoria) y Sueño (<code
                  class="text-purple-400">dream</code>, sin certeza de hecho).</p>
              <div class="text-[10px] font-mono text-slate-500 pt-1 border-t border-slate-900">
                <strong class="text-slate-400">Ejemplo:</strong> Hipótesis = <code
                  class="text-amber-300">"Preferencia por respuestas concisas"</code> (certeza: 75%).
              </div>
            </div>

            <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800/80 space-y-1">
              <span class="font-mono font-bold text-slate-200 block">Rectificación & Purgas:</span>
              <p class="text-slate-400">Rectificar crea una versión corregida sin sobrescribir. Purgar elimina el texto
                definitivamente dejando constancia en tombstones.</p>
              <div class="text-[10px] font-mono text-slate-500 pt-1 border-t border-slate-900">
                <strong class="text-slate-400">Ejemplo:</strong> Purgar memoria genera tombstone con motivo <code
                  class="text-rose-300">"Solicitud de olvido"</code>.
              </div>
            </div>
          </div>
        </div>

        <!-- Filter Controls -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-4">
          <div class="flex flex-wrap gap-6 items-center">
            <!-- Taxonomy filter -->
            <div class="flex items-center gap-2">
              <span class="text-xs font-mono text-slate-400">Categoría:</span>
              <div class="inline-flex rounded-lg bg-slate-950 p-1 border border-slate-800 text-xs">
                <button type="button" v-for="cat in ['all', 'episodic', 'semantic', 'social', 'procedural', 'oneiric']"
                  :key="cat" @click="selectedType = cat; fetchMemories()" :class="[
                    'px-2.5 py-1 rounded capitalize transition font-mono',
                    selectedType === cat ? 'bg-slate-800 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200'
                  ]">
                  {{ cat === 'all' ? 'Todas' : cat }}
                </button>
              </div>
            </div>

            <!-- Epistemic Status filter -->
            <div class="flex items-center gap-2">
              <span class="text-xs font-mono text-slate-400">Estado Epistémico:</span>
              <div class="inline-flex rounded-lg bg-slate-950 p-1 border border-slate-800 text-xs">
                <button type="button" v-for="st in ['all', 'fact', 'hypothesis', 'dream']" :key="st"
                  @click="selectedEpistemicStatus = st; fetchMemories()" :class="[
                    'px-2.5 py-1 rounded capitalize transition font-mono',
                    selectedEpistemicStatus === st ? 'bg-slate-800 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200'
                  ]">
                  {{ st === 'all' ? 'Todos' : (st === 'fact' ? 'Hecho' : (st === 'hypothesis' ? 'Hipótesis' : 'Sueño'))
                  }}
                </button>
              </div>
            </div>

            <!-- Include superseded toggle -->
            <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer select-none">
              <input type="checkbox" v-model="includeSuperseded" @change="fetchMemories"
                class="rounded bg-slate-950 border-slate-700 text-cyan-600 focus:ring-0" />
              <span>Incluir obsoletas / rectificadas</span>
            </label>
          </div>
        </div>

        <!-- Memories List -->
        <div v-if="loading" class="text-center py-16 text-slate-500 font-mono text-sm">
          Consultando registros de persistencia...
        </div>

        <div v-else-if="memories.length === 0"
          class="rounded-xl border border-dashed border-slate-800 p-12 text-center space-y-3">
          <div class="text-3xl text-slate-600">∅</div>
          <h4 class="text-sm font-semibold text-slate-300">No hay registros de memoria con los filtros seleccionados
          </h4>
          <p class="text-xs text-slate-500 max-w-md mx-auto">
            La memoria autobiográfica inicial se encuentra vacía de acuerdo a los principios ontológicos de EXILIO-001.
            Puedes registrar una entrada manual para pruebas.
          </p>
          <button type="button" @click="openCreateModal"
            class="mt-2 px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-800 hover:bg-slate-700 text-cyan-300 border border-slate-700 transition inline-block">
            Registrar primera entrada
          </button>
        </div>

        <div v-else class="space-y-3">
          <article v-for="m in memories" :key="m.id" :class="[
            'rounded-xl border p-5 transition bg-slate-900/90 relative group',
            m.superseded_at ? 'border-slate-800/60 opacity-60 bg-slate-950/40' : 'border-slate-800 hover:border-slate-700',
            m.epistemic_status === 'fact' ? 'border-l-4 border-l-emerald-500' :
              (m.epistemic_status === 'hypothesis' ? 'border-l-4 border-l-amber-500' : 'border-l-4 border-l-purple-500')
          ]">
            <!-- Card Header -->
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
              <div class="flex flex-wrap items-center gap-2">
                <!-- Epistemic Badge -->
                <span v-if="m.epistemic_status === 'fact'"
                  class="px-2 py-0.5 rounded text-[11px] font-bold font-mono bg-emerald-950 text-emerald-300 border border-emerald-800">
                  HECHO (Fact)
                </span>
                <span v-else-if="m.epistemic_status === 'hypothesis'"
                  class="px-2 py-0.5 rounded text-[11px] font-bold font-mono bg-amber-950 text-amber-300 border border-amber-800 flex items-center gap-1.5">
                  HIPÓTESIS
                  <span class="text-[10px] font-normal px-1 rounded bg-amber-900/50 text-amber-200">
                    Certeza: {{ (m.confidence * 100).toFixed(0) }}%
                  </span>
                </span>
                <span v-else-if="m.epistemic_status === 'dream'"
                  class="px-2 py-0.5 rounded text-[11px] font-bold font-mono bg-purple-950 text-purple-300 border border-purple-800">
                  SUEÑO / SIMULACIÓN
                </span>

                <!-- Taxonomy Category -->
                <span
                  class="px-2 py-0.5 rounded text-[11px] font-mono bg-slate-800 text-slate-300 border border-slate-700 capitalize">
                  {{ m.type }}
                </span>

                <!-- Origin Badge -->
                <span class="text-[11px] font-mono text-slate-400">
                  vía <span class="text-slate-300 font-semibold">{{ m.origin }}</span>
                </span>

                <!-- Evidence Ref -->
                <span v-if="m.evidence_ref"
                  class="text-[11px] font-mono text-cyan-400/80 bg-cyan-950/40 px-1.5 py-0.5 rounded border border-cyan-900/60">
                  ref: {{ m.evidence_ref }}
                </span>
              </div>

              <!-- Timestamps & Actions -->
              <div class="flex items-center gap-3 text-xs text-slate-400 font-mono">
                <span>{{ new Date(m.created_at).toLocaleString() }}</span>

                <div v-if="!m.superseded_at" class="flex items-center gap-1">
                  <button type="button" @click="openRectifyModal(m)"
                    class="px-2 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition text-xs"
                    title="Rectificar mediante nueva versión (no in-place)">
                    Rectificar
                  </button>
                  <button type="button" @click="openDeleteModal(m)"
                    class="px-2 py-1 rounded bg-rose-950/60 hover:bg-rose-900 text-rose-300 border border-rose-800/60 hover:text-white transition text-xs"
                    title="Purgar memoria permanentemente (genera tombstone)">
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
            <div
              class="flex flex-wrap items-center justify-between text-xs pt-2 border-t border-slate-800/80 text-slate-400 font-mono">
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
    <dialog ref="createModal" closedby="any" aria-labelledby="createModalTitle"
      @click="handleDialogClickFallback($event, createModal)"
      class="fixed inset-0 m-auto max-h-[90vh] overflow-y-auto rounded-2xl bg-slate-900 border border-slate-700 text-slate-100 p-6 max-w-xl w-[calc(100%-2rem)] shadow-2xl backdrop:bg-slate-950/80 outline-none">
      <form @submit.prevent="submitCreate" class="space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 id="createModalTitle" class="text-base font-bold text-white font-mono">Registrar Entrada Manual de Memoria
          </h3>
          <button type="button" @click="createModal?.close()" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <!-- Función & Ejemplo: Registrar Memoria -->
        <div class="p-3 rounded-lg bg-slate-950/90 border border-slate-800 text-xs space-y-1.5">
          <span class="text-[10px] font-mono font-bold text-cyan-400 uppercase block">¿Qué hace esta función?</span>
          <p class="text-slate-300 text-[11px] leading-relaxed">
            Permite ingresar una experiencia o inferencia al sistema validando sus restricciones ontológicas. Los hechos
            (<code class="text-emerald-400">fact</code>) exigen origen observable sin confianza; las hipótesis (<code
              class="text-amber-400">hypothesis</code>) exigen nivel de confianza; y los sueños (<code
              class="text-purple-400">dream</code>) solo son válidos bajo categoría onírica.
          </p>
          <div class="text-[10px] font-mono text-slate-400 pt-1 border-t border-slate-800">
            <strong>Ejemplo:</strong> Hecho episódico: <code
              class="text-cyan-300">"El usuario solicitó auditar la memoria"</code> con evidencia <code
              class="text-cyan-300">obs-cli-01</code>.
          </div>
        </div>

        <!-- Taxonomy category selection -->
        <fieldset class="space-y-1.5">
          <legend class="text-xs font-mono text-slate-300">Categoría Taxonómica:</legend>
          <div class="grid grid-cols-3 gap-2 text-xs">
            <label v-for="t in ['episodic', 'semantic', 'social', 'procedural', 'oneiric']" :key="t" :class="[
              'flex items-center gap-2 p-2 rounded-lg border cursor-pointer capitalize font-mono',
              newMemoryForm.type === t ? 'bg-cyan-950/80 border-cyan-600 text-cyan-200' : 'bg-slate-950 border-slate-800 text-slate-400 hover:border-slate-700'
            ]">
              <input type="radio" name="new_type" :value="t" v-model="newMemoryForm.type"
                @change="onTypeChange(newMemoryForm)" class="hidden" />
              <span>{{ t }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Epistemic Status selection -->
        <fieldset class="space-y-1.5">
          <legend class="text-xs font-mono text-slate-300">Estado Epistémico:</legend>
          <div class="grid grid-cols-3 gap-2 text-xs">
            <label v-for="s in ['fact', 'hypothesis', 'dream']" :key="s" :class="[
              'flex items-center gap-2 p-2 rounded-lg border cursor-pointer capitalize font-mono',
              newMemoryForm.epistemic_status === s ? 'bg-cyan-950/80 border-cyan-600 text-cyan-200' : 'bg-slate-950 border-slate-800 text-slate-400 hover:border-slate-700'
            ]">
              <input type="radio" name="new_status" :value="s" v-model="newMemoryForm.epistemic_status"
                @change="onEpistemicChange(newMemoryForm)" class="hidden" />
              <span>{{ s === 'fact' ? 'Hecho' : (s === 'hypothesis' ? 'Hipótesis' : 'Sueño') }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Confidence input (only if hypothesis) -->
        <div v-if="newMemoryForm.epistemic_status === 'hypothesis'"
          class="space-y-1.5 p-3 rounded-lg bg-amber-950/30 border border-amber-900/60">
          <div class="flex items-center justify-between text-xs">
            <label for="new_confidence" class="font-mono text-amber-300">Nivel de Confianza (Incertidumbre):</label>
            <span class="font-mono font-bold text-amber-200">{{ (newMemoryForm.confidence * 100).toFixed(0) }}%</span>
          </div>
          <input id="new_confidence" type="range" min="0.0" max="1.0" step="0.05" v-model="newMemoryForm.confidence"
            class="w-full accent-amber-500 cursor-pointer" />
        </div>

        <!-- Evidence ref input -->
        <div class="space-y-1">
          <label for="new_evidence" class="text-xs font-mono text-slate-300">Referencia de Evidencia (opcional):</label>
          <input id="new_evidence" type="text" v-model="newMemoryForm.evidence_ref"
            placeholder="ej. msg-042, obs-console-01"
            class="w-full text-xs font-mono px-3 py-2 rounded-lg bg-slate-950 border border-slate-800 text-slate-200 focus:outline-none focus:border-cyan-500" />
        </div>

        <!-- Content textarea -->
        <div class="space-y-1">
          <label for="new_content" class="text-xs font-mono text-slate-300">Contenido Observable / Enunciado:</label>
          <textarea id="new_content" v-model="newMemoryForm.content" required rows="3"
            placeholder="Descripción precisa del registro de experiencia..."
            class="w-full text-xs px-3 py-2 rounded-lg bg-slate-950 border border-slate-800 text-slate-200 focus:outline-none focus:border-cyan-500 leading-relaxed"></textarea>
        </div>

        <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
          <button type="button" @click="createModal?.close()"
            class="px-4 py-2 rounded-lg text-xs font-mono text-slate-400 hover:text-white">
            Cancelar
          </button>
          <button type="submit"
            class="px-4 py-2 rounded-lg text-xs font-semibold font-mono bg-cyan-600 hover:bg-cyan-500 text-white transition">
            Persistir Registro
          </button>
        </div>
      </form>
    </dialog>

    <!-- Modal: Rectificar Memoria -->
    <dialog ref="rectifyModal" closedby="any" aria-labelledby="rectifyModalTitle"
      @click="handleDialogClickFallback($event, rectifyModal)"
      class="fixed inset-0 m-auto max-h-[90vh] overflow-y-auto rounded-2xl bg-slate-900 border border-slate-700 text-slate-100 p-6 max-w-xl w-[calc(100%-2rem)] shadow-2xl backdrop:bg-slate-950/80 outline-none">
      <form @submit.prevent="submitRectify" class="space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 id="rectifyModalTitle" class="text-base font-bold text-white font-mono">Rectificar Memoria</h3>
          <button type="button" @click="rectifyModal?.close()"
            class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <!-- Función & Ejemplo: Rectificar Memoria -->
        <div class="p-3 rounded-lg bg-slate-950/90 border border-slate-800 text-xs space-y-1.5">
          <span class="text-[10px] font-mono font-bold text-cyan-400 uppercase block">¿Qué hace esta función?</span>
          <p class="text-slate-300 text-[11px] leading-relaxed">
            Corrige una memoria de forma inmutable sin sobrescribirla en su lugar. La versión actual queda archivada con
            <code class="text-amber-400 font-mono">superseded_at</code> y se crea un nuevo registro con <code
              class="text-cyan-400 font-mono">supersedes_id</code> apuntando al original.
          </p>
          <div class="text-[10px] font-mono text-slate-400 pt-1 border-t border-slate-800">
            <strong>Ejemplo:</strong> Hipótesis preliminar al 40% &rarr; rectificada con nueva evidencia documental al
            85%.
          </div>
        </div>

        <div class="p-3 rounded-lg bg-slate-950 border border-slate-800 text-xs space-y-1">
          <span class="text-slate-500 font-mono">Registro original a rectificar:</span>
          <p class="text-slate-300 italic truncate">{{ rectifyTarget?.content }}</p>
          <span class="text-[10px] text-cyan-400 font-mono">
            ID: {{ rectifyTarget?.id }}
          </span>
        </div>

        <!-- Taxonomy category selection -->
        <fieldset class="space-y-1.5">
          <legend class="text-xs font-mono text-slate-300">Categoría Taxonómica:</legend>
          <div class="grid grid-cols-3 gap-2 text-xs">
            <label v-for="t in ['episodic', 'semantic', 'social', 'procedural', 'oneiric']" :key="t" :class="[
              'flex items-center gap-2 p-2 rounded-lg border cursor-pointer capitalize font-mono',
              rectifyForm.type === t ? 'bg-cyan-950/80 border-cyan-600 text-cyan-200' : 'bg-slate-950 border-slate-800 text-slate-400 hover:border-slate-700'
            ]">
              <input type="radio" name="rectify_type" :value="t" v-model="rectifyForm.type"
                @change="onTypeChange(rectifyForm)" class="hidden" />
              <span>{{ t }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Epistemic Status selection -->
        <fieldset class="space-y-1.5">
          <legend class="text-xs font-mono text-slate-300">Estado Epistémico:</legend>
          <div class="grid grid-cols-3 gap-2 text-xs">
            <label v-for="s in ['fact', 'hypothesis', 'dream']" :key="s" :class="[
              'flex items-center gap-2 p-2 rounded-lg border cursor-pointer capitalize font-mono',
              rectifyForm.epistemic_status === s ? 'bg-cyan-950/80 border-cyan-600 text-cyan-200' : 'bg-slate-950 border-slate-800 text-slate-400 hover:border-slate-700'
            ]">
              <input type="radio" name="rectify_status" :value="s" v-model="rectifyForm.epistemic_status"
                @change="onEpistemicChange(rectifyForm)" class="hidden" />
              <span>{{ s === 'fact' ? 'Hecho' : (s === 'hypothesis' ? 'Hipótesis' : 'Sueño') }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Confidence input (only if hypothesis) -->
        <div v-if="rectifyForm.epistemic_status === 'hypothesis'"
          class="space-y-1.5 p-3 rounded-lg bg-amber-950/30 border border-amber-900/60">
          <div class="flex items-center justify-between text-xs">
            <label for="rectify_confidence" class="font-mono text-amber-300">Nivel de Confianza (Incertidumbre):</label>
            <span class="font-mono font-bold text-amber-200">{{ (rectifyForm.confidence * 100).toFixed(0) }}%</span>
          </div>
          <input id="rectify_confidence" type="range" min="0.0" max="1.0" step="0.05" v-model="rectifyForm.confidence"
            class="w-full accent-amber-500 cursor-pointer" />
        </div>

        <!-- Evidence ref input -->
        <div class="space-y-1">
          <label for="rectify_evidence" class="text-xs font-mono text-slate-300">Referencia de Nueva Evidencia:</label>
          <input id="rectify_evidence" type="text" v-model="rectifyForm.evidence_ref"
            placeholder="ej. contra-evidencia-02"
            class="w-full text-xs font-mono px-3 py-2 rounded-lg bg-slate-950 border border-slate-800 text-slate-200 focus:outline-none focus:border-cyan-500" />
        </div>

        <!-- Content textarea -->
        <div class="space-y-1">
          <label for="rectify_content" class="text-xs font-mono text-slate-300">Contenido Rectificado:</label>
          <textarea id="rectify_content" v-model="rectifyForm.content" required rows="3"
            placeholder="Nueva formulación corregida..."
            class="w-full text-xs px-3 py-2 rounded-lg bg-slate-950 border border-slate-800 text-slate-200 focus:outline-none focus:border-cyan-500 leading-relaxed"></textarea>
        </div>

        <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
          <button type="button" @click="rectifyModal?.close()"
            class="px-4 py-2 rounded-lg text-xs font-mono text-slate-400 hover:text-white">
            Cancelar
          </button>
          <button type="submit"
            class="px-4 py-2 rounded-lg text-xs font-semibold font-mono bg-cyan-600 hover:bg-cyan-500 text-white transition">
            Crear Versión Rectificada
          </button>
        </div>
      </form>
    </dialog>

    <!-- Modal: Eliminar / Purga con Tombstone -->
    <dialog ref="deleteModal" closedby="any" aria-labelledby="deleteModalTitle"
      @click="handleDialogClickFallback($event, deleteModal)"
      class="fixed inset-0 m-auto max-h-[90vh] overflow-y-auto rounded-2xl bg-slate-900 border border-rose-800 text-slate-100 p-6 max-w-md w-[calc(100%-2rem)] shadow-2xl backdrop:bg-slate-950/80 outline-none">
      <form @submit.prevent="submitDelete" class="space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 id="deleteModalTitle" class="text-base font-bold text-rose-400 font-mono">Confirmar Purga Permanente</h3>
          <button type="button" @click="deleteModal?.close()" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <!-- Función & Ejemplo: Purgar Memoria -->
        <div class="p-3 rounded-lg bg-slate-950/90 border border-rose-900/60 text-xs space-y-1.5">
          <span class="text-[10px] font-mono font-bold text-rose-400 uppercase block">¿Qué hace esta función?</span>
          <p class="text-slate-300 text-[11px] leading-relaxed">
            Elimina físicamente el texto sensible de la memoria en PostgreSQL y crea un registro mínimo inmutable en
            <code class="text-rose-300 font-mono">memory_tombstones</code> con el motivo de auditoría.
          </p>
          <div class="text-[10px] font-mono text-slate-400 pt-1 border-t border-slate-800">
            <strong>Ejemplo de motivo:</strong> <code
              class="text-rose-300">"Solicitud de olvido del operador / datos sensibles"</code>.
          </div>
        </div>

        <div class="space-y-1">
          <label for="delete_reason" class="text-xs font-mono text-slate-400">Motivo de la purga:</label>
          <input id="delete_reason" type="text" v-model="deleteReason" required
            placeholder="ej. Solicitud de olvido, error de captura"
            class="w-full text-xs font-mono px-3 py-2 rounded-lg bg-slate-950 border border-slate-800 text-slate-200 focus:outline-none focus:border-rose-500" />
        </div>

        <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
          <button type="button" @click="deleteModal?.close()"
            class="px-4 py-2 rounded-lg text-xs font-mono text-slate-400 hover:text-white">
            Cancelar
          </button>
          <button type="submit"
            class="px-4 py-2 rounded-lg text-xs font-semibold font-mono bg-rose-700 hover:bg-rose-600 text-white transition">
            Confirmar y Purgar
          </button>
        </div>
      </form>
    </dialog>

    <!-- Modal: Tombstones (Auditoría de Purgas) -->
    <dialog ref="tombstonesModal" closedby="any" aria-labelledby="tombstonesModalTitle"
      @click="handleDialogClickFallback($event, tombstonesModal)"
      class="fixed inset-0 m-auto max-h-[90vh] overflow-y-auto rounded-2xl bg-slate-900 border border-slate-700 text-slate-100 p-6 max-w-2xl w-[calc(100%-2rem)] shadow-2xl backdrop:bg-slate-950/80 outline-none">
      <div class="space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 id="tombstonesModalTitle" class="text-base font-bold text-white font-mono">Registro de Purgas (Memory
            Tombstones)</h3>
          <button type="button" @click="tombstonesModal?.close()"
            class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <!-- Función & Ejemplo: Tombstones -->
        <div class="p-3 rounded-lg bg-slate-950/90 border border-slate-800 text-xs space-y-1.5">
          <span class="text-[10px] font-mono font-bold text-cyan-400 uppercase block">¿Qué hace esta función?</span>
          <p class="text-slate-300 text-[11px] leading-relaxed">
            Permite a los auditores comprobar qué registros fueron eliminados permanentemente sin conservar datos
            sensibles personales. Garantiza trazabilidad y derecho al olvido.
          </p>
          <div class="text-[10px] font-mono text-slate-400 pt-1 border-t border-slate-800">
            <strong>Ejemplo:</strong> <code
              class="text-slate-300">Memory ID: a4b1... | Motivo: Purga manual por auditoría | Fecha: 2026-11-05</code>.
          </div>
        </div>

        <div v-if="tombstones.length === 0"
          class="py-8 text-center text-slate-500 font-mono text-xs border border-dashed border-slate-800 rounded-lg">
          No hay registros de purgas registrados.
        </div>

        <div v-else class="max-h-80 overflow-y-auto space-y-2 pr-1">
          <div v-for="t in tombstones" :key="t.id"
            class="p-3 rounded-lg bg-slate-950 border border-slate-800 flex items-center justify-between gap-4 text-xs font-mono">
            <div>
              <div class="text-slate-300 font-semibold">{{ t.reason || 'Sin motivo especificado' }}</div>
              <div class="text-[11px] text-slate-500 mt-0.5">Memoria ID: {{ t.memory_id }}</div>
            </div>
            <span class="text-slate-400 text-[11px] shrink-0">{{ new Date(t.created_at).toLocaleString() }}</span>
          </div>
        </div>

        <div class="flex justify-end pt-3 border-t border-slate-800">
          <button type="button" @click="tombstonesModal?.close()"
            class="px-4 py-2 rounded-lg text-xs font-mono bg-slate-800 hover:bg-slate-700 text-slate-200 transition">
            Cerrar
          </button>
        </div>
      </div>
    </dialog>

    <!-- Modal: Ejecutar Aprendizaje Controlado (Fase 3) -->
    <dialog ref="learnModal" closedby="any" aria-labelledby="learnModalTitle"
      @click="handleDialogClickFallback($event, learnModal)"
      class="fixed inset-0 m-auto max-h-[90vh] overflow-y-auto rounded-2xl bg-slate-900 border border-indigo-700/80 text-slate-100 p-6 max-w-2xl w-[calc(100%-2rem)] shadow-2xl backdrop:bg-slate-950/80 outline-none">
      <form @submit.prevent="submitLearn" class="space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 id="learnModalTitle" class="text-base font-bold text-white font-mono">Ejecutar Operación de Aprendizaje
            Controlado</h3>
          <button type="button" @click="learnModal?.close()" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <!-- Función & Ejemplo: Aprendizaje Controlado -->
        <div class="p-3 rounded-lg bg-slate-950/90 border border-indigo-900/60 text-xs space-y-1.5">
          <span class="text-[10px] font-mono font-bold text-indigo-400 uppercase block">¿Qué hace esta función?</span>
          <p class="text-slate-300 text-[11px] leading-relaxed">
            Aplica una evolución explícita e incremental (<code
              class="text-cyan-300">v{{ (agent?.personality_version || 1) + 1 }}.0</code>) respaldada por memorias
            vigentes. Respeta los límites epistémicos: hechos consolidan, hipótesis son tentativas y sueños guían
            preguntas exploratorias con <code class="text-purple-300">R &le; 0.25</code> sin modificar rasgos de
            personalidad.
          </p>
          <div class="text-[10px] font-mono text-slate-400 pt-1 border-t border-slate-800">
            <strong>Ejemplo:</strong> Seleccionar memoria de sueño &rarr; sembrar tema <code
              class="text-purple-300">"playas aptas para perros"</code> con pregunta <code
              class="text-purple-300">"Averiguar cuál es la mejor playa para llevar un perro"</code>.
          </div>
        </div>

        <!-- Selección de memorias vigentes -->
        <div class="space-y-2">
          <label class="text-xs font-mono text-slate-300 block">1. Memorias vigentes de evidencia (obligatorio):</label>
          <div v-if="activeMemories.length === 0"
            class="p-4 rounded-lg bg-slate-950 border border-dashed border-slate-800 text-xs text-slate-500 font-mono">
            No hay memorias vigentes disponibles. Registra primero una memoria para usarla como evidencia.
          </div>
          <div v-else class="max-h-44 overflow-y-auto space-y-1.5 pr-1">
            <label v-for="m in activeMemories" :key="m.id"
              class="flex items-start gap-2.5 p-2.5 rounded-lg bg-slate-950 border border-slate-800 hover:border-slate-700 cursor-pointer text-xs">
              <input type="checkbox" :value="m.id" v-model="learnForm.evidence_memory_ids"
                class="mt-0.5 rounded bg-slate-900 border-slate-700 text-indigo-600" />
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 font-mono text-[10px]">
                  <span :class="[
                    'px-1.5 py-0.5 rounded font-bold uppercase',
                    m.epistemic_status === 'fact'
                      ? 'bg-emerald-950 text-emerald-300'
                      : m.epistemic_status === 'hypothesis'
                        ? 'bg-amber-950 text-amber-300'
                        : 'bg-purple-950 text-purple-300'
                  ]">
                    {{ m.epistemic_status }}
                  </span>
                  <span class="text-slate-400">{{ m.type }}</span>
                  <span class="text-slate-500">{{ m.id.substring(0, 8) }}...</span>
                </div>
                <p class="text-slate-300 truncate mt-1">{{ m.content }}</p>
              </div>
            </label>
          </div>
        </div>

        <!-- Aviso epistémico cuando solo hay sueños seleccionados -->
        <div v-if="isOnlyDreamEvidenceSelected"
          class="p-3 rounded-lg bg-purple-950/40 border border-purple-800/80 text-xs text-purple-200 font-mono">
          ✦ Evidencia exclusivamente onírica seleccionada: los rasgos de personalidad están bloqueados para no confundir
          sueños con hechos reales. Define abajo el interés o pregunta exploratoria que el sueño inspira investigar (ej.
          "Averiguar cuál es la mejor playa para llevar un perro").
        </div>

        <!-- Rasgo de personalidad (opcional, deshabilitado si solo hay sueños) -->
        <div class="p-3.5 rounded-lg bg-slate-950 border border-slate-800 space-y-3"
          :class="{ 'opacity-50': isOnlyDreamEvidenceSelected }">
          <div class="text-xs font-mono text-slate-300">2. Rasgo de Personalidad (opcional, requiere Hecho o Hipótesis):
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label for="learn_trait_key" class="text-[11px] font-mono text-slate-400 block mb-1">Identificador del
                rasgo:</label>
              <input id="learn_trait_key" type="text" v-model="learnForm.trait_key"
                :disabled="isOnlyDreamEvidenceSelected" placeholder="ej. analytical_rigor"
                class="w-full text-xs font-mono px-3 py-1.5 rounded bg-slate-900 border border-slate-800 text-slate-200" />
            </div>
            <div>
              <div class="flex justify-between text-[11px] font-mono text-slate-400 mb-1">
                <label for="learn_trait_val">Valor objetivo [0, 1]:</label>
                <span class="text-cyan-300 font-bold">{{ Number(learnForm.trait_value).toFixed(2) }}</span>
              </div>
              <input id="learn_trait_val" type="range" min="0.0" max="1.0" step="0.05" v-model="learnForm.trait_value"
                :disabled="isOnlyDreamEvidenceSelected" class="w-full accent-cyan-500 cursor-pointer" />
            </div>
          </div>
        </div>

        <!-- Interés / Relevancia Adquirida R / Pregunta Exploratoria -->
        <div class="p-3.5 rounded-lg bg-slate-950 border border-slate-800 space-y-3">
          <div class="text-xs font-mono text-slate-300">3. Interés, Relevancia Adquirida (R) y Pregunta Exploratoria
            (opcional):</div>
          <div class="space-y-2.5">
            <div>
              <label for="learn_interest_topic" class="text-[11px] font-mono text-slate-400 block mb-1">Tópico de
                interés:</label>
              <input id="learn_interest_topic" type="text" v-model="learnForm.interest_topic"
                placeholder="ej. playas aptas para perros"
                class="w-full text-xs font-mono px-3 py-1.5 rounded bg-slate-900 border border-slate-800 text-slate-200" />
            </div>
            <div>
              <label for="learn_exploratory_query" class="text-[11px] font-mono text-slate-400 block mb-1">Pregunta /
                Objetivo exploratorio guiado (especialmente para sueños e hipótesis):</label>
              <input id="learn_exploratory_query" type="text" v-model="learnForm.exploratory_query"
                placeholder="ej. Averiguar cuál es la mejor playa para llevar un perro"
                class="w-full text-xs px-3 py-1.5 rounded bg-slate-900 border border-slate-800 text-slate-200" />
            </div>
          </div>
        </div>

        <!-- Resumen de auditoría -->
        <div class="space-y-1">
          <label for="learn_summary" class="text-xs font-mono text-slate-300">Resumen de la operación
            (auditoría):</label>
          <input id="learn_summary" type="text" v-model="learnForm.summary" required
            class="w-full text-xs font-mono px-3 py-2 rounded-lg bg-slate-950 border border-slate-800 text-slate-200" />
        </div>

        <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
          <button type="button" @click="learnModal?.close()"
            class="px-4 py-2 rounded-lg text-xs font-mono text-slate-400 hover:text-white">
            Cancelar
          </button>
          <button type="submit" :disabled="learnForm.evidence_memory_ids.length === 0"
            class="px-4 py-2 rounded-lg text-xs font-semibold font-mono bg-indigo-600 hover:bg-indigo-500 disabled:opacity-40 text-white transition">
            Aplicar Nueva Versión
          </button>
        </div>
      </form>
    </dialog>

    <!-- Modal: Historial de Versiones y Reversión (Fase 3) -->
    <dialog ref="versionsModal" closedby="any" aria-labelledby="versionsModalTitle"
      @click="handleDialogClickFallback($event, versionsModal)"
      class="fixed inset-0 m-auto max-h-[90vh] overflow-y-auto rounded-2xl bg-slate-900 border border-slate-700 text-slate-100 p-6 max-w-2xl w-[calc(100%-2rem)] shadow-2xl backdrop:bg-slate-950/80 outline-none">
      <div class="space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h3 id="versionsModalTitle" class="text-base font-bold text-white font-mono">Historial de Versiones &
            Reversión Auditable</h3>
          <button type="button" @click="versionsModal?.close()"
            class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <!-- Función & Ejemplo: Historial & Reversión -->
        <div class="p-3 rounded-lg bg-slate-950/90 border border-slate-800 text-xs space-y-1.5">
          <span class="text-[10px] font-mono font-bold text-cyan-400 uppercase block">¿Qué hace esta función?</span>
          <p class="text-slate-300 text-[11px] leading-relaxed">
            Conserva snapshots inmutables de cada estado alcanzado por el agente. Permite regresar a cualquier versión
            anterior creando un nuevo evento de reversión (<code class="text-cyan-300">operation_type = revert</code>)
            sin borrar el rastro histórico.
          </p>
          <div class="text-[10px] font-mono text-slate-400 pt-1 border-t border-slate-800">
            <strong>Ejemplo:</strong> Revertir desde <code class="text-amber-300">v2.0</code> a <code
              class="text-cyan-300">v1.0</code> crea <code class="text-emerald-300">v3.0</code> con los atributos vacíos
            de v1 y registro auditable del motivo.
          </div>
        </div>

        <div class="space-y-1">
          <label for="revert_reason_input" class="text-xs font-mono text-slate-400">Motivo en caso de reversión:</label>
          <input id="revert_reason_input" type="text" v-model="revertReason"
            class="w-full text-xs font-mono px-3 py-1.5 rounded-lg bg-slate-950 border border-slate-800 text-slate-200" />
        </div>

        <div class="max-h-80 overflow-y-auto space-y-2.5 pr-1">
          <div v-for="v in versions" :key="v.id"
            class="p-3.5 rounded-lg bg-slate-950 border border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs font-mono">
            <div class="space-y-1">
              <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded bg-cyan-950 text-cyan-300 border border-cyan-800 font-bold">
                  v{{ v.version }}.0
                </span>
                <span class="uppercase text-[10px] px-1.5 py-0.5 rounded bg-slate-800 text-slate-300">
                  {{ v.operation_type }}
                </span>
                <span v-if="v.reverted_from_version" class="text-[11px] text-amber-400">
                  ↺ restaurado desde v{{ v.reverted_from_version }}.0
                </span>
              </div>
              <div class="text-slate-200 font-sans">{{ v.summary }}</div>
              <div class="text-[11px] text-slate-500">
                Evidencias: {{ (v.evidence_memory_ids || []).length }} |
                Fecha: {{ v.created_at ? new Date(v.created_at).toLocaleString() : '—' }}
              </div>
            </div>

            <div class="shrink-0">
              <button v-if="v.version < (agent?.personality_version || 1)" type="button"
                @click="submitRevert(v.version)"
                class="px-3 py-1.5 rounded bg-amber-950/80 hover:bg-amber-900 text-amber-300 border border-amber-800 transition text-xs">
                Revertir a v{{ v.version }}.0
              </button>
              <span v-else class="text-[11px] text-emerald-400 px-2 py-1">
                Versión activa
              </span>
            </div>
          </div>
        </div>

        <div class="flex justify-end pt-3 border-t border-slate-800">
          <button type="button" @click="versionsModal?.close()"
            class="px-4 py-2 rounded-lg text-xs font-mono bg-slate-800 hover:bg-slate-700 text-slate-200 transition">
            Cerrar
          </button>
        </div>
      </div>
    </dialog>

    <!-- Modal: Informe de Indagación Cognitiva (Fase 4 - Opción A) -->
    <dialog
      ref="inquiryModal"
      closedby="any"
      aria-labelledby="inquiryModalTitle"
      @click="handleDialogClickFallback($event, inquiryModal)"
      class="fixed inset-0 m-auto max-h-[90vh] overflow-y-auto rounded-2xl bg-slate-900 border border-cyan-700/80 text-slate-100 p-6 max-w-2xl w-[calc(100%-2rem)] shadow-2xl backdrop:bg-slate-950/80 outline-none"
    >
      <div v-if="inquiryReport" class="space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <div class="flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-cyan-400 animate-ping"></span>
            <h3 id="inquiryModalTitle" class="text-base font-bold text-white font-mono">
              Informe de Indagación Cognitiva de EXILIO-001
            </h3>
          </div>
          <button type="button" @click="inquiryModal?.close()" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <!-- Función & Ejemplo: Ciclo de Indagación -->
        <div class="p-3 rounded-lg bg-slate-950/90 border border-cyan-900/60 text-xs space-y-1.5">
          <span class="text-[10px] font-mono font-bold text-cyan-400 uppercase block">¿Qué hace esta función?</span>
          <p class="text-slate-300 text-[11px] leading-relaxed">
            Permite a EXILIO-001 "buscar y explorar" bajo tu demanda. Selecciona su tema de mayor curiosidad (<code class="text-cyan-300 font-mono">C = {{ Number(inquiryReport.curiosity_score).toFixed(2) }}</code>), delibera sobre la pregunta abierta con Gemini y formula una hipótesis tentativa con nivel de certeza. Si apruebas el hallazgo, puedes persistirlo directamente en su memoria autobiográfica para que evolucione en el siguiente ciclo.
          </p>
          <div class="text-[10px] font-mono text-slate-400 pt-1 border-t border-slate-800">
            <strong>Ejemplo de persistencia:</strong> Guardar como hipótesis (<code class="text-amber-300 font-mono">confidence: {{ (inquiryReport.confidence * 100).toFixed(0) }}%</code>) en la categoría <code class="text-cyan-300 font-mono">{{ inquiryReport.taxonomic_type }}</code> con procedencia trazable.
          </div>
        </div>

        <!-- Tópico y Curiosidad C -->
        <div class="p-3.5 rounded-lg bg-slate-950 border border-slate-800 flex flex-wrap items-center justify-between gap-3 text-xs font-mono">
          <div>
            <span class="text-slate-400 text-[11px] block">Tópico investigado:</span>
            <span class="text-sm font-bold text-slate-100">{{ inquiryReport.topic }}</span>
          </div>
          <div class="flex items-center gap-2">
            <span class="px-2 py-0.5 rounded bg-indigo-950 text-indigo-300 border border-indigo-800 text-xs">
              Curiosidad C={{ Number(inquiryReport.curiosity_score).toFixed(2) }}
            </span>
            <span class="px-2 py-0.5 rounded bg-slate-900 text-slate-300 border border-slate-700 text-xs capitalize">
              {{ inquiryReport.taxonomic_type }}
            </span>
          </div>
        </div>

        <!-- Pregunta que guió la exploración -->
        <div class="p-3 rounded-lg bg-slate-950 border border-slate-800/80 text-xs space-y-1">
          <span class="text-[10px] font-mono uppercase text-slate-400 block">Pregunta activa que guió la exploración:</span>
          <p class="text-slate-200 italic font-sans">"{{ inquiryReport.exploratory_query }}"</p>
        </div>

        <!-- Deliberación analítica de EXILIO-001 -->
        <div class="p-3.5 rounded-lg bg-slate-950 border border-slate-800 space-y-1.5 text-xs">
          <span class="text-[10px] font-mono uppercase font-bold text-cyan-400 block">Razonamiento & Deliberación Cognitiva:</span>
          <p class="text-slate-300 leading-relaxed font-sans">{{ inquiryReport.deliberation }}</p>
        </div>

        <!-- Hipótesis Formulada -->
        <div class="p-3.5 rounded-lg bg-amber-950/20 border border-amber-800/80 space-y-2 text-xs">
          <div class="flex items-center justify-between">
            <span class="text-[10px] font-mono uppercase font-bold text-amber-300">Hipótesis Formada por EXILIO-001:</span>
            <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-amber-950 text-amber-200 border border-amber-700">
              Certeza: {{ (inquiryReport.confidence * 100).toFixed(0) }}%
            </span>
          </div>
          <p class="text-slate-100 font-sans leading-relaxed text-sm bg-slate-950/60 p-2.5 rounded border border-amber-900/40">
            "{{ inquiryReport.hypothesis_content }}"
          </p>
        </div>

        <!-- Siguiente pregunta guiada -->
        <div class="p-3 rounded-lg bg-purple-950/20 border border-purple-900/60 text-xs space-y-1">
          <span class="text-[10px] font-mono uppercase text-purple-300 block">Siguiente pregunta sugerida para continuar explorando:</span>
          <p class="text-purple-200 font-sans">{{ inquiryReport.next_exploratory_question }}</p>
        </div>

        <!-- Telemetría de la indagación -->
        <div class="p-2.5 rounded-lg bg-slate-950 border border-slate-800/60 flex flex-wrap items-center justify-between text-[11px] font-mono text-slate-400 gap-2">
          <span>Proveedor: <strong class="text-slate-200">{{ inquiryReport.telemetry?.provider }}</strong></span>
          <span>Tokens: <strong class="text-cyan-300">{{ inquiryReport.telemetry?.tokens_used }}</strong></span>
          <span>Costo: <strong class="text-emerald-400">${{ Number(inquiryReport.telemetry?.cost_usd || 0).toFixed(5) }}</strong></span>
        </div>

        <!-- Botones de Acción -->
        <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
          <button
            type="button"
            @click="inquiryModal?.close()"
            class="px-4 py-2 rounded-lg text-xs font-mono text-slate-400 hover:text-white"
          >
            Cerrar / Descartar
          </button>
          <button
            type="button"
            @click="persistInquiryFinding"
            :disabled="persistingFinding"
            class="px-4 py-2 rounded-lg text-xs font-semibold font-mono bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white transition flex items-center gap-1.5 shadow-sm"
          >
            <span v-if="persistingFinding" class="animate-spin">◐</span>
            <span v-else>✓</span>
            <span>{{ persistingFinding ? 'Persistiendo...' : 'Persistir en Memoria Autobiográfica' }}</span>
          </button>
        </div>
      </div>
    </dialog>
  </div>
</template>
