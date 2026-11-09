<script setup>
import { ref, useTemplateRef } from 'vue'

const props = defineProps({
  agentId: { type: String, default: null },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['persisted', 'error', 'busy'])

const dialog = useTemplateRef('dialog')
const report = ref(null)
const dreaming = ref(false)
const saving = ref(false)

async function readError(res, fallback) {
  try {
    const data = await res.json()
    return data.message || fallback
  } catch {
    return fallback
  }
}

async function dream() {
  if (!props.agentId || dreaming.value) return
  dreaming.value = true
  emit('busy', true, 'Soñando: recombinando experiencia vigente')
  try {
    const res = await fetch(`/api/agents/${props.agentId}/dream`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
    })
    if (!res.ok) throw new Error(await readError(res, 'No se pudo generar el sueño'))
    report.value = (await res.json()).dream_report
    dialog.value?.showModal()
  } catch (err) {
    emit('error', err.message)
  } finally {
    dreaming.value = false
    emit('busy', false)
  }
}

async function approve() {
  if (!report.value || saving.value) return
  saving.value = true
  try {
    const r = report.value
    const res = await fetch(`/api/agents/${props.agentId}/dream/persist`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        narrative: r.narrative,
        exploratory_topic: r.exploratory_topic,
        exploratory_query: r.exploratory_query,
        source_memory_ids: r.source_memory_ids,
      }),
    })
    if (!res.ok) throw new Error(await readError(res, 'No se pudo registrar el sueño'))
    const data = await res.json()
    dialog.value?.close()
    report.value = null
    emit('persisted', data)
  } catch (err) {
    emit('error', err.message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="flex justify-center">
    <button type="button" @click="dream" :disabled="disabled || dreaming || !agentId"
      class="group inline-flex items-center gap-2 px-5 py-2 rounded-full text-xs tracking-widest uppercase
        text-violet-100 bg-violet-500/10 hover:bg-violet-500/20 border border-violet-300/20 transition
        disabled:opacity-40 disabled:cursor-not-allowed"
      title="Recombina memorias vigentes en una simulación (dream) que solo guía la curiosidad">
      <span :class="dreaming ? 'animate-spin' : 'group-hover:scale-125 transition'">☾</span>
      {{ dreaming ? 'Soñando…' : 'Soñar' }}
    </button>

    <dialog ref="dialog" closedby="any" aria-labelledby="dreamTitle"
      class="w-full max-w-xl rounded-3xl bg-slate-950/95 text-violet-50 border border-violet-300/15 p-0 backdrop:bg-black/70">
      <div v-if="report" class="p-6 space-y-5">
        <div>
          <h3 id="dreamTitle" class="text-lg text-violet-100" style="font-family: ui-serif, Georgia, serif">Un sueño propuesto</h3>
          <p class="text-[11px] text-violet-200/50 mt-1">
            Simulación interna. No es un hecho ni una experiencia vivida y no modifica rasgos.
          </p>
        </div>

        <p class="text-sm leading-relaxed italic text-violet-100/90 whitespace-pre-line">{{ report.narrative }}</p>

        <div class="rounded-xl bg-violet-500/5 border border-violet-300/10 p-3 text-xs space-y-1">
          <div class="text-[10px] uppercase tracking-widest text-violet-300/60">Semilla de curiosidad (a verificar)</div>
          <div class="text-violet-100">{{ report.exploratory_topic }}</div>
          <div class="text-violet-200/70">{{ report.exploratory_query }}</div>
        </div>

        <details class="text-xs text-violet-200/60">
          <summary class="cursor-pointer hover:text-violet-100">Memorias de origen ({{ report.sources.length }})</summary>
          <ul class="mt-2 space-y-1">
            <li v-for="s in report.sources" :key="s.id" class="leading-snug">
              <span :class="s.epistemic_status === 'fact' ? 'text-emerald-300' : 'text-amber-300'">
                {{ s.epistemic_status === 'fact' ? 'hecho' : 'hipótesis' }}
              </span>
              · {{ s.excerpt }}
            </li>
          </ul>
        </details>

        <p v-if="report.is_fallback" class="text-[11px] text-amber-300/80">
          Recombinación local ({{ report.telemetry.provider }}): no la generó el modelo.
        </p>
        <p class="text-[10px] font-mono text-violet-200/35">
          {{ report.telemetry.provider }} · {{ report.telemetry.tokens_used }} tokens · ${{ Number(report.telemetry.cost_usd).toFixed(5) }}
        </p>

        <div class="flex justify-end gap-3 pt-1">
          <button type="button" @click="dialog?.close()"
            class="px-4 py-1.5 rounded-full text-xs text-violet-200/70 hover:text-white transition">Descartar</button>
          <button type="button" @click="approve" :disabled="saving"
            class="px-4 py-1.5 rounded-full text-xs bg-violet-500/30 hover:bg-violet-500/45 border border-violet-300/30 text-white transition disabled:opacity-50">
            {{ saving ? 'Registrando…' : 'Registrar sueño' }}
          </button>
        </div>
      </div>
    </dialog>
  </div>
</template>
